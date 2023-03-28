<?php
/**
 * Newspack Multi-branded site plugin initialization.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site;

/**
 * Class to handle the plugin initialization
 */
class Initializer {

	/**
	 * Runs the initialization.
	 */
	public static function init() {
		Taxonomy::init();
		Admin::init();

		Customizations\Url::init();
		Customizations\ShowPageOnFront::init();
	}

}
