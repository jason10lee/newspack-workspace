<?php
/**
 * Tests for Newspack\Dynamic_Pricing\Subscriptions\Stepped_By_Cycle_Strategy.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Subscriptions\Stepped_By_Cycle_Strategy;
use Newspack\Dynamic_Pricing\Subscriptions\Subscription_Surface;
use Newspack\Dynamic_Pricing\WooProduct_Surface;
use Newspack\Dynamic_Pricing\Amount_Calculator;
use Newspack\Dynamic_Pricing\Pricing_Context;
use Newspack\Dynamic_Pricing\Price_Decision;

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Stepped_By_Cycle_Strategy extends WP_UnitTestCase {
	public function test_applies_on_scheduled_step_or_cart_trigger_with_signal() {
		$s = new Stepped_By_Cycle_Strategy();
		$this->assertTrue( $s->applies_to( $this->ctx( Subscription_Surface::TRIGGER_SCHEDULED_STEP, [ 'completed_cycles' => 2 ] ), [] ) );
		$this->assertTrue( $s->applies_to( $this->ctx( WooProduct_Surface::TRIGGER_CART, [ 'completed_cycles' => 1 ] ), [] ), 'Cart trigger should apply (acquisition path).' );
		$this->assertFalse( $s->applies_to( $this->ctx( 'unknown_trigger', [ 'completed_cycles' => 2 ] ), [] ) );
		$this->assertFalse( $s->applies_to( $this->ctx( Subscription_Surface::TRIGGER_SCHEDULED_STEP, [] ), [] ), 'No completed_cycles signal should not apply.' );
	}

	public function test_decide_selects_highest_step_le_cycle_with_fixed_price() {
		$s = new Stepped_By_Cycle_Strategy();
		$params = [ 'steps' => [
			[ 'at' => 1,  'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => 1,  'label' => 'Intro' ],
			[ 'at' => 4,  'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => 8,  'label' => 'Standard' ],
			[ 'at' => 13, 'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => 10, 'label' => 'Year 2' ],
		] ];

		$d = $s->decide( $this->ctx( Subscription_Surface::TRIGGER_SCHEDULED_STEP, [ 'completed_cycles' => 4 ], 1.0 ), $params );
		$this->assertNotNull( $d );
		$this->assertSame( 8.0, $d->amount );
		$this->assertSame( Price_Decision::DURABLE, $d->durability );
		$this->assertSame( 4, $d->dimension_value );
		$this->assertStringStartsWith( 'step_at_4_', $d->reason );
	}

	public function test_decide_returns_null_when_step_amount_equals_base() {
		$s = new Stepped_By_Cycle_Strategy();
		$params = [ 'steps' => [ [ 'at' => 1, 'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => 1, 'label' => 'Intro' ] ] ];
		$this->assertNull( $s->decide( $this->ctx( Subscription_Surface::TRIGGER_SCHEDULED_STEP, [ 'completed_cycles' => 1 ], 1.0 ), $params ) );
	}

	public function test_decide_resolves_percent_of_base() {
		$s = new Stepped_By_Cycle_Strategy();
		$params = [ 'steps' => [
			[ 'at' => 4, 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 80, 'label' => 'Standard' ],
		] ];
		$d = $s->decide( $this->ctx( Subscription_Surface::TRIGGER_SCHEDULED_STEP, [ 'completed_cycles' => 4 ], 10.0 ), $params );
		$this->assertNotNull( $d );
		$this->assertSame( 8.0, $d->amount );
	}

	public function test_decide_returns_null_when_no_step_matches() {
		$s = new Stepped_By_Cycle_Strategy();
		$params = [ 'steps' => [ [ 'at' => 5, 'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => 5, 'label' => 'Late' ] ] ];
		$this->assertNull( $s->decide( $this->ctx( Subscription_Surface::TRIGGER_SCHEDULED_STEP, [ 'completed_cycles' => 3 ], 10.0 ), $params ) );
	}

	public function test_decide_returns_null_for_malformed_params() {
		$s = new Stepped_By_Cycle_Strategy();
		$this->assertNull( $s->decide( $this->ctx( Subscription_Surface::TRIGGER_SCHEDULED_STEP, [ 'completed_cycles' => 4 ], 10.0 ), [] ) );
		$this->assertNull( $s->decide( $this->ctx( Subscription_Surface::TRIGGER_SCHEDULED_STEP, [ 'completed_cycles' => 4 ], 10.0 ), [ 'steps' => 'not-an-array' ] ) );
	}

	private function ctx( string $trigger, array $signals, float $base = 10.0 ): Pricing_Context {
		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$product->method( 'get_id' )->willReturn( 1 );
		return new Pricing_Context( $trigger, $product, null, $base, $signals, null );
	}
}
