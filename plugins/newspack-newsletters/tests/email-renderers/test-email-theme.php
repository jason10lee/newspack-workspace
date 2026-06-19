<?php
/**
 * Class Email Theme Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Email_Theme;

/**
 * Email Theme Test.
 */
class Test_Email_Theme extends WP_UnitTestCase {
	/**
	 * The canonical fragment exposes a branded button with the agreed values.
	 */
	public function test_canonical_returns_branded_button() {
		$post   = get_post( self::factory()->post->create() );
		$button = Email_Theme::canonical( $post )['styles']['elements']['button'];

		$this->assertSame( '#fff', $button['color']['text'] );
		$this->assertSame( '5px', $button['border']['radius'] );
		$this->assertSame( '12px', $button['spacing']['padding']['top'] );
		$this->assertSame( '24px', $button['spacing']['padding']['left'] );
	}

	/**
	 * Without newspack-plugin's Lite_Site, the primary color falls back to #36f.
	 */
	public function test_primary_color_falls_back_when_lite_site_absent() {
		$post   = get_post( self::factory()->post->create() );
		$button = Email_Theme::canonical( $post )['styles']['elements']['button'];

		if ( ! method_exists( '\Newspack\Lite_Site', 'get_primary_color' ) ) {
			$this->assertSame( '#36f', $button['color']['background'] );
		} else {
			$this->assertNotEmpty( $button['color']['background'] );
		}
	}
}
