<?php
/**
 * Encrypted local integration-secret storage.
 *
 * The secret is required only for signed server-to-server requests. It is never
 * printed, localized, included in diagnostics, or exposed to storefront code.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Secret_Store {
	const CIPHER = 'aes-256-gcm';
	const PREFIX = 'v1.';

	/**
	 * Returns whether this host can retain a decryptable secret safely.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) && defined( 'OPENSSL_RAW_DATA' );
	}

	/**
	 * Encrypts and stores the final integration secret in a non-autoloaded option.
	 *
	 * @param string $secret Final credential returned by the one-time pairing exchange.
	 * @return bool
	 * @throws Api_Exception When server cryptography is unavailable.
	 */
	public static function set( $secret ) {
		if ( null === Signature::decode_secret( $secret ) ) {
			throw new Api_Exception( 'The final integration credential is malformed.', 'invalid_integration_secret' );
		}

		return self::set_named( 'integration_secret', $secret );
	}

	/**
	 * Encrypts named bootstrap material in a non-autoloaded option.
	 *
	 * @param string $name Approved secret-material name.
	 * @param string $secret Secret plaintext.
	 * @return bool
	 * @throws Api_Exception When server cryptography is unavailable.
	 */
	public static function set_named( $name, $secret ) {
		$option_key = self::option_key( $name );

		if ( ! self::is_available() ) {
			throw new Api_Exception( 'Secure local secret storage is unavailable.', 'crypto_unavailable' );
		}

		$installation_uid = (string) Options::get( Options::INSTALLATION_UID, '' );
		if ( '' === $installation_uid || '' === $secret ) {
			throw new Api_Exception( 'Installation identity is unavailable.', 'installation_identity_unavailable' );
		}

		$iv         = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt(
			$secret,
			self::CIPHER,
			self::key( $installation_uid, $name ),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			self::associated_data( $installation_uid, $name )
		);

		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			throw new Api_Exception( 'Secure local secret storage failed.', 'crypto_write_failed' );
		}

		$encoded = self::PREFIX . base64_encode( $iv . $tag . $ciphertext );

		$written = null === Options::get( $option_key, null )
			? Options::add( $option_key, $encoded )
			: Options::update( $option_key, $encoded );

		if ( $written ) {
			return true;
		}

		/*
		 * WordPress returns false for an unchanged option as well as a failed
		 * write. Accept only an exact decrypted read-back, never an ambiguous
		 * raw option result, so pairing recovery material remains available when
		 * a database write genuinely fails.
		 */
		$stored = self::get_named( $name );
		if ( is_string( $stored ) && hash_equals( $secret, $stored ) ) {
			return true;
		}

		throw new Api_Exception( 'Secure local secret storage could not be verified.', 'crypto_write_failed' );
	}

	/**
	 * Gets the decrypted secret for a signed server-side request.
	 *
	 * @return string|null
	 */
	public static function get() {
		return self::get_named( 'integration_secret' );
	}

	/**
	 * Gets named encrypted bootstrap material for server-side use only.
	 *
	 * @param string $name Approved secret-material name.
	 * @return string|null
	 */
	public static function get_named( $name ) {
		$option_key = self::option_key( $name );

		if ( ! self::is_available() ) {
			return null;
		}

		$encoded          = (string) Options::get( $option_key, '' );
		$installation_uid = (string) Options::get( Options::INSTALLATION_UID, '' );

		if ( '' === $encoded || '' === $installation_uid || 0 !== strpos( $encoded, self::PREFIX ) ) {
			return null;
		}

		$payload = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true );
		if ( false === $payload || strlen( $payload ) < 29 ) {
			return null;
		}

		$iv         = substr( $payload, 0, 12 );
		$tag        = substr( $payload, 12, 16 );
		$ciphertext = substr( $payload, 28 );
		$secret     = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			self::key( $installation_uid, $name ),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			self::associated_data( $installation_uid, $name )
		);

		return is_string( $secret ) && '' !== $secret ? $secret : null;
	}

	/**
	 * Deletes the encrypted local secret.
	 *
	 * @return bool
	 */
	public static function delete() {
		return self::delete_named( 'integration_secret' );
	}

	/**
	 * Deletes named encrypted bootstrap material.
	 *
	 * @param string $name Approved secret-material name.
	 * @return bool
	 */
	public static function delete_named( $name ) {
		return Options::delete( self::option_key( $name ) );
	}

	/**
	 * Encrypts a short-lived commerce event envelope keyed by its opaque event ID.
	 * The event key itself is safe to schedule; its attribution token remains out
	 * of Action Scheduler arguments and is removed after delivery or expiry.
	 *
	 * @param string $event_key Lowercase 64-hex event key.
	 * @param string $payload JSON event envelope.
	 * @return bool
	 * @throws Api_Exception When the event material is invalid.
	 */
	public static function set_commerce_event( $event_key, $payload ) {
		return self::set_named( self::commerce_event_name( $event_key ), $payload );
	}

	/**
	 * @param string $event_key Lowercase 64-hex event key.
	 * @return string|null
	 */
	public static function get_commerce_event( $event_key ) {
		return self::get_named( self::commerce_event_name( $event_key ) );
	}

	/**
	 * @param string $event_key Lowercase 64-hex event key.
	 * @return bool
	 */
	public static function delete_commerce_event( $event_key ) {
		return self::delete_named( self::commerce_event_name( $event_key ) );
	}

	/**
	 * Derives an encryption key from WordPress server salts and this installation.
	 *
	 * @param string $installation_uid Installation UUID.
	 * @param string $name Secret-material name.
	 * @return string
	 */
	private static function key( $installation_uid, $name ) {
		return hash( 'sha256', wp_salt( 'auth' ) . '|spellexo-woocommerce|v1|' . $name . '|' . $installation_uid, true );
	}

	/**
	 * @param string $installation_uid Installation UUID.
	 * @param string $name Secret-material name.
	 * @return string
	 */
	private static function associated_data( $installation_uid, $name ) {
		return $name . '|' . $installation_uid;
	}

	/**
	 * Maps only known secret material to plugin-owned option names.
	 *
	 * @param string $name Material name.
	 * @return string
	 * @throws Api_Exception For an undeclared name.
	 */
	private static function option_key( $name ) {
		$keys = array(
			'integration_secret' => Options::INTEGRATION_SECRET,
			'pairing_verifier'   => 'pairing_verifier',
			'pairing_poll_token' => 'pairing_poll_token',
		);

		if ( ! isset( $keys[ $name ] ) ) {
			if ( preg_match( '/^commerce_event_[a-f0-9]{64}$/', (string) $name ) ) {
				return (string) $name;
			}

			throw new Api_Exception( 'Unknown secure material.', 'invalid_secret_material' );
		}

		return $keys[ $name ];
	}

	/**
	 * @param string $event_key Lowercase 64-hex event key.
	 * @return string
	 * @throws Api_Exception When the key is malformed.
	 */
	private static function commerce_event_name( $event_key ) {
		$event_key = (string) $event_key;
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $event_key ) ) {
			throw new Api_Exception( 'Invalid commerce event key.', 'invalid_commerce_event_key' );
		}

		return 'commerce_event_' . $event_key;
	}
}
