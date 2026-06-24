<?php
/**
 * Shared font resolver for newsletter email rendering and the editor canvas.
 *
 * Resolves the body/header font stacks a newsletter should use, with a clear
 * precedence so the email render and the editor canvas agree, and so an
 * un-customized newsletter inherits the active theme's fonts (matching the
 * standard post editor) instead of a hardcoded default.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Email_Renderers;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves newsletter body/header font stacks.
 *
 * Precedence (highest first):
 *   1. Explicit newsletter font meta (font_header/font_body), validated against
 *      the supported-fonts whitelist — preserves the legacy authoring behaviour.
 *   2. Global styles typography.fontFamily (the "unless global fonts are set"
 *      branch) — site-wide/global-styles typography wins over the theme default.
 *   3. Active theme fonts via the theme's newspack_font_stack() — the new default
 *      that matches what the standard post editor shows.
 *   4. Hardcoded fallback (Theme_Json_Builder defaults) — standalone / no-theme.
 *
 * Every theme function and theme-mod call is guarded so the plugin works
 * standalone with no Newspack theme installed.
 */
class Fonts {

	/**
	 * Newspack-theme default body font stack.
	 *
	 * Mirrors `--newspack-theme-font-body` in newspack-theme's
	 * sass/variables-site/_fonts.scss. This is what the standard post editor
	 * shows when no `font_body` customizer mod is set — newspack_font_stack()
	 * returns a degenerate `"","Georgia","serif"` for an unset mod, so we use the
	 * theme's actual CSS default here to match the post editor exactly.
	 *
	 * @var string
	 */
	const THEME_DEFAULT_BODY_FONT = 'georgia, garamond, "Times New Roman", serif';

	/**
	 * Newspack-theme default heading font stack.
	 *
	 * Mirrors `--newspack-theme-font-heading` in newspack-theme's
	 * sass/variables-site/_fonts.scss — the stack the standard post editor shows
	 * when no `font_header` customizer mod is set.
	 *
	 * @var string
	 */
	const THEME_DEFAULT_HEADER_FONT = '-apple-system, BlinkMacSystemFont, "Segoe UI", "Roboto", "Oxygen", "Ubuntu", "Cantarell", "Fira Sans", "Droid Sans", "Helvetica Neue", sans-serif';

	/**
	 * Resolve the body and header font stacks for a newsletter.
	 *
	 * @param \WP_Post $post Newsletter post.
	 * @return array{body:string,header:string} Resolved font stacks.
	 */
	public static function resolve( \WP_Post $post ): array {
		return [
			'body'   => self::resolve_side(
				(string) \get_post_meta( $post->ID, 'font_body', true ),
				'body',
				Theme_Json_Builder::DEFAULT_BODY_FONT
			),
			'header' => self::resolve_side(
				(string) \get_post_meta( $post->ID, 'font_header', true ),
				'header',
				Theme_Json_Builder::DEFAULT_HEADER_FONT
			),
		];
	}

	/**
	 * Resolve a single side (body or header) through the precedence chain.
	 *
	 * @param string $meta_value Stored font meta value for this side.
	 * @param string $side       'body' or 'header'.
	 * @param string $fallback   Hardcoded fallback stack.
	 * @return string Resolved font stack.
	 */
	private static function resolve_side( string $meta_value, string $side, string $fallback ): string {
		// 1. Explicit, supported newsletter font meta wins.
		$explicit = self::validate_meta_font( $meta_value );
		if ( null !== $explicit ) {
			return $explicit;
		}

		// 2. Global styles typography.fontFamily.
		$global = self::resolve_global_font( $side );
		if ( null !== $global ) {
			return $global;
		}

		// 3. Active theme fonts (matches the standard post editor).
		$theme = self::resolve_theme_font( $side );
		if ( null !== $theme ) {
			return $theme;
		}

		// 4. Hardcoded fallback (standalone / no theme).
		return $fallback;
	}

