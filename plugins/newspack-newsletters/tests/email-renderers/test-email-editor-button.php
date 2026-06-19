<?php
/**
 * Class Email Editor Button Test
 *
 * @package Newspack_Newsletters
 */

/**
 * Verifies the canonical button reaches the editor theme.json, flag-gated.
 */
class Test_Email_Editor_Button extends WP_UnitTestCase {
	/**
	 * Simulate an email-editor request for a newsletter post.
	 *
	 * @param int $post_id Newsletter post id.
	 */
	private function fake_email_editor_request( $post_id ) {
		$GLOBALS['pagenow'] = 'post.php';
		$_GET['post']       = $post_id;
		$GLOBALS['post']    = get_post( $post_id );
	}

	/**
	 * Run the editor override filter and return the resulting theme.json array.
	 */
	private function run_override() {
		$data = new WP_Theme_JSON_Data( [ 'version' => 3 ], 'theme' );
		return Newspack_Newsletters_Editor::override_theme_json_for_email_editor( $data )->get_data();
	}

	/**
	 * Flag ON: the canonical button is present in the editor theme.json.
	 */
	public function test_button_present_when_flag_on() {
		$post_id = self::factory()->post->create( [ 'post_type' => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ] );
		$this->fake_email_editor_request( $post_id );
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );

		$theme = $this->run_override();

		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		$this->assertSame( '5px', $theme['styles']['elements']['button']['border']['radius'] );
	}

	/**
	 * Flag OFF on a classic theme: no canonical button (legacy behavior).
	 */
	public function test_button_absent_when_flag_off_classic_theme() {
		$post_id = self::factory()->post->create( [ 'post_type' => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ] );
		$this->fake_email_editor_request( $post_id );
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );

		$theme = $this->run_override();

		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );
		$this->assertArrayNotHasKey( 'button', $theme['styles']['elements'] ?? [] );
	}

	/**
	 * Clean up request globals.
	 */
	public function tear_down() {
		unset( $_GET['post'], $GLOBALS['post'] );
		parent::tear_down();
	}
}
