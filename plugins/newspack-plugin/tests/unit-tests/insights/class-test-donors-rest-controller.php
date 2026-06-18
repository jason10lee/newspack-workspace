<?php
/**
 * Test Donors_REST_Controller (NPPD-1696).
 *
 * Covers the derived `has_window_activity` empty-state signal added to the
 * Tab 7 window payload. The derivation is the only server-side logic NPPD-1696
 * introduces: a pure function of three already-computed values
 * (`total_revenue`, `new_donors`, `lapsed_donors`) with no extra query. We
 * exercise the private `build_window()` against a mocked `Donors_Metric` so the
 * test is deterministic and independent of the WooCommerce order schema — the
 * storage layer is out of scope for this ticket and unchanged.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use DateTimeImmutable;
use Newspack\Insights\Donors_Metric;
use Newspack\Insights\Donors_REST_Controller;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Donors_REST_Controller test class.
 *
 * @group insights
 */
class Test_Donors_REST_Controller extends WP_UnitTestCase {

	/**
	 * Invoke the private build_window() with a mocked metric and return the
	 * derived `has_window_activity` flag.
	 *
	 * Only the three values the derivation reads are stubbed; PHPUnit
	 * auto-generates type-matching defaults (0 / 0.0 / []) for the remaining
	 * metric methods build_window() calls.
	 *
	 * @param int   $new_donors    Stubbed first-time donor count.
	 * @param int   $lapsed_donors Stubbed lapsed donor count.
	 * @param float $total_revenue Stubbed total donation revenue.
	 * @return bool The derived has_window_activity flag.
	 */
	private function derive_has_window_activity( int $new_donors, int $lapsed_donors, float $total_revenue ): bool {
		$metric = $this->getMockBuilder( Donors_Metric::class )
			->disableOriginalConstructor()
			->getMock();
		$metric->method( 'get_new_donors_in_window' )->willReturn( $new_donors );
		$metric->method( 'get_lapsed_donors_in_window' )->willReturn( $lapsed_donors );
		$metric->method( 'get_total_donation_revenue' )->willReturn( $total_revenue );

		$build_window = new ReflectionMethod( Donors_REST_Controller::class, 'build_window' );
		$build_window->setAccessible( true );
		$window = $build_window->invoke(
			new Donors_REST_Controller(),
			$metric,
			new DateTimeImmutable( '2026-05-18' ),
			new DateTimeImmutable( '2026-06-16' )
		);

		$this->assertArrayHasKey( 'has_window_activity', $window, 'build_window() must always emit has_window_activity.' );
		$this->assertIsBool( $window['has_window_activity'] );
		return $window['has_window_activity'];
	}

	/**
	 * An empty window — no revenue, no new donors, no lapses — is the
	 * no_opportunity state: has_window_activity is false.
	 */
	public function test_no_activity_is_false() {
		$this->assertFalse( $this->derive_has_window_activity( 0, 0, 0.0 ) );
	}

	/**
	 * Any one of the three signals flips has_window_activity true.
	 *
	 * @dataProvider activity_signal_provider
	 *
	 * @param int    $new_donors    First-time donor count.
	 * @param int    $lapsed_donors Lapsed donor count.
	 * @param float  $total_revenue Total donation revenue.
	 * @param bool   $expected      Expected has_window_activity.
	 * @param string $message      Assertion message.
	 */
	public function test_activity_signals( int $new_donors, int $lapsed_donors, float $total_revenue, bool $expected, string $message ) {
		$this->assertSame( $expected, $this->derive_has_window_activity( $new_donors, $lapsed_donors, $total_revenue ), $message );
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
