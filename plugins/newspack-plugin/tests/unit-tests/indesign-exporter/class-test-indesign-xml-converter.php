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
		// Mock get_coauthors() to simulate Co-Authors Plus being active.
		// Uses a global so individual tests can control the return value.
		// Loaded here (not file scope) to avoid leaking into other test files.
		require_once __DIR__ . '/../../mocks/co-authors-plus-mocks.php';
		parent::set_up();
		$this->converter = new InDesign_XML_Converter();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		parent::tear_down();
		unset( $GLOBALS['_test_cap_coauthors'] );
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
		$post_id = self::factory()->post->create( [ 'post_title' => 'Title' ] );

		// Simulate CAP returning a single author for this post.
		$GLOBALS['_test_cap_coauthors'] = [ (object) [ 'display_name' => 'Jane Doe' ] ];

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<byline>By Jane Doe</byline>', $xml );
	}

	/**
	 * Paragraph blocks emit <para> inside <body>.
	 */
	public function test_emits_paragraph_blocks() {
		$content = "<!-- wp:paragraph -->\n<p>Hello world.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Second paragraph.</p>\n<!-- /wp:paragraph -->";
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Title',
				'post_content' => $content,
			]
		);

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<body>', $xml );
		$this->assertStringContainsString( '<para>Hello world.</para>', $xml );
		$this->assertStringContainsString( '<para>Second paragraph.</para>', $xml );
		$this->assertStringContainsString( '</body>', $xml );
	}

	/**
	 * Heading blocks emit <heading level="N">.
	 */
	public function test_emits_heading_with_level() {
		$content = "<!-- wp:heading {\"level\":3} -->\n<h3>A subhead</h3>\n<!-- /wp:heading -->";
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Title',
				'post_content' => $content,
			]
		);

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<heading level="3">A subhead</heading>', $xml );
	}

	/**
	 * Heading defaults to level 2 when not set.
	 */
	public function test_heading_defaults_to_level_2() {
		$content = "<!-- wp:heading -->\n<h2>Default level</h2>\n<!-- /wp:heading -->";
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Title',
				'post_content' => $content,
			]
		);

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<heading level="2">Default level</heading>', $xml );
	}

	/**
	 * Paragraph body content is XML-escaped.
	 */
	public function test_paragraph_body_xml_escapes_special_characters() {
		$content = "<!-- wp:paragraph -->\n<p>Cats &amp; dogs &lt; mice.</p>\n<!-- /wp:paragraph -->";
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Title',
				'post_content' => $content,
			]
		);

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<para>Cats &amp; dogs &lt; mice.</para>', $xml );
	}

	/**
	 * <strong> passes through as <strong>.
	 */
	public function test_paragraph_preserves_strong() {
		$content = "<!-- wp:paragraph -->\n<p>Some <strong>bold</strong> text.</p>\n<!-- /wp:paragraph -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<para>Some <strong>bold</strong> text.</para>', $xml );
	}

	/**
	 * <em> and <i> normalize to <em>.
	 */
	public function test_paragraph_normalizes_italics_to_em() {
		$content = "<!-- wp:paragraph -->\n<p>A <em>real em</em> and an <i>html i</i>.</p>\n<!-- /wp:paragraph -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<para>A <em>real em</em> and an <em>html i</em>.</para>', $xml );
	}

	/**
	 * <sup> and <sub> pass through.
	 */
	public function test_paragraph_preserves_sup_sub() {
		$content = "<!-- wp:paragraph -->\n<p>x<sup>2</sup> and H<sub>2</sub>O.</p>\n<!-- /wp:paragraph -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( 'x<sup>2</sup>', $xml );
		$this->assertStringContainsString( 'H<sub>2</sub>O', $xml );
	}

	/**
	 * <br> becomes <br/>.
	 */
	public function test_paragraph_normalizes_br_to_self_closing() {
		$content = "<!-- wp:paragraph -->\n<p>Line one<br>line two.</p>\n<!-- /wp:paragraph -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Line one<br/>line two.', $xml );
	}

	/**
	 * Already self-closed <br/> stays as <br/> (idempotent).
	 */
	public function test_paragraph_br_self_closing_is_idempotent() {
		$content = "<!-- wp:paragraph -->\n<p>Line one<br/>line two.</p>\n<!-- /wp:paragraph -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Line one<br/>line two.', $xml );
	}

	/**
	 * <a href> becomes lowercase <link href>.
	 */
	public function test_paragraph_preserves_hyperlink_as_lowercase_link() {
		$content = "<!-- wp:paragraph -->\n<p>See <a href=\"https://example.com/\">the source</a>.</p>\n<!-- /wp:paragraph -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<link href="https://example.com/">the source</link>', $xml );
	}

	/**
	 * Disallowed inline tags get escaped (not stripped, not silently rendered).
	 *
	 * Note: wp_insert_post() runs wp_kses_post for non-unfiltered_html users and
	 * would strip <script> before our converter sees it. We disable kses filters
	 * around the post create so this test exercises the converter's own escape
	 * logic rather than WordPress's sanitization pipeline.
	 */
	public function test_paragraph_escapes_disallowed_inline_html() {
		kses_remove_filters();
		$content = "<!-- wp:paragraph -->\n<p>Naughty <script>alert(1)</script> text.</p>\n<!-- /wp:paragraph -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );
		kses_init_filters();

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringNotContainsString( '<script>', $xml );
		$this->assertStringContainsString( '&lt;script&gt;', $xml );
	}
}
