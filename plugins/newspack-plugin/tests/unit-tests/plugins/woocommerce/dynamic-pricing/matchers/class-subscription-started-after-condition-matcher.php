<?php
/**
 * Tests for Newspack\Dynamic_Pricing\Matchers\Subscription_Started_After_Condition_Matcher.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Matchers\Subscription_Started_After_Condition_Matcher;
use Newspack\Dynamic_Pricing\Pricing_Context;

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Subscription_Started_After_Condition_Matcher extends WP_UnitTestCase {
	private Subscription_Started_After_Condition_Matcher $matcher;

	public function set_up() {
		parent::set_up();
		$this->matcher = new Subscription_Started_After_Condition_Matcher();
	}

	public function test_id_returns_stable_string() {
		$this->assertSame( 'subscription_started_after', $this->matcher->id() );
	}

	public function test_off_value_passes() {
		$ctx = $this->renewal_context( $this->subscription_started_at( time() - YEAR_IN_SECONDS ) );
		$this->assertTrue( $this->matcher->matches( $ctx, null ) );
		$this->assertTrue( $this->matcher->matches( $ctx, 0 ) );
		$this->assertTrue( $this->matcher->matches( $ctx, '' ) );
	}

	public function test_acquisition_passes_once_window_open() {
		$ctx = $this->acquisition_context();
		$this->assertTrue( $this->matcher->matches( $ctx, time() - DAY_IN_SECONDS ), 'Past threshold: cohort window is open for new purchases.' );
		$this->assertFalse( $this->matcher->matches( $ctx, time() + DAY_IN_SECONDS ), 'Future threshold: rule not open yet at checkout.' );
	}

	public function test_renewal_gates_on_subscription_start_date() {
		$threshold = time() - WEEK_IN_SECONDS;

		$newer = $this->renewal_context( $this->subscription_started_at( time() - DAY_IN_SECONDS ) );
		$this->assertTrue( $this->matcher->matches( $newer, $threshold ), 'Subscription started after the threshold matches.' );

		$older = $this->renewal_context( $this->subscription_started_at( time() - YEAR_IN_SECONDS ) );
		$this->assertFalse( $this->matcher->matches( $older, $threshold ), 'A live rule must not reach back into older cohorts.' );
	}

	public function test_renewal_fails_open_when_start_unknown() {
		$ctx = $this->renewal_context( $this->subscription_started_at( 0 ) );
		$this->assertTrue( $this->matcher->matches( $ctx, time() - WEEK_IN_SECONDS ) );
	}

	public function test_renewal_fails_open_for_non_subscription_target() {
		$ctx = $this->renewal_context( null );
		$this->assertTrue( $this->matcher->matches( $ctx, time() - WEEK_IN_SECONDS ) );
	}

	private function subscription_started_at( int $timestamp ): \WC_Subscription {
		$sub = $this->getMockBuilder( \WC_Subscription::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_time', 'get_date_created' ] )
			->getMock();
		$sub->method( 'get_time' )->with( 'start' )->willReturn( $timestamp );
		$sub->method( 'get_date_created' )->willReturn( null );
		return $sub;
	}

	private function acquisition_context(): Pricing_Context {
		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		return new Pricing_Context( 'cart', $product, null, 10.0, [], null, Pricing_Context::INTENT_ACQUISITION, false );
	}

	private function renewal_context( mixed $target ): Pricing_Context {
		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		return new Pricing_Context( 'scheduled_step', $product, null, 10.0, [ 'completed_cycles' => 2 ], $target, Pricing_Context::INTENT_RENEWAL, true );
	}
}