	/**
	 * Validate an explicit font meta value against the supported-fonts whitelist.
	 *
	 * Mirrors the builder's former resolve_font() validation so the explicit
	 * authoring path is unchanged.
	 *
	 * @param string $font Stored font meta value.
	 * @return string|null The font when supported, or null to fall through.
	 */
	private static function validate_meta_font( string $font ): ?string {
		if ( $font && \in_array( $font, \Newspack_Newsletters::$supported_fonts, true ) ) {
			return $font;
		}
		return null;
	}

	/**
	 * Resolve the global-styles font family for a side, if set.
	 *
	 * Reads `typography.fontFamily` (body) or
	 * `elements.heading.typography.fontFamily` (header) from the site's global
	 * styles. A test seam filter (`newspack_newsletters_test_global_styles`)
	 * allows unit tests to inject a global-styles array without a live theme.
	 *
	 * @param string $side 'body' or 'header'.
	 * @return string|null The global font family, or null when unset/unavailable.
	 */
	private static function resolve_global_font( string $side ): ?string {
		$styles = self::get_global_styles();
		if ( ! \is_array( $styles ) ) {
			return null;
		}

		if ( 'header' === $side ) {
			$value = $styles['elements']['heading']['typography']['fontFamily'] ?? null;
		} else {
			$value = $styles['typography']['fontFamily'] ?? null;
		}

		if ( \is_string( $value ) && '' !== \trim( $value ) ) {
			return $value;
		}
		return null;
	}

	/**
	 * Fetch the site's global styles array defensively.
	 *
	 * @return array|null Global styles array, or null when unavailable.
	 */
	private static function get_global_styles(): ?array {
		/**
		 * Test seam: lets unit tests inject a global-styles array.
		 *
		 * @param array|null $styles Global styles array, or null to read live.
		 */
		$injected = \apply_filters( 'newspack_newsletters_test_global_styles', null );
		if ( \is_array( $injected ) ) {
			return $injected;
		}

		if ( ! \function_exists( 'wp_get_global_styles' ) ) {
			return null;
		}

		$styles = \wp_get_global_styles();
		return \is_array( $styles ) ? $styles : null;
	}

	/**
	 * Resolve the active theme's font stack for a side.
	 *
	 * Mirrors the newspack-theme contract (sass/variables-site/_fonts.scss +
	 * inc/typography.php), which is what the standard post editor renders:
	 *
	 *  - When the customizer `font_body`/`font_header` mod IS set, the theme
	 *    builds the stack with newspack_font_stack( <mod>, <stack> ) — replicated
	 *    here so a customized site matches the post editor.
	 *  - When the mod is UNSET, the theme falls back to its CSS-var defaults
	 *    (`--newspack-theme-font-body` / `--newspack-theme-font-heading`).
	 *    newspack_font_stack( '', 'serif' ) would instead yield a degenerate
	 *    `"","Georgia","serif"`, so we use the theme's actual default stacks here
	 *    to match the post editor exactly.
	 *
	 * Returns null only when no Newspack theme is detected (standalone install),
	 * so the caller falls through to the hardcoded email-safe default.
	 *
	 * @param string $side 'body' or 'header'.
	 * @return string|null The theme font stack, or null when no Newspack theme.
	 */
	private static function resolve_theme_font( string $side ): ?string {
		// Detect the Newspack theme via its font helper. Absent → standalone.
		if ( ! \function_exists( 'newspack_font_stack' ) || ! \function_exists( 'get_theme_mod' ) ) {
			return null;
		}

		if ( 'header' === $side ) {
			$primary      = (string) \get_theme_mod( 'font_header', '' );
			$fallback     = (string) \get_theme_mod( 'font_header_stack', 'serif' );
			$theme_default = self::THEME_DEFAULT_HEADER_FONT;
		} else {
			$primary      = (string) \get_theme_mod( 'font_body', '' );
			$fallback     = (string) \get_theme_mod( 'font_body_stack', 'serif' );
			$theme_default = self::THEME_DEFAULT_BODY_FONT;
		}

		// Mod unset → use the theme's CSS-var default (matches the post editor).
		if ( '' === \trim( $primary ) ) {
			return $theme_default;
		}

		$stack = \newspack_font_stack( $primary, $fallback );
		if ( \is_string( $stack ) && '' !== \trim( $stack ) ) {
			return $stack;
		}
		return $theme_default;
	}
}
