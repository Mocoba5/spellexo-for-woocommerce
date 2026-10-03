<?php
/**
 * Plugin uninstall entry point.
 *
 * @package Spellexo_For_WooCommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-loader.php';

\Spellexo\WooCommerce\Uninstall::run();
