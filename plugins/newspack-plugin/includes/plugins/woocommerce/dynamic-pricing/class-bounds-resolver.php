<?php
/**
 * Cascading bounds resolver: product -> category (widest envelope) -> site default.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves floor/ceiling price bounds for a product using a cascading lookup:
 * product meta, then the widest envelope across category term meta, then site option.
 */
final class Bounds_Resolver {
	const PRODUCT_FLOOR_META   = '_dynamic_pricing_floor';
	const PRODUCT_CEILING_META = '_dynamic_pricing_ceiling';
	const SITE_FLOOR_OPTION    = 'newspack_dynamic_pricing_default_floor';
	const SITE_CEILING_OPTION  = 'newspack_dynamic_pricing_default_ceiling';

	/**
	 * Resolve floor/ceiling bounds for a product.
	 *
	 * @param \WC_Product $product Product to resolve bounds for.
	 * @return array{0: float, 1: float} [floor, ceiling]
	 */
	public function for_product( \WC_Product $product ): array {
		$product_id = (int) $product->get_id();
		$floor      = $this->meta_or_null( $product_id, self::PRODUCT_FLOOR_META );
		$ceiling    = $this->meta_or_null( $product_id, self::PRODUCT_CEILING_META );

		if ( null === $floor || null === $ceiling ) {
			[ $cat_floor, $cat_ceiling ] = $this->category_bounds( $product_id );
			$floor                     ??= $cat_floor;
			$ceiling                   ??= $cat_ceiling;
		}

		$floor   ??= (float) get_option( self::SITE_FLOOR_OPTION, 0 );
		$ceiling ??= (float) get_option( self::SITE_CEILING_OPTION, PHP_FLOAT_MAX );

		return [ (float) $floor, (float) $ceiling ];
	}

	/**
	 * Read a numeric post meta value or null if the meta is absent / empty.
	 *
	 * @param int    $product_id Product post id.
	 * @param string $key        Meta key.
	 */
	private function meta_or_null( int $product_id, string $key ): ?float {
		$value = get_post_meta( $product_id, $key, true );
		return '' === $value ? null : (float) $value;
	}

	/**
	 * Multi-category resolution: widest envelope (lowest floor, highest ceiling).
	 *
	 * @param int $product_id Product post id.
	 * @return array{0: ?float, 1: ?float}
	 */
	private function category_bounds( int $product_id ): array {
		$term_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
		$floor    = null;
		$ceiling  = null;
		foreach ( $term_ids as $term_id ) {
			$tf = get_term_meta( $term_id, self::PRODUCT_FLOOR_META, true );
			$tc = get_term_meta( $term_id, self::PRODUCT_CEILING_META, true );
			if ( '' !== $tf && ( null === $floor || (float) $tf < $floor ) ) {
				$floor = (float) $tf;
			}
			if ( '' !== $tc && ( null === $ceiling || (float) $tc > $ceiling ) ) {
				$ceiling = (float) $tc;
			}
		}
		return [ $floor, $ceiling ];
	}
}
