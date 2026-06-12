<?php
/**
 * Newspack Ads Ad Slot Block
 *
 * @package Newspack
 */

namespace Newspack_Ads;

use Newspack_Ads\Placements;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Ads Ad Slot Block.
 *
 * Renders a wizard-managed ad unit bound to a named placement, intended for
 * insertion into block-theme template parts (header, footer, single-post).
 */
final class Ad_Slot_Block {

	const BLOCK_NAME = 'newspack-ads/ad-slot';

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Register the block type with WordPress.
	 *
	 * Block metadata (name, attributes, supports, etc.) is defined in
	 * src/blocks/ad-slot/block.json — the single source of truth shared with the
	 * editor script. Only the dynamic render callback is supplied here.
	 *
	 * @return void
	 */
	public static function register_block() {
		register_block_type_from_metadata(
			NEWSPACK_ADS_BLOCKS_PATH . '/ad-slot', // Directory where block.json is found.
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Render the block on the front-end.
	 *
	 * Derives the synthetic hook name from the `placement` attribute and fires it,
	 * which routes through inject_placement_ad() and the standard
	 * Providers::render_placement_ad_code() pipeline. Returns empty string when no
	 * placement is selected, no listener is subscribed for that placement (e.g.,
	 * classic-theme context, or unknown key), or the hook produces no output
	 * (no ad unit bound, suppressed, provider not active).
	 *
	 * @param array $attrs Block attributes.
	 *
	 * @return string Rendered HTML.
	 */
	public static function render_block( $attrs ) {
		if ( empty( $attrs['placement'] ) ) {
			return '';
		}
		$hook_name = Placements::get_block_hook_name( $attrs['placement'] );
		if ( ! has_action( $hook_name ) ) {
			return '';
		}
		ob_start();
		do_action( $hook_name );
		$content = ob_get_clean();

		return self::apply_spacing( $content, $attrs );
	}

	/**
	 * Merge the block's spacing (padding/margin) styles onto the first element of
	 * the rendered ad markup, rather than wrapping it in a new element. The ad
	 * markup is provider-owned (e.g. the GAM `<div id='div-gpt-ad-…'>` or the
	 * Broadstreet `.newspack-broadstreet-ad` div), so we attach the inline style
	 * directly to keep template-part markup free of an extra wrapper.
	 *
	 * Styles are derived from the block's `style` attribute via the style engine,
	 * which resolves theme spacing presets and needs no global render context.
	 *
	 * @param string $content Rendered ad markup.
	 * @param array  $attrs   Block attributes.
	 *
	 * @return string Markup with spacing applied.
	 */
	private static function apply_spacing( $content, $attrs ) {
		if ( '' === trim( (string) $content ) || empty( $attrs['style']['spacing'] ) ) {
			return $content;
		}

		$styles = wp_style_engine_get_styles( [ 'spacing' => $attrs['style']['spacing'] ] );
		$css    = $styles['css'] ?? '';
		if ( '' === $css ) {
			return $content;
		}

		// Advance to the first rendered element, skipping leading <style>/<script>
		// tags. Fixed-height GAM placements emit a <style> block (via
		// print_fixed_height_css on newspack_ads_before_placement_ad) ahead of the
		// ad container, and spacing must land on the visible container, not that.
		$processor = new \WP_HTML_Tag_Processor( $content );
		$found     = false;
		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();
			if ( 'STYLE' === $tag || 'SCRIPT' === $tag ) {
				continue;
			}
			$found = true;
			break;
		}
		if ( ! $found ) {
			return $content;
		}
		$existing = $processor->get_attribute( 'style' );
		$merged   = $existing ? rtrim( trim( $existing ), ';' ) . ';' . $css : $css;
		$processor->set_attribute( 'style', $merged );

		return $processor->get_updated_html();
	}
}
Ad_Slot_Block::init();
