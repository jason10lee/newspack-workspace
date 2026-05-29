<?php
/**
 * InDesign XML Converter - Converts WordPress posts to InDesign XML format.
 *
 * @package Newspack
 */

namespace Newspack\Optional_Modules\InDesign_Export;

defined( 'ABSPATH' ) || exit;

/**
 * Converts WordPress posts to InDesign XML format.
 *
 * Companion to InDesign_Converter (Tagged Text). XML output supports image
 * references via <Link href="images/N.ext"/> and is bundled by InDesign_XML_Packager
 * into a ZIP containing the XML plus an images/ subdirectory.
 */
class InDesign_XML_Converter {

	/**
	 * Block types with no print equivalent, excluded from export by default.
	 *
	 * @var string[]
	 */
	const EXCLUDED_BLOCK_TYPES = [
		'core/file',
		'core/embed',
		'core/video',
		'core/audio',
	];

	/**
	 * Convert a WordPress post to InDesign XML.
	 *
	 * @param int|\WP_Post $post    Post ID or WP_Post object.
	 * @param array        $options Optional conversion options.
	 * @return string|false XML string, or false on failure.
	 */
	public function convert_post( $post, $options = [] ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}

		$default_options = [
			'include_subtitle' => true,
			'include_byline'   => true,
		];
		$options = wp_parse_args( $options, $default_options );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<article>' . "\n";
		$xml .= '  <headline>' . $this->escape_text( $post->post_title ) . '</headline>' . "\n";

		if ( $options['include_subtitle'] ) {
			$subtitle = $this->get_post_subtitle( $post );
			if ( $subtitle ) {
				$xml .= '  <subtitle>' . $this->escape_text( $subtitle ) . '</subtitle>' . "\n";
			}
		}

		if ( $options['include_byline'] ) {
			$byline = $this->get_byline( $post );
			if ( ! empty( $byline ) ) {
				$xml .= '  <byline>' . $this->escape_text( $byline ) . '</byline>' . "\n";
			}
		}

		$this->set_post_context( $post );
		$body = $this->process_content( $post );
		if ( '' !== $body ) {
			$xml .= '  <body>' . "\n" . $body . '  </body>' . "\n";
		}

		$xml .= '</article>' . "\n";

