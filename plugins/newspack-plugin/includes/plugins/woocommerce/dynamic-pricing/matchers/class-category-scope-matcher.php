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
		$product_terms = wc_get_product_term_ids( (int) $product->get_id(), 'product_cat' );
		return ! empty( array_intersect( array_map( 'intval', $value ), $product_terms ) );
	}
}
