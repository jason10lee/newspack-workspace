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

	public function for_context( Pricing_Context $ctx ): array {
		$product_id = (int) $ctx->product->get_id();
		$cache_key  = 'policies_for_product_' . $product_id . '_v' . self::get_cache_version();

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$now   = time();
		$posts = get_posts( [
			'post_type'      => 'shop_pricing_policy',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => [
				'relation' => 'AND',
				[
					'relation' => 'OR',
					[ 'key' => '_active_from', 'compare' => 'NOT EXISTS' ],
					[ 'key' => '_active_from', 'value' => $now, 'compare' => '<=', 'type' => 'NUMERIC' ],
				],
				[
					'relation' => 'OR',
					[ 'key' => '_active_until', 'compare' => 'NOT EXISTS' ],
					[ 'key' => '_active_until', 'value' => $now, 'compare' => '>=', 'type' => 'NUMERIC' ],
				],
			],
		] );

		$engine   = Pricing_Engine::instance();
		$policies = [];
		foreach ( $posts as $post ) {
			$policy = Policy::from_post( $post );
			if ( ! $policy->is_active_now() ) {
				continue;
			}
			if ( ! $policy->matches_product( $ctx->product, $engine ) ) {
				continue;
			}
			$policies[] = $policy;
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
	 * Bumps the cache version. Old entries become orphaned (different cache key) and
	 * TTL out via CACHE_TTL. Works on any object-cache backend regardless of whether
	 * `wp_cache_flush_group` is supported.
	 */
	public static function flush_cache(): void {
		$current = self::get_cache_version();
		update_option( self::CACHE_VERSION_OPTION, $current + 1, false );
	}
}
