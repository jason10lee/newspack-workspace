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
		// Failure-safe: clear any filters individual tests added.
		remove_all_filters( 'newspack_indesign_export_excluded_blocks' );
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
	 * Two-author byline joins with " & ".
	 */
	public function test_byline_two_authors() {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Title' ] );

		$GLOBALS['_test_cap_coauthors'] = [
			(object) [ 'display_name' => 'Jane Doe' ],
			(object) [ 'display_name' => 'John Roe' ],
		];

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<byline>By Jane Doe &amp; John Roe</byline>', $xml );
	}

	/**
	 * Three-or-more-author byline uses commas with " & " before the last.
	 */
	public function test_byline_three_authors() {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Title' ] );

		$GLOBALS['_test_cap_coauthors'] = [
			(object) [ 'display_name' => 'Alice' ],
			(object) [ 'display_name' => 'Bob' ],
			(object) [ 'display_name' => 'Carol' ],
		];

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<byline>By Alice, Bob &amp; Carol</byline>', $xml );
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
	public function test_paragraph_strips_disallowed_inline_html() {
		kses_remove_filters();
		$content = "<!-- wp:paragraph -->\n<p>Naughty <script>alert(1)</script> text.</p>\n<!-- /wp:paragraph -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );
		kses_init_filters();

		$xml = $this->converter->convert_post( $post_id );

		// Tag markup stripped (no raw or escaped tag leaks into XML).
		$this->assertStringNotContainsString( '<script>', $xml );
		$this->assertStringNotContainsString( '&lt;script&gt;', $xml );
		$this->assertStringNotContainsString( '</script>', $xml );
		// Surrounding text preserved; inner text remains (XML is import-only,
		// not executed — kses already runs upstream for the normal write path).
		$this->assertStringContainsString( 'Naughty', $xml );
		$this->assertStringContainsString( 'text.', $xml );
	}

	/**
	 * Non-whitelisted inline tags (e.g. <mark>, <span>) are stripped to their
	 * inner text — markup doesn't leak into the XML.
	 */
	public function test_paragraph_strips_mark_tag_keeps_inner_text() {
		$content = "<!-- wp:paragraph -->\n<p>Before <mark style=\"background-color:#f2e011\" class=\"has-inline-color\">highlighted bit</mark> after.</p>\n<!-- /wp:paragraph -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<para>Before highlighted bit after.</para>', $xml );
		$this->assertStringNotContainsString( '<mark', $xml );
		$this->assertStringNotContainsString( '&lt;mark', $xml );
	}

	/**
	 * Unordered list emits <ul><li>...</li></ul>.
	 */
	public function test_emits_unordered_list() {
		$content = "<!-- wp:list -->\n<ul><!-- wp:list-item --><li>One</li><!-- /wp:list-item --><!-- wp:list-item --><li>Two</li><!-- /wp:list-item --></ul>\n<!-- /wp:list -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<ul>', $xml );
		$this->assertStringContainsString( '<li>One</li>', $xml );
		$this->assertStringContainsString( '<li>Two</li>', $xml );
		$this->assertStringContainsString( '</ul>', $xml );
	}

	/**
	 * Ordered list emits <ol>.
	 */
	public function test_emits_ordered_list() {
		$content = "<!-- wp:list {\"ordered\":true} -->\n<ol><!-- wp:list-item --><li>First</li><!-- /wp:list-item --></ol>\n<!-- /wp:list -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<ol>', $xml );
		$this->assertStringContainsString( '<li>First</li>', $xml );
		$this->assertStringContainsString( '</ol>', $xml );
	}

	/**
	 * Nested lists are preserved with the inner list inside its parent's <li>.
	 */
	public function test_emits_nested_list() {
		$content = "<!-- wp:list -->\n<ul><!-- wp:list-item --><li>Outer<!-- wp:list -->\n<ul><!-- wp:list-item --><li>Inner</li><!-- /wp:list-item --></ul>\n<!-- /wp:list --></li><!-- /wp:list-item --></ul>\n<!-- /wp:list -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<ul>', $xml );
		$this->assertStringContainsString( '<li>Outer', $xml );
		$this->assertStringContainsString( '<li>Inner</li>', $xml );

		// Structural: <li>Inner</li> must appear AFTER <li>Outer, not before
		// (i.e. it's actually nested, not a sibling).
		$outer_pos = strpos( $xml, '<li>Outer' );
		$inner_pos = strpos( $xml, '<li>Inner</li>' );
		$this->assertNotFalse( $outer_pos );
		$this->assertNotFalse( $inner_pos );
		$this->assertLessThan( $inner_pos, $outer_pos );
	}

	/**
	 * Stray top-level core/list-item (outside core/list) is dropped, not
	 * emitted as an orphan <li>.
	 */
	public function test_orphan_list_item_outside_list_is_dropped() {
		$content = "<!-- wp:list-item -->\n<li>Orphan</li>\n<!-- /wp:list-item -->\n\n<!-- wp:paragraph -->\n<p>After.</p>\n<!-- /wp:paragraph -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringNotContainsString( '<li>Orphan', $xml );
		$this->assertStringContainsString( '<para>After.</para>', $xml );
	}

	/**
	 * core/quote emits <blockquote> with optional <cite>.
	 */
	public function test_emits_blockquote_with_cite() {
		$content = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph --><p>Quoted text.</p><!-- /wp:paragraph --><cite>Source</cite></blockquote>\n<!-- /wp:quote -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<blockquote>', $xml );
		$this->assertStringContainsString( '<para>Quoted text.</para>', $xml );
		$this->assertStringContainsString( '<cite>Source</cite>', $xml );
		$this->assertStringContainsString( '</blockquote>', $xml );
	}

	/**
	 * core/pullquote emits <pullquote>.
	 */
	public function test_emits_pullquote() {
		$content = "<!-- wp:pullquote -->\n<figure class=\"wp-block-pullquote\"><blockquote><p>Big idea.</p><cite>Speaker</cite></blockquote></figure>\n<!-- /wp:pullquote -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<pullquote>', $xml );
		$this->assertStringContainsString( 'Big idea.', $xml );
		$this->assertStringContainsString( '<cite>Speaker</cite>', $xml );
		$this->assertStringContainsString( '</pullquote>', $xml );
	}

	/**
	 * A quote with only a cite (no paragraphs) emits a clean <blockquote>
	 * with just the cite — no spurious blank lines between open and cite.
	 */
	public function test_emits_blockquote_with_cite_only() {
		$content = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><cite>Source only</cite></blockquote>\n<!-- /wp:quote -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<blockquote>', $xml );
		$this->assertStringContainsString( '<cite>Source only</cite>', $xml );
		$this->assertStringContainsString( '</blockquote>', $xml );
		// No double newline between opening tag and cite.
		$this->assertStringNotContainsString( "<blockquote>\n\n", $xml );
	}

	/**
	 * core/separator emits <hr/>.
	 */
	public function test_emits_horizontal_rule() {
		$content = "<!-- wp:separator -->\n<hr class=\"wp-block-separator\"/>\n<!-- /wp:separator -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<hr/>', $xml );
	}

	/**
	 * core/group walks innerBlocks; the container itself does not emit a wrapper.
	 */
	public function test_group_block_walks_inner_blocks() {
		$content = "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph --><p>Inside group.</p><!-- /wp:paragraph --></div>\n<!-- /wp:group -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<para>Inside group.</para>', $xml );
		$this->assertStringNotContainsString( '<group>', $xml );
	}

	/**
	 * core/file and core/embed are excluded by default.
	 */
	public function test_excluded_blocks_are_stripped() {
		$content = "<!-- wp:paragraph -->\n<p>Before.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:file -->\n<div class=\"wp-block-file\"><a href=\"x.pdf\">Download</a></div>\n<!-- /wp:file -->\n\n"
			. "<!-- wp:embed {\"url\":\"https://youtu.be/x\"} -->\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">https://youtu.be/x</div></figure>\n<!-- /wp:embed -->\n\n"
			. "<!-- wp:paragraph -->\n<p>After.</p>\n<!-- /wp:paragraph -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<para>Before.</para>', $xml );
		$this->assertStringContainsString( '<para>After.</para>', $xml );
		$this->assertStringNotContainsString( 'Download', $xml );
		$this->assertStringNotContainsString( 'youtu.be', $xml );
	}

	/**
	 * Excluded blocks are stripped recursively inside containers.
	 */
	public function test_excluded_blocks_stripped_inside_group() {
		$content = "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:embed --><figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">https://youtu.be/x</div></figure><!-- /wp:embed --><!-- wp:paragraph --><p>Kept.</p><!-- /wp:paragraph --></div>\n<!-- /wp:group -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<para>Kept.</para>', $xml );
		$this->assertStringNotContainsString( 'youtu.be', $xml );
	}

	/**
	 * Filter newspack_indesign_export_excluded_blocks adds custom block types.
	 *
	 * The custom block wraps a real paragraph so the container fallback in
	 * render_block() would render its inner content if the filter weren't
	 * applied — making this a load-bearing test of the exclusion, not just
	 * an assertion about empty containers.
	 */
	public function test_excluded_blocks_filter_applies() {
		$content = "<!-- wp:my/custom -->\n<div class=\"wp-block-my-custom\"><!-- wp:paragraph --><p>secret</p><!-- /wp:paragraph --></div>\n<!-- /wp:my/custom -->\n\n<!-- wp:paragraph -->\n<p>kept</p>\n<!-- /wp:paragraph -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		add_filter(
			'newspack_indesign_export_excluded_blocks',
			function ( $types ) {
				$types[] = 'my/custom';
				return $types;
			}
		);

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringNotContainsString( 'secret', $xml );
		$this->assertStringContainsString( '<para>kept</para>', $xml );
	}

	/**
	 * Per-block attrs.indesignTag becomes a style attribute (D1).
	 */
	public function test_per_block_indesign_tag_becomes_style_attribute() {
		$content = "<!-- wp:paragraph {\"indesignTag\":\"dropcap\"} -->\n<p>Lead paragraph.</p>\n<!-- /wp:paragraph -->";
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<para style="dropcap">Lead paragraph.</para>', $xml );
	}

	/**
	 * core/image emits <figure> with <Link href="images/N.ext"/> and caption/credit.
	 */
	public function test_emits_inline_image_figure() {
		$attachment_id = self::factory()->attachment->create_object(
			'image.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A nice scene',
			]
		);
		update_post_meta( $attachment_id, '_media_credit', 'Photo by Alice' );

		$content  = sprintf(
			"<!-- wp:image {\"id\":%d} -->\n<figure class=\"wp-block-image\"><img src=\"image.jpg\" class=\"wp-image-%d\"/></figure>\n<!-- /wp:image -->",
			$attachment_id,
			$attachment_id
		);
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<figure id="' . $attachment_id . '">', $xml );
		$this->assertStringContainsString( '<Link href="images/' . $attachment_id . '.jpg"/>', $xml );
		$this->assertStringContainsString( '<caption>A nice scene</caption>', $xml );
		$this->assertStringContainsString( '<credit>Photo by Alice</credit>', $xml );
		$this->assertStringContainsString( '</figure>', $xml );
	}

	/**
	 * Inline caption from figcaption overrides the attachment excerpt.
	 */
	public function test_inline_figcaption_overrides_attachment_caption() {
		$attachment_id = self::factory()->attachment->create_object(
			'image.png',
			0,
			[
				'post_mime_type' => 'image/png',
				'post_excerpt'   => 'Attachment default',
			]
		);
		$content  = sprintf(
			"<!-- wp:image {\"id\":%d} -->\n<figure class=\"wp-block-image\"><img src=\"image.png\" class=\"wp-image-%d\"/><figcaption>Inline override</figcaption></figure>\n<!-- /wp:image -->",
			$attachment_id,
			$attachment_id
		);
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringContainsString( '<caption>Inline override</caption>', $xml );
		$this->assertStringNotContainsString( 'Attachment default', $xml );
	}

	/**
	 * Network-distributed posts skip all images.
	 */
	public function test_network_distributed_post_skips_images() {
		$attachment_id = self::factory()->attachment->create_object(
			'image.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'Caption',
			]
		);
		$content  = sprintf(
			"<!-- wp:image {\"id\":%d} -->\n<figure class=\"wp-block-image\"><img src=\"image.jpg\" class=\"wp-image-%d\"/></figure>\n<!-- /wp:image -->",
			$attachment_id,
			$attachment_id
		);
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );
		update_post_meta( $post_id, 'newspack_network_post_id', 'remote-123' );

		$xml = $this->converter->convert_post( $post_id );

		$this->assertStringNotContainsString( '<figure', $xml );
		// And get_image_ids() must NOT leak the skipped attachment — otherwise
		// the packager would download an image the XML never references.
		$this->assertEmpty( $this->converter->get_image_ids() );
	}

	/**
	 * Featured image is the first <figure> in <body>, before any paragraphs.
	 */
	public function test_featured_image_emits_at_top_of_body() {
		$attachment_id = self::factory()->attachment->create_object(
			'hero.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'Hero image',
			]
		);
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Title',
				'post_content' => "<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->",
			]
		);
		set_post_thumbnail( $post_id, $attachment_id );

		$xml = $this->converter->convert_post( $post_id );

		// Featured figure must appear before the body paragraph in the output.
		$figure_pos = strpos( $xml, '<figure id="' . $attachment_id . '">' );
		$para_pos   = strpos( $xml, '<para>Body.</para>' );
		$this->assertNotFalse( $figure_pos );
		$this->assertNotFalse( $para_pos );
		$this->assertLessThan( $para_pos, $figure_pos );
	}

	/**
	 * Featured image is not duplicated if the post body already contains it.
	 */
	public function test_featured_image_not_duplicated_when_inline() {
		$attachment_id = self::factory()->attachment->create_object(
			'shared.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'Shared',
			]
		);
		$content = sprintf(
			"<!-- wp:image {\"id\":%d} -->\n<figure class=\"wp-block-image\"><img src=\"shared.jpg\" class=\"wp-image-%d\"/></figure>\n<!-- /wp:image -->",
			$attachment_id,
			$attachment_id
		);
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );
		set_post_thumbnail( $post_id, $attachment_id );

		$xml = $this->converter->convert_post( $post_id );

		// The figure for $attachment_id should appear exactly once.
		$this->assertSame( 1, substr_count( $xml, '<figure id="' . $attachment_id . '">' ) );
	}

	/**
	 * Converter exposes the attachment IDs it referenced in the last conversion.
	 */
	public function test_get_image_ids_returns_referenced_attachments() {
		$attachment_id = self::factory()->attachment->create_object(
			'image.jpg',
			0,
			[ 'post_mime_type' => 'image/jpeg' ]
		);
		$content = sprintf(
			"<!-- wp:image {\"id\":%d} -->\n<figure class=\"wp-block-image\"><img src=\"image.jpg\" class=\"wp-image-%d\"/></figure>\n<!-- /wp:image -->",
			$attachment_id,
			$attachment_id
		);
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );

		$this->converter->convert_post( $post_id );

		$this->assertSame( [ $attachment_id ], $this->converter->get_image_ids() );
	}

	/**
	 * Image IDs include the featured image when not duplicated.
	 */
	public function test_get_image_ids_includes_featured_when_not_inline() {
		$featured_id = self::factory()->attachment->create_object(
			'hero.jpg',
			0,
			[ 'post_mime_type' => 'image/jpeg' ]
		);
		$post_id = self::factory()->post->create();
		set_post_thumbnail( $post_id, $featured_id );

		$this->converter->convert_post( $post_id );

		$this->assertContains( $featured_id, $this->converter->get_image_ids() );
	}
}
