<?php
/**
 * Read-only Site Health integration.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Site_Health {
	/** @var Connection */
	private $connection;

	/**
	 * @param Connection $connection Connection manager.
	 */
	public function __construct( $connection ) {
		$this->connection = $connection;
	}

	/**
	 * @return void
	 */
	public function register() {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	/**
	 * @param array<string, mixed> $tests Existing tests.
	 * @return array<string, mixed>
	 */
	public function add_tests( $tests ) {
		$tests['direct']['spellexo_woocommerce_connection'] = array(
			'label' => __( 'Spellexo connection status', 'spellexo-for-woocommerce' ),
			'test'  => array( $this, 'connection_test' ),
		);

		return $tests;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function connection_test() {
		if ( $this->connection->is_connected() ) {
			return array(
				'label'       => __( 'Spellexo is connected', 'spellexo-for-woocommerce' ),
				'status'      => 'good',
				'badge'       => array( 'label' => __( 'Spellexo', 'spellexo-for-woocommerce' ), 'color' => 'blue' ),
				'description' => '<p>' . esc_html__( 'The local connector is ready to render explicitly placed storefront mounts.', 'spellexo-for-woocommerce' ) . '</p>',
				'actions'     => '',
			);
		}

		return array(
			'label'       => __( 'Spellexo is not connected', 'spellexo-for-woocommerce' ),
			'status'      => 'recommended',
			'badge'       => array( 'label' => __( 'Spellexo', 'spellexo-for-woocommerce' ), 'color' => 'blue' ),
			'description' => '<p>' . esc_html__( 'No storefront request is made until a WooCommerce administrator explicitly connects this site to Spellexo.', 'spellexo-for-woocommerce' ) . '</p>',
			'actions'     => '',
		);
	}
}
