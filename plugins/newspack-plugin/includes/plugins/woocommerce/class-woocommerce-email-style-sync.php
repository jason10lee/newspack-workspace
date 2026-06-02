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
 *   When this class first runs on an existing site, each option gets
 *   its own customization check; per-option semantics because the two
 *   options have different "publisher customization" signals:
 *
 *   - `woocommerce_email_base_color`: WC's own admin paths (settings-
 *     page save, install migrations, the `email_improvements` feature
 *     activation) routinely write WC's CURRENT default value into the
 *     option row even when the publisher hasn't touched anything. A
 *     row-presence check would treat those WC writes as "publisher
 *     customization" and silently bypass the brand-color sync (the
 *     NPPD-1537 bug). Instead we compare the stored value against WC's
 *     current default at check time via
 *     `EmailColors::get_default_colors()` — if they match, the row was
 *     written by WC and we proceed with the sync; if they differ, the
 *     publisher has a real customization and we skip. The check happens
 *     at check time, so if WC ships a new default in a future version,
 *     the comparison adapts automatically.
 *
 *   - `woocommerce_email_header_image`: row-presence semantics, because
 *     WC has no default header image — any value in the row is either
 *     ours from a previous sync or a publisher-uploaded logo. Either
 *     way, leave it alone.
 *
 *   After first-run, ongoing theme changes propagate freely via the
 *   Customizer / theme-switch hooks.
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
	 * site — each of the two synced options gets its OWN customization
	 * check because they have different "publisher customization" signals
	 * (see class-level docblock). The customize_save_after /
	 * after_switch_theme hooks remain active regardless of what happens
	 * here, so future theme changes propagate independent of first-run
	 * outcome.
	 *
	 * The WC presence check goes through `is_wc_emails_available()` (the
	 * `newspack_wc_emails_available` filter) rather than calling
	 * `class_exists( 'WC_Emails' )` directly — see that method's docblock
	 * for the test-isolation reasoning.
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
		// the sync paths, we don't want to re-enter on every admin_init
		// and trap the admin in an exception loop.
		update_option( self::FIRST_RUN_DONE_OPTION, true );

		// Per-option customization checks. NPPD-1537 originally used a
		// single row-presence check across both options, but that
		// silently bypassed the brand-color sync on most sites because
		// WC routinely writes its own default value into
		// `woocommerce_email_base_color` (via settings-page save, install
		// migrations, `email_improvements` feature activation, etc.).
		// See has_base_color_customization() / has_header_image_customization()
		// for the per-option logic.
		if ( self::has_base_color_customization() ) {
			Logger::log(
				'WC email style sync: skipping first-run base_color write — stored value differs from WC default, treating as publisher customization.',
				self::LOGGER_HEADER,
				'info'
			);
		} else {
			self::sync_base_color();
		}
		if ( self::has_header_image_customization() ) {
			Logger::log(
				'WC email style sync: skipping first-run header_image write — option row already has a value (previous sync or publisher upload).',
				self::LOGGER_HEADER,
				'info'
			);
		} else {
			self::sync_header_image();
		}
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
	 * Has the publisher customized `woocommerce_email_base_color`?
	 *
	 * Value-equality against WC's current default rather than row
	 * presence — because WC writes its own default value into the option
	 * row through routine admin paths (settings-page save, install
	 * migrations, `email_improvements` feature activation). A row-
	 * presence check would treat all of those WC writes as "publisher
	 * customization" and silently bypass our sync. Compare-at-check-time
	 * against WC's current default sidesteps that:
	 *
	 *   - Row absent → return false (not customized, sync writes).
	 *   - Row present AND value matches WC default → return false (WC
	 *     wrote its own default, treat as not customized, sync writes
	 *     the publisher's brand color).
	 *   - Row present AND value differs from WC default → return true
	 *     (publisher has a real customization, sync skips).
	 *
	 * If WC's `EmailColors::get_default_colors()` is unavailable
	 * (older WC, or WC removed the @internal class), fall back to
	 * row-presence so we don't silently overwrite anything we can't
	 * verify. That's the conservative behavior the class started with.
	 *
	 * @return bool
	 */
	private static function has_base_color_customization(): bool {
		$stored = get_option( 'woocommerce_email_base_color', false );
		// `get_option( $name, false )` returns `false` only when the
		// option row truly doesn't exist. For string-typed options
		// (color hex strings), there's no legitimate stored-false
		// value, so the false return reliably means "row absent."
		if ( false === $stored ) {
			return false;
		}
		$wc_default = self::get_wc_default_base_color();
		if ( null === $wc_default ) {
			// EmailColors API unavailable — can't tell whether the
			// stored value is a WC default or a real customization,
			// so be conservative and treat as customized.
			return true;
		}
		return strtolower( (string) $stored ) !== strtolower( $wc_default );
	}

	/**
	 * Has the publisher customized `woocommerce_email_header_image`?
	 *
	 * Row-presence semantics. WC doesn't write a default header image,
	 * so any value in the row is either ours from a previous sync (the
	 * `newspack_wc_email_style_sync_first_run_done` flag from a prior
	 * sync iteration on the same site) or a publisher-uploaded logo via
	 * WC > Settings > Emails. Either way: don't overwrite.
	 *
	 * @return bool
	 */
	private static function has_header_image_customization(): bool {
		return false !== get_option( 'woocommerce_email_header_image', false );
	}

	/**
	 * Get WC's current default for `woocommerce_email_base_color`.
	 *
	 * Reads from WC's `EmailColors::get_default_colors()` if available.
	 * That class is marked `@internal` in WC; if WC ever removes or
	 * renames it, we fall back to null and the per-option check above
	 * conservatively treats the row as customized. The result is also
	 * filterable so tests can force a value without needing the WC
	 * class on the autoloader path.
	 *
	 * @return string|null WC's current default base color hex (e.g. '#8526ff'),
	 *                     or null if WC's EmailColors API isn't available.
	 */
	private static function get_wc_default_base_color(): ?string {
		$default = null;
		if (
			class_exists( '\Automattic\WooCommerce\Internal\Email\EmailColors' )
			&& method_exists( '\Automattic\WooCommerce\Internal\Email\EmailColors', 'get_default_colors' )
		) {
			$colors  = \Automattic\WooCommerce\Internal\Email\EmailColors::get_default_colors();
			$default = is_array( $colors ) && isset( $colors['base'] ) && is_string( $colors['base'] )
				? $colors['base']
				: null;
		}
		/**
		 * Filters the WC default value for `woocommerce_email_base_color`
		 * that the first-run customization check compares against. Return
		 * null to force the conservative-fallback path (treat row-present
		 * as customized regardless of stored value).
		 *
		 * Tests use this filter to control the comparison value without
		 * needing WC's @internal EmailColors class on the autoload path.
		 *
		 * @param string|null $default Current WC default base color hex, or null if EmailColors is unavailable.
		 */
		return apply_filters( 'newspack_wc_email_style_sync_wc_default_base_color', $default );
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
