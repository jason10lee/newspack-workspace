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

	public function test_for_context_caches_full_active_list_under_one_versioned_key() {
		$this->seed_policy( 'publish', [
			'_strategy_id' => 'stepped_by_cycle',
			'_scope_type'  => 'product_ids',
		], [ 42 ] );

		$result = $this->repo->for_context( $this->mock_context( $this->mock_product( 42 ) ) );
		$this->assertCount( 1, $result );

		// The cache holds ALL active policies (per-product filtering is in-memory) —
		// a second product's lookup must hit the same single cached list.
		$v      = CPT_Policy_Repository::get_cache_version();
		$cached = wp_cache_get( 'active_policies_v' . $v, CPT_Policy_Repository::CACHE_GROUP );
		$this->assertIsArray( $cached );
		$this->assertCount( 1, $cached, 'Cache stores the full active-policy list.' );
		$this->assertSame( [], $this->repo->for_context( $this->mock_context( $this->mock_product( 7 ) ) ), 'Non-matching product filters in memory.' );
	}

	public function test_flush_cache_bumps_version() {
		$before = CPT_Policy_Repository::get_cache_version();
		CPT_Policy_Repository::flush_cache();
		$after = CPT_Policy_Repository::get_cache_version();
		$this->assertSame( $before + 1, $after, 'flush_cache must increment the cache version.' );
	}

	public function test_flush_cache_invalidates_subsequent_for_context_calls() {
		$this->seed_policy( 'publish', [
			'_strategy_id' => 'stepped_by_cycle',
			'_scope_type'  => 'product_ids',
		], [ 42 ] );

		$this->repo->for_context( $this->mock_context( $this->mock_product( 42 ) ) );
		$v1      = CPT_Policy_Repository::get_cache_version();
		$cached1 = wp_cache_get( 'active_policies_v' . $v1, CPT_Policy_Repository::CACHE_GROUP );
		$this->assertNotEmpty( $cached1, 'First lookup should populate the versioned cache.' );

		CPT_Policy_Repository::flush_cache();
		$v2 = CPT_Policy_Repository::get_cache_version();
		$this->assertNotSame( $v1, $v2 );

		$cached_new = wp_cache_get( 'active_policies_v' . $v2, CPT_Policy_Repository::CACHE_GROUP );
		$this->assertFalse( $cached_new, 'After flush, the new versioned key should be empty until next lookup.' );
	}

	public function test_has_policies_reflects_published_policy_presence() {
		delete_option( CPT_Policy_Repository::HAS_POLICIES_OPTION );
		$this->assertFalse( CPT_Policy_Repository::has_policies(), 'No policies → false (self-healing recompute).' );

		$this->seed_policy( 'publish', [
			'_strategy_id' => 'stepped_by_cycle',
			'_scope_type'  => 'product_ids',
		], [ 42 ] );
		// seed_policy fires save_post → flush_cache → flag refresh.
		$this->assertTrue( CPT_Policy_Repository::has_policies() );
	}

	public function test_for_context_short_circuits_when_no_policies_exist() {
		delete_option( CPT_Policy_Repository::HAS_POLICIES_OPTION );
		$this->assertSame( [], $this->repo->for_context( $this->mock_context( $this->mock_product( 42 ) ) ) );
		$v = CPT_Policy_Repository::get_cache_version();
		$this->assertFalse(
			wp_cache_get( 'active_policies_v' . $v, CPT_Policy_Repository::CACHE_GROUP ),
			'Zero-policy sites must not even populate the policy cache.'
		);
	}

	public function test_renewal_intent_excludes_deal_class_policies() {
		// Default application is deal — it must not live-resolve at renewal.
		$this->seed_policy( 'publish', [
			'_strategy_id' => 'stepped_by_cycle',
			'_scope_type'  => 'product_ids',
		], [ 42 ] );

		$this->assertCount( 1, $this->repo->for_context( $this->mock_context( $this->mock_product( 42 ) ) ), 'Deal policies resolve at acquisition.' );
		$this->assertSame( [], $this->repo->for_context( $this->renewal_context( $this->mock_product( 42 ) ) ), 'Deal policies reach renewals only through pinned snapshots.' );
	}

	public function test_renewal_intent_includes_live_class_policies() {
		$this->seed_policy( 'publish', [
			'_strategy_id' => 'stepped_by_cycle',
			'_scope_type'  => 'product_ids',
			'_application' => 'current',
		], [ 42 ] );

		$result = $this->repo->for_context( $this->renewal_context( $this->mock_product( 42 ) ) );
		$this->assertCount( 1, $result );
		$this->assertSame( Policy::APPLICATION_CURRENT, $result[0]->application );
	}

	public function test_renewal_intent_returns_pinned_deal_plus_live_policies() {
		$this->seed_policy( 'publish', [
			'_strategy_id' => 'stepped_by_cycle',
			'_scope_type'  => 'product_ids',
			'_application' => 'current',
		], [ 42 ] );

		$ctx    = $this->renewal_context( $this->mock_product( 42 ), $this->mock_pinned_subscription( $this->snapshot_fixture() ) );
		$result = $this->repo->for_context( $ctx );

		$this->assertCount( 2, $result );
		$this->assertSame( '777', $result[0]->id, 'Pinned deal is sourced first.' );
		$this->assertSame( [ 'steps' => [ [ 'at' => 1, 'calc_type' => 'fixed_price', 'value' => 5, 'label' => 'Intro' ] ] ], $result[0]->params );
		$this->assertSame( Policy::APPLICATION_CURRENT, $result[1]->application );
	}

	public function test_pinned_deal_resolves_even_with_zero_policies() {
		// The snapshot outlives its policy row by design — has_policies must not block it.
		delete_option( CPT_Policy_Repository::HAS_POLICIES_OPTION );
		$this->assertFalse( CPT_Policy_Repository::has_policies() );

		$ctx    = $this->renewal_context( $this->mock_product( 42 ), $this->mock_pinned_subscription( $this->snapshot_fixture() ) );
		$result = $this->repo->for_context( $ctx );

		$this->assertCount( 1, $result );
		$this->assertSame( '777', $result[0]->id );
	}

	public function test_invalid_snapshot_is_ignored() {
		$ctx = $this->renewal_context(
			$this->mock_product( 42 ),
			$this->mock_pinned_subscription( [ 'schema_version' => 99, 'strategy_id' => 'stepped_by_cycle' ] )
		);
		$this->assertSame( [], $this->repo->for_context( $ctx ), 'Unknown snapshot schema versions must not resolve.' );
	}

	private function snapshot_fixture(): array {
		return [
			'schema_version' => 1,
			'policy_id'      => '777',
			'pinned_at'      => '2026-06-10 12:00:00',
			'title'          => 'Pinned deal',
			'strategy_id'    => 'stepped_by_cycle',
			'params'         => [ 'steps' => [ [ 'at' => 1, 'calc_type' => 'fixed_price', 'value' => 5, 'label' => 'Intro' ] ] ],
			'priority'       => 50,
			'compose_mode'   => 'min',
			'publicize'      => true,
		];
	}

	private function mock_pinned_subscription( array $snapshot ): \WC_Subscription {
		// The wc-mocks line item shim returns constructor-provided meta verbatim.
		$line = new \WC_Order_Item_Product( [ 'meta' => [ \Newspack\Dynamic_Pricing\Subscription_Pin::DEAL_META_KEY => $snapshot ] ] );
		$sub  = $this->getMockBuilder( \WC_Subscription::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_items' ] )
			->getMock();
		$sub->method( 'get_items' )->willReturn( [ $line ] );
		return $sub;
	}

	private function renewal_context( \WC_Product $product, mixed $target = null ): Pricing_Context {
		return new Pricing_Context( 'scheduled_step', $product, null, 10.0, [ 'completed_cycles' => 2 ], $target, Pricing_Context::INTENT_RENEWAL, true );
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
