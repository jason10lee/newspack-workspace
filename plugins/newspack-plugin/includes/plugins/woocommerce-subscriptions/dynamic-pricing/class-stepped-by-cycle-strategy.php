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
use Newspack\Dynamic_Pricing\WooProduct_Surface;

defined( 'ABSPATH' ) || exit;

final class Stepped_By_Cycle_Strategy implements Pricing_Strategy {
	public function id(): string {
		return 'stepped_by_cycle';
	}

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
		$valid_triggers = [
			Subscription_Surface::TRIGGER_SCHEDULED_STEP,
			WooProduct_Surface::TRIGGER_CART,
		];
		return in_array( $ctx->trigger, $valid_triggers, true )
			&& isset( $ctx->signals['completed_cycles'] );
	}

	public function decide( Pricing_Context $ctx, array $params ): ?Price_Decision {
		$cycle = (int) ( $ctx->signals['completed_cycles'] ?? 0 );
		$steps = is_array( $params['steps'] ?? null ) ? $params['steps'] : [];
		$step  = null;
		foreach ( $steps as $candidate ) {
			if ( ! is_array( $candidate ) || ! isset( $candidate['at'] ) ) {
				continue;
			}
			if ( $cycle >= (int) $candidate['at'] ) {
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
		if ( abs( $amount - $ctx->base_price ) < 0.01 ) {
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
}
