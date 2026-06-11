<?php
/**
 * Simple price strategy — one calc applied uniformly, optionally limited to the
 * first N cycles (the purchase + N−1 renewals).
 *
 * The basic counterpart to Stepped_By_Cycle_Strategy: instead of a schedule of
 * steps, a single `calc_type` + `value` pair prices every cycle. The optional
 * `cycles_limit` bounds the adjustment to the first N cycles (0 = unlimited,
 * the default) — e.g. "set to 80% of regular for the first 3 cycles" =
 * 20% off the purchase and the next two renewals.
 *
 * Lives in the foundation layer: its only signal dependency is
 * `completed_cycles`, which every current surface provides; nothing here
 * requires WooCommerce Subscriptions.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing\Strategies;

use Newspack\Dynamic_Pricing\Amount_Calculator;
use Newspack\Dynamic_Pricing\Price_Decision;
use Newspack\Dynamic_Pricing\Pricing_Context;
use Newspack\Dynamic_Pricing\Pricing_Strategy;

defined( 'ABSPATH' ) || exit;

final class Simple_Price_Strategy implements Pricing_Strategy {
	public function id(): string {
		return 'simple_price';
	}

	/**
	 * Declared constraints for `params`. Enforced inline in decide() — the
	 * strategy is the single validation layer regardless of author path.
	 */
	public function config_schema(): array {
		return [
			'calc_type'      => [
				'type'     => 'enum',
				'options'  => Amount_Calculator::supported_types(),
				'required' => true,
			],
			'value'        => [ 'type' => 'number', 'min' => 0, 'required' => true ],
			'label'        => [ 'type' => 'string', 'required' => false ],
			'cycles_limit' => [
				'type'     => 'int',
				'min'      => 0,
				'required' => false,
				'default'  => 0, // 0 = unlimited.
			],
		];
	}

	public function applies_to( Pricing_Context $ctx, array $params ): bool {
		// Same contract as the stepped strategy: any surface that can say which
		// cycle it is pricing can be adjusted.
		return isset( $ctx->signals['completed_cycles'] );
	}

	public function decide( Pricing_Context $ctx, array $params ): ?Price_Decision {
		$calc_type = (string) ( $params['calc_type'] ?? '' );
		$value     = (float) ( $params['value'] ?? -1 );
		if ( $value < 0 || ! in_array( $calc_type, Amount_Calculator::supported_types(), true ) ) {
			return null;
		}

		$label = (string) ( $params['label'] ?? '' );
		$limit = max( 0, (int) ( $params['cycles_limit'] ?? 0 ) );
		$cycle = (int) ( $ctx->signals['completed_cycles'] ?? 0 );

		// Beyond the limited window the adjustment ends. A price-persisting
		// surface holds the last written amount, so the catalog price MUST be
		// emitted as a restore decision — abstaining would freeze the adjusted
		// price forever. Stateless surfaces get catalog pricing by abstention.
		if ( $limit > 0 && $cycle > $limit ) {
			if ( ! $ctx->persists_price || $ctx->base_price <= 0 ) {
				return null;
			}
			return new Price_Decision(
				round( $ctx->base_price, Amount_Calculator::price_decimals() ),
				Price_Decision::DURABLE,
				sprintf( 'restore_base_after_%d_cycles', $limit ),
				$label,
				$this->id(),
				$cycle
			);
		}

		$amount = Amount_Calculator::calculate( $calc_type, $value, $ctx->base_price );

		// Same persistence-aware abstain as the stepped strategy: on a stateless
		// surface a base-equal amount is a no-op; on a persisting surface it must
		// be emitted (the surface's idempotency guard absorbs true no-ops).
		if ( ! $ctx->persists_price && abs( $amount - $ctx->base_price ) < 0.01 ) {
			return null;
		}

		return new Price_Decision(
			$amount,
			Price_Decision::DURABLE,
			sprintf( 'simple_%s', $calc_type ),
			$label,
			$this->id(),
			$cycle
		);
	}
}
