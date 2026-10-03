<?php
/**
 * Conservative uninstall lifecycle.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Uninstall {
	/**
	 * Best-effort remote revocation followed by local data cleanup.
	 *
	 * Remote models and billing are intentionally retained. A network failure does
	 * not stop local cleanup; the hosted heartbeat/revocation policy handles an
	 * unreachable site conservatively.
	 *
	 * @return void
	 */
	public static function run() {
		$connection = Options::connection();
		$secret     = Secret_Store::get();

		if ( null !== $secret && '' !== $connection['store_id'] ) {
			try {
				$client = new Api_Client();
				$client->request(
					'POST',
					'/v1/integrations/wordpress/disconnect',
					array(
						'schema_version' => 1,
						'reason'         => 'plugin_uninstall',
					),
					array(
						'idempotency_key' => Signature::idempotency_key( 'uninstall', array( $connection['store_id'], Options::get( Options::CREDENTIAL_VERSION, 1 ) ), $secret ),
						'max_attempts'    => 1,
						'timeout'         => 3,
					)
				);
			} catch ( Api_Exception $exception ) {
				// No secret, token, request body, or remote response is logged on uninstall.
			}
		}

		Sync::unschedule_all();
		Heartbeat::unschedule_all();
		Commerce_Analytics::unschedule_all();
		Commerce_Analytics::purge_all_events();
		Secret_Store::delete_named( 'pairing_verifier' );
		Secret_Store::delete_named( 'pairing_poll_token' );
		Options::delete( Options::INSTALLATION_UID );
		Options::delete( Options::INTEGRATION_SECRET );
		Options::delete( Options::CREDENTIAL_VERSION );
		Options::delete( Options::CONNECTION );
		Options::delete( Options::SCHEMA_VERSION );
		Options::delete( Options::LAST_SYNC );
		Options::delete( Options::LAST_SYNC_ATTEMPT );
		Options::delete( Options::LAST_ERROR );
		Options::delete( Options::SYNC_RUN );
		Options::delete( Options::SYNC_START_LOCK );
		Options::delete( Options::SYNC_PAGE_LOCK );
		Options::delete( Options::HEARTBEAT_LOCK );
		Options::delete( Options::SITE_REVISION );
		Options::delete( Options::QUEUE_FAILURES );
		Options::delete( Options::ACKNOWLEDGED_COMMAND_IDS );
		Options::delete( Options::COMMERCE_EVENT_KEYS );
		Options::delete( Options::AUDIT_LOG );
		Options::delete( 'pairing_request_id' );
		delete_transient( 'spellexo_woocommerce_activation_notice' );
	}
}
