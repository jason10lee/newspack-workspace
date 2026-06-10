<?php
/**
 * Subscription-started-after condition matcher (cohort gating).
 *
 * Restricts a policy to subscriptions acquired on/after a given moment, so a
 * LIVE-class policy created today doesn't reach back into renewals of
 * subscriptions purchased before it existed (deal-class policies never reach
 * back; this closes the same gap for live ones). A future-dated value doubles
 * as "not open yet" at acquisition.
 *
 * Value: UTC timestamp (int). Falsy = condition off.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing\Matchers;

use Newspack\Dynamic_Pricing\Condition_Matcher;
use Newspack\Dynamic_Pricing\Pricing_Context;

defined( 'ABSPATH' ) || exit;

final class Subscription_Started_After_Condition_Matcher implements Condition_Matcher {
	public function id(): string {
		return 'subscription_started_after';
	}

	public function matches( Pricing_Context $ctx, mixed $value ): bool {
		$threshold = (int) $value;
		if ( $threshold <= 0 ) {
			return true;
		}

		// Acquisition: the subscription starts NOW — the gate is simply whether
		// the cohort window has opened.
		if ( Pricing_Context::INTENT_ACQUISITION === $ctx->intent ) {
			return time() >= $threshold;
		}

		// Renewal: gate on the target subscription's start. Fail open when the
		// start can't be determined (exotic surface/target) — consistent with
		// the other condition matchers' posture of never silently killing a
		// policy on contexts they don't understand.
		if ( ! $ctx->target instanceof \WC_Subscription ) {
			return true;
		}
		$started = method_exists( $ctx->target, 'get_time' ) ? (int) $ctx->target->get_time( 'start' ) : 0;
		if ( $started <= 0 && method_exists( $ctx->target, 'get_date_created' ) ) {
			$created = $ctx->target->get_date_created();
			$started = $created ? (int) $created->getTimestamp() : 0;
		}
		if ( $started <= 0 ) {
			return true;
		}
		return $started >= $threshold;
	}
}
