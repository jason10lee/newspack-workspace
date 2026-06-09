<?php
/**
 * Scope Matcher contract (product-side filter).
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

interface Scope_Matcher {
	public function id(): string;
	public function matches( \WC_Product $product, mixed $value ): bool;
}
