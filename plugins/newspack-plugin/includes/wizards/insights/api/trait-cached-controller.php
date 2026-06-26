<?php
/**
 * Newspack Insights — Cached_Controller_Trait.
 *
 * Wraps a REST controller's window-bound payload in the Insights cache
 * envelope and registers a sibling POST /{tab}/refresh route.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

trait Cached_Controller_Trait {

	/**
	 * Source classification for this controller's data.
	 */
	abstract protected function cache_source(): string;

	/**
	 * Stable tab slug used as the cache namespace.
	 */
	abstract protected function tab_slug(): string;

	/**
	 * Response-shape version mixed into the cache key. Override and bump it when
	 * a controller's payload shape changes so cached payloads from a prior shape
	 * don't survive a deploy. Default empty: no version component, so controllers
	 * that don't opt in keep their existing key shape (no surprise cache-bust).
	 */
	protected function cache_schema_version(): string {
		return '';
	}

	/**
	 * Cache-aware GET wrapper.
	 *
	 * @param WP_REST_Request $request       Incoming request.
	 * @param callable        $build_payload () => array.
	 */
	protected function cached_response( WP_REST_Request $request, callable $build_payload ): WP_REST_Response {
		$envelope = Cache::store(
			$this->tab_slug(),
			$this->cache_source(),
			$this->versioned_cache_key_parts( $request ),
			$build_payload
		);
		$response = rest_ensure_response( self::wrap_envelope( $envelope ) );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * POST /{tab}/refresh wrapper. Always returns a 200 envelope — when the
	 * BQ cooldown blocks a refresh, `cache.cooldown_until` is populated in
	 * the envelope so the client can render the throttle UI without relying
	 * on a 4xx response (Atomic's edge mutates 4xx bodies).
	 *
	 * @param WP_REST_Request $request       Incoming request.
	 * @param callable        $build_payload () => array.
	 */
	protected function refresh_response( WP_REST_Request $request, callable $build_payload ): WP_REST_Response {
		$envelope = Cache::refresh(
			$this->tab_slug(),
			$this->cache_source(),
			$this->versioned_cache_key_parts( $request ),
			$build_payload
		);
		$response = rest_ensure_response( self::wrap_envelope( $envelope ) );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * Canonical window components used as the cache key.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array{0:string,1:string,2:?string,3:?string}
	 */
	private static function cache_key_parts( WP_REST_Request $request ): array {
		return [
			(string) $request->get_param( 'start' ),
			(string) $request->get_param( 'end' ),
			$request->get_param( 'compare_start' ) ? (string) $request->get_param( 'compare_start' ) : null,
			$request->get_param( 'compare_end' ) ? (string) $request->get_param( 'compare_end' ) : null,
		];
	}

	/**
	 * Canonical window key parts with the response-shape version prepended when
	 * the controller sets one. An empty version leaves the window parts
	 * untouched, so a non-overriding controller's cache key is unchanged.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array
	 */
	private function versioned_cache_key_parts( WP_REST_Request $request ): array {
		$parts   = self::cache_key_parts( $request );
		$version = $this->cache_schema_version();
		if ( '' !== $version ) {
			array_unshift( $parts, $version );
		}
		return $parts;
	}

	/**
	 * Build the outer {cache,data} envelope from a Cache envelope.
	 *
	 * @param array $envelope Cache::store() / Cache::refresh() return.
	 * @return array
	 */
	private static function wrap_envelope( array $envelope ): array {
		return [
			'cache' => [
				'source'         => $envelope['source'],
				'computed_at'    => $envelope['computed_at'],
				'cooldown_until' => $envelope['cooldown_until'],
			],
			'data'  => $envelope['payload'],
		];
	}
}
