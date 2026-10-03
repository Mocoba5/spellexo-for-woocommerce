<?php
/**
 * Dynamic block callback.
 *
 * @package Spellexo_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

return \Spellexo\WooCommerce\Plugin::instance()->mount_renderer()->render(
	array(
		'editor_preview' => is_admin(),
	)
);
