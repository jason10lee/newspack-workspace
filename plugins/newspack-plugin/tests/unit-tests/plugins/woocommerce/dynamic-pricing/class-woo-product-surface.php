<?php
/**
 * Tests for WooProduct_Surface.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\WooProduct_Surface;
use Newspack\Dynamic_Pricing\Price_Decision;
use Newspack\Dynamic_Pricing\Pricing_Context;

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_WooProduct_Surface extends WP_UnitTestCase {
	public function test_id_is_stable() {
		$this->assertSame( 'woo_product', ( new WooProduct_Surface() )->id() );
	}

	public function test_is_stateful_false() {
		$this->assertFalse( ( new WooProduct_Surface() )->is_stateful() );
	}

	public function test_triggers_lists_cart() {
		$this->assertSame( [ WooProduct_Surface::TRIGGER_CART ], ( new WooProduct_Surface() )->triggers() );
	}

	public function test_trigger_constant_matches_spec() {
		$this->assertSame( 'cart', WooProduct_Surface::TRIGGER_CART );
	}

	public function test_context_signals_completed_cycles_is_one() {
		$product = $this->getMockBuilder( \WC_Product::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_type' ] )
			->addMethods( [ 'get_regular_price' ] )
			->getMock();
		$product->method( 'get_type' )->willReturn( 'subscription' );
		$product->method( 'get_regular_price' )->willReturn( '10' );

		$cart_item = [ 'data' => $product ];
		$ctx = ( new WooProduct_Surface() )->context( $cart_item, WooProduct_Surface::TRIGGER_CART );

		$this->assertSame( 1, $ctx->signals['completed_cycles'] );
		$this->assertSame( WooProduct_Surface::TRIGGER_CART, $ctx->trigger );
		$this->assertSame( $cart_item, $ctx->target );
	}

	public function test_context_base_price_falls_back_to_regular_price_when_wcs_unavailable() {
		$product = $this->getMockBuilder( \WC_Product::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_type' ] )
			->addMethods( [ 'get_regular_price' ] )
			->getMock();
		$product->method( 'get_type' )->willReturn( 'simple' );
		$product->method( 'get_regular_price' )->willReturn( '25' );

		$cart_item = [ 'data' => $product ];
		$ctx = ( new WooProduct_Surface() )->context( $cart_item, WooProduct_Surface::TRIGGER_CART );

		$this->assertSame( 25.0, $ctx->base_price );
	}

	public function test_apply_sets_price_on_cart_item_product() {
		$product = $this->getMockBuilder( \WC_Product::class )
			->disableOriginalConstructor()
			->addMethods( [ 'set_price' ] )
			->getMock();
		$product->expects( $this->once() )->method( 'set_price' )->with( 8.0 );

		$cart_item = [ 'data' => $product ];
		$ctx       = new Pricing_Context( WooProduct_Surface::TRIGGER_CART, $product, null, 10.0, [ 'completed_cycles' => 1 ], $cart_item );
		$d         = new Price_Decision( 8.0, Price_Decision::DURABLE, 'step_at_1_fixed_price', 'Intro', 'stepped_by_cycle', 1 );
		$d->policy_id = 'pol_1';

		( new WooProduct_Surface() )->apply( $ctx, $d );
	}

	public function test_apply_bails_when_target_lacks_data_product() {
		$placeholder = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$ctx = new Pricing_Context( WooProduct_Surface::TRIGGER_CART, $placeholder, null, 10.0, [], [ 'data' => null ] );
		$d   = new Price_Decision( 8.0, Price_Decision::DURABLE, 'r', 'l', 'stepped_by_cycle', 1 );

		// Should not throw.
		( new WooProduct_Surface() )->apply( $ctx, $d );
		$this->assertTrue( true );
	}
}
