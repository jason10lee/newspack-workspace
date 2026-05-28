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

		$xml .= '</article>' . "\n";

		return $xml;
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
	 * Falls back to wp_posts.post_author when CAP returns no authors (e.g. when
	 * force_guest_authors is enabled and the post has no co-author terms yet).
	 *
	 * @param \WP_Post $post Post object.
	 * @return array Author objects.
	 */
	private function get_post_authors( $post ) {
		if ( function_exists( 'get_coauthors' ) ) {
			$coauthors = get_coauthors( $post->ID );
			if ( ! empty( $coauthors ) ) {
				return $coauthors;
			}
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
