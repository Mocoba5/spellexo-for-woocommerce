<?php
/**
 * Environment and WooCommerce feature compatibility checks.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Compatibility {
	const MINIMUM_PHP = '7.4';
	const MINIMUM_WORDPRESS = '6.3';
	const MINIMUM_WOOCOMMERCE = '7.3';

	/**
	 * Declares compatibility through WooCommerce's supported API.
	 *
	 * This plugin uses Woo CRUD APIs and does not query order tables directly.
	 * Its Store API adapter uses the official cart/extensions callback, the
	 * add-to-cart data filter, and the documented order-processed action without
	 * modifying cart merge keys or intercepting browser fetch.
	 *
	 * @param string $plugin_file Main plugin file path.
	 * @return void
	 */
	public static function declare_feature_compatibility( $plugin_file ) {
		if ( ! class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', $plugin_file, true );
		if ( Commerce_Analytics::blocks_attribution_version_supported() ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', $plugin_file, true );
		}
	}

	/**
	 * Returns current environment checks for the status screen.
	 *
	 * @return array<string, bool|string>
	 */
	public function checks() {
		$wordpress_version = get_bloginfo( 'version' );
		$woocommerce       = defined( 'WC_VERSION' ) ? WC_VERSION : '';

		return array(
			'php'             => version_compare( PHP_VERSION, self::MINIMUM_PHP, '>=' ),
			'wordpress'       => version_compare( $wordpress_version, self::MINIMUM_WORDPRESS, '>=' ),
			'woocommerce'     => class_exists( 'WooCommerce' ) && '' !== $woocommerce && version_compare( $woocommerce, self::MINIMUM_WOOCOMMERCE, '>=' ),
			'cart_checkout_blocks' => Commerce_Analytics::blocks_attribution_supported(),
			'https'           => 'https' === wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ),
			'php_version'     => PHP_VERSION,
			'wordpress_version' => $wordpress_version,
			'woocommerce_version' => $woocommerce,
		);
	}

	/**
	 * Determines whether Woo-dependent features can initialize.
	 *
	 * @return bool
	 */
	public function is_supported() {
		$checks = $this->checks();

		return $checks['php'] && $checks['wordpress'] && $checks['woocommerce'];
	}

	/**
	 * Determines whether a pairing request may be started.
	 *
	 * @return bool
	 */
	public function can_connect() {
		$checks = $this->checks();

		return $this->is_supported() && $checks['https'] && Secret_Store::is_available();
	}

	/**
	 * Renders a narrow, relevant admin warning when needed.
	 *
	 * @return void
	 */
	public function render_admin_notice() {
		if ( ! current_user_can( 'activate_plugins' ) || $this->is_supported() ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Spellexo for WooCommerce needs WordPress 6.3+, PHP 7.4+, and WooCommerce 7.3+ before its connection and storefront features can run.', 'spellexo-for-woocommerce' );
		echo '</p></div>';
	}
}
