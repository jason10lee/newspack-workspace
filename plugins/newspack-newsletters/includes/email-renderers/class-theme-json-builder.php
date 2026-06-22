<?php
/**
 * Builds a per-newsletter theme.json array from existing post meta.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Email_Renderers;

defined( 'ABSPATH' ) || exit;

/**
 * Translates Newspack per-newsletter theme post-meta into the theme.json shape
 * the WC renderer consumes. Non-destructive: reads meta, never writes it.
 */
class Theme_Json_Builder {
	/**
	 * Newspack newsletter font-size scale (slug => CSS size). Mirrors
	 * Newspack_Newsletters_Renderer::get_font_size() so presets resolve to the
	 * same pixel values the newsletter editor has always used.
	 *
	 * @var array
	 */
	const FONT_SIZES = [
		'xx-small'     => '8px',
		'x-small'      => '10px',
		'small'        => '12px',
		'normal'       => '16px',
		'medium'       => '16px',
		'large'        => '24px',
		'huge'         => '36px',
		'x-large'      => '36px',
		'xx-large'     => '40px',
		'xxx-large'    => '48px',
		'xxxx-large'   => '56px',
		'xxxxx-large'  => '64px',
		'xxxxxx-large' => '72px',
	];

	/**
	 * Newspack newsletter spacing scale (slug => CSS size). Mirrors the presets
	 * in Newspack_Newsletters_Renderer::get_spacing_value() so
	 * `var:preset|spacing|*` references resolve.
	 *
	 * @var array
	 */
	const SPACING_SIZES = [
		'20' => '8px',
		'30' => '16px',
		'40' => '24px',
		'50' => '32px',
		'60' => '32px',
		'70' => '48px',
		'80' => '64px',
	];

	/**
	 * Default heading font stack when meta is absent/unsupported.
	 *
	 * @var string
	 */
	const DEFAULT_HEADER_FONT = 'Arial, Helvetica, sans-serif';

	/**
	 * Default body font stack when meta is absent/unsupported.
	 *
	 * @var string
	 */
	const DEFAULT_BODY_FONT = 'Georgia, serif';

	/**
	 * Build a theme.json-shaped array for a newsletter.
	 *
	 * @param \WP_Post $post Newsletter post.
	 * @return array
	 */
	public static function build( \WP_Post $post ): array {
		$background = \sanitize_hex_color( (string) \get_post_meta( $post->ID, 'background_color', true ) );
		$text       = \sanitize_hex_color( (string) \get_post_meta( $post->ID, 'text_color', true ) );

		$header_font = self::resolve_font( (string) \get_post_meta( $post->ID, 'font_header', true ), self::DEFAULT_HEADER_FONT );
		$body_font   = self::resolve_font( (string) \get_post_meta( $post->ID, 'font_body', true ), self::DEFAULT_BODY_FONT );

		$settings = [
			'spacing'    => [
				'spacingSizes' => self::build_presets( self::SPACING_SIZES ),
			],
			'typography' => [
				// Disable fluid typography so font sizes resolve to fixed pixels in email.
				'fluid'     => false,
				'fontSizes' => self::build_presets( self::FONT_SIZES ),
			],
		];

		// Only emit the palette when the newsletter configures one. WP_Theme_JSON::merge()
		// replaces preset arrays per origin, so an empty palette would wipe the editor's
		// default color presets rather than leave them intact.
		$palette = self::build_palette();
		if ( ! empty( $palette ) ) {
			$settings['color'] = [ 'palette' => $palette ];
		}

		$styles = [
			'color'      => [
				'background' => $background ? $background : '#ffffff',
				'text'       => $text ? $text : '#000000',
			],
			'typography' => [
				'fontFamily' => $body_font,
			],
			'elements'   => [
				'heading' => [
					'typography' => [
						'fontFamily' => $header_font,
					],
				],
			],
		];

		// Emit an email-safe button border-radius when the WC renderer is active.
		// The WC email package drops CSS-var and rem values it cannot resolve,
		// so we resolve the theme's button radius to a px literal here.
		if ( Feature_Flag::is_enabled() ) {
			$styles['elements']['button'] = [
				'border' => [
					'radius' => self::resolve_button_border_radius(),
				],
			];
		}

		return [
			'version'  => 3,
			'settings' => $settings,
			'styles'   => $styles,
		];
	}

