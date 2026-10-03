<?php
/**
 * Optional native Elementor registration.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce\Elementor;

use Spellexo\WooCommerce\Mount_Renderer;

defined( 'ABSPATH' ) || exit;

final class Integration {
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
	 * Registers only when Elementor is fully active.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'elementor/loaded', array( $this, 'hook_widget_registration' ) );

		if ( did_action( 'elementor/loaded' ) ) {
			$this->hook_widget_registration();
		}
	}

	/**
	 * @return void
	 */
	public function hook_widget_registration() {
		add_action( 'elementor/widgets/register', array( $this, 'register_widget' ) );
	}

	/**
	 * @param object $widgets_manager Elementor widget manager.
	 * @return void
	 */
	public function register_widget( $widgets_manager ) {
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) || ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}

		require_once __DIR__ . '/class-view-in-your-room-widget.php';
		$widget = new View_In_Your_Room_Widget();
		$widget->set_renderer( $this->renderer );
		$widgets_manager->register( $widget );
	}
}
