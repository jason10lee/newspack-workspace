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
		return match ( $calc_type ) {
			self::FIXED_PRICE      => round( $value, 2 ),
			self::PERCENT_OF_BASE  => round( $base * ( $value / 100 ), 2 ),
			self::DISCOUNT_FIXED   => round( max( 0, $base - $value ), 2 ),
			self::DISCOUNT_PERCENT => round( $base * ( 1 - $value / 100 ), 2 ),
			default                => 0.00,
		};
	}

	public static function supported_types(): array {
		return [ self::FIXED_PRICE, self::PERCENT_OF_BASE, self::DISCOUNT_FIXED, self::DISCOUNT_PERCENT ];
	}
}
