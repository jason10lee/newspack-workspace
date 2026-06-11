<?php
/**
 * Tests for WooProduct_Surface.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\WooProduct_Surface;
use Newspack\Dynamic_Pricing\Price_Decision;
use Newspack\Dynamic_Pricing\Pricing_Context;

// `wc_price` is not provided by tests/mocks/wc-mocks.php; the audit-note
// formatter needs it.
if ( ! function_exists( 'wc_price' ) ) {
	function wc_price( $price ) {
		return '$' . number_format( (float) $price, 2 );
	}
}

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_WooProduct_Surface extends WP_UnitTestCase {
	public function set_up() {
		parent::set_up();
		// Clear the request-scoped registries between tests.
		WooProduct_Surface::reset_publicized_registry( new \WC_Cart() );
	}
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

	public function test_apply_records_applied_decision_even_when_silent() {
		$product = $this->mock_product_with_set_price();
		$ctx = new Pricing_Context(
			WooProduct_Surface::TRIGGER_CART,
			$product,
			null,
			10.0,
			[ 'completed_cycles' => 1 ],
			[ 'data' => $product, 'key' => 'audit_key', 'quantity' => 2 ]
		);
		$d = new Price_Decision( 5.0, Price_Decision::DURABLE, 'simple_fixed_price', 'Promo', 'simple_price', 1 );
		$d->policy_id = 'pol_audit';
		$d->publicize = false;

		( new WooProduct_Surface() )->apply( $ctx, $d );

		$this->assertNull( WooProduct_Surface::get_publicized_apply_for( 'audit_key' ), 'Silent policies must not publicize.' );
		$applied = WooProduct_Surface::get_applied_for( 'audit_key' );
		$this->assertIsArray( $applied, 'Silent policies MUST still be recorded for the audit trail.' );
		$this->assertSame( 'pol_audit', $applied['policy_id'] );
		$this->assertSame( 5.0, $applied['amount'] );
		$this->assertSame( 10.0, $applied['original'] );
		$this->assertSame( 2, $applied['quantity'] );
	}

	public function test_reset_clears_applied_registry() {
		$product = $this->mock_product_with_set_price();
		$ctx = new Pricing_Context( WooProduct_Surface::TRIGGER_CART, $product, null, 10.0, [], [ 'data' => $product, 'key' => 'reset_key' ] );
		$d = new Price_Decision( 5.0, Price_Decision::DURABLE, 'r', 'l', 'simple_price', 1 );
		( new WooProduct_Surface() )->apply( $ctx, $d );
		$this->assertNotNull( WooProduct_Surface::get_applied_for( 'reset_key' ) );

		WooProduct_Surface::reset_publicized_registry( new \WC_Cart() );
		$this->assertNull( WooProduct_Surface::get_applied_for( 'reset_key' ) );
	}

	public function test_acquisition_note_names_policy_product_and_both_prices() {
		$note = WooProduct_Surface::acquisition_note( [
			'policy_id' => '18',
			'label'     => 'Intro',
			'reason'    => 'step_at_1_discount_percent',
			'amount'    => 5.0,
			'original'  => 10.0,
			'item_name' => 'Test Subscription',
			'quantity'  => 1,
		] );
		$this->assertStringContainsString( '[rule 18]', $note );
		$this->assertStringContainsString( 'Test Subscription', $note );
		$this->assertStringContainsString( '$5.00', $note );
		$this->assertStringContainsString( '$10.00', $note );
		$this->assertStringContainsString( 'Intro', $note );
	}

	public function test_acquisition_note_falls_back_to_reason_when_label_empty() {
		$note = WooProduct_Surface::acquisition_note( [
			'policy_id' => '18',
			'label'     => '',
			'reason'    => 'simple_discount_percent',
			'amount'    => 8.0,
			'original'  => 10.0,
			'item_name' => 'Test Subscription',
			'quantity'  => 1,
		] );
		$this->assertStringContainsString( 'simple_discount_percent', $note );
	}

	public function test_note_acquisition_on_order_notes_each_priced_line_once() {
		// Seed the registry via apply (one silent line).
		$product = $this->mock_product_with_set_price();
		$ctx = new Pricing_Context( WooProduct_Surface::TRIGGER_CART, $product, null, 10.0, [], [ 'data' => $product, 'key' => 'order_note_key' ] );
		$d = new Price_Decision( 5.0, Price_Decision::DURABLE, 'simple_fixed_price', 'Promo', 'simple_price', 1 );
		$d->policy_id = 'pol_o';
		( new WooProduct_Surface() )->apply( $ctx, $d );

		$order = $this->getMockBuilder( \WC_Order::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_meta', 'save' ] )
			->addMethods( [ 'add_order_note', 'update_meta_data' ] )
			->getMock();
		$order->method( 'get_meta' )->willReturn( '' );
		$order->expects( $this->once() )->method( 'add_order_note' )->with( $this->stringContains( '[rule pol_o]' ) );
		$order->expects( $this->once() )->method( 'update_meta_data' )->with( '_newspack_dp_acquisition_noted', '1' );
		$order->expects( $this->once() )->method( 'save' );

		WooProduct_Surface::note_acquisition_on_order( $order );
	}

	public function test_note_acquisition_on_order_is_idempotent() {
		$product = $this->mock_product_with_set_price();
		$ctx = new Pricing_Context( WooProduct_Surface::TRIGGER_CART, $product, null, 10.0, [], [ 'data' => $product, 'key' => 'idem_key' ] );
		$d = new Price_Decision( 5.0, Price_Decision::DURABLE, 'r', 'l', 'simple_price', 1 );
		( new WooProduct_Surface() )->apply( $ctx, $d );

		$order = $this->getMockBuilder( \WC_Order::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_meta', 'save' ] )
			->addMethods( [ 'add_order_note', 'update_meta_data' ] )
			->getMock();
		// Already noted (e.g., the other checkout hook fired first).
		$order->method( 'get_meta' )->willReturn( '1' );
		$order->expects( $this->never() )->method( 'add_order_note' );

		WooProduct_Surface::note_acquisition_on_order( $order );
	}

	private function mock_product_with_set_price(): \WC_Product {
		return $this->getMockBuilder( \WC_Product::class )
			->disableOriginalConstructor()
			->addMethods( [ 'set_price' ] )
			->getMock();
	}
}
