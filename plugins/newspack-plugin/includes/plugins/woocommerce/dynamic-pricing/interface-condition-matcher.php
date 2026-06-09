<?php
/**
 * Condition Matcher contract (customer/runtime-side filter).
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

interface Condition_Matcher {
	public function id(): string;
	public function matches( Pricing_Context $ctx, mixed $value ): bool;
}
