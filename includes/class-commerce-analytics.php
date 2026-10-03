<?php
/**
 * Privacy-minimized WooCommerce attribution transport and analytics.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Commerce_Analytics {
	const ACTION_GROUP = 'spellexo_woocommerce';
	const CART_ACTION = 'spellexo_woocommerce_send_cart_analytics';
	const PURCHASE_ACTION = 'spellexo_woocommerce_send_purchase_analytics';
	const TOKEN_TTL_SECONDS = 86400;
	const MAX_DELIVERY_ATTEMPTS = 2;
	const CART_TOKEN_META = '_spellexo_attribution';
	const CART_TOKEN_CREATED_META = '_spellexo_attribution_created_at';
	const ORDER_EVENT_KEY_META = '_spellexo_purchase_event_key';
	const ORDER_TOKEN_META = '_spellexo_attribution';
	const ORDER_TOKEN_EXPIRES_META = '_spellexo_attribution_expires_at';
	const ORDER_PRODUCT_ID_META = '_spellexo_purchase_product_id';
	const ORDER_VARIANT_ID_META = '_spellexo_purchase_variant_id';
	const ORDER_QUANTITY_META = '_spellexo_purchase_quantity';
	const ORDER_OCCURRED_AT_META = '_spellexo_purchase_occurred_at';
	const ORDER_SENT_AT_META = '_spellexo_purchase_sent_at';
	const STORE_API_NAMESPACE = 'spellexo-for-woocommerce';
	const STORE_API_SESSION_KEY = 'spellexo_woocommerce_store_api_attribution';
	const MINIMUM_BLOCKS_WOOCOMMERCE = '7.3';
	const MAX_EVENT_QUANTITY = 1000;

	/** @var Connection */
	private $connection;

	/** @var Api_Client */
	private $api_client;

	/** @var array<string, mixed>|null Request-scoped Store API context. */
	private $store_api_request_context = null;

	/**
	 * @param Connection|null $connection Connection manager override.
	 * @param Api_Client|null $api_client API client override.
	 */
	public function __construct( $connection = null, $api_client = null ) {
		$this->connection = $connection instanceof Connection ? $connection : new Connection();
		$this->api_client = $api_client instanceof Api_Client ? $api_client : new Api_Client();
	}

	/**
	 * Returns the earliest WooCommerce version supported by the complete Store
	 * API attribution adapter. This is used for the compatibility declaration,
	 * which runs before Blocks registers its runtime helper functions.
	 *
	 * @return bool
	 */
	public static function blocks_attribution_version_supported() {
		return defined( 'WC_VERSION' ) && version_compare( (string) WC_VERSION, self::MINIMUM_BLOCKS_WOOCOMMERCE, '>=' );
	}

	/**
	 * Returns whether the current request exposes the official Blocks extension
	 * callback required by the Store API handoff. Heartbeats use this runtime
	 * check rather than claiming support merely from a version string.
	 *
	 * @return bool
	 */
	public static function blocks_attribution_supported() {
		return self::blocks_attribution_version_supported() && function_exists( 'woocommerce_store_api_register_update_callback' );
	}

	/**
	 * @return void
	 */
	public function register() {
		/* Do not use woocommerce_add_cart_item_data: it participates in cart-item keys. */
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_extension' ), 20 );
		add_filter( 'woocommerce_store_api_add_to_cart_data', array( $this, 'capture_store_api_add_to_cart_data' ), 20, 2 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'capture_cart_attribution' ), 20, 6 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'restore_cart_attribution' ), 20, 3 );
		add_action( 'woocommerce_before_checkout_process', array( $this, 'prune_cart_attribution' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'copy_attribution_to_order_item' ), 20, 4 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'ensure_store_api_order_attribution' ), 20 );
		add_action( 'woocommerce_payment_complete', array( $this, 'queue_order_purchases' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'queue_order_purchases' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'queue_order_purchases' ), 20 );
		add_action( self::CART_ACTION, array( $this, 'send_cart_event' ), 10, 2 );
		add_action( self::PURCHASE_ACTION, array( $this, 'send_purchase_event' ), 10, 4 );
		add_action( 'spellexo_woocommerce_disconnected', array( __CLASS__, 'unschedule_all' ) );
		add_action( 'init', array( __CLASS__, 'purge_expired_events' ), 30 );
	}

	/**
	 * Registers a namespaced, official Store API extension callback once Blocks
	 * has initialized its shared ExtendSchema instance. No custom REST route or
	 * browser fetch interception is used.
	 *
	 * @return void
	 */
	public function register_store_api_extension() {
		if ( self::blocks_attribution_supported() ) {
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::STORE_API_NAMESPACE,
					'callback'  => array( $this, 'store_api_update_attribution' ),
				)
			);
		}
	}

	/**
	 * Stores a validated viewer attribution context in the current WC session.
	 * The official cart/extensions callback runs before a later Store API add-item
	 * request; no opaque token is added to cart_item_data or its merge key.
	 *
	 * @param mixed $data Namespaced extension data.
	 * @return void
	 */
	public function store_api_update_attribution( $data ) {
		$session = $this->store_api_session();
		if ( ! is_object( $session ) ) {
			return;
		}

		if ( is_array( $data ) && ! empty( $data['clear'] ) ) {
			$this->clear_store_api_session_context( $session );
			return;
		}

		$context = self::store_api_context_from_data( $data );
		if ( ! $this->connection->is_connected() || null === $context ) {
			$this->clear_store_api_session_context( $session );
			return;
		}

		$session->set( self::STORE_API_SESSION_KEY, $context );
	}

	/**
	 * Enables one request-scoped handoff for the official Store API add-item
	 * filter. The returned data is deliberately unchanged, preserving Woo's cart
	 * merge identity; woocommerce_add_to_cart attaches private data afterwards.
	 *
	 * @param mixed  $add_to_cart_data Woo Store API add-to-cart data.
	 * @param object $request WP_REST_Request instance.
	 * @return mixed
	 */
	public function capture_store_api_add_to_cart_data( $add_to_cart_data, $request ) {
		$this->store_api_request_context = null;
		$context                         = $this->store_api_session_context();
		$request_id                      = is_object( $request ) && method_exists( $request, 'get_param' ) ? (int) $request->get_param( 'id' ) : 0;

		if ( null !== $context && $request_id > 0 && self::store_api_context_can_target_product( $context, $request_id ) ) {
			$this->store_api_request_context = $context;
		}

		return $add_to_cart_data;
	}

	/**
	 * Captures an opaque classic-form or Store API token only after WooCommerce
	 * has already calculated the cart item key. Updating this private line value
	 * therefore never changes WooCommerce's merge identity.
	 *
	 * @param string $cart_item_key Woo cart item key.
	 * @param int    $product_id Product ID.
	 * @param int    $quantity Added quantity.
	 * @param int    $variation_id Variation ID.
	 * @param array  $variation Variation attributes.
	 * @param array  $cart_item_data Original cart item data.
	 * @return void
	 */
	public function capture_cart_attribution( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		unset( $variation, $cart_item_data );

		if ( ! $this->connection->is_connected() ) {
			return;
		}

		$token = $this->posted_attribution_token();
		if ( null === $token ) {
			$token = $this->consume_store_api_attribution( (int) $product_id, (int) $variation_id );
		}
		$cart  = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
		if ( null === $token || ! is_object( $cart ) || ! isset( $cart->cart_contents[ $cart_item_key ] ) ) {
			return;
		}

		$created_at = time();
		$cart->cart_contents[ $cart_item_key ][ self::CART_TOKEN_META ] = $token;
		$cart->cart_contents[ $cart_item_key ][ self::CART_TOKEN_CREATED_META ] = $created_at;
		if ( method_exists( $cart, 'set_session' ) ) {
			$cart->set_session();
		}

		$this->queue_cart_event(
			$token,
			(int) $product_id,
			(int) $variation_id,
			max( 1, (int) $quantity ),
			$created_at
		);
	}

	/**
	 * Store API checkout has a documented order-processed action. The normal
	 * Woo CRUD line-item hook already carries cart metadata, but this narrowly
	 * fills any absent private metadata before payment to preserve Blocks parity.
	 *
	 * @param object $order Woo order.
	 * @return void
	 */
	public function ensure_store_api_order_attribution( $order ) {
		$cart    = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
		$changed = false;

		if ( ! $this->connection->is_connected() || ! is_object( $order ) || ! method_exists( $order, 'get_items' ) || ! is_object( $cart ) || empty( $cart->cart_contents ) || ! is_array( $cart->cart_contents ) ) {
			return;
		}

		foreach ( (array) $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) || self::is_valid_event_key( (string) $item->get_meta( self::ORDER_EVENT_KEY_META, true ) ) ) {
				continue;
			}

			$product_id   = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
			$variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
			foreach ( $cart->cart_contents as $cart_item ) {
				if ( ! is_array( $cart_item ) || $product_id !== (int) ( isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : 0 ) || $variation_id !== (int) ( isset( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : 0 ) ) {
					continue;
				}

				$before = (string) $item->get_meta( self::ORDER_EVENT_KEY_META, true );
				$this->copy_attribution_to_order_item( $item, '', $cart_item, $order );
				if ( '' !== (string) $item->get_meta( self::ORDER_EVENT_KEY_META, true ) && $before !== (string) $item->get_meta( self::ORDER_EVENT_KEY_META, true ) ) {
					$changed = true;
				}
				break;
			}
		}

		if ( $changed && method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/**
	 * Removes expired/private values while restoring a WC cart session.
	 *
	 * @param array  $session_data Restored item data.
	 * @param array  $values Stored session values.
	 * @param string $cart_item_key Cart item key.
	 * @return array
	 */
	public function restore_cart_attribution( $session_data, $values, $cart_item_key ) {
		unset( $cart_item_key );

		if ( ! is_array( $session_data ) ) {
			return $session_data;
		}

		if ( empty( $values[ self::CART_TOKEN_META ] ) || ! self::is_valid_attribution_token( (string) $values[ self::CART_TOKEN_META ] ) || self::token_expired( isset( $values[ self::CART_TOKEN_CREATED_META ] ) ? (int) $values[ self::CART_TOKEN_CREATED_META ] : 0 ) ) {
			unset( $session_data[ self::CART_TOKEN_META ], $session_data[ self::CART_TOKEN_CREATED_META ] );
			return $session_data;
		}

		$session_data[ self::CART_TOKEN_META ] = (string) $values[ self::CART_TOKEN_META ];
		$session_data[ self::CART_TOKEN_CREATED_META ] = (int) $values[ self::CART_TOKEN_CREATED_META ];

		return $session_data;
	}

	/**
	 * @return void
	 */
	public function prune_cart_attribution() {
		$cart = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
		$changed = false;

		if ( ! is_object( $cart ) || empty( $cart->cart_contents ) || ! is_array( $cart->cart_contents ) ) {
			return;
		}

		foreach ( $cart->cart_contents as $cart_item_key => $cart_item ) {
			if ( ! is_array( $cart_item ) || empty( $cart_item[ self::CART_TOKEN_META ] ) || ! self::is_valid_attribution_token( (string) $cart_item[ self::CART_TOKEN_META ] ) || self::token_expired( isset( $cart_item[ self::CART_TOKEN_CREATED_META ] ) ? (int) $cart_item[ self::CART_TOKEN_CREATED_META ] : 0 ) ) {
				if ( isset( $cart->cart_contents[ $cart_item_key ][ self::CART_TOKEN_META ] ) ) {
					unset( $cart->cart_contents[ $cart_item_key ][ self::CART_TOKEN_META ], $cart->cart_contents[ $cart_item_key ][ self::CART_TOKEN_CREATED_META ] );
					$changed = true;
				}
			}
		}

		if ( $changed && method_exists( $cart, 'set_session' ) ) {
			$cart->set_session();
		}
	}

	/**
	 * Copies only private attribution/event metadata onto an order line. It does
	 * not expose a value to shopper-facing item displays or change line totals.
	 *
	 * @param object $item Woo order item.
	 * @param string $cart_item_key Cart item key.
	 * @param array  $values Cart item data.
	 * @param object $order Woo order.
	 * @return void
	 */
	public function copy_attribution_to_order_item( $item, $cart_item_key, $values, $order ) {
		unset( $cart_item_key, $order );

		$token = is_array( $values ) && isset( $values[ self::CART_TOKEN_META ] ) ? (string) $values[ self::CART_TOKEN_META ] : '';
		$created_at = is_array( $values ) && isset( $values[ self::CART_TOKEN_CREATED_META ] ) ? (int) $values[ self::CART_TOKEN_CREATED_META ] : 0;

		if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) || ! self::is_valid_attribution_token( $token ) || self::token_expired( $created_at ) || ( method_exists( $item, 'get_meta' ) && self::is_valid_event_key( (string) $item->get_meta( self::ORDER_EVENT_KEY_META, true ) ) ) ) {
			return;
		}

		$product_id   = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
		$variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
		$quantity     = method_exists( $item, 'get_quantity' ) ? max( 1, (int) $item->get_quantity() ) : 1;
		if ( $product_id < 1 ) {
			return;
		}

		$item->add_meta_data( self::ORDER_TOKEN_META, $token, true );
		$item->add_meta_data( self::ORDER_TOKEN_EXPIRES_META, $created_at + self::TOKEN_TTL_SECONDS, true );
		$item->add_meta_data( self::ORDER_EVENT_KEY_META, self::new_event_key(), true );
		$item->add_meta_data( self::ORDER_PRODUCT_ID_META, (string) $product_id, true );
		$item->add_meta_data( self::ORDER_VARIANT_ID_META, self::variant_id( $product_id, $variation_id ), true );
		$item->add_meta_data( self::ORDER_QUANTITY_META, $quantity, true );
		$item->add_meta_data( self::ORDER_OCCURRED_AT_META, gmdate( 'Y-m-d\\TH:i:s\\Z' ), true );
	}

	/**
	 * Queues persistent purchase events only for an order progressing through a
	 * payment/fulfillment status. Repeated status transitions are safe because
	 * every line uses one persisted 64-hex event key.
	 *
	 * @param int $order_id Woo order ID.
	 * @return void
	 */
	public function queue_order_purchases( $order_id ) {
		if ( ! $this->connection->is_connected() || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( (int) $order_id );
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		foreach ( (array) $order->get_items( 'line_item' ) as $item_id => $item ) {
			$event_key = is_object( $item ) && method_exists( $item, 'get_meta' ) ? (string) $item->get_meta( self::ORDER_EVENT_KEY_META, true ) : '';
			$sent_at   = is_object( $item ) && method_exists( $item, 'get_meta' ) ? (int) $item->get_meta( self::ORDER_SENT_AT_META, true ) : 0;

			if ( self::is_valid_event_key( $event_key ) && $sent_at < 1 ) {
				$this->schedule( self::PURCHASE_ACTION, array( (int) $order_id, (int) $item_id, $event_key, 0 ), time(), true );
			}
		}
	}

	/**
	 * @param string $event_key Opaque event key.
	 * @param int    $attempt Delivery attempt.
	 * @return void
	 */
	public function send_cart_event( $event_key, $attempt = 0 ) {
		$this->send_encrypted_event( (string) $event_key, (int) $attempt, '/v1/integrations/wordpress/analytics/carts', self::CART_ACTION );
	}

	/**
	 * @param int    $order_id Woo order ID.
	 * @param int    $item_id Woo order item ID.
	 * @param string $event_key Persisted opaque event key.
	 * @param int    $attempt Delivery attempt.
	 * @return void
	 */
	public function send_purchase_event( $order_id, $item_id, $event_key, $attempt = 0 ) {
		if ( ! $this->connection->is_connected() || ! function_exists( 'wc_get_order' ) || ! self::is_valid_event_key( (string) $event_key ) ) {
			return;
		}

		$order = wc_get_order( (int) $order_id );
		$item  = $order && method_exists( $order, 'get_item' ) ? $order->get_item( (int) $item_id ) : null;
		if ( ! $item || ! method_exists( $item, 'get_meta' ) || (string) $event_key !== (string) $item->get_meta( self::ORDER_EVENT_KEY_META, true ) || (int) $item->get_meta( self::ORDER_SENT_AT_META, true ) > 0 ) {
			return;
		}

		$expires_at = (int) $item->get_meta( self::ORDER_TOKEN_EXPIRES_META, true );
		if ( $expires_at < time() ) {
			$this->clear_order_token( $order, $item, true, false );
			return;
		}

		$payload = array(
			'event_key'         => (string) $event_key,
			'attribution_token' => (string) $item->get_meta( self::ORDER_TOKEN_META, true ),
			'product_id'        => (string) $item->get_meta( self::ORDER_PRODUCT_ID_META, true ),
			'variant_id'        => (string) $item->get_meta( self::ORDER_VARIANT_ID_META, true ),
			'quantity'          => max( 1, (int) $item->get_meta( self::ORDER_QUANTITY_META, true ) ),
			'occurred_at'       => (string) $item->get_meta( self::ORDER_OCCURRED_AT_META, true ),
		);

		if ( ! self::valid_purchase_payload( $payload ) ) {
			$this->clear_order_token( $order, $item, true, false );
			return;
		}

		$this->deliver_purchase( $order, $item, $payload, (int) $attempt );
	}

	/**
	 * @return void
	 */
	public static function unschedule_all() {
		$hooks = array( self::CART_ACTION, self::PURCHASE_ACTION );

		foreach ( $hooks as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), self::ACTION_GROUP );
			}
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Deletes expired encrypted cart event envelopes. Keys themselves are opaque
	 * and no token is stored in the registry option.
	 *
	 * @return void
	 */
	public static function purge_expired_events() {
		$events = Options::get( Options::COMMERCE_EVENT_KEYS, array() );
		$keep   = array();

		foreach ( is_array( $events ) ? $events : array() as $entry ) {
			$key        = is_array( $entry ) && isset( $entry['key'] ) ? (string) $entry['key'] : '';
			$expires_at = is_array( $entry ) && isset( $entry['expires_at'] ) ? (int) $entry['expires_at'] : 0;
			if ( ! self::is_valid_event_key( $key ) || $expires_at < time() ) {
				if ( self::is_valid_event_key( $key ) ) {
					Secret_Store::delete_commerce_event( $key );
				}
				continue;
			}

			$keep[] = array( 'key' => $key, 'expires_at' => $expires_at );
		}

		$registry = self::trim_commerce_event_registry( $keep );
		self::delete_evicted_event_secrets( $registry['evicted'], $registry['kept'] );
		Options::update( Options::COMMERCE_EVENT_KEYS, $registry['kept'] );
		self::purge_legacy_orphaned_events();
	}

	/**
	 * @return void
	 */
	public static function purge_all_events() {
		$events = Options::get( Options::COMMERCE_EVENT_KEYS, array() );
		foreach ( is_array( $events ) ? $events : array() as $entry ) {
			$key = is_array( $entry ) && isset( $entry['key'] ) ? (string) $entry['key'] : '';
			if ( self::is_valid_event_key( $key ) ) {
				Secret_Store::delete_commerce_event( $key );
			}
		}
		Options::delete( Options::COMMERCE_EVENT_KEYS );
		/* Bounded uninstall cleanup for legacy entries missing from the registry. */
		for ( $attempt = 0; $attempt < 4 && self::purge_legacy_orphaned_events() > 0; $attempt++ ) {
			// Each pass deletes at most 500 plugin-owned options.
		}
	}

	/**
	 * Deletes bounded legacy encrypted event options that are no longer referenced
	 * by the registry. This is intentionally limited and never scans/deletes
	 * arbitrary WordPress options.
	 *
	 * @param int $limit Maximum plugin-owned options to delete.
	 * @return int Number removed.
	 */
	public static function purge_legacy_orphaned_events( $limit = 500 ) {
		global $wpdb;
		$limit = max( 1, min( 500, (int) $limit ) );
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'esc_like' ) ) {
			return 0;
		}

		$like = $wpdb->esc_like( Options::PREFIX . 'commerce_event_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded orphan cleanup must read current plugin-owned options, not cached names.
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d', $wpdb->options, $like, $limit ) );
		$active = Options::get( Options::COMMERCE_EVENT_KEYS, array() );
		$orphan_suffixes = self::legacy_orphan_option_suffixes( is_array( $names ) ? $names : array(), $active, $limit );

		foreach ( $orphan_suffixes as $suffix ) {
			Options::delete( $suffix );
		}

		return count( $orphan_suffixes );
	}

	/**
	 * Pure legacy-option selection helper for bounded cleanup tests.
	 *
	 * @param mixed $option_names Candidate full option names.
	 * @param mixed $registry Current event registry.
	 * @param int   $limit Maximum results.
	 * @return array<int, string>
	 */
	public static function legacy_orphan_option_suffixes( $option_names, $registry, $limit = 500 ) {
		$active = array();
		foreach ( is_array( $registry ) ? $registry : array() as $entry ) {
			$key = is_array( $entry ) && isset( $entry['key'] ) ? (string) $entry['key'] : '';
			if ( self::is_valid_event_key( $key ) ) {
				$active[] = 'commerce_event_' . $key;
			}
		}

		$limit   = max( 1, min( 500, (int) $limit ) );
		$orphans = array();
		$prefix  = Options::PREFIX;
		foreach ( is_array( $option_names ) ? $option_names : array() as $option_name ) {
			$option_name = (string) $option_name;
			if ( 0 !== strpos( $option_name, $prefix ) ) {
				continue;
			}
			$suffix = substr( $option_name, strlen( $prefix ) );
			if ( ! preg_match( '/^commerce_event_[a-f0-9]{64}$/', $suffix ) || in_array( $suffix, $active, true ) ) {
				continue;
			}
			$orphans[] = $suffix;
			if ( count( $orphans ) >= $limit ) {
				break;
			}
		}

		return $orphans;
	}

	/**
	 * @param string $token Opaque purpose-scoped token.
	 * @return bool
	 */
	public static function is_valid_attribution_token( $token ) {
		return (bool) preg_match( '/^[A-Za-z0-9._~+\/=-]{16,4096}$/', (string) $token );
	}

	/**
	 * @param string $event_key Persisted event key.
	 * @return bool
	 */
	public static function is_valid_event_key( $event_key ) {
		return (bool) preg_match( '/^[a-f0-9]{64}$/', (string) $event_key );
	}

	/**
	 * Public pure validator used by contract-focused tests and extension authors.
	 * It accepts only the canonical privacy-minimized v1 batch envelope.
	 *
	 * @param array<string, mixed> $payload Analytics payload.
	 * @return bool
	 */
	public static function is_valid_analytics_payload( $payload ) {
		return self::valid_analytics_batch_payload( $payload, false );
	}

	/**
	 * @param array<string, mixed> $payload Analytics purchase batch.
	 * @return bool
	 */
	public static function is_valid_purchase_analytics_payload( $payload ) {
		return self::valid_analytics_batch_payload( $payload, true );
	}

	/**
	 * Wraps a locally queued singleton in the backend's canonical batch envelope.
	 * Idempotency remains tied to the one event key, not the transient batch body.
	 *
	 * @param array<string, mixed> $event Privacy-minimized event.
	 * @return array<string, mixed>
	 */
	public static function analytics_batch_payload( $event ) {
		return array(
			'schema_version' => 1,
			'events'         => array( $event ),
		);
	}

	/**
	 * Validates the namespaced data sent through Woo's official Store API
	 * extension callback. The server timestamps it; client input cannot choose
	 * an expiry or an unrelated cart identity.
	 *
	 * @param mixed $data Extension data.
	 * @return array<string, mixed>|null
	 */
	public static function store_api_context_from_data( $data ) {
		$token      = is_array( $data ) && isset( $data['attribution_token'] ) ? trim( (string) $data['attribution_token'] ) : '';
		$product_id = is_array( $data ) && isset( $data['product_id'] ) ? (string) $data['product_id'] : '';
		$variant_id = is_array( $data ) && isset( $data['variant_id'] ) ? (string) $data['variant_id'] : '';

		if ( ! self::is_valid_attribution_token( $token ) || ! preg_match( '/^[1-9][0-9]*$/', $product_id ) || ! preg_match( '/^(product|variation):[1-9][0-9]*$/', $variant_id ) ) {
			return null;
		}

		if ( 'product:' . $product_id !== $variant_id && 0 === strpos( $variant_id, 'product:' ) ) {
			return null;
		}

		return array(
			'attribution_token' => $token,
			'product_id'        => $product_id,
			'variant_id'        => $variant_id,
			'created_at'        => time(),
		);
	}

	/**
	 * @param mixed $context Store API session context.
	 * @param int   $product_id Requested Store API product or variation ID.
	 * @return bool
	 */
	public static function store_api_context_can_target_product( $context, $product_id ) {
		if ( ! self::is_valid_store_api_context( $context ) || $product_id < 1 ) {
			return false;
		}

		$variant_numeric_id = (int) substr( (string) $context['variant_id'], strpos( (string) $context['variant_id'], ':' ) + 1 );

		return (int) $context['product_id'] === $product_id || $variant_numeric_id === $product_id;
	}

	/**
	 * @param mixed $context Store API session context.
	 * @param int   $product_id Actual cart parent/product ID.
	 * @param int   $variation_id Actual cart variation ID.
	 * @return bool
	 */
	public static function store_api_context_matches_cart_item( $context, $product_id, $variation_id ) {
		return self::is_valid_store_api_context( $context ) && (int) $context['product_id'] === (int) $product_id && hash_equals( (string) $context['variant_id'], self::variant_id( (int) $product_id, (int) $variation_id ) );
	}

	/**
	 * @return object|null
	 */
	private function store_api_session() {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;

		return is_object( $woocommerce ) && isset( $woocommerce->session ) && is_object( $woocommerce->session ) ? $woocommerce->session : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function store_api_session_context() {
		$session = $this->store_api_session();
		$context = is_object( $session ) && method_exists( $session, 'get' ) ? $session->get( self::STORE_API_SESSION_KEY ) : null;

		if ( self::is_valid_store_api_context( $context ) ) {
			return $context;
		}

		if ( is_object( $session ) ) {
			$this->clear_store_api_session_context( $session );
		}

		return null;
	}

	/**
	 * @param int $product_id Actual cart parent/product ID.
	 * @param int $variation_id Actual cart variation ID.
	 * @return string|null
	 */
	private function consume_store_api_attribution( $product_id, $variation_id ) {
		$context                         = $this->store_api_request_context;
		$this->store_api_request_context = null;

		if ( ! self::store_api_context_matches_cart_item( $context, $product_id, $variation_id ) ) {
			return null;
		}

		$session = $this->store_api_session();
		if ( is_object( $session ) ) {
			$this->clear_store_api_session_context( $session );
		}

		return (string) $context['attribution_token'];
	}

	/**
	 * @param mixed $context Store API session context.
	 * @return bool
	 */
	private static function is_valid_store_api_context( $context ) {
		return is_array( $context ) && self::is_valid_attribution_token( isset( $context['attribution_token'] ) ? $context['attribution_token'] : '' ) && (bool) preg_match( '/^[1-9][0-9]*$/', isset( $context['product_id'] ) ? (string) $context['product_id'] : '' ) && (bool) preg_match( '/^(product|variation):[1-9][0-9]*$/', isset( $context['variant_id'] ) ? (string) $context['variant_id'] : '' ) && ! self::token_expired( isset( $context['created_at'] ) ? (int) $context['created_at'] : 0 );
	}

	/**
	 * @param object $session WC session.
	 * @return void
	 */
	private function clear_store_api_session_context( $session ) {
		if ( method_exists( $session, '__unset' ) ) {
			$session->__unset( self::STORE_API_SESSION_KEY );
		} elseif ( method_exists( $session, 'set' ) ) {
			$session->set( self::STORE_API_SESSION_KEY, null );
		}
	}

	/**
	 * @return string|null
	 */
	private function posted_attribution_token() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Reads an optional signed token in WooCommerce's add-to-cart hook; WooCommerce handles the cart operation and the service verifies attribution.
		if ( ! isset( $_POST['spellexo_attribution'] ) || ! is_string( $_POST['spellexo_attribution'] ) ) {
			return null;
		}

		$token = trim( sanitize_text_field( wp_unslash( $_POST['spellexo_attribution'] ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return self::is_valid_attribution_token( $token ) ? $token : null;
	}

	/**
	 * @param string $token Attribution token.
	 * @param int    $product_id Product ID.
	 * @param int    $variation_id Variation ID.
	 * @param int    $quantity Quantity.
	 * @param int    $occurred_at Unix timestamp.
	 * @return void
	 */
	private function queue_cart_event( $token, $product_id, $variation_id, $quantity, $occurred_at ) {
		$event_key = self::new_event_key();
		$payload   = array(
			'event_key'         => $event_key,
			'attribution_token' => $token,
			'product_id'        => (string) $product_id,
			'variant_id'        => self::variant_id( $product_id, $variation_id ),
			'quantity'          => $quantity,
			'occurred_at'       => gmdate( 'Y-m-d\\TH:i:s\\Z', $occurred_at ),
		);

		if ( ! self::valid_cart_payload( $payload ) ) {
			return;
		}

		try {
			$this->store_encrypted_event( $event_key, $payload, $occurred_at + self::TOKEN_TTL_SECONDS );
			if ( ! $this->schedule( self::CART_ACTION, array( $event_key, 0 ), time(), true ) ) {
				$this->delete_encrypted_event( $event_key );
				Options::record_queue_failure();
				Options::update_connection( array( 'last_error_code' => 'commerce_cart_schedule_failed' ) );
			}
		} catch ( Api_Exception $exception ) {
			Options::record_queue_failure();
			$this->connection->record_error( $exception );
		}
	}

	/**
	 * @param string $event_key Event key.
	 * @param int    $attempt Delivery attempt.
	 * @param string $path Signed endpoint path.
	 * @param string $hook Retry action hook.
	 * @return void
	 */
	private function send_encrypted_event( $event_key, $attempt, $path, $hook ) {
		if ( ! $this->connection->is_connected() || ! self::is_valid_event_key( $event_key ) ) {
			return;
		}

		$raw = Secret_Store::get_commerce_event( $event_key );
		$envelope = null === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $envelope ) || empty( $envelope['expires_at'] ) || (int) $envelope['expires_at'] < time() || empty( $envelope['payload'] ) || ! is_array( $envelope['payload'] ) ) {
			$this->delete_encrypted_event( $event_key );
			return;
		}

		$payload = $envelope['payload'];
		if ( ! self::valid_cart_payload( $payload ) ) {
			$this->delete_encrypted_event( $event_key );
			return;
		}
		$batch = self::analytics_batch_payload( $payload );
		if ( ! self::is_valid_analytics_payload( $batch ) ) {
			$this->delete_encrypted_event( $event_key );
			return;
		}

		$secret = Secret_Store::get();
		if ( null === $secret ) {
			return;
		}

		try {
			$this->api_client->request(
				'POST',
				$path,
				$batch,
				array(
					'idempotency_key' => Signature::idempotency_key( 'commerce-cart', array( $event_key ), $secret ),
				)
			);
			$this->delete_encrypted_event( $event_key );
			Options::clear_queue_failures();
		} catch ( Api_Exception $exception ) {
			$this->connection->record_error( $exception );
			Options::record_queue_failure();
			if ( $exception->is_retryable() && $attempt < self::MAX_DELIVERY_ATTEMPTS && $this->schedule( $hook, array( $event_key, $attempt + 1 ), time() + ( 60 * ( 1 << $attempt ) ), false ) ) {
				return;
			}
			$this->delete_encrypted_event( $event_key );
		}
	}

	/**
	 * @param object $order Woo order.
	 * @param object $item Woo order item.
	 * @param array<string, mixed> $payload Purchase payload.
	 * @param int $attempt Delivery attempt.
	 * @return void
	 */
	private function deliver_purchase( $order, $item, $payload, $attempt ) {
		$secret = Secret_Store::get();
		if ( null === $secret ) {
			return;
		}
		$batch = self::analytics_batch_payload( $payload );
		if ( ! self::is_valid_purchase_analytics_payload( $batch ) ) {
			$this->clear_order_token( $order, $item, true, false );
			return;
		}

		try {
			$this->api_client->request(
				'POST',
				'/v1/integrations/wordpress/analytics/purchases',
				$batch,
				array(
					'idempotency_key' => Signature::idempotency_key( 'commerce-purchase', array( $payload['event_key'] ), $secret ),
				)
			);
			$item->update_meta_data( self::ORDER_SENT_AT_META, time() );
			$this->clear_order_token( $order, $item, false );
			if ( method_exists( $order, 'save' ) ) {
				$order->save();
			}
			Options::clear_queue_failures();
		} catch ( Api_Exception $exception ) {
			$this->connection->record_error( $exception );
			Options::record_queue_failure();
			if ( $exception->is_retryable() && $attempt < self::MAX_DELIVERY_ATTEMPTS && $this->schedule( self::PURCHASE_ACTION, array( (int) $order->get_id(), (int) $item->get_id(), (string) $payload['event_key'], $attempt + 1 ), time() + ( 60 * ( 1 << $attempt ) ), false ) ) {
				return;
			}
			/* Terminal or unschedulable delivery never retains attribution tokens. */
			$this->clear_order_token( $order, $item, true, false );
		}
	}

	/**
	 * @param string $event_key Event key.
	 * @param array<string, mixed> $payload Event payload.
	 * @param int $expires_at Expiry timestamp.
	 * @return void
	 */
	private function store_encrypted_event( $event_key, $payload, $expires_at ) {
		self::purge_expired_events();
		$encoded = wp_json_encode(
			array(
				'expires_at' => (int) $expires_at,
				'payload'    => $payload,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( false === $encoded ) {
			throw new Api_Exception( 'The commerce event could not be encoded.', 'commerce_event_encoding_failed' );
		}

		Secret_Store::set_commerce_event( $event_key, $encoded );
		$events   = Options::get( Options::COMMERCE_EVENT_KEYS, array() );
		$events[] = array( 'key' => $event_key, 'expires_at' => (int) $expires_at );
		$registry = self::trim_commerce_event_registry( $events );
		self::delete_evicted_event_secrets( $registry['evicted'], $registry['kept'] );
		Options::update( Options::COMMERCE_EVENT_KEYS, $registry['kept'] );
	}

	/**
	 * Retains a bounded registry and returns exact evictions so their encrypted
	 * option values cannot become orphaned token retention.
	 *
	 * @param mixed $events Registry entries.
	 * @param int   $limit Maximum retained entries.
	 * @return array{kept:array<int, array<string,mixed>>,evicted:array<int, array<string,mixed>>}
	 */
	public static function trim_commerce_event_registry( $events, $limit = 500 ) {
		$limit   = max( 1, min( 500, (int) $limit ) );
		$valid   = array();
		$evicted = array();

		foreach ( is_array( $events ) ? $events : array() as $entry ) {
			if ( ! is_array( $entry ) || ! self::is_valid_event_key( isset( $entry['key'] ) ? (string) $entry['key'] : '' ) || (int) ( isset( $entry['expires_at'] ) ? $entry['expires_at'] : 0 ) < 1 ) {
				continue;
			}
			$valid[] = array( 'key' => (string) $entry['key'], 'expires_at' => (int) $entry['expires_at'] );
		}

		while ( count( $valid ) > $limit ) {
			$evicted[] = array_shift( $valid );
		}

		return array(
			'kept'    => $valid,
			'evicted' => $evicted,
		);
	}

	/**
	 * Deletes encrypted data only when its event key is no longer retained by the
	 * bounded registry. This also makes malformed duplicate registry rows safe.
	 *
	 * @param array<int, array<string,mixed>> $evicted Evicted entries.
	 * @param array<int, array<string,mixed>> $kept Retained entries.
	 * @return void
	 */
	private static function delete_evicted_event_secrets( $evicted, $kept ) {
		$retained_keys = array();
		foreach ( $kept as $entry ) {
			if ( is_array( $entry ) && isset( $entry['key'] ) ) {
				$retained_keys[] = (string) $entry['key'];
			}
		}

		foreach ( $evicted as $entry ) {
			$key = is_array( $entry ) && isset( $entry['key'] ) ? (string) $entry['key'] : '';
			if ( self::is_valid_event_key( $key ) && ! in_array( $key, $retained_keys, true ) ) {
				Secret_Store::delete_commerce_event( $key );
			}
		}
	}

	/**
	 * @param string $event_key Event key.
	 * @return void
	 */
	private function delete_encrypted_event( $event_key ) {
		Secret_Store::delete_commerce_event( $event_key );
		$events = Options::get( Options::COMMERCE_EVENT_KEYS, array() );
		$keep   = array();

		foreach ( is_array( $events ) ? $events : array() as $entry ) {
			if ( ! is_array( $entry ) || (string) $event_key === ( isset( $entry['key'] ) ? (string) $entry['key'] : '' ) ) {
				continue;
			}
			$keep[] = $entry;
		}

		Options::update( Options::COMMERCE_EVENT_KEYS, $keep );
	}

	/**
	 * @param object $order Woo order.
	 * @param object $item Woo order item.
	 * @param bool $save Whether to save immediately.
	 * @param bool $retain_dedupe Whether to retain event key/sent marker.
	 * @return void
	 */
	private function clear_order_token( $order, $item, $save = true, $retain_dedupe = true ) {
		foreach ( self::order_attribution_meta_to_clear() as $meta_key ) {
			$item->delete_meta_data( $meta_key );
		}
		if ( ! $retain_dedupe ) {
			$item->delete_meta_data( self::ORDER_EVENT_KEY_META );
			$item->delete_meta_data( self::ORDER_SENT_AT_META );
		}
		if ( $save && method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/**
	 * Only the opaque event key and sent marker remain after terminal delivery or
	 * expiry, allowing exactly-once dedupe without retaining attribution data.
	 *
	 * @return array<int, string>
	 */
	public static function order_attribution_meta_to_clear() {
		return array(
			self::ORDER_TOKEN_META,
			self::ORDER_TOKEN_EXPIRES_META,
			self::ORDER_PRODUCT_ID_META,
			self::ORDER_VARIANT_ID_META,
			self::ORDER_QUANTITY_META,
			self::ORDER_OCCURRED_AT_META,
		);
	}

	/**
	 * @param string $hook Action hook.
	 * @param array  $args Action args.
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
				return (bool) as_enqueue_async_action( $hook, $args, self::ACTION_GROUP, $unique );
			}

			return (bool) as_schedule_single_action( $timestamp, $hook, $args, self::ACTION_GROUP, $unique );
		}

		if ( $unique && wp_next_scheduled( $hook, $args ) ) {
			return true;
		}

		return wp_schedule_single_event( max( time() + 1, $timestamp ), $hook, $args );
	}

	/**
	 * @return string
	 */
	private static function new_event_key() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * @param int $product_id Product ID.
	 * @param int $variation_id Variation ID.
	 * @return string
	 */
	private static function variant_id( $product_id, $variation_id ) {
		return $variation_id > 0 ? 'variation:' . $variation_id : 'product:' . $product_id;
	}

	/**
	 * @param int $created_at Token creation timestamp.
	 * @return bool
	 */
	private static function token_expired( $created_at ) {
		return $created_at < 1 || $created_at + self::TOKEN_TTL_SECONDS < time();
	}

	/**
	 * @param mixed $payload Canonical analytics batch.
	 * @param bool  $require_quantity Whether each event must carry quantity.
	 * @return bool
	 */
	private static function valid_analytics_batch_payload( $payload, $require_quantity ) {
		$allowed = array( 'schema_version', 'events' );
		$keys    = is_array( $payload ) ? array_keys( $payload ) : array();
		sort( $allowed );
		sort( $keys );

		if ( $allowed !== $keys || 1 !== (int) $payload['schema_version'] || ! is_array( $payload['events'] ) || count( $payload['events'] ) < 1 || count( $payload['events'] ) > 100 ) {
			return false;
		}

		$event_keys = array();
		foreach ( $payload['events'] as $event ) {
			if ( ! self::valid_cart_payload( $event, $require_quantity ) || in_array( (string) $event['event_key'], $event_keys, true ) ) {
				return false;
			}
			$event_keys[] = (string) $event['event_key'];
		}

		return true;
	}

	/**
	 * @param mixed $payload Cart event without the outer batch envelope.
	 * @param bool  $require_quantity Whether quantity is required.
	 * @return bool
	 */
	private static function valid_cart_payload( $payload, $require_quantity = true ) {
		$required = array( 'event_key', 'attribution_token', 'product_id', 'variant_id', 'occurred_at' );
		$allowed  = array_merge( $required, array( 'quantity' ) );
		$keys     = is_array( $payload ) ? array_keys( $payload ) : array();
		sort( $allowed );
		sort( $keys );

		if ( ! is_array( $payload ) || array_diff( $required, $keys ) || array_diff( $keys, $allowed ) || ( $require_quantity && ! isset( $payload['quantity'] ) ) ) {
			return false;
		}

		return self::is_valid_event_key( $payload['event_key'] )
			&& self::is_valid_attribution_token( $payload['attribution_token'] )
			&& (bool) preg_match( '/^[1-9][0-9]*$/', (string) $payload['product_id'] )
			&& (bool) preg_match( '/^(product|variation):[1-9][0-9]*$/', (string) $payload['variant_id'] )
			&& ( ! isset( $payload['quantity'] ) || ( is_int( $payload['quantity'] ) && $payload['quantity'] >= 1 && $payload['quantity'] <= self::MAX_EVENT_QUANTITY ) )
			&& (bool) preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $payload['occurred_at'] )
			&& false !== strtotime( (string) $payload['occurred_at'] );
	}

	/**
	 * @param array<string, mixed> $payload Purchase event.
	 * @return bool
	 */
	private static function valid_purchase_payload( $payload ) {
		return self::valid_cart_payload( $payload, true );
	}
}
