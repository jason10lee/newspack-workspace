<?php
/**
 * Sync Newspack site brand styles into WooCommerce's classic email options.
 *
 * Bridge feature — finite lifespan.
 *
 * WooCommerce core's transactional emails already render through the
 * block-based email editor and inherit site styles automatically. The
 * `woocommerce_email_*` options this class writes are read by WC's
 * classic (template-php) email renderer, which WC Subscriptions and a
 * handful of other still-classic WC emails continue to use. This class
 * fills that gap so a Newspack publisher's brand color and logo show
 * up consistently across both renderers until the remaining classic
 * emails migrate to block-based templates.
 *
 * Behavior matches WC core's block-based emails — site styles
 * propagate to the rendered email whenever the publisher changes their
 * theme color in the Customizer or switches themes. We don't track
 * "last-synced value" or attempt customization-respect after first-run
 * because WC core itself doesn't either; matching that bar.
 *
 * Migration safety (first-run only):
 *   When this class first runs on an existing site, it checks whether
 *   any of the WC email options it would write already exist in the
 *   DB. If they do, that's treated as pre-existing publisher state and
 *   we skip the first-run write — we mark first-run done and log via
 *   Newspack\Logger. After first-run, ongoing theme changes propagate
 *   freely via the Customizer / theme-switch hooks. Row-presence is the
 *   guard rather than value-comparison-against-WC-defaults because the
 *   WC defaults are not stable (WC 9.6.1 migrated the base color
 *   default), and any presence-based mistake is conservative:
 *   publishers who deliberately set the option to today's WC default
 *   are treated as customized and won't get the first-run sync, which
 *   is preferable to overwriting real customizations.
 *
 * Opt-out:
 *   Publishers can disable the sync entirely via the
 *   `newspack_wc_email_style_sync_enabled` filter (default true).
 *   For sites that want their WC Subs / classic emails to look
 *   intentionally distinct from their site theme.
 *
 * Test isolation:
 *   The WC presence gate goes through the `newspack_wc_emails_available`
 *   filter (default `class_exists( 'WC_Emails' )`). Tests flip the
 *   filter rather than declaring a global `class WC_Emails {}` shim,
 *   which would couple this test class to suite ordering with any
 *   future code branching on the same `class_exists` check.
 *
 * Deprecation trigger:
 *   When WooCommerce Subscriptions (and any other remaining
 *   newspack-supported classic-template WC emails) ship block-based
 *   templates that inherit theme styles automatically, this class
 *   becomes redundant and should be removed.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Email Style Sync class. See file-level docblock.
 */
class WooCommerce_Email_Style_Sync {

	/**
	 * Boolean flag that records first-run has completed (whether the
	 * sync wrote or was skipped because of an existing customization).
	 * Used to short-circuit the first-run gate after one pass per site.
	 *
	 * Intentionally a boolean rather than a version string: the class
	 * is a finite bridge, not a long-lived migration framework. If a
	 * future need arises to re-trigger first-run on existing sites,
	 * delete this option from the affected sites via WP-CLI; don't
	 * bake an escape hatch into a class that's slated for removal.
	 *
	 * @var string
	 */
	const FIRST_RUN_DONE_OPTION = 'newspack_wc_email_style_sync_first_run_done';

	/**
	 * Logger header so log entries from this subsystem can be filtered
	 * uniformly. Convention used by other Newspack subsystems
	 * (Data_Events::LOGGER_HEADER, Webhooks::LOGGER_HEADER, etc.).
	 *
	 * @var string
	 */
	const LOGGER_HEADER = 'NEWSPACK-WC-EMAIL-SYNC';

	/**
	 * The WC option keys this class touches. Single source of truth for
	 * `has_existing_customization()` (which checks row presence on these)
	 * and the write methods (`sync_base_color`, `sync_header_image`).
	 *
	 * @var string[]
	 */
	const SYNCED_OPTION_KEYS = [
		'woocommerce_email_base_color',
		'woocommerce_email_header_image',
	];

	/**
	 * Initialize hooks.
	 *
	 * @codeCoverageIgnore
	 */
	public static function init(): void {
		// First-run sync on admin_init (after WC is loaded).
		add_action( 'admin_init', [ __CLASS__, 'maybe_sync_on_first_run' ] );

		// Re-sync when Customizer colors change or when themes are switched.
		// Theme-agnostic hooks (not update_option_theme_mods_{theme}) so
		// they survive a theme switch.
		add_action( 'customize_save_after', [ __CLASS__, 'sync_styles' ] );
		add_action( 'after_switch_theme', [ __CLASS__, 'sync_styles' ] );
	}

