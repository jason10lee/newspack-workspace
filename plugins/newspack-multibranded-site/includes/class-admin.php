<?php
/**
 * Newspack Multi-branded site plugin administration screen handling.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site;

/**
 * Class to handle the plugin admin pages
 */
class Admin {
	const MULTI_BRANDED_PAGE_SLUG = 'newspack-multi-branded-sites';

	/**
	 * Runs the initialization.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
	}

	/**
	 * Adds the admin page
	 *
	 * @return void
	 */
	public static function add_admin_menu() {
		$page_suffix = add_menu_page(
			__( 'Multi-branded site', 'newspack-multibranded-site' ),
			__( 'Multi-branded site', 'newspack-multibranded-site' ),
			'manage_options',
			self::MULTI_BRANDED_PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);

		add_action( 'load-' . $page_suffix, array( __CLASS__, 'admin_init' ) );
	}

	/**
	 * Renders the page content
	 *
	 * @return void
	 */
	public static function render_page() {
		echo '<div id="root"></div>';
	}

	/**
	 * Callback for the load admin page hook.
	 *
	 * @return void
	 */
	public static function admin_init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Add Newspack admin body class, necessary for wizard styling.
	 *
	 * @param string $classes Space-separated list of CSS classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$screen = get_current_screen();

		$is_newspack_screen = ( 'toplevel_page_newspack-' === substr( $screen->base, 0, 23 ) );
		if ( ! $screen || ! $is_newspack_screen ) {
			return $classes;
		}

		$classes .= ' admin_page_newspack-multi-branded-sites';

		return $classes;
	}

	/**
	 * Enqueue admin page assets.
	 *
	 * @param string $handler Page handler.
	 *
	 * @return void
	 */
	public static function enqueue_scripts( $handler ) {
		if ( false === strpos( $handler, self::MULTI_BRANDED_PAGE_SLUG ) ) {
			return;
		};

		\wp_register_script(
			self::MULTI_BRANDED_PAGE_SLUG,
			plugins_url( '../dist/admin.js', __FILE__ ),
			array( 'wp-components', 'wp-api-fetch' ),
			filemtime( NEWSPACK_MULTIBRANDED_SITE_PLUGIN_DIR . 'dist/admin.js' ),
			true
		);

		$plugin_data   = get_plugin_data( NEWSPACK_MULTIBRANDED_SITE_PLUGIN_FILE );
		$support_email = ( defined( 'NEWSPACK_SUPPORT_EMAIL' ) && NEWSPACK_SUPPORT_EMAIL ) ? NEWSPACK_SUPPORT_EMAIL : false;

		$urls = array(
			'dashboard'      => esc_url( admin_url( 'admin.php?page=' . self::MULTI_BRANDED_PAGE_SLUG ) ),
			'public_path'    => \Newspack\Newspack::plugin_url() . '/dist/',
			'bloginfo'       => array(
				'name' => get_bloginfo( 'name' ),
			),
			'plugin_version' => array(
				'label' => $plugin_data['Name'] . ' ' . $plugin_data['Version'],
			),
			'homepage'       => get_edit_post_link( get_option( 'page_on_front', false ) ),
			'site'           => get_site_url(),
			'support'        => esc_url( 'https://newspack.com/support/' ),
			'support_email'  => $support_email,
		);

		$aux_data = array(
			'is_e2e'              => class_exists( \Newspack\Starter_Content::class ) && \Newspack\Starter_Content::is_e2e(),
			'is_debug_mode'       => class_exists( \Newspack\Newspack::class ) && \Newspack\Newspack::is_debug_mode(),
			'has_completed_setup' => get_option( NEWSPACK_SETUP_COMPLETE ),
			'site_title'          => get_option( 'blogname' ),
		);

		wp_localize_script( self::MULTI_BRANDED_PAGE_SLUG, 'newspack_urls', $urls );
		wp_localize_script( self::MULTI_BRANDED_PAGE_SLUG, 'newspack_aux_data', $aux_data );

		\wp_enqueue_script( self::MULTI_BRANDED_PAGE_SLUG );

		\wp_register_style(
			self::MULTI_BRANDED_PAGE_SLUG,
			plugins_url( '../dist/admin.css', __FILE__ ),
			array( 'wp-components' ),
			filemtime( NEWSPACK_MULTIBRANDED_SITE_PLUGIN_DIR . 'dist/admin.css' )
		);
		\wp_style_add_data( self::MULTI_BRANDED_PAGE_SLUG, 'rtl', 'replace' );
		\wp_enqueue_style( self::MULTI_BRANDED_PAGE_SLUG );

		\wp_enqueue_style( // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			'tachyons',
			'https://unpkg.com/tachyons@4.12.0/css/tachyons.min.css'
		);
	}

}
