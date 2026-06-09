<?php
/**
 * Product IDs scope matcher.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing\Matchers;

use Newspack\Dynamic_Pricing\Scope_Matcher;

defined( 'ABSPATH' ) || exit;

/**
 * Matches when the product ID is in the configured list.
 */
final class Product_Ids_Scope_Matcher implements Scope_Matcher {
	public function id(): string {
		return 'product_ids';
	}

	public function matches( \WC_Product $product, mixed $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		return in_array( (int) $product->get_id(), array_map( 'intval', $value ), true );
	}
}
