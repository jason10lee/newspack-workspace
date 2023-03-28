<?php
/**
 * Newspack Multi-branded site taxonomy.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Meta;

use Newspack_Multibranded_Site\Meta;

/**
 * Class to handle the ShowPageOnFront Meta
 */
class ShowPageOnFront extends Meta {

	/**
	 * Gets the meta key
	 *
	 * @return string
	 */
	public static function get_key() {
		return '_show_page_on_front';
	}

	/**
	 * Gets the meta description
	 *
	 * @return string
	 */
	public static function get_description() {
		return __( 'Whether the brand URL should be at the root of the site (yes) or at the default taxonomy URL (no)', 'newspack-multibranded-site' );
	}

	/**
	 * Gets the meta schema
	 *
	 * @return array
	 */
	public static function get_schema() {
		return array(
			'type'     => 'integer',
			'nullable' => true,
		);
	}

}
