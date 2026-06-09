<?php
use Newspack\Dynamic_Pricing\CPT_Policy_Repository;
use Newspack\Dynamic_Pricing\Policy;
use Newspack\Dynamic_Pricing\Pricing_Context;
use Newspack\Dynamic_Pricing\Pricing_Engine;
use Newspack\Dynamic_Pricing\Matchers\Product_Ids_Scope_Matcher;
use Newspack\Dynamic_Pricing\Matchers\All_Subscriptions_Scope_Matcher;

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_CPT_Policy_Repository extends WP_UnitTestCase {
	private CPT_Policy_Repository $repo;

	public function set_up() {
		parent::set_up();
		register_post_type( 'shop_pricing_policy', [ 'public' => false, 'show_ui' => false ] );
		wp_cache_flush();
		// Wire up the engine's matcher registry so Policy::matches_product can succeed.
		$engine = Pricing_Engine::instance();
		$engine->reset_for_tests();
		$engine->register_scope( new Product_Ids_Scope_Matcher() );
		$engine->register_scope( new All_Subscriptions_Scope_Matcher() );
		$this->repo = new CPT_Policy_Repository();
	}

	public function test_for_context_returns_active_policy_matching_product() {
		$post_id = $this->seed_policy( 'publish', [
			'_strategy_id' => 'stepped_by_cycle',
			'_scope_type'  => 'product_ids',
			'_priority'    => 100,
		], [ 42 ] );

		$product = $this->mock_product( 42 );
		$ctx     = $this->mock_context( $product );

		$result = $this->repo->for_context( $ctx );
		$this->assertCount( 1, $result );
		$this->assertSame( (string) $post_id, $result[0]->id );
	}

	public function test_for_context_skips_draft_policies() {
		$this->seed_policy( 'draft', [
			'_strategy_id' => 'stepped_by_cycle',
			'_scope_type'  => 'product_ids',
		], [ 42 ] );
		$this->assertSame( [], $this->repo->for_context( $this->mock_context( $this->mock_product( 42 ) ) ) );
	}

	public function test_for_context_skips_policies_outside_active_window() {
		$this->seed_policy( 'publish', [
			'_strategy_id'  => 'stepped_by_cycle',
			'_scope_type'   => 'product_ids',
			'_active_until' => time() - 3600,
		], [ 42 ] );
		$this->assertSame( [], $this->repo->for_context( $this->mock_context( $this->mock_product( 42 ) ) ) );
	}

	public function test_for_context_skips_policies_with_non_matching_product() {
		$this->seed_policy( 'publish', [
			'_strategy_id' => 'stepped_by_cycle',
			'_scope_type'  => 'product_ids',
		], [ 42 ] );
		$this->assertSame( [], $this->repo->for_context( $this->mock_context( $this->mock_product( 7 ) ) ) );
	}

	public function test_for_context_caches_result() {
		$this->seed_policy( 'publish', [
			'_strategy_id' => 'stepped_by_cycle',
			'_scope_type'  => 'product_ids',
		], [ 42 ] );

		$ctx    = $this->mock_context( $this->mock_product( 42 ) );
		$first  = $this->repo->for_context( $ctx );
		$cached = wp_cache_get( 'policies_for_product_42', CPT_Policy_Repository::CACHE_GROUP );
		$this->assertSame( $first, $cached );
	}

	public function test_flush_cache_clears_group() {
		$this->seed_policy( 'publish', [
			'_strategy_id' => 'stepped_by_cycle',
			'_scope_type'  => 'product_ids',
		], [ 42 ] );

		$ctx = $this->mock_context( $this->mock_product( 42 ) );
		$this->repo->for_context( $ctx );
		CPT_Policy_Repository::flush_cache();
		$this->assertFalse( wp_cache_get( 'policies_for_product_42', CPT_Policy_Repository::CACHE_GROUP ) );
	}

	private function seed_policy( string $post_status, array $meta, array $scope_product_ids = [] ): int {
		$post_id = $this->factory->post->create( [ 'post_type' => 'shop_pricing_policy', 'post_status' => $post_status ] );
		foreach ( $meta as $k => $v ) {
			update_post_meta( $post_id, $k, $v );
		}
		foreach ( $scope_product_ids as $pid ) {
			add_post_meta( $post_id, '_scope_product_id', $pid );
		}
		return $post_id;
	}

	private function mock_product( int $id ): \WC_Product {
		$p = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$p->method( 'get_id' )->willReturn( $id );
		$p->method( 'get_type' )->willReturn( 'subscription' );
		return $p;
	}

	private function mock_context( \WC_Product $product ): Pricing_Context {
		return new Pricing_Context( 'scheduled_step', $product, null, 10.0, [], null );
	}
}
