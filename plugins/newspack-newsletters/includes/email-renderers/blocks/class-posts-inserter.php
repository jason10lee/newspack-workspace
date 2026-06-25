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
		return self::render_inserted_blocks( $children );
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
		$html = '';
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			// Wrap a named child back into its delimiter so the outer block is
			// email-rendered (its render_email_callback fires). A child without a
			// block name is unexpected — the editor always stores one — but fall
			// back to rendering its inner HTML so content is never dropped.
			$html .= empty( $child['blockName'] )
				? do_blocks( (string) ( $child['innerHTML'] ?? '' ) )
				: do_blocks( self::serialize_inserted_block( $child ) );
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
