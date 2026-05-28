<?php // phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing
/**
 * Tests for WooCommerce_Email_Style_Sync.
 *
 * Test isolation:
 *
 *   - WC presence: flipped via the `newspack_wc_emails_available` filter
 *     rather than a `class WC_Emails {}` shim. A global class shim
 *     would couple this test class to suite ordering and collide with
 *     slice 2a's tests (`emails-section-woocommerce.php`), which
 *     branch on the same `class_exists( 'WC_Emails' )` check to detect
 *     a real-WC environment. With a shim present, 2a's tests would
 *     skip the "no real WC" branch and call into production code that
 *     also gates on `function_exists( 'WC' )` — which isn't shimmed —
 *     so an assertNotEmpty downstream would fail.
 *
 *   - Hook detach: `Emails::maybe_update_email_templates` is hooked on
 *     `update_option_theme_mods_{theme}` and calls into a
 *     Newspack_Newsletters method that isn't present here, so
 *     `set_theme_mod( 'primary_color_hex', ... )` would explode.
 *     set_up() detaches the handler; tear_down() explicitly re-attaches
 *     it to keep this test class from leaking the detach into any
 *     subsequent test in the same PHPUnit process.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_Email_Style_Sync;

class Newspack_Test_WooCommerce_Email_Style_Sync extends WP_UnitTestCase {

	/**
	 * The action hook that fires when theme mods change.
	 *
	 * @var string
	 */
	private $theme_mods_hook;

	/**
	 * Reset state between tests so option / theme-mod writes don't leak.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( WooCommerce_Email_Style_Sync::SYNCED_VERSION_OPTION );
		delete_option( 'woocommerce_email_base_color' );
		delete_option( 'woocommerce_email_header_image' );
		remove_theme_mod( 'primary_color_hex' );
		remove_theme_mod( 'custom_logo' );

		// Enable the WC-presence gate without shimming a global class.
		add_filter( 'newspack_wc_emails_available', '__return_true' );

		// Detach Emails::maybe_update_email_templates so set_theme_mod
		// doesn't trip an unrelated Newspack_Newsletters call. See file
		// docblock for context; tear_down() re-attaches.
		$this->theme_mods_hook = 'update_option_theme_mods_' . ( wp_get_theme()->parent() ? get_stylesheet() : get_template() );
		remove_action( $this->theme_mods_hook, [ \Newspack\Emails::class, 'maybe_update_email_templates' ], 10 );
	}

	/**
	 * Explicit teardown — restore both option/theme-mod state AND the
	 * hook + filter we touched in set_up(), so nothing leaks to later
	 * tests in the same PHPUnit process.
	 */
	public function tear_down() {
		delete_option( WooCommerce_Email_Style_Sync::SYNCED_VERSION_OPTION );
		delete_option( 'woocommerce_email_base_color' );
		delete_option( 'woocommerce_email_header_image' );
		remove_theme_mod( 'primary_color_hex' );
		remove_theme_mod( 'custom_logo' );
		remove_all_filters( 'newspack_wc_email_style_sync_enabled' );
		remove_filter( 'newspack_wc_emails_available', '__return_true' );

		// Restore the hook we removed in set_up() so subsequent tests
		// run against the same hook table as the rest of the suite.
		add_action( $this->theme_mods_hook, [ \Newspack\Emails::class, 'maybe_update_email_templates' ], 10, 2 );

		parent::tear_down();
	}

	public function test_sync_styles_writes_expected_options() {
		set_theme_mod( 'primary_color_hex', '#abcdef' );

		WooCommerce_Email_Style_Sync::sync_styles();

		$this->assertSame( '#abcdef', get_option( 'woocommerce_email_base_color' ) );
		// No custom logo set — header image is written as empty string.
		$this->assertSame( '', get_option( 'woocommerce_email_header_image' ) );
	}

	public function test_maybe_sync_on_first_run_writes_when_no_customization() {
		set_theme_mod( 'primary_color_hex', '#112233' );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$this->assertSame( '#112233', get_option( 'woocommerce_email_base_color' ) );
		$this->assertSame(
			WooCommerce_Email_Style_Sync::CURRENT_VERSION,
			get_option( WooCommerce_Email_Style_Sync::SYNCED_VERSION_OPTION )
		);
	}

	public function test_maybe_sync_on_first_run_skips_when_customized() {
		// Publisher has already customized the base color directly in WC.
		update_option( 'woocommerce_email_base_color', '#deadbe' );
		set_theme_mod( 'primary_color_hex', '#112233' );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		// The publisher's customization is preserved — not overwritten with
		// the theme's primary color.
		$this->assertSame( '#deadbe', get_option( 'woocommerce_email_base_color' ) );
		// But the version is still marked so we don't re-evaluate forever.
		$this->assertSame(
			WooCommerce_Email_Style_Sync::CURRENT_VERSION,
			get_option( WooCommerce_Email_Style_Sync::SYNCED_VERSION_OPTION )
		);
	}

	public function test_maybe_sync_on_first_run_is_idempotent() {
		set_theme_mod( 'primary_color_hex', '#111111' );
		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();
		$this->assertSame( '#111111', get_option( 'woocommerce_email_base_color' ) );

		// Simulate the theme color changing AFTER first-run, then re-invoking
		// the first-run gate. Because the version is already marked, the
		// first-run path must short-circuit — only the customize_save_after /
		// after_switch_theme hooks should propagate ongoing theme changes.
		set_theme_mod( 'primary_color_hex', '#222222' );
		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$this->assertSame( '#111111', get_option( 'woocommerce_email_base_color' ) );
	}

	public function test_filter_disables_sync_entirely() {
		add_filter( 'newspack_wc_email_style_sync_enabled', '__return_false' );
		set_theme_mod( 'primary_color_hex', '#abcdef' );

		// Neither entry point should write anything when the filter is off.
		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();
		WooCommerce_Email_Style_Sync::sync_styles();

		$this->assertFalse( get_option( 'woocommerce_email_base_color' ) );
		$this->assertFalse( get_option( 'woocommerce_email_header_image' ) );
		$this->assertFalse( get_option( WooCommerce_Email_Style_Sync::SYNCED_VERSION_OPTION ) );
	}
}
