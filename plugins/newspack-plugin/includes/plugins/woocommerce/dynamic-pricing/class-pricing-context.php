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
	 * This purchase creates a subscription (or is a plain product purchase):
	 * the customer is acquiring something they do not already hold.
	 */
	const INTENT_ACQUISITION = 'acquisition';

	/**
	 * Pricing a future cycle of a subscription the customer already holds.
	 */
	const INTENT_RENEWAL = 'renewal';

	/**
	 * @param string             $trigger        Lifecycle event id (e.g., Subscription_Surface::TRIGGER_SCHEDULED_STEP).
	 * @param \WC_Product        $product        Subject product.
	 * @param \WC_Customer|null  $customer       Customer when known.
	 * @param float              $base_price     Catalog regular recurring price; basis for percent calculations.
	 * @param array              $signals        Open-ended signals (e.g., 'completed_cycles').
	 * @param mixed              $target         Surface-native handle (e.g., WC_Subscription); opaque to strategies.
	 * @param string             $intent         INTENT_* — whether this context acquires a new subscription or
	 *                                           reprices an existing one. Matchers/strategies branch on this
	 *                                           instead of comparing surface trigger strings.
	 * @param bool               $persists_price Mirror of the building surface's is_stateful(): when true, an
	 *                                           applied price outlives this resolution, so an engine abstention
	 *                                           keeps the LAST WRITTEN price — not the catalog price. Strategies
	 *                                           must emit (not abstain from) catalog-price-equal decisions here.
	 *
	 * Surfaces MUST set $intent and $persists_price explicitly when building contexts;
	 * the defaults describe a stateless acquisition (the safe fallback).
	 */
	public function __construct(
		public string $trigger,
		public \WC_Product $product,
		public ?\WC_Customer $customer,
		public float $base_price,
		public array $signals,
		public mixed $target,
		public string $intent = self::INTENT_ACQUISITION,
		public bool $persists_price = false
	) {}
}
