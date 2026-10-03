<?php
/**
 * Signed WordPress HTTP API client for connector calls.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Api_Client {
	const DEFAULT_TIMEOUT = 8;
	const MAX_ATTEMPTS = 3;

	/**
	 * Makes an HMAC-signed connector request.
	 *
	 * The caller receives only parsed JSON and a status code. Response bodies are
	 * intentionally not logged because they may contain service-only data.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $path API path without query string.
	 * @param array<string, mixed>|null $payload Request payload.
	 * @param array<string, mixed> $options Request options.
	 * @return array{status:int,body:array<string,mixed>}
	 * @throws Api_Exception When the request cannot complete safely.
	 */
	public function request( $method, $path, $payload = null, $options = array() ) {
		$method = strtoupper( (string) $method );
		$path   = (string) $path;

		if ( ! in_array( $method, array( 'GET', 'POST' ), true ) || 0 !== strpos( $path, '/' ) || false !== strpos( $path, '?' ) || false !== strpos( $path, '..' ) ) {
			throw new Api_Exception( 'Invalid connector request.', 'invalid_request' );
		}

		$base_url = $this->base_url();
		if ( '' === $base_url ) {
			throw new Api_Exception( 'The Spellexo service URL is invalid.', 'invalid_service_url' );
		}

		$installation_uid = (string) Options::get( Options::INSTALLATION_UID, '' );
		$secret           = Secret_Store::get();

		if ( '' === $installation_uid || null === $secret || null === Signature::decode_secret( $secret ) ) {
			throw new Api_Exception( 'The local connection credential is unavailable.', 'credential_unavailable' );
		}

		$body = '';
		if ( null !== $payload ) {
			$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $body ) {
				throw new Api_Exception( 'The connector request could not be encoded.', 'request_encoding_failed' );
			}
		}

		$timeout      = isset( $options['timeout'] ) ? min( 15, max( 1, (int) $options['timeout'] ) ) : self::DEFAULT_TIMEOUT;
		$max_attempts = isset( $options['max_attempts'] ) ? min( self::MAX_ATTEMPTS, max( 1, (int) $options['max_attempts'] ) ) : self::MAX_ATTEMPTS;
		$idempotency  = isset( $options['idempotency_key'] ) ? (string) $options['idempotency_key'] : '';
		$last_error   = null;
		$credential_version = (int) Options::get( Options::CREDENTIAL_VERSION, 1 );

		if ( $credential_version < 1 || '' === $idempotency || strlen( $idempotency ) > 255 || ! preg_match( '/^[A-Za-z0-9._:-]+$/', $idempotency ) ) {
			throw new Api_Exception( 'The connector request is missing a valid idempotency key.', 'invalid_idempotency_key' );
		}

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$timestamp = time();
			$nonce     = Signature::nonce();
			$body_hash = Signature::body_hash( $body );
			$canonical = Signature::canonical_string( $installation_uid, $credential_version, $timestamp, $nonce, $method, $path, $body, $idempotency );
			$headers   = array(
				'Accept'                          => 'application/json',
				'Content-Type'                    => 'application/json',
				'X-Spellexo-Protocol'             => '1',
				'X-Spellexo-Installation'         => $installation_uid,
				'X-Spellexo-Credential-Version'   => (string) $credential_version,
				'X-Spellexo-Timestamp'            => (string) $timestamp,
				'X-Spellexo-Nonce'                => $nonce,
				'X-Spellexo-Content-SHA256'       => $body_hash,
				'X-Spellexo-Signature'            => Signature::sign( $secret, $canonical ),
				'Idempotency-Key'                 => $idempotency,
			);

			$response = wp_remote_request(
				$base_url . $path,
				array(
					'method'      => $method,
					'headers'     => $headers,
					'body'        => $body,
					'timeout'     => $timeout,
					'redirection' => 0,
					'sslverify'   => true,
					'data_format' => 'body',
					'user-agent'  => 'Spellexo-WooCommerce/' . SPELLEXO_WC_VERSION,
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error = new Api_Exception( 'The Spellexo service could not be reached.', 'network_error', true );
				if ( $attempt < $max_attempts ) {
					$this->bounded_backoff( $attempt );
					continue;
				}
				break;
			}

			$verified_response = $this->verified_response( $response, $installation_uid, $credential_version, $nonce, $secret );
			if ( null === $verified_response ) {
				/*
				 * A failure can occur before the service has authenticated the request,
				 * so no shared secret is available to sign that response. Trust only the
				 * HTTP status for deciding whether a retry is safe; never consume an
				 * unsigned response body or header as application data.
				 */
				$unsigned_status    = (int) wp_remote_retrieve_response_code( $response );
				$unsigned_retryable = 408 === $unsigned_status || 429 === $unsigned_status || $unsigned_status >= 500;
				$last_error         = $unsigned_retryable
					? new Api_Exception( 'The Spellexo service is temporarily unavailable.', 'service_temporary_error', true )
					: new Api_Exception( 'The Spellexo service response could not be verified.', 'invalid_signed_response' );

				if ( $unsigned_retryable && $attempt < $max_attempts ) {
					$this->bounded_backoff( $attempt );
					continue;
				}
				break;
			}

			$status = $verified_response['status'];
			$parsed = $verified_response['body'];

			/* verified_response() accepts only a JSON object/array body. */
			if ( $status >= 200 && $status < 300 ) {
				return array(
					'status' => $status,
					'body'   => $parsed,
				);
			}

			$retryable = 408 === $status || 429 === $status || $status >= 500 || ( 409 === $status && isset( $parsed['error'] ) && 'request_in_progress' === $parsed['error'] );
			$last_error = new Api_Exception(
				$retryable ? 'The Spellexo service is temporarily unavailable.' : 'The Spellexo service rejected the request.',
				$retryable ? 'service_temporary_error' : 'service_request_rejected',
				$retryable
			);

			if ( $retryable && $attempt < $max_attempts ) {
				$this->bounded_backoff( $attempt );
				continue;
			}

			break;
		}

		throw $last_error instanceof Api_Exception ? $last_error : new Api_Exception( 'The connector request failed.', 'request_failed' );
	}

	/**
	 * Gets a validated base URL for connector calls.
	 *
	 * @return string
	 */
	private function base_url() {
		$base_url      = (string) apply_filters( 'spellexo_woocommerce_api_base_url', 'https://api.spellexo.com' );
		$allowed_hosts = (array) apply_filters( 'spellexo_woocommerce_api_hosts', array( 'api.spellexo.com' ) );
		$parts         = wp_parse_url( $base_url );

		if ( ! Url::is_allowed_https_url( $base_url, $allowed_hosts ) || ! is_array( $parts ) || isset( $parts['query'] ) ) {
			return '';
		}

		return untrailingslashit( $base_url );
	}

	/**
	 * Waits briefly before a retry. The wait is bounded and runs only in a
	 * deliberate connector action, never during a Woo product-save request.
	 *
	 * @param int $attempt Attempt number.
	 * @return void
	 */
	private function bounded_backoff( $attempt ) {
		$maximum_microseconds = 150000 * (int) $attempt;
		usleep( random_int( 50000, $maximum_microseconds ) );
	}

	/**
	 * Verifies the exact JSON response bytes before any status/body is trusted.
	 * The response signature binds to the request nonce, installation, credential
	 * version, and HTTP status, so a valid response cannot be replayed for a
	 * different request.
	 *
	 * @param array<string, mixed> $response WordPress HTTP response.
	 * @param string $installation_uid Installation UUID.
	 * @param int    $credential_version Credential version.
	 * @param string $request_nonce Exact outbound request nonce.
	 * @param string $secret Integration secret.
	 * @return array{status:int,body:array<string,mixed>}|null
	 */
	private function verified_response( $response, $installation_uid, $credential_version, $request_nonce, $secret ) {
		$status            = (int) wp_remote_retrieve_response_code( $response );
		$raw               = (string) wp_remote_retrieve_body( $response );
		$protocol          = trim( (string) wp_remote_retrieve_header( $response, 'x-spellexo-protocol' ) );
		$content_hash      = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'x-spellexo-content-sha256' ) ) );
		$response_signature = trim( (string) wp_remote_retrieve_header( $response, 'x-spellexo-response-signature' ) );
		$content_type      = trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$expected_hash     = Signature::body_hash( $raw );

		if ( $status < 100 || 599 < $status || '1' !== $protocol || ! preg_match( '/^[a-f0-9]{64}$/', $content_hash ) || ! hash_equals( $expected_hash, $content_hash ) || ! preg_match( '/^application\/json(?:\s*;|$)/i', $content_type ) || ! Signature::verify_response( $secret, $installation_uid, $credential_version, $request_nonce, $status, $raw, $response_signature ) ) {
			return null;
		}

		$parsed = json_decode( $raw, true );

		return is_array( $parsed ) && JSON_ERROR_NONE === json_last_error() ? array(
			'status' => $status,
			'body'   => $parsed,
		) : null;
	}
}
