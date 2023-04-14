<?php
/**
 * Newspack Post Primary Brand.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Admin;

use Newspack_Multibranded_Site\Taxonomy;
use Newspack_Multibranded_Site\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Post Primary Brand.
 */
class Post_Primary_Brand {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueues the scripts to the Post edit screen.
	 */
	public static function enqueue_scripts() {
		$screen = get_current_screen();
		if ( 'post' !== $screen->base || ! in_array( $screen->post_type, Taxonomy::POST_TYPES, true ) ) {
			return;
		}

		wp_enqueue_script(
			'newspack-post-primary-brand',
			plugins_url( '../../dist/postPrimaryBrand.js', __FILE__ ),
			array( 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n', 'wp-plugins', 'wp-url' ),
			filemtime( NEWSPACK_MULTIBRANDED_SITE_PLUGIN_DIR . '/dist/postPrimaryBrand.js' ),
			true
		);

		wp_localize_script(
			'newspack-post-primary-brand',
			'newspackPostPrimaryBrand',
			array(
				'adminURL'     => admin_url( 'admin.php?page=' . Admin::MULTI_BRANDED_PAGE_SLUG ),
				'taxonomySlug' => Taxonomy::SLUG,
				'metaKey'      => Taxonomy::PRIMARY_META_KEY,
				'hasYoast'     => class_exists( 'WPSEO_Primary_Term' ),
			)
		);
	}


}
