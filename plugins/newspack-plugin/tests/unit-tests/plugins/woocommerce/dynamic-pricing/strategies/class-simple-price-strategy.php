<?php
/**
 * Tests for Newspack\Dynamic_Pricing\Strategies\Simple_Price_Strategy.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Strategies\Simple_Price_Strategy;
use Newspack\Dynamic_Pricing\Amount_Calculator;
use Newspack\Dynamic_Pricing\Price_Decision;
use Newspack\Dynamic_Pricing\Pricing_Context;

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Simple_Price_Strategy extends WP_UnitTestCase {
	public function test_id_returns_stable_string() {
		$this->assertSame( 'simple_price', ( new Simple_Price_Strategy() )->id() );
	}

	public function test_applies_to_any_context_with_completed_cycles_signal() {
		$s = new Simple_Price_Strategy();
		$this->assertTrue( $s->applies_to( $this->ctx( [ 'completed_cycles' => 1 ] ), [] ) );
		$this->assertTrue( $s->applies_to( $this->ctx( [ 'completed_cycles' => 24 ] ), [] ) );
		$this->assertFalse( $s->applies_to( $this->ctx( [] ), [] ), 'No completed_cycles signal should not apply.' );
	}

	public function test_decide_applies_each_calc_type() {
		$s = new Simple_Price_Strategy();

		$fixed = $s->decide( $this->ctx( [ 'completed_cycles' => 1 ] ), [ 'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => 5, 'label' => 'Promo' ] );
		$this->assertSame( 5.0, $fixed->amount );
		$this->assertSame( Price_Decision::DURABLE, $fixed->durability );
		$this->assertSame( 'simple_fixed_price', $fixed->reason );
		$this->assertSame( 'Promo', $fixed->label );

		$percent = $s->decide( $this->ctx( [ 'completed_cycles' => 1 ] ), [ 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 80 ] );
		$this->assertSame( 8.0, $percent->amount, '80% of $10 base = $8 (i.e. a 20% reduction).' );
	}

	public function test_decide_returns_null_for_malformed_params() {
		$s = new Simple_Price_Strategy();
		$this->assertNull( $s->decide( $this->ctx( [ 'completed_cycles' => 1 ] ), [] ) );
		$this->assertNull( $s->decide( $this->ctx( [ 'completed_cycles' => 1 ] ), [ 'calc_type' => 'bogus', 'value' => 5 ] ) );
		$this->assertNull( $s->decide( $this->ctx( [ 'completed_cycles' => 1 ] ), [ 'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => -1 ] ) );
	}

	public function test_unlimited_by_default_applies_at_any_cycle() {
		$s = new Simple_Price_Strategy();
		$d = $s->decide( $this->ctx( [ 'completed_cycles' => 500 ] ), [ 'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => 5 ] );
		$this->assertNotNull( $d );
		$this->assertSame( 5.0, $d->amount );
	}

	public function test_within_cycles_limit_applies() {
		$s      = new Simple_Price_Strategy();
		$params = [ 'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => 5, 'cycles_limit' => 3 ];
		$this->assertSame( 5.0, $s->decide( $this->ctx( [ 'completed_cycles' => 1 ] ), $params )->amount );
		$this->assertSame( 5.0, $s->decide( $this->ctx( [ 'completed_cycles' => 3 ] ), $params )->amount, 'Limit is inclusive: cycle 3 of 3 still applies.' );
	}

	public function test_beyond_limit_emits_restore_on_price_persisting_surface() {
		$s      = new Simple_Price_Strategy();
		$params = [ 'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => 5, 'cycles_limit' => 3, 'label' => 'Promo' ];
		$d      = $s->decide( $this->ctx( [ 'completed_cycles' => 4 ], 10.0, true ), $params );
		$this->assertNotNull( $d, 'Abstaining beyond the limit would freeze the adjusted price forever.' );
		$this->assertSame( 10.0, $d->amount, 'Restore decision carries the catalog base price.' );
		$this->assertSame( 'restore_base_after_3_cycles', $d->reason );
	}

	public function test_beyond_limit_abstains_on_stateless_surface() {
		$s      = new Simple_Price_Strategy();
		$params = [ 'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => 5, 'cycles_limit' => 3 ];
		$this->assertNull( $s->decide( $this->ctx( [ 'completed_cycles' => 4 ], 10.0, false ), $params ), 'Stateless surfaces get catalog pricing by abstention.' );
	}

	public function test_beyond_limit_restore_abstains_when_base_unknown() {
		$s      = new Simple_Price_Strategy();
		$params = [ 'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => 5, 'cycles_limit' => 3 ];
		$this->assertNull( $s->decide( $this->ctx( [ 'completed_cycles' => 4 ], 0.0, true ), $params ), 'Never restore to a $0 recurring price.' );
	}

	public function test_base_equal_amount_abstains_stateless_and_emits_persisting() {
		$s      = new Simple_Price_Strategy();
		$params = [ 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 100 ];
		$this->assertNull( $s->decide( $this->ctx( [ 'completed_cycles' => 1 ], 10.0, false ), $params ) );
		$d = $s->decide( $this->ctx( [ 'completed_cycles' => 1 ], 10.0, true ), $params );
		$this->assertNotNull( $d );
		$this->assertSame( 10.0, $d->amount );
	}

	private function ctx( array $signals, float $base = 10.0, bool $persists_price = false ): Pricing_Context {
		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$product->method( 'get_id' )->willReturn( 1 );
		$intent = $persists_price ? Pricing_Context::INTENT_RENEWAL : Pricing_Context::INTENT_ACQUISITION;
		return new Pricing_Context( 'cart', $product, null, $base, $signals, null, $intent, $persists_price );
	}
}
