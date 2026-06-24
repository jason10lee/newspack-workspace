<?php
/**
 * Newspack override of the WC email-editor core/quote renderer.
 *
 * Exists solely to restore editor↔email cite parity: the package's
 * Integrations/Core/theme.json declares `fontStyle: italic` for the cite
 * element inside core/quote, which the CSS inliner injects as
 * `font-style: italic` on the rendered `<cite class="email-block-quote-citation">`.
 * The editor canvas renders the cite upright (font-style: normal), so the
 * email must match.
 *
 * The fix is applied via a `woocommerce_email_editor_theme_json` filter at
 * priority 11 (after the Core Initializer's priority-10 which sets italic) to
 * override `core/quote.elements.cite.typography.fontStyle` to `"normal"`. This
 * runs before the CSS inliner, so the inliner writes `font-style: normal`
 * instead of `font-style: italic`.
 *
 * The block-renderer class itself is a required structural shim — the registry
 * only wires up overrides that extend a package renderer class. All layout,
 * border, padding, and wrapper logic are inherited from the package's Quote
 * renderer without modification.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Quote as Package_Quote;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a core/quote block, inheriting the package renderer unchanged.
 *
 * The only behavioral change is delivered via the theme.json filter registered
 * below, not via render_content() — the filter runs before CSS inlining, which
 * is where the forced italic is injected. Extending Package_Quote satisfies the
 * registry type-guard and inherits all quote layout logic.
 */
class Quote extends Package_Quote {
	/**
	 * Override the package theme.json's forced cite italic for core/quote.
	 *
	 * The Core Initializer merges `core/quote.elements.cite.typography.fontStyle =
	 * "italic"` via `woocommerce_email_editor_theme_json` at priority 10. This
	 * callback runs at priority 11 and merges `"normal"` so the CSS inliner sees
	 * `font-style: normal` — matching what the editor canvas shows the author.
	 *
	 * Registered from the blocks/ file (not Editor_Bootstrap) so the fix is
	 * co-located with the block it concerns and loaded only through the same
	 * auto-discovery gate as all other overrides.
	 *
	 * @param \WP_Theme_JSON $theme The assembled email editor theme.
	 * @return \WP_Theme_JSON
	 */
	public static function un_italic_cite( \WP_Theme_JSON $theme ): \WP_Theme_JSON {
		$theme->merge(
			new \WP_Theme_JSON(
				[
					'version' => 3,
					'styles'  => [
						'blocks' => [
							'core/quote' => [
								'elements' => [
									'cite' => [
										'typography' => [
											'fontStyle' => 'normal',
										],
									],
								],
							],
						],
					],
				],
				'default'
			)
		);
		return $theme;
	}
}

add_filter( 'woocommerce_email_editor_theme_json', [ Quote::class, 'un_italic_cite' ], 11 );

// Self-register this override so the registry discovers it via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/quote', Quote::class );
