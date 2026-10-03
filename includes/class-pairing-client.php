<?php
/**
 * TLS-only bootstrap pairing client.
 *
 * Pairing deliberately does not use the HMAC client: the Spellexo backend has
 * no integration credential to verify until a claimed pairing is finalized.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Pairing_Client {
	/**
	 * Creates an unsigned PKCE-style pairing record after a merchant click.
	 *
	 * @param array<string, mixed> $payload Create payload.
	 * @return array<string, mixed>
	 * @throws Api_Exception On a safe, non-sensitive failure.
	 */
	public function create( $payload ) {
		return $this->request( 'POST', '/v1/integrations/wordpress/pairings', $payload );
	}

	/**
	 * Retrieves pairing status with the opaque poll token only in Authorization.
	 *
	 * @param string $pairing_id Pairing ID.
	 * @param string $poll_token Opaque high-entropy poll token.
	 * @return array<string, mixed>
	 * @throws Api_Exception On a safe, non-sensitive failure.
	 */
	public function status( $pairing_id, $poll_token ) {
		return $this->request( 'GET', '/v1/integrations/wordpress/pairings/' . rawurlencode( $pairing_id ) . '/status', null, $poll_token );
	}

	/**
	 * Performs the verifier-gated exchange. An exact retry can recover the same
	 * credential after a lost response; it never requests a replacement.
	 *
	 * @param string $pairing_id Pairing ID.
	 * @param string $poll_token Opaque high-entropy poll token.
	 * @param string $pairing_verifier One-time verifier.
	 * @return array<string, mixed>
	 * @throws Api_Exception On a safe, non-sensitive failure.
	 */
	public function finalize( $pairing_id, $poll_token, $pairing_verifier ) {
		return $this->request(
			'POST',
			'/v1/integrations/wordpress/pairings/' . rawurlencode( $pairing_id ) . '/finalize',
			array(
				'schema_version' => 1,
				'code_verifier'  => $pairing_verifier,
			),
			$poll_token
		);
	}

	/**
	 * @param string $method HTTP method.
	 * @param string $path Exact API path without query string.
	 * @param array<string, mixed>|null $payload JSON payload.
	 * @param string $poll_token Optional Spellexo pairing token.
	 * @return array<string, mixed>
	 * @throws Api_Exception On a safe failure.
	 */
	private function request( $method, $path, $payload = null, $poll_token = '' ) {
		$base_url = $this->base_url();
		$method   = strtoupper( (string) $method );
		$path     = (string) $path;

		if ( '' === $base_url || ! in_array( $method, array( 'GET', 'POST' ), true ) || 0 !== strpos( $path, '/' ) || false !== strpos( $path, '?' ) || false !== strpos( $path, '..' ) ) {
			throw new Api_Exception( 'Invalid pairing request.', 'invalid_pairing_request' );
		}

		if ( '' !== $poll_token && ! preg_match( '/^[A-Za-z0-9._~+\/=-]{32,512}$/', $poll_token ) ) {
			throw new Api_Exception( 'Invalid pairing credential.', 'invalid_poll_token' );
		}

		$body = null === $payload ? '' : wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $body ) {
			throw new Api_Exception( 'The pairing request could not be encoded.', 'pairing_encoding_failed' );
		}

		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
			'User-Agent'   => 'Spellexo-WooCommerce/' . SPELLEXO_WC_VERSION,
		);
		if ( '' !== $poll_token ) {
			$headers['Authorization'] = 'Spellexo-Pairing ' . $poll_token;
		}

		$response = wp_remote_request(
			$base_url . $path,
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => 8,
				'redirection' => 0,
				'sslverify'   => true,
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Api_Exception( 'The Spellexo pairing service could not be reached.', 'pairing_network_error', true );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$parsed = '' === $raw ? array() : json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 && is_array( $parsed ) ) {
			return $parsed;
		}

		/*
		 * Bootstrap responses are intentionally unauthenticated, so never trust
		 * their body or headers. HTTP 409 is nevertheless retry-safe here: the
		 * pairing service uses it when its local pairing store is temporarily
		 * unavailable. Classify only from the status code.
		 */
		$retryable = $status >= 500 || in_array( $status, array( 408, 409, 425, 429 ), true );

		if ( $retryable ) {
			throw new Api_Exception( 'The Spellexo pairing service is temporarily unavailable.', 'pairing_temporary_error', true );
		}

		throw new Api_Exception( 'The Spellexo pairing service rejected the request.', 'pairing_request_rejected', false );
	}

	/**
	 * @return string
	 */
	private function base_url() {
		$url   = (string) apply_filters( 'spellexo_woocommerce_pairing_api_base_url', 'https://api.spellexo.com' );
		$hosts = (array) apply_filters( 'spellexo_woocommerce_pairing_api_hosts', array( 'api.spellexo.com' ) );
		$parts = wp_parse_url( $url );

		return Url::is_allowed_https_url( $url, $hosts ) && is_array( $parts ) && ! isset( $parts['query'] ) ? untrailingslashit( $url ) : '';
	}
}
