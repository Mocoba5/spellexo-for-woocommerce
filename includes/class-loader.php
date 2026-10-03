<?php
/**
 * Internal class loader.
 *
 * @package Spellexo_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

$spellexo_woocommerce_class_files = array(
	'class-options.php',
	'class-audit-log.php',
	'class-url.php',
	'class-signature.php',
	'class-secret-store.php',
	'class-api-exception.php',
	'class-api-client.php',
	'class-pairing-client.php',
	'class-compatibility.php',
	'class-activation.php',
	'class-deactivation.php',
	'class-connection.php',
	'class-product-serializer.php',
	'class-sync.php',
	'class-heartbeat.php',
	'class-commerce-analytics.php',
	'class-mount-renderer.php',
	'class-shortcode.php',
	'class-blocks.php',
	'class-admin.php',
	'class-privacy.php',
	'class-site-health.php',
	'class-uninstall.php',
	'class-plugin.php',
);

foreach ( $spellexo_woocommerce_class_files as $spellexo_woocommerce_class_file ) {
	require_once __DIR__ . '/' . $spellexo_woocommerce_class_file;
}

unset( $spellexo_woocommerce_class_files, $spellexo_woocommerce_class_file );
