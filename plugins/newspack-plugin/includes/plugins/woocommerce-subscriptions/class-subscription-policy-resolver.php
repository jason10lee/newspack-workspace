<?php
/**
 * Subscription Policy Resolver — RSM Layer 2 integration seam.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the pricing-policy stack and effective price for a subscription product.
 *
 * ============================================================================
 * INTEGRATION SEAM (RSM Layer 2)
 * ============================================================================
 * This class is the SINGLE boundary between the Subscription Products UI and the
 * pricing-policy engine. Today it returns deterministic MOCK data so the UX can be
 * evaluated before the policy engine is merged.
 *
 * To wire it to the real policy engine read API:
 *   1. Replace the body of {@see Subscription_Policy_Resolver::get_resolution()}
 *      with a call to the engine, OR hook the
 *      `newspack_subscription_policy_resolution` filter from the engine.
 *   2. Keep the returned array shape IDENTICAL (see the docblock on resolve()).
 *      The DataViews UI consumes only that shape, so no front-end change is needed.
 *
 * Nothing else in the codebase should call the policy engine directly — route every
 * read through resolve() so the swap is a one-file change.
 * ============================================================================
 */
class Subscription_Policy_Resolver {

	/**
	 * Whether the resolver is backed by real engine data.
	 *
	 * Flipped to true once get_resolution() is wired to the engine. The UI surfaces
	 * this so reviewers can tell mock data from live data.
	 *
	 * @var bool
	 */
	const IS_MOCK = true;

	/**
	 * Resolve the policy stack and effective price for a product (and optional cycle context).
	 *
	 * @param int   $product_id The subscription product (or variation) ID.
	 * @param array $context    Optional resolution context. Recognised keys:
	 *                          - base_price (float)  Base recurring price for the cycle.
	 *                          - cycle      (string) Billing period slug, e.g. 'month'.
	 *                          - currency   (string) ISO currency code.
	 *
	 * @return array {
	 *     Resolved pricing for the product.
	 *
	 *     @type bool   $is_mock         Whether this is mock data.
	 *     @type float  $base_price      The unmodified base price.
	 *     @type float  $effective_price The price after the winning policy is applied.
	 *     @type string $currency        ISO currency code.
	 *     @type string $cycle           Billing period slug.
	 *     @type array  $policies        List of applied policies, each: {
	 *         @type string $id               Stable policy id.
	 *         @type string $slug             Machine slug.
	 *         @type string $label            Human label.
	 *         @type string $type             Policy type: promo|season|winback|loyalty.
	 *         @type bool   $is_winning       Whether this policy sets the effective price.
	 *         @type string $adjustment_label Short description of the adjustment.
	 *     }
	 * }
	 */
	public static function resolve( $product_id, $context = [] ) {
		$resolution = self::get_resolution( (int) $product_id, $context );

		/**
		 * Filters the resolved subscription pricing-policy stack for a product.
		 *
		 * The policy engine may hook here instead of replacing get_resolution(). The
		 * returned array must match the shape documented on
		 * Subscription_Policy_Resolver::resolve().
		 *
		 * @param array $resolution The resolved pricing array.
		 * @param int   $product_id The product/variation ID.
		 * @param array $context    The resolution context.
		 */
		return apply_filters( 'newspack_subscription_policy_resolution', $resolution, (int) $product_id, $context );
	}

	/**
	 * Produce the resolution payload.
	 *
	 * MOCK IMPLEMENTATION — replace this body with the policy-engine read call.
	 * The mock is deterministic per product so the table is stable across reloads,
	 * and it deliberately exercises every boundary case the UI must handle:
	 *   - no policies applied (effective price === base price)
	 *   - a single winning policy
	 *   - multiple overlapping policies with one winner
	 *
	 * @param int   $product_id The product/variation ID.
	 * @param array $context    The resolution context.
	 *
	 * @return array Resolution payload (see resolve()).
	 */
	private static function get_resolution( $product_id, $context ) {
		$base_price = isset( $context['base_price'] ) ? (float) $context['base_price'] : 0.0;
		$currency   = isset( $context['currency'] ) ? $context['currency'] : get_woocommerce_currency();
		$cycle      = isset( $context['cycle'] ) ? $context['cycle'] : 'month';

		// Deterministic bucket so each product always resolves the same way in the mock.
		$bucket = $product_id % 4;

		switch ( $bucket ) {
			case 1:
				// Single winning promo.
				$policies = [
					self::policy( 'promo-spring', 'promo', __( 'Spring promo', 'newspack-plugin' ), true, __( '−20% for 3 cycles', 'newspack-plugin' ) ),
				];
				$factor   = 0.8;
				break;
			case 2:
				// Two overlapping policies; winback wins over season.
				$policies = [
					self::policy( 'season-summer', 'season', __( 'Summer rate', 'newspack-plugin' ), false, __( '−10%', 'newspack-plugin' ) ),
					self::policy( 'winback-30', 'winback', __( 'Win-back', 'newspack-plugin' ), true, __( '−30% first cycle', 'newspack-plugin' ) ),
				];
				$factor   = 0.7;
				break;
			case 3:
				// Three overlapping policies; loyalty wins.
				$policies = [
					self::policy( 'promo-flash', 'promo', __( 'Flash sale', 'newspack-plugin' ), false, __( '−15%', 'newspack-plugin' ) ),
					self::policy( 'season-holiday', 'season', __( 'Holiday rate', 'newspack-plugin' ), false, __( '−5%', 'newspack-plugin' ) ),
					self::policy( 'loyalty-2yr', 'loyalty', __( 'Loyalty (2yr+)', 'newspack-plugin' ), true, __( '−25%', 'newspack-plugin' ) ),
				];
				$factor   = 0.75;
				break;
			default:
				// No policies — effective price equals base price.
				$policies = [];
				$factor   = 1.0;
				break;
		}

		$effective_price = round( $base_price * $factor, wc_get_price_decimals() );

		return [
			'is_mock'         => self::IS_MOCK,
			'base_price'      => $base_price,
			'effective_price' => $effective_price,
			'currency'        => $currency,
			'cycle'           => $cycle,
			'policies'        => $policies,
		];
	}

	/**
	 * Build a single policy entry.
	 *
	 * @param string $id               Stable policy id.
	 * @param string $type             Policy type.
	 * @param string $label            Human label.
	 * @param bool   $is_winning       Whether this policy sets the effective price.
	 * @param string $adjustment_label Short description of the adjustment.
	 *
	 * @return array The policy entry.
	 */
	private static function policy( $id, $type, $label, $is_winning, $adjustment_label ) {
		return [
			'id'               => $id,
			'slug'             => $id,
			'label'            => $label,
			'type'             => $type,
			'is_winning'       => (bool) $is_winning,
			'adjustment_label' => $adjustment_label,
		];
	}
}
