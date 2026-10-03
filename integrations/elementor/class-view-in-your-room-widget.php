<?php
/**
 * Optional native Elementor widget.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce\Elementor;

use Spellexo\WooCommerce\Mount_Renderer;

defined( 'ABSPATH' ) || exit;

final class View_In_Your_Room_Widget extends \Elementor\Widget_Base {
	/**
	 * @var Mount_Renderer
	 */
	private $renderer;

	/**
	 * Injects the shared renderer without changing Elementor's constructor
	 * contract. Elementor instantiates and hydrates widgets with its own
	 * constructor arguments, so dependency injection belongs in a setter.
	 *
	 * @param Mount_Renderer $renderer Shared renderer.
	 * @return void
	 */
	public function set_renderer( $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * @return string
	 */
	public function get_name() {
		return 'spellexo_view_in_your_room';
	}

	/**
	 * @return string
	 */
	public function get_title() {
		return esc_html__( 'Spellexo — View In Your Room', 'spellexo-for-woocommerce' );
	}

	/**
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-image';
	}

	/**
	 * @return array<int, string>
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Elementor asks for these dependencies before rendering its widget, so the
	 * locally bundled stylesheet reaches wp_head for template placements.
	 *
	 * @return array<int, string>
	 */
	public function get_style_depends() {
		return array( Mount_Renderer::STYLE_HANDLE );
	}

	/**
	 * @return void
	 */
	protected function render() {
		if ( ! $this->renderer instanceof Mount_Renderer ) {
			return;
		}

		$markup = $this->renderer->render(
			array(
				'editor_preview' => \Elementor\Plugin::$instance->editor->is_edit_mode(),
			)
		);
		echo wp_kses(
			$markup,
			array(
				'div' => array(
					'class' => true,
					'role' => true,
					'aria-live' => true,
					'data-spellexo-woocommerce-mount' => true,
					'data-spellexo-woocommerce-config' => true,
				),
			)
		);
	}
}
