<?php
/**
 * Tests for Newspack\Dynamic_Pricing\Matchers\First_Time_Only_Condition_Matcher.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Matchers\First_Time_Only_Condition_Matcher;
use Newspack\Dynamic_Pricing\Pricing_Context;
use Newspack\Dynamic_Pricing\WooProduct_Surface;

// Mock for wcs_user_has_subscription — driven by a global so tests can seed prior subs.
if ( ! function_exists( 'wcs_user_has_subscription' ) ) {
	function wcs_user_has_subscription( $user_id, $product_id = 0, $status = '' ) {
		global $newspack_test_dp_prior_subs;
		$newspack_test_dp_prior_subs = $newspack_test_dp_prior_subs ?? [];
		return isset( $newspack_test_dp_prior_subs[ (int) $user_id ][ (int) $product_id ] );
	}
}

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_First_Time_Only_Condition_Matcher extends WP_UnitTestCase {
	private First_Time_Only_Condition_Matcher $matcher;

	public function set_up() {
		parent::set_up();
		global $newspack_test_dp_prior_subs;
		$newspack_test_dp_prior_subs = [];
		$this->matcher = new First_Time_Only_Condition_Matcher();
	}

	public function tear_down() {
		global $newspack_test_dp_prior_subs;
		$newspack_test_dp_prior_subs = [];
		parent::tear_down();
	}

	public function test_id_returns_stable_string() {
		$this->assertSame( 'first_time_only', $this->matcher->id() );
	}

	public function test_off_value_passes() {
		$ctx = $this->build_context( Pricing_Context::INTENT_ACQUISITION, 42, 100 );
		// Even if user has a prior sub, value=false (condition off) short-circuits.
		$this->seed_prior_sub( 100, 42 );
		$this->assertTrue( $this->matcher->matches( $ctx, false ) );
		$this->assertTrue( $this->matcher->matches( $ctx, null ) );
		$this->assertTrue( $this->matcher->matches( $ctx, 0 ) );
	}

	public function test_renewal_intent_passes_for_returner() {
		$ctx = $this->build_context( Pricing_Context::INTENT_RENEWAL, 42, 100 );
		$this->seed_prior_sub( 100, 42 );
		// Renewal context — matcher always passes so stepped policies keep stepping.
		$this->assertTrue( $this->matcher->matches( $ctx, true ) );
	}

	public function test_guest_is_first_time() {
		$ctx = $this->build_context( Pricing_Context::INTENT_ACQUISITION, 42, 0 );
		$this->assertTrue( $this->matcher->matches( $ctx, true ) );
	}

	public function test_first_timer_passes_on_acquisition() {
		$ctx = $this->build_context( Pricing_Context::INTENT_ACQUISITION, 42, 100 );
		// No prior subs seeded — user is first-time for this product.
		$this->assertTrue( $this->matcher->matches( $ctx, true ) );
	}

	public function test_returner_fails_on_acquisition() {
		$ctx = $this->build_context( Pricing_Context::INTENT_ACQUISITION, 42, 100 );
		$this->seed_prior_sub( 100, 42 );
		$this->assertFalse( $this->matcher->matches( $ctx, true ) );
	}

	public function test_returner_to_different_product_passes() {
		$ctx = $this->build_context( Pricing_Context::INTENT_ACQUISITION, 42, 100 );
		// User had a sub to product 99, NOT product 42 — first-time for 42.
		$this->seed_prior_sub( 100, 99 );
		$this->assertTrue( $this->matcher->matches( $ctx, true ) );
	}

	private function build_context( string $intent, int $product_id, int $user_id ): Pricing_Context {
		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$product->method( 'get_id' )->willReturn( $product_id );
		$product->method( 'get_type' )->willReturn( 'subscription' );

		$customer = null;
		if ( $user_id > 0 ) {
			$customer = $this->getMockBuilder( \WC_Customer::class )->disableOriginalConstructor()->getMock();
			$customer->method( 'get_id' )->willReturn( $user_id );
		}

		$trigger = Pricing_Context::INTENT_ACQUISITION === $intent ? WooProduct_Surface::TRIGGER_CART : 'scheduled_step';
		return new Pricing_Context( $trigger, $product, $customer, 10.0, [], null, $intent, Pricing_Context::INTENT_RENEWAL === $intent );
	}

	private function seed_prior_sub( int $user_id, int $product_id ): void {
		global $newspack_test_dp_prior_subs;
		$newspack_test_dp_prior_subs[ $user_id ][ $product_id ] = true;
	}
}
