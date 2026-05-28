<?php
/**
 * Tests for InDesign_XML_Converter.
 *
 * @package Newspack\Tests
 */

use Newspack\Optional_Modules\InDesign_Export\InDesign_XML_Converter;

/**
 * Test class for InDesign_XML_Converter.
 */
class Newspack_Test_InDesign_XML_Converter extends WP_UnitTestCase {

	/**
	 * @var InDesign_XML_Converter
	 */
	private $converter;

	/**
	 * Set up the test.
	 */
	public function set_up() {
		parent::set_up();
		$this->converter = new InDesign_XML_Converter();
	}

	/**
	 * Converter returns false when given an invalid post.
	 */
	public function test_convert_post_returns_false_for_invalid_post() {
		$this->assertFalse( $this->converter->convert_post( 99999999 ) );
	}

	/**
	 * Headline is emitted inside <headline> with XML escaping.
	 */
	public function test_emits_headline_with_xml_escaping() {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Big & Bold News',
				'post_content' => '',
			]
		);

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<?xml version="1.0" encoding="UTF-8"?>', $xml );
		$this->assertStringContainsString( '<article>', $xml );
		$this->assertStringContainsString( '<headline>Big &amp; Bold News</headline>', $xml );
		$this->assertStringContainsString( '</article>', $xml );
	}

	/**
	 * Subtitle is emitted when set.
	 */
	public function test_emits_subtitle_when_postmeta_set() {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Title' ] );
		update_post_meta( $post_id, 'newspack_post_subtitle', 'A clear deck' );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<subtitle>A clear deck</subtitle>', $xml );
	}

	/**
	 * Subtitle is omitted when include_subtitle option is false.
	 */
	public function test_subtitle_respects_include_subtitle_option() {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Title' ] );
		update_post_meta( $post_id, 'newspack_post_subtitle', 'A clear deck' );

		$xml = $this->converter->convert_post( $post_id, [ 'include_subtitle' => false ] );

		$this->assertStringNotContainsString( '<subtitle>', $xml );
	}

	/**
	 * Single-author byline is "By {name}".
	 */
	public function test_byline_single_author() {
		$user_id = self::factory()->user->create( [ 'display_name' => 'Jane Doe' ] );
		$post_id = self::factory()->post->create(
			[
				'post_title'  => 'Title',
				'post_author' => $user_id,
			]
		);

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<byline>By Jane Doe</byline>', $xml );
	}
}
