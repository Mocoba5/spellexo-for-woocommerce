<?php
/**
 * HMAC request-signing primitives.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Signature {
	const PROTOCOL = 'spellexo-hmac-v1';
	const RESPONSE_PROTOCOL = 'spellexo-hmac-response-v1';
	const VERSION = 'v1';

	/**
	 * Builds the canonical string used for request signatures.
	 *
	 * @param string $installation_uid Installation UUID.
	 * @param int    $credential_version Credential version.
	 * @param int    $timestamp Unix timestamp.
	 * @param string $nonce Per-request nonce.
	 * @param string $method HTTP method.
	 * @param string $path Request path only.
	 * @param string $body Encoded request body bytes.
	 * @param string $idempotency_key Idempotency key.
	 * @return string
	 */
	public static function canonical_string( $installation_uid, $credential_version, $timestamp, $nonce, $method, $path, $body, $idempotency_key ) {
		return implode(
			"\n",
			array(
				self::PROTOCOL,
				(string) $installation_uid,
				(string) $credential_version,
				(string) $timestamp,
				(string) $nonce,
				strtoupper( (string) $method ),
				(string) $path,
				'application/json',
				self::body_hash( $body ),
				(string) $idempotency_key,
			)
		);
	}

	/**
	 * Builds the canonical string for a signed backend response. The request nonce
	 * binds the response to one exact outbound request and prevents a response
	 * from being replayed across requests.
	 *
	 * @param string $installation_uid Installation UUID.
	 * @param int    $credential_version Credential version.
	 * @param string $request_nonce Exact nonce sent in the request.
	 * @param int    $http_status Signed HTTP response status.
	 * @param string $body Exact response body bytes.
	 * @return string
	 */
	public static function response_canonical_string( $installation_uid, $credential_version, $request_nonce, $http_status, $body ) {
		return implode(
			"\n",
			array(
				self::RESPONSE_PROTOCOL,
				(string) $installation_uid,
				(string) $credential_version,
				(string) $request_nonce,
				(string) (int) $http_status,
				self::body_hash( $body ),
			)
		);
	}

	/**
	 * Verifies a backend response signature in constant time.
	 *
	 * @param string $secret Integration secret.
	 * @param string $installation_uid Installation UUID.
	 * @param int    $credential_version Credential version.
	 * @param string $request_nonce Exact nonce sent in the request.
	 * @param int    $http_status Signed HTTP response status.
	 * @param string $body Exact response body bytes.
	 * @param string $provided_signature Response signature header value.
	 * @return bool
	 */
	public static function verify_response( $secret, $installation_uid, $credential_version, $request_nonce, $http_status, $body, $provided_signature ) {
		return self::verify(
			$secret,
			self::response_canonical_string( $installation_uid, $credential_version, $request_nonce, $http_status, $body ),
			$provided_signature
		);
	}

	/**
	 * Returns a lowercase SHA-256 digest of the exact transmitted body bytes.
	 *
	 * @param string $body Request body bytes.
	 * @return string
	 */
	public static function body_hash( $body ) {
		return hash( 'sha256', (string) $body );
	}

	/**
	 * Creates an HMAC-SHA256 signature value.
	 *
	 * @param string $secret Integration secret.
	 * @param string $canonical_string Canonical request string.
	 * @return string
	 */
	public static function sign( $secret, $canonical_string ) {
		$raw_secret = self::decode_secret( $secret );

		if ( null === $raw_secret ) {
			return '';
		}

		return self::VERSION . '=' . hash_hmac( 'sha256', $canonical_string, $raw_secret );
	}

	/**
	 * Strictly decodes the final integration credential to its 32 raw HMAC bytes.
	 *
	 * @param string $secret Unpadded base64url credential.
	 * @return string|null
	 */
	public static function decode_secret( $secret ) {
		$secret = (string) $secret;

		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $secret ) ) {
			return null;
		}

		$raw = base64_decode( strtr( $secret, '-_', '+/' ) . '=', true );

		return is_string( $raw ) && 32 === strlen( $raw ) ? $raw : null;
	}

	/**
	 * Verifies a v1 signature in constant time.
	 *
	 * @param string $secret Integration secret.
	 * @param string $canonical_string Canonical request string.
	 * @param string $provided_signature Header value.
	 * @return bool
	 */
	public static function verify( $secret, $canonical_string, $provided_signature ) {
		$provided_signature = (string) $provided_signature;

		if ( 0 !== strpos( $provided_signature, self::VERSION . '=' ) ) {
			return false;
		}

		$hex = substr( $provided_signature, strlen( self::VERSION ) + 1 );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $hex ) ) {
			return false;
		}

		$expected = self::sign( $secret, $canonical_string );

		return '' !== $expected && hash_equals( $expected, $provided_signature );
	}

	/**
	 * Creates a cryptographically random replay nonce.
	 *
	 * @return string
	 */
	public static function nonce() {
		return rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Creates a deterministic idempotency key that does not reveal a secret.
	 *
	 * @param string $operation Operation name.
	 * @param array  $parts Stable operation inputs.
	 * @param string $secret Integration secret.
	 * @return string
	 */
	public static function idempotency_key( $operation, $parts, $secret ) {
		$material = $operation . "\n" . wp_json_encode( array_values( $parts ) );
		$raw_secret = self::decode_secret( $secret );

		if ( null === $raw_secret ) {
			return '';
		}

		return 'spxwc-' . substr( hash_hmac( 'sha256', $material, $raw_secret ), 0, 48 );
	}
}
