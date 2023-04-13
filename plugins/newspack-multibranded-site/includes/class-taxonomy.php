<?php
/**
 * Newspack Multi-branded site taxonomy.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site;

use WP_Term;

/**
 * Class to handle the brands taxonomy
 */
class Taxonomy {

	/**
	 * The taxonomy slug.
	 *
	 * @var string
	 */
	const SLUG = 'brand';

	/**
	 * The post types to which the taxonomy should be applied.
	 *
	 * @var array
	 */
	const POST_TYPES = array( 'post', 'page' );

	/**
	 * The meta key used to flag the primary brand.
	 *
	 * @var string
	 */
	const PRIMARY_META_KEY = '_primary_brand';

	/**
	 * The current brand, determined depending on the context on WP initiazliation.
	 *
	 * @var ?WP_Term
	 */
	private static $current_brand;

	/**
	 * Runs the initialization.
	 */
	public static function init() {
		add_action( 'setup_theme', [ __CLASS__, 'register_taxonomy' ] );
		if ( ! wp_using_themes() ) {
			add_action( 'init', [ __CLASS__, 'register_taxonomy' ] ); // For CLI and tests.
		}
		add_action( 'wp', [ __CLASS__, 'determine_current_brand' ] );
	}

	/**
	 * Get the current brand, depending on the context
	 *
	 * @return ?WP_Term The current brand term.
	 */
	public static function get_current() {
		if ( empty( self::$current_brand ) ) {
			self::determine_current_brand();
		}
		return self::$current_brand;
	}

	/**
	 * Registers the taxonomy
	 *
	 * @return void
	 */
	public static function register_taxonomy() {
		$labels = array(
			'name'              => _x( 'Brands', 'taxonomy general name', 'newspack-multibranded-site' ),
			'singular_name'     => _x( 'Brand', 'taxonomy singular name', 'newspack-multibranded-site' ),
			'search_items'      => __( 'Search Brands', 'newspack-multibranded-site' ),
			'all_items'         => __( 'All Brands', 'newspack-multibranded-site' ),
			'parent_item'       => __( 'Parent Brand', 'newspack-multibranded-site' ),
			'parent_item_colon' => __( 'Parent Brand:', 'newspack-multibranded-site' ),
			'edit_item'         => __( 'Edit Brand', 'newspack-multibranded-site' ),
			'update_item'       => __( 'Update Brand', 'newspack-multibranded-site' ),
			'add_new_item'      => __( 'Add New Brand', 'newspack-multibranded-site' ),
			'new_item_name'     => __( 'New Brand Name', 'newspack-multibranded-site' ),
			'menu_name'         => __( 'Brands', 'newspack-multibranded-site' ),
		);
		$params = array(
			'labels'             => $labels,
			'hierarchical'       => true, // True to have the checkbox UI instead of the tags UI.
			'publicly_queryable' => true,
			'show_in_nav_menus'  => true,
			'show_in_menu'       => false,
			'show_ui'            => true,
			'show_admin_column'  => true,
			'show_in_rest'       => true,
			'query_var'          => true,
			'capabilities'       => array(
				'manage_terms' => 'manage_options',
				'edit_terms'   => 'manage_options',
				'delete_terms' => 'manage_options',
				'assign_terms' => 'edit_posts',
			),
		);
		register_taxonomy( self::SLUG, self::POST_TYPES, $params );

		// Initialize metadata.
		Meta\Url::init();
		Meta\ShowPageOnFront::init();
		Meta\Post_Primary_Brand::init();
		Meta\Logo::init();
		Meta\Theme_Colors::init();
		Meta\Menus::init();
		Meta\User_Primary_Brand::init();
		Meta\Tag_Primary_Brand::init();
		Meta\Category_Primary_Brand::init();
	}

	/**
	 * Get the current brand based on a post.
	 *
	 * If a post has is of a supported post type and has only one brand, it will return this brand, otherwise it will return null.
	 *
	 * @param int|WP_Post $post_or_post_id The Post object or the post id.
	 * @return ?WP_Term The current brand for the post.
	 */
	public static function get_current_brand_for_post( $post_or_post_id ) {
		// Account for Brands with page on front.
		if ( $post_or_post_id instanceof WP_Term ) {
			return self::get_current_brand_for_term( $post_or_post_id );
		}

		$post = $post_or_post_id instanceof \WP_Post ? $post_or_post_id : get_post( $post_or_post_id );

		if ( ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return;
		}

		$terms = wp_get_post_terms( $post->ID, self::SLUG );

		if ( 1 === count( $terms ) ) {
			return $terms[0];
		}

		$post_primary_brand = get_post_meta( $post->ID, self::PRIMARY_META_KEY, true );

		if ( $post_primary_brand ) {
			$term = get_term( $post_primary_brand, self::SLUG );
			if ( $term instanceof WP_Term ) {
				return $term;
			}
		}
	}

	/**
	 * Get the current brand based on a term.
	 *
	 * If a term is a brand, it will return this brand
	 *
	 * @param int|WP_Term $term_or_term_id The Term object or the term id.
	 * @return ?WP_Term The current brand for the post.
	 */
	public static function get_current_brand_for_term( $term_or_term_id ) {
		$term = $term_or_term_id instanceof WP_Term ? $term_or_term_id : get_term( $term_or_term_id );
		if ( self::SLUG === $term->taxonomy ) {
			return $term;
		}
		if ( in_array( $term->taxonomy, [ 'category', 'post_tag' ], true ) ) {
			return self::recursive_search_term_primary_brand( $term );
		}
	}

	/**
	 * Finds the primary brand for a term, searching recursively through ancestors.
	 *
	 * @param WP_Term $term The Term.
	 * @return ?WP_Term The primary brand for the term.
	 */
	protected static function recursive_search_term_primary_brand( WP_Term $term ) {
		$primary_brand = get_term_meta( $term->term_id, self::PRIMARY_META_KEY, true );
		if ( $primary_brand ) {
			$brand = get_term( $primary_brand, self::SLUG );
			if ( $brand instanceof WP_Term ) {
				return $brand;
			}
		}
		if ( $term->parent ) {
			$parent = get_term( $term->parent, $term->taxonomy );
			if ( $parent instanceof WP_Term ) {
				return self::recursive_search_term_primary_brand( $parent );
			}
		}
	}

	/**
	 * Get the current brand based on an author.
	 *
	 * If the author has a custom primary brand, it will return this brand
	 *
	 * @param int $author_id The author ID.
	 * @return ?WP_Term The current brand for the post.
	 */
	public static function get_current_brand_for_author( $author_id ) {
		$author_brand = get_user_meta( $author_id, self::PRIMARY_META_KEY, true );
		if ( ! $author_brand ) {
			return;
		}
		$brand = get_term( $author_brand, self::SLUG );
		if ( $brand instanceof WP_Term ) {
			return $brand;
		}
	}

	/**
	 * Determines and stores the current brand depending on the current context.
	 *
	 * @return void
	 */
	public static function determine_current_brand() {
		if ( is_singular() ) {
			self::$current_brand = self::get_current_brand_for_post( get_queried_object() );
		} elseif ( is_tax() || is_category() || is_tag() ) {
			self::$current_brand = self::get_current_brand_for_term( get_queried_object() );
		} elseif ( is_author() ) {
			self::$current_brand = self::get_current_brand_for_author( get_queried_object_id() );
		} else {
			self::$current_brand = null;
		}
	}

}
