<?php
/**
 * Test Subscribers_Metric (NPPD-1695).
 *
 * Covers the derived `has_window_activity` empty-state signal —
 * `Subscribers_Metric::window_activity_signal()`, a pure function of values the
 * controller already fetches. Tested directly (no reflection, no mock),
 * mirroring the Donors metric test.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Subscribers_Metric;
use WP_UnitTestCase;

/**
 * Subscribers_Metric test class.
 *
 * @group insights
 */
class Test_Subscribers_Metric extends WP_UnitTestCase {

	/**
	 * An empty window — no revenue, no new subscribers, no churn — is the
	 * no_opportunity state: the signal is false.
	 */
	public function test_no_activity_is_false() {
		$this->assertFalse( Subscribers_Metric::window_activity_signal( 0, 0, 0.0, 0.0 ) );
	}

	/**
	 * Any one of the signals flips the activity flag true.
	 *
	 * @dataProvider activity_signal_provider
	 *
	 * @param int    $new_subscribers     First-time subscriber count.
	 * @param int    $churned_subscribers Churned subscriber count.
	 * @param float  $revenue_gross       Gross subscription revenue.
	 * @param float  $revenue_net         Net subscription revenue.
	 * @param bool   $expected            Expected has_window_activity.
	 * @param string $message             Assertion message.
	 */
	public function test_activity_signals( int $new_subscribers, int $churned_subscribers, float $revenue_gross, float $revenue_net, bool $expected, string $message ) {
		$this->assertSame(
			$expected,
			Subscribers_Metric::window_activity_signal( $new_subscribers, $churned_subscribers, $revenue_gross, $revenue_net ),
			$message
		);
	}

	/**
	 * Each signal in isolation, the all-zero baseline, the refund-only edge
	 * (negative net), and the two churn cases that matter for the good-zero
	 * treatment: churn EVENTS are activity, but zero churn alone is not.
	 *
	 * @return array<string, array{0:int,1:int,2:float,3:float,4:bool,5:string}>
	 */
	public function activity_signal_provider(): array {
		return [
			'all zero'                     => [ 0, 0, 0.0, 0.0, false, 'No revenue, no new subscribers, no churn → no activity.' ],
			'gross revenue only'           => [ 0, 0, 250.0, 250.0, true, 'Subscription revenue alone is activity.' ],
			'refund-only (negative net)'   => [ 0, 0, 0.0, -50.0, true, 'A refund (negative net revenue) is still activity, not an empty window.' ],
			'new subscribers only'         => [ 5, 0, 0.0, 0.0, true, 'A first-time subscriber is activity even at $0.' ],
			'churn only'                   => [ 0, 3, 0.0, 0.0, true, 'Churn EVENTS are activity — a window where subscribers left is not empty.' ],
			'zero churn does not suppress' => [ 0, 0, 500.0, 500.0, true, 'Zero churn alongside non-zero revenue still renders (the churn card shows its good zero).' ],
			'all signals present'          => [ 8, 2, 900.0, 850.0, true, 'A fully populated window is active.' ],
		];
	}
}
