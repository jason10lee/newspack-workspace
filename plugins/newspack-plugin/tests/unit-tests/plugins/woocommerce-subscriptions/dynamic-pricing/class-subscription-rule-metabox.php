<?php
/**
 * Tests for Newspack\Dynamic_Pricing\Subscriptions\Subscription_Rule_Metabox.
 *
 * Render-output assertions against mocked subscriptions; the engine is reset
 * so the next-renewal line (engine-dependent) stays out of these outputs.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Pricing_Engine;
use Newspack\Dynamic_Pricing\Subscriptions\Subscription_Rule_Metabox;

if ( ! function_exists( 'wc_price' ) ) {
	function wc_price( $price ) {
		return '$' . number_format( (float) $price, 2 );
	}
}

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Subscription_Rule_Metabox extends WP_UnitTestCase {
	private const PRODUCT_ID = 4321;

	public function set_up() {
		parent::set_up();
		Pricing_Engine::instance()->reset_for_tests();
		wc_create_mock_product(
			[
				'id'            => self::PRODUCT_ID,
				'type'          => 'subscription',
				'regular_price' => 10,
			]
		);
	}

	public function test_pinned_snapshot_renders_steps_with_position_marker() {
		$sub = $this->mock_subscription( $this->stepped_snapshot( 424242 ), completed_payments: 1 );

		$html = $this->render( $sub );

		$this->assertStringContainsString( 'Locked at purchase', $html );
		$this->assertStringContainsString( 'Test rule', $html );
		$this->assertStringContainsString( 'Snapshotted on', $html );
		$this->assertStringContainsString( '$5.00', $html, 'Step amounts computed against the regular price.' );
		$this->assertStringContainsString( '$7.50', $html );
		$this->assertSame( 1, substr_count( $html, '← next renewal' ), 'Exactly one governing step is marked.' );
		// Upcoming cycle is 2 (1 completed): the marker belongs to the at=2 row, whose price is $7.50.
		$marker_pos = strpos( $html, '← next renewal' );
		$this->assertGreaterThan( strpos( $html, '$7.50' ), $marker_pos, 'Marker sits on the cycle-2 row.' );
		$this->assertLessThan( strpos( $html, '$10.00' ), $marker_pos, 'Marker precedes the cycle-4 row.' );
		// Rule 424242 doesn't exist.
		$this->assertStringContainsString( 'no longer exists', $html );
	}

	public function test_pinned_snapshot_reports_drift_from_live_rule() {
		$rule_id = wp_insert_post(
			[
				'post_type'   => 'shop_pricing_rule',
				'post_status' => 'publish',
				'post_title'  => 'Test rule',
			]
		);
		update_post_meta( $rule_id, '_strategy_id', 'stepped_by_cycle' );
		update_post_meta( $rule_id, '_scope_type', 'all_subscriptions' );
		// Live params differ from the snapshot (90% step instead of 75%).
		update_post_meta( $rule_id, '_params', wp_slash( wp_json_encode( [ 'steps' => [ [ 'at' => 1, 'calc_type' => 'percent_of_base', 'value' => 90, 'label' => '' ] ] ] ) ) );

		$html = $this->render( $this->mock_subscription( $this->stepped_snapshot( $rule_id ) ) );
		$this->assertStringContainsString( 'edited since', $html );

		// Now make the live rule match the snapshot exactly.
		$snapshot = $this->stepped_snapshot( $rule_id );
		update_post_meta( $rule_id, '_params', wp_slash( wp_json_encode( $snapshot['params'] ) ) );
		$html = $this->render( $this->mock_subscription( $snapshot ) );
		$this->assertStringContainsString( 'matches the rule', $html );
	}

	public function test_simple_price_snapshot_renders_phrase_and_limit() {
		$snapshot = [
			'schema_version' => 1,
			'rule_id'        => '424242',
			'pinned_at'      => '2026-06-11 12:00:00',
			'title'          => 'Member deal',
			'strategy_id'    => 'simple_price',
			'params'         => [
				'calc_type'    => 'percent_of_base',
				'value'        => 80,
				'cycles_limit' => 3,
				'label'        => '',
			],
			'priority'       => 100,
			'compose_mode'   => 'min',
			'publicize'      => false,
		];

		$html = $this->render( $this->mock_subscription( $snapshot ) );

		$this->assertStringContainsString( '80% of regular price', $html );
		$this->assertStringContainsString( '$8.00', $html );
		$this->assertStringContainsString( 'first 3 cycles', $html );
	}

	public function test_multi_pin_renders_every_snapshot_with_compose_note() {
		$second                = $this->stepped_snapshot( 424243 );
		$second['title']       = 'Season promo';
		$second['strategy_id'] = 'simple_price';
		$second['params']      = [
			'calc_type'    => 'percent_of_base',
			'value'        => 80,
			'cycles_limit' => 5,
			'label'        => '',
		];

		$line = new \WC_Order_Item_Product(
			[
				'product_id' => self::PRODUCT_ID,
				'quantity'   => 1,
				'meta'       => [ '_newspack_dp_locked_rule' => [ $this->stepped_snapshot( 424242 ), $second ] ],
			]
		);
		$html = $this->render( $this->mock_subscription_with_line( $line ) );

		$this->assertStringContainsString( '2 rules locked at purchase', $html );
		$this->assertStringContainsString( 'Test rule', $html );
		$this->assertStringContainsString( 'Season promo', $html );
		$this->assertStringContainsString( '80% of regular price', $html );
		$this->assertStringContainsString( 'first 5 cycles', $html );
	}

	public function test_always_current_attribution_without_snapshot() {
		$rule_id = wp_insert_post(
			[
				'post_type'   => 'shop_pricing_rule',
				'post_status' => 'publish',
				'post_title'  => 'Live rule',
			]
		);

		$line = new \WC_Order_Item_Product(
			[
				'product_id' => self::PRODUCT_ID,
				'quantity'   => 1,
				'meta'       => [ '_newspack_dp_rule_id' => (string) $rule_id ],
			]
		);
		$sub  = $this->mock_subscription_with_line( $line );

		$html = $this->render( $sub );

		$this->assertStringContainsString( 'Always current', $html );
		$this->assertStringContainsString( 'Live rule', $html );
		$this->assertStringNotContainsString( 'Locked at purchase', $html );
	}

	private function render( $sub ): string {
		ob_start();
		Subscription_Rule_Metabox::render( $sub );
		return (string) ob_get_clean();
	}

	private function stepped_snapshot( $rule_id ): array {
		return [
			'schema_version' => 1,
			'rule_id'        => (string) $rule_id,
			'pinned_at'      => '2026-06-11 12:00:00',
			'title'          => 'Test rule',
			'strategy_id'    => 'stepped_by_cycle',
			'params'         => [
				'steps' => [
					[ 'at' => 1, 'calc_type' => 'percent_of_base', 'value' => 50, 'label' => 'Intro' ],
					[ 'at' => 2, 'calc_type' => 'percent_of_base', 'value' => 75, 'label' => 'Second' ],
					[ 'at' => 4, 'calc_type' => 'percent_of_base', 'value' => 100, 'label' => 'Standard' ],
				],
			],
			'priority'       => 100,
			'compose_mode'   => 'min',
			'publicize'      => true,
		];
	}

	private function mock_subscription( array $snapshot, int $completed_payments = 1 ): \WC_Subscription {
		$line = new \WC_Order_Item_Product(
			[
				'product_id' => self::PRODUCT_ID,
				'quantity'   => 1,
				'meta'       => [
					'_newspack_dp_locked_rule' => $snapshot,
					'_newspack_dp_rule_id'     => (string) $snapshot['rule_id'],
				],
			]
		);
		return $this->mock_subscription_with_line( $line, $completed_payments );
	}

	private function mock_subscription_with_line( \WC_Order_Item_Product $line, int $completed_payments = 1 ): \WC_Subscription {
		$sub = $this->getMockBuilder( \WC_Subscription::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_items', 'get_meta' ] )
			->addMethods( [ 'get_payment_count' ] )
			->getMock();
		$sub->method( 'get_items' )->willReturn( [ $line ] );
		$sub->method( 'get_meta' )->willReturn( '' );
		$sub->method( 'get_payment_count' )->willReturn( $completed_payments );
		return $sub;
	}
}
