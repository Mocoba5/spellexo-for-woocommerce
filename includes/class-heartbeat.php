<?php
/**
 * Bounded, outbound-only connection heartbeat.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Heartbeat {
	const ACTION = 'spellexo_woocommerce_heartbeat';
	const INTERVAL_SECONDS = 3600;
	const LOCK_TTL_SECONDS = 900;
	const QUEUE_SCAN_LIMIT = 1000;

	/** @var Connection */
	private $connection;

	/** @var Api_Client */
	private $api_client;

	/** @var Sync */
	private $sync;

	/** @var string Lease token held only for this request. */
	private $lock_token = '';

	/**
	 * @param Connection|null $connection Connection manager override.
	 * @param Api_Client|null $api_client API client override.
	 * @param Sync|null       $sync Synchronization manager override.
	 */
	public function __construct( $connection = null, $api_client = null, $sync = null ) {
		$this->connection = $connection instanceof Connection ? $connection : new Connection();
		$this->api_client = $api_client instanceof Api_Client ? $api_client : new Api_Client();
		$this->sync       = $sync instanceof Sync ? $sync : new Sync( $this->connection, $this->api_client );
	}

	/**
	 * @return void
	 */
	public function register() {
		add_action( self::ACTION, array( $this, 'send' ), 10, 1 );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 25 );
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
		add_action( 'spellexo_woocommerce_connected', array( $this, 'ensure_scheduled' ) );
		add_action( 'spellexo_woocommerce_disconnected', array( __CLASS__, 'unschedule_all' ) );
	}

	/**
	 * Provides the WP-Cron fallback interval when Action Scheduler is absent.
	 *
	 * @param array<string, array<string,mixed>> $schedules Existing schedules.
	 * @return array<string, array<string,mixed>>
	 */
	public function add_cron_schedule( $schedules ) {
		$schedules['spellexo_hourly'] = array(
			'interval' => self::INTERVAL_SECONDS,
			'display'  => __( 'Once Hourly (Spellexo)', 'spellexo-for-woocommerce' ),
		);

		return $schedules;
	}

	/**
	 * Schedules one non-overlapping hourly action only after explicit connection.
	 *
	 * @return bool
	 */
	public function ensure_scheduled() {
		if ( ! $this->connection->is_connected() ) {
			return false;
		}

		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' ) && false !== as_has_scheduled_action( self::ACTION, array(), Sync::ACTION_GROUP ) ) {
				return true;
			}

			return (bool) as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, self::INTERVAL_SECONDS, self::ACTION, array(), Sync::ACTION_GROUP, true );
		}

		if ( wp_next_scheduled( self::ACTION ) ) {
			return true;
		}

		return false !== wp_schedule_event( time() + MINUTE_IN_SECONDS, 'spellexo_hourly', self::ACTION );
	}

	/**
	 * Sends one health-only heartbeat. Api_Client performs its own bounded,
	 * same-body/same-idempotency-key retry loop; no additional job retry is
	 * scheduled, preventing changed payloads under a reused key.
	 *
	 * @param int $attempt Reserved Action Scheduler argument for future use.
	 * @return void
	 */
	public function send( $attempt = 0 ) {
		unset( $attempt );

		if ( ! $this->connection->is_connected() ) {
			self::unschedule_all();
			return;
		}

		if ( ! $this->acquire_lock() ) {
			return;
		}

		try {
			$secret = Secret_Store::get();
			if ( null === $secret ) {
				throw new Api_Exception( 'The local connection credential is unavailable.', 'credential_unavailable' );
			}

			$payload = $this->payload();
			$body    = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $body ) {
				throw new Api_Exception( 'The heartbeat payload could not be encoded.', 'heartbeat_encoding_failed' );
			}

			$response = $this->api_client->request(
				'POST',
				'/v1/integrations/wordpress/heartbeat',
				$payload,
				array(
					'idempotency_key' => Signature::idempotency_key( 'heartbeat', array( Signature::body_hash( $body ) ), $secret ),
				)
			);

			if ( ! self::is_valid_response_body( $response['body'] ) ) {
				throw new Api_Exception( 'The heartbeat response was invalid.', 'invalid_heartbeat_response' );
			}

			$this->handle_commands( $response['body'] );
			Options::update_connection(
				array(
					'last_heartbeat_at' => time(),
					'last_error_code'   => '',
				)
			);
		} catch ( Api_Exception $exception ) {
			$this->connection->record_error( $exception );
			Options::record_queue_failure();
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Clears only this plugin's recurring heartbeat work.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION, array(), Sync::ACTION_GROUP );
		}
		wp_clear_scheduled_hook( self::ACTION );
		Options::delete( Options::HEARTBEAT_LOCK );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function payload() {
		$connection = Options::connection();
		$checks     = ( new Compatibility() )->checks();
		$site_url   = Url::canonical_site_url();
		$origin     = Url::canonical_origin();

		$blocks_attribution_supported = Commerce_Analytics::blocks_attribution_supported();

		return array(
			'schema_version'            => 1,
			'plugin_version'            => SPELLEXO_WC_VERSION,
			'wordpress_version'         => get_bloginfo( 'version' ),
			'woocommerce_version'       => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'php_version'               => PHP_VERSION,
			'canonical_site_url'        => $site_url,
			'canonical_origin'          => $origin,
			'capabilities'              => array(
				'https'                    => true === $checks['https'],
				'hpos_compatible'          => true,
				'cart_checkout_blocks'     => $blocks_attribution_supported,
				'action_scheduler'          => function_exists( 'as_schedule_recurring_action' ),
				'classic_attribution'      => true,
				'store_api_attribution'    => $blocks_attribution_supported,
				'origin_matches_connection'=> $site_url === (string) $connection['canonical_site_url'] && $origin === (string) $connection['canonical_origin'],
			),
			'queue'                     => $this->queue_metrics(),
			'catalog'                   => array(
				'product_count'           => $this->post_count( 'product' ),
				'variation_count'         => $this->post_count( 'product_variation' ),
				'last_completed_sync_at'  => $this->completed_sync_at(),
			),
			'acknowledged_command_ids'  => Options::acknowledged_command_ids(),
		);
	}

	/**
	 * @return array{depth:int,failures:int,oldest_pending_at:string|null}
	 */
	private function queue_metrics() {
		$metrics = array(
			'depth'             => 0,
			'failures'          => Options::queue_failure_count(),
			'oldest_pending_at' => null,
		);
		$work_hooks = self::work_queue_hooks();

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			foreach ( $work_hooks as $hook ) {
				$pending = as_get_scheduled_actions(
					array(
						'hook'     => $hook,
						'group'    => Sync::ACTION_GROUP,
						'status'   => 'pending',
						'per_page' => self::QUEUE_SCAN_LIMIT,
						'orderby'  => 'date',
						'order'    => 'ASC',
					),
					'OBJECT'
				);
				$failed = as_get_scheduled_actions(
					array(
						'hook'     => $hook,
						'group'    => Sync::ACTION_GROUP,
						'status'   => 'failed',
						'per_page' => self::QUEUE_SCAN_LIMIT,
					),
					'ids'
				);

				$metrics['depth']    = min( self::QUEUE_SCAN_LIMIT, $metrics['depth'] + ( is_array( $pending ) ? count( $pending ) : 0 ) );
				$metrics['failures'] = max( $metrics['failures'], min( self::QUEUE_SCAN_LIMIT, is_array( $failed ) ? count( $failed ) : 0 ) );
				$first_pending = self::first_scheduled_action( $pending );
				if ( null !== $first_pending ) {
					$pending_at = $this->scheduled_action_time( $first_pending );
					if ( null !== $pending_at && ( null === $metrics['oldest_pending_at'] || strtotime( $pending_at ) < strtotime( $metrics['oldest_pending_at'] ) ) ) {
						$metrics['oldest_pending_at'] = $pending_at;
					}
				}
			}

			return $metrics;
		}

		$crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		foreach ( is_array( $crons ) ? $crons : array() as $timestamp => $hooks ) {
			foreach ( $work_hooks as $hook ) {
				if ( empty( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
					continue;
				}

				$metrics['depth'] += count( $hooks[ $hook ] );
				if ( null === $metrics['oldest_pending_at'] || (int) $timestamp < strtotime( $metrics['oldest_pending_at'] ) ) {
					$metrics['oldest_pending_at'] = gmdate( 'Y-m-d\\TH:i:s\\Z', (int) $timestamp );
				}
			}
		}

		$metrics['depth'] = min( self::QUEUE_SCAN_LIMIT, $metrics['depth'] );

		return $metrics;
	}

	/**
	 * Returns only finite connector work hooks; the recurring heartbeat itself is
	 * deliberately excluded from backlog health metrics.
	 *
	 * @return string[]
	 */
	public static function work_queue_hooks() {
		return array( Sync::PRODUCT_ACTION, Sync::CATALOG_ACTION, Sync::TOMBSTONE_ACTION, Commerce_Analytics::CART_ACTION, Commerce_Analytics::PURCHASE_ACTION );
	}

	/**
	 * Action Scheduler's object query results are keyed by action ID, which need
	 * not start at zero. Return the first ordered action without assuming a
	 * numeric zero key or trusting an invalid result value.
	 *
	 * @param mixed $actions Action Scheduler query result.
	 * @return object|null
	 */
	public static function first_scheduled_action( $actions ) {
		if ( ! is_array( $actions ) || empty( $actions ) ) {
			return null;
		}

		$action = reset( $actions );

		return is_object( $action ) ? $action : null;
	}

	/**
	 * @param mixed $action Action Scheduler action.
	 * @return string|null
	 */
	private function scheduled_action_time( $action ) {
		if ( ! is_object( $action ) || ! method_exists( $action, 'get_schedule' ) ) {
			return null;
		}

		$schedule = $action->get_schedule();
		$date     = is_object( $schedule ) && method_exists( $schedule, 'get_date' ) ? $schedule->get_date() : null;

		return is_object( $date ) && method_exists( $date, 'getTimestamp' ) ? gmdate( 'Y-m-d\\TH:i:s\\Z', (int) $date->getTimestamp() ) : null;
	}

	/**
	 * @param string $post_type Product post type.
	 * @return int
	 */
	private function post_count( $post_type ) {
		$counts = function_exists( 'wp_count_posts' ) ? wp_count_posts( $post_type ) : null;
		$total  = 0;

		foreach ( is_object( $counts ) ? get_object_vars( $counts ) : array() as $status => $count ) {
			if ( 'trash' !== $status ) {
				$total += max( 0, (int) $count );
			}
		}

		return min( 100000000, $total );
	}

	/**
	 * @return string|null
	 */
	private function completed_sync_at() {
		$timestamp = (int) Options::get( Options::LAST_SYNC, 0 );

		return $timestamp > 0 ? gmdate( 'Y-m-d\\TH:i:s\\Z', $timestamp ) : null;
	}

	/**
	 * @param array<string, mixed> $body Verified heartbeat body.
	 * @return bool
	 */
	public static function is_valid_response_body( $body ) {
		$allowed = array( 'commands', 'integration_status', 'origin_change_pending', 'schema_version', 'server_time', 'status' );
		$keys    = is_array( $body ) ? array_keys( $body ) : array();
		sort( $allowed );
		sort( $keys );

		$integration_statuses = array( 'pairing', 'active', 'stale', 'origin_change_pending', 'revoked' );
		$server_time          = is_array( $body ) && isset( $body['server_time'] ) ? (string) $body['server_time'] : '';
		if (
			! is_array( $body ) ||
			$allowed !== $keys ||
			1 !== (int) $body['schema_version'] ||
			'ok' !== $body['status'] ||
			! in_array( $body['integration_status'], $integration_statuses, true ) ||
			! is_bool( $body['origin_change_pending'] ) ||
			! preg_match( '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,3})?Z$/', $server_time ) ||
			false === strtotime( $server_time ) ||
			! is_array( $body['commands'] ) ||
			count( $body['commands'] ) > 25
		) {
			return false;
		}

		foreach ( $body['commands'] as $command ) {
			if ( ! self::is_valid_command( $command, false ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param mixed $command Signed heartbeat command.
	 * @return bool
	 */
	public static function is_allowed_command( $command ) {
		return self::is_valid_command( $command, true );
	}

	/**
	 * @param mixed $command Signed command.
	 * @param bool  $require_unexpired Whether an expired command may be acted on.
	 * @return bool
	 */
	private static function is_valid_command( $command, $require_unexpired ) {
		$allowed = array( 'id', 'type', 'schema_version', 'expires_at', 'payload' );
		$keys    = is_array( $command ) ? array_keys( $command ) : array();
		sort( $allowed );
		sort( $keys );
		if ( ! is_array( $command ) || $allowed !== $keys || ! self::is_valid_command_id( $command['id'] ) || 'request_reconciliation' !== (string) $command['type'] || 1 !== (int) $command['schema_version'] || ! is_array( $command['payload'] ) ) {
			return false;
		}

		$payload_keys = array_keys( $command['payload'] );
		sort( $payload_keys );
		$expires_at = strtotime( (string) $command['expires_at'] );

		return array( 'reason' ) === $payload_keys
			&& in_array( isset( $command['payload']['reason'] ) ? (string) $command['payload']['reason'] : '', array( 'manual', 'catalog_drift', 'recovery' ), true )
			&& false !== $expires_at
			&& ( ! $require_unexpired || $expires_at > time() );
	}

	/**
	 * @param mixed $command_id Backend command UUID.
	 * @return bool
	 */
	public static function is_valid_command_id( $command_id ) {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) $command_id );
	}

	/**
	 * Applies only known, signed command types and acknowledges them after their
	 * local side effect has been queued successfully.
	 *
	 * @param array<string, mixed> $body Verified heartbeat response.
	 * @return void
	 */
	private function handle_commands( $body ) {
		$acknowledged = Options::acknowledged_command_ids();

		foreach ( isset( $body['commands'] ) && is_array( $body['commands'] ) ? $body['commands'] : array() as $command ) {
			if ( ! self::is_allowed_command( $command ) ) {
				continue;
			}

			$command_id = (string) $command['id'];
			if ( in_array( $command_id, $acknowledged, true ) ) {
				continue;
			}

			if ( $this->sync->queue_initial_sync() ) {
				Options::acknowledge_command( $command_id );
				Audit_Log::record( 'heartbeat_reconciliation_requested' );
				$acknowledged[] = $command_id;
			}
		}
	}

	/**
	 * @return bool
	 */
	private function acquire_lock() {
		try {
			$token = (string) time() . ':' . bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $exception ) {
			unset( $exception );
			return false;
		}

		if ( ! Options::acquire_lease_lock( Options::HEARTBEAT_LOCK, $token, time(), self::LOCK_TTL_SECONDS ) ) {
			return false;
		}

		$this->lock_token = $token;

		return true;
	}

	/**
	 * @return void
	 */
	private function release_lock() {
		if ( '' !== $this->lock_token ) {
			Options::release_lease_lock( Options::HEARTBEAT_LOCK, $this->lock_token );
			$this->lock_token = '';
		}
	}
}
