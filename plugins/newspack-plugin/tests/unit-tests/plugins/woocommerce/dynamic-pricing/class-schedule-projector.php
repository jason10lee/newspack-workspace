<?php
/**
 * Tests for Newspack\Dynamic_Pricing\Schedule_Projector.
 *
 * Wires a real engine (CPT repository + guardrails + matchers + strategies)
 * like the end-to-end smoke test, then projects schedules for cart items.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Schedule_Projector;
use Newspack\Dynamic_Pricing\WooProduct_Surface;
use Newspack\Dynamic_Pricing\Pricing_Engine;
use Newspack\Dynamic_Pricing\CPT_Pricing_Rule_Repository;
use Newspack\Dynamic_Pricing\Pricing_Guardrails;
use Newspack\Dynamic_Pricing\Bounds_Resolver;
use Newspack\Dynamic_Pricing\Matchers\All_Subscriptions_Scope_Matcher;
use Newspack\Dynamic_Pricing\Matchers\Product_Ids_Scope_Matcher;
use Newspack\Dynamic_Pricing\Strategies\Simple_Price_Strategy;
use Newspack\Dynamic_Pricing\Subscriptions\Stepped_By_Cycle_Strategy;
use Newspack\Dynamic_Pricing\Amount_Calculator;

if ( ! function_exists( 'wc_price' ) ) {
	function wc_price( $price ) {
		return '$' . number_format( (float) $price, 2 );
	}
}
if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency() {
		return get_option( 'woocommerce_currency', 'USD' );
	}
}
if ( ! function_exists( 'wc_format_sale_price' ) ) {
	function wc_format_sale_price( $regular, $sale ) {
		return '<del>' . $regular . '</del> <ins>' . $sale . '</ins>';
	}
}

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Schedule_Projector extends WP_UnitTestCase {
	public function set_up() {
		parent::set_up();
		register_post_type( 'shop_pricing_rule', [ 'public' => false, 'show_ui' => false ] );
		wp_cache_flush();

		$engine = Pricing_Engine::instance();
		$engine->reset_for_tests();
		$engine->set_repository( new CPT_Pricing_Rule_Repository() );
		$engine->set_guardrails( new Pricing_Guardrails( new Bounds_Resolver() ) );
		$engine->register_scope( new All_Subscriptions_Scope_Matcher() );
		$engine->register_scope( new Product_Ids_Scope_Matcher() );
		$engine->register( new Stepped_By_Cycle_Strategy() );
		$engine->register( new Simple_Price_Strategy() );
	}

	public function test_multi_step_schedule_projects_all_segments() {
		// Rule 18's shape: 50% → 75% → 90% → 100% on a $10 product.
		$this->seed_rule( [
			'steps' => [
				[ 'at' => 1, 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 50, 'label' => 'Intro' ],
				[ 'at' => 2, 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 75, 'label' => 'Second' ],
				[ 'at' => 3, 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 90, 'label' => 'Third' ],
				[ 'at' => 4, 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 100, 'label' => 'Standard' ],
			],
		], 'stepped_by_cycle' );

		$segments = Schedule_Projector::project_for_cart_item( [ 'data' => $this->mock_subscription_product() ] );

		$this->assertSame(
			[
				[ 'from_cycle' => 1, 'amount' => 5.0 ],
				[ 'from_cycle' => 2, 'amount' => 7.5 ],
				[ 'from_cycle' => 3, 'amount' => 9.0 ],
				[ 'from_cycle' => 4, 'amount' => 10.0 ],
			],
			$segments
		);
		$this->assertTrue( Schedule_Projector::has_undisclosed_changes( $segments ), 'Three distinct renewal prices cannot fit in one recurring-total number.' );
	}

	public function test_flat_unlimited_rule_is_a_single_segment() {
		$this->seed_rule( [ 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 80, 'cycles_limit' => 0, 'label' => '' ], 'simple_price' );

		$segments = Schedule_Projector::project_for_cart_item( [ 'data' => $this->mock_subscription_product() ] );

		$this->assertSame( [ [ 'from_cycle' => 1, 'amount' => 8.0 ] ], $segments );
		$this->assertFalse( Schedule_Projector::has_undisclosed_changes( $segments ), 'A constant price is fully told by the recurring total.' );
	}

	public function test_flat_rule_with_cycles_limit_discloses_the_restore() {
		$this->seed_rule( [ 'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => 6, 'cycles_limit' => 3, 'label' => '' ], 'simple_price' );

		$segments = Schedule_Projector::project_for_cart_item( [ 'data' => $this->mock_subscription_product() ] );

		$this->assertSame(
			[
				[ 'from_cycle' => 1, 'amount' => 6.0 ],
				[ 'from_cycle' => 4, 'amount' => 10.0 ],
			],
			$segments
		);
		// Cycles 2–3 stay $6 (same as the recurring total) but cycle 4 restores:
		// that IS a change beyond the next renewal.
		$this->assertTrue( Schedule_Projector::has_undisclosed_changes( $segments ) );

		// The cycle-1 segment spans renewals 1–2; the sentence must say so.
		$this->assertSame(
			'$6.00 today, then $6.00 for 2 renewals, then $10.00 / month',
			WooProduct_Surface::schedule_sentence( $segments, $this->mock_subscription_product() )
		);
	}

	public function test_no_rules_projects_the_regular_price_only() {
		$segments = Schedule_Projector::project_for_cart_item( [ 'data' => $this->mock_subscription_product() ] );
		$this->assertSame( [ [ 'from_cycle' => 1, 'amount' => 10.0 ] ], $segments );
		$this->assertFalse( Schedule_Projector::has_undisclosed_changes( $segments ) );
	}

	public function test_schedule_sentence_narrates_today_and_every_renewal_segment() {
		$segments = [
			[ 'from_cycle' => 1, 'amount' => 5.0 ],
			[ 'from_cycle' => 2, 'amount' => 7.5 ],
			[ 'from_cycle' => 3, 'amount' => 9.0 ],
			[ 'from_cycle' => 4, 'amount' => 10.0 ],
		];
		$sentence = WooProduct_Surface::schedule_sentence( $segments, $this->mock_subscription_product() );
		$this->assertSame( '$5.00 today, then $7.50 for 1 renewal, then $9.00 for 1 renewal, then $10.00 / month', $sentence );
	}

	public function test_annotation_gated_on_publicize_and_filters_render() {
		$rule_id = $this->seed_rule( [
			'steps' => [
				[ 'at' => 1, 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 50, 'label' => 'Intro' ],
				[ 'at' => 2, 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 75, 'label' => 'Second' ],
			],
		], 'stepped_by_cycle' );
		Pricing_Engine::instance()->add_surface( new WooProduct_Surface() );

		$product   = new \WC_Product( [ 'id' => 17, 'type' => 'subscription', 'regular_price' => 10, 'name' => 'Test Sub' ] );
		$cart_item = [ 'data' => $product, 'key' => 'ann1', 'quantity' => 1 ];
		$cart      = new \WC_Cart( [ 'ann1' => $cart_item ] );

		// Silent rule: applied, audited, but NOT annotated.
		WooProduct_Surface::on_calculate_totals( $cart );
		$this->assertNotNull( WooProduct_Surface::get_applied_for( 'ann1' ), 'Silent rules are still audited.' );
		$this->assertNull( WooProduct_Surface::get_annotation_for( 'ann1' ), 'Silent rules must not annotate.' );
		$this->assertSame( '$5.00 / month', WooProduct_Surface::filter_cart_item_subtotal( '$5.00 / month', $cart_item, 'ann1' ), 'Subtotal untouched for silent rules.' );

		// Publicized rule: annotated with strikethrough + badge + qualifier.
		update_post_meta( $rule_id, '_publicize', '1' );
		CPT_Pricing_Rule_Repository::flush_cache();
		WooProduct_Surface::reset_applied_registry( new \WC_Cart() );
		WooProduct_Surface::on_calculate_totals( $cart );

		$annotation = WooProduct_Surface::get_annotation_for( 'ann1' );
		$this->assertIsArray( $annotation );
		$this->assertSame( 'Intro', $annotation['label'] );

		$subtotal = WooProduct_Surface::filter_cart_item_subtotal( '$5.00 / month', $cart_item, 'ann1' );
		$this->assertStringContainsString( '$10.00', $subtotal, 'Regular price shown for comparison.' );
		$this->assertStringNotContainsString( '/ month', $subtotal, 'The period suffix lies when the purchase price does not recur — it must be stripped.' );
		$this->assertStringContainsString( '$5.00', $subtotal, 'Charged amount preserved.' );
		$this->assertStringContainsString( 'first month', $subtotal, 'Qualifier replaces the stripped period suffix.' );

		$name = WooProduct_Surface::filter_cart_item_name( 'Test Sub', $cart_item, 'ann1' );
		$this->assertStringContainsString( 'newspack-dp-badge', $name );
		$this->assertStringContainsString( 'Intro', $name );

		$payload = WooProduct_Surface::store_api_cart_item_data( $cart_item );
		$this->assertTrue( $payload['publicized'] );
		$this->assertSame( ' — Intro', $payload['name_suffix'] );
		$this->assertSame( ' (regularly $10.00 — first month)', $payload['price_suffix'] );
		$this->assertNotEmpty( $payload['period_suffix'], 'JS must receive the WCS period suffix to strip from the price.' );
	}

	public function test_no_first_cycle_qualifier_when_purchase_price_recurs() {
		$rule_id = $this->seed_rule( [ 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 80, 'cycles_limit' => 0, 'label' => 'Member' ], 'simple_price' );
		update_post_meta( $rule_id, '_publicize', '1' );
		CPT_Pricing_Rule_Repository::flush_cache();
		Pricing_Engine::instance()->add_surface( new WooProduct_Surface() );

		$product   = new \WC_Product( [ 'id' => 17, 'type' => 'subscription', 'regular_price' => 10, 'name' => 'Test Sub' ] );
		$cart_item = [ 'data' => $product, 'key' => 'flat1', 'quantity' => 1 ];
		WooProduct_Surface::on_calculate_totals( new \WC_Cart( [ 'flat1' => $cart_item ] ) );

		$subtotal = WooProduct_Surface::filter_cart_item_subtotal( '$8.00 / month', $cart_item, 'flat1' );
		$this->assertStringContainsString( '$10.00', $subtotal );
		$this->assertStringNotContainsString( 'first month', $subtotal, 'Flat unlimited: the charged price IS the recurring price; no qualifier.' );
		$this->assertStringContainsString( '/ month', $subtotal, 'Flat unlimited: the charged price recurs, so the period suffix must STAY.' );

		$payload = WooProduct_Surface::store_api_cart_item_data( $cart_item );
		$this->assertSame( '', $payload['period_suffix'], 'Flat unlimited: blocks must keep the period suffix on the line.' );
	}

	public function test_store_api_cart_data_emits_sentence_for_multi_step_items_only() {
		$this->seed_rule( [
			'steps' => [
				[ 'at' => 1, 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 50, 'label' => 'Intro' ],
				[ 'at' => 2, 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 75, 'label' => 'Second' ],
				[ 'at' => 3, 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 100, 'label' => 'Standard' ],
			],
		], 'stepped_by_cycle' );

		$product = new \WC_Product( [ 'id' => 17, 'type' => 'subscription', 'regular_price' => 10, 'name' => 'Test Sub' ] );

		// Inject a cart instance for store_api_cart_data() to walk via WC()->cart.
		$cart                   = new \WC_Cart( [ 'k1' => [ 'data' => $product, 'key' => 'k1', 'quantity' => 1 ] ] );
		$wc_singleton           = new \stdClass();
		$wc_singleton->cart     = $cart;
		$wc_singleton->customer = null;
		$GLOBALS['woocommerce'] = $wc_singleton;

		$payload = WooProduct_Surface::store_api_cart_data();

		$this->assertCount( 1, $payload['schedule_sentences'] );
		$this->assertSame( 'k1', $payload['schedule_sentences'][0]['key'] );
		$this->assertSame( 'Test Sub', $payload['schedule_sentences'][0]['item_name'] );
		$this->assertStringStartsWith( '$5.00 today', $payload['schedule_sentences'][0]['sentence'] );
		$this->assertSame( 'Price schedule', $payload['schedule_label'] );

		unset( $GLOBALS['woocommerce'] );
	}

	public function test_store_api_cart_data_omits_items_without_a_multi_segment_schedule() {
		$this->seed_rule( [ 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 80, 'cycles_limit' => 0, 'label' => '' ], 'simple_price' );

		$product               = new \WC_Product( [ 'id' => 17, 'type' => 'subscription', 'regular_price' => 10 ] );
		$cart                  = new \WC_Cart( [ 'k1' => [ 'data' => $product, 'key' => 'k1', 'quantity' => 1 ] ] );
		$wc_singleton          = new \stdClass();
		$wc_singleton->cart    = $cart;
		$GLOBALS['woocommerce'] = $wc_singleton;

		$payload = WooProduct_Surface::store_api_cart_data();
		$this->assertSame( [], $payload['schedule_sentences'], 'Flat unlimited rules: the recurring total tells the whole story.' );

		unset( $GLOBALS['woocommerce'] );
	}

	public function test_recurring_pass_prices_a_clone_not_the_shared_product_instance() {
		// 50% at purchase, 75% from the first renewal.
		$this->seed_rule( [
			'steps' => [
				[ 'at' => 1, 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 50, 'label' => 'Intro' ],
				[ 'at' => 2, 'calc_type' => Amount_Calculator::PERCENT_OF_BASE, 'value' => 75, 'label' => 'Second' ],
			],
		], 'stepped_by_cycle' );
		Pricing_Engine::instance()->add_surface( new WooProduct_Surface() );

		$product = new \WC_Product( [ 'id' => 17, 'type' => 'subscription', 'regular_price' => 10 ] );
		$cart    = new \WC_Cart( [ 'k1' => [ 'data' => $product, 'key' => 'k1', 'quantity' => 1 ] ] );

		// Main pass: the shared instance is priced at cycle 1.
		WooProduct_Surface::on_calculate_totals( $cart );
		$this->assertSame( 5.0, (float) $product->get_price() );

		// Recurring projection pass — WCS shares product objects into the clone.
		\WC_Subscriptions_Cart::set_calculation_type( 'recurring_total' );
		WooProduct_Surface::on_calculate_totals( $cart );
		\WC_Subscriptions_Cart::set_calculation_type( 'none' );

		$this->assertSame( 5.0, (float) $product->get_price(), 'The main cart line-item display re-reads this instance: the projection must not leak into it.' );
		$this->assertNotSame( $product, $cart->cart_contents['k1']['data'], 'The recurring cart must hold a private copy.' );
		$this->assertSame( 7.5, (float) $cart->cart_contents['k1']['data']->get_price(), 'The private copy carries the cycle-2 projection.' );
	}

	private function seed_rule( array $params, string $strategy_id ): int {
		$rule_id = $this->factory->post->create( [ 'post_type' => 'shop_pricing_rule', 'post_status' => 'publish', 'post_title' => 'Test rule' ] );
		update_post_meta( $rule_id, '_strategy_id', $strategy_id );
		update_post_meta( $rule_id, '_scope_type', 'all_subscriptions' );
		update_post_meta( $rule_id, '_params', wp_slash( wp_json_encode( $params ) ) );
		return $rule_id;
	}

	private function mock_subscription_product(): \WC_Product {
		$product = $this->getMockBuilder( \WC_Product::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_id', 'get_type', 'get_regular_price', 'get_meta' ] )
			->getMock();
		$product->method( 'get_id' )->willReturn( 17 );
		$product->method( 'get_type' )->willReturn( 'subscription' );
		$product->method( 'get_regular_price' )->willReturn( '10' );
		$product->method( 'get_meta' )->willReturn( '' ); // WCS shim: price falls back to regular, period to month.
		return $product;
	}
}
