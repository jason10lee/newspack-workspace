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
		$this->assertSame( Pricing_Context::INTENT_RENEWAL, $ctx->intent );
		$this->assertTrue( $ctx->persists_price, 'Stateful surface contexts must declare price persistence.' );
	}

	public function test_apply_persists_amount_onto_recurring_line_item() {
		$line = $this->mock_line_item( 10.0 );
		// Discount split: subtotal carries the regular price, total the charged.
		$line->expects( $this->once() )->method( 'set_subtotal' )->with( 10.0 );
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
		$d->rule_id = 'pol_1';

		( new Subscription_Surface() )->apply( $ctx, $d );

		$this->assertIsArray( $captured_state );
		$this->assertArrayHasKey( 'pol_1', $captured_state );
		$this->assertSame( 4, $captured_state['pol_1']['dimension_value'] );
		$this->assertSame( 8.0, $captured_state['pol_1']['amount'] );
		$this->assertSame( 'stepped_by_cycle', $captured_state['pol_1']['strategy_id'] );
		$this->assertSame( 'step_at_4_fixed_price', $captured_state['pol_1']['reason'] );
	}

	public function test_apply_multiplies_per_unit_amount_by_line_quantity() {
		// Decision amounts are per unit; a qty-3 line must total 3 × $8 = $24,
		// with the subtotal carrying 3 × $10 regular.
		$line = $this->mock_line_item( 30.0, 3 );
		$line->expects( $this->once() )->method( 'set_subtotal' )->with( 30.0 );
		$line->expects( $this->once() )->method( 'set_total' )->with( 24.0 );
		$line->expects( $this->once() )->method( 'save' );

		$sub = $this->mock_subscription( completed_payments: 3, line_item: $line );

		$ctx = $this->ctx( $sub, base_price: 10.0 );
		$d   = new Price_Decision( 8.0, Price_Decision::DURABLE, 'step_at_4_fixed_price', 'Standard', 'stepped_by_cycle', 4 );
		$d->rule_id = 'pol_1';

		( new Subscription_Surface() )->apply( $ctx, $d );
	}

	public function test_apply_restore_writes_equal_subtotal() {
		// Restoring to the regular price ends the split — subtotal == total == regular.
		$line = $this->mock_line_item( 8.0 );
		$line->expects( $this->once() )->method( 'set_subtotal' )->with( 10.0 );
		$line->expects( $this->once() )->method( 'set_total' )->with( 10.0 );
		$line->expects( $this->once() )->method( 'save' );

		$sub = $this->mock_subscription( completed_payments: 3, line_item: $line );

		$ctx = $this->ctx( $sub, base_price: 10.0 );
		$d   = new Price_Decision( 10.0, Price_Decision::DURABLE, 'restore_base_after_3_cycles', '', 'simple_price', 4 );
		$d->rule_id = 'pol_1';

		( new Subscription_Surface() )->apply( $ctx, $d );
	}

	public function test_apply_refreshes_rule_id_attribution_meta() {
		$line = $this->mock_line_item( 10.0 );
		$captured_meta = [];
		$line->method( 'update_meta_data' )->willReturnCallback(
			function ( $key, $value ) use ( &$captured_meta ) {
				$captured_meta[ $key ] = $value;
			}
		);

		$sub = $this->mock_subscription( completed_payments: 3, line_item: $line );

		$ctx = $this->ctx( $sub, base_price: 10.0 );
		$d   = new Price_Decision( 7.5, Price_Decision::DURABLE, 'step_at_2_percent_of_base', 'Second', 'stepped_by_cycle', 2 );
		$d->rule_id = '18';

		( new Subscription_Surface() )->apply( $ctx, $d );

		$this->assertSame( '18', $captured_meta[ \Newspack\Dynamic_Pricing\WooProduct_Surface::LINE_META_RULE_ID ] ?? null );
	}

	public function test_apply_quantity_aware_idempotency_short_circuit() {
		// Line already aggregates 3 × $8 = $24 — applying the same $8/unit decision
		// must be a no-op even though 24 ≠ 8 (the per-unit amount).
		$line = $this->mock_line_item( 24.0, 3 );
		$line->expects( $this->never() )->method( 'set_subtotal' );
		$line->expects( $this->never() )->method( 'save' );

		$sub = $this->mock_subscription( completed_payments: 3, line_item: $line );
		$sub->expects( $this->never() )->method( 'save' );

		$ctx = $this->ctx( $sub, base_price: 10.0 );
		$d   = new Price_Decision( 8.0, Price_Decision::DURABLE, 'step_at_4_fixed_price', 'Standard', 'stepped_by_cycle', 5 );
		$d->rule_id = 'pol_1';

		( new Subscription_Surface() )->apply( $ctx, $d );
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
		$d->rule_id = 'pol_1';

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
		$d->rule_id = 'pol_1';

		( new Subscription_Surface() )->apply( $ctx, $d );
	}

	public function test_note_acquisition_on_subscription_notes_policy_priced_lines() {
		// Seed the cart surface's applied registry the way checkout would: via apply().
		\Newspack\Dynamic_Pricing\WooProduct_Surface::reset_applied_registry( new \WC_Cart() );
		$product = $this->getMockBuilder( \WC_Product::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'set_price' ] )
			->getMock();
		$ctx = new Pricing_Context(
			'cart',
			$product,
			null,
			10.0,
			[ 'completed_cycles' => 1 ],
			[ 'data' => $product, 'key' => 'sub_note_key' ]
		);
		$d = new Price_Decision( 5.0, Price_Decision::DURABLE, 'step_at_1_fixed_price', 'Intro', 'stepped_by_cycle', 1 );
		$d->rule_id = '18';

		( new \Newspack\Dynamic_Pricing\WooProduct_Surface() )->apply( $ctx, $d );

		$recurring_cart = new \WC_Cart( [ 'sub_note_key' => [ 'data' => $product ] ] );

		$line = $this->mock_line_item( 5.0 );
		$sub  = $this->mock_subscription( completed_payments: 0, line_item: $line );
		$sub->expects( $this->once() )
			->method( 'add_order_note' )
			->with( $this->logicalAnd( $this->stringContains( '[rule 18]' ), $this->stringContains( 'initial purchase' ) ) );

		Subscription_Surface::note_acquisition_on_subscription( $sub, null, $recurring_cart );
	}

	/**
	 * Wire a real engine so apply() captures `locked_snapshots` through the
	 * matching pipeline, mirroring checkout. Returns a subscription-typed
	 * product mock the scope matcher accepts.
	 */
	private function wire_engine_for_pinning(): \WC_Product {
		register_post_type( 'shop_pricing_rule', [ 'public' => false ] );
		\Newspack\Dynamic_Pricing\CPT_Pricing_Rule_Repository::flush_cache();
		$engine = \Newspack\Dynamic_Pricing\Pricing_Engine::instance();
		$engine->reset_for_tests();
		$engine->set_repository( new \Newspack\Dynamic_Pricing\CPT_Pricing_Rule_Repository() );
		$engine->set_guardrails( new \Newspack\Dynamic_Pricing\Pricing_Guardrails( new \Newspack\Dynamic_Pricing\Bounds_Resolver() ) );
		$engine->register_scope( new \Newspack\Dynamic_Pricing\Matchers\All_Subscriptions_Scope_Matcher() );
		$engine->register( new \Newspack\Dynamic_Pricing\Subscriptions\Stepped_By_Cycle_Strategy() );
		$engine->register( new \Newspack\Dynamic_Pricing\Strategies\Simple_Price_Strategy() );

		$product = $this->getMockBuilder( \WC_Product::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_id', 'get_type', 'get_regular_price', 'get_meta', 'get_name', 'set_price' ] )
			->getMock();
		$product->method( 'get_id' )->willReturn( 99001 );
		$product->method( 'get_type' )->willReturn( 'subscription' );
		$product->method( 'get_regular_price' )->willReturn( '10' );
		$product->method( 'get_meta' )->willReturn( '' );
		$product->method( 'get_name' )->willReturn( 'Pin product' );
		return $product;
	}

	private function seed_locked_rule( string $title, array $params, string $strategy = 'stepped_by_cycle' ): int {
		$rule_id = $this->factory->post->create( [ 'post_type' => 'shop_pricing_rule', 'post_status' => 'publish', 'post_title' => $title ] );
		update_post_meta( $rule_id, '_strategy_id', $strategy );
		update_post_meta( $rule_id, '_scope_type', 'all_subscriptions' );
		update_post_meta( $rule_id, '_params', wp_slash( wp_json_encode( $params ) ) );
		\Newspack\Dynamic_Pricing\CPT_Pricing_Rule_Repository::flush_cache();
		return $rule_id;
	}

	public function test_pin_deal_on_subscription_snapshots_every_matching_locked_rule() {
		\Newspack\Dynamic_Pricing\WooProduct_Surface::reset_applied_registry( new \WC_Cart() );
		$product = $this->wire_engine_for_pinning();

		// TWO locked rules match: the cycle-1 winner AND a flat rule that only
		// governs later cycles. Both belong in the pin (docs 08) — the
		// checkout schedule composed both.
		$stepped_id = $this->seed_locked_rule( 'Intro ramp', [ 'steps' => [ [ 'at' => 1, 'calc_type' => 'fixed_price', 'value' => 5, 'label' => 'Intro' ] ] ] );
		$flat_id    = $this->seed_locked_rule( 'Season promo', [ 'calc_type' => 'percent_of_base', 'value' => 80, 'cycles_limit' => 5, 'label' => '' ], 'simple_price' );

		// Seed the applied registry as checkout would.
		$ctx = new Pricing_Context( 'cart', $product, null, 10.0, [ 'completed_cycles' => 1 ], [ 'data' => $product, 'key' => 'pin_key' ] );
		$d   = new Price_Decision( 5.0, Price_Decision::DURABLE, 'step_at_1_fixed_price', 'Intro', 'stepped_by_cycle', 1 );
		$d->rule_id = (string) $stepped_id;
		( new \Newspack\Dynamic_Pricing\WooProduct_Surface() )->apply( $ctx, $d );

		$line = $this->getMockBuilder( \WC_Order_Item_Product::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_product_id' ] )
			->addMethods( [ 'get_variation_id', 'update_meta_data', 'save' ] )
			->getMock();
		$line->method( 'get_variation_id' )->willReturn( 0 );
		$line->method( 'get_product_id' )->willReturn( 0 );
		$captured = null;
		$line->expects( $this->once() )->method( 'update_meta_data' )->willReturnCallback(
			function ( $key, $value ) use ( &$captured ) {
				if ( \Newspack\Dynamic_Pricing\Subscription_Pin::LOCKED_RULE_META_KEY === $key ) {
					$captured = $value;
				}
			}
		);
		$line->expects( $this->once() )->method( 'save' );

		$sub = $this->mock_subscription( completed_payments: 0, line_item: $line );
		$sub->expects( $this->once() )->method( 'add_order_note' )->with( $this->stringContains( 'terms locked at purchase' ) );

		Subscription_Surface::pin_rule_on_subscription( $sub, null, new \WC_Cart( [ 'pin_key' => [ 'data' => $product ] ] ) );

		$this->assertIsArray( $captured );
		$this->assertCount( 2, $captured, 'BOTH matching locked rules are pinned — the composed deal, not just the cycle-1 winner.' );
		$pinned_ids = array_map( fn( array $s ): string => $s['rule_id'], $captured );
		$this->assertContains( (string) $stepped_id, $pinned_ids );
		$this->assertContains( (string) $flat_id, $pinned_ids );
		$this->assertSame( 1, $captured[0]['schema_version'] );

		\Newspack\Dynamic_Pricing\Pricing_Engine::instance()->reset_for_tests();
	}

	public function test_pin_deal_on_subscription_skips_live_policies() {
		\Newspack\Dynamic_Pricing\WooProduct_Surface::reset_applied_registry( new \WC_Cart() );
		$product = $this->wire_engine_for_pinning();

		// A current-application rule matches — it must NOT be pinned.
		$rule_id = $this->seed_locked_rule( 'Live promo', [ 'calc_type' => 'percent_of_base', 'value' => 80, 'cycles_limit' => 0, 'label' => '' ], 'simple_price' );
		update_post_meta( $rule_id, '_application', 'current' );
		\Newspack\Dynamic_Pricing\CPT_Pricing_Rule_Repository::flush_cache();

		$ctx = new Pricing_Context( 'cart', $product, null, 10.0, [ 'completed_cycles' => 1 ], [ 'data' => $product, 'key' => 'live_key' ] );
		$d   = new Price_Decision( 8.0, Price_Decision::DURABLE, 'r', 'l', 'simple_price', 1 );
		$d->rule_id = (string) $rule_id;
		( new \Newspack\Dynamic_Pricing\WooProduct_Surface() )->apply( $ctx, $d );

		$line = $this->getMockBuilder( \WC_Order_Item_Product::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_product_id' ] )
			->addMethods( [ 'get_variation_id', 'update_meta_data', 'save' ] )
			->getMock();
		$line->expects( $this->never() )->method( 'update_meta_data' );

		$sub = $this->mock_subscription( completed_payments: 0, line_item: $line );

		Subscription_Surface::pin_rule_on_subscription( $sub, null, new \WC_Cart( [ 'live_key' => [ 'data' => $product ] ] ) );

		\Newspack\Dynamic_Pricing\Pricing_Engine::instance()->reset_for_tests();
	}

	public function test_on_payment_complete_bails_when_product_is_deleted() {
		// Mock subscription with a line item that points at a non-existent product
		// (wc_get_product returns false). The guard in on_payment_complete() must
		// catch this before Pricing_Context's non-nullable $product constructor
		// param triggers a TypeError.
		$item = $this->getMockBuilder( \WC_Order_Item_Product::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_product_id' ] )
			->addMethods( [ 'get_variation_id' ] )
			->getMock();
		$item->method( 'get_variation_id' )->willReturn( 0 );
		$item->method( 'get_product_id' )->willReturn( 999999 ); // does not exist.
		$sub = $this->getMockBuilder( \WC_Subscription::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_items' ] )
			->addMethods( [ 'get_payment_count' ] )
			->getMock();
		$sub->method( 'get_items' )->willReturn( [ $item ] );

		// Wire up the singleton with this surface registered so on_payment_complete can find it.
		$engine = \Newspack\Dynamic_Pricing\Pricing_Engine::instance();
		$engine->reset_for_tests();
		$engine->add_surface( new Subscription_Surface() );

		// Must not throw.
		Subscription_Surface::on_payment_complete( $sub );
		$this->assertTrue( true ); // reached without TypeError.
	}

	/**
	 * Mock a recurring line item with a configurable subtotal.
	 *
	 * `addMethods()` is required because the wc-mocks `WC_Order_Item_Product`
	 * shim does not declare `get_variation_id`, `set_subtotal`, `set_total`, or
	 * `save` — the real WC class does.
	 */
	private function mock_line_item( float $total, int $quantity = 1 ): \WC_Order_Item_Product {
		$line = $this->getMockBuilder( \WC_Order_Item_Product::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_total', 'get_subtotal', 'get_product_id', 'get_quantity' ] )
			->addMethods( [ 'get_variation_id', 'set_subtotal', 'set_total', 'save', 'update_meta_data', 'delete_meta_data' ] )
			->getMock();
		$line->method( 'get_total' )->willReturn( $total );
		$line->method( 'get_subtotal' )->willReturn( $total );
		$line->method( 'get_product_id' )->willReturn( self::PRODUCT_ID );
		$line->method( 'get_quantity' )->willReturn( $quantity );
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
	 */
	private function ctx( \WC_Subscription $sub, float $base_price ): Pricing_Context {
		$product = $this->getMockBuilder( \WC_Product::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_id', 'get_regular_price' ] )
			->getMock();
		$product->method( 'get_id' )->willReturn( 1 );
		$product->method( 'get_regular_price' )->willReturn( $base_price );
		return new Pricing_Context(
			Subscription_Surface::TRIGGER_SCHEDULED_STEP,
			$product,
			null,
			$base_price,
			[ 'completed_cycles' => $sub->get_payment_count( 'completed' ) + 1 ],
			$sub,
			Pricing_Context::INTENT_RENEWAL,
			true
		);
	}
}
