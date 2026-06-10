<?php
/**
 * Category scope matcher.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing\Matchers;

use Newspack\Dynamic_Pricing\Scope_Matcher;

defined( 'ABSPATH' ) || exit;

/**
 * Matches when the product belongs to at least one of the configured product_cat term ids.
 */
final class Category_Scope_Matcher implements Scope_Matcher {
	public function id(): string {
		return 'category';
	}

	public function matches( \WC_Product $product, mixed $value ): bool {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return false;
		}
		// product_cat terms are assigned to the parent product, never to variations —
		// look up the parent when the surface hands us a variation.
		$term_lookup_id = (int) ( $product->get_parent_id() ?: $product->get_id() );
		$product_terms  = wc_get_product_term_ids( $term_lookup_id, 'product_cat' );
		return ! empty( array_intersect( array_map( 'intval', $value ), $product_terms ) );
	}
}
