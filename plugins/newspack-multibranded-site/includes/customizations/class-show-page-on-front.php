<?php
/**
 * Newspack Multi-branded site taxonomy.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Customizations;

use Newspack_Multibranded_Site\Meta\ShowPageOnFront as ShowPageOnFront_Meta;
use Newspack_Multibranded_Site\Taxonomy;

/**
 * Class to handle the ShowPageOnFront Customization
 */
class ShowPageOnFront {

	/**
	 * Whether the query has been filtered in the current request
	 *
	 * @var boolean
	 */
	protected static $filtered = false;

	/**
	 * Initializes
	 */
	public static function init() {
		add_action( 'pre_get_posts', [ __CLASS__, 'pre_get_posts' ], 20 );
		add_filter( 'body_class', [ __CLASS__, 'body_class' ], 10, 2 );
		add_filter( 'template_include', [ __CLASS__, 'template_include' ] );
	}

	/**
	 * Change the query if we want to display a page on front
	 *
	 * @param WP_Query $query The WP_Query object.
	 * @return void
	 */
	public static function pre_get_posts( &$query ) {
		if ( ! $query->is_main_query() ) {
			return;
		}

		if ( empty( $query->query[ Taxonomy::SLUG ] ) ) {
			return;
		}

		$brand_slug = $query->query[ Taxonomy::SLUG ];
		$term       = get_term_by( 'slug', $brand_slug, Taxonomy::SLUG );

		if ( $term ) {
			$show_page_on_front = get_term_meta( $term->term_id, ShowPageOnFront_Meta::get_key(), true );
			if ( ! empty( $show_page_on_front ) ) {
				$page = get_page( $show_page_on_front );
				if ( $page ) {
					$query->query      = [ 'page_id' => $page->ID ];
					$query->query_vars = $query->query;
					$query->parse_query();
					self::$filtered = true;
				}
			}
		}
	}

	/**
	 * Fixes the body classes when the term displays a page on front
	 *
	 * @param string[] $classes An array of body class names.
	 * @param string[] $class   An array of additional class names added to the body.
	 * @return array
	 */
	public static function body_class( $classes, $class ) {
		$queried_object = get_queried_object();
		if ( ! $queried_object instanceof \WP_Term || Taxonomy::SLUG !== $queried_object->taxonomy ) {
			return $classes;
		}

		$show_page_on_front = get_term_meta( $queried_object->term_id, ShowPageOnFront_Meta::get_key(), true );

		if ( ! $show_page_on_front ) {
			return $classes;
		}

		$classes_to_remove = [ 'archive', '-template-default', 'page-id-' . $queried_object->term_id ];

		$classes = array_diff( $classes, $classes_to_remove );

		$classes = array_merge(
			[
				'newspack-front-page',
				'page-template-default',
				'page-id-' . $show_page_on_front,
			],
			$classes
		);

		return $classes;
	}

	/**
	 * Filters the template to use when displaying a page on front
	 *
	 * @param string $template The template file.
	 * @return string
	 */
	public static function template_include( $template ) {
		if ( is_page() && self::$filtered ) {
			$template = get_front_page_template();
		}

		return $template;
	}


}