		return $xml;
	}

	/**
	 * Build the <body> children from post content.
	 *
	 * Only block-structured content is rendered. Classic (non-block) post
	 * content is intentionally excluded — the InDesign XML format relies on
	 * block-level structure for element mapping, and classic content has no
	 * reliable way to map to <para>, <heading>, etc.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string Inner XML (without the <body> tag itself), or empty string.
	 */
	private function process_content( $post ) {
		if ( ! has_blocks( $post->post_content ) ) {
			return '';
		}

		$excluded = $this->get_excluded_block_types();
		$blocks   = $this->strip_excluded_blocks( parse_blocks( $post->post_content ), $excluded );
		return $this->render_blocks( $blocks );
	}

	/**
	 * Resolve the filtered excluded-block-type list.
	 *
	 * @return string[]
	 */
	private function get_excluded_block_types() {
		$excluded = (array) apply_filters(
			'newspack_indesign_export_excluded_blocks',
			self::EXCLUDED_BLOCK_TYPES
		);
		return array_values( array_filter( $excluded, 'is_string' ) );
	}

	/**
	 * Recursively strip excluded block types from a block tree.
	 *
	 * @param array    $blocks   Block list.
	 * @param string[] $excluded Excluded block type names.
	 * @return array Filtered block list.
	 */
	private function strip_excluded_blocks( $blocks, $excluded ) {
		$filtered = [];
		foreach ( $blocks as $block ) {
			if ( $this->is_excluded_block( $block['blockName'] ?? null, $excluded ) ) {
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->strip_excluded_blocks( $block['innerBlocks'], $excluded );
			}
			$filtered[] = $block;
		}
		return $filtered;
	}

	/**
	 * Whether a block name is excluded. Mirrors the legacy core-embed/* rule.
	 *
	 * @param string|null $block_name Block type name.
	 * @param string[]    $excluded   Excluded block type names.
	 * @return bool
	 */
	private function is_excluded_block( $block_name, $excluded ) {
		if ( ! is_string( $block_name ) || '' === $block_name ) {
			return false;
		}
		if ( in_array( $block_name, $excluded, true ) ) {
			return true;
		}
		if ( in_array( 'core/embed', $excluded, true ) && 0 === strpos( $block_name, 'core-embed/' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Render a list of blocks as XML body children.
	 *
	 * @param array $blocks Block list.
	 * @return string XML fragment.
	 */
	private function render_blocks( $blocks ) {
		$output = '';
		foreach ( $blocks as $block ) {
			$output .= $this->render_block( $block );
		}
		return $output;
	}

	/**
	 * Render a single block.
	 *
	 * @param array $block Block data.
	 * @return string XML fragment for this block.
	 */
	private function render_block( $block ) {
		$name = $block['blockName'] ?? null;

		if ( null === $name ) {
			return '';
		}

		switch ( $name ) {
			case 'core/paragraph':
				return $this->render_paragraph( $block );
			case 'core/heading':
				return $this->render_heading( $block );
			case 'core/list':
				return $this->render_list( $block );
			// core/list-item is only valid inside core/list, where render_list()
			// calls render_list_item() directly. A stray list-item outside a
			// list is malformed content and is dropped.
			case 'core/quote':
				return $this->render_quote( $block, 'blockquote' );
			case 'core/pullquote':
				return $this->render_quote( $block, 'pullquote' );
			case 'core/separator':
				return '    <hr/>' . "\n";
			case 'core/image':
				return $this->render_image( $block );
			case 'core/gallery':
			case 'jetpack/slideshow':
			case 'jetpack/tiled-gallery':
				return $this->render_gallery( $block );
		}

		// Container fallback: walk innerBlocks if present, otherwise drop.
		if ( ! empty( $block['innerBlocks'] ) ) {
			return $this->render_blocks( $block['innerBlocks'] );
		}
		return '';
	}

	/**
	 * Render a core/paragraph block as <para>.
	 *
	 * @param array $block Block data.
	 * @return string XML fragment.
	 */
	private function render_paragraph( $block ) {
		$text = $this->extract_inner_text( $block['innerHTML'] ?? '' );
		if ( '' === $text ) {
			return '';
		}
		$style_attr = '';
		if ( ! empty( $block['attrs']['indesignTag'] ) ) {
			$style_attr = ' style="' . htmlspecialchars( (string) $block['attrs']['indesignTag'], ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '"';
		}
		return '    <para' . $style_attr . '>' . $text . '</para>' . "\n";
	}

	/**
	 * Render a core/heading block as <heading level="N">.
	 *
	 * @param array $block Block data.
	 * @return string XML fragment.
	 */
	private function render_heading( $block ) {
		$level = (int) ( $block['attrs']['level'] ?? 2 );
		$text  = $this->extract_inner_text( $block['innerHTML'] ?? '' );
		if ( '' === $text ) {
			return '';
		}
		return '    <heading level="' . $level . '">' . $text . '</heading>' . "\n";
	}

	/**
	 * Render a core/list block as <ul> or <ol>.
	 *
	 * Iterates inner blocks directly and dispatches list items via
	 * render_list_item() — bypasses render_block() so a stray top-level
	 * core/list-item (malformed content) cannot emit an orphan <li>.
	 *
	 * Note: nested lists currently share the parent list's indent depth
	 * (cosmetic, not a well-formedness issue). A depth-aware refactor of
	 * the render pipeline is deferred until container blocks arrive in
	 * Task 7, where the depth parameter pays off across multiple methods.
	 *
	 * @param array $block Block data.
	 * @return string XML fragment.
	 */
	private function render_list( $block ) {
		$ordered = ! empty( $block['attrs']['ordered'] );
		$tag     = $ordered ? 'ol' : 'ul';
		$inner   = '';
		foreach ( $block['innerBlocks'] ?? [] as $item ) {
			if ( 'core/list-item' === ( $item['blockName'] ?? null ) ) {
				$inner .= $this->render_list_item( $item );
			}
		}
		if ( '' === trim( $inner ) ) {
			return '';
		}
		return '    <' . $tag . '>' . "\n" . $inner . '    </' . $tag . '>' . "\n";
	}

	/**
	 * Render a core/list-item block as <li>.
	 *
	 * @param array $block Block data.
	 * @return string XML fragment.
	 */
	private function render_list_item( $block ) {
		$text = $this->extract_inner_text( $block['innerHTML'] ?? '' );
		// Render any nested lists inside this item.
		$nested = $this->render_blocks( $block['innerBlocks'] ?? [] );
		if ( '' === $text && '' === trim( $nested ) ) {
			return '';
		}
		if ( '' !== trim( $nested ) ) {
			return '      <li>' . $text . "\n" . $nested . '      </li>' . "\n";
		}
		return '      <li>' . $text . '</li>' . "\n";
	}

	/**
	 * Render a quote/pullquote block.
	 *
	 * Extracts text + optional cite. Because parse_blocks moves inner paragraph
	 * content into innerBlocks (leaving innerHTML with only the cite and wrapper
	 * tags), we check innerBlocks first for paragraph text, then fall back to
	 * scanning innerHTML directly for any <p> tags.
	 *
	 * @param array  $block        Block data.
	 * @param string $element_name 'blockquote' or 'pullquote'.
	 * @return string XML fragment.
	 */
	private function render_quote( $block, $element_name ) {
		$inner_html = $block['innerHTML'] ?? '';
		$inner_html = preg_replace( '/<!--.*?-->/s', '', $inner_html );

		// Extract optional <cite>...</cite> from innerHTML.
		// Gutenberg's quote/pullquote UI emits at most one cite per block, so
		// we capture only the first; all subsequent cite tags are stripped
		// along with the first via the preg_replace below.
		$cite = '';
		if ( preg_match( '/<cite[^>]*>(.*?)<\/cite>/is', $inner_html, $m ) ) {
			$cite       = '      <cite>' . $this->convert_inline_html( $m[1] ) . '</cite>' . "\n";
			$inner_html = preg_replace( '/<cite[^>]*>.*?<\/cite>/is', '', $inner_html );
		}

		// Collect paragraph text. parse_blocks places inner paragraph content in
		// innerBlocks (not innerHTML), so check there first.
		$paras = [];
		foreach ( $block['innerBlocks'] ?? [] as $inner_block ) {
			$block_name = $inner_block['blockName'] ?? null;
			if ( 'core/paragraph' === $block_name ) {
				$text = $this->extract_inner_text( $inner_block['innerHTML'] ?? '' );
				if ( '' !== trim( $text ) ) {
					$paras[] = '      <para>' . $text . '</para>';
				}
			}
		}

		// Fallback: scan innerHTML for <p> tags (pullquote wraps in <figure>/<blockquote>).
		if ( empty( $paras ) ) {
			if ( preg_match_all( '/<p[^>]*>(.*?)<\/p>/is', $inner_html, $matches ) ) {
				foreach ( $matches[1] as $para ) {
					$text = $this->convert_inline_html( $para );
					if ( '' !== trim( $text ) ) {
						$paras[] = '      <para>' . $text . '</para>';
					}
				}
			}
		}

		// Final fallback: use the whole stripped-tag content.
		if ( empty( $paras ) ) {
			$bare = trim( wp_strip_all_tags( $inner_html ) );
			if ( '' !== $bare ) {
				$paras[] = '      <para>' . $this->escape_text( $bare ) . '</para>';
			}
		}

		if ( empty( $paras ) && '' === $cite ) {
			return '';
		}

		$body = '' === implode( "\n", $paras ) ? '' : implode( "\n", $paras ) . "\n";
		return '    <' . $element_name . '>' . "\n" . $body . $cite . '    </' . $element_name . '>' . "\n";
	}

	/**
	 * Whether this post's images should be skipped.
	 *
	 * Mirrors the existing Tagged Text converter — network-distributed posts skip
	 * all images to avoid duplicating media that's owned by another site.
	 *
	 * @var int|null
	 */
	private $skip_images_for_post = null;

	/**
	 * Set the current post context so image rendering can check the network meta.
	 *
	 * Called from convert_post().
	 *
	 * @param \WP_Post $post Post object.
	 */
	private function set_post_context( $post ) {
		$this->skip_images_for_post = $post->ID;
	}

	/**
	 * Whether images for the current post should be emitted.
	 *
	 * @return bool
	 */
	private function should_emit_images() {
		if ( null === $this->skip_images_for_post ) {
			return true;
		}
		return ! get_post_meta( $this->skip_images_for_post, 'newspack_network_post_id', true );
	}

	/**
	 * Render a core/image block as <figure>.
	 *
	 * @param array $block Block data.
	 * @return string XML fragment.
	 */
	private function render_image( $block ) {
		if ( ! $this->should_emit_images() ) {
			return '';
		}

		$id = (int) ( $block['attrs']['id'] ?? 0 );
		if ( ! $id ) {
			return '';
		}

		// Prefer inline figcaption over the attachment excerpt.
		$inline_caption = null;
		if ( ! empty( $block['innerHTML'] ) && preg_match( '/<figcaption[^>]*>(.*?)<\/figcaption>/is', $block['innerHTML'], $m ) ) {
			$inline_caption = $m[1];
		}

		return $this->build_figure( $id, $inline_caption );
	}

	/**
	 * Render a gallery block as a sequence of <figure> siblings.
	 *
	 * @param array $block Block data.
	 * @return string XML fragment.
	 */
	private function render_gallery( $block ) {
		if ( ! $this->should_emit_images() ) {
			return '';
		}

		$out = '';
		$ids = [];

		// core/gallery uses innerBlocks of core/image; jetpack/* may use attrs.ids.
		if ( ! empty( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $inner ) {
				$inner_id = (int) ( $inner['attrs']['id'] ?? 0 );
				if ( $inner_id ) {
					$ids[] = $inner_id;
				}
			}
		}
		if ( ! empty( $block['attrs']['ids'] ) && is_array( $block['attrs']['ids'] ) ) {
			foreach ( $block['attrs']['ids'] as $i ) {
				$ids[] = (int) $i;
			}
		}

		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$out .= $this->build_figure( $id, null );
		}
		return $out;
	}

	/**
	 * Build a <figure> XML fragment for an attachment.
	 *
	 * @param int         $attachment_id Attachment post ID.
	 * @param string|null $inline_caption Optional override caption (raw HTML from figcaption).
	 * @return string XML fragment.
	 */
	private function build_figure( $attachment_id, $inline_caption ) {
		$ext = $this->resolve_attachment_extension( $attachment_id );
		if ( '' === $ext ) {
			return '';
		}

		$caption = $inline_caption ?? wp_get_attachment_caption( $attachment_id );
		$credit  = get_post_meta( $attachment_id, '_media_credit', true );

		$xml  = '    <figure id="' . $attachment_id . '">' . "\n";
		$xml .= '      <Link href="images/' . $attachment_id . '.' . $ext . '"/>' . "\n";
		if ( $caption ) {
			$xml .= '      <caption>' . $this->convert_inline_html( $caption ) . '</caption>' . "\n";
		}
		if ( $credit ) {
			$xml .= '      <credit>' . $this->escape_text( $credit ) . '</credit>' . "\n";
		}
		$xml .= '    </figure>' . "\n";
		return $xml;
	}

	/**
	 * Get the file extension (no dot) for an attachment, or '' if unresolvable.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Extension or empty string.
	 */
	private function resolve_attachment_extension( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( $file ) {
			$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			if ( $ext ) {
				return $ext;
			}
		}
		$mime = get_post_mime_type( $attachment_id );
		switch ( $mime ) {
			case 'image/jpeg':
				return 'jpg';
			case 'image/png':
				return 'png';
			case 'image/gif':
				return 'gif';
			case 'image/webp':
				return 'webp';
		}
		return '';
	}

	/**
	 * Strip the outer tag from a block's innerHTML and convert inline HTML
	 * to XML, preserving an inline-mark whitelist and escaping the rest.
	 *
	 * Whitelist (passthrough or normalized):
	 *   <strong>, <em>, <i> → <em>, <sup>, <sub>, <br> → <br/>, <a href> → <link href>
	 *
	 * @param string $inner_html Block innerHTML.
	 * @return string XML fragment.
	 */
	private function extract_inner_text( $inner_html ) {
		$trimmed = trim( $inner_html );
		if ( '' === $trimmed ) {
			return '';
		}
		// Strip a single outer tag pair if present.
		$inner = preg_replace( '/^<[^>]+>(.*)<\/[^>]+>$/s', '$1', $trimmed );
		return $this->convert_inline_html( $inner );
	}

	/**
	 * Convert a string of post-content HTML to XML body content. Preserves
	 * inline marks per the whitelist and escapes the rest.
	 *
	 * @param string $html Raw HTML fragment.
	 * @return string XML fragment.
	 */
	private function convert_inline_html( $html ) {
		// Step 1: replace whitelisted tags with placeholder tokens that survive escaping.
		$placeholders = [];
		$counter      = 0;

		// <br> → self-closing token.
		$html = preg_replace_callback(
			'/<br\s*\/?>/i',
			function () use ( &$placeholders, &$counter ) {
				$key                  = "\0XML_TOKEN_{$counter}\0";
				$placeholders[ $key ] = '<br/>';
				$counter++;
				return $key;
			},
			$html
		);

		// <a href="..."> ... </a> → <link href="..."> ... </link>
		//
		// NOTE: ordering matters. The anchor's inner text ($m[3]) is returned
		// raw between the open/close placeholder tokens, then re-scanned by
		// the inline-mark pass below. So <a><strong>x</strong></a> works
		// because the <strong> tokenization runs after this callback.
		// Do not reorder the inline-mark pass to run before this one.
		$html = preg_replace_callback(
			'/<a\s+[^>]*href=("|\')([^"\']*)\1[^>]*>(.*?)<\/a>/is',
			function ( $m ) use ( &$placeholders, &$counter ) {
				$href      = htmlspecialchars( $m[2], ENT_XML1 | ENT_QUOTES, 'UTF-8' );
				$open_key  = "\0XML_TOKEN_{$counter}\0";
				$counter++;
				$close_key = "\0XML_TOKEN_{$counter}\0";
				$counter++;
				$placeholders[ $open_key ]  = '<link href="' . $href . '">';
				$placeholders[ $close_key ] = '</link>';
				return $open_key . $m[3] . $close_key;
			},
			$html
		);

		// <strong>, </strong>, <em>, </em>, <i>, </i>, <sup>, </sup>, <sub>, </sub>
		//
		// Opening-tag patterns use \b after the tag name so <i[^>]*> doesn't
		// also match <iframe...>, <em...> doesn't match <embed...>, etc.
		// (Kses normally strips those, but defense in depth is cheap.)
		$pairs = [
			'/<strong\b[^>]*>/i' => '<strong>',
			'/<\/strong>/i'      => '</strong>',
			'/<em\b[^>]*>/i'     => '<em>',
			'/<\/em>/i'          => '</em>',
			'/<i\b[^>]*>/i'      => '<em>',
			'/<\/i>/i'           => '</em>',
			'/<sup\b[^>]*>/i'    => '<sup>',
			'/<\/sup>/i'         => '</sup>',
			'/<sub\b[^>]*>/i'    => '<sub>',
			'/<\/sub>/i'         => '</sub>',
		];
		foreach ( $pairs as $pattern => $replacement ) {
			$html = preg_replace_callback(
				$pattern,
				function () use ( $replacement, &$placeholders, &$counter ) {
					$key                  = "\0XML_TOKEN_{$counter}\0";
					$placeholders[ $key ] = $replacement;
					$counter++;
					return $key;
				},
				$html
			);
		}

		// Step 2: escape everything else (any remaining HTML becomes literal text).
		$escaped = $this->escape_text( $html );

		// Step 3: restore placeholders.
		$escaped = strtr( $escaped, $placeholders );

		return $escaped;
	}

	/**
	 * XML-escape a text node or attribute value, preserving UTF-8.
	 *
	 * @param string $text Raw text.
	 * @return string Escaped text.
	 */
	private function escape_text( $text ) {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Strip non-breaking space.
		$text = str_replace( "\xC2\xA0", ' ', $text );
		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Get the post subtitle.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string|null Subtitle or null.
	 */
	private function get_post_subtitle( $post ) {
		$subtitle = get_post_meta( $post->ID, 'newspack_post_subtitle', true );
		return $subtitle ?? null;
	}

	/**
	 * Get the post authors (CAP-aware).
	 *
	 * @param \WP_Post $post Post object.
	 * @return array Author objects.
	 */
	private function get_post_authors( $post ) {
		if ( function_exists( 'get_coauthors' ) ) {
			return get_coauthors( $post->ID );
		}

		$author = get_userdata( $post->post_author );
		return $author ? [ $author ] : [];
	}

	/**
	 * Format byline ("By Foo & Bar").
	 *
	 * @param \WP_Post $post Post object.
	 * @return string Formatted byline.
	 */
	private function get_byline( $post ) {
		$authors = $this->get_post_authors( $post );
		if ( empty( $authors ) ) {
			return '';
		}

		$author_names = [];
		foreach ( $authors as $author ) {
			$author_names[] = $author->display_name;
		}

		if ( 1 === count( $author_names ) ) {
			$name = $author_names[0];
		} else {
			$last_author = array_pop( $author_names );
			$name        = implode( ', ', $author_names ) . ' & ' . $last_author;
		}

		return 'By ' . $name;
	}
}
