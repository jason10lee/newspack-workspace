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
		$blocks = parse_blocks( $post->post_content );
		return $this->render_blocks( $blocks );
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

		// parse_blocks returns null name for freeform/whitespace chunks; skip.
		if ( null === $name ) {
			return '';
		}

		switch ( $name ) {
			case 'core/paragraph':
				return $this->render_paragraph( $block );
			case 'core/heading':
				return $this->render_heading( $block );
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
		return '    <para>' . $text . '</para>' . "\n";
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
		$pairs = [
			'/<strong[^>]*>/i' => '<strong>',
			'/<\/strong>/i'    => '</strong>',
			'/<em[^>]*>/i'     => '<em>',
			'/<\/em>/i'        => '</em>',
			'/<i[^>]*>/i'      => '<em>',
			'/<\/i>/i'         => '</em>',
			'/<sup[^>]*>/i'    => '<sup>',
			'/<\/sup>/i'       => '</sup>',
			'/<sub[^>]*>/i'    => '<sub>',
			'/<\/sub>/i'       => '</sub>',
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
