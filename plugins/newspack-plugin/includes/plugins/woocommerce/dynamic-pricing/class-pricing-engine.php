<?php
/**
 * Pricing Engine — the resolver singleton.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

final class Pricing_Engine {
	private static ?self $instance = null;

	/** @var array<string,Pricing_Strategy> */
	private array $strategies = [];
	/** @var array<string,Price_Surface> */
	private array $surfaces = [];
	/** @var array<string,Scope_Matcher> */
	private array $scope_matchers = [];
	/** @var array<string,Condition_Matcher> */
	private array $condition_matchers = [];

	/**
	 * Public constructor — tests inject mocks directly; production wires via instance().
	 */
	public function __construct(
		private ?Policy_Repository $policies = null,
		private ?Pricing_Guardrails $guardrails = null
	) {}

	/**
	 * Lazy singleton used by production bootstrap. Prefer `new Pricing_Engine(...)` in tests.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register( Pricing_Strategy $s ): void                  { $this->strategies[ $s->id() ] = $s; }
	public function strategy( string $id ): ?Pricing_Strategy              { return $this->strategies[ $id ] ?? null; }
	public function add_surface( Price_Surface $s ): void                  { $this->surfaces[ $s->id() ] = $s; }
	public function surface( string $id ): ?Price_Surface                  { return $this->surfaces[ $id ] ?? null; }
	public function register_scope( Scope_Matcher $m ): void               { $this->scope_matchers[ $m->id() ] = $m; }
	public function scope_matcher( string $id ): ?Scope_Matcher            { return $this->scope_matchers[ $id ] ?? null; }
	public function register_condition( Condition_Matcher $m ): void       { $this->condition_matchers[ $m->id() ] = $m; }
	public function condition_matcher( string $id ): ?Condition_Matcher    { return $this->condition_matchers[ $id ] ?? null; }

	public function set_repository( Policy_Repository $r ): void           { $this->policies = $r; }
	public function set_guardrails( Pricing_Guardrails $g ): void          { $this->guardrails = $g; }

	public function resolve( Pricing_Context $ctx ): ?Price_Decision {
		if ( $this->is_excluded( $ctx->product, $ctx->target ) ) {
			return null;
		}
		if ( null === $this->policies || null === $this->guardrails ) {
			return null;
		}

		$applicable = $this->policies->for_context( $ctx );
		usort( $applicable, fn( Policy $a, Policy $b ) => $a->priority <=> $b->priority );

		$decision = null;
		foreach ( $applicable as $policy ) {
			if ( $decision && $decision->is_locked ) {
				break;
			}

			$strategy = $this->strategies[ $policy->strategy_id ] ?? null;
			if ( ! $strategy || ! $strategy->applies_to( $ctx, $policy->params ) ) {
				continue;
			}
			if ( ! $policy->passes_conditions( $ctx, $this ) ) {
				continue;
			}
			$d = $strategy->decide( $ctx, $policy->params );
			if ( ! $d ) {
				continue;
			}
			$d->policy_id = $policy->id;
			$decision = $this->guardrails->compose( $decision, $d, $policy, $ctx );
		}
		return $decision ? $this->guardrails->guard( $decision, $ctx ) : null;
	}

	/**
	 * Engine-level exclusions only — WC/WCS-native concerns.
	 * Newspack-specific exclusions (donations, group subscriptions, pause meta)
	 * hook in via the `newspack_dynamic_pricing_is_excluded` filter from
	 * Newspack\Dynamic_Pricing_Bridges (Task 11). See spec §16.1.
	 */
	private function is_excluded( \WC_Product $product, mixed $target ): bool {
		if (
			$target instanceof \WC_Subscription
			&& class_exists( 'WCS_Gifting' )
			&& (bool) $target->get_meta( '_wcsg_recipient' )
		) {
			return apply_filters( 'newspack_dynamic_pricing_is_excluded', true, $product, $target );
		}
		if (
			$target instanceof \WC_Subscription
			&& $target->get_currency() !== get_woocommerce_currency()
		) {
			return apply_filters( 'newspack_dynamic_pricing_is_excluded', true, $product, $target );
		}
		if (
			$target instanceof \WC_Subscription
			&& count( $target->get_items( 'line_item' ) ) > 1
		) {
			return apply_filters( 'newspack_dynamic_pricing_is_excluded', true, $product, $target );
		}
		return apply_filters( 'newspack_dynamic_pricing_is_excluded', false, $product, $target );
	}

	/** @internal Test helper. */
	public function reset_for_tests(): void {
		$this->strategies         = [];
		$this->surfaces           = [];
		$this->scope_matchers     = [];
		$this->condition_matchers = [];
		$this->policies           = null;
		$this->guardrails         = null;
	}
}
