<?php // phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing
/**
 * Tests for WooCommerce_Email_Style_Sync.
 *
 * Test isolation:
 *
 *   - WC presence: flipped via the `newspack_wc_emails_available` filter
 *     rather than a `class WC_Emails {}` shim. A global class shim would
 *     couple this test class to suite ordering with any future code
 *     that branches on `class_exists( 'WC_Emails' )`.
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

	private function create_fake_logo_attachment_id(): int {
		return self::factory()->attachment->create_object(
			'fake-logo.png',
			0,
			[
				'post_mime_type' => 'image/png',
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
			]
		);
	}

	/**
	 * Reset state between tests so option / theme-mod writes don't leak.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( WooCommerce_Email_Style_Sync::FIRST_RUN_DONE_OPTION );
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
		delete_option( WooCommerce_Email_Style_Sync::FIRST_RUN_DONE_OPTION );
		delete_option( 'woocommerce_email_base_color' );
		delete_option( 'woocommerce_email_header_image' );
		remove_theme_mod( 'primary_color_hex' );
		remove_theme_mod( 'custom_logo' );

		// Narrow removal of the specific callback this class registers,
		// not remove_all_filters — that would nuke any sibling caller's
		// registration on the same hook.
		remove_filter( 'newspack_wc_email_style_sync_enabled', '__return_false' );
		remove_filter( 'newspack_wc_emails_available', '__return_true' );
		remove_all_filters( 'newspack_wc_email_style_sync_wc_default_base_color' );

		// Restore the hook we removed in set_up() so subsequent tests
		// run against the same hook table as the rest of the suite.
		add_action( $this->theme_mods_hook, [ \Newspack\Emails::class, 'maybe_update_email_templates' ], 10, 2 );

		parent::tear_down();
	}

	public function test_sync_styles_writes_base_color_only() {
		set_theme_mod( 'primary_color_hex', '#abcdef' );

		// Seed a custom logo too — sync_styles() should NOT propagate
		// it. Only base color is touched on the ongoing-change paths.
		set_theme_mod( 'custom_logo', $this->create_fake_logo_attachment_id() );

		WooCommerce_Email_Style_Sync::sync_styles();

		$this->assertSame( '#abcdef', get_option( 'woocommerce_email_base_color' ) );
		$this->assertSame(
			false,
			get_option( 'woocommerce_email_header_image', false ),
			'sync_styles() must not write the header_image option — that is first-run-only.'
		);
	}

	public function test_first_run_writes_header_image_when_logo_set() {
		set_theme_mod( 'primary_color_hex', '#abcdef' );
		$attachment_id = $this->create_fake_logo_attachment_id();
		set_theme_mod( 'custom_logo', $attachment_id );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$expected_url = wp_get_attachment_url( $attachment_id );
		$this->assertNotEmpty( $expected_url, 'Test setup invariant: attachment URL must resolve.' );
		$this->assertSame( $expected_url, get_option( 'woocommerce_email_header_image' ) );
		$this->assertSame( '#abcdef', get_option( 'woocommerce_email_base_color' ) );
	}

	public function test_maybe_sync_on_first_run_writes_when_no_customization() {
		set_theme_mod( 'primary_color_hex', '#112233' );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$this->assertSame( '#112233', get_option( 'woocommerce_email_base_color' ) );
		$this->assertTrue(
			(bool) get_option( WooCommerce_Email_Style_Sync::FIRST_RUN_DONE_OPTION )
		);
	}

	public function test_maybe_sync_on_first_run_skips_when_customized() {
		// Publisher already has a value in the option row — could be a
		// real customization or could be a WC migration write. Either
		// way, the row-presence check treats it as customization.
		update_option( 'woocommerce_email_base_color', '#deadbe' );
		set_theme_mod( 'primary_color_hex', '#112233' );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		// The pre-existing value is preserved.
		$this->assertSame( '#deadbe', get_option( 'woocommerce_email_base_color' ) );
		// First-run is still marked done so we don't re-evaluate forever.
		$this->assertTrue(
			(bool) get_option( WooCommerce_Email_Style_Sync::FIRST_RUN_DONE_OPTION )
		);
	}

	public function test_maybe_sync_on_first_run_is_idempotent() {
		set_theme_mod( 'primary_color_hex', '#111111' );
		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();
		$this->assertSame( '#111111', get_option( 'woocommerce_email_base_color' ) );

		// Simulate theme color changing AFTER first-run, then re-invoke
		// the first-run gate. Because the flag is set, the first-run
		// path must short-circuit — only the customize_save_after /
		// after_switch_theme hooks propagate ongoing theme changes.
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

		// Use assertSame against false sentinel rather than assertFalse(
		// get_option(...) ): on a CI matrix where WC's installer has run,
		// these options would exist as empty-string rows and assertFalse
		// would fail on `''`. assertSame( false, ... ) survives that
		// (an empty-string row would be a real signal something wrote).
		$this->assertSame( false, get_option( 'woocommerce_email_base_color', false ) );
		$this->assertSame( false, get_option( 'woocommerce_email_header_image', false ) );
		$this->assertSame( false, get_option( WooCommerce_Email_Style_Sync::FIRST_RUN_DONE_OPTION, false ) );
	}

	/**
	 * Regression lock for NPPD-1537 Katie-reported bug.
	 *
	 * WC's settings-page save persists every registered field on submit,
	 * even unchanged ones — landing WC's current default (`#8526ff` on
	 * sites with email_improvements active) in the option row. The old
	 * row-presence guard treated that as publisher customization and
	 * silently bypassed the sync. Per-option semantics fix: stored value
	 * matches WC default → not customized → sync writes the Newspack
	 * brand color.
	 */
	public function test_first_run_writes_base_color_when_stored_value_matches_wc_default() {
		// Use the filter to control "WC default" without depending on
		// WC's @internal EmailColors class.
		add_filter(
			'newspack_wc_email_style_sync_wc_default_base_color',
			static function () {
				return '#8526ff';
			}
		);
		// Seed the stored value as WC default (Katie's bug case).
		update_option( 'woocommerce_email_base_color', '#8526ff' );
		set_theme_mod( 'primary_color_hex', '#003da5' );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$this->assertSame(
			'#003da5',
			get_option( 'woocommerce_email_base_color' ),
			'Stored value matches WC default → not customized → sync should write the Newspack brand color.'
		);
	}

	/**
	 * Real publisher customization is still protected. Stored value
	 * differs from WC's default → treat as customized → skip the write.
	 */
	public function test_first_run_skips_base_color_when_stored_value_differs_from_wc_default() {
		add_filter(
			'newspack_wc_email_style_sync_wc_default_base_color',
			static function () {
				return '#8526ff';
			}
		);
		// Publisher's deliberate customization — differs from WC default.
		update_option( 'woocommerce_email_base_color', '#deadbe' );
		set_theme_mod( 'primary_color_hex', '#003da5' );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$this->assertSame(
			'#deadbe',
			get_option( 'woocommerce_email_base_color' ),
			'Stored value differs from WC default → customized → must not overwrite.'
		);
	}

	/**
	 * Row-presence semantics on the header image are preserved. WC
	 * doesn't write a default header image, so any value in the row is
	 * either ours (from a previous sync iteration) or a real publisher
	 * upload — either way, leave it alone.
	 */
	public function test_first_run_skips_when_header_image_already_set() {
		update_option( 'woocommerce_email_header_image', 'https://example.test/existing-logo.png' );
		set_theme_mod( 'custom_logo', $this->create_fake_logo_attachment_id() );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$this->assertSame(
			'https://example.test/existing-logo.png',
			get_option( 'woocommerce_email_header_image' ),
			'Header image row already populated → must not overwrite.'
		);
	}

	/**
	 * Per-option semantics: the two options are evaluated independently,
	 * so a customized `header_image` does NOT block writing `base_color`
	 * (the old row-presence guard would have skipped BOTH if either row
	 * existed). This locks in the per-option behavior.
	 */
	public function test_first_run_skips_header_image_but_still_writes_base_color() {
		add_filter(
			'newspack_wc_email_style_sync_wc_default_base_color',
			static function () {
				return '#8526ff';
			}
		);
		// header_image populated (publisher uploaded their logo), but
		// base_color is at WC default (i.e. WC settings-save wrote it).
		update_option( 'woocommerce_email_header_image', 'https://example.test/existing-logo.png' );
		update_option( 'woocommerce_email_base_color', '#8526ff' );
		set_theme_mod( 'primary_color_hex', '#003da5' );
		set_theme_mod( 'custom_logo', $this->create_fake_logo_attachment_id() );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$this->assertSame(
			'https://example.test/existing-logo.png',
			get_option( 'woocommerce_email_header_image' ),
			'Header image stays — row-presence semantics apply per-option.'
		);
		$this->assertSame(
			'#003da5',
			get_option( 'woocommerce_email_base_color' ),
			'Base color gets the Newspack primary — header_image customization does not block the color sync.'
		);
	}
}
