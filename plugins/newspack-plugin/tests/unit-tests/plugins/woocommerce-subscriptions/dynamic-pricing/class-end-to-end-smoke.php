<?php
/**
 * End-to-end smoke test for the dynamic pricing foundation.
 *
 * Walks a real `$1 → $8 → $10` policy through the engine at cycles 1, 3, 4, 12.
 * Wires real engine + CPT_Policy_Repository + Pricing_Guardrails + scope matchers
 * + Stepped_By_Cycle_Strategy + Subscription_Surface; only the subscription
 * target and its line item are PHPUnit mocks, because the wc-mocks
 * `WC_Subscription` shim does not implement `get_payment_count`,
 * `calculate_totals`, `add_order_note`, and the `WC_Order_Item_Product` shim
 * does not implement `set_subtotal`, `set_total`, `save`, `get_variation_id`
 * (see Task 12 surface test for the same adaptation).
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Pricing_Engine;
use Newspack\Dynamic_Pricing\CPT_Policy_Repository;
use Newspack\Dynamic_Pricing\Pricing_Guardrails;
use Newspack\Dynamic_Pricing\Bounds_Resolver;
use Newspack\Dynamic_Pricing\Matchers\All_Subscriptions_Scope_Matcher;
use Newspack\Dynamic_Pricing\Matchers\Product_Ids_Scope_Matcher;
use Newspack\Dynamic_Pricing\Subscriptions\Subscription_Surface;
use Newspack\Dynamic_Pricing\Subscriptions\Stepped_By_Cycle_Strategy;
use Newspack\Dynamic_Pricing\Amount_Calculator;
use Newspack\Dynamic_Pricing\Price_Decision;

// Helpers the foundation classes call that the test bootstrap does not provide.
if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency() {
		return get_option( 'woocommerce_currency', 'USD' );
	}
}
if ( ! function_exists( 'wc_get_product_term_ids' ) ) {
	function wc_get_product_term_ids( $product_id, $taxonomy ) {
		$terms = get_the_terms( $product_id, $taxonomy );
		return ( empty( $terms ) || is_wp_error( $terms ) ) ? [] : wp_list_pluck( $terms, 'term_id' );
	}
}
if ( ! function_exists( 'wc_price' ) ) {
	function wc_price( $price ) {
		return '$' . number_format( (float) $price, 2 );
	}
}

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Dynamic_Pricing_End_To_End extends WP_UnitTestCase {
	/** @var int CPT product id used by the policy scope and `wc_get_product()` lookup. */
	private const PRODUCT_ID = 4242;

	/** @var float Catalog base price the line item starts at and the strategy compares to. */
	private const BASE_PRICE = 1.0;

	/** @var int Policy id created via WP factory. */
	private int $policy_id;

	/** @var \WC_Order_Item_Product&\PHPUnit\Framework\MockObject\MockObject */
	private $line;

	/** @var \WC_Subscription&\PHPUnit\Framework\MockObject\MockObject */
	private $sub;

	/** @var int Per-test view of how many cycles have been paid. Drives `get_payment_count`. */
	private int $completed_payments = 0;

	/** @var float Per-test view of the current line subtotal. Drives `get_subtotal`. */
	private float $line_subtotal = self::BASE_PRICE;

	/** @var array Per-test view of the `_newspack_dynamic_pricing_state` map. */
	private array $state_meta = [];

	public function set_up() {
		parent::set_up();
		register_post_type( 'shop_pricing_policy', [ 'public' => false, 'show_ui' => false ] );
		wp_cache_flush();

		// Seed wc-mocks product database so Subscription_Surface::context()'s
		// wc_get_product( $product_id ) returns the same instance the policy scopes to.
		// `_subscription_price` is what WC_Subscriptions_Product::get_price() reads to
		// drive Pricing_Context::base_price (the amount the cycle-1 step resolves equal
		// to, exercising the apply()-side no-op guard on the first walk step).
		wc_create_mock_product( [
			'id'   => self::PRODUCT_ID,
			'type' => 'subscription',
			'meta' => [ '_subscription_price' => self::BASE_PRICE ],
		] );

		$engine = Pricing_Engine::instance();
		$engine->reset_for_tests();
		$engine->set_repository( new CPT_Policy_Repository() );
		$engine->set_guardrails( new Pricing_Guardrails( new Bounds_Resolver() ) );
		$engine->register_scope( new All_Subscriptions_Scope_Matcher() );
		$engine->register_scope( new Product_Ids_Scope_Matcher() );
		$engine->add_surface( new Subscription_Surface() );
		$engine->register( new Stepped_By_Cycle_Strategy() );

		$this->policy_id          = $this->seed_policy( self::PRODUCT_ID );
		$this->line               = $this->mock_line_item();
		$this->sub                = $this->mock_subscription( $this->line );
		$this->completed_payments = 0;
		$this->line_subtotal      = self::BASE_PRICE;
		$this->state_meta         = [];
	}

	/**
	 * Walk the policy through cycles 1 → 3 → 4 → 12 and assert the engine + surface
	 * produce the expected sequence of (decision, persistence, short-circuit, decision).
	 */
	public function test_full_lifecycle() {
		/** @var Subscription_Surface $surface */
		$surface = Pricing_Engine::instance()->surface( 'subscription' );
		$this->assertNotNull( $surface, 'Subscription surface must be registered after bootstrap wiring.' );

		// --- Cycle 1 paid: upcoming=2; step_at_1 ($1) === base. On this price-persisting
		// surface the strategy still EMITS the decision (abstaining would leave a prior
		// write in place forever); the surface's apply() is the no-op layer, bailing on
		// the subtotal-equality guard before any writes.
		$this->completed_payments = 1;
		$ctx = $surface->context( $this->sub, Subscription_Surface::TRIGGER_SCHEDULED_STEP );
		$this->assertSame( 2, $ctx->signals['completed_cycles'] );
		$this->assertTrue( $ctx->persists_price, 'Subscription surface contexts must declare price persistence.' );
		$d = Pricing_Engine::instance()->resolve( $ctx );
		$this->assertNotNull( $d, 'Strategy must emit base-equal decisions on a price-persisting surface.' );
		$this->assertSame( 1.0, $d->amount );

		$surface->apply( $ctx, $d );
		$this->assertSame( self::BASE_PRICE, $this->line_subtotal, 'Apply must short-circuit when subtotal already matches.' );
		$this->assertSame( [], $this->state_meta, 'No-op apply must not record state.' );

		// --- Cycle 3 paid: upcoming=4; step_at_4 ($8) ≠ base ⇒ persist $8. ---
		$this->completed_payments = 3;
		$ctx = $surface->context( $this->sub, Subscription_Surface::TRIGGER_SCHEDULED_STEP );
		$this->assertSame( 4, $ctx->signals['completed_cycles'] );
		$d = Pricing_Engine::instance()->resolve( $ctx );
		$this->assertNotNull( $d, 'A decision is expected at cycle 4 (step_at_4 triggers).' );
		$this->assertSame( 8.0, $d->amount );
		$this->assertSame( (string) $this->policy_id, $d->policy_id );
		$this->assertSame( 'stepped_by_cycle', $d->strategy_id );
		$this->assertSame( 4, $d->dimension_value );

		$surface->apply( $ctx, $d );
		$this->assertSame( 8.0, $this->line_subtotal, 'Line subtotal must persist as $8 after apply.' );
		$this->assertArrayHasKey( (string) $this->policy_id, $this->state_meta );
		$this->assertSame( 4, $this->state_meta[ (string) $this->policy_id ]['dimension_value'] );
		$this->assertSame( 8.0, $this->state_meta[ (string) $this->policy_id ]['amount'] );

		// --- Cycle 4 paid: upcoming=5; line is already $8; amount-equality short-circuit. ---
		// step_at_4 fires again at cycle 5 with the same amount, so apply() must bail at
		// the `abs(line subtotal - amount) < 0.01` guard before mutating state.
		$this->completed_payments = 4;
		$state_before             = $this->state_meta;
		$ctx                      = $surface->context( $this->sub, Subscription_Surface::TRIGGER_SCHEDULED_STEP );
		$d                        = Pricing_Engine::instance()->resolve( $ctx );
		$this->assertNotNull( $d, 'Strategy still produces a decision at cycle 5; surface must short-circuit.' );
		$this->assertSame( 8.0, $d->amount );

		$surface->apply( $ctx, $d );
		$this->assertSame( $state_before, $this->state_meta, 'Short-circuit must leave state map unchanged.' );
		$this->assertSame( 8.0, $this->line_subtotal, 'Short-circuit must leave line subtotal unchanged.' );

		// --- Cycle 12 paid: upcoming=13; step_at_13 ($10) ≠ $8 ⇒ persist $10. ---
		$this->completed_payments = 12;
		$ctx = $surface->context( $this->sub, Subscription_Surface::TRIGGER_SCHEDULED_STEP );
		$this->assertSame( 13, $ctx->signals['completed_cycles'] );
		$d = Pricing_Engine::instance()->resolve( $ctx );
		$this->assertNotNull( $d );
		$this->assertSame( 10.0, $d->amount );
		$this->assertSame( 13, $d->dimension_value );

		$surface->apply( $ctx, $d );
		$this->assertSame( 10.0, $this->line_subtotal, 'Line subtotal must persist as $10 after final step.' );
		$this->assertSame( 13, $this->state_meta[ (string) $this->policy_id ]['dimension_value'] );
		$this->assertSame( 10.0, $this->state_meta[ (string) $this->policy_id ]['amount'] );
	}

	/**
	 * Seed a stepped_by_cycle policy at the canonical $1 → $8 → $10 ramp.
	 */
	private function seed_policy( int $product_id ): int {
		$policy_id = $this->factory->post->create( [
			'post_type'   => 'shop_pricing_policy',
			'post_status' => 'publish',
			'post_title'  => 'Intro ramp',
		] );
		update_post_meta( $policy_id, '_strategy_id', 'stepped_by_cycle' );
		update_post_meta( $policy_id, '_priority', 100 );
		update_post_meta( $policy_id, '_compose_mode', 'min' );
		update_post_meta( $policy_id, '_scope_type', 'product_ids' );
		add_post_meta( $policy_id, '_scope_product_id', $product_id );
		update_post_meta(
			$policy_id,
			'_params',
			wp_json_encode( [
				'steps' => [
					[ 'at' => 1,  'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => 1,  'label' => 'Intro' ],
					[ 'at' => 4,  'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => 8,  'label' => 'Standard' ],
					[ 'at' => 13, 'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => 10, 'label' => 'Year 2' ],
				],
			] )
		);
		wp_cache_flush();
		return $policy_id;
	}

	/**
	 * Mocked recurring line item.
	 *
	 * Captures `set_subtotal` + `save` into `$this->line_subtotal` so the test can
	 * read back persisted state across cycles. `get_subtotal` returns the live
	 * value, which is what powers the amount-equality short-circuit in apply().
	 *
	 * @return \WC_Order_Item_Product&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function mock_line_item(): \WC_Order_Item_Product {
		$line = $this->getMockBuilder( \WC_Order_Item_Product::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_subtotal', 'get_product_id' ] )
			->addMethods( [ 'get_variation_id', 'set_subtotal', 'set_total', 'save' ] )
			->getMock();
		$line->method( 'get_subtotal' )->willReturnCallback( fn() => $this->line_subtotal );
		$line->method( 'get_product_id' )->willReturn( self::PRODUCT_ID );
		$line->method( 'get_variation_id' )->willReturn( 0 );
		$line->method( 'set_subtotal' )->willReturnCallback(
			function ( $value ) {
				$this->line_subtotal = (float) $value;
			}
		);
		$line->method( 'set_total' )->willReturnCallback(
			function ( $value ) {
				// set_subtotal already mirrors here; nothing extra to capture.
			}
		);
		return $line;
	}

	/**
	 * Mocked WC_Subscription.
	 *
	 * Wires `get_payment_count` and the state-map meta read/write so the engine +
	 * surface can drive a full cycle progression. `get_currency` matches
	 * get_woocommerce_currency() so the engine's currency-mismatch exclusion is a no-op.
	 *
	 * @param \WC_Order_Item_Product&\PHPUnit\Framework\MockObject\MockObject $line Line item to return from get_items().
	 * @return \WC_Subscription&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function mock_subscription( \WC_Order_Item_Product $line ): \WC_Subscription {
		$sub = $this->getMockBuilder( \WC_Subscription::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_id', 'get_user_id', 'get_items', 'get_meta', 'update_meta_data', 'get_currency', 'save' ] )
			->addMethods( [ 'get_payment_count', 'add_order_note', 'calculate_totals' ] )
			->getMock();
		$sub->method( 'get_id' )->willReturn( 9999 );
		$sub->method( 'get_user_id' )->willReturn( 0 );
		$sub->method( 'get_currency' )->willReturn( get_woocommerce_currency() );
		$sub->method( 'get_items' )->willReturn( [ $line ] );
		$sub->method( 'get_payment_count' )->willReturnCallback(
			fn( $type = 'completed' ) => 'completed' === $type ? $this->completed_payments : 0
		);
		$sub->method( 'get_meta' )->willReturnCallback(
			fn( $key ) => Subscription_Surface::STATE_META_KEY === $key ? $this->state_meta : ''
		);
		$sub->method( 'update_meta_data' )->willReturnCallback(
			function ( $key, $value ) {
				if ( Subscription_Surface::STATE_META_KEY === $key ) {
					$this->state_meta = is_array( $value ) ? $value : [];
				}
			}
		);
		return $sub;
	}
}
