<?php
/**
 * Tests for Newspack\Dynamic_Pricing\Pricing_Rule.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Pricing_Rule;

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Pricing_Rule extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		if ( ! post_type_exists( 'shop_pricing_rule' ) ) {
			register_post_type( 'shop_pricing_rule', [ 'public' => false ] );
		}
	}

	public function test_from_post_reads_meta_into_properties() {
		$post_id = $this->factory->post->create( [ 'post_type' => 'shop_pricing_rule', 'post_status' => 'publish', 'post_title' => 'Test' ] );
		update_post_meta( $post_id, '_strategy_id', 'stepped_by_cycle' );
		update_post_meta( $post_id, '_priority', 100 );
		update_post_meta( $post_id, '_compose_mode', 'min' );
		update_post_meta( $post_id, '_scope_type', 'product_ids' );
		add_post_meta( $post_id, '_scope_product_id', 42 );
		add_post_meta( $post_id, '_scope_product_id', 99 );
		update_post_meta( $post_id, '_params', wp_json_encode( [ 'steps' => [ [ 'at' => 1, 'value' => 1 ] ] ] ) );

		$rule = Pricing_Rule::from_post( get_post( $post_id ) );

		$this->assertSame( (string) $post_id, $rule->id );
		$this->assertSame( 'Test', $rule->title );
		$this->assertSame( 'stepped_by_cycle', $rule->strategy_id );
		$this->assertSame( 100, $rule->priority );
		$this->assertSame( 'min', $rule->compose_mode );
		$this->assertSame( 'product_ids', $rule->scope_type );
		$this->assertSame( [ 42, 99 ], $rule->scope_ids );
		$this->assertSame( [ 'steps' => [ [ 'at' => 1, 'value' => 1 ] ] ], $rule->params );
		$this->assertSame( [], $rule->conditions );
		$this->assertNull( $rule->active_from );
		$this->assertNull( $rule->active_until );
	}

	public function test_publicize_defaults_false_and_reads_meta() {
		$post_id = $this->factory->post->create( [ 'post_type' => 'shop_pricing_rule' ] );
		update_post_meta( $post_id, '_strategy_id', 'stepped_by_cycle' );
		$silent = Pricing_Rule::from_post( get_post( $post_id ) );
		$this->assertFalse( $silent->publicize, 'publicize defaults to false when meta absent.' );

		update_post_meta( $post_id, '_publicize', '1' );
		$loud = Pricing_Rule::from_post( get_post( $post_id ) );
		$this->assertTrue( $loud->publicize, 'publicize reads true from _publicize meta.' );
	}

	public function test_compose_mode_defaults_to_min_when_meta_absent() {
		$post_id = $this->factory->post->create( [ 'post_type' => 'shop_pricing_rule' ] );
		update_post_meta( $post_id, '_strategy_id', 'stepped_by_cycle' );
		$rule = Pricing_Rule::from_post( get_post( $post_id ) );
		$this->assertSame( 'min', $rule->compose_mode );
	}

	public function test_application_defaults_to_locked_and_reads_current() {
		$post_id = $this->factory->post->create( [ 'post_type' => 'shop_pricing_rule' ] );
		update_post_meta( $post_id, '_strategy_id', 'stepped_by_cycle' );
		$this->assertSame( Pricing_Rule::APPLICATION_LOCKED, Pricing_Rule::from_post( get_post( $post_id ) )->application, 'Locked rules are the default — prospective edits, pinned at purchase.' );

		update_post_meta( $post_id, '_application', 'current' );
		$this->assertSame( Pricing_Rule::APPLICATION_CURRENT, Pricing_Rule::from_post( get_post( $post_id ) )->application );

		update_post_meta( $post_id, '_application', 'bogus' );
		$this->assertSame( Pricing_Rule::APPLICATION_LOCKED, Pricing_Rule::from_post( get_post( $post_id ) )->application, 'Unknown values fall back to locked.' );
	}

	public function test_snapshot_roundtrip_preserves_decision_relevant_config() {
		$p = $this->make_policy( [] );
		$p->params       = [ 'steps' => [ [ 'at' => 1, 'calc_type' => 'fixed_price', 'value' => 5, 'label' => 'Intro' ] ] ];
		$p->priority     = 42;
		$p->compose_mode = 'priority_exclusive';
		$p->publicize    = true;

		$snapshot = $p->to_snapshot();
		$this->assertSame( 1, $snapshot['schema_version'] );
		$this->assertSame( '1', $snapshot['rule_id'] );

		$hydrated = Pricing_Rule::from_snapshot( $snapshot );
		$this->assertSame( $p->strategy_id, $hydrated->strategy_id );
		$this->assertSame( $p->params, $hydrated->params );
		$this->assertSame( 42, $hydrated->priority );
		$this->assertSame( 'priority_exclusive', $hydrated->compose_mode );
		$this->assertTrue( $hydrated->publicize );
		$this->assertSame( [], $hydrated->conditions, 'A pinned rule is unconditional.' );
		$this->assertNull( $hydrated->active_from, 'A pinned rule has no window — locked rule lifetime is subscription lifetime.' );
		$this->assertTrue( $hydrated->is_active_now() );
	}

	public function test_scope_type_defaults_to_all_subscriptions_when_meta_absent() {
		$post_id = $this->factory->post->create( [ 'post_type' => 'shop_pricing_rule' ] );
		update_post_meta( $post_id, '_strategy_id', 'stepped_by_cycle' );
		// No _scope_type meta — '' would resolve no matcher and kill the rule.
		$rule = Pricing_Rule::from_post( get_post( $post_id ) );
		$this->assertSame( 'all_subscriptions', $rule->scope_type );
	}

	public function test_priority_defaults_to_100_when_meta_absent() {
		$post_id = $this->factory->post->create( [ 'post_type' => 'shop_pricing_rule' ] );
		update_post_meta( $post_id, '_strategy_id', 'stepped_by_cycle' );
		// Do NOT set _priority meta.
		$rule = Pricing_Rule::from_post( get_post( $post_id ) );
		$this->assertSame( 100, $rule->priority );
	}

	public function test_is_active_now_true_when_dates_open() {
		$p = $this->make_policy( [] );
		$this->assertTrue( $p->is_active_now() );
	}

	public function test_is_active_now_false_when_active_from_in_future() {
		$p = $this->make_policy( [ 'active_from' => time() + 3600 ] );
		$this->assertFalse( $p->is_active_now() );
	}

	public function test_is_active_now_false_when_active_until_in_past() {
		$p = $this->make_policy( [ 'active_until' => time() - 3600 ] );
		$this->assertFalse( $p->is_active_now() );
	}

	private function make_policy( array $overrides ): Pricing_Rule {
		$p = new Pricing_Rule();
		$p->id            = '1';
		$p->title         = 'Test';
		$p->strategy_id   = 'stepped_by_cycle';
		$p->params        = [];
		$p->priority      = 100;
		$p->compose_mode  = 'min';
		$p->scope_type    = 'all_subscriptions';
		$p->scope_ids     = [];
		$p->active_from   = $overrides['active_from'] ?? null;
		$p->active_until  = $overrides['active_until'] ?? null;
		$p->conditions    = [];
		return $p;
	}
}
