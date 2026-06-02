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
 *     NPPD-1537 bug). Instead we compare the stored value against a
 *     UNION of known WC defaults — hardcoded historical defaults
 *     (`#7f54b3`, `#720eec`, `#8526ff`) plus WC's currently-reported
 *     defaults from `EmailColors::get_default_colors()` when that
 *     `@internal` class is available. The hardcoded historicals are
 *     the safety net: older WC installs where `EmailColors` doesn't
 *     exist still get the sync rather than silently bypass.
 *
 *   - `woocommerce_email_header_image`: row-presence semantics, because
 *     WC has no default header image — any value in the row is either
 *     ours from a previous sync or a publisher-uploaded logo. Either
 *     way, leave it alone.
 *
 *   After first-run, ongoing theme changes propagate via the
 *   Customizer / theme-switch hooks — but with write-provenance
 *   protection: we record what we last wrote in
 *   `LAST_SYNCED_BASE_COLOR_OPTION`, and the ongoing-sync path skips
 *   the write when the current stored value no longer matches that
 *   marker (i.e., the publisher edited it via WC > Settings > Emails
 *   between our writes). First-run also seeds the marker on the
 *   skip-due-to-customization path so the ongoing path doesn't
 *   incorrectly write the publisher's customization as a baseline.
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
	 * Records the last `woocommerce_email_base_color` value this class
	 * wrote (whether via first-run or via `sync_styles`). Used by
	 * `sync_styles()` as a write-provenance marker: if the current
	 * stored value doesn't match this, the publisher edited the option
	 * via WC > Settings > Emails after our last write, and we leave it
	 * alone instead of clobbering on the next Customizer save / theme
	 * switch.
	 *
	 * @var string
	 */
	const LAST_SYNCED_BASE_COLOR_OPTION = 'newspack_wc_email_style_sync_last_base_color';

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
				'WC email style sync: skipping first-run base_color write — stored value differs from every known WC default, treating as publisher customization.',
				self::LOGGER_HEADER,
				'info'
			);
			// Record the current stored value as our provenance baseline.
			// Without this, sync_styles() on the next Customizer save would
			// see no marker, fall through to its "establish baseline" path,
			// and overwrite the publisher's customization. Recording it
			// here means sync_styles() correctly sees stored === marker
			// and keeps its hands off.
			$current = get_option( 'woocommerce_email_base_color', '' );
			if ( is_string( $current ) && '' !== $current ) {
				update_option( self::LAST_SYNCED_BASE_COLOR_OPTION, $current );
			}
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
	 * switch) propagates to WC classic emails. Respects publisher
	 * customizations made after first-run via write-provenance: if the
	 * stored `woocommerce_email_base_color` no longer matches what we
	 * last wrote (`LAST_SYNCED_BASE_COLOR_OPTION`), the publisher edited
	 * it via WC > Settings > Emails between our writes and we leave it
	 * alone rather than clobbering on the next unrelated Customizer save.
	 *
	 * If `LAST_SYNCED_BASE_COLOR_OPTION` is unset (first-run hasn't
	 * happened yet — e.g., publisher edited the Customizer before
	 * opening any wp-admin page), the sync proceeds and sets the
	 * marker as a side effect.
	 *
	 * The header image is NOT re-synced here; see the class docblock
	 * for the rationale.
	 */
	public static function sync_styles(): void {
		if ( ! self::is_sync_enabled() ) {
			return;
		}
		if ( ! self::is_wc_emails_available() ) {
			return;
		}

		$last_synced = get_option( self::LAST_SYNCED_BASE_COLOR_OPTION, '' );
		$has_marker  = is_string( $last_synced ) && '' !== $last_synced;

		if ( $has_marker ) {
			// Write-provenance check: if the current stored value differs
			// from what we last wrote, something other than this class
			// (most likely a manual WC > Settings > Emails save) edited
			// the option after our last write. Respect that — don't
			// clobber on the next unrelated Customizer save.
			$stored = get_option( 'woocommerce_email_base_color', '' );
			if (
				is_string( $stored ) && '' !== $stored
				&& strtolower( trim( $stored ) ) !== strtolower( trim( $last_synced ) )
			) {
				Logger::log(
					'WC email style sync: skipping ongoing base_color write — stored value differs from last-synced marker, treating as publisher customization.',
					self::LOGGER_HEADER,
					'info'
				);
				return;
			}
		} elseif ( self::has_base_color_customization() ) {
			// No marker yet (first-run hasn't fired — unusual but
			// possible if the Customizer save races admin_init). Defer
			// to the same customization check first-run uses so we don't
			// clobber pre-existing publisher customizations.
			Logger::log(
				'WC email style sync: skipping ongoing base_color write — no provenance marker and stored value looks like a publisher customization.',
				self::LOGGER_HEADER,
				'info'
			);
			return;
		}

		self::sync_base_color();
	}

	/**
	 * Write the current theme primary color to the WC classic email
	 * base color option AND update the write-provenance marker
	 * (`LAST_SYNCED_BASE_COLOR_OPTION`) so subsequent `sync_styles()`
	 * invocations can detect publisher edits made between our writes.
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
		// Record what we just wrote so `sync_styles()` can distinguish
		// "publisher hasn't touched it" (proceed) from "publisher edited
		// it via WC > Settings > Emails" (skip).
		update_option( self::LAST_SYNCED_BASE_COLOR_OPTION, $primary );
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
	 * Value-equality against a UNION of known WC defaults (current API
	 * value + hardcoded historicals) rather than row presence — because
	 * WC writes its own default value into the option row through routine
	 * admin paths (settings-page save, install migrations,
	 * `email_improvements` feature activation). A row-presence check
	 * would treat those WC writes as "publisher customization" and
	 * silently bypass our sync.
	 *
	 *   - Row absent or empty → return false (not customized, sync writes).
	 *   - Row matches ANY known WC default → return false (WC wrote its
	 *     own default; treat as not customized; sync writes the publisher's
	 *     brand color).
	 *   - Row differs from every known WC default → return true (publisher
	 *     has a real customization, sync skips).
	 *
	 * See `get_known_wc_default_base_colors()` for the union definition.
	 *
	 * @return bool
	 */
	private static function has_base_color_customization(): bool {
		$stored = get_option( 'woocommerce_email_base_color', false );
		// Row-absent OR empty-string row → not customized. The empty-string
		// case matters because some WC code paths register options with
		// `''` default and persist that on first settings save; treating
		// that as customization would silently bypass our sync.
		if ( false === $stored || '' === $stored ) {
			return false;
		}
		// Defensive: any non-string stored value (e.g., a third-party
		// plugin filtered get_option to return an array) is treated as
		// customized — we don't want to risk overwriting a non-standard
		// value we don't understand.
		if ( ! is_string( $stored ) ) {
			return true;
		}
		$known_defaults = self::get_known_wc_default_base_colors();
		$stored_lower   = strtolower( trim( $stored ) );
		return ! in_array( $stored_lower, $known_defaults, true );
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
	 * The set of stored values that count as "WC wrote this, not the
	 * publisher" for `woocommerce_email_base_color`.
	 *
	 * Union of three sources, normalized to lowercase / trimmed:
	 *
	 *   1. Hardcoded historical WC static defaults (`#7f54b3` pre-9.6.1,
	 *      `#720eec` 9.6.1+ legacy, `#8526ff` post-email_improvements).
	 *      These are the LITERAL hex strings WC's installer / settings
	 *      page / migrations have written to the option row over time —
	 *      hardcoded specifically so publishers on OLDER WC versions
	 *      (where the `@internal` `EmailColors` class isn't yet available)
	 *      still benefit from the sync rather than silently bypass it.
	 *   2. WC's CURRENT static default (via
	 *      `EmailColors::get_default_colors( false )` — forcing the
	 *      pre-improvements branch). Catches any value WC may have
	 *      migrated to.
	 *   3. WC's CURRENT value with `email_improvements` auto-detected
	 *      (via `EmailColors::get_default_colors( true )`). On classic
	 *      themes this returns the static post-improvements default;
	 *      on block themes it returns the THEME-DERIVED value from
	 *      `wp_get_global_styles()` — which is what WC's own
	 *      auto-sync would have written.
	 *
	 * Calls #2 and #3 are wrapped in `safely_resolve_wc_default_base_color()`
	 * for try/catch + class_exists / method_exists guards; failures
	 * fall through, leaving the hardcoded historicals as the safety net.
	 *
	 * @return string[] Lowercase / trimmed hex strings (each starting with `#`).
	 */
	private static function get_known_wc_default_base_colors(): array {
		$defaults = [ '#7f54b3', '#720eec', '#8526ff' ];

		// Add WC's currently-reported default(s) — both the static
		// pre-improvements branch (false) AND the current branch (true)
		// which on classic themes is the static post-improvements value
		// and on block themes is the theme-derived button-background.
		foreach ( [ false, true ] as $email_improvements_enabled ) {
			$color = self::safely_resolve_wc_default_base_color( $email_improvements_enabled );
			if ( null !== $color ) {
				$defaults[] = $color;
			}
		}

		/**
		 * Filters the union of base color hex values that the first-run
		 * customization check treats as "WC wrote this, not a publisher
		 * customization." Default includes WC's hardcoded historical
		 * defaults plus WC's currently-reported values from EmailColors.
		 *
		 * Return an empty array to treat any non-empty stored value as
		 * publisher customization (conservative).
		 *
		 * @param string[] $defaults Hex strings (each with leading `#`).
		 */
		$defaults = (array) apply_filters( 'newspack_wc_email_style_sync_known_wc_default_base_colors', $defaults );

		return array_values(
			array_unique(
				array_map(
					static function ( $color ) {
						return strtolower( trim( (string) $color ) );
					},
					array_filter( $defaults, 'is_string' )
				)
			)
		);
	}

	/**
	 * Safely resolve a single WC default base color via the `@internal`
	 * `EmailColors::get_default_colors()` API.
	 *
	 * Wrapped in `class_exists` / `method_exists` guards so older WC
	 * versions (where the class doesn't exist) fall through to null;
	 * wrapped in `try/catch (\Throwable)` so a future WC version whose
	 * implementation throws doesn't surface as a fatal admin notice.
	 *
	 * @param bool $email_improvements_enabled Pass false to force WC's
	 *                                         pre-improvements static
	 *                                         defaults; pass true to let
	 *                                         WC return the current
	 *                                         default (which on block
	 *                                         themes pulls from
	 *                                         `wp_get_global_styles()`).
	 * @return string|null Hex string (with leading `#`), or null if the
	 *                     API is unavailable or returns an unexpected shape.
	 */
	private static function safely_resolve_wc_default_base_color( bool $email_improvements_enabled ): ?string {
		if (
			! class_exists( '\Automattic\WooCommerce\Internal\Email\EmailColors' )
			|| ! method_exists( '\Automattic\WooCommerce\Internal\Email\EmailColors', 'get_default_colors' )
		) {
			return null;
		}
		try {
			$colors = \Automattic\WooCommerce\Internal\Email\EmailColors::get_default_colors( $email_improvements_enabled );
		} catch ( \Throwable $e ) {
			// EmailColors is @internal — future signature/behavior
			// changes could throw. Fail soft so the hardcoded historical
			// list still protects publishers.
			return null;
		}
		if ( ! is_array( $colors ) || ! isset( $colors['base'] ) || ! is_string( $colors['base'] ) ) {
			return null;
		}
		return $colors['base'];
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
