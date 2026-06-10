<?php
/**
 * Amount Calculator — shared math for fixed/percent/discount calc types.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

final class Amount_Calculator {
	const FIXED_PRICE      = 'fixed_price';
	const PERCENT_OF_BASE  = 'percent_of_base';
	const DISCOUNT_FIXED   = 'discount_fixed';
	const DISCOUNT_PERCENT = 'discount_percent';

	public static function calculate( string $calc_type, float $value, float $base ): float {
		$decimals = self::price_decimals();
		return match ( $calc_type ) {
			self::FIXED_PRICE      => round( $value, $decimals ),
			self::PERCENT_OF_BASE  => round( $base * ( $value / 100 ), $decimals ),
			self::DISCOUNT_FIXED   => round( max( 0, $base - $value ), $decimals ),
			self::DISCOUNT_PERCENT => round( $base * ( 1 - $value / 100 ), $decimals ),
			default                => 0.00,
		};
	}

	public static function supported_types(): array {
		return [ self::FIXED_PRICE, self::PERCENT_OF_BASE, self::DISCOUNT_FIXED, self::DISCOUNT_PERCENT ];
	}

	/**
	 * Store-configured price decimals (per-currency), with a fallback for
	 * contexts where WooCommerce isn't loaded (tests).
	 */
	public static function price_decimals(): int {
		return function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
	}

	/**
	 * Catalog recurring base price for a product — the single resolver shared by
	 * every surface, so percent calculations use the same basis everywhere.
	 *
	 * Precedence: WCS recurring price for subscription products, falling back to
	 * the regular price when WCS is unavailable or reports a non-positive amount.
	 */
	public static function base_price_for( \WC_Product $product ): float {
		$base_price = 0.0;
		if (
			class_exists( '\WC_Subscriptions_Product' )
			&& in_array( $product->get_type(), [ 'subscription', 'variable-subscription', 'subscription_variation' ], true )
		) {
			$base_price = (float) \WC_Subscriptions_Product::get_price( $product );
		}
		if ( $base_price <= 0 ) {
			$base_price = (float) $product->get_regular_price();
		}
		return $base_price;
	}
}
