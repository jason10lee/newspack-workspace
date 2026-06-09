<?php
/**
 * Class Editor Assets Test
 *
 * @package Newspack_Popups
 */

/**
 * Editor assets test case.
 */
class EditorAssetsTest extends WP_UnitTestCase {
	/**
	 * Tear down.
	 */
	public function tear_down() {
		wp_dequeue_script( 'newspack-popups' );
		wp_deregister_script( 'newspack-popups' );
		wp_dequeue_script( 'newspack-popups-blocks' );
		wp_deregister_script( 'newspack-popups-blocks' );
		wp_dequeue_style( 'newspack-popups-editor' );
		wp_deregister_style( 'newspack-popups-editor' );
		wp_dequeue_style( 'newspack-popups-blocks' );
		wp_deregister_style( 'newspack-popups-blocks' );
		unset( $GLOBALS['post'] );
		unset( $GLOBALS['wp_meta_boxes'][ Newspack_Popups::NEWSPACK_POPUPS_CPT ] );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Set the current screen to the prompt editor.
	 */
	private function set_prompt_editor_screen() {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_title'   => 'Prompt editor assets',
				'post_content' => 'Prompt content.',
			]
		);

		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );

		set_current_screen( 'post' );
		get_current_screen()->post_type = Newspack_Popups::NEWSPACK_POPUPS_CPT;
	}

	/**
	 * Test editor UI assets are not enqueued with block assets.
	 */
	public function test_prompt_editor_ui_assets_are_not_enqueued_as_block_assets() {
		$this->set_prompt_editor_screen();

		Newspack_Popups::enqueue_block_assets();

		self::assertTrue( wp_script_is( 'newspack-popups-blocks', 'enqueued' ) );
		self::assertFalse( wp_script_is( 'newspack-popups', 'enqueued' ) );
	}

	/**
	 * Test editor UI assets are enqueued with editor assets.
	 */
	public function test_prompt_editor_ui_assets_are_enqueued_as_editor_assets() {
		$this->set_prompt_editor_screen();

		Newspack_Popups::enqueue_block_editor_assets();

		self::assertTrue( wp_script_is( 'newspack-popups', 'enqueued' ) );
		self::assertTrue( wp_style_is( 'newspack-popups-editor', 'enqueued' ) );
		self::assertContains( 'wp-plugins', wp_scripts()->registered['newspack-popups']->deps );
	}

	/**
	 * Test prompt meta is not editable through the stale core Custom Fields metabox.
	 */
	public function test_prompt_editor_removes_core_custom_fields_meta_box() {
		self::assertTrue( post_type_supports( Newspack_Popups::NEWSPACK_POPUPS_CPT, 'custom-fields' ) );

		add_meta_box(
			'postcustom',
			'Custom Fields',
			'__return_empty_string',
			Newspack_Popups::NEWSPACK_POPUPS_CPT,
			'normal'
		);

		Newspack_Popups::remove_custom_fields_meta_box();

		self::assertFalse( $GLOBALS['wp_meta_boxes'][ Newspack_Popups::NEWSPACK_POPUPS_CPT ]['normal']['core']['postcustom'] );
	}
}
