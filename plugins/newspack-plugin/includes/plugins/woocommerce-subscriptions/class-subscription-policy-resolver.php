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
 * standalone pricing-rule engine (woocommerce-dynamic-pricing). get_resolution()
 * reads the live engine: it composes all active rules over the product's purchase
 * cycle for the effective price, and lists the matching rules with the winner
 * flagged. When the engine plugin is inactive it reports the base price with no
 * rules.
 *
 * The returned array shape is the contract the DataViews UI consumes; keep it
 * stable. Nothing else should call the engine for this read directly — route it
 * through resolve() (and the `newspack_subscription_policy_resolution` filter).
 * ============================================================================
 */
class Subscription_Policy_Resolver {

	/**
	 * Whether the resolver returns mock data. Now that get_resolution() reads the
	 * live engine, this is always false; the UI's mock-data notice never shows.
	 *
	 * @var bool
	 */
	const IS_MOCK = false;

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
	 * Produce the resolution payload by reading the live pricing-rule engine.
	 *
	 * Composes all active rules over the product's purchase (acquisition) cycle for
	 * the effective price, and lists the matching rules with the winner flagged —
	 * the same engine the storefront uses, so the table matches what buyers see.
	 * Without the engine (plugin inactive), an invalid product, or an
	 * engine-excluded product (e.g. donations), it reports the base price and no
	 * rules.
	 *
	 * @param int   $product_id The product/variation ID.
	 * @param array $context    The resolution context.
	 *
	 * @return array Resolution payload (see resolve()).
	 */
	private static function get_resolution( $product_id, $context ) {
		$currency = isset( $context['currency'] ) ? $context['currency'] : get_woocommerce_currency();
		$cycle    = isset( $context['cycle'] ) ? $context['cycle'] : 'month';
		$base     = isset( $context['base_price'] ) ? (float) $context['base_price'] : 0.0;

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;
		$engine  = class_exists( '\Automattic\WooCommerce\DynamicPricing\Pricing_Engine' )
			? \Automattic\WooCommerce\DynamicPricing\Pricing_Engine::instance()
			: null;

		// No engine / invalid product / engine-excluded product (e.g. donations the
		// engine never prices) → base price, no rules, rather than a fabricated one.
		if ( ! $engine || ! $product instanceof \WC_Product || $engine->is_excluded( $product ) ) {
			return self::build( $base, $base, $currency, $cycle, [] );
		}

		// Acquisition (purchase) cycle, composed across all active rules — the same
		// basis as the rule editor's impact-preview "resulting price".
		$ctx = new \Automattic\WooCommerce\DynamicPricing\Pricing_Context(
			'subscription_products',
			$product,
			null,
			$base,
			[ 'completed_cycles' => 1 ],
			null,
			\Automattic\WooCommerce\DynamicPricing\Pricing_Context::INTENT_ACQUISITION,
			false
		);

		$decision   = $engine->resolve( $ctx );
		$effective  = $decision ? (float) $decision->amount : $base;
		$winning_id = ( $decision && $decision->rule_id ) ? (string) $decision->rule_id : '';

		$repository = $engine->repository();
		$matching   = $repository ? $repository->for_context( $ctx ) : [];

		$rules = [];
		foreach ( $matching as $rule ) {
			$rule_id = (string) $rule->id;
			$rules[] = self::policy(
				$rule_id,
				(string) $rule->strategy_id,
				get_the_title( (int) $rule_id ),
				'' !== $winning_id && $rule_id === $winning_id,
				self::strategy_label( (string) $rule->strategy_id )
			);
		}

		return self::build( $base, $effective, $currency, $cycle, $rules );
	}

	/**
	 * Assemble a resolution payload in the shape resolve() documents.
	 *
	 * @param float  $base_price      The unmodified base price.
	 * @param float  $effective_price The composed price after rules.
	 * @param string $currency        ISO currency code.
	 * @param string $cycle           Billing period slug.
	 * @param array  $rules           Applied-rule entries (see policy()).
	 *
	 * @return array The resolution payload.
	 */
	private static function build( $base_price, $effective_price, $currency, $cycle, $rules ) {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return [
			'is_mock'         => self::IS_MOCK,
			'base_price'      => $base_price,
			'effective_price' => round( (float) $effective_price, $decimals ),
			'currency'        => $currency,
			'cycle'           => $cycle,
			'policies'        => $rules,
		];
	}

	/**
	 * Short, human pricing-model label for a rule's strategy (shown in the chip tooltip).
	 *
	 * @param string $strategy_id The rule's strategy id.
	 *
	 * @return string Human label.
	 */
	private static function strategy_label( $strategy_id ) {
		switch ( $strategy_id ) {
			case 'simple_price':
				return __( 'Flat adjustment', 'newspack-plugin' );
			case 'stepped_by_cycle':
				return __( 'Price schedule', 'newspack-plugin' );
			default:
				return (string) $strategy_id;
		}
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
