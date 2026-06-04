<?php
/**
 * Newspack My Account core shell.
 *
 * Owns the My Account page, endpoints, page detection, URL generation, tab
 * registry, and content dispatch independently of WooCommerce. When
 * WooCommerce is active, every accessor delegates to WooCommerce so behavior
 * is unchanged; when it is absent, the shell runs natively.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * My_Account class.
 */
class My_Account {
	/**
	 * Option that stores the native account page ID (used only when WooCommerce
	 * is not active).
	 */
	const PAGE_ID_OPTION = 'newspack_my_account_page_id';

	/**
	 * Core endpoint slug constants.
	 */
	const ENDPOINT_EDIT_ACCOUNT   = 'edit-account';
	const ENDPOINT_DELETE_ACCOUNT = 'newspack-delete-account';

	/**
	 * Option storing the last-registered endpoint slug set (for flush detection).
	 */
	const ENDPOINTS_OPTION = 'newspack_my_account_endpoint_slugs';

	/**
	 * Whether WooCommerce owns the My Account shell.
	 *
	 * @return bool
	 */
	public static function woocommerce_owns_shell() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_page_permalink' );
	}

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		\add_action( 'init', [ __CLASS__, 'register_shortcode' ] );
		if ( ! self::woocommerce_owns_shell() ) {
			\add_filter( 'page_template', [ __CLASS__, 'page_template' ], 11 );
			\add_action( 'init', [ __CLASS__, 'register_endpoints' ], 6 );
			\add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );
		}
	}

	/**
	 * Get the My Account page ID.
	 *
	 * Resolution order: WooCommerce account page when Woo is active, else the
	 * native Newspack account page.
	 *
	 * @return int Page ID, or 0 if none is set.
	 */
	public static function get_page_id() {
		if ( self::woocommerce_owns_shell() ) {
			return (int) \get_option( 'woocommerce_myaccount_page_id', 0 );
		}
		return (int) \get_option( self::PAGE_ID_OPTION, 0 );
	}

	/**
	 * Get the native account page ID, creating the page if it does not exist.
	 *
	 * Only used when WooCommerce is not active.
	 *
	 * @return int Page ID.
	 */
	public static function get_or_create_page() {
		$page_id = (int) \get_option( self::PAGE_ID_OPTION, 0 );
		if ( $page_id && 'page' === \get_post_type( $page_id ) ) {
			return $page_id;
		}

		$page_id = \wp_insert_post(
			[
				'post_type'      => 'page',
				'post_title'     => \esc_html__( 'My Account', 'newspack-plugin' ),
				'post_content'   => '[newspack_my_account]',
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			]
		);

		if ( \is_numeric( $page_id ) && $page_id ) {
			\update_option( self::PAGE_ID_OPTION, (int) $page_id );
			\update_post_meta( $page_id, 'newspack_hide_page_title', true );
			return (int) $page_id;
		}

		return 0;
	}

	/**
	 * Whether the current request is the My Account page (or one of its
	 * endpoints).
	 *
	 * @return bool
	 */
	public static function is_account_page() {
		if ( self::woocommerce_owns_shell() && function_exists( 'is_account_page' ) ) {
			return \is_account_page();
		}
		$page_id = self::get_page_id();
		return $page_id && \is_page( $page_id );
	}

	/**
	 * Get the URL for a My Account endpoint.
	 *
	 * @param string $endpoint Endpoint slug. Empty string returns the base
	 *                         account page URL.
	 * @param string $value    Optional endpoint value (e.g. a subscription ID).
	 * @return string URL, or empty string if the page is not set.
	 */
	public static function get_endpoint_url( $endpoint = '', $value = '' ) {
		if ( self::woocommerce_owns_shell() ) {
			if ( '' === $endpoint || 'dashboard' === $endpoint ) {
				return \wc_get_account_endpoint_url( 'dashboard' );
			}
			return \wc_get_endpoint_url( $endpoint, $value, \wc_get_page_permalink( 'myaccount' ) );
		}

		$page_id = self::get_page_id();
		if ( ! $page_id ) {
			return '';
		}
		$permalink = \get_permalink( $page_id );
		if ( ! $permalink || '' === $endpoint ) {
			return $permalink ? $permalink : '';
		}

		if ( \get_option( 'permalink_structure' ) ) {
			$url = \trailingslashit( $permalink ) . $endpoint;
			if ( '' !== $value ) {
				$url .= '/' . $value;
			}
			return \user_trailingslashit( $url );
		}
		return \add_query_arg( $endpoint, $value, $permalink );
	}

	/**
	 * Get the registered endpoint slugs => labels for the native shell.
	 *
	 * Core tabs plus any integration-declared endpoints. Filterable so
	 * integrations and sites can extend the set.
	 *
	 * @return array<string,string> slug => label.
	 */
	public static function get_endpoints() {
		$endpoints = [
			self::ENDPOINT_EDIT_ACCOUNT   => \__( 'Account details', 'newspack-plugin' ),
			self::ENDPOINT_DELETE_ACCOUNT => \__( 'Delete account', 'newspack-plugin' ),
		];
		/**
		 * Filters the My Account endpoint slugs => labels (native shell).
		 *
		 * @param array<string,string> $endpoints slug => label.
		 */
		return \apply_filters( 'newspack_my_account_endpoints', $endpoints );
	}

	/**
	 * Get the ordered set of navigation tabs (slug => label).
	 *
	 * Dashboard first, then endpoints (core + integration), then logout last.
	 *
	 * @return array<string,string>
	 */
	public static function get_tabs() {
		$tabs = array_merge(
			[ '' => \__( 'Account', 'newspack-plugin' ) ],
			self::get_endpoints(),
			[ 'customer-logout' => \__( 'Sign out', 'newspack-plugin' ) ]
		);
		/**
		 * Filters the ordered My Account navigation tabs.
		 *
		 * @param array<string,string> $tabs slug => label.
		 */
		return \apply_filters( 'newspack_my_account_tabs', $tabs );
	}

	/**
	 * Register rewrite endpoints for the native shell.
	 */
	public static function register_endpoints() {
		$slugs = array_keys( self::get_endpoints() );
		foreach ( $slugs as $slug ) {
			\add_rewrite_endpoint( $slug, EP_PAGES );
		}

		$current = $slugs;
		sort( $current );
		$previous = \get_option( self::ENDPOINTS_OPTION, [] );
		if ( ! is_array( $previous ) ) {
			$previous = [];
		}
		sort( $previous );
		if ( $current !== $previous ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- only fires when the slug set changes.
			\flush_rewrite_rules( false );
			\update_option( self::ENDPOINTS_OPTION, $current );
		}
	}

	/**
	 * Add the endpoint slugs to the public query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		foreach ( array_keys( self::get_endpoints() ) as $slug ) {
			$vars[] = $slug;
		}
		return $vars;
	}

	/**
	 * Get the current endpoint slug from the query, or '' for the dashboard.
	 *
	 * @return string
	 */
	public static function get_current_endpoint() {
		global $wp;
		foreach ( array_keys( self::get_endpoints() ) as $slug ) {
			if ( isset( $wp->query_vars[ $slug ] ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Register the [newspack_my_account] shortcode.
	 */
	public static function register_shortcode() {
		\add_shortcode( 'newspack_my_account', [ __CLASS__, 'render_page' ] );
	}

	/**
	 * Use the blank My Account page template on the native account page.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public static function page_template( $template ) {
		if ( ! self::is_account_page() || ! \is_user_logged_in() ) {
			return $template;
		}
		return NEWSPACK_ABSPATH . 'includes/plugins/woocommerce/my-account/templates/v1/my-account.php';
	}

	/**
	 * Render the My Account page body.
	 *
	 * Outputs the navigation and the content for the current endpoint. Used by
	 * the [newspack_my_account] shortcode and block when WooCommerce is absent.
	 *
	 * @return string Rendered HTML.
	 */
	public static function render_page() {
		if ( ! \is_user_logged_in() ) {
			return '';
		}

		ob_start();
		echo '<div class="newspack-my-account newspack-ui">';
		self::render_navigation();
		echo '<div class="newspack-my-account__content woocommerce-MyAccount-content">';
		self::render_content();
		echo '</div>';
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Render the native navigation menu.
	 */
	protected static function render_navigation() {
		$current = self::get_current_endpoint();
		echo '<nav class="woocommerce-MyAccount-navigation newspack-ui" aria-label="' . \esc_attr__( 'Account pages', 'newspack-plugin' ) . '">';
		echo '<ul>';
		foreach ( self::get_tabs() as $slug => $label ) {
			if ( 'customer-logout' === $slug ) {
				$url = \wp_logout_url( \home_url( '/' ) );
			} else {
				$url = self::get_endpoint_url( $slug );
			}
			$is_current = ( $slug === $current );
			printf(
				'<li class="%1$s"><a href="%2$s"%3$s>%4$s</a></li>',
				\esc_attr( $is_current ? 'is-active' : '' ),
				\esc_url( $url ),
				$is_current ? ' aria-current="page"' : '',
				\esc_html( $label )
			);
		}
		echo '</ul>';
		echo '</nav>';
	}

	/**
	 * Render the content for the current endpoint.
	 *
	 * Core endpoints render their own templates; the dashboard renders the
	 * default landing. Integration endpoints are rendered by the
	 * newspack_my_account_content action.
	 */
	public static function render_content() {
		$endpoint = self::get_current_endpoint();

		switch ( $endpoint ) {
			case self::ENDPOINT_EDIT_ACCOUNT:
				self::render_account_settings();
				break;
			case self::ENDPOINT_DELETE_ACCOUNT:
				self::render_delete_account();
				break;
			case '':
				self::render_dashboard();
				break;
		}

		/**
		 * Fires when rendering My Account content for an endpoint. Integrations
		 * hook this to render their tab body when their slug is current.
		 *
		 * @param string $endpoint Current endpoint slug ('' for dashboard).
		 */
		\do_action( 'newspack_my_account_content', $endpoint );
	}

	/**
	 * Render the dashboard landing. Stub; refined in a later task.
	 */
	protected static function render_dashboard() {
		echo '<p>' . \esc_html__( 'Welcome to your account.', 'newspack-plugin' ) . '</p>';
	}

	/**
	 * Render the account settings tab. Implemented in a later task.
	 */
	protected static function render_account_settings() {
		// Implemented in a later task.
	}

	/**
	 * Render the delete-account tab. Implemented in a later task.
	 */
	protected static function render_delete_account() {
		// Implemented in a later task.
	}
}

My_Account::init();
