<?php
/**
 * Newspack WC email-editor renderer for the posts-inserter block.
 *
 * Renders each inserted child block through `do_blocks()` so nested blocks are
 * fully rendered and email-processed, instead of leaking raw block-comment
 * delimiters into the email body.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Abstract_Block_Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a newspack-newsletters/posts-inserter block under the WC engine.
 *
 * The block stores its content in the `innerBlocksToInsert` attribute, an array
 * of child blocks shaped `{ blockName, attrs, innerHTML, innerBlocks }`. The
 * block's own server render callback concatenates each child's raw `innerHTML`,
 * so any child carrying nested blocks leaks literal `<!-- wp:... -->` delimiters
 * into the output. This override instead pushes each child's `innerHTML` through
 * `do_blocks()`. Because the package's `render_block` filter is still active mid
 * render, the rendered children come back email-processed for free.
 */
class Posts_Inserter extends Abstract_Block_Renderer {
	/**
	 * Render the posts-inserter content.
	 *
	 * Rebuilds the output from the `innerBlocksToInsert` attribute rather than the
	 * supplied `$block_content` (the block's own callback produces the leaky raw
	 * concatenation). The base `render()` adds the spacer and the package adds the
	 * root horizontal padding around the result.
	 *
	 * @param string            $block_content     Block content (ignored; rebuilt from attrs).
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string
	 */
	protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		$children = $parsed_block['attrs']['innerBlocksToInsert'] ?? [];
		if ( ! is_array( $children ) ) {
			return '';
		}
		return self::apply_email_styles( self::render_inserted_blocks( $children ) );
	}

	/**
	 * Vertical gap between the meta blocks within a post item (title, subtitle,
	 * date, excerpt, continue-reading), replacing the package's uniform 16px.
	 *
	 * The posts-inserter has no post-editor equivalent, so it matches the tighter
	 * MJML newsletter look rather than the package default.
	 */
	const META_GAP = '8px';

	/**
	 * Vertical gap between consecutive post items. The package concatenates the
	 * inserted children with no gap, so each item after the first gets this top
	 * margin to separate the posts.
	 */
	const POST_GAP = '24px';

	/**
	 * Restyle the rendered post items to match the MJML newsletter look.
	 *
	 * Two adjustments, since the posts-inserter has no post-editor equivalent and
	 * matches MJML:
	 * 1. Post title / "continue reading" links: the package renders them with no
	 *    colour (→ the client's default blue) and `text-decoration: none`. Set
	 *    them black + underlined. Anchors are bare at this stage; the CSS inliner
	 *    adds its styles downstream but preserves existing inline styles, so this
	 *    wins. Image-wrapping anchors are skipped via the lookahead.
	 * 2. Block spacing: collapse the package's uniform 16px gap to META_GAP.
	 * 3. Root padding: each child renders as a top-level block and so gets the
	 *    email's 24px root padding again — on top of the posts-inserter block's
	 *    own root padding — double-indenting the posts. Zero the inner root
	 *    padding so the posts line up flush with the other blocks.
	 *
	 * @param string $html Rendered posts-inserter HTML.
	 * @return string
	 */
	private static function apply_email_styles( string $html ): string {
		$html = (string) preg_replace(
			'/<a\b([^>]*)>(?!\s*<img)/i',
			'<a$1 style="text-decoration: underline; color: #000000;">',
			$html
		);
		$html = (string) preg_replace( '/margin-top:\s*16px/i', 'margin-top: ' . self::META_GAP, $html );
		return (string) preg_replace( '/padding-left:\s*24px;\s*padding-right:\s*24px/i', 'padding-left: 0; padding-right: 0', $html );
	}

	/**
	 * Render the inserted child blocks to email-safe HTML.
	 *
	 * Wraps each child back into its block delimiter, then runs it through
	 * `do_blocks()` so the child block itself is rendered — its own
	 * `render_email_callback` fires via the package's `render_block` filter, and
	 * any nested blocks render too (no raw block-comment delimiters survive).
	 * Rendering the bare `innerHTML` instead would render only the inner blocks
	 * and leave the outer block (e.g. `core/columns`) as raw markup that never
	 * gets its email wrapper — so its columns overflow the email width. Kept as a
	 * static so it stays unit-testable without booting the WC engine.
	 *
	 * @param array $children The `innerBlocksToInsert` array of child blocks.
	 * @return string The concatenated rendered HTML, in child order.
	 */
	public static function render_inserted_blocks( array $children ): string {
		$html  = '';
		$index = 0;
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			// A child without a block name is unexpected — the editor always stores
			// one — but fall back to rendering its inner HTML so content is never
			// dropped.
			if ( empty( $child['blockName'] ) ) {
				$html .= do_blocks( (string) ( $child['innerHTML'] ?? '' ) );
				++$index;
				continue;
			}
			// Separate consecutive post items: give every item after the first a top
			// margin (the package renders the concatenated children with no gap).
			if ( $index > 0 ) {
				$child['attrs']['style']['spacing']['margin']['top'] = self::POST_GAP;
			}
			// Wrap the child back into its delimiter so the outer block is
			// email-rendered (its render_email_callback fires).
			$html .= do_blocks( self::serialize_inserted_block( $child ) );
			++$index;
		}
		return $html;
	}

	/**
	 * Wrap a parsed child block back into block markup for `do_blocks()`.
	 *
	 * Rebuilds the block delimiter around the saved `innerHTML` (which already
	 * carries the child's own inner-block delimiters), so `do_blocks()` re-parses
	 * and renders the full block — outer wrapper included. Mirrors core's
	 * `serialize_block()` but reads from `innerHTML`, since the inserted children
	 * carry `innerHTML` but not necessarily `innerContent`.
	 *
	 * @param array $child A child block shaped `{ blockName, attrs, innerHTML }`.
	 * @return string Block markup ready for `do_blocks()`.
	 */
	private static function serialize_inserted_block( array $child ): string {
		$name       = (string) $child['blockName'];
		$short_name = str_starts_with( $name, 'core/' ) ? substr( $name, 5 ) : $name;
		$attrs      = empty( $child['attrs'] ) ? '' : ' ' . serialize_block_attributes( $child['attrs'] );
		$inner_html = (string) ( $child['innerHTML'] ?? '' );

		if ( '' === trim( $inner_html ) ) {
			return "<!-- wp:{$short_name}{$attrs} /-->";
		}
		return "<!-- wp:{$short_name}{$attrs} -->{$inner_html}<!-- /wp:{$short_name} -->";
	}
}

// Self-register this override so the registry discovers it via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'newspack-newsletters/posts-inserter', Posts_Inserter::class );
