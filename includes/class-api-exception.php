<?php
/**
 * Safe API exception type.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Api_Exception extends \RuntimeException {
	/**
	 * Stable, non-sensitive error code suitable for admin diagnostics.
	 *
	 * @var string
	 */
	private $spellexo_error_code;

	/**
	 * Whether the operation may be retried by a bounded queue policy.
	 *
	 * @var bool
	 */
	private $retryable;

	/**
	 * @param string $message Safe generic message.
	 * @param string $error_code Stable error code.
	 * @param bool   $retryable Whether queue retry is allowed.
	 */
	public function __construct( $message, $error_code = 'api_error', $retryable = false ) {
		parent::__construct( $message );
		$this->spellexo_error_code = (string) $error_code;
		$this->retryable           = (bool) $retryable;
	}

	/**
	 * @return string
	 */
	public function error_code() {
		return $this->spellexo_error_code;
	}

	/**
	 * @return bool
	 */
	public function is_retryable() {
		return $this->retryable;
	}
}
