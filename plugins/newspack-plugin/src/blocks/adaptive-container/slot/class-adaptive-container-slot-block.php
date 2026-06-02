<?php
/**
 * Adaptive Container Slot Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Adaptive_Container;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptive_Container_Slot_Block Class.
 *
 * Registers the child slot block. Content is saved statically; this server-side
 * registration exists so block supports are recognized.
 */
final class Adaptive_Container_Slot_Block {

	/**
	 * Initializes the block.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Registers the block type from metadata.
	 *
	 * @return void
	 */
	public static function register_block() {
		register_block_type_from_metadata( __DIR__ . '/block.json' );
	}
}
Adaptive_Container_Slot_Block::init();
