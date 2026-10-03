<?php
/**
 * Dynamic Gutenberg block registration.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Blocks {
	/**
	 * Registers the dynamic product-template block through block.json.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * @return void
	 */
	public function register_block() {
		if ( function_exists( 'register_block_type' ) ) {
			register_block_type( SPELLEXO_WC_PLUGIN_DIR . 'blocks/view-in-your-room' );
			/* Core loads this local style only when this dynamic block is present. */
			if ( function_exists( 'wp_enqueue_block_style' ) ) {
				wp_enqueue_block_style(
					'spellexo/view-in-your-room',
					array(
						'handle' => Mount_Renderer::STYLE_HANDLE,
						'src'    => SPELLEXO_WC_PLUGIN_URL . 'assets/css/storefront.css',
						'path'   => SPELLEXO_WC_PLUGIN_DIR . 'assets/css/storefront.css',
						'ver'    => SPELLEXO_WC_VERSION,
					)
				);
			}
		}
	}
}
