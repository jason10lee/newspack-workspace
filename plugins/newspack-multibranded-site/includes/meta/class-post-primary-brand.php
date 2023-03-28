<?php
/**
 * Newspack Multi-branded site taxonomy.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Meta;

use Newspack_Multibranded_Site\Meta;
use Newspack_Multibranded_Site\Taxonomy;

/**
 * Class to handle the Post Primary brand Meta
 */
class Post_Primary_Brand extends Meta {

	/**
	 * The meta type
	 *
	 * @var string
	 */
	public static $type = 'post';

	/**
	 * Gets the meta key
	 *
	 * @return string
	 */
	public static function get_key() {
		return Taxonomy::PRIMARY_META_KEY;
	}

	/**
	 * Gets the meta description
	 *
	 * @return string
	 */
	public static function get_description() {
		return __( 'The primary brand for a post, if a post belongs to more than one brand', 'newspack-multibranded-site' );
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
