<?php
/**
 * Adaptive Container Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Adaptive_Container;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptive_Container_Block Class.
 *
 * Registers the parent container block and outputs the front-end visibility
 * stylesheet that swaps the desktop/mobile slots at the resolved breakpoint.
 */
final class Adaptive_Container_Block {

	/**
	 * Default breakpoint, in pixels. Desktop slot shows at >= this width;
	 * mobile slot shows at <= ( breakpoint - 1 ).
	 */
	const DEFAULT_BREAKPOINT = 782;

	/**
	 * Initializes the block.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_visibility_style' ] );
	}

	/**
	 * Registers the block type from metadata (static block, no render callback).
	 *
	 * @return void
	 */
	public static function register_block() {
		register_block_type_from_metadata( __DIR__ . '/block.json' );
	}

	/**
	 * Resolves the swap breakpoint.
	 *
	 * Precedence (highest first): the `newspack_adaptive_container_breakpoint`
	 * filter, the theme.json `settings.custom.newspackAdaptiveBreakpoint` value,
	 * then the default.
	 *
	 * @return int Breakpoint in pixels.
	 */
	public static function get_breakpoint() {
		$default = self::DEFAULT_BREAKPOINT;
		$custom  = wp_get_global_settings( [ 'custom', 'newspackAdaptiveBreakpoint' ] );
		$value   = is_numeric( $custom ) ? (int) $custom : $default;

		/**
		 * Filters the breakpoint (in pixels) at which the Adaptive Container
		 * swaps between its desktop and mobile slots.
		 *
		 * @param int $value Resolved breakpoint in pixels.
		 */
		$value = (int) apply_filters( 'newspack_adaptive_container_breakpoint', $value );

		// A non-positive breakpoint would produce invalid media queries; fall back.
		return $value > 0 ? $value : $default;
	}

	/**
	 * Builds the front-end visibility CSS for the current breakpoint.
	 *
	 * @return string CSS rules.
	 */
	public static function get_visibility_css() {
		$breakpoint = self::get_breakpoint();
		return sprintf(
			'@media (max-width:%1$dpx){.newspack-adaptive-container-slot--desktop{display:none !important;}}@media (min-width:%2$dpx){.newspack-adaptive-container-slot--mobile{display:none !important;}}',
			$breakpoint - 1,
			$breakpoint
		);
	}

	/**
	 * Enqueues the single global visibility stylesheet on the front-end.
	 *
	 * Always enqueued (a few bytes) because the block commonly lives in header/
	 * footer template parts, which `has_block()` cannot detect from post content.
	 *
	 * @return void
	 */
	public static function enqueue_visibility_style() {
		wp_register_style( 'newspack-adaptive-container', false, [], NEWSPACK_PLUGIN_VERSION );
		wp_enqueue_style( 'newspack-adaptive-container' );
		wp_add_inline_style( 'newspack-adaptive-container', self::get_visibility_css() );
	}
}
Adaptive_Container_Block::init();
