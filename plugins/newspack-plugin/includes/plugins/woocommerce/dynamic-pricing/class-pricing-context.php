<?php
/**
 * Pricing Context value object.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Surface-agnostic input passed to pricing strategies.
 */
final class Pricing_Context {
	/**
	 * @param string             $trigger     Lifecycle event id (e.g., Subscription_Surface::TRIGGER_SCHEDULED_STEP).
	 * @param \WC_Product        $product     Subject product.
	 * @param \WC_Customer|null  $customer    Customer when known.
	 * @param float              $base_price  Catalog regular recurring price; basis for percent calculations.
	 * @param array              $signals     Open-ended signals (e.g., 'completed_cycles').
	 * @param mixed              $target      Surface-native handle (e.g., WC_Subscription); opaque to strategies.
	 */
	public function __construct(
		public string $trigger,
		public \WC_Product $product,
		public ?\WC_Customer $customer,
		public float $base_price,
		public array $signals,
		public mixed $target
	) {}
}
