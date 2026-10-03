<?php
/**
 * Shared product-template mount renderer.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Mount_Renderer {
	const STYLE_HANDLE = 'spellexo-woocommerce-storefront';
	const SCRIPT_HANDLE = 'spellexo-woocommerce-storefront';

	/**
	 * @var Connection
	 */
	private $connection;

	/**
	 * @param Connection|null $connection Connection override for tests.
	 */
	public function __construct( $connection = null ) {
		$this->connection = $connection instanceof Connection ? $connection : new Connection();
	}

	/**
	 * Registers local assets before wp_head and detects classic shortcode/block
	 * placements in the queried product content. Block templates and Elementor
	 * request the same style through their native dependency mechanisms.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_detected_placement_style' ), 20 );
	}

	/**
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			self::STYLE_HANDLE,
			SPELLEXO_WC_PLUGIN_URL . 'assets/css/storefront.css',
			array(),
			SPELLEXO_WC_VERSION
		);
		wp_register_script(
			self::SCRIPT_HANDLE,
			SPELLEXO_WC_PLUGIN_URL . 'assets/js/storefront-bootstrap.js',
			array(),
			SPELLEXO_WC_VERSION,
			true
		);
		wp_script_add_data( self::SCRIPT_HANDLE, 'strategy', 'defer' );
	}

	/**
	 * Enqueues the bundled stylesheet for Woo product pages before wp_head.
	 * This intentionally delivers only CSS: mounts remain explicit placements
	 * and the JavaScript is still queued only by a functional mount.
	 *
	 * @return void
	 */
	public function enqueue_detected_placement_style() {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'get_queried_object_id' ) ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if ( $post_id > 0 ) {
			wp_enqueue_style( self::STYLE_HANDLE );
		}
	}

	/**
	 * @param string $content Queried product content.
	 * @return bool
	 */
	public static function content_has_explicit_placement( $content ) {
		return false !== strpos( (string) $content, 'wp:spellexo/view-in-your-room' ) || (bool) preg_match( '/\[spellexo_view_in_your_room(?:\s[^\]]*)?\]/i', (string) $content );
	}

	/**
	 * Renders the same mount for the dynamic block, Elementor widget, and
	 * shortcode. It never inserts into a product page automatically.
	 *
	 * @param array<string, mixed> $context Render context.
	 * @return string
	 */
	public function render( $context = array() ) {
		if ( ! empty( $context['editor_preview'] ) || is_admin() ) {
			return '<div class="spellexo-woocommerce-editor-placeholder" role="status">' . esc_html__( 'Spellexo viewer button placement', 'spellexo-for-woocommerce' ) . '</div>';
		}

		if ( ! $this->connection->is_connected() || ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = $this->current_product();
		if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
			return '';
		}

		$product_id = (int) $product->get_id();
		$product_type = method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) ? 'variable' : 'simple';
		$connection = Options::connection();
		$api_base   = $this->public_api_base();
		$viewer_url = $this->viewer_url();

		if ( $product_id < 1 || '' === $api_base || '' === $viewer_url || '' === $connection['public_store_key'] ) {
			return '';
		}

		$this->enqueue_assets();

		$config = array(
			'publicStoreKey' => $connection['public_store_key'],
			'productId'      => (string) $product_id,
			'productType'    => $product_type,
			'simpleVariantId'=> 'product:' . $product_id,
			'apiBase'        => $api_base,
			'viewerUrl'      => $viewer_url,
			'integrationVersion' => 1,
			'i18n'           => array(
				'label'          => __( 'View In Your Room', 'spellexo-for-woocommerce' ),
				'loading'        => __( 'Loading viewer…', 'spellexo-for-woocommerce' ),
				'close'          => __( 'Close viewer', 'spellexo-for-woocommerce' ),
				'error'          => __( 'The viewer could not be opened. Please try again.', 'spellexo-for-woocommerce' ),
			),
		);

		$json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return '';
		}

		return '<div class="spellexo-woocommerce-mount" data-spellexo-woocommerce-mount="1" data-spellexo-woocommerce-config="' . esc_attr( $json ) . '" aria-live="polite"></div>';
	}

	/**
	 * Resolves the current global WooCommerce product without accepting a local
	 * merchant-selected product ID.
	 *
	 * @return object|false
	 */
	private function current_product() {
		global $product;

		$queried_id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
		$fallback_id = function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0;
		$product_id = self::product_id_for_mount( $queried_id, $fallback_id );

		if ( $product_id > 0 ) {
			$resolved = wc_get_product( $product_id );
			if ( $resolved && method_exists( $resolved, 'get_id' ) ) {
				return $resolved;
			}
		}

		/* A loop-local global product is safe only when it is the bounded fallback. */
		if ( $fallback_id > 0 && is_object( $product ) && method_exists( $product, 'get_id' ) && $fallback_id === (int) $product->get_id() ) {
			return $product;
		}

		return false;
	}

	/**
	 * Prefers the main product query over a loop-local post/global product. The
	 * fallback is used only when WordPress cannot provide a queried object ID.
	 *
	 * @param int $queried_id Main queried object ID.
	 * @param int $fallback_id Bounded current-post fallback ID.
	 * @return int
	 */
	public static function product_id_for_mount( $queried_id, $fallback_id ) {
		$queried_id  = max( 0, (int) $queried_id );
		$fallback_id = max( 0, (int) $fallback_id );

		return $queried_id > 0 ? $queried_id : $fallback_id;
	}

	/**
	 * Enqueues only bundled plugin assets and only when a functional mount exists.
	 *
	 * @return void
	 */
	private function enqueue_assets() {
		$this->register_assets();
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );
	}

	/**
	 * Gets the public eligibility service base URL.
	 *
	 * @return string
	 */
	private function public_api_base() {
		$url   = (string) apply_filters( 'spellexo_woocommerce_public_api_base_url', 'https://api.spellexo.com' );
		$hosts = (array) apply_filters( 'spellexo_woocommerce_public_api_hosts', array( 'api.spellexo.com' ) );

		return Url::is_allowed_https_url( $url, $hosts ) ? untrailingslashit( $url ) : '';
	}

	/**
	 * Gets the trusted hosted viewer URL. It deliberately contains no tier.
	 *
	 * @return string
	 */
	private function viewer_url() {
		$url   = (string) apply_filters( 'spellexo_woocommerce_viewer_url', 'https://api.spellexo.com/viewer' );
		$hosts = (array) apply_filters( 'spellexo_woocommerce_viewer_hosts', array( 'api.spellexo.com' ) );

		return Url::is_allowed_https_url( $url, $hosts ) ? $url : '';
	}
}
