<?php
/**
 * Dynamic Pricing bootstrap.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

use Newspack\Dynamic_Pricing\Matchers\All_Subscriptions_Scope_Matcher;
use Newspack\Dynamic_Pricing\Matchers\Product_Ids_Scope_Matcher;
use Newspack\Dynamic_Pricing\Matchers\Category_Scope_Matcher;

defined( 'ABSPATH' ) || exit;

final class Dynamic_Pricing {
	const LOGGER_HEADER = 'NEWSPACK-DYNAMIC-PRICING';
	const CPT           = 'shop_pricing_policy';

	public static function init(): void {
		// CPT registers on the standard 'init' hook.
		add_action( 'init', [ __CLASS__, 'register_cpt' ] );

		// Engine wiring runs once on this method (which itself is called on plugins_loaded; see file end).
		$engine = Pricing_Engine::instance();
		$engine->set_repository( new CPT_Policy_Repository() );
		$engine->set_guardrails( new Pricing_Guardrails( new Bounds_Resolver() ) );
		$engine->register_scope( new All_Subscriptions_Scope_Matcher() );
		$engine->register_scope( new Product_Ids_Scope_Matcher() );
		$engine->register_scope( new Category_Scope_Matcher() );

		// Cache invalidation hooks.
		add_action( 'save_post_' . self::CPT, [ CPT_Policy_Repository::class, 'flush_cache' ] );
		add_action( 'deleted_post', [ __CLASS__, 'maybe_flush_cache_on_delete' ], 10, 2 );
	}

	public static function register_cpt(): void {
		register_post_type( self::CPT, [
			'label'           => __( 'Pricing Policies', 'newspack-plugin' ),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false,
			'show_in_rest'    => false,
			'supports'        => [ 'title', 'custom-fields' ],
			'capability_type' => 'product',
			'map_meta_cap'    => true,
		] );
	}

	public static function maybe_flush_cache_on_delete( int $post_id, \WP_Post $post ): void {
		if ( self::CPT === $post->post_type ) {
			CPT_Policy_Repository::flush_cache();
		}
	}
}

// File-end bootstrap idiom — mirrors Subscriptions_Tiers::init_hooks().
add_action( 'plugins_loaded', [ Dynamic_Pricing::class, 'init' ], 20 );
