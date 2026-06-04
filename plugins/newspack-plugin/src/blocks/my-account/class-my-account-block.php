<?php
/**
 * My Account Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\My_Account;

use Newspack\My_Account;

defined( 'ABSPATH' ) || exit;

/**
 * My Account Block.
 */
final class My_Account_Block {
	/**
	 * Initialize the block.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Register block from metadata.
	 */
	public static function register_block() {
		\register_block_type_from_metadata(
			__DIR__ . '/block.json',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Render the block.
	 *
	 * @return string
	 */
	public static function render_block() {
		return My_Account::render_page();
	}
}

My_Account_Block::init();
