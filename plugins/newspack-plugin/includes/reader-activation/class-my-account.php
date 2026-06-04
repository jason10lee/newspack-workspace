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
			\add_action( 'template_redirect', [ __CLASS__, 'redirect_dashboard_to_account_details' ], 5 );
			\add_action( 'template_redirect', [ __CLASS__, 'handle_form_submissions' ] );
			\add_action( 'admin_init', [ __CLASS__, 'maybe_provision_page' ] );
			\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 11 );
			\add_filter( 'body_class', [ __CLASS__, 'add_body_class' ] );
			\add_filter( 'show_admin_bar', [ __CLASS__, 'hide_admin_bar' ] ); // phpcs:ignore WordPressVIPMinimum.UserExperience.AdminBarRemoval.RemovalDetected
		}
	}

	/**
	 * Enqueue My Account styles on the native account page.
	 *
	 * Reuses the compiled My Account stylesheet (dist/my-account-v1.css). The
	 * WooCommerce path enqueues this via My_Account_UI_V1; when WooCommerce is
	 * absent the native shell must do it.
	 */
	public static function enqueue_assets() {
		if ( ! self::is_account_page() || ! \is_user_logged_in() ) {
			return;
		}
		\wp_enqueue_style(
			'newspack-my-account-v1',
			\Newspack\Newspack::plugin_url() . '/dist/my-account-v1.css',
			[ 'newspack-ui' ],
			NEWSPACK_PLUGIN_VERSION
		);
	}

	/**
	 * Add My Account body classes on the native account page.
	 *
	 * Mirrors My_Account_UI_V1::add_body_class() so the layout CSS (scoped to
	 * these classes) applies on the native path.
	 *
	 * @param array $classes Body classes.
	 * @return array
	 */
	public static function add_body_class( $classes ) {
		if ( ! self::is_account_page() ) {
			return $classes;
		}
		if ( \is_user_logged_in() ) {
			$classes[] = 'newspack-ui';
		}
		$classes[] = 'newspack-my-account';
		$classes[] = 'newspack-my-account--v1';
		if ( ! \is_user_logged_in() ) {
			$classes[] = 'newspack-my-account--logged-out';
		} else {
			$classes[] = 'newspack-my-account--logged-in';
		}
		return $classes;
	}

	/**
	 * Hide the WordPress admin bar on the native My Account page.
	 *
	 * @param bool $show Whether to show the admin bar.
	 * @return bool
	 */
	public static function hide_admin_bar( $show ) {
		if ( self::is_account_page() ) {
			return false;
		}
		return $show;
	}

	/**
	 * Ensure the native account page exists when running without WooCommerce.
	 *
	 * Self-healing: runs on admin_init, creates the page at most once (guarded
	 * by the stored option), and only when Reader Activation is enabled.
	 */
	public static function maybe_provision_page() {
		if ( ! Reader_Activation::is_enabled() ) {
			return;
		}
		$page_id = (int) \get_option( self::PAGE_ID_OPTION, 0 );
		if ( $page_id && 'page' === \get_post_type( $page_id ) ) {
			return;
		}
		self::get_or_create_page();
	}

	/**
	 * Handle the native account-settings save (Woo absent).
	 *
	 * Updates display name and email. Returns the updated user ID, or 0 on
	 * failure / invalid nonce.
	 *
	 * @return int
	 */
	public static function handle_save_account() {
		if ( ! \is_user_logged_in() ) {
			return 0;
		}
		$nonce = isset( $_POST['newspack_my_account_save_nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['newspack_my_account_save_nonce'] ) ) : '';
		if ( ! \wp_verify_nonce( $nonce, 'newspack_my_account_save' ) ) {
			return 0;
		}

		$user_id = \get_current_user_id();
		$args    = [ 'ID' => $user_id ];
		if ( isset( $_POST['account_display_name'] ) ) {
			$args['display_name'] = \sanitize_text_field( \wp_unslash( $_POST['account_display_name'] ) );
		}
		if ( isset( $_POST['account_email'] ) ) {
			$email = \sanitize_email( \wp_unslash( $_POST['account_email'] ) );
			if ( \is_email( $email ) ) {
				$args['user_email'] = $email;
			}
		}
		$result = \wp_update_user( $args );
		return \is_wp_error( $result ) ? 0 : $user_id;
	}

	/**
	 * Route native form POSTs to their handlers on the `template_redirect` hook.
	 *
	 * The form `action` value is only used for routing; each target handler runs
	 * its own nonce verification before mutating any data.
	 */
	public static function handle_form_submissions() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- routing only; the dispatched handlers verify their own nonces.
		if ( empty( $_POST['action'] ) || ! self::is_account_page() ) {
			return;
		}
		$action = \sanitize_text_field( \wp_unslash( $_POST['action'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( 'newspack_my_account_save_account' === $action ) {
			self::handle_save_account();
			\wp_safe_redirect( self::get_endpoint_url( self::ENDPOINT_EDIT_ACCOUNT ) );
			exit;
		}
		if ( 'newspack_my_account_request_delete' === $action ) {
			self::handle_delete_request();
		}
	}

	/**
	 * Redirect the base account page to the Account details endpoint.
	 *
	 * The dashboard has no standalone view; Account details is the default.
	 */
	public static function redirect_dashboard_to_account_details() {
		if ( ! \is_user_logged_in() || ! self::is_account_page() ) {
			return;
		}
		if ( '' !== self::get_current_endpoint() ) {
			return;
		}
		\wp_safe_redirect( self::get_endpoint_url( self::ENDPOINT_EDIT_ACCOUNT ) );
		exit;
	}

	/**
	 * Handle the native delete-account request (Woo absent): send the existing
	 * deletion email if available, else delete after confirmation.
	 */
	public static function handle_delete_request() {
		if ( ! \is_user_logged_in() ) {
			return;
		}
		$nonce = isset( $_POST['newspack_my_account_delete_nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['newspack_my_account_delete_nonce'] ) ) : '';
		if ( ! \wp_verify_nonce( $nonce, 'newspack_my_account_delete' ) ) {
			return;
		}
		$user = \wp_get_current_user();
		if ( class_exists( 'Newspack\WooCommerce_My_Account' ) && method_exists( 'Newspack\WooCommerce_My_Account', 'send_delete_account_email' ) ) {
			WooCommerce_My_Account::send_delete_account_email( $user );
		}
		\wp_safe_redirect( self::get_endpoint_url( self::ENDPOINT_EDIT_ACCOUNT ) );
		exit;
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
	 * Account details first, then integration endpoints, then logout last. The
	 * dashboard and the delete-account endpoint are intentionally excluded:
	 * the base account URL redirects to Account details, and account deletion
	 * lives within the Account details screen.
	 *
	 * @return array<string,string>
	 */
	public static function get_tabs() {
		$endpoints = self::get_endpoints();
		unset( $endpoints[ self::ENDPOINT_DELETE_ACCOUNT ] );
		$tabs = array_merge(
			$endpoints,
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
		echo '<div class="woocommerce">';
		self::render_navigation();
		echo '<div class="newspack-my-account__content woocommerce-MyAccount-content">';
		self::render_content();
		echo '</div>';
		echo '</div>';
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Render the native navigation menu.
	 *
	 * Mirrors the structure of templates/v1/navigation.php so the sidebar CSS
	 * (dist/my-account-v1.css) applies, using native tab data instead of
	 * WooCommerce menu functions.
	 */
	protected static function render_navigation() {
		$current = self::get_current_endpoint();
		$tabs    = self::get_tabs();
		$logout  = null;
		if ( isset( $tabs['customer-logout'] ) ) {
			$logout = $tabs['customer-logout'];
			unset( $tabs['customer-logout'] );
		}
		$site_logo = \get_site_icon_url( 96 );
		?>
		<nav class="woocommerce-MyAccount-navigation newspack-ui" aria-label="<?php \esc_attr_e( 'Account pages', 'newspack-plugin' ); ?>">
			<div class="newspack-my-account__navigation-header">
				<?php if ( ! empty( $site_logo ) ) : ?>
					<a class="newspack-my-account__site-logo" href="<?php echo \esc_url( \home_url( '/' ) ); ?>" title="<?php \esc_attr_e( 'Back to Homepage', 'newspack-plugin' ); ?>">
						<img src="<?php echo \esc_url( $site_logo ); ?>" alt="" />
					</a>
				<?php endif; ?>
				<a href="<?php echo \esc_url( \home_url( '/' ) ); ?>" class="newspack-my-account__home-link newspack-ui__button newspack-ui__button--small newspack-ui__button--ghost-light">
					<?php Newspack_UI_Icons::print_svg( 'chevronLeft' ); ?>
					<?php \esc_html_e( 'Back to Homepage', 'newspack-plugin' ); ?>
				</a>
				<ul>
					<?php foreach ( $tabs as $newspack_tab_slug => $newspack_tab_label ) : ?>
						<?php $is_current = ( $newspack_tab_slug === $current ) || ( '' === $current && self::ENDPOINT_EDIT_ACCOUNT === $newspack_tab_slug ); ?>
						<li class="<?php echo \esc_attr( $is_current ? 'is-active' : '' ); ?>">
							<a href="<?php echo \esc_url( self::get_endpoint_url( $newspack_tab_slug ) ); ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?> class="newspack-ui__button newspack-ui__button--small <?php echo $is_current ? 'newspack-ui__button--accent' : 'newspack-ui__button--ghost'; ?>">
								<?php echo \esc_html( $newspack_tab_label ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php if ( $logout ) : ?>
			<div class="newspack-my-account__navigation-footer">
				<ul>
					<li class="woocommerce-MyAccount-navigation-link woocommerce-MyAccount-navigation-link--customer-logout">
						<a href="<?php echo \esc_url( \wp_logout_url( \home_url( '/' ) ) ); ?>" class="newspack-ui__button newspack-ui__button--small newspack-ui__button--ghost">
							<?php echo \esc_html( $logout ); ?>
							<?php Newspack_UI_Icons::print_svg( 'logout' ); ?>
						</a>
					</li>
				</ul>
			</div>
			<?php endif; ?>
		</nav>
		<?php
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
	 * Render the native account settings form (display name, email, password
	 * link). Email-change verification reuses the shared handler.
	 */
	protected static function render_account_settings() {
		// When arriving from the account-deletion email link, show the confirmation form.
		if ( isset( $_GET[ WooCommerce_My_Account::DELETE_ACCOUNT_FORM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is verified in render_delete_confirmation().
			self::render_delete_confirmation();
			return;
		}

		$user = \wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return;
		}
		?>
		<section id="account-profile">
			<h4 class="newspack-ui__font--m newspack-ui__spacing-top--0"><?php \esc_html_e( 'Profile', 'newspack-plugin' ); ?></h4>
			<form class="woocommerce-EditAccountForm edit-profile newspack-my-account__settings-form" action="" method="post">
				<p class="woocommerce-form-row form-row form-row-wide">
					<label for="account_display_name"><?php \esc_html_e( 'Display name', 'newspack-plugin' ); ?></label>
					<input type="text" class="woocommerce-Input input-text" name="account_display_name" id="account_display_name" value="<?php echo \esc_attr( $user->display_name ); ?>" />
				</p>
				<p class="woocommerce-form-row form-row form-row-wide">
					<label for="account_email"><?php \esc_html_e( 'Email address', 'newspack-plugin' ); ?>&nbsp;<span class="required">*</span></label>
					<input type="email" class="woocommerce-Input input-text" name="account_email" id="account_email" value="<?php echo \esc_attr( $user->user_email ); ?>" required />
				</p>
				<?php
				/** Lets integrations add fields, mirroring the Woo template hook. */
				\do_action( 'newspack_woocommerce_edit_account_form_fields' );
				?>
				<p class="woocommerce-buttons-card">
					<?php \wp_nonce_field( 'newspack_my_account_save', 'newspack_my_account_save_nonce' ); ?>
					<input type="hidden" name="action" value="newspack_my_account_save_account" />
					<button type="submit" class="newspack-ui__button newspack-ui__button--primary"><?php \esc_html_e( 'Update profile', 'newspack-plugin' ); ?></button>
				</p>
			</form>
		</section>
		<section id="delete-account">
			<h4 class="newspack-ui__font--m is-destructive"><?php \esc_html_e( 'Delete account', 'newspack-plugin' ); ?></h4>
			<p><?php \esc_html_e( 'Please note, account deletion is final, and there will be no way to restore your account.', 'newspack-plugin' ); ?></p>
			<p class="woocommerce-buttons-card">
				<a class="newspack-ui__button newspack-ui__button--destructive" href="<?php echo \esc_url( self::get_endpoint_url( self::ENDPOINT_DELETE_ACCOUNT ) ); ?>"><?php \esc_html_e( 'Delete account', 'newspack-plugin' ); ?></a>
			</p>
		</section>
		<?php
	}

	/**
	 * Render the account-deletion confirmation form (Woo absent).
	 *
	 * Reached when the reader clicks the link in the account-deletion email,
	 * which lands on the edit-account endpoint carrying the deletion nonce and
	 * token. Mirrors the safe happy-path of the Woo delete-account template but
	 * uses no WooCommerce functions. Submitting the form is processed by
	 * WooCommerce_My_Account::handle_delete_account() on template_redirect.
	 */
	protected static function render_delete_confirmation() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce is verified below before any output of the form.
		$nonce = isset( $_GET[ WooCommerce_My_Account::DELETE_ACCOUNT_FORM ] ) ? \sanitize_text_field( \wp_unslash( $_GET[ WooCommerce_My_Account::DELETE_ACCOUNT_FORM ] ) ) : '';
		if ( ! \wp_verify_nonce( $nonce, WooCommerce_My_Account::DELETE_ACCOUNT_FORM ) ) {
			echo '<p>' . \esc_html__( 'Invalid request.', 'newspack-plugin' ) . '</p>';
			return;
		}

		$token           = isset( $_GET['token'] ) ? \sanitize_text_field( \wp_unslash( $_GET['token'] ) ) : '';
		$transient_token = \get_transient( 'np_reader_account_delete_' . \get_current_user_id() );
		if ( ! $token || ! $transient_token || $token !== $transient_token ) {
			echo '<p>' . \esc_html__( 'Invalid request.', 'newspack-plugin' ) . '</p>';
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<section id="delete-account-confirm">
			<h4 class="newspack-ui__font--m is-destructive"><?php \esc_html_e( 'Delete account', 'newspack-plugin' ); ?></h4>
			<p><?php \esc_html_e( 'Confirm to delete your account permanently.', 'newspack-plugin' ); ?></p>
			<p><?php \esc_html_e( 'Deleting your account will also cancel any newsletter subscriptions and recurring payments.', 'newspack-plugin' ); ?></p>
			<p><strong><?php \esc_html_e( 'Caution, this action is irreversible!', 'newspack-plugin' ); ?></strong></p>
			<form method="post">
				<input type="hidden" name="<?php echo \esc_attr( WooCommerce_My_Account::DELETE_ACCOUNT_FORM ); ?>" value="<?php echo \esc_attr( $nonce ); ?>" />
				<input type="hidden" name="token" value="<?php echo \esc_attr( $token ); ?>" />
				<input type="hidden" name="confirm_delete" value="1" />
				<button type="submit" class="newspack-ui__button newspack-ui__button--destructive"><?php \esc_html_e( 'Delete account', 'newspack-plugin' ); ?></button>
			</form>
		</section>
		<?php
	}

	/**
	 * Render the native delete-account confirmation tab.
	 */
	protected static function render_delete_account() {
		?>
		<section id="delete-account-confirm">
			<h4 class="newspack-ui__font--m is-destructive"><?php \esc_html_e( 'Delete account', 'newspack-plugin' ); ?></h4>
			<p><?php \esc_html_e( 'This action is permanent. To confirm, submit the request below and follow the link we email you.', 'newspack-plugin' ); ?></p>
			<form method="post" action="">
				<?php \wp_nonce_field( 'newspack_my_account_delete', 'newspack_my_account_delete_nonce' ); ?>
				<input type="hidden" name="action" value="newspack_my_account_request_delete" />
				<button type="submit" class="newspack-ui__button newspack-ui__button--destructive"><?php \esc_html_e( 'Request account deletion', 'newspack-plugin' ); ?></button>
			</form>
		</section>
		<?php
	}
}

My_Account::init();
