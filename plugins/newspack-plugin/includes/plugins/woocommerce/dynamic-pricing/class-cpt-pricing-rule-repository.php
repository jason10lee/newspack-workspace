<?php
/**
 * CPT-backed Pricing_Rule Repository.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

final class CPT_Pricing_Rule_Repository implements Pricing_Rule_Repository {
	const CACHE_GROUP          = 'newspack_dynamic_pricing';
	const CACHE_TTL            = MINUTE_IN_SECONDS;
	const CACHE_VERSION_OPTION = 'newspack_dynamic_pricing_cache_version';
	const HAS_POLICIES_OPTION  = 'newspack_dynamic_pricing_has_policies';

	/**
	 * Cache version is keyed into the cache key. Bumping it on rule save invalidates
	 * all previous entries without relying on `wp_cache_flush_group` (which is a no-op
	 * on the default `WP_Object_Cache` and on some persistent backends). Old entries
	 * orphan and TTL out naturally; new requests miss the cache and refresh.
	 */
	public static function get_cache_version(): int {
		$v = (int) get_option( self::CACHE_VERSION_OPTION, 1 );
		return $v > 0 ? $v : 1;
	}

	/**
	 * Cheap zero-rules short-circuit: WC recalculates cart totals on essentially
	 * every front-end page view for visitors with a cart, and most sites have no
	 * pricing policies at all. Backed by an autoloaded option maintained by
	 * flush_cache() (hooked to every rule save/delete), self-healing on first read.
	 */
	public static function has_policies(): bool {
		$flag = get_option( self::HAS_POLICIES_OPTION, '' );
		if ( 'yes' !== $flag && 'no' !== $flag ) {
			$flag = self::count_published_policies() > 0 ? 'yes' : 'no';
			update_option( self::HAS_POLICIES_OPTION, $flag, true );
		}
		return 'yes' === $flag;
	}

	/**
	 * The rule set for a pricing context. Intent-aware (docs 03):
	 *
	 * - Acquisition: every matching repository rule, locked- and current-class —
	 *   v1 semantics. A winning locked-class rule gets pinned downstream.
	 * - Renewal: the target subscription's pinned-rule snapshot (if any) plus
	 *   matching LIVE-class policies. Deal-class repository policies are
	 *   excluded entirely — their renewal effect flows only through snapshots,
	 *   so no rule edit can leak into an existing locked rule.
	 */
	public function for_context( Pricing_Context $ctx ): array {
		$is_renewal = Pricing_Context::INTENT_RENEWAL === $ctx->intent;
		$pinned     = $is_renewal ? self::pinned_rule_for( $ctx->target ) : null;

		// The zero-rules short-circuit must not block pinned rules: a snapshot
		// outlives its rule row by design (it may be the only rule left).
		if ( ! self::has_policies() ) {
			return $pinned ? [ $pinned ] : [];
		}

		$engine   = Pricing_Engine::instance();
		$policies = $pinned ? [ $pinned ] : [];
		foreach ( $this->all_active() as $rule ) {
			if ( $is_renewal && Pricing_Rule::APPLICATION_LOCKED === $rule->application ) {
				continue;
			}
			if ( $rule->matches_product( $ctx->product, $engine ) ) {
				$policies[] = $rule;
			}
		}
		return $policies;
	}

	/**
	 * Read the pinned-rule snapshot off a renewal target's recurring line item
	 * and hydrate it. Multi-line subscriptions are excluded upstream, so the
	 * first line item is the recurring line.
	 *
	 * @param mixed $target Surface-native target; only WC_Subscription carries pins.
	 */
	public static function pinned_rule_for( mixed $target ): ?Pricing_Rule {
		if ( ! $target instanceof \WC_Subscription ) {
			return null;
		}
		foreach ( $target->get_items( 'line_item' ) as $line ) {
			$snapshot = Subscription_Pin::snapshot( $line );
			return $snapshot ? Pricing_Rule::from_snapshot( $snapshot ) : null;
		}
		return null;
	}

	/**
	 * All currently-active policies, hydrated, under ONE versioned cache key.
	 * The per-product filter happens in memory — N products in a cart share a
	 * single query instead of issuing N identical unbounded ones.
	 *
	 * @return Pricing_Rule[]
	 */
	private function all_active(): array {
		$cache_key = 'active_policies_v' . self::get_cache_version();

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$posts = get_posts( [
			'post_type'      => 'shop_pricing_rule',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		] );

		// Active-window filtering happens here (Pricing_Rule::is_active_now) rather than
		// in a meta_query — one source of truth for the window logic. The 60s TTL
		// bounds how stale a window-boundary crossing can be.
		$policies = [];
		foreach ( $posts as $post ) {
			$rule = Pricing_Rule::from_post( $post );
			if ( $rule->is_active_now() ) {
				$policies[] = $rule;
			}
		}

		wp_cache_set( $cache_key, $policies, self::CACHE_GROUP, self::CACHE_TTL );
		return $policies;
	}

	public function save( Pricing_Rule $p ): void {
		throw new \BadMethodCallException( 'Pricing_Rule::save not implemented in v1; create policies via WP-CLI per spec §5.5.' );
	}

	public function all(): array {
		$posts = get_posts( [ 'post_type' => 'shop_pricing_rule', 'posts_per_page' => -1, 'post_status' => 'any' ] );
		return array_map( [ Pricing_Rule::class, 'from_post' ], $posts );
	}

	/**
	 * Bumps the cache version and refreshes the has-policies flag. Old cache
	 * entries become orphaned (different cache key) and TTL out. Works on any
	 * object-cache backend regardless of `wp_cache_flush_group` support.
	 */
	public static function flush_cache(): void {
		$current = self::get_cache_version();
		update_option( self::CACHE_VERSION_OPTION, $current + 1, false );
		update_option( self::HAS_POLICIES_OPTION, self::count_published_policies() > 0 ? 'yes' : 'no', true );
	}

	private static function count_published_policies(): int {
		$counts = wp_count_posts( 'shop_pricing_rule' );
		return (int) ( $counts->publish ?? 0 );
	}
}
