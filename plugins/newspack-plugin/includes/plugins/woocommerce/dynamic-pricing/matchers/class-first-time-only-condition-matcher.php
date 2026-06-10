<?php
/**
 * First-time-only condition matcher.
 *
 * Restricts a policy to customers who have never held a subscription to the
 * scoped product (any status). Addresses the v1 known limitation in spec §10.4:
 * a cancelled subscriber who re-purchases would otherwise re-trigger the intro
 * step at cycle 1.
 *
 * Renewal-intent contexts ALWAYS pass — the matcher only gates acquisition.
 * Without this, a stepped policy with `first_time_only` on would fail at every
 * renewal and the engine would abstain, leaving the line item frozen at
 * cycle-1 forever. Operators who want a strict "no stepping after returners"
 * policy should split into two: an intro-only policy with `first_time_only`
 * on, plus an unconditioned stepped policy.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing\Matchers;

use Newspack\Dynamic_Pricing\Condition_Matcher;
use Newspack\Dynamic_Pricing\Pricing_Context;

defined( 'ABSPATH' ) || exit;

final class First_Time_Only_Condition_Matcher implements Condition_Matcher {
	public function id(): string {
		return 'first_time_only';
	}

	public function matches( Pricing_Context $ctx, mixed $value ): bool {
		// Falsy value = condition is off, always pass.
		if ( ! $value ) {
			return true;
		}

		// Only gate the acquisition path. Renewals always pass so stepped policies
		// keep applying their schedule to existing subscribers.
		if ( Pricing_Context::INTENT_ACQUISITION !== $ctx->intent ) {
			return true;
		}

		// WCS unavailable — fail open (no restriction) so policies don't silently
		// stop working on non-WCS sites.
		if ( ! function_exists( 'wcs_user_has_subscription' ) ) {
			return true;
		}

		$customer = $ctx->customer;
		$user_id  = $customer ? (int) $customer->get_id() : 0;
		if ( 0 === $user_id ) {
			// Guest checkout — by definition first-time. Catches the common case;
			// the alternate (fresh-email returner) is documented in spec §10.4
			// as an accepted gap.
			return true;
		}

		$product_id = (int) $ctx->product->get_id();
		// Empty $status arg = ANY status (active, on-hold, cancelled, expired, etc.).
		// We want to fail for anyone who has *ever* held this subscription.
		$has_prior = wcs_user_has_subscription( $user_id, $product_id );
		return ! $has_prior;
	}
}
