<?php
/**
 * Tests for Newspack\Dynamic_Pricing\Policy.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Policy;

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Policy extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		if ( ! post_type_exists( 'shop_pricing_policy' ) ) {
			register_post_type( 'shop_pricing_policy', [ 'public' => false ] );
		}
	}

	public function test_from_post_reads_meta_into_properties() {
		$post_id = $this->factory->post->create( [ 'post_type' => 'shop_pricing_policy', 'post_status' => 'publish', 'post_title' => 'Test' ] );
		update_post_meta( $post_id, '_strategy_id', 'stepped_by_cycle' );
		update_post_meta( $post_id, '_priority', 100 );
		update_post_meta( $post_id, '_compose_mode', 'min' );
		update_post_meta( $post_id, '_scope_type', 'product_ids' );
		add_post_meta( $post_id, '_scope_product_id', 42 );
		add_post_meta( $post_id, '_scope_product_id', 99 );
		update_post_meta( $post_id, '_params', wp_json_encode( [ 'steps' => [ [ 'at' => 1, 'value' => 1 ] ] ] ) );

		$policy = Policy::from_post( get_post( $post_id ) );

		$this->assertSame( (string) $post_id, $policy->id );
		$this->assertSame( 'Test', $policy->title );
		$this->assertSame( 'stepped_by_cycle', $policy->strategy_id );
		$this->assertSame( 100, $policy->priority );
		$this->assertSame( 'min', $policy->compose_mode );
		$this->assertSame( 'product_ids', $policy->scope_type );
		$this->assertSame( [ 42, 99 ], $policy->scope_ids );
		$this->assertSame( [ 'steps' => [ [ 'at' => 1, 'value' => 1 ] ] ], $policy->params );
		$this->assertSame( [], $policy->conditions );
		$this->assertNull( $policy->active_from );
		$this->assertNull( $policy->active_until );
	}

	public function test_compose_mode_defaults_to_min_when_meta_absent() {
		$post_id = $this->factory->post->create( [ 'post_type' => 'shop_pricing_policy' ] );
		update_post_meta( $post_id, '_strategy_id', 'stepped_by_cycle' );
		$policy = Policy::from_post( get_post( $post_id ) );
		$this->assertSame( 'min', $policy->compose_mode );
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

	private function make_policy( array $overrides ): Policy {
		$p = new Policy();
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
