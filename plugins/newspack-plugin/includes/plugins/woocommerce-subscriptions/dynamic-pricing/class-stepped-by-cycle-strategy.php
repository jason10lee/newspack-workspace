<?php
/**
 * Stepped by cycle pricing strategy.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing\Subscriptions;

use Newspack\Dynamic_Pricing\Amount_Calculator;
use Newspack\Dynamic_Pricing\Price_Decision;
use Newspack\Dynamic_Pricing\Pricing_Context;
use Newspack\Dynamic_Pricing\Pricing_Strategy;

defined( 'ABSPATH' ) || exit;

final class Stepped_By_Cycle_Strategy implements Pricing_Strategy {
	public function id(): string {
		return 'stepped_by_cycle';
	}

	/**
	 * Declared constraints for `params`. Enforced by normalized_steps() — the
	 * strategy is the single validation layer, so policies authored via the admin
	 * UI, WP-CLI, or raw meta all get the same treatment at decision time.
	 */
	public function config_schema(): array {
		return [
			'steps' => [
				'type'     => 'list',
				'required' => true,
				'of'       => [
					'at'        => [ 'type' => 'int', 'min' => 1, 'required' => true ],
					'calc_type' => [
						'type'     => 'enum',
						'options'  => Amount_Calculator::supported_types(),
						'required' => true,
					],
					'value'     => [ 'type' => 'number', 'min' => 0, 'required' => true ],
					'label'     => [ 'type' => 'string', 'required' => true ],
				],
			],
		];
	}

	public function applies_to( Pricing_Context $ctx, array $params ): bool {
		// The strategy's real dependency is the cycle signal, not a list of known
		// surfaces: any surface that can say which cycle it is pricing can be stepped.
		return isset( $ctx->signals['completed_cycles'] );
	}

	public function decide( Pricing_Context $ctx, array $params ): ?Price_Decision {
		$cycle = (int) ( $ctx->signals['completed_cycles'] ?? 0 );
		$step  = null;
		foreach ( $this->normalized_steps( $params ) as $candidate ) {
			// Highest `at` ≤ cycle wins — independent of authored order.
			if ( $cycle >= $candidate['at'] && ( null === $step || $candidate['at'] > $step['at'] ) ) {
				$step = $candidate;
			}
		}
		if ( ! $step ) {
			return null;
		}

		$amount = Amount_Calculator::calculate(
			(string) $step['calc_type'],
			(float) $step['value'],
			$ctx->base_price
		);
		// On a stateless surface, abstaining IS the catalog price, so a step that
		// resolves to base is a no-op and abstaining avoids pointless publicity.
		// On a price-persisting surface the last written amount sticks: a restore
		// step (amount == base) MUST be emitted so the surface writes the price
		// back up — Subscription_Surface::apply() short-circuits true no-ops.
		if ( ! $ctx->persists_price && abs( $amount - $ctx->base_price ) < 0.01 ) {
			return null;
		}

		return new Price_Decision(
			$amount,
			Price_Decision::DURABLE,
			sprintf( 'step_at_%d_%s', (int) $step['at'], (string) $step['calc_type'] ),
			(string) $step['label'],
			$this->id(),
			$cycle
		);
	}

	/**
	 * Enforce config_schema() on raw params: drop malformed steps (missing/invalid
	 * `at`, unsupported calc_type, negative value) and coerce field types, so the
	 * decision logic only ever sees well-formed steps regardless of author path.
	 *
	 * @return array<int, array{at: int, calc_type: string, value: float, label: string}>
	 */
	private function normalized_steps( array $params ): array {
		$raw   = is_array( $params['steps'] ?? null ) ? $params['steps'] : [];
		$steps = [];
		foreach ( $raw as $candidate ) {
			if ( ! is_array( $candidate ) || ! isset( $candidate['at'] ) ) {
				continue;
			}
			$at        = (int) $candidate['at'];
			$calc_type = (string) ( $candidate['calc_type'] ?? '' );
			$value     = (float) ( $candidate['value'] ?? -1 );
			if ( $at < 1 || $value < 0 || ! in_array( $calc_type, Amount_Calculator::supported_types(), true ) ) {
				continue;
			}
			$steps[] = [
				'at'        => $at,
				'calc_type' => $calc_type,
				'value'     => $value,
				'label'     => (string) ( $candidate['label'] ?? '' ),
			];
		}
		return $steps;
	}
}