	/**
	 * First-run gate. Writes initial sync when this class first runs on a
	 * site — but only if no existing customization is detected on the WC
	 * email options we'd touch. If a customization IS detected, mark
	 * the version as synced (so we don't re-check forever) and log via
	 * Newspack\Logger; the customize_save_after / after_switch_theme
	 * hooks remain active either way for future theme changes.
	 *
	 * The presence check goes through `is_wc_emails_available()` (the
	 * `newspack_wc_emails_available` filter) rather than calling
	 * `class_exists( 'WC_Emails' )` directly — see that method's
	 * docblock for the test-isolation reasoning.
	 */
	public static function maybe_sync_on_first_run(): void {
		if ( ! self::is_sync_enabled() ) {
			return;
		}
		if ( ! self::is_wc_emails_available() ) {
			return;
		}
		if ( get_option( self::FIRST_RUN_DONE_OPTION, false ) ) {
			return;
		}

		// Mark first-run done BEFORE attempting any writes. If a
		// downstream `pre_update_option_*` filter throws inside
		// sync_styles, we don't want to re-enter on every admin_init
		// and trap the admin in an exception loop.
		update_option( self::FIRST_RUN_DONE_OPTION, true );

		if ( self::has_existing_customization() ) {
			Logger::log(
				'WC email style sync: skipping first-run write — one or more WC email options already hold pre-existing values, indicating publisher customization. Marking first-run done to avoid re-evaluation; future Customizer saves and theme switches will still propagate normally.',
				self::LOGGER_HEADER,
				'info'
			);
			return;
		}

		// First-run writes BOTH the base color and the header image.
		// Subsequent customize_save_after / after_switch_theme calls
		// only re-sync the base color via sync_styles() — see that
		// method's docblock for why the logo is first-run-only.
		self::sync_base_color();
		self::sync_header_image();
	}

	/**
	 * Re-sync the WC classic email base color from current theme state.
	 *
	 * Hooked on `customize_save_after` and `after_switch_theme` so a
	 * publisher's brand-color change in the Customizer (or a theme
	 * switch) propagates to WC classic emails. Matches WC core's
	 * block-based emails — those derive the base color dynamically
	 * from the active theme at render time. The header image is
	 * NOT re-synced here; see the class docblock for the rationale.
	 */
	public static function sync_styles(): void {
		if ( ! self::is_sync_enabled() ) {
			return;
		}
		if ( ! self::is_wc_emails_available() ) {
			return;
		}
		self::sync_base_color();
	}

	/**
	 * Write the current theme primary color to the WC classic email
	 * base color option.
	 *
	 * Skips the write if the resolved primary color is empty rather
	 * than clobbering whatever WC has for it (a transient empty value
	 * from a partially-configured theme mid-migration shouldn't wipe
	 * out a previously-good sync).
	 */
	private static function sync_base_color(): void {
		$primary = self::get_site_primary_color();
		if ( '' === $primary ) {
			return;
		}
		update_option( 'woocommerce_email_base_color', $primary );
	}

	/**
	 * Write the current site logo URL to the WC classic email header
	 * image option.
	 *
	 * Called only on first-run, not on the ongoing customize/switch
	 * paths. The site logo doesn't typically change with a theme
	 * switch, so re-syncing on every Customizer save would mean any
	 * publisher who later sets a different header image in
	 * WC > Settings > Emails gets it silently clobbered on the next
	 * unrelated Customizer change. This matches WC core's block-based
	 * emails, which don't have a `header_image` option at all —
	 * logo placement is built into the block template, so no
	 * propagation analogue exists there.
	 *
	 * Skips the write if the site has no custom logo set.
	 */
	private static function sync_header_image(): void {
		$logo = self::get_site_logo_url();
		if ( '' === $logo ) {
			return;
		}
		update_option( 'woocommerce_email_header_image', $logo );
	}

