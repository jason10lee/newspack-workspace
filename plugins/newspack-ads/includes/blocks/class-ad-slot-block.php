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
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Block content.
	 * @param WP_Block $block      Block instance.
	 *
	 * @return string Rendered HTML.
	 */
	public static function render_block( $attributes, $content = '', $block = null ) {
		if ( empty( $attributes['placement'] ) ) {
			return '';
		}
		$hook_name = Placements::get_block_hook_name( $attributes['placement'] );
		if ( ! has_action( $hook_name ) ) {
			return '';
		}

		// Forward the block's margin/padding onto the .newspack_global_ad wrapper
		// so it collapses together with the ad when GAM hides an empty slot.
		// Scoped to this render only — the filter is removed immediately after,
		// so other placements (widgets, sidebars, hooks) never see it.
		$spacing_css = '';
		$spacing     = $attributes['style']['spacing'] ?? [];
		if ( ! empty( $spacing ) ) {
			$engine_styles = wp_style_engine_get_styles( [ 'spacing' => $spacing ] );
			$spacing_css   = $engine_styles['css'] ?? '';
		}
		$inject = static function () use ( $spacing_css ) {
			return $spacing_css;
		};
		if ( $spacing_css ) {
			add_filter( 'newspack_ads_placement_inline_style', $inject );
		}

		ob_start();
		do_action( $hook_name );
		$output = ob_get_clean();

		if ( $spacing_css ) {
			remove_filter( 'newspack_ads_placement_inline_style', $inject );
		}

		return $output;
	}
}
Ad_Slot_Block::init();
