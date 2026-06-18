<?php
/**
 * Test Advertising_Metric (NPPD-1697).
 *
 * Covers the derived `has_window_activity` empty-state signal —
 * `Advertising_Metric::window_activity_signal()`, a pure function of the two
 * GAM volume metrics. Tested directly (no reflection, no mock), mirroring the
 * Donors / Subscribers metric tests.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Advertising_Metric;
use WP_UnitTestCase;

/**
 * Advertising_Metric test class.
 *
 * @group insights
 */
class Test_Advertising_Metric extends WP_UnitTestCase {

	/**
	 * A resolved window with no impressions and no revenue is the
	 * no_opportunity state: the signal is false.
	 */
	public function test_no_activity_is_false() {
		$this->assertFalse( Advertising_Metric::window_activity_signal( 0, 0.0 ) );
	}

	/**
	 * Either volume signal flips the activity flag true.
	 *
	 * @dataProvider activity_signal_provider
	 *
	 * @param int    $impressions Total impressions.
	 * @param float  $revenue     Total revenue.
	 * @param bool   $expected    Expected has_window_activity.
	 * @param string $message     Assertion message.
	 */
	public function test_activity_signals( int $impressions, float $revenue, bool $expected, string $message ) {
		$this->assertSame( $expected, Advertising_Metric::window_activity_signal( $impressions, $revenue ), $message );
	}

	/**
	 * Impressions alone, revenue alone, the all-zero baseline, and both.
	 * GAM has no refunds (no negative revenue) and no transaction count, so
	 * these two are the only signals.
	 *
	 * @return array<string, array{0:int,1:float,2:bool,3:string}>
	 */
	public function activity_signal_provider(): array {
		return [
			'all zero'                => [ 0, 0.0, false, 'No impressions and no revenue → no activity.' ],
			'impressions only'        => [ 2400000, 0.0, true, 'Impressions running is activity (drives the per-card no-revenue treatment).' ],
			'revenue only'            => [ 0, 50.0, true, 'Revenue alone is activity.' ],
			'impressions and revenue' => [ 2400000, 4200.0, true, 'A fully populated window is active.' ],
		];
	}
}
