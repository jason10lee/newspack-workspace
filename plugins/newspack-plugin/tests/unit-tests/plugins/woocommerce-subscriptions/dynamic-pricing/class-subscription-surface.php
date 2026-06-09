<?php
/**
 * Tests for Newspack\Dynamic_Pricing\Subscriptions\Subscription_Surface.
 *
 * Mirrors the PHPUnit-mock pattern used by the Task 11 bridges test and the
 * Pricing_Engine test rather than the wc-mocks `wcs_create_subscription()`
 * shim — that shim's `WC_Subscription` does not support `add_product()`,
 * `get_payment_count()`, `add_order_note()`, or `calculate_totals()`, all of
 * which the surface exercises.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Subscriptions\Subscription_Surface;
use Newspack\Dynamic_Pricing\Price_Decision;
use Newspack\Dynamic_Pricing\Pricing_Context;

// `wc_price` is not provided by tests/mocks/wc-mocks.php; stub it here so the
// surface's audit-note formatter (`sprintf( ..., wc_price( $d->amount ), ... )`)
// resolves without pulling in WooCommerce.
if ( ! function_exists( 'wc_price' ) ) {
	function wc_price( $price ) {
		return '$' . number_format( (float) $price, 2 );
	}
}

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Subscription_Surface extends WP_UnitTestCase {
	/** @var int Product id used to seed `wc_get_product()` lookups. */
	private const PRODUCT_ID = 1234;

	public function set_up() {
		parent::set_up();
		// Seed the wc-mocks product database so `wc_get_product( self::PRODUCT_ID )`
		// returns a real WC_Product instance during `Subscription_Surface::context()`.
		wc_create_mock_product( [ 'id' => self::PRODUCT_ID ] );
	}
	public function test_trigger_constant_matches_spec() {
		$this->assertSame( 'scheduled_step', Subscription_Surface::TRIGGER_SCHEDULED_STEP );
	}

	public function test_context_signals_completed_cycles_is_count_plus_one() {
		$line    = $this->mock_line_item( 10.0 );
		$sub     = $this->mock_subscription( completed_payments: 3, line_item: $line );
		$surface = new Subscription_Surface();

		$ctx = $surface->context( $sub, Subscription_Surface::TRIGGER_SCHEDULED_STEP );

		$this->assertSame( 4, $ctx->signals['completed_cycles'] );
		$this->assertSame( Subscription_Surface::TRIGGER_SCHEDULED_STEP, $ctx->trigger );
		$this->assertSame( $sub, $ctx->target );
	}

	public function test_apply_persists_amount_onto_recurring_line_item() {
		$line = $this->mock_line_item( 10.0 );
		$line->expects( $this->once() )->method( 'set_subtotal' )->with( 8.0 );
		$line->expects( $this->once() )->method( 'set_total' )->with( 8.0 );
		$line->expects( $this->once() )->method( 'save' );

		$sub = $this->mock_subscription( completed_payments: 3, line_item: $line );
		// The state-map writeback should land on this subscription.
		$captured_state = null;
		$sub->method( 'update_meta_data' )->willReturnCallback(
			function ( $key, $value ) use ( &$captured_state ) {
				if ( Subscription_Surface::STATE_META_KEY === $key ) {
					$captured_state = $value;
				}
			}
		);
		$sub->expects( $this->once() )->method( 'calculate_totals' );
		$sub->expects( $this->once() )->method( 'add_order_note' );
		$sub->expects( $this->once() )->method( 'save' );

		$ctx = $this->ctx( $sub, base_price: 10.0 );
		$d   = new Price_Decision( 8.0, Price_Decision::DURABLE, 'step_at_4_fixed_price', 'Standard', 'stepped_by_cycle', 4 );
		$d->policy_id = 'pol_1';

		( new Subscription_Surface() )->apply( $ctx, $d );

		$this->assertIsArray( $captured_state );
		$this->assertArrayHasKey( 'pol_1', $captured_state );
		$this->assertSame( 4, $captured_state['pol_1']['dimension_value'] );
		$this->assertSame( 8.0, $captured_state['pol_1']['amount'] );
		$this->assertSame( 'stepped_by_cycle', $captured_state['pol_1']['strategy_id'] );
		$this->assertSame( 'step_at_4_fixed_price', $captured_state['pol_1']['reason'] );
	}

	public function test_apply_short_circuits_when_amount_already_matches() {
		$line = $this->mock_line_item( 8.0 );
		$line->expects( $this->never() )->method( 'set_subtotal' );
		$line->expects( $this->never() )->method( 'save' );

		$sub = $this->mock_subscription( completed_payments: 3, line_item: $line );
		$sub->expects( $this->never() )->method( 'update_meta_data' );
		$sub->expects( $this->never() )->method( 'add_order_note' );
		$sub->expects( $this->never() )->method( 'calculate_totals' );
		$sub->expects( $this->never() )->method( 'save' );

		$ctx = $this->ctx( $sub, base_price: 10.0 );
		$d   = new Price_Decision( 8.0, Price_Decision::DURABLE, 'step_at_4_fixed_price', 'Standard', 'stepped_by_cycle', 5 );
		$d->policy_id = 'pol_1';

		( new Subscription_Surface() )->apply( $ctx, $d );
	}

	public function test_apply_skips_one_time_decisions_in_v1() {
		$line = $this->mock_line_item( 10.0 );
		$line->expects( $this->never() )->method( 'set_subtotal' );
		$line->expects( $this->never() )->method( 'save' );

		$sub = $this->mock_subscription( completed_payments: 3, line_item: $line );
		$sub->expects( $this->never() )->method( 'update_meta_data' );
		$sub->expects( $this->never() )->method( 'add_order_note' );
		$sub->expects( $this->never() )->method( 'save' );

		$ctx = $this->ctx( $sub, base_price: 10.0 );
		$d   = new Price_Decision( 8.0, Price_Decision::ONE_TIME, 'test', 'Test', 'stepped_by_cycle', 4 );
		$d->policy_id = 'pol_1';

		( new Subscription_Surface() )->apply( $ctx, $d );
	}

	/**
	 * Mock a recurring line item with a configurable subtotal.
	 *
	 * `addMethods()` is required because the wc-mocks `WC_Order_Item_Product`
	 * shim does not declare `get_variation_id`, `set_subtotal`, `set_total`, or
	 * `save` — the real WC class does.
	 */
	private function mock_line_item( float $subtotal ): \WC_Order_Item_Product {
		$line = $this->getMockBuilder( \WC_Order_Item_Product::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_subtotal', 'get_product_id' ] )
			->addMethods( [ 'get_variation_id', 'set_subtotal', 'set_total', 'save' ] )
			->getMock();
		$line->method( 'get_subtotal' )->willReturn( $subtotal );
		$line->method( 'get_product_id' )->willReturn( self::PRODUCT_ID );
		$line->method( 'get_variation_id' )->willReturn( 0 );
		return $line;
	}

	/**
	 * Mock a WC_Subscription with completed payment count and a single line item.
	 *
	 * `addMethods()` covers methods the wc-mocks `WC_Subscription` shim lacks
	 * (`get_payment_count`, `add_order_note`, `calculate_totals`).
	 */
	private function mock_subscription( int $completed_payments = 0, ?\WC_Order_Item_Product $line_item = null ): \WC_Subscription {
		$sub = $this->getMockBuilder( \WC_Subscription::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_user_id', 'get_items', 'get_meta', 'update_meta_data', 'save' ] )
			->addMethods( [ 'get_payment_count', 'add_order_note', 'calculate_totals' ] )
			->getMock();
		$sub->method( 'get_payment_count' )->willReturnCallback(
			function ( $type = 'completed' ) use ( $completed_payments ) {
				return 'completed' === $type ? $completed_payments : 0;
			}
		);
		$sub->method( 'get_user_id' )->willReturn( 0 );
		$sub->method( 'get_items' )->willReturn( $line_item ? [ $line_item ] : [] );
		// Default: no state recorded yet.
		$sub->method( 'get_meta' )->willReturn( '' );
		return $sub;
	}

	/**
	 * Build a Pricing_Context targeting the mocked subscription.
	 *
	 * `addMethods()` adds `get_regular_price`, which the wc-mocks `WC_Product`
	 * shim does not declare. The surface falls back to this when
	 * `WC_Subscriptions_Product::get_price` is unavailable.
	 */
	private function ctx( \WC_Subscription $sub, float $base_price ): Pricing_Context {
		$product = $this->getMockBuilder( \WC_Product::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_id' ] )
			->addMethods( [ 'get_regular_price' ] )
			->getMock();
		$product->method( 'get_id' )->willReturn( 1 );
		$product->method( 'get_regular_price' )->willReturn( $base_price );
		return new Pricing_Context(
			Subscription_Surface::TRIGGER_SCHEDULED_STEP,
			$product,
			null,
			$base_price,
			[ 'completed_cycles' => $sub->get_payment_count( 'completed' ) + 1 ],
			$sub
		);
	}
}
