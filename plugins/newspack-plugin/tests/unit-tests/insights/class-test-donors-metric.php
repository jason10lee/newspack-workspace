<?php
/**
 * Test Donors_Metric (NPPD-1696).
 *
 * Covers the derived `has_window_activity` empty-state signal —
 * `Donors_Metric::window_activity_signal()`, a pure function of three
 * already-computed window values. Tested directly (no reflection, no mock),
 * mirroring how the Gates section-totals derivation is tested.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Donors_Metric;
use WP_UnitTestCase;

/**
 * Donors_Metric test class.
 *
 * @group insights
 */
class Test_Donors_Metric extends WP_UnitTestCase {

	/**
	 * An empty window — no revenue, no new donors, no lapses — is the
	 * no_opportunity state: the signal is false.
	 */
	public function test_no_activity_is_false() {
		$this->assertFalse( Donors_Metric::window_activity_signal( 0, 0, 0.0 ) );
	}

	/**
	 * Any one of the three signals flips the activity flag true.
	 *
	 * @dataProvider activity_signal_provider
	 *
	 * @param int    $new_donors    First-time donor count.
	 * @param int    $lapsed_donors Lapsed donor count.
	 * @param float  $total_revenue Total donation revenue.
	 * @param bool   $expected      Expected has_window_activity.
	 * @param string $message       Assertion message.
	 */
	public function test_activity_signals( int $new_donors, int $lapsed_donors, float $total_revenue, bool $expected, string $message ) {
		$this->assertSame( $expected, Donors_Metric::window_activity_signal( $new_donors, $lapsed_donors, $total_revenue ), $message );
	}

	/**
	 * Each signal in isolation, plus the all-zero baseline and the
	 * net-of-refunds edge (negative revenue is still activity).
	 *
	 * @return array<string, array{0:int,1:int,2:float,3:bool,4:string}>
	 */
	public function activity_signal_provider(): array {
		return [
			'all zero'             => [ 0, 0, 0.0, false, 'No revenue, no new donors, no lapses → no activity.' ],
			'revenue only'         => [ 0, 0, 250.0, true, 'Donation revenue alone is activity.' ],
			'new donors only'      => [ 5, 0, 0.0, true, 'A first-time donor is activity even at $0 net.' ],
			'lapsed donors only'   => [ 0, 2, 0.0, true, 'A lapse is activity even with no revenue this window.' ],
			'negative net revenue' => [ 0, 0, -50.0, true, 'A refund (negative net revenue) is still activity, not an empty window.' ],
			'all signals present'  => [ 8, 1, 900.0, true, 'A fully populated window is active.' ],
		];
	}
}
