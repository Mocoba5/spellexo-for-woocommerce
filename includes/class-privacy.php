<?php
/**
 * Privacy-policy suggestion hook.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Privacy {
	/**
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * @return void
	 */
	public function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p>' . esc_html__( 'Spellexo for WooCommerce connects this site to the Spellexo hosted service only after an administrator chooses Connect. An account is required. The service provides catalog/model association, the customer-facing 3D and AR viewer, analytics, and billing.', 'spellexo-for-woocommerce' ) . '</p>';
		$content .= '<p>' . esc_html__( 'After connection, the plugin sends site identity and versions, WooCommerce product and variation metadata, model-association identifiers, hosted viewer/API request data, and privacy-minimized attribution events. The hosted viewer and API process an IP address for security, abuse prevention, and rate limiting; browser, device-capability, and performance information to operate the viewer and device test; and country or region derived from IP where available. Spellexo retains aggregate analytics and performance telemetry. Optional raw viewer-event logs are disabled by default and, if enabled in the hosted service, are deleted with the store; provider operational logs are kept only for the shortest period needed for reliability and security investigation.', 'spellexo-for-woocommerce' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Minimized attribution event payloads contain opaque attribution or event tokens, product and variation IDs, quantity, and event time. They do not contain prices, order IDs, customer IDs, names, emails, addresses, phone numbers, passwords, payment details, or IP addresses.', 'spellexo-for-woocommerce' ) . '</p>';
		$content .= '<p>' . esc_html__( 'The plugin contacts dashboard.spellexo.com when an administrator connects or opens the hosted dashboard, and api.spellexo.com for synchronization, eligibility, the hosted viewer/AR service, and documented attribution events. Camera access is requested by the hosted viewer only after a shopper action.', 'spellexo-for-woocommerce' ) . '</p>';
		$content .= '<p><a href="https://spellexo.com/privacy" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Spellexo Privacy Policy', 'spellexo-for-woocommerce' ) . '</a></p>';

		wp_add_privacy_policy_content( __( 'Spellexo for WooCommerce', 'spellexo-for-woocommerce' ), wp_kses_post( $content ) );
	}
}
