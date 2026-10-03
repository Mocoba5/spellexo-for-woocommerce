<?php
/**
 * Plugin-owned option storage.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Options {
	const PREFIX = 'spellexo_woocommerce_';

	const INSTALLATION_UID = 'installation_uid';
	const INTEGRATION_SECRET = 'integration_secret';
	const CREDENTIAL_VERSION = 'credential_version';
	const CONNECTION = 'connection';
	const SCHEMA_VERSION = 'schema_version';
	const LAST_SYNC = 'last_sync';
	const LAST_SYNC_ATTEMPT = 'last_sync_attempt';
	const LAST_ERROR = 'last_error';
	const SYNC_RUN = 'sync_run';
	const SYNC_START_LOCK = 'sync_start_lock';
	const SYNC_PAGE_LOCK = 'sync_page_lock';
	const HEARTBEAT_LOCK = 'heartbeat_lock';
	const SITE_REVISION = 'site_revision';
	const QUEUE_FAILURES = 'queue_failures';
	const ACKNOWLEDGED_COMMAND_IDS = 'acknowledged_command_ids';
	const COMMERCE_EVENT_KEYS = 'commerce_event_keys';
	const AUDIT_LOG = 'audit_log';

	/**
	 * Retrieves a plugin-owned option.
	 *
	 * @param string $key Option suffix.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		return get_option( self::name( $key ), $default );
	}

	/**
	 * Stores a plugin-owned option without autoloading it.
	 *
	 * @param string $key Option suffix.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public static function update( $key, $value ) {
		return update_option( self::name( $key ), $value, false );
	}

	/**
	 * Adds an option without autoloading it when it does not already exist.
	 *
	 * @param string $key Option suffix.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public static function add( $key, $value ) {
		return add_option( self::name( $key ), $value, '', 'no' );
	}

	/**
	 * Deletes a plugin-owned option.
	 *
	 * @param string $key Option suffix.
	 * @return bool
	 */
	public static function delete( $key ) {
		return delete_option( self::name( $key ) );
	}

	/**
	 * Atomically claims a short-lived plugin-owned option lease.
	 *
	 * add_option() is atomic for the normal uncontended path. A stale lease is
	 * reclaimed with a conditional database update, so one worker cannot erase
	 * another worker's replacement lease between a read and a write. The token
	 * must include its creation timestamp followed by a random hexadecimal
	 * component; it is not a credential and is never sent remotely.
	 *
	 * @param string $key Option suffix.
	 * @param string $token Candidate lease token.
	 * @param int    $now Current Unix timestamp.
	 * @param int    $ttl Lease duration in seconds.
	 * @return bool
	 */
	public static function acquire_lease_lock( $key, $token, $now, $ttl ) {
		$key   = (string) $key;
		$token = (string) $token;
		$now   = max( 1, (int) $now );
		$ttl   = max( 1, (int) $ttl );

		if ( ! preg_match( '/^[a-z0-9_]{1,64}$/', $key ) || ! self::is_valid_lease_token( $token ) ) {
			return false;
		}

		if ( self::add( $key, $token ) ) {
			return true;
		}

		$previous = (string) self::get( $key, '' );
		if ( ! self::is_stale_lease_value( $previous, $now, $ttl ) ) {
			return false;
		}

		global $wpdb;
		if ( isset( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'query' ) ) {
			$option_name = self::name( $key );
			$sql         = $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$token,
				$option_name,
				$previous
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- A conditional update provides compare-and-swap lease recovery; all variable values are prepared.
			$updated = $wpdb->query( $sql );
			if ( 1 === (int) $updated ) {
				if ( function_exists( 'wp_cache_delete' ) ) {
					wp_cache_delete( $option_name, 'options' );
				}

				return true;
			}

			return false;
		}

		/* Unit-test/nonstandard fallback. Production WordPress provides $wpdb. */
		if ( ! hash_equals( $previous, (string) self::get( $key, '' ) ) ) {
			return false;
		}

		return self::update( $key, $token );
	}

	/**
	 * Releases this worker's lease without deleting a newer replacement lease.
	 *
	 * @param string $key Option suffix.
	 * @param string $token Lease token returned to the caller.
	 * @return bool
	 */
	public static function release_lease_lock( $key, $token ) {
		$key   = (string) $key;
		$token = (string) $token;
		if ( ! preg_match( '/^[a-z0-9_]{1,64}$/', $key ) || ! self::is_valid_lease_token( $token ) ) {
			return false;
		}

		global $wpdb;
		if ( isset( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'query' ) ) {
			$option_name = self::name( $key );
			$sql         = $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$option_name,
				$token
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- A conditional delete prevents an expired worker from releasing a newer lease; all variable values are prepared.
			$deleted = $wpdb->query( $sql );
			if ( 1 === (int) $deleted && function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $option_name, 'options' );
			}

			return 1 === (int) $deleted;
		}

		/* Unit-test/nonstandard fallback. */
		if ( ! hash_equals( $token, (string) self::get( $key, '' ) ) ) {
			return false;
		}

		return self::delete( $key );
	}

	/**
	 * @param mixed $value Stored lease value.
	 * @param int   $now Current Unix timestamp.
	 * @param int   $ttl Lease duration in seconds.
	 * @return bool
	 */
	public static function is_stale_lease_value( $value, $now, $ttl ) {
		$matches = array();
		$now     = max( 1, (int) $now );
		$ttl     = max( 1, (int) $ttl );

		return 1 === preg_match( '/^([1-9][0-9]*):[a-f0-9]{32}$/', (string) $value, $matches ) && (int) $matches[1] <= $now - $ttl;
	}

	/**
	 * @param string $token Candidate lease token.
	 * @return bool
	 */
	private static function is_valid_lease_token( $token ) {
		return 1 === preg_match( '/^[1-9][0-9]*:[a-f0-9]{32}$/', (string) $token );
	}

	/**
	 * Gets the locally retained connection state.
	 *
	 * @return array<string, mixed>
	 */
	public static function connection() {
		$connection = self::get( self::CONNECTION, array() );

		if ( ! is_array( $connection ) ) {
			$connection = array();
		}

		return wp_parse_args(
			$connection,
			array(
				'status'              => 'not_connected',
				'store_id'            => '',
				'public_store_key'    => '',
				'canonical_site_url'  => '',
				'canonical_origin'    => '',
				'pairing_id'          => '',
				'pairing_in_progress'=> false,
				'pairing_expires_at'  => 0,
				'dashboard_url'       => '',
				'api_version'         => 1,
				'credential_version'  => 1,
				'last_heartbeat_at'   => 0,
				'last_error_code'     => '',
				'connected_at'        => 0,
			)
		);
	}

	/**
	 * Updates only declared connection-state fields.
	 *
	 * @param array<string, mixed> $changes Connection changes.
	 * @return array<string, mixed>
	 */
	public static function update_connection( $changes ) {
		$current = self::connection();
		$allowed = array_keys( $current );

		foreach ( $changes as $key => $value ) {
			if ( in_array( $key, $allowed, true ) ) {
				$current[ $key ] = $value;
			}
		}

		self::update( self::CONNECTION, $current );

		return $current;
	}

	/**
	 * Atomically advances the plugin-owned signed-BIGINT catalog revision.
	 *
	 * The decimal string is deliberately retained as a string: JSON number
	 * consumers (notably browser tooling) cannot safely represent every BIGINT
	 * value. The normal WordPress path uses a connection-local MySQL increment
	 * so concurrent background workers cannot issue the same revision.
	 *
	 * @return string Positive decimal revision, at most PHP signed BIGINT max.
	 * @throws Api_Exception When the local counter cannot advance safely.
	 */
	public static function next_site_revision() {
		$option_name = self::name( self::SITE_REVISION );

		self::add( self::SITE_REVISION, '0' );

		global $wpdb;
		if ( isset( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'get_var' ) ) {
			$sql = $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID( CAST( option_value AS UNSIGNED ) + 1 ) WHERE option_name = %s AND option_value REGEXP '^[0-9]+$' AND CAST( option_value AS UNSIGNED ) < 9223372036854775807",
				$option_name
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- A connection-local atomic counter prevents concurrent duplicate revisions; the only value is prepared.
			$updated = $wpdb->query( $sql );
			if ( 1 === (int) $updated ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LAST_INSERT_ID() is connection-local and must never return a cached counter value.
				$revision = (string) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
				if ( self::is_valid_site_revision( $revision ) ) {
					if ( function_exists( 'wp_cache_delete' ) ) {
						wp_cache_delete( $option_name, 'options' );
					}

					return $revision;
				}
			}

			throw new Api_Exception( 'The local catalog revision counter could not advance safely.', 'site_revision_unavailable' );
		}

		/* Unit-test/nonstandard fallback. Production WordPress provides $wpdb. */
		$current = (string) self::get( self::SITE_REVISION, '0' );
		if ( ! preg_match( '/^0$|^[1-9][0-9]{0,18}$/', $current ) ) {
			throw new Api_Exception( 'The local catalog revision counter is invalid.', 'site_revision_invalid' );
		}

		$revision = self::increment_decimal( $current );
		if ( ! self::is_valid_site_revision( $revision ) ) {
			throw new Api_Exception( 'The local catalog revision counter is exhausted.', 'site_revision_exhausted' );
		}

		self::update( self::SITE_REVISION, $revision );

		return $revision;
	}

	/**
	 * @param mixed $revision Candidate decimal BIGINT revision.
	 * @return bool
	 */
	public static function is_valid_site_revision( $revision ) {
		$revision = (string) $revision;

		return (bool) preg_match( '/^[1-9][0-9]{0,18}$/', $revision ) && ( strlen( $revision ) < 19 || strcmp( $revision, '9223372036854775807' ) <= 0 );
	}

	/**
	 * Returns a bounded, sanitized command acknowledgement list.
	 *
	 * @return array<int, string>
	 */
	public static function acknowledged_command_ids() {
		$stored = self::get( self::ACKNOWLEDGED_COMMAND_IDS, array() );
		$ids    = array();

		foreach ( is_array( $stored ) ? $stored : array() as $id ) {
			$id = (string) $id;
			if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id ) && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return array_slice( $ids, -100 );
	}

	/**
	 * @param string $command_id Signed, allow-listed command ID.
	 * @return void
	 */
	public static function acknowledge_command( $command_id ) {
		$command_id = (string) $command_id;
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $command_id ) ) {
			return;
		}

		$ids = self::acknowledged_command_ids();
		if ( ! in_array( $command_id, $ids, true ) ) {
			$ids[] = $command_id;
		}

		self::update( self::ACKNOWLEDGED_COMMAND_IDS, array_slice( $ids, -100 ) );
	}

	/**
	 * @return int
	 */
	public static function queue_failure_count() {
		return max( 0, min( 1000, (int) self::get( self::QUEUE_FAILURES, 0 ) ) );
	}

	/**
	 * @return void
	 */
	public static function record_queue_failure() {
		self::update( self::QUEUE_FAILURES, min( 1000, self::queue_failure_count() + 1 ) );
	}

	/**
	 * @return void
	 */
	public static function clear_queue_failures() {
		self::update( self::QUEUE_FAILURES, 0 );
	}

	/**
	 * @param string $value Non-negative decimal integer.
	 * @return string
	 */
	private static function increment_decimal( $value ) {
		$digits = str_split( $value );
		$carry  = 1;

		for ( $index = count( $digits ) - 1; $index >= 0 && $carry; $index-- ) {
			$digit          = (int) $digits[ $index ] + $carry;
			$digits[ $index ] = (string) ( $digit % 10 );
			$carry          = $digit > 9 ? 1 : 0;
		}

		if ( $carry ) {
			array_unshift( $digits, '1' );
		}

		return implode( '', $digits );
	}

	/**
	 * Gets a complete option key.
	 *
	 * @param string $key Option suffix.
	 * @return string
	 */
	private static function name( $key ) {
		return self::PREFIX . $key;
	}
}
