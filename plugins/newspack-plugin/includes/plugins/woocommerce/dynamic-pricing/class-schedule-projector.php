<?php
/**
 * Schedule Projector — walks the engine across future cycles and emits the
 * priced sequence a subscriber will actually experience.
 *
 * Engine-side, not display-side: rather than reimplementing strategy step
 * logic, it resolves `Pricing_Engine::resolve()` once per cycle with synthetic
 * acquisition contexts and merges consecutive equal amounts into segments.
 * Composition (`min` / `priority_exclusive` across multiple rules) is therefore
 * respected per cycle for free. A cycle with no decision means the regular
 * price applies — the merge treats that as an amount like any other.
 *
 * Output shape: ordered segments `{from_cycle:int, amount:float}`; the last
 * segment is open-ended (the stabilized price). Cycle 1 is the purchase;
 * cycle 2 is the first renewal.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

final class Schedule_Projector {
	/**
	 * Beyond this cycle the projection assumes stability. Covers every realistic
	 * schedule (publishers cite ≤ 3 steps; 24 monthly cycles = two years).
	 */
	const HORIZON = 24;

	/**
	 * Project the price sequence for a cart item being purchased now.
	 *
	 * @param array $cart_item Cart item array (caller checks eligibility).
	 * @return array<int, array{from_cycle: int, amount: float}> Merged segments; empty if no product.
	 */
	public static function project_for_cart_item( array $cart_item ): array {
		if ( ! isset( $cart_item['data'] ) || ! ( $cart_item['data'] instanceof \WC_Product ) ) {
			return [];
		}
		$product    = $cart_item['data'];
		$base_price = Amount_Calculator::base_price_for( $product );

		$customer = null;
		if ( function_exists( 'WC' ) && WC() && WC()->customer ) {
			$customer = WC()->customer;
		}

		$engine   = Pricing_Engine::instance();
		$segments = [];
		$previous = null;

		for ( $cycle = 1; $cycle <= self::HORIZON; $cycle++ ) {
			$ctx = new Pricing_Context(
				WooProduct_Surface::TRIGGER_CART,
				$product,
				$customer,
				$base_price,
				[ 'completed_cycles' => $cycle ],
				$cart_item,
				Pricing_Context::INTENT_ACQUISITION,
				false
			);

			$decision = $engine->resolve( $ctx );
			$amount   = round( $decision ? $decision->amount : $base_price, Amount_Calculator::price_decimals() );

			if ( null === $previous || abs( $amount - $previous ) >= 0.01 ) {
				$segments[] = [
					'from_cycle' => $cycle,
					'amount'     => $amount,
				];
				$previous = $amount;
			}
		}

		return $segments;
	}

	/**
	 * The renewal-only view of a projection: segments re-based at cycle 2.
	 * A segment that starts at cycle 1 spans the early renewals too (e.g.
	 * "$6 from cycle 1, $10 from cycle 4" means renewals 1–2 are $6), so the
	 * first renewal segment carries whatever amount is in effect at cycle 2.
	 *
	 * @param array $segments Output of project_for_cart_item().
	 * @return array<int, array{from_cycle: int, amount: float}> Starting at from_cycle 2.
	 */
	public static function renewal_segments( array $segments ): array {
		$result = [];
		foreach ( $segments as $segment ) {
			if ( $segment['from_cycle'] <= 2 ) {
				// Last segment at-or-before cycle 2 owns the first renewal.
				$result = [
					[
						'from_cycle' => 2,
						'amount'     => $segment['amount'],
					],
				];
			} else {
				$result[] = $segment;
			}
		}
		return $result;
	}

	/**
	 * Whether the projection holds price changes BEYOND the next renewal —
	 * i.e. information the single "Recurring total" number cannot carry.
	 *
	 * @param array $segments Output of project_for_cart_item().
	 */
	public static function has_undisclosed_changes( array $segments ): bool {
		// One renewal segment = the recurring total already tells the whole story.
		return count( self::renewal_segments( $segments ) ) > 1;
	}
}
