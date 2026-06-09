<?php
/**
 * All Subscriptions scope matcher.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing\Matchers;

use Newspack\Dynamic_Pricing\Scope_Matcher;

defined( 'ABSPATH' ) || exit;

/**
 * Matches any WooCommerce Subscriptions product (simple or variable).
 */
final class All_Subscriptions_Scope_Matcher implements Scope_Matcher {
	public function id(): string {
		return 'all_subscriptions';
	}

	public function matches( \WC_Product $product, mixed $value ): bool {
		return in_array( $product->get_type(), [ 'subscription', 'variable-subscription' ], true );
	}
}
