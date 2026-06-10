<?php
/**
 * Dynamic Pricing bootstrap.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

use Newspack\Dynamic_Pricing\Admin\Policy_Edit_UI;
use Newspack\Dynamic_Pricing\Matchers\All_Subscriptions_Scope_Matcher;
use Newspack\Dynamic_Pricing\Matchers\Product_Ids_Scope_Matcher;
use Newspack\Dynamic_Pricing\Matchers\Category_Scope_Matcher;
use Newspack\Dynamic_Pricing\Matchers\First_Time_Only_Condition_Matcher;
use Newspack\Dynamic_Pricing\Strategies\Simple_Price_Strategy;

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
		$engine->register_condition( new First_Time_Only_Condition_Matcher() );

		// Foundation-level strategy: no WCS dependency (the cycle signal is
		// surface-provided). WCS-specific strategies register in Subscriptions_Bootstrap.
		$engine->register( new Simple_Price_Strategy() );

		// Cart-time surface: foundation-level (no WCS dependency at registration time;
		// the strategy's applies_to() filters cart context to subscription products via scope).
		$engine->add_surface( new WooProduct_Surface() );
		WooProduct_Surface::init();

		// Cache invalidation hooks.
		add_action( 'save_post_' . self::CPT, [ CPT_Policy_Repository::class, 'flush_cache' ] );
		add_action( 'deleted_post', [ __CLASS__, 'maybe_flush_cache_on_delete' ], 10, 2 );

		// MVP admin UI (not in v1 spec; added for manual testing). See spec §14 for the
		// planned Wizard-based form that supersedes this.
		if ( is_admin() ) {
			Policy_Edit_UI::init();
		}
	}

	public static function register_cpt(): void {
		register_post_type( self::CPT, [
			'labels'          => [
				'name'                  => _x( 'Pricing Policies', 'post type general name', 'newspack-plugin' ),
				'singular_name'         => _x( 'Pricing Policy', 'post type singular name', 'newspack-plugin' ),
				'menu_name'             => _x( 'Pricing Policies', 'admin menu', 'newspack-plugin' ),
				'add_new'               => __( 'Add Policy', 'newspack-plugin' ),
				'add_new_item'          => __( 'Add New Pricing Policy', 'newspack-plugin' ),
				'edit_item'             => __( 'Edit Pricing Policy', 'newspack-plugin' ),
				'new_item'              => __( 'New Pricing Policy', 'newspack-plugin' ),
				'view_item'             => __( 'View Pricing Policy', 'newspack-plugin' ),
				'search_items'          => __( 'Search Pricing Policies', 'newspack-plugin' ),
				'not_found'             => __( 'No policies found.', 'newspack-plugin' ),
				'not_found_in_trash'    => __( 'No policies in Trash.', 'newspack-plugin' ),
				'all_items'             => __( 'All Pricing Policies', 'newspack-plugin' ),
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'menu_position'   => 56, // just below "Comments" / above WooCommerce
			'menu_icon'       => 'dashicons-tag',
			'show_in_rest'    => false,
			'supports'        => [ 'title' ],
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
