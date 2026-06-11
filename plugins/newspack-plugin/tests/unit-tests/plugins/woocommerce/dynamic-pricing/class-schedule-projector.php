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
			'$6.00 for 2 renewals, then $10.00 / month',
			WooProduct_Surface::schedule_sentence( $segments, $this->mock_subscription_product() )
		);
	}

	public function test_no_rules_projects_the_regular_price_only() {
		$segments = Schedule_Projector::project_for_cart_item( [ 'data' => $this->mock_subscription_product() ] );
		$this->assertSame( [ [ 'from_cycle' => 1, 'amount' => 10.0 ] ], $segments );
		$this->assertFalse( Schedule_Projector::has_undisclosed_changes( $segments ) );
	}

	public function test_schedule_sentence_narrates_renewal_segments() {
		$segments = [
			[ 'from_cycle' => 1, 'amount' => 5.0 ],
			[ 'from_cycle' => 2, 'amount' => 7.5 ],
			[ 'from_cycle' => 3, 'amount' => 9.0 ],
			[ 'from_cycle' => 4, 'amount' => 10.0 ],
		];
		$sentence = WooProduct_Surface::schedule_sentence( $segments, $this->mock_subscription_product() );
		$this->assertSame( '$7.50 for 1 renewal, then $9.00 for 1 renewal, then $10.00 / month', $sentence );
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
