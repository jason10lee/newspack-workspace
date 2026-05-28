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
 *   any of the WC email options it would write currently hold a
 *   non-default value. If so, the publisher has customized something
 *   directly via WooCommerce > Settings > Emails, and we don't
 *   overwrite that on the day the bridge ships — we mark the version
 *   as synced (so we don't keep checking forever) and log via
 *   Newspack\Logger. After first-run, ongoing theme changes propagate
 *   freely via the Customizer / theme-switch hooks.
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
 *   to avoid suite-ordering coupling with slice 2a's tests that
 *   branch on the same `class_exists` check.
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
	 * Option tracking which sync version has been applied. Marks
	 * first-run as completed regardless of whether the write fired
	 * (so the customization-detection check doesn't re-evaluate
	 * forever).
	 *
	 * @var string
	 */
	const SYNCED_VERSION_OPTION = 'newspack_wc_email_style_sync_version';

	/**
	 * Current sync version. Bump to re-trigger first-run sync on all
	 * sites (e.g. if we add a new option key to the sync).
	 *
	 * @var string
	 */
	const CURRENT_VERSION = 'v1';

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
		if ( self::CURRENT_VERSION === get_option( self::SYNCED_VERSION_OPTION ) ) {
			return;
		}

		if ( self::has_existing_customization() ) {
			Logger::log(
				'WC email style sync: skipping first-run write — one or more WC email options already hold non-default values, indicating publisher customization. Marking sync version to avoid re-evaluation; future Customizer saves and theme switches will still propagate normally.',
				'NEWSPACK-EMAILS',
				'info'
			);
			update_option( self::SYNCED_VERSION_OPTION, self::CURRENT_VERSION );
			return;
		}

		self::sync_styles();
		update_option( self::SYNCED_VERSION_OPTION, self::CURRENT_VERSION );
	}

	/**
	 * Write site brand colors and logo into WC classic email options.
	 *
	 * Called from:
	 *   - First-run (gated by `maybe_sync_on_first_run`)
	 *   - `customize_save_after` (any Customizer save propagates theme
	 *     style changes — matches WC core block-based behavior)
	 *   - `after_switch_theme` (new theme's styles propagate)
	 */
	public static function sync_styles(): void {
		if ( ! self::is_sync_enabled() ) {
			return;
		}
		if ( ! self::is_wc_emails_available() ) {
			return;
		}
		$colors = self::get_site_colors();
		foreach ( $colors as $option_name => $value ) {
			update_option( $option_name, $value );
		}
		update_option( 'woocommerce_email_header_image', self::get_site_logo_url() );
	}

	/**
	 * The WC email options style-sync writes. Single source of truth
	 * for `has_existing_customization()` and the option-writing path.
	 *
	 * @return array<string, string> Option name => WC core default value.
	 */
	private static function synced_options_and_defaults(): array {
		return [
			// WC core default base color is its brand purple.
			'woocommerce_email_base_color'   => '#7f54b3',
			// No header image by default.
			'woocommerce_email_header_image' => '',
		];
	}

	/**
	 * Whether WooCommerce's classic email infrastructure is loaded.
	 *
	 * Default is `class_exists( 'WC_Emails' )`. The check is wrapped in
	 * the `newspack_wc_emails_available` filter so tests can flip the
	 * gate without declaring a global `class WC_Emails {}` shim — the
	 * shim approach couples the test outcome to suite ordering and
	 * collides with slice 2a's tests that branch on the same
	 * `class_exists( 'WC_Emails' )` check to detect a real-WC
	 * environment.
	 *
	 * Mirrors the precedent set by `Emails_Section::is_woocommerce_active()`
	 * in slice 2a (the `newspack_woocommerce_active` filter) for the
	 * same reason.
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
	 * email options we'd write. Compares the current option value to
	 * WC core's default; any divergence is treated as a publisher
	 * customization we should not overwrite on first-run.
	 *
	 * Note: if WC core changes a default between releases, this method
	 * could fail to detect a publisher customization (false negative)
	 * or detect a phantom customization (false positive). The bridge
	 * lifetime is finite and the affected options are stable in WC
	 * core; accepting the risk.
	 *
	 * @return bool True if at least one option holds a non-default value.
	 */
	private static function has_existing_customization(): bool {
		foreach ( self::synced_options_and_defaults() as $option_name => $wc_default ) {
			// `get_option( $name, $wc_default )` returns $wc_default when
			// the option row doesn't exist in the DB at all. So this
			// comparison only returns true for an explicit publisher
			// customization (option row present, value diverges from
			// the WC default).
			if ( get_option( $option_name, $wc_default ) !== $wc_default ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the site brand colors mapped to WC email options.
	 *
	 * Only syncs `woocommerce_email_base_color` (header background,
	 * links, accents). We intentionally do NOT sync:
	 *   - `woocommerce_email_text_color`: the theme's primary_text_color
	 *     is contrast-computed against the primary brand color, not
	 *     against white, so mapping it to body text would break
	 *     readability on sites with dark primaries.
	 *   - `woocommerce_email_background_color`: WC's #f7f7f7 surround
	 *     works across all palettes.
	 *   - `woocommerce_email_body_background_color`: WC's #ffffff body
	 *     works across all palettes.
	 *
	 * @return array<string, string> WC option name => color hex value.
	 */
	private static function get_site_colors(): array {
		// `newspack_get_theme_colors()` is declared in `namespace Newspack`
		// in includes/util.php — its fully-qualified name is
		// `\Newspack\newspack_get_theme_colors`. The unqualified call
		// below resolves correctly because this class is also in
		// `namespace Newspack`, so the lookup happens via the current
		// namespace context. A `function_exists( 'newspack_get_theme_colors' )`
		// guard would NOT work — string-based function lookup does not
		// traverse namespaces, so the guard would always return false
		// against the un-prefixed string and silently no-op the sync.
		// util.php is loaded unconditionally by the plugin bootstrap, so
		// no presence guard is needed here regardless.
		return [
			'woocommerce_email_base_color' => newspack_get_theme_colors()['primary_color'],
		];
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
