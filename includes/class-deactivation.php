<?php
/**
 * Plugin deactivation lifecycle.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Deactivation {
	/**
	 * Stops local work while intentionally retaining the connection for a safe
	 * reactivation. Remote models, accounts, and subscriptions are untouched.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Sync::unschedule_all();
		Heartbeat::unschedule_all();
		Commerce_Analytics::unschedule_all();
	}
}
