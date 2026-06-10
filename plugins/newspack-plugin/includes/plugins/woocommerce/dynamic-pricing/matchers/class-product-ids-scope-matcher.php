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
 * Matches when the product ID — or, for variations, the parent product ID —
 * is in the configured list.
 */
final class Product_Ids_Scope_Matcher implements Scope_Matcher {
	public function id(): string {
		return 'product_ids';
	}

	public function matches( \WC_Product $product, mixed $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		$ids = array_map( 'intval', $value );
		if ( in_array( (int) $product->get_id(), $ids, true ) ) {
			return true;
		}
		// Surfaces resolve variations while admins configure parent product ids;
		// fall back to the parent so variable subscriptions match.
		$parent_id = (int) $product->get_parent_id();
		return $parent_id > 0 && in_array( $parent_id, $ids, true );
	}
}
