<?php
/**
 * Minimal, PII-free WooCommerce product serializer.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Product_Serializer {
	const MAX_VARIANTS_PER_PRODUCT = 5000;
	const MAX_ATTRIBUTES_PER_VARIANT = 50;

	/**
	 * Converts a simple or variable WooCommerce product to contract v1.
	 *
	 * @param object $product WC_Product instance.
	 * @return array<string, mixed>
	 * @throws Api_Exception When a supported Woo product is unavailable.
	 */
	public function serialize( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			throw new Api_Exception( 'A WooCommerce product is required for synchronization.', 'invalid_product' );
		}

		$product_id = (int) $product->get_id();
		$type       = (string) $product->get_type();

		if ( $product_id < 1 || ! in_array( $type, array( 'simple', 'variable' ), true ) ) {
			throw new Api_Exception( 'Only simple and variable WooCommerce products are supported.', 'unsupported_product_type' );
		}

		return array(
			'external_id'    => (string) $product_id,
			'type'           => $type,
			'status'         => sanitize_key( (string) $product->get_status() ),
			'title'          => self::text( $product->get_name() ),
			'slug'           => self::text( sanitize_title( (string) $product->get_slug() ), 255 ),
			'sku'            => self::text( $product->get_sku(), 255 ),
			'storefront_url' => self::catalog_url_or_null( $product->get_permalink() ),
			'admin_url'      => self::catalog_url_or_null( get_edit_post_link( $product_id, 'raw' ) ),
			'image_url'      => $this->image_url( $product ),
			/* Informational product timestamp; ordering uses Sync's site_revision. */
			'modified_at'    => gmdate( 'Y-m-d\\TH:i:s\\Z', (int) get_post_modified_time( 'U', true, $product_id ) ),
			'variants'       => $this->variants( $product, $type ),
		);
	}

	/**
	 * Builds stable simple-product or variation entries.
	 *
	 * @param object $product WC_Product instance.
	 * @param string $type Product type.
	 * @return array<int, array<string, mixed>>
	 */
	private function variants( $product, $type ) {
		if ( 'simple' === $type ) {
			return array(
				array(
					'external_id' => 'product:' . (int) $product->get_id(),
					'title'       => self::text( $product->get_name() ),
					'sku'         => self::text( $product->get_sku(), 255 ),
					'status'      => sanitize_key( (string) $product->get_status() ),
					'purchasable' => (bool) $product->is_purchasable(),
					// An empty PHP array encodes as JSON [], but contract v1 requires an object.
					'attributes'  => new \stdClass(),
				),
			);
		}

		$variants = array();
		foreach ( (array) $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! method_exists( $variation, 'is_type' ) || ! $variation->is_type( 'variation' ) ) {
				continue;
			}

			$attributes = self::normalize_variant_attributes( (array) $variation->get_attributes() );

			if ( count( $variants ) >= self::MAX_VARIANTS_PER_PRODUCT ) {
				throw new Api_Exception( 'This product has more than 5,000 variations and cannot be synchronized by connector contract v1.', 'catalog_variant_limit_exceeded' );
			}

			$variants[] = array(
				'external_id' => 'variation:' . (int) $variation->get_id(),
				'title'       => self::text( $variation->get_name() ),
				'sku'         => self::text( $variation->get_sku(), 255 ),
				'status'      => sanitize_key( (string) $variation->get_status() ),
				'purchasable' => (bool) $variation->is_purchasable(),
				'attributes'  => $attributes,
			);
		}

		return $variants;
	}

	/**
	 * Returns a product image URL when one is publicly available.
	 *
	 * @param object $product WC_Product instance.
	 * @return string|null
	 */
	private function image_url( $product ) {
		$image_id = (int) $product->get_image_id();
		$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : false;

		return is_string( $image ) ? self::catalog_url_or_null( $image ) : null;
	}

	/**
	 * Catalog URLs are accepted by the backend only when they are absolute HTTPS
	 * URLs on this authenticated site's canonical origin. A media CDN is useful
	 * to the browser but is deliberately omitted from connector sync rather than
	 * accepted as a foreign origin.
	 *
	 * @param mixed       $url Candidate URL.
	 * @param string|null $canonical_origin Optional origin override for tests.
	 * @return string|null
	 */
	public static function catalog_url_or_null( $url, $canonical_origin = null ) {
		$url             = esc_url_raw( (string) $url );
		$parts           = wp_parse_url( $url );
		$canonical_origin = null === $canonical_origin ? Url::canonical_origin() : Url::canonical_origin( $canonical_origin );

		if ( '' === $url || ! is_array( $parts ) || 'https' !== strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || '' === $canonical_origin || ! hash_equals( $canonical_origin, Url::canonical_origin( $url ) ) ) {
			return null;
		}

		return $url;
	}

	/**
	 * Normalizes a variation's bounded catalog attributes. The connector schema
	 * allows at most 50 entries, so enforcement lives at serialization time as
	 * well as in the backend contract validator.
	 *
	 * @param array<mixed, mixed> $attributes Raw WooCommerce variation attributes.
	 * @return array<string, string>|\stdClass
	 */
	public static function normalize_variant_attributes( $attributes ) {
		$normalized = array();
		foreach ( (array) $attributes as $attribute_name => $attribute_value ) {
			$attribute_key = self::text( sanitize_key( $attribute_name ), 128 );
			if ( '' === $attribute_key || in_array( $attribute_key, array( '__proto__', 'prototype', 'constructor' ), true ) ) {
				continue;
			}

			$normalized[ $attribute_key ] = self::text( $attribute_value );
			if ( count( $normalized ) >= self::MAX_ATTRIBUTES_PER_VARIANT ) {
				break;
			}
		}

		// Preserve the JSON object shape required by the connector contract when
		// WooCommerce reports no attributes for a variation.
		return empty( $normalized ) ? new \stdClass() : $normalized;
	}

	/**
	 * Removes markup and limits an outbound catalog string.
	 *
	 * @param mixed $value Value.
	 * @param int   $limit Maximum UTF-8 characters.
	 * @return string
	 */
	private static function text( $value, $limit = 500 ) {
		$value = wp_strip_all_tags( (string) $value );

		return self::truncate_utf8( $value, $limit );
	}

	/**
	 * Truncates by Unicode code point without depending on mbstring. WordPress
	 * strings are UTF-8; malformed input is omitted rather than emitting a
	 * partial multi-byte sequence to the signed connector payload.
	 *
	 * @param mixed $value Input text.
	 * @param int   $limit Maximum Unicode code points.
	 * @return string
	 */
	public static function truncate_utf8( $value, $limit = 500 ) {
		$value = (string) $value;
		$limit = max( 1, (int) $limit );
		$chars = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $chars ) ) {
			return '';
		}

		return implode( '', array_slice( $chars, 0, $limit ) );
	}
}
