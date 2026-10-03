<?php
/**
 * Plugin Name: Spellexo 3D & AR for WooCommerce
 * Plugin URI: https://www.spellexo.com/woocommerce-3d-product-viewer-plugin
 * Description: Add interactive 3D product viewing and augmented reality to WooCommerce with the Spellexo hosted service.
 * Version: 0.1.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.3
 * WC tested up to: 11.1
 * Author: Spellexo
 * Author URI: https://www.spellexo.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: spellexo-for-woocommerce
 * Domain Path: /languages
 *
 * @package Spellexo_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'SPELLEXO_WC_VERSION', '0.1.0' );
define( 'SPELLEXO_WC_PLUGIN_FILE', __FILE__ );
define( 'SPELLEXO_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPELLEXO_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SPELLEXO_WC_PLUGIN_DIR . 'includes/class-loader.php';

register_activation_hook( __FILE__, array( '\\Spellexo\\WooCommerce\\Activation', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Spellexo\\WooCommerce\\Deactivation', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		\Spellexo\WooCommerce\Compatibility::declare_feature_compatibility( SPELLEXO_WC_PLUGIN_FILE );
	}
);

\Spellexo\WooCommerce\Plugin::boot( SPELLEXO_WC_PLUGIN_FILE );
