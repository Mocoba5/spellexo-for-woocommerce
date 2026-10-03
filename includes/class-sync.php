<?php
/**
 * Bounded outbound catalog synchronization queue.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Sync {
	const ACTION_GROUP = 'spellexo_woocommerce';
	const PRODUCT_ACTION = 'spellexo_woocommerce_sync_product';
	const CATALOG_ACTION = 'spellexo_woocommerce_sync_catalog_page';
	const TOMBSTONE_ACTION = 'spellexo_woocommerce_sync_catalog_tombstone';
	const BATCH_SIZE = 200;
	const MAX_VARIANTS_PER_BATCH = 5000;
	const SYNC_PROTOCOL = 'adaptive_stream_v1';
	const CATALOG_BODY_MAX_BYTES = 4194304;
	const MAX_PAGE_COUNT = 100000;
	const MAX_CATALOG_PAGE_RETRIES = 2;
	const START_LOCK_TTL_SECONDS = 30;
	const PAGE_LOCK_TTL_SECONDS = 60;

	/**
	 * @var Connection
	 */
	private $connection;

	/**
	 * @var Api_Client
	 */
	private $api_client;

	/**
	 * @var Product_Serializer
	 */
	private $serializer;

	/** @var array<int, int> Parent IDs captured before variation hard deletion. */
	private $deleted_variation_parents = array();

	/** @var string Full-sync start lease held only for this request. */
	private $start_lock_token = '';

	/** @var string Catalog-page execution lease held only for this request. */
	private $page_lock_token = '';

	/**
	 * @param Connection|null $connection Connection override for tests.
	 * @param Api_Client|null $api_client API override for tests.
	 * @param Product_Serializer|null $serializer Serializer override for tests.
	 */
	public function __construct( $connection = null, $api_client = null, $serializer = null ) {
		$this->connection = $connection instanceof Connection ? $connection : new Connection();
		$this->api_client = $api_client instanceof Api_Client ? $api_client : new Api_Client();
		$this->serializer = $serializer instanceof Product_Serializer ? $serializer : new Product_Serializer();
	}

	/**
	 * Registers only asynchronous WooCommerce lifecycle hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_update_product', array( $this, 'on_product_change' ), 20, 1 );
		add_action( 'woocommerce_new_product', array( $this, 'on_product_change' ), 20, 1 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'on_product_change' ), 20, 1 );
		add_action( 'woocommerce_new_product_variation', array( $this, 'on_product_change' ), 20, 1 );
		/* trashed_post runs after the post status is trash, unlike wp_trash_post. */
		add_action( 'trashed_post', array( $this, 'on_product_change' ), 20, 1 );
		add_action( 'untrashed_post', array( $this, 'on_product_change' ), 20, 1 );
		add_action( 'before_delete_post', array( $this, 'on_product_delete' ), 20, 2 );
		add_action( 'deleted_post', array( $this, 'on_product_deleted' ), 20, 2 );
		add_action( 'spellexo_woocommerce_connected', array( $this, 'queue_initial_sync' ), 20 );

		add_action( self::PRODUCT_ACTION, array( $this, 'sync_product' ), 10, 2 );
		add_action( self::CATALOG_ACTION, array( $this, 'sync_catalog_page' ), 10, 3 );
		add_action( self::TOMBSTONE_ACTION, array( $this, 'sync_tombstone' ), 10, 4 );
	}

	/**
	 * Queues a product-level upsert only after a merchant has connected.
	 *
	 * @param int $product_or_variation_id Woo object ID.
	 * @return void
	 */
	public function on_product_change( $product_or_variation_id ) {
		if ( ! $this->connection->is_connected() || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product = wc_get_product( (int) $product_or_variation_id );
		if ( ! $product ) {
			return;
		}

		$product_id = method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		if ( $product_id > 0 ) {
			$this->queue_product_sync( $product_id );
		}
	}

	/**
	 * Queues a hard-delete tombstone while WooCommerce still exposes the product
	 * record. This hook performs only local revision allocation and scheduling;
	 * it never makes a network call in the delete request.
	 *
	 * @param int   $post_id Deleted post ID.
	 * @param mixed $post Deleted post object.
	 * @return void
	 */
	public function on_product_delete( $post_id, $post = null ) {
		unset( $post );
		if ( ! $this->connection->is_connected() || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product = wc_get_product( (int) $post_id );
		if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		$is_variation = method_exists( $product, 'is_type' ) && $product->is_type( 'variation' );
		$plan         = self::hard_delete_plan( $is_variation, (int) $product->get_id(), $is_variation ? (int) $product->get_parent_id() : 0 );
		if ( 'parent_upsert' === $plan['action'] ) {
			/* deleted_post schedules this only after the variation is gone. */
			$this->deleted_variation_parents[ (int) $post_id ] = $plan['product_id'];
			return;
		}

		if ( 'tombstone' === $plan['action'] ) {
			$this->queue_product_tombstone( $plan['product_id'] );
		}
	}

	/**
	 * Schedules a parent upsert only after a captured variation has been deleted,
	 * so serialization cannot retain the now-missing child variation.
	 *
	 * @param int   $post_id Deleted post ID.
	 * @param mixed $post Deleted post object.
	 * @return void
	 */
	public function on_product_deleted( $post_id, $post = null ) {
		unset( $post );
		$parent_id = isset( $this->deleted_variation_parents[ (int) $post_id ] ) ? (int) $this->deleted_variation_parents[ (int) $post_id ] : 0;
		unset( $this->deleted_variation_parents[ (int) $post_id ] );

		if ( $parent_id > 0 && $this->connection->is_connected() ) {
			/* One second prevents an async runner from racing this delete request. */
			$this->schedule( self::PRODUCT_ACTION, array( $parent_id, 0 ), time() + 1, true );
		}
	}

	/**
	 * Queues initial catalog synchronization from the native admin screen.
	 *
	 * @return bool
	 */
	public function queue_initial_sync() {
		if ( ! $this->connection->is_connected() || ! function_exists( 'wc_get_products' ) ) {
			return false;
		}

		if ( ! $this->acquire_start_lock() ) {
			return self::is_valid_sync_run( Options::get( Options::SYNC_RUN, array() ) );
		}

		try {
			$sync_run = $this->prepare_catalog_run();
			if ( null === $sync_run ) {
				return false;
			}

			return $this->resume_catalog_run( $sync_run );
		} finally {
			$this->release_start_lock();
		}
	}

	/**
	 * Executes one catalog page inside an authenticated merchant request.
	 *
	 * The browser repeats this bounded step until the final page is accepted. This
	 * is deliberately independent of Action Scheduler and WP-Cron so a host with
	 * a broken loopback runner can still complete a merchant-requested recovery.
	 * Background workers and manual requests share the same page lease, run ID,
	 * revision, and idempotency key.
	 *
	 * @return array<string, mixed>
	 */
	public function run_manual_catalog_step() {
		if ( ! $this->connection->is_connected() || ! function_exists( 'wc_get_products' ) ) {
			return self::manual_result( 'failed', 0, 0, 0, 'catalog_unavailable' );
		}

		if ( ! $this->acquire_start_lock() ) {
			return self::manual_result( 'busy', 0, 0, 0, 'catalog_sync_busy' );
		}

		try {
			$sync_run = $this->prepare_catalog_run();
			if ( null === $sync_run ) {
				$connection = Options::connection();
				$code       = '' !== $connection['last_error_code'] ? (string) $connection['last_error_code'] : 'catalog_start_failed';

				return self::manual_result( 'failed', 0, 0, 0, $code );
			}

			/* Manual execution owns pending work; already-running work is lease-guarded. */
			self::unschedule_catalog_actions();
			$page       = (int) $sync_run['next_page'];
			$offset     = (int) $sync_run['next_offset'];
			$total      = (int) $sync_run['initial_total'];
			$run_id     = (string) $sync_run['id'];
		} finally {
			$this->release_start_lock();
		}

		$outcome = $this->sync_catalog_page( $page, 0, $run_id, false );
		$current = Options::get( Options::SYNC_RUN, array() );

		if ( 'completed' === $outcome ) {
			return self::manual_result( 'completed', $page, $total, $total, '' );
		}

		if ( 'advanced' === $outcome ) {
			$processed = self::is_valid_sync_run( $current ) && hash_equals( $run_id, (string) $current['id'] ) ? (int) $current['next_offset'] : $offset;

			return self::manual_result( 'running', $page, $processed, $total, '' );
		}

		if ( 'busy' === $outcome ) {
			return self::manual_result( 'busy', $page, $offset, $total, 'catalog_sync_busy' );
		}

		/* A concurrent worker may have advanced the same run before our lease. */
		if ( 'stale' === $outcome && self::is_valid_sync_run( $current ) && hash_equals( $run_id, (string) $current['id'] ) && (int) $current['next_page'] > $page ) {
			return self::manual_result( 'running', $page, (int) $current['next_offset'], $total, '' );
		}

		$connection = Options::connection();
		$code       = '' !== $connection['last_error_code'] ? (string) $connection['last_error_code'] : 'catalog_sync_no_progress';

		return self::manual_result( 'failed', $page, $offset, $total, $code );
	}

	/**
	 * Serializes and posts one product outside the Woo save request.
	 *
	 * @param int $product_id Product ID.
	 * @param int $attempt Retry attempt.
	 * @return void
	 */
	public function sync_product( $product_id, $attempt = 0 ) {
		if ( ! $this->connection->is_connected() || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return;
		}

		$secret = Secret_Store::get();
		if ( null === $secret ) {
			return;
		}

		try {
			$serialized = $this->serializer->serialize( $product );
			$revision   = Options::next_site_revision();
			$payload    = array(
				'schema_version' => 1,
				'site_revision'  => $revision,
				'product'        => $serialized,
			);
			if ( ! self::catalog_payload_fits( $payload ) ) {
				throw new Api_Exception( 'This product catalog payload exceeds the 4 MiB connector limit. Reduce unusually long variation metadata and retry synchronization.', 'catalog_payload_too_large' );
			}
			$this->api_client->request(
				'POST',
				'/v1/integrations/wordpress/catalog/upsert',
				$payload,
				array(
					'idempotency_key' => Signature::idempotency_key( 'catalog-upsert', array( $product_id, $revision ), $secret ),
				)
			);
			Options::update( Options::LAST_SYNC, time() );
			Options::clear_queue_failures();
			Options::update_connection( array( 'last_error_code' => '' ) );
		} catch ( Api_Exception $exception ) {
			$this->connection->record_error( $exception );
			Options::record_queue_failure();
			if ( $exception->is_retryable() && (int) $attempt < 2 ) {
				$this->schedule( self::PRODUCT_ACTION, array( (int) $product_id, (int) $attempt + 1 ), time() + ( 60 * ( 1 << (int) $attempt ) ), false );
			}
		}
	}

	/**
	 * Sends one persistent hard-delete catalog tombstone. Revision, timestamp,
	 * and idempotency inputs are created before deletion and remain unchanged for
	 * bounded retries.
	 *
	 * @param int    $product_id Parent product ID.
	 * @param string $revision Plugin-owned decimal revision.
	 * @param string $deleted_at UTC ISO timestamp.
	 * @param int    $attempt Retry attempt.
	 * @return void
	 */
	public function sync_tombstone( $product_id, $revision, $deleted_at, $attempt = 0 ) {
		$payload = array(
			'schema_version' => 1,
			'site_revision'  => (string) $revision,
			'external_id'    => (string) $product_id,
			'deleted_at'     => (string) $deleted_at,
		);

		if ( ! $this->connection->is_connected() || ! self::is_valid_tombstone_payload( $payload ) ) {
			return;
		}

		$secret = Secret_Store::get();
		$body   = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( null === $secret || false === $body || strlen( $body ) > 16384 ) {
			return;
		}

		try {
			$this->api_client->request(
				'POST',
				'/v1/integrations/wordpress/catalog/tombstone',
				$payload,
				array(
					'idempotency_key' => Signature::idempotency_key( 'catalog-tombstone', array( (string) $product_id, (string) $revision ), $secret ),
				)
			);
			Options::update( Options::LAST_SYNC, time() );
			Options::clear_queue_failures();
			Options::update_connection( array( 'last_error_code' => '' ) );
		} catch ( Api_Exception $exception ) {
			$this->connection->record_error( $exception );
			Options::record_queue_failure();
			if ( $exception->is_retryable() && (int) $attempt < self::MAX_CATALOG_PAGE_RETRIES ) {
				$this->schedule( self::TOMBSTONE_ACTION, array( (int) $product_id, (string) $revision, (string) $deleted_at, (int) $attempt + 1 ), time() + ( 60 * ( 1 << (int) $attempt ) ), false );
			}
		}
	}

	/**
	 * Synchronizes one bounded page of an initial catalog run.
	 *
	 * @param int    $page One-based page.
	 * @param int    $attempt Retry attempt.
	 * @param string $sync_run_id Isolated catalog-run ID.
	 * @param bool   $background Whether another page/retry may be queued.
	 * @return string completed, advanced, failed, busy, stale, or unavailable.
	 */
	public function sync_catalog_page( $page = 1, $attempt = 0, $sync_run_id = '', $background = true ) {
		if ( ! $this->connection->is_connected() || ! function_exists( 'wc_get_products' ) ) {
			return 'unavailable';
		}

		if ( ! $this->acquire_page_lock() ) {
			return 'busy';
		}

		try {
			return $this->process_catalog_page( $page, $attempt, $sync_run_id, (bool) $background );
		} finally {
			$this->release_page_lock();
		}
	}

	/**
	 * @param int    $page One-based page.
	 * @param int    $attempt Retry attempt.
	 * @param string $sync_run_id Isolated catalog-run ID.
	 * @param bool   $background Whether another page/retry may be queued.
	 * @return string
	 */
	private function process_catalog_page( $page, $attempt, $sync_run_id, $background ) {
		$sync_run = Options::get( Options::SYNC_RUN, array() );
		$page       = max( 1, (int) $page );
		$attempt    = max( 0, (int) $attempt );
		if ( ! self::should_process_catalog_page( $sync_run, $sync_run_id, $page ) ) {
			return 'stale';
		}

		$offset = (int) $sync_run['next_offset'];
		$total  = (int) $sync_run['initial_total'];
		$sync_run['started_at']      = isset( $sync_run['started_at'] ) && (int) $sync_run['started_at'] > 0 ? (int) $sync_run['started_at'] : time();
		$sync_run['last_attempt_at'] = time();
		Options::update( Options::SYNC_RUN, $sync_run );
		Options::update( Options::LAST_SYNC_ATTEMPT, $sync_run['last_attempt_at'] );

		$results = wc_get_products(
			array(
				'limit'    => self::BATCH_SIZE,
				'offset'   => $offset,
				'paginate' => true,
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'status'   => 'any',
				'type'     => array( 'simple', 'variable' ),
			)
		);

		if ( ! is_object( $results ) || ! isset( $results->products ) || ! isset( $results->total ) ) {
			$this->fail_catalog_page( $sync_run, $page, $attempt, new Api_Exception( 'The catalog page could not be loaded.', 'catalog_page_unavailable', true ), $background );
			return 'failed';
		}
		if ( (int) $results->total !== $total ) {
			$this->restart_drifted_catalog_run( $sync_run );
			return 'failed';
		}

		$payload_base = array(
			'schema_version' => 1,
			'sync_run_id'    => (string) $sync_run['id'],
			'site_revision'  => (string) $sync_run['revision'],
			'page'           => $page,
			'is_final_page'  => false,
		);
		$candidate_count = count( (array) $results->products );
		$candidates      = ( function() use ( $results ) {
			foreach ( (array) $results->products as $product ) {
				yield $this->serializer->serialize( $product );
			}
		} )();
		try {
			$products = self::select_catalog_batch( $candidates, $payload_base );
		} catch ( \Throwable $exception ) {
			/* Fail closed: an incomplete page must never finalize a snapshot. */
			$this->fail_catalog_page( $sync_run, $page, $attempt, self::catalog_serialization_exception( $exception ), $background );
			return 'failed';
		}
		if ( $candidate_count > 0 && empty( $products ) ) {
			$this->fail_catalog_page( $sync_run, $page, $attempt, new Api_Exception( 'A product catalog payload exceeds the adaptive connector limits.', 'catalog_payload_too_large' ), $background );
			return 'failed';
		}
		if ( empty( $products ) && $offset < $total ) {
			$this->restart_drifted_catalog_run( $sync_run );
			return 'failed';
		}

		$processed = $offset + count( $products );
		$is_final  = $processed >= $total;
		if ( ! $is_final && $page >= self::MAX_PAGE_COUNT ) {
			$this->fail_catalog_page( $sync_run, $page, $attempt, new Api_Exception( 'The catalog requires too many adaptive batches.', 'catalog_page_limit_exceeded' ), $background );
			return 'failed';
		}

		$secret = Secret_Store::get();
		if ( null === $secret ) {
			$this->fail_catalog_page( $sync_run, $page, $attempt, new Api_Exception( 'The local connection credential is unavailable.', 'credential_unavailable' ), $background );
			return 'failed';
		}

		/* Do not let offset pagination finalize a snapshot after catalog drift. */
		if ( $is_final ) {
			$current_snapshot = $this->catalog_snapshot();
			if ( null === $current_snapshot ) {
				$this->fail_catalog_page( $sync_run, $page, $attempt, new Api_Exception( 'The catalog snapshot could not be rechecked.', 'catalog_snapshot_unavailable', true ), $background );
				return 'failed';
			}
			if ( ! self::catalog_snapshot_matches( $sync_run, $current_snapshot ) ) {
				$this->restart_drifted_catalog_run( $sync_run );
				return 'failed';
			}
		}

		$payload                  = $payload_base;
		$payload['is_final_page'] = $is_final;
		$payload['products']      = $products;
		if ( ! self::catalog_payload_fits( $payload ) ) {
			$this->fail_catalog_page( $sync_run, $page, $attempt, new Api_Exception( 'A product catalog payload exceeds the 4 MiB connector limit. Reduce unusually long variation metadata and retry synchronization.', 'catalog_payload_too_large' ), $background );
			return 'failed';
		}

		try {
			$request_options = array(
				'idempotency_key' => Signature::idempotency_key( 'catalog-batch-stream', array( $sync_run['id'], $page ), $secret ),
				'timeout'         => 20,
			);
			if ( ! $background ) {
				/* One bounded attempt keeps every admin AJAX step below host limits. */
				$request_options['max_attempts'] = 1;
				$request_options['timeout']      = 20;
			}
			$this->api_client->request(
				'POST',
				'/v1/integrations/wordpress/catalog/batch-stream',
				$payload,
				$request_options
			);
		} catch ( Api_Exception $exception ) {
			$this->fail_catalog_page( $sync_run, $page, $attempt, $exception, $background );
			return 'failed';
		}
		Options::update_connection( array( 'last_error_code' => '' ) );

		if ( ! $is_final ) {
			$sync_run['next_page']   = $page + 1;
			$sync_run['next_offset'] = $processed;
			Options::update( Options::SYNC_RUN, $sync_run );
			if ( $background && ! $this->schedule( self::CATALOG_ACTION, array( $page + 1, 0, $sync_run['id'] ), time(), false ) ) {
				Options::record_queue_failure();
				Options::update_connection( array( 'last_error_code' => 'catalog_schedule_failed' ) );
				return 'failed';
			}
			return 'advanced';
		}

		Options::delete( Options::SYNC_RUN );
		Options::update( Options::LAST_SYNC, time() );
		Options::clear_queue_failures();
		Options::update_connection( array( 'last_error_code' => '' ) );

		return 'completed';
	}

	/**
	 * Clears only plugin-owned background actions.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		$hooks = array( self::PRODUCT_ACTION, self::TOMBSTONE_ACTION );

		foreach ( $hooks as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), self::ACTION_GROUP );
			}
			wp_clear_scheduled_hook( $hook );
		}

		self::unschedule_catalog_actions();
		Options::delete( Options::SYNC_START_LOCK );
		Options::delete( Options::SYNC_PAGE_LOCK );
	}

	/**
	 * Cancels only this plugin's pending full-catalog page jobs. Every job carries
	 * a run ID as a second isolation layer, so a worker that has already started
	 * can only act on the matching option state.
	 *
	 * @return void
	 */
	private static function unschedule_catalog_actions() {
		if ( function_exists( 'as_get_scheduled_actions' ) && function_exists( 'as_unschedule_action' ) ) {
			/* Cancel pending jobs by their exact args; do not touch other plugin work. */
			for ( $batch = 0; $batch < 100; $batch++ ) {
				$actions = as_get_scheduled_actions(
					array(
						'hook'     => self::CATALOG_ACTION,
						'group'    => self::ACTION_GROUP,
						'status'   => 'pending',
						'per_page' => 100,
					),
					'OBJECT'
				);
				if ( empty( $actions ) || ! is_array( $actions ) ) {
					break;
				}

				foreach ( $actions as $action ) {
					$args = is_object( $action ) && method_exists( $action, 'get_args' ) ? $action->get_args() : array();
					as_unschedule_action( self::CATALOG_ACTION, is_array( $args ) ? $args : array(), self::ACTION_GROUP );
				}
			}
		} elseif ( function_exists( 'as_unschedule_all_actions' ) ) {
			/* Legacy fallback: current catalog work always has explicit action args. */
			as_unschedule_all_actions( self::CATALOG_ACTION, array(), self::ACTION_GROUP );
		}
		wp_clear_scheduled_hook( self::CATALOG_ACTION );
	}

	/**
	 * Uses Action Scheduler when WooCommerce provides it, otherwise a native
	 * single WP-Cron event. Both paths are namespaced and bounded.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Action arguments.
	 * @param int    $timestamp Earliest timestamp.
	 * @param bool   $unique Whether duplicate work should be suppressed.
	 * @return bool
	 */
	private function schedule( $hook, $args, $timestamp, $unique ) {
		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_enqueue_async_action' ) ) {
			if ( $unique && function_exists( 'as_has_scheduled_action' ) && false !== as_has_scheduled_action( $hook, $args, self::ACTION_GROUP ) ) {
				return true;
			}

			if ( $timestamp <= time() ) {
				$scheduled = (bool) as_enqueue_async_action( $hook, $args, self::ACTION_GROUP, $unique );
			} else {
				$scheduled = (bool) as_schedule_single_action( $timestamp, $hook, $args, self::ACTION_GROUP, $unique );
			}

			if ( ! self::should_fallback_to_wp_cron( $scheduled ) ) {
				return true;
			}
		}

		if ( $unique && wp_next_scheduled( $hook, $args ) ) {
			return true;
		}

		return wp_schedule_single_event( max( time() + 1, $timestamp ), $hook, $args );
	}

	/**
	 * Queues an individual product sync.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	private function queue_product_sync( $product_id ) {
		return $this->schedule( self::PRODUCT_ACTION, array( (int) $product_id, 0 ), time(), true );
	}

	/**
	 * @param int $product_id Parent product ID.
	 * @return bool
	 */
	private function queue_product_tombstone( $product_id ) {
		try {
			$revision = Options::next_site_revision();
		} catch ( Api_Exception $exception ) {
			$this->connection->record_error( $exception );
			return false;
		}

		return $this->schedule(
			self::TOMBSTONE_ACTION,
			array( (int) $product_id, $revision, gmdate( 'Y-m-d\\TH:i:s\\Z' ), 0 ),
			time(),
			true
		);
	}

	/**
	 * @param mixed $payload Tombstone payload.
	 * @return bool
	 */
	public static function is_valid_tombstone_payload( $payload ) {
		$allowed = array( 'schema_version', 'site_revision', 'external_id', 'deleted_at' );
		$keys    = is_array( $payload ) ? array_keys( $payload ) : array();
		sort( $allowed );
		sort( $keys );

		return $allowed === $keys && 1 === (int) $payload['schema_version'] && Options::is_valid_site_revision( $payload['site_revision'] ) && (bool) preg_match( '/^[1-9][0-9]*$/', (string) $payload['external_id'] ) && (bool) preg_match( '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/', (string) $payload['deleted_at'] ) && false !== strtotime( (string) $payload['deleted_at'] );
	}

	/**
	 * Chooses the deletion behavior without ever mapping a removed variation to
	 * a parent tombstone. Callers preserve the parent before deletion and defer
	 * its upsert until deleted_post.
	 *
	 * @param bool $is_variation Whether the deleted object is a variation.
	 * @param int  $product_id Deleted product ID.
	 * @param int  $parent_id Parent ID for a variation.
	 * @return array{action:string,product_id:int}
	 */
	public static function hard_delete_plan( $is_variation, $product_id, $parent_id = 0 ) {
		$product_id = max( 0, (int) $product_id );
		$parent_id  = max( 0, (int) $parent_id );

		if ( $is_variation ) {
			return $parent_id > 0 ? array( 'action' => 'parent_upsert', 'product_id' => $parent_id ) : array( 'action' => 'none', 'product_id' => 0 );
		}

		return $product_id > 0 ? array( 'action' => 'tombstone', 'product_id' => $product_id ) : array( 'action' => 'none', 'product_id' => 0 );
	}

	/**
	 * Validates the persisted state for one full catalog reconciliation.
	 *
	 * @param mixed $sync_run Stored run state.
	 * @return bool
	 */
	public static function is_valid_sync_run( $sync_run ) {
		if ( ! is_array( $sync_run ) || ! isset( $sync_run['protocol'] ) || self::SYNC_PROTOCOL !== (string) $sync_run['protocol'] || empty( $sync_run['id'] ) || ! preg_match( '/^[A-Za-z0-9-]{16,128}$/', (string) $sync_run['id'] ) || ! Options::is_valid_site_revision( isset( $sync_run['revision'] ) ? $sync_run['revision'] : '' ) ) {
			return false;
		}

		$next_page   = isset( $sync_run['next_page'] ) ? (int) $sync_run['next_page'] : 0;
		$next_offset = isset( $sync_run['next_offset'] ) ? (int) $sync_run['next_offset'] : -1;
		$total       = isset( $sync_run['initial_total'] ) ? (int) $sync_run['initial_total'] : -1;
		$highest_id  = isset( $sync_run['highest_product_id'] ) ? (int) $sync_run['highest_product_id'] : -1;

		return $next_page >= 1 && $next_page <= self::MAX_PAGE_COUNT && $next_offset >= 0 && $next_offset <= $total && $total >= 0 && $total <= self::BATCH_SIZE * self::MAX_PAGE_COUNT && ( ( 0 === $total && 0 === $highest_id ) || ( $total > 0 && $highest_id > 0 ) );
	}

	/**
	 * Compares the final preflight summary against the immutable run snapshot.
	 * A changed total or highest product ID means offset pagination can no longer
	 * safely prove reconciliation completeness.
	 *
	 * @param mixed $sync_run Persisted run state.
	 * @param mixed $snapshot Current catalog summary.
	 * @return bool
	 */
	public static function catalog_snapshot_matches( $sync_run, $snapshot ) {
		return self::is_valid_sync_run( $sync_run ) && is_array( $snapshot ) && isset( $snapshot['total'], $snapshot['highest_product_id'] ) && (int) $sync_run['initial_total'] === (int) $snapshot['total'] && (int) $sync_run['highest_product_id'] === (int) $snapshot['highest_product_id'];
	}

	/**
	 * Applies the same exact UTF-8 JSON byte ceiling as both catalog endpoints.
	 *
	 * @param mixed $payload Catalog request payload.
	 * @return bool
	 */
	public static function catalog_payload_fits( $payload ) {
		$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return false !== $body && strlen( $body ) <= self::CATALOG_BODY_MAX_BYTES;
	}

	/**
	 * Selects the largest product-atomic prefix that satisfies every batch bound.
	 * A parent product and all of its variations are appended together or not at
	 * all; variations are never continued as another product or later page.
	 *
	 * Each serialized product is JSON-encoded at most once. Traversable inputs are
	 * consumed lazily, so a variation-heavy page stops loading products as soon as
	 * its next parent would exceed a request bound.
	 *
	 * @param iterable<int, array<string, mixed>> $candidates Serialized products.
	 * @param array<string, mixed>                $payload_base Envelope without products.
	 * @return array<int, array<string, mixed>>
	 */
	public static function select_catalog_batch( $candidates, $payload_base ) {
		$selected      = array();
		$variant_count = 0;
		$empty_payload             = (array) $payload_base;
		$empty_payload['products'] = array();
		$empty_body                = wp_json_encode( $empty_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $empty_body || strlen( $empty_body ) > self::CATALOG_BODY_MAX_BYTES || ( ! is_array( $candidates ) && ! ( $candidates instanceof \Traversable ) ) ) {
			return $selected;
		}
		$payload_bytes = strlen( $empty_body );

		foreach ( $candidates as $product ) {
			if ( count( $selected ) >= self::BATCH_SIZE || ! is_array( $product ) || ! isset( $product['variants'] ) || ! is_array( $product['variants'] ) ) {
				break;
			}

			$next_variant_count = $variant_count + count( $product['variants'] );
			if ( $next_variant_count > self::MAX_VARIANTS_PER_BATCH ) {
				break;
			}

			$product_body = wp_json_encode( $product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $product_body ) {
				break;
			}
			$candidate_bytes = $payload_bytes + ( empty( $selected ) ? 0 : 1 ) + strlen( $product_body );
			if ( $candidate_bytes > self::CATALOG_BODY_MAX_BYTES ) {
				break;
			}

			$selected[]    = $product;
			$variant_count = $next_variant_count;
			$payload_bytes = $candidate_bytes;
		}

		return $selected;
	}

	/**
	 * Rejects stale, out-of-order, and legacy page jobs before they load data or
	 * make an outbound request.
	 *
	 * @param mixed  $sync_run Persisted run state.
	 * @param string $sync_run_id Scheduled run ID.
	 * @param int    $page Scheduled page.
	 * @return bool
	 */
	public static function should_process_catalog_page( $sync_run, $sync_run_id, $page ) {
		return self::is_valid_sync_run( $sync_run ) && is_string( $sync_run_id ) && '' !== $sync_run_id && hash_equals( (string) $sync_run['id'], $sync_run_id ) && (int) $sync_run['next_page'] === (int) $page;
	}

	/**
	 * Keeps the documented native WP-Cron fallback available when WooCommerce's
	 * Action Scheduler API is loaded but declines a specific enqueue request.
	 *
	 * @param mixed $action_scheduler_scheduled Action Scheduler result.
	 * @return bool
	 */
	public static function should_fallback_to_wp_cron( $action_scheduler_scheduled ) {
		return ! (bool) $action_scheduler_scheduled;
	}

	/**
	 * @param int $attempt Current retry count.
	 * @return array{retry:bool,next_attempt:int,delay:int}
	 */
	public static function catalog_page_failure_plan( $attempt ) {
		$attempt = max( 0, (int) $attempt );

		return array(
			'retry'        => $attempt < self::MAX_CATALOG_PAGE_RETRIES,
			'next_attempt' => $attempt + 1,
			'delay'        => 60 * ( 1 << min( $attempt, self::MAX_CATALOG_PAGE_RETRIES ) ),
		);
	}

	/**
	 * Preserves a serializer's explicit connector error so terminal conditions
	 * (for example catalog_payload_too_large) are not retried as generic
	 * serialization failures. Unexpected PHP errors remain bounded-retryable.
	 *
	 * @param \Throwable $exception Serialization exception.
	 * @return Api_Exception
	 */
	public static function catalog_serialization_exception( $exception ) {
		if ( $exception instanceof Api_Exception ) {
			return $exception;
		}

		return new Api_Exception( 'A catalog product could not be serialized.', 'catalog_serialization_failed', true );
	}

	/**
	 * Records a safe page failure, retries with the same run/revision/idempotency
	 * inputs when appropriate, and abandons the run after its bounded limit.
	 *
	 * @param array<string, mixed> $sync_run Persisted run state.
	 * @param int                  $page Page number.
	 * @param int                  $attempt Current retry count.
	 * @param Api_Exception        $exception Safe failure.
	 * @param bool                 $schedule_retry Whether a background retry may be queued.
	 * @return void
	 */
	private function fail_catalog_page( $sync_run, $page, $attempt, $exception, $schedule_retry = true ) {
		$this->connection->record_error( $exception );
		Options::record_queue_failure();
		$plan = self::catalog_page_failure_plan( $attempt );

		if ( $exception->is_retryable() && $plan['retry'] ) {
			if ( $schedule_retry ) {
				$this->schedule( self::CATALOG_ACTION, array( (int) $page, $plan['next_attempt'], (string) $sync_run['id'] ), time() + $plan['delay'], false );
			}
			return;
		}

		$current = Options::get( Options::SYNC_RUN, array() );
		if ( self::is_valid_sync_run( $current ) && hash_equals( (string) $sync_run['id'], (string) $current['id'] ) ) {
			Options::delete( Options::SYNC_RUN );
		}
	}

	/**
	 * Drops an unfinalized, drifted run and immediately starts a distinct run.
	 * Earlier accepted pages cannot archive anything because the final page was
	 * never sent; the next run receives a fresh run ID and site revision.
	 *
	 * @param array<string, mixed> $sync_run Drifted run.
	 * @return void
	 */
	private function restart_drifted_catalog_run( $sync_run ) {
		$current = Options::get( Options::SYNC_RUN, array() );
		if ( self::is_valid_sync_run( $current ) && hash_equals( (string) $sync_run['id'], (string) $current['id'] ) ) {
			Options::delete( Options::SYNC_RUN );
		}

		Options::update_connection( array( 'last_error_code' => 'catalog_snapshot_drift' ) );
		if ( ! $this->queue_initial_sync() ) {
			Options::record_queue_failure();
		}
	}

	/**
	 * Returns the current valid run or creates a fresh immutable snapshot.
	 * The caller must hold the start lease.
	 *
	 * @return array<string, mixed>|null
	 */
	private function prepare_catalog_run() {
		$existing_run = Options::get( Options::SYNC_RUN, array() );
		if ( self::is_valid_sync_run( $existing_run ) ) {
			if ( empty( $existing_run['started_at'] ) ) {
				$existing_run['started_at'] = time();
				Options::update( Options::SYNC_RUN, $existing_run );
			}

			return $existing_run;
		}

		/* Jobs from a previous invalid run must never share new option state. */
		self::unschedule_catalog_actions();
		$snapshot = $this->catalog_snapshot();
		if ( null === $snapshot ) {
			Options::update_connection( array( 'last_error_code' => 'catalog_snapshot_unavailable' ) );
			return null;
		}

		try {
			$revision = Options::next_site_revision();
		} catch ( Api_Exception $exception ) {
			$this->connection->record_error( $exception );
			return null;
		}

		$sync_run = array(
			'id'                 => wp_generate_uuid4(),
			'protocol'           => self::SYNC_PROTOCOL,
			'revision'           => $revision,
			'next_page'          => 1,
			'next_offset'        => 0,
			'initial_total'      => $snapshot['total'],
			'highest_product_id' => $snapshot['highest_product_id'],
			'started_at'         => time(),
			'last_attempt_at'    => 0,
		);
		Options::update( Options::SYNC_RUN, $sync_run );
		Options::update_connection( array( 'last_error_code' => '' ) );

		return $sync_run;
	}

	/**
	 * Returns a non-sensitive snapshot for the native diagnostics table.
	 *
	 * @return array<string, mixed>
	 */
	public function catalog_status() {
		$sync_run = Options::get( Options::SYNC_RUN, array() );
		$active   = self::is_valid_sync_run( $sync_run );

		return array(
			'active'                     => $active,
			'next_page'                  => $active ? (int) $sync_run['next_page'] : 0,
			'processed_products'         => $active ? (int) $sync_run['next_offset'] : 0,
			'total_products'             => $active ? (int) $sync_run['initial_total'] : 0,
			'started_at'                 => $active && ! empty( $sync_run['started_at'] ) ? (int) $sync_run['started_at'] : 0,
			'last_attempt_at'            => (int) Options::get( Options::LAST_SYNC_ATTEMPT, 0 ),
			'action_scheduler_available' => function_exists( 'as_enqueue_async_action' ),
			'wp_cron_disabled'            => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'queue_failures'              => Options::queue_failure_count(),
		);
	}

	/**
	 * @param string $status completed, running, busy, or failed.
	 * @param int    $processed_page Last page attempted or accepted.
	 * @param int    $processed_products Number of parent products accepted.
	 * @param int    $total_products Immutable parent-product total.
	 * @param string $code Safe diagnostic code.
	 * @return array<string, mixed>
	 */
	private static function manual_result( $status, $processed_page, $processed_products, $total_products, $code ) {
		return array(
			'status'             => (string) $status,
			'processed_page'     => max( 0, (int) $processed_page ),
			'processed_products' => max( 0, (int) $processed_products ),
			'total_products'     => max( 0, (int) $total_products ),
			'code'               => sanitize_key( (string) $code ),
		);
	}

	/**
	 * @param array<string, mixed> $sync_run Current run.
	 * @return bool
	 */
	private function resume_catalog_run( $sync_run ) {
		if ( $this->has_pending_catalog_action() ) {
			return true;
		}

		/* A stale in-progress claim must not suppress a safe, lease-guarded retry. */
		return $this->schedule( self::CATALOG_ACTION, array( (int) $sync_run['next_page'], 0, (string) $sync_run['id'] ), time(), false );
	}

	/**
	 * @return bool
	 */
	private function has_pending_catalog_action() {
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions(
				array(
					'hook'     => self::CATALOG_ACTION,
					'group'    => self::ACTION_GROUP,
					'status'   => 'pending',
					'per_page' => 1,
				),
				'ids'
			);

			return is_array( $pending ) && ! empty( $pending );
		}

		return false !== wp_next_scheduled( self::CATALOG_ACTION );
	}


	/**
	 * @return bool
	 */
	private function acquire_start_lock() {
		try {
			$token = (string) time() . ':' . bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $exception ) {
			unset( $exception );
			return false;
		}

		if ( ! Options::acquire_lease_lock( Options::SYNC_START_LOCK, $token, time(), self::START_LOCK_TTL_SECONDS ) ) {
			return false;
		}

		$this->start_lock_token = $token;

		return true;
	}

	/**
	 * @return void
	 */
	private function release_start_lock() {
		if ( '' !== $this->start_lock_token ) {
			Options::release_lease_lock( Options::SYNC_START_LOCK, $this->start_lock_token );
			$this->start_lock_token = '';
		}
	}

	/**
	 * @return bool
	 */
	private function acquire_page_lock() {
		try {
			$token = (string) time() . ':' . bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $exception ) {
			unset( $exception );
			return false;
		}

		if ( ! Options::acquire_lease_lock( Options::SYNC_PAGE_LOCK, $token, time(), self::PAGE_LOCK_TTL_SECONDS ) ) {
			return false;
		}

		$this->page_lock_token = $token;

		return true;
	}

	/**
	 * @return void
	 */
	private function release_page_lock() {
		if ( '' !== $this->page_lock_token ) {
			Options::release_lease_lock( Options::SYNC_PAGE_LOCK, $this->page_lock_token );
			$this->page_lock_token = '';
		}
	}

	/**
	 * Captures the bounded offset-pagination guard before a full reconciliation.
	 * A zero-item catalog still uses one empty final page. The highest ID and
	 * total are rechecked immediately before that final page is posted.
	 *
	 * @return array{total:int,highest_product_id:int}|null
	 */
	private function catalog_snapshot() {
		$results = wc_get_products(
			array(
				'limit'    => 1,
				'page'     => 1,
				'paginate' => true,
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'status'   => 'any',
				'type'     => array( 'simple', 'variable' ),
			)
		);

		if ( ! is_object( $results ) || ! isset( $results->total ) ) {
			return null;
		}

		$total      = max( 0, (int) $results->total );
		if ( $total > self::BATCH_SIZE * self::MAX_PAGE_COUNT ) {
			return null;
		}

		$highest_product_id = 0;
		if ( $total > 0 ) {
			$highest = wc_get_products(
				array(
					'limit'    => 1,
					'orderby'  => 'ID',
					'order'    => 'DESC',
					'paginate' => false,
					'status'   => 'any',
					'type'     => array( 'simple', 'variable' ),
				)
			);
			if ( ! is_array( $highest ) || empty( $highest ) || ! is_object( $highest[0] ) || ! method_exists( $highest[0], 'get_id' ) ) {
				return null;
			}
			$highest_product_id = (int) $highest[0]->get_id();
			if ( $highest_product_id < 1 ) {
				return null;
			}
		}

		return array(
			'total'              => $total,
			'highest_product_id' => $highest_product_id,
		);
	}
}
