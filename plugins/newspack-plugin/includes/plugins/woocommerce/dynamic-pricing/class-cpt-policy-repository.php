<?php
/**
 * CPT-backed Policy Repository.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

final class CPT_Policy_Repository implements Policy_Repository {
	const CACHE_GROUP          = 'newspack_dynamic_pricing';
	const CACHE_TTL            = MINUTE_IN_SECONDS;
	const CACHE_VERSION_OPTION = 'newspack_dynamic_pricing_cache_version';
	const HAS_POLICIES_OPTION  = 'newspack_dynamic_pricing_has_policies';

	/**
	 * Cache version is keyed into the cache key. Bumping it on policy save invalidates
	 * all previous entries without relying on `wp_cache_flush_group` (which is a no-op
	 * on the default `WP_Object_Cache` and on some persistent backends). Old entries
	 * orphan and TTL out naturally; new requests miss the cache and refresh.
	 */
	public static function get_cache_version(): int {
		$v = (int) get_option( self::CACHE_VERSION_OPTION, 1 );
		return $v > 0 ? $v : 1;
	}

	/**
	 * Cheap zero-policy short-circuit: WC recalculates cart totals on essentially
	 * every front-end page view for visitors with a cart, and most sites have no
	 * pricing policies at all. Backed by an autoloaded option maintained by
	 * flush_cache() (hooked to every policy save/delete), self-healing on first read.
	 */
	public static function has_policies(): bool {
		$flag = get_option( self::HAS_POLICIES_OPTION, '' );
		if ( 'yes' !== $flag && 'no' !== $flag ) {
			$flag = self::count_published_policies() > 0 ? 'yes' : 'no';
			update_option( self::HAS_POLICIES_OPTION, $flag, true );
		}
		return 'yes' === $flag;
	}

	public function for_context( Pricing_Context $ctx ): array {
		if ( ! self::has_policies() ) {
			return [];
		}

		$engine   = Pricing_Engine::instance();
		$policies = [];
		foreach ( $this->all_active() as $policy ) {
			if ( $policy->matches_product( $ctx->product, $engine ) ) {
				$policies[] = $policy;
			}
		}
		return $policies;
	}

	/**
	 * All currently-active policies, hydrated, under ONE versioned cache key.
	 * The per-product filter happens in memory — N products in a cart share a
	 * single query instead of issuing N identical unbounded ones.
	 *
	 * @return Policy[]
	 */
	private function all_active(): array {
		$cache_key = 'active_policies_v' . self::get_cache_version();

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$posts = get_posts( [
			'post_type'      => 'shop_pricing_policy',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		] );

		// Active-window filtering happens here (Policy::is_active_now) rather than
		// in a meta_query — one source of truth for the window logic. The 60s TTL
		// bounds how stale a window-boundary crossing can be.
		$policies = [];
		foreach ( $posts as $post ) {
			$policy = Policy::from_post( $post );
			if ( $policy->is_active_now() ) {
				$policies[] = $policy;
			}
		}

		wp_cache_set( $cache_key, $policies, self::CACHE_GROUP, self::CACHE_TTL );
		return $policies;
	}

	public function save( Policy $p ): void {
		throw new \BadMethodCallException( 'Policy::save not implemented in v1; create policies via WP-CLI per spec §5.5.' );
	}

	public function all(): array {
		$posts = get_posts( [ 'post_type' => 'shop_pricing_policy', 'posts_per_page' => -1, 'post_status' => 'any' ] );
		return array_map( [ Policy::class, 'from_post' ], $posts );
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
		$counts = wp_count_posts( 'shop_pricing_policy' );
		return (int) ( $counts->publish ?? 0 );
	}
}
