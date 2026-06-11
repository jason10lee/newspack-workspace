<?php
use Newspack\Dynamic_Pricing\Pricing_Engine;
use Newspack\Dynamic_Pricing\Pricing_Strategy;
use Newspack\Dynamic_Pricing\Pricing_Rule;
use Newspack\Dynamic_Pricing\Pricing_Rule_Repository;
use Newspack\Dynamic_Pricing\Pricing_Context;
use Newspack\Dynamic_Pricing\Price_Decision;
use Newspack\Dynamic_Pricing\Pricing_Guardrails;
use Newspack\Dynamic_Pricing\Bounds_Resolver;
use Newspack\Dynamic_Pricing\Matchers\All_Subscriptions_Scope_Matcher;

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency() {
		return get_option( 'woocommerce_currency', 'USD' );
	}
}

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Pricing_Engine extends WP_UnitTestCase {
	public function test_register_and_lookup_strategy() {
		$engine = $this->fresh_engine();
		$s = $this->stub_strategy( 'noop', null );
		$engine->register( $s );
		$this->assertSame( $s, $engine->strategy( 'noop' ) );
	}

	public function test_register_and_lookup_scope_matcher() {
		$engine = $this->fresh_engine();
		$m = new All_Subscriptions_Scope_Matcher();
		$engine->register_scope( $m );
		$this->assertSame( $m, $engine->scope_matcher( 'all_subscriptions' ) );
	}

	public function test_constructor_injection_used_for_dependencies() {
		$repo = $this->mock_repo( [] );
		$guard = new Pricing_Guardrails( new Bounds_Resolver() );
		$engine = new Pricing_Engine( $repo, $guard );
		$engine->register_scope( new All_Subscriptions_Scope_Matcher() );

		$ctx = new Pricing_Context( 'scheduled_step', $this->mock_subscription_product( 1 ), null, 10, [], null );
		$this->assertNull( $engine->resolve( $ctx ) );
	}

	public function test_resolve_returns_decision_from_single_matching_policy() {
		$engine = $this->fresh_engine();
		$engine->register( $this->stub_strategy( 'fixed_eight', 8.0 ) );
		$engine->set_repository( $this->mock_repo( [ $this->stub_policy( 'p1', 'fixed_eight', 100, 'min' ) ] ) );

		$ctx = new Pricing_Context( 'scheduled_step', $this->mock_subscription_product( 1 ), null, 10, [], null );
		$d = $engine->resolve( $ctx );

		$this->assertNotNull( $d );
		$this->assertSame( 8.0, $d->amount );
		$this->assertSame( 'p1', $d->rule_id );
	}

	public function test_resolve_break_on_locked_decision() {
		$engine = $this->fresh_engine();
		$engine->register( $this->stub_strategy( 'fixed_twelve', 12.0 ) );
		$engine->register( $this->stub_strategy( 'fixed_five', 5.0 ) );

		$exclusive = $this->stub_policy( 'p_exclusive', 'fixed_twelve', 10, 'priority_exclusive' );
		$bargain   = $this->stub_policy( 'p_bargain', 'fixed_five', 100, 'min' );
		$engine->set_repository( $this->mock_repo( [ $exclusive, $bargain ] ) );

		$ctx = new Pricing_Context( 'scheduled_step', $this->mock_subscription_product( 1 ), null, 100, [], null );
		$d = $engine->resolve( $ctx );

		$this->assertSame( 12.0, $d->amount, 'Locked decision must persist; min() rule must not override.' );
		$this->assertSame( 'p_exclusive', $d->rule_id );
	}

	public function test_resolve_honors_excluded_filter_from_a_bridge() {
		add_filter( 'newspack_dynamic_pricing_is_excluded', '__return_true' );
		$engine = $this->fresh_engine();
		$engine->register( $this->stub_strategy( 'fixed_eight', 8.0 ) );
		$engine->set_repository( $this->mock_repo( [ $this->stub_policy( 'p1', 'fixed_eight', 100, 'min' ) ] ) );

		$ctx = new Pricing_Context( 'scheduled_step', $this->mock_subscription_product( 1 ), null, 10, [], null );
		$this->assertNull( $engine->resolve( $ctx ) );
		remove_filter( 'newspack_dynamic_pricing_is_excluded', '__return_true' );
	}

	public function test_resolve_excludes_multi_line_item_subscription() {
		$engine = $this->fresh_engine();
		$engine->register( $this->stub_strategy( 'fixed_eight', 8.0 ) );
		$engine->set_repository( $this->mock_repo( [ $this->stub_policy( 'p1', 'fixed_eight', 100, 'min' ) ] ) );

		$sub = $this->getMockBuilder( \WC_Subscription::class )->disableOriginalConstructor()->getMock();
		$sub->method( 'get_meta' )->willReturn( false );
		$sub->method( 'get_items' )->willReturn( [ 'l1' => 'a', 'l2' => 'b' ] );
		$sub->method( 'get_currency' )->willReturn( get_woocommerce_currency() );

		$ctx = new Pricing_Context( 'scheduled_step', $this->mock_subscription_product( 1 ), null, 10, [], $sub );
		$this->assertNull( $engine->resolve( $ctx ) );
	}

	private function fresh_engine(): Pricing_Engine {
		$engine = Pricing_Engine::instance();
		$engine->reset_for_tests();
		$engine->set_repository( $this->mock_repo( [] ) );
		$engine->set_guardrails( new Pricing_Guardrails( new Bounds_Resolver() ) );
		$engine->register_scope( new All_Subscriptions_Scope_Matcher() );
		return $engine;
	}

	private function mock_repo( array $policies ): Pricing_Rule_Repository {
		return new class( $policies ) implements Pricing_Rule_Repository {
			public function __construct( private array $policies ) {}
			public function for_context( Pricing_Context $ctx ): array { return $this->policies; }
			public function save( Pricing_Rule $p ): void {}
			public function all(): array { return $this->policies; }
		};
	}

	private function mock_subscription_product( int $id ): \WC_Product {
		$p = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$p->method( 'get_id' )->willReturn( $id );
		$p->method( 'get_type' )->willReturn( 'subscription' );
		return $p;
	}

	private function stub_strategy( string $id, ?float $amount ): Pricing_Strategy {
		return new class( $id, $amount ) implements Pricing_Strategy {
			public function __construct( private string $sid, private ?float $amount ) {}
			public function id(): string { return $this->sid; }
			public function config_schema(): array { return []; }
			public function applies_to( Pricing_Context $ctx, array $params ): bool { return null !== $this->amount; }
			public function decide( Pricing_Context $ctx, array $params ): ?Price_Decision {
				if ( null === $this->amount ) { return null; }
				return new Price_Decision( $this->amount, Price_Decision::DURABLE, 'test', 'Test', $this->sid, 1 );
			}
		};
	}

	private function stub_policy( string $id, string $strategy_id, int $priority, string $compose_mode ): Pricing_Rule {
		$p = new Pricing_Rule();
		$p->id           = $id;
		$p->title        = $id;
		$p->strategy_id  = $strategy_id;
		$p->priority     = $priority;
		$p->compose_mode = $compose_mode;
		$p->scope_type   = 'all_subscriptions';
		return $p;
	}
}
