<?php
/**
 * Canonical URL helpers.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Url {
	/**
	 * Normalizes a site URL while preserving a WordPress subdirectory.
	 *
	 * @param string|null $url URL to normalize.
	 * @return string
	 */
	public static function canonical_site_url( $url = null ) {
		$url   = null === $url ? home_url( '/' ) : $url;
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = self::normalize_host( $parts['host'] );
		$port   = self::normalized_port( $scheme, isset( $parts['port'] ) ? (int) $parts['port'] : 0 );
		$path   = isset( $parts['path'] ) ? '/' . trim( $parts['path'], '/' ) : '/';

		if ( '/' === $path || '/.' === $path ) {
			$path = '/';
		} else {
			$path = untrailingslashit( $path );
		}

		return $scheme . '://' . $host . $port . $path;
	}

	/**
	 * Gets an origin (scheme, host, optional non-default port) from a site URL.
	 *
	 * @param string|null $url URL to normalize.
	 * @return string
	 */
	public static function canonical_origin( $url = null ) {
		$site  = self::canonical_site_url( $url );
		$parts = wp_parse_url( $site );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		return strtolower( $parts['scheme'] ) . '://' . self::normalize_host( $parts['host'] ) . self::normalized_port(
			strtolower( $parts['scheme'] ),
			isset( $parts['port'] ) ? (int) $parts['port'] : 0
		);
	}

	/**
	 * Checks that an HTTPS URL has an allow-listed hostname.
	 *
	 * @param string   $url URL to validate.
	 * @param string[] $allowed_hosts Allowed hostnames.
	 * @return bool
	 */
	public static function is_allowed_https_url( $url, $allowed_hosts ) {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}

		$host = self::normalize_host( $parts['host'] );

		foreach ( $allowed_hosts as $allowed_host ) {
			if ( hash_equals( self::normalize_host( $allowed_host ), $host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalizes a hostname without changing its identity.
	 *
	 * @param string $host Hostname.
	 * @return string
	 */
	private static function normalize_host( $host ) {
		$host = strtolower( trim( $host, '[]' ) );

		if ( function_exists( 'idn_to_ascii' ) && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$idn_host = idn_to_ascii( $host );
			if ( false !== $idn_host ) {
				$host = $idn_host;
			}
		}

		return $host;
	}

	/**
	 * Includes only a non-default port.
	 *
	 * @param string $scheme URL scheme.
	 * @param int    $port Port.
	 * @return string
	 */
	private static function normalized_port( $scheme, $port ) {
		if ( 0 === $port || ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			return '';
		}

		return ':' . $port;
	}
}
