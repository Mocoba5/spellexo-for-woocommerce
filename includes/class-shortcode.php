<?php
/**
 * Explicit shortcode placement method.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Shortcode {
	/**
	 * @var Mount_Renderer
	 */
	private $renderer;

	/**
	 * @param Mount_Renderer $renderer Shared mount renderer.
	 */
	public function __construct( $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Registers the only shortcode provided by this plugin.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'spellexo_view_in_your_room', array( $this, 'render' ) );
	}

	/**
	 * @return string
	 */
	public function render() {
		return $this->renderer->render();
	}
}
