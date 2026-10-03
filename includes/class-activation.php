<?php
/**
 * Plugin activation lifecycle.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Activation {
	/**
	 * Initializes local-only state. It intentionally makes no remote calls.
	 *
	 * @return void
	 */
	public static function activate() {
		Options::add( Options::SCHEMA_VERSION, 1 );
		Options::add( Options::CREDENTIAL_VERSION, 1 );
		Options::add( Options::INTEGRATION_SECRET, '' );
		Options::add( Options::SITE_REVISION, '0' );
		Options::add( Options::QUEUE_FAILURES, 0 );
		Options::add( Options::ACKNOWLEDGED_COMMAND_IDS, array() );
		Options::add( Options::COMMERCE_EVENT_KEYS, array() );
		Options::add( 'pairing_verifier', '' );
		Options::add( 'pairing_poll_token', '' );

		$installation_uid = (string) Options::get( Options::INSTALLATION_UID, '' );
		if ( '' === $installation_uid ) {
			Options::add( Options::INSTALLATION_UID, wp_generate_uuid4() );
			$installation_uid = (string) Options::get( Options::INSTALLATION_UID, '' );
		}

		if ( '' !== $installation_uid && null === Secret_Store::get_named( 'pairing_verifier' ) && Secret_Store::is_available() ) {
			try {
				Secret_Store::set_named( 'pairing_verifier', self::base64url( random_bytes( 32 ) ) );
			} catch ( Api_Exception $exception ) {
				Options::update( Options::LAST_ERROR, $exception->error_code() );
			}
		}

		Options::update_connection(
			array(
				'canonical_site_url' => Url::canonical_site_url(),
				'canonical_origin'   => Url::canonical_origin(),
				'last_error_code'    => Secret_Store::is_available() ? '' : 'crypto_unavailable',
			)
		);

		set_transient( 'spellexo_woocommerce_activation_notice', 1, MINUTE_IN_SECONDS );
	}

	/**
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private static function base64url( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