	/**
	 * Whether WooCommerce's classic email infrastructure is loaded.
	 *
	 * Default is `class_exists( 'WC_Emails' )`. The check is wrapped in
	 * the `newspack_wc_emails_available` filter so tests can flip the
	 * gate without declaring a global `class WC_Emails {}` shim — a
	 * shim would couple the test outcome to suite ordering with any
	 * future code that branches on the same `class_exists` check.
	 *
	 * @return bool
	 */
	private static function is_wc_emails_available(): bool {
		/**
		 * Filters whether WooCommerce's classic email infrastructure is
		 * considered available for style-sync. Default is
		 * `class_exists( 'WC_Emails' )`.
		 *
		 * @param bool $available Whether WC_Emails is loaded.
		 */
		return (bool) apply_filters( 'newspack_wc_emails_available', class_exists( 'WC_Emails' ) );
	}

	/**
	 * Is the sync feature enabled for this site?
	 *
	 * Publishers can disable the sync entirely by returning false from
	 * the `newspack_wc_email_style_sync_enabled` filter. Use case: a
	 * site that wants its WC Subs (or other classic) emails to look
	 * intentionally distinct from its site theme.
	 *
	 * @return bool
	 */
	private static function is_sync_enabled(): bool {
		/**
		 * Filters whether the WC email style sync runs on this site.
		 * Defaults to true. Return false to disable both first-run and
		 * ongoing sync entirely.
		 *
		 * @param bool $enabled Whether the sync is enabled.
		 */
		return (bool) apply_filters( 'newspack_wc_email_style_sync_enabled', true );
	}

	/**
	 * Detect whether the publisher has already customized any of the WC
	 * email options we'd write. Treats the presence of ANY option row
	 * as customization, regardless of value.
	 *
	 * Trade-off explainer: comparing against a hardcoded "WC default"
	 * is brittle — WC core has migrated `woocommerce_email_base_color`
	 * defaults at least once (WC 9.6.1's
	 * `wc_update_961_migrate_default_email_base_color` migration
	 * persists `#720eec` into the option row, which is indistinguishable
	 * from a publisher having explicitly set that value). Row-presence
	 * is conservative: a publisher who deliberately set the option to
	 * the current WC default gets treated as "customized" and won't
	 * receive the Newspack brand color via first-run sync — but they
	 * can either delete the option row or rely on the
	 * customize_save_after / after_switch_theme propagation paths that
	 * fire after first-run is marked done.
	 *
	 * @return bool True if any of the synced option rows already exists in the DB.
	 */
	private static function has_existing_customization(): bool {
		foreach ( self::SYNCED_OPTION_KEYS as $option_name ) {
			// `get_option( $name, false )` returns `false` only when
			// the option row truly doesn't exist. For string-typed
			// options (these are color hex / URL strings), there's no
			// legitimate stored-false value, so the false return
			// reliably means "row absent."
			if ( false !== get_option( $option_name, false ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the site brand primary color hex.
	 *
	 * Only the primary color is synced to WC's classic emails (mapped
	 * to `woocommerce_email_base_color`, which drives header background,
	 * links, and accents). We intentionally do NOT sync:
	 *   - `woocommerce_email_text_color`: the theme's primary_text_color
	 *     is contrast-computed against the primary brand color, not
	 *     against white, so mapping it to body text would break
	 *     readability on sites with dark primaries.
	 *   - `woocommerce_email_background_color`: WC's #f7f7f7 surround
	 *     works across all palettes.
	 *   - `woocommerce_email_body_background_color`: WC's #ffffff body
	 *     works across all palettes.
	 *
	 * `newspack_get_theme_colors()` is namespaced (`\Newspack\...`); the
	 * unqualified call resolves via this class's namespace context.
	 *
	 * @return string Primary color hex (e.g. '#abcdef'). Empty-or-falsy
	 *                theme-mod values fall through to WC's default
	 *                (which the option-read path supplies via WC's
	 *                own settings registration) by returning ''.
	 */
	private static function get_site_primary_color(): string {
		$primary = newspack_get_theme_colors()['primary_color'] ?? '';
		// Guard against a misconfigured theme/filter returning empty
		// or a non-string — writing empty to woocommerce_email_base_color
		// would produce broken inline CSS in the classic email render.
		return is_string( $primary ) && '' !== $primary ? $primary : '';
	}

	/**
	 * Get the site logo URL for the WC email header image.
	 *
	 * @return string Logo URL, or empty string if no logo is set.
	 */
	private static function get_site_logo_url(): string {
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			$url = wp_get_attachment_url( $custom_logo_id );
			return $url ? $url : '';
		}
		return '';
	}
}
WooCommerce_Email_Style_Sync::init();
