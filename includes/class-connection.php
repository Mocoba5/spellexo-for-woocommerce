<?php
/**
 * One-time dashboard pairing and final HMAC connection state.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Connection {
	const DASHBOARD_BASE_URL = 'https://dashboard.spellexo.com/';

	/** @var Api_Client */
	private $api_client;

	/** @var Pairing_Client */
	private $pairing_client;

	/** @var Compatibility */
	private $compatibility;

	/**
	 * @param Api_Client|null $api_client Final-credential API client override.
	 * @param Pairing_Client|null $pairing_client Bootstrap pairing client override.
	 * @param Compatibility|null $compatibility Compatibility override.
	 */
	public function __construct( $api_client = null, $pairing_client = null, $compatibility = null ) {
		$this->api_client     = $api_client instanceof Api_Client ? $api_client : new Api_Client();
		$this->pairing_client = $pairing_client instanceof Pairing_Client ? $pairing_client : new Pairing_Client();
		$this->compatibility  = $compatibility instanceof Compatibility ? $compatibility : new Compatibility();
	}

	/**
	 * Starts a merchant-approved, TLS-only PKCE-style pairing request.
	 *
	 * The backend has no integration credential at this point, so this path is
	 * intentionally separate from Api_Client and never sends a verifier.
	 *
	 * @return array<string, mixed>
	 * @throws Api_Exception When pairing cannot start safely.
	 */
	public function start_pairing() {
		if ( ! $this->compatibility->can_connect() || ! Secret_Store::is_available() ) {
			throw new Api_Exception( 'This site must meet the WooCommerce requirements, use HTTPS, and support encrypted local secret storage before it can connect.', 'environment_not_ready' );
		}

		$installation_uid = (string) Options::get( Options::INSTALLATION_UID, '' );
		$site_url         = Url::canonical_site_url();
		$origin           = Url::canonical_origin();
		$existing         = Options::connection();

		if ( '' === $installation_uid || '' === $site_url || '' === $origin ) {
			throw new Api_Exception( 'This site URL or installation identity is unavailable.', 'invalid_site_identity' );
		}

		$verifier = $this->fresh_pairing_verifier();

		try {
			$body = $this->pairing_client->create(
				array(
					'schema_version'      => 1,
					'installation_uid'     => $installation_uid,
					'canonical_site_url'   => $site_url,
					'canonical_origin'     => $origin,
					'code_challenge'       => $this->code_challenge( $verifier ),
					'code_challenge_method'=> 'S256',
					'plugin_version'       => SPELLEXO_WC_VERSION,
					'wordpress_version'    => get_bloginfo( 'version' ),
					'woocommerce_version'  => defined( 'WC_VERSION' ) ? WC_VERSION : '',
					'php_version'          => PHP_VERSION,
				)
			);

			if ( ! $this->valid_pairing_create_response( $body ) ) {
				throw new Api_Exception( 'The pairing response was invalid.', 'invalid_pairing_response' );
			}

			$expires_at = strtotime( (string) $body['expires_at'] );
			if ( false === $expires_at || $expires_at <= time() ) {
				throw new Api_Exception( 'The pairing response has expired.', 'expired_pairing_response' );
			}

			Secret_Store::set_named( 'pairing_poll_token', (string) $body['poll_token'] );
		} catch ( Api_Exception $exception ) {
			$this->clear_temporary_pairing_material( $existing, $exception->error_code() );
			throw $exception;
		}

		/* The claim URL is response-only; it must never enter the plain option. */
		$claim_dashboard_url = esc_url_raw( (string) $body['dashboard_url'] );
		$connection = Options::update_connection(
			array(
				/* Keep an active integration serving while a merchant deliberately reconnects. */
				'status'              => $this->has_active_credential( $existing ) ? 'connected' : 'pairing',
				'canonical_site_url'  => $site_url,
				'canonical_origin'    => $origin,
				'pairing_id'          => sanitize_text_field( (string) $body['pairing_id'] ),
				'pairing_in_progress' => true,
				'pairing_expires_at'  => (int) $expires_at,
				'dashboard_url'       => $this->has_active_credential( $existing ) ? self::DASHBOARD_BASE_URL : '',
				'last_error_code'     => '',
			)
		);
		$connection['dashboard_url'] = $claim_dashboard_url;

		return $connection;
	}

	/**
	 * Polls an already-started pairing with a protected poll token. Status polling
	 * never receives the final integration secret; a separate finalize exchange
	 * proves the verifier and may recover the same credential after a lost response.
	 *
	 * @return array<string, mixed>
	 * @throws Api_Exception When a bootstrap response is unsafe.
	 */
	public function poll_pairing() {
		$connection = Options::connection();

		if ( empty( $connection['pairing_in_progress'] ) || '' === $connection['pairing_id'] ) {
			return $connection;
		}

		if ( (int) $connection['pairing_expires_at'] <= time() ) {
			return $this->clear_temporary_pairing_material( $connection, 'pairing_expired' );
		}

		$poll_token = Secret_Store::get_named( 'pairing_poll_token' );
		$verifier   = Secret_Store::get_named( 'pairing_verifier' );
		if ( null === $poll_token || null === $verifier ) {
			return $this->clear_temporary_pairing_material( $connection, 'pairing_material_unavailable' );
		}

		$response = $this->pairing_client->status( $connection['pairing_id'], $poll_token );
		if ( ! $this->valid_pairing_status_response( $response ) ) {
			throw new Api_Exception( 'The pairing status response was invalid.', 'invalid_pairing_status' );
		}
		$status   = isset( $response['status'] ) ? sanitize_key( (string) $response['status'] ) : 'pending';
		$expires_at = strtotime( (string) $response['expires_at'] );
		if ( false === $expires_at ) {
			throw new Api_Exception( 'The pairing status response was invalid.', 'invalid_pairing_status' );
		}

		if ( 'pending' === $status ) {
			if ( $expires_at <= time() ) {
				return $this->clear_temporary_pairing_material( $connection, 'pairing_expired' );
			}

			return Options::update_connection( array( 'pairing_expires_at' => (int) $expires_at ) );
		}

		if ( in_array( $status, array( 'expired', 'revoked', 'failed' ), true ) ) {
			return $this->clear_temporary_pairing_material( $connection, 'pairing_' . $status );
		}

		if ( 'ready_to_finalize' !== $status ) {
			throw new Api_Exception( 'The pairing status response was invalid.', 'invalid_pairing_status' );
		}
		if ( $expires_at <= time() ) {
			return $this->clear_temporary_pairing_material( $connection, 'pairing_expired' );
		}

		$final = $this->pairing_client->finalize( $connection['pairing_id'], $poll_token, $verifier );
		if ( ! $this->valid_finalization_response( $final ) ) {
			throw new Api_Exception( 'The pairing finalization response was invalid.', 'invalid_pairing_finalization' );
		}

		/* Store the final credential before removing any recovery material. */
		$credential_version = max( 1, (int) $final['credential_version'] );
		Secret_Store::set( (string) $final['integration_secret'] );
		$stored_secret = Secret_Store::get();
		if ( ! is_string( $stored_secret ) || ! hash_equals( (string) $final['integration_secret'], $stored_secret ) ) {
			throw new Api_Exception( 'The final integration credential could not be saved locally.', 'connection_write_failed' );
		}
		Options::update( Options::CREDENTIAL_VERSION, $credential_version );
		if ( $credential_version !== (int) Options::get( Options::CREDENTIAL_VERSION, 0 ) ) {
			throw new Api_Exception( 'The final credential version could not be saved locally.', 'connection_write_failed' );
		}

		$expected = array(
			'status'             => 'connected',
			'store_id'           => sanitize_text_field( (string) $final['store_id'] ),
			'public_store_key'   => sanitize_text_field( (string) $final['public_store_key'] ),
			'credential_version' => $credential_version,
			'api_version'        => isset( $final['api_version'] ) ? max( 1, (int) $final['api_version'] ) : 1,
			'pairing_id'         => '',
			'pairing_in_progress' => false,
			'pairing_expires_at' => 0,
			'dashboard_url'      => self::DASHBOARD_BASE_URL,
			'last_error_code'    => '',
		);
		$connection = Options::update_connection(
			array(
				'status'              => $expected['status'],
				'store_id'            => $expected['store_id'],
				'public_store_key'    => $expected['public_store_key'],
				'credential_version'  => $expected['credential_version'],
				'api_version'         => $expected['api_version'],
				'pairing_id'          => $expected['pairing_id'],
				'pairing_in_progress' => $expected['pairing_in_progress'],
				'pairing_expires_at'  => $expected['pairing_expires_at'],
				'dashboard_url'       => $expected['dashboard_url'],
				'last_error_code'     => $expected['last_error_code'],
				'connected_at'        => time(),
			)
		);
		$stored = Options::connection();
		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists( $key, $stored ) || $value !== $stored[ $key ] ) {
				throw new Api_Exception( 'The connection could not be saved locally.', 'connection_write_failed' );
			}
		}

		/* Recovery material is disposable only after both local records verify. */
		Secret_Store::delete_named( 'pairing_verifier' );
		Secret_Store::delete_named( 'pairing_poll_token' );

		return $stored;
	}

	/**
	 * Sends a signed explicit disconnect request and disables storefront output.
	 *
	 * @return array<string, mixed>
	 * @throws Api_Exception On a temporary failure; local final credentials are
	 *                       retained so an administrator can retry safely.
	 */
	public function disconnect() {
		$connection = Options::connection();
		$secret     = Secret_Store::get();

		if ( null === $secret || '' === $connection['store_id'] || null === Signature::decode_secret( $secret ) ) {
			Secret_Store::delete_named( 'pairing_verifier' );
			Secret_Store::delete_named( 'pairing_poll_token' );
			return Options::update_connection(
				array(
					'status'              => 'revoked',
					'public_store_key'    => '',
					'pairing_id'          => '',
					'pairing_in_progress' => false,
					'last_error_code'     => '',
				)
			);
		}

		$this->api_client->request(
			'POST',
			'/v1/integrations/wordpress/disconnect',
			array(
				'schema_version' => 1,
				'reason'         => 'merchant_disconnect',
			),
			array(
				'idempotency_key' => Signature::idempotency_key( 'disconnect', array( $connection['store_id'], Options::get( Options::CREDENTIAL_VERSION, 1 ) ), $secret ),
			)
		);

		Secret_Store::delete();
		Secret_Store::delete_named( 'pairing_verifier' );
		Secret_Store::delete_named( 'pairing_poll_token' );

		return Options::update_connection(
			array(
				'status'              => 'revoked',
				'public_store_key'    => '',
				'pairing_id'          => '',
				'pairing_in_progress' => false,
				'pairing_expires_at'  => 0,
				'dashboard_url'       => '',
				'last_error_code'     => '',
			)
		);
	}

	/**
	 * @return bool
	 */
	public function is_connected() {
		return $this->has_active_credential( Options::connection() );
	}

	/**
	 * @param Api_Exception $exception Exception.
	 * @return void
	 */
	public function record_error( $exception ) {
		if ( $exception instanceof Api_Exception ) {
			Options::update_connection( array( 'last_error_code' => $exception->error_code() ) );
		}
	}

	/**
	 * @return string
	 * @throws Api_Exception If encrypted temporary material cannot be created.
	 */
	private function fresh_pairing_verifier() {
		$existing = Secret_Store::get_named( 'pairing_verifier' );
		if ( null !== $existing && preg_match( '/^[A-Za-z0-9_-]{43}$/', $existing ) ) {
			return $existing;
		}

		$verifier = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		Secret_Store::set_named( 'pairing_verifier', $verifier );

		return $verifier;
	}

	/**
	 * @param string $verifier ASCII one-time verifier.
	 * @return string
	 */
	private function code_challenge( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * @param array<string, mixed> $body Create response.
	 * @return bool
	 */
	private function valid_pairing_create_response( $body ) {
		if ( ! is_array( $body ) || 1 !== (int) ( isset( $body['schema_version'] ) ? $body['schema_version'] : 0 ) || empty( $body['pairing_id'] ) || empty( $body['poll_token'] ) || empty( $body['dashboard_url'] ) || empty( $body['expires_at'] ) ) {
			return false;
		}

		$pairing_id = (string) $body['pairing_id'];

		return (bool) preg_match( '/^[A-Za-z0-9._-]{16,256}$/', $pairing_id )
			&& (bool) preg_match( '/^[A-Za-z0-9._~+\/=-]{32,512}$/', (string) $body['poll_token'] )
			&& $this->is_allowed_dashboard_url( (string) $body['dashboard_url'], $pairing_id );
	}

	/**
	 * @param array<string, mixed> $body Finalization response.
	 * @return bool
	 */
	private function valid_finalization_response( $body ) {
		if ( ! is_array( $body ) || 1 !== (int) ( isset( $body['schema_version'] ) ? $body['schema_version'] : 0 ) || 'connected' !== ( isset( $body['status'] ) ? (string) $body['status'] : '' ) || empty( $body['store_id'] ) || empty( $body['public_store_key'] ) || empty( $body['integration_secret'] ) || empty( $body['credential_version'] ) ) {
			return false;
		}

		return (bool) preg_match( '/^[a-zA-Z0-9-]{16,128}$/', (string) $body['store_id'] )
			&& (bool) preg_match( '/^[A-Za-z0-9_-]{32,128}$/', (string) $body['public_store_key'] )
			&& null !== Signature::decode_secret( (string) $body['integration_secret'] )
			&& (int) $body['credential_version'] >= 1;
	}

	/**
	 * @param array<string, mixed> $body Pairing status response.
	 * @return bool
	 */
	private function valid_pairing_status_response( $body ) {
		if ( ! is_array( $body ) || 1 !== (int) ( isset( $body['schema_version'] ) ? $body['schema_version'] : 0 ) || empty( $body['status'] ) || empty( $body['expires_at'] ) ) {
			return false;
		}

		return in_array( sanitize_key( (string) $body['status'] ), array( 'pending', 'ready_to_finalize', 'expired', 'revoked', 'failed' ), true ) && false !== strtotime( (string) $body['expires_at'] );
	}

	/**
	 * Clears only temporary pairing material. An existing active integration
	 * credential and connection state survive failed or expired reconnects.
	 *
	 * @param array<string, mixed> $connection Existing state.
	 * @param string $reason Safe diagnostic code.
	 * @return array<string, mixed>
	 */
	private function clear_temporary_pairing_material( $connection, $reason ) {
		Secret_Store::delete_named( 'pairing_verifier' );
		Secret_Store::delete_named( 'pairing_poll_token' );

		$active = $this->has_active_credential( $connection );

		return Options::update_connection(
			array(
				'status'              => $active ? 'connected' : 'not_connected',
				'pairing_id'          => '',
				'pairing_in_progress' => false,
				'pairing_expires_at'  => 0,
				'dashboard_url'       => $active ? self::DASHBOARD_BASE_URL : '',
				'last_error_code'     => $reason,
			)
		);
	}

	/**
	 * @param array<string, mixed> $connection Connection state.
	 * @return bool
	 */
	private function has_active_credential( $connection ) {
		$secret = Secret_Store::get();

		return 'connected' === $connection['status'] && '' !== $connection['store_id'] && '' !== $connection['public_store_key'] && null !== $secret && null !== Signature::decode_secret( $secret );
	}

	/**
	 * The claim credential belongs in the URL fragment so it is available to the
	 * dashboard but never sent in an HTTP request. Validate that exact fragment
	 * instead of using the general external-URL helper, which correctly rejects
	 * fragments for ordinary outbound requests.
	 *
	 * @param string $url Dashboard URL.
	 * @param string $pairing_id Pairing identifier from the response body.
	 * @return bool
	 */
	private function is_allowed_dashboard_url( $url, $pairing_id ) {
		$hosts = (array) apply_filters( 'spellexo_woocommerce_dashboard_hosts', array( 'dashboard.spellexo.com' ) );
		$parts = wp_parse_url( $url );

		if (
			! is_array( $parts )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| empty( $parts['fragment'] )
			|| '/connect/wordpress' !== ( isset( $parts['path'] ) ? $parts['path'] : '' )
			|| ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] )
		) {
			return false;
		}

		$fragment_position = strpos( $url, '#' );
		if ( false === $fragment_position || ! Url::is_allowed_https_url( substr( $url, 0, $fragment_position ), $hosts ) ) {
			return false;
		}

		$claims = array();
		foreach ( explode( '&', (string) $parts['fragment'] ) as $component ) {
			$pair = explode( '=', $component, 2 );
			if ( 2 !== count( $pair ) || ! in_array( $pair[0], array( 'pairing_id', 'claim_token' ), true ) || isset( $claims[ $pair[0] ] ) ) {
				return false;
			}

			$claims[ $pair[0] ] = $pair[1];
		}

		return isset( $claims['pairing_id'], $claims['claim_token'] )
			&& hash_equals( (string) $pairing_id, (string) $claims['pairing_id'] )
			&& 1 === preg_match( '/^[A-Za-z0-9_-]{43,128}$/', (string) $claims['claim_token'] );
	}
}
