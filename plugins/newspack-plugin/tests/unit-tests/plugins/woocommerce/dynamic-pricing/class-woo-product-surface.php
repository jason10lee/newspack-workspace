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

	public function test_eligible_cart_item_requires_product_data() {
		$this->assertFalse( WooProduct_Surface::is_eligible_cart_item( null ) );
		$this->assertFalse( WooProduct_Surface::is_eligible_cart_item( [] ) );
		$this->assertFalse( WooProduct_Surface::is_eligible_cart_item( [ 'data' => 'not-a-product' ] ) );
		$this->assertTrue( WooProduct_Surface::is_eligible_cart_item( [ 'data' => $this->mock_product_with_set_price() ] ) );
	}

	public function test_renewal_family_cart_items_are_not_eligible() {
		$product = $this->mock_product_with_set_price();
		foreach ( [ 'subscription_renewal', 'subscription_resubscribe', 'subscription_switch' ] as $key ) {
			$this->assertFalse(
				WooProduct_Surface::is_eligible_cart_item( [ 'data' => $product, $key => [ 'subscription_id' => 99 ] ] ),
				"Cart items flagged {$key} are not acquisitions and must not be priced by this surface."
			);
		}
	}

	public function test_gifted_cart_items_are_not_eligible() {
		$product = $this->mock_product_with_set_price();
		$this->assertFalse(
			WooProduct_Surface::is_eligible_cart_item( [ 'data' => $product, 'wcsg_gift_recipients_email' => 'recipient@example.com' ] ),
			'Gifted items produce subscriptions the renewal surface excludes; no acquisition grant.'
		);
	}

	public function test_context_declares_acquisition_intent_without_price_persistence() {
		$product = $this->getMockBuilder( \WC_Product::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_type', 'get_regular_price' ] )
			->getMock();
		$product->method( 'get_type' )->willReturn( 'subscription' );
		$product->method( 'get_regular_price' )->willReturn( '10' );

		$ctx = ( new WooProduct_Surface() )->context( [ 'data' => $product ], WooProduct_Surface::TRIGGER_CART );

		$this->assertSame( Pricing_Context::INTENT_ACQUISITION, $ctx->intent );
		$this->assertFalse( $ctx->persists_price );
	}

	public function test_context_signals_completed_cycles_is_one() {
		$product = $this->getMockBuilder( \WC_Product::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_type', 'get_regular_price' ] )
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
			->onlyMethods( [ 'get_type', 'get_regular_price' ] )
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

	public function test_apply_does_not_record_publicized_state_when_flag_is_off() {
		$cart_item_key = 'silent_key';
		$product = $this->mock_product_with_set_price();
		$ctx = new Pricing_Context(
			WooProduct_Surface::TRIGGER_CART,
			$product,
			null,
			10.0,
			[ 'completed_cycles' => 1 ],
			[ 'data' => $product, 'key' => $cart_item_key ]
		);
		$d = new Price_Decision( 8.0, Price_Decision::DURABLE, 'r', 'Intro', 'stepped_by_cycle', 1 );
		$d->policy_id = 'pol_silent';
		$d->publicize = false;

		( new WooProduct_Surface() )->apply( $ctx, $d );

		$this->assertNull( WooProduct_Surface::get_publicized_apply_for( $cart_item_key ) );
	}

	public function test_apply_records_publicized_state_when_flag_is_on() {
		$cart_item_key = 'loud_key';
		$product = $this->mock_product_with_set_price();
		$ctx = new Pricing_Context(
			WooProduct_Surface::TRIGGER_CART,
			$product,
			null,
			10.0,
			[ 'completed_cycles' => 1 ],
			[ 'data' => $product, 'key' => $cart_item_key ]
		);
		$d = new Price_Decision( 2.0, Price_Decision::DURABLE, 'step_at_1_fixed_price', 'Intro', 'stepped_by_cycle', 1 );
		$d->policy_id = 'pol_loud';
		$d->publicize = true;

		( new WooProduct_Surface() )->apply( $ctx, $d );

		$applied = WooProduct_Surface::get_publicized_apply_for( $cart_item_key );
		$this->assertIsArray( $applied );
		$this->assertSame( 10.0, $applied['original'] );
		$this->assertSame( 2.0, $applied['discounted'] );
		$this->assertSame( 'Intro', $applied['label'] );
		$this->assertSame( 'pol_loud', $applied['policy_id'] );
	}

	private function mock_product_with_set_price(): \WC_Product {
		return $this->getMockBuilder( \WC_Product::class )
			->disableOriginalConstructor()
			->addMethods( [ 'set_price' ] )
			->getMock();
	}
}