	/**
	 * Resolve the active theme's button border-radius to an email-safe px string.
	 *
	 * Reads `styles.elements.button.border.radius` from the merged theme.json.
	 * If the value is a `var( --wp--custom--... )` reference, it is resolved via
	 * `settings.custom`. rem/em values are converted to px (× 16). Values that
	 * are already in px, unitless, or otherwise unrecognised are returned as-is.
	 * Falls back to `Email_Defaults::DEFAULT_BUTTON_BORDER_RADIUS` when the theme
	 * defines nothing.
	 *
	 * @return string Email-safe border-radius value (e.g. "6px", "4px").
	 */
	private static function resolve_button_border_radius(): string {
		$merged = \WP_Theme_JSON_Resolver::get_merged_data();
		$raw    = $merged->get_raw_data();
		$radius = $raw['styles']['elements']['button']['border']['radius'] ?? null;

		if ( empty( $radius ) ) {
			return Email_Defaults::DEFAULT_BUTTON_BORDER_RADIUS;
		}

		// Resolve a `var( --wp--custom--... )` reference via settings.custom.
		if ( preg_match( '/^var\(\s*--wp--custom--([a-z0-9_-]+(?:--[a-z0-9_-]+)*)\s*\)$/i', $radius, $matches ) ) {
			// Convert CSS-var segments to the PHP array path used in settings.custom.
			// e.g. "--wp--custom--border--radius-medium" → segments ["border", "radius-medium"].
			$segments = explode( '--', $matches[1] );
			$custom   = $raw['settings']['custom'] ?? [];
			foreach ( $segments as $segment ) {
				if ( ! \is_array( $custom ) || ! \array_key_exists( $segment, $custom ) ) {
					// Cannot resolve — fall back to default.
					return Email_Defaults::DEFAULT_BUTTON_BORDER_RADIUS;
				}
				$custom = $custom[ $segment ];
			}
			if ( \is_string( $custom ) && '' !== $custom ) {
				$radius = $custom;
			} else {
				return Email_Defaults::DEFAULT_BUTTON_BORDER_RADIUS;
			}
		}

		// Convert rem/em to px (assume 1rem = 16px, standard for email clients).
		if ( preg_match( '/^([\d.]+)r?em$/i', $radius, $m ) ) {
			$px = (int) round( (float) $m[1] * 16 );
			return $px . 'px';
		}

		// Already px or another unit — return as-is.
		return $radius;
	}

	/**
	 * Resolve a font meta value to a supported font stack, or a default.
	 *
	 * @param string $font    Stored font meta value.
	 * @param string $fallback Default stack when the value is empty/unsupported.
	 * @return string
	 */
	private static function resolve_font( string $font, string $fallback ): string {
		if ( $font && \in_array( $font, \Newspack_Newsletters::$supported_fonts, true ) ) {
			return $font;
		}
		return $fallback;
	}

	/**
	 * Build the theme color palette from the newsletter color-palette option.
	 *
	 * @return array Theme.json color palette entries.
	 */
	private static function build_palette(): array {
		$option  = \json_decode( (string) \get_option( \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_PALETTE_META, '{}' ), true );
		$palette = [];
		if ( ! \is_array( $option ) ) {
			return $palette;
		}
		foreach ( $option as $slug => $hex ) {
			// Slugs become CSS custom-property/classname fragments, so sanitize them.
			$slug  = \sanitize_key( (string) $slug );
			$color = \sanitize_hex_color( (string) $hex );
			if ( '' === $slug || ! $color ) {
				continue;
			}
			$palette[] = [
				'slug'  => $slug,
				'color' => $color,
				'name'  => $slug,
			];
		}
		return $palette;
	}

	/**
	 * Convert a slug => size map into theme.json preset entries.
	 *
	 * @param array $map Slug => CSS size.
	 * @return array Theme.json preset entries ({ slug, size, name }).
	 */
	private static function build_presets( array $map ): array {
		$presets = [];
		foreach ( $map as $slug => $size ) {
			$presets[] = [
				'slug' => (string) $slug,
				'size' => $size,
				'name' => (string) $slug,
			];
		}
		return $presets;
	}
}
