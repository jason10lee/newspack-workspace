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
		return '';
	}
}
