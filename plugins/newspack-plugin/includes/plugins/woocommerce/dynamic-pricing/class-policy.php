<?php
/**
 * Pricing Policy entity.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Value object representing a single pricing policy CPT row.
 *
 * `post_status='publish'` is the active/draft signal; there is no `_status` meta key.
 */
final class Policy {
	public string $id;
	public string $title;
	public string $strategy_id;
	public array $params         = [];
	public int $priority         = 100;
	public string $compose_mode  = 'min';
	public string $scope_type    = 'all_subscriptions';
	public array $scope_ids      = [];
	public ?int $active_from     = null;
	public ?int $active_until    = null;
	public array $conditions     = [];
	/**
	 * Whether the engine should communicate this policy to the reader (cart strikethrough,
	 * label badge, etc.). Default false (silent application).
	 */
	public bool $publicize       = false;

	/**
	 * Hydrate a Policy from a `shop_pricing_policy` post.
	 */
	public static function from_post( \WP_Post $post ): self {
		$p = new self();
		$p->id           = (string) $post->ID;
		$p->title        = $post->post_title;
		$p->strategy_id  = (string) get_post_meta( $post->ID, '_strategy_id', true );

		// Preserve class default (100) when meta is absent — `(int) ''` returns 0,
		// which would sort this policy first instead of in the middle.
		$priority_meta = get_post_meta( $post->ID, '_priority', true );
		$p->priority   = '' === $priority_meta ? 100 : (int) $priority_meta;

		$p->compose_mode = (string) get_post_meta( $post->ID, '_compose_mode', true ) ?: 'min';
		// Preserve the class default when meta is absent — '' would resolve no
		// scope matcher and silently kill the policy.
		$p->scope_type   = (string) get_post_meta( $post->ID, '_scope_type', true ) ?: $p->scope_type;
		$p->scope_ids    = self::resolve_scope_ids( $post->ID, $p->scope_type );

		$active_from  = get_post_meta( $post->ID, '_active_from', true );
		$active_until = get_post_meta( $post->ID, '_active_until', true );
		$p->active_from  = '' === $active_from  ? null : (int) $active_from;
		$p->active_until = '' === $active_until ? null : (int) $active_until;

		$p->publicize = '1' === (string) get_post_meta( $post->ID, '_publicize', true );

		$params     = get_post_meta( $post->ID, '_params', true );
		$conditions = get_post_meta( $post->ID, '_conditions', true );
		$p->params     = is_string( $params )     ? ( json_decode( $params, true ) ?: [] ) : ( is_array( $params ) ? $params : [] );
		$p->conditions = is_string( $conditions ) ? ( json_decode( $conditions, true ) ?: [] ) : ( is_array( $conditions ) ? $conditions : [] );

		return $p;
	}

	/**
	 * Whether the policy's active window includes the current moment.
	 */
	public function is_active_now(): bool {
		$now = time();
		if ( null !== $this->active_from  && $this->active_from  > $now ) {
			return false;
		}
		if ( null !== $this->active_until && $this->active_until < $now ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether the configured scope matches the given product, via the engine's scope matcher registry.
	 */
	public function matches_product( \WC_Product $product, Pricing_Engine $engine ): bool {
		$matcher = $engine->scope_matcher( $this->scope_type );
		if ( ! $matcher ) {
			return false;
		}
		// resolve_scope_ids() already returns [] for scope types without ids, so the
		// matcher value is always just the ids — matchers that don't need them ignore it.
		return $matcher->matches( $product, $this->scope_ids );
	}

	/**
	 * Whether every configured condition passes for the given context, via the engine's matcher registry.
	 */
	public function passes_conditions( Pricing_Context $ctx, Pricing_Engine $engine ): bool {
		foreach ( $this->conditions as $condition ) {
			if ( ! isset( $condition['type'] ) ) {
				return false;
			}
			$matcher = $engine->condition_matcher( $condition['type'] );
			if ( ! $matcher ) {
				return false;
			}
			if ( ! $matcher->matches( $ctx, $condition['value'] ?? null ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Read scope IDs from meta based on scope type.
	 */
	private static function resolve_scope_ids( int $post_id, string $scope_type ): array {
		return match ( $scope_type ) {
			'product_ids' => array_map( 'intval', (array) get_post_meta( $post_id, '_scope_product_id', false ) ),
			'category'    => array_map( 'intval', (array) get_post_meta( $post_id, '_scope_category_id', false ) ),
			default       => [],
		};
	}
}
