<?php
/**
 * Pricing Guardrails: compose overlapping decisions; clamp to product bounds.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Composes overlapping price decisions and clamps them to per-product bounds.
 */
final class Pricing_Guardrails {
	/**
	 * @param Bounds_Resolver $bounds Cascading floor/ceiling resolver.
	 */
	public function __construct( private Bounds_Resolver $bounds ) {}

	/**
	 * compose() semantics:
	 *  - priority_exclusive policy: replace current, set is_locked.
	 *  - min(): pick lower amount; if either side is already is_locked, propagate the lock.
	 *
	 * @param Price_Decision|null $current  Decision already chosen by previous policies.
	 * @param Price_Decision      $incoming Decision produced by the policy being composed.
	 * @param Policy              $policy   Policy that produced $incoming (drives compose_mode).
	 * @param Pricing_Context     $ctx      Pricing context (unused today; reserved for future modes).
	 */
	public function compose( ?Price_Decision $current, Price_Decision $incoming, Policy $policy, Pricing_Context $ctx ): Price_Decision {
		if ( 'priority_exclusive' === $policy->compose_mode ) {
			$incoming->is_locked = true;
			return $incoming;
		}

		if ( null === $current ) {
			return $incoming;
		}

		$winner = $incoming->amount < $current->amount ? $incoming : $current;
		// Lock survives: if either side was locked, the winner inherits it.
		$winner->is_locked = $winner->is_locked || $current->is_locked || $incoming->is_locked;
		return $winner;
	}

	/**
	 * Clamp a decision's amount into the product's floor/ceiling envelope.
	 *
	 * @param Price_Decision  $d   Decision to clamp.
	 * @param Pricing_Context $ctx Pricing context (provides the product whose bounds apply).
	 */
	public function guard( Price_Decision $d, Pricing_Context $ctx ): ?Price_Decision {
		[ $floor, $ceiling ] = $this->bounds->for_product( $ctx->product );
		$d->amount           = max( $floor, min( $ceiling, $d->amount ) );
		return $d;
	}
}
