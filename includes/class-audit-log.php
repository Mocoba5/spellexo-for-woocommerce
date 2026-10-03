<?php
/**
 * Minimal local audit trail without PII, secrets, tokens, or request payloads.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Audit_Log {
	const MAX_ENTRIES = 50;

	/**
	 * Records a safe state transition only.
	 *
	 * @param string $event Stable event code.
	 * @return void
	 */
	public static function record( $event ) {
		$events = array(
			'pairing_started',
			'pairing_connected',
			'catalog_sync_queued',
			'heartbeat_reconciliation_requested',
			'disconnected',
		);

		if ( ! in_array( $event, $events, true ) ) {
			return;
		}

		$entries = Options::get( Options::AUDIT_LOG, array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}

		$entries[] = array(
			'event' => $event,
			'at'    => time(),
		);
		$entries = array_slice( $entries, - self::MAX_ENTRIES );

		if ( null === Options::get( Options::AUDIT_LOG, null ) ) {
			Options::add( Options::AUDIT_LOG, $entries );
			return;
		}

		Options::update( Options::AUDIT_LOG, $entries );
	}
}
