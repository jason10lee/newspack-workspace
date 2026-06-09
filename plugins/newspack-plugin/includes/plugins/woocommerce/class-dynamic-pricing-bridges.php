<?php
/**
 * Newspack-specific bridges into the Dynamic Pricing engine.
 *
 * The engine itself has no Newspack imports — these filter callbacks add
 * Newspack-specific exclusions on top of the engine's WC/WCS-native checks.
 * See spec §16.1 for the upstream-portability rationale.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack bridges for the Dynamic Pricing engine.
 *
 * Registers callbacks against the `newspack_dynamic_pricing_is_excluded`
 * filter to opt specific products / subscriptions out of dynamic pricing:
 *
 *  - Donation products (via Newspack\Donations::is_donation_product).
 *  - Group subscriptions (via Newspack\Group_Subscription::is_group_subscription).
 *  - Subscriptions explicitly paused by Newspack (via the
 *    `_newspack_dynamic_pricing_paused` meta key).
 */
final class Dynamic_Pricing_Bridges {
	/**
	 * Subscription meta key used to opt a single subscription out of
	 * dynamic pricing (e.g. after a customer-service override).
	 */
	const PAUSED_META_KEY = '_newspack_dynamic_pricing_paused';

	/**
	 * Register all bridge filter callbacks.
	 */
	public static function init(): void {
		add_filter( 'newspack_dynamic_pricing_is_excluded', [ __CLASS__, 'exclude_donations' ], 10, 3 );
		add_filter( 'newspack_dynamic_pricing_is_excluded', [ __CLASS__, 'exclude_group_subscriptions' ], 10, 3 );
		add_filter( 'newspack_dynamic_pricing_is_excluded', [ __CLASS__, 'exclude_paused_subscriptions' ], 10, 3 );
	}

	/**
	 * Exclude donation products from dynamic pricing.
	 *
	 * @param bool        $excluded Whether the engine has already excluded this context.
	 * @param \WC_Product $product  Product being priced.
	 * @param mixed       $target   Optional target (e.g. a WC_Subscription).
	 */
	public static function exclude_donations( bool $excluded, \WC_Product $product, mixed $target ): bool {
		if ( $excluded ) {
			return true;
		}
		if ( class_exists( '\Newspack\Donations' ) && Donations::is_donation_product( $product->get_id() ) ) {
			return true;
		}
		return $excluded;
	}

	/**
	 * Exclude group subscriptions from dynamic pricing.
	 *
	 * @param bool        $excluded Whether the engine has already excluded this context.
	 * @param \WC_Product $product  Product being priced.
	 * @param mixed       $target   Optional target (e.g. a WC_Subscription).
	 */
	public static function exclude_group_subscriptions( bool $excluded, \WC_Product $product, mixed $target ): bool {
		if ( $excluded ) {
			return true;
		}
		if (
			$target instanceof \WC_Subscription
			&& class_exists( '\Newspack\Group_Subscription' )
			&& method_exists( '\Newspack\Group_Subscription', 'is_group_subscription' )
			&& Group_Subscription::is_group_subscription( $target )
		) {
			return true;
		}
		return $excluded;
	}

	/**
	 * Exclude subscriptions paused via the Newspack-specific meta key.
	 *
	 * @param bool        $excluded Whether the engine has already excluded this context.
	 * @param \WC_Product $product  Product being priced.
	 * @param mixed       $target   Optional target (e.g. a WC_Subscription).
	 */
	public static function exclude_paused_subscriptions( bool $excluded, \WC_Product $product, mixed $target ): bool {
		if ( $excluded ) {
			return true;
		}
		if ( $target instanceof \WC_Subscription && (bool) $target->get_meta( self::PAUSED_META_KEY ) ) {
			return true;
		}
		return $excluded;
	}
}

Dynamic_Pricing_Bridges::init();
