<?php
/**
 * Class Newspack Block Renderers Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry;
use Newspack\Newsletters\Email_Renderers\Blocks\Posts_Inserter;
use Newspack\Newsletters\Email_Renderers\Blocks\Share;
use Newspack\Newsletters\Email_Renderers\Editor_Bootstrap;
use Newspack\Newsletters\Email_Renderers\Renderer_Controller;

/**
 * Tests the Newspack dynamic-block overrides for the WC email engine:
 * posts-inserter (renders inserted children through do_blocks) and share
 * (emits the saved anchor only when the newsletter is public).
 */
class Test_Newspack_Block_Renderers extends WP_UnitTestCase {
	/**
	 * Run override discovery so the self-registering renderers are mapped.
	 */
	public function set_up() {
		parent::set_up();
		Block_Renderer_Registry::init();
	}

	/**
	 * The posts-inserter helper renders nested blocks, leaking no delimiters.
	 *
	 * A child whose innerHTML carries a nested core/columns block must come back
	 * fully rendered (the wp-block-columns markup) with no raw `<!-- wp:`
	 * block-comment delimiters surviving into the output.
	 */
	public function test_posts_inserter_renders_nested_blocks_without_delimiters() {
		$children = [
			[
				// A real inserted child carries the block's INNER html (no outer
				// `<!-- wp:columns -->` delimiter — that is implied by blockName).
				'blockName' => 'core/columns',
				'innerHTML' => '<div class="wp-block-columns">'
					. '<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
					. '</div>',
			],
		];

		$result = Posts_Inserter::render_inserted_blocks( $children );

		$this->assertStringContainsString( 'wp-block-columns', $result, 'Expected the nested columns block to be rendered.' );
		$this->assertStringContainsString( 'Hello', $result, 'Expected the inner paragraph content to survive.' );
		$this->assertStringNotContainsString( '<!-- wp:', $result, 'Expected no raw block-comment delimiters to leak into the output.' );
	}

	/**
	 * The posts-inserter helper returns an empty string for no children.
	 */
	public function test_posts_inserter_empty_array_renders_nothing() {
		$this->assertSame( '', Posts_Inserter::render_inserted_blocks( [] ), 'Expected an empty children array to render nothing.' );
	}

	/**
	 * The posts-inserter helper concatenates children in document order.
	 *
	 * Two paragraph children must render in the same order they appear in the
	 * innerBlocksToInsert array.
	 */
	public function test_posts_inserter_preserves_child_order() {
		$children = [
			[ 'innerHTML' => '<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->' ],
			[ 'innerHTML' => '<!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->' ],
		];

		$result = Posts_Inserter::render_inserted_blocks( $children );

		$this->assertLessThan(
			strpos( $result, 'Second' ),
			strpos( $result, 'First' ),
			'Expected children to render in document order.'
		);
		$this->assertStringNotContainsString( '<!-- wp:', $result, 'Expected no raw block-comment delimiters to leak.' );
	}

	/**
	 * Serialize a posts-inserter block whose innerBlocksToInsert holds the given
	 * children, the way the block editor stores it.
	 *
	 * Uses serialize_block() so the children's HTML is JSON-escaped exactly as the
	 * editor writes it (e.g. `<!-- wp:columns -->`).
	 * Critically, the saved comment carries no raw `<` for kses to strip, so when
	 * stored via wp_slash()+wp_insert_post the markup survives byte-for-byte and
	 * parse_blocks() decodes each child's innerHTML back to real block delimiters.
	 *
	 * A naive `'<!-- wp:... ' . wp_json_encode( $attrs ) . ' /-->'` does NOT survive
	 * kses on save (the unescaped child HTML is stripped), which is why this fixture
	 * had to mirror the editor's escaping to reproduce the production leak.
	 *
	 * @param array $children innerBlocksToInsert child blocks (blockName/innerHTML).
	 * @return string Serialized posts-inserter block markup.
	 */
	private function serialize_posts_inserter( array $children ): string {
		return serialize_block(
			[
				'blockName'    => 'newspack-newsletters/posts-inserter',
				'attrs'        => [ 'innerBlocksToInsert' => $children ],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Create a newsletter CPT carrying the given block markup, kses-safe.
	 *
	 * Slashing preserves the editor's backslash escapes through wp_insert_post so
	 * the stored content is byte-identical to the serialized markup.
	 *
	 * @param string $content Serialized block markup.
	 * @return int Created post ID.
	 */
	private function create_newsletter_with_content( string $content ): int {
		return self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Posts inserter newsletter',
				'post_content' => wp_slash( $content ),
			]
		);
	}

	/**
	 * A real posts-inserter render emits the child columns as email tables and
	 * leaks no raw block-comment delimiters.
	 *
	 * This is the end-to-end regression for the override never wiring up in the real
	 * WC pipeline. The posts-inserter block is registered via register_block_type()
	 * (no metadata), so the package's `block_type_metadata_settings` filter — the
	 * only path the registry used to set `render_email_callback` — never fired for
	 * it. The engine then fell back to the block's own server callback, which
	 * concatenates each child's raw innerHTML, leaking `<!-- wp:columns -->` and
	 * never turning the columns into email tables.
	 *
	 * The fixture mirrors post 76: a heading child plus a nested columns child,
	 * serialized exactly as the editor stores it (see serialize_posts_inserter).
	 */
	public function test_posts_inserter_integration_renders_columns() {
		Editor_Bootstrap::init();

		// A real inserted child carries the block's INNER html — no outer
		// `<!-- wp:columns -->` delimiter (that is implied by blockName).
		$columns_inner = '<div class="wp-block-columns">'
			. '<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Left column body</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
			. '<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Right column body</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
			. '</div>';

		$content = $this->serialize_posts_inserter(
			[
				[
					'blockName'    => 'core/heading',
					'attrs'        => [],
					'innerHTML'    => '<h2>Latest posts</h2>',
					'innerContent' => [ '<h2>Latest posts</h2>' ],
					'innerBlocks'  => [],
				],
				[
					'blockName'    => 'core/columns',
					'attrs'        => [],
					'innerHTML'    => $columns_inner,
					'innerContent' => [ $columns_inner ],
					'innerBlocks'  => [],
				],
			]
		);

		$post_id = $this->create_newsletter_with_content( $content );

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		// The heading and both column bodies must survive into the email.
		$this->assertStringContainsString( 'Latest posts', $html, 'Expected the inserted heading content to appear.' );
		$this->assertStringContainsString( 'Left column body', $html, 'Expected the left column body to appear.' );
		$this->assertStringContainsString( 'Right column body', $html, 'Expected the right column body to appear.' );

		// The columns must be rendered as the email-block column markup, not leaked raw.
		$this->assertStringContainsString( 'wp-block-column', $html, 'Expected the inserted columns to render as column markup.' );

		// The outer columns block must be email-rendered with its width wrapper, not
		// left as a raw div that overflows the email body — the override must render
		// the whole child block, not only its inner blocks.
		$this->assertStringContainsString( 'email-block-columns', $html, 'Expected the inserted columns to get the email-block-columns width wrapper.' );

		// No raw block-comment delimiters may leak into the email body — this is the bug.
		$this->assertStringNotContainsString( '<!-- wp:', $html, 'Expected no raw block-comment delimiters in the rendered email.' );
	}

	/**
	 * The share builder wraps the content in a single anchor with the href.
	 */
	public function test_share_builder_wraps_anchor() {
		$result = Share::build_share_html( 'mailto:?body=x', 'Share this' );

		$this->assertStringContainsString( '<a href="mailto:?body=x">', $result, 'Expected the anchor to carry the share href.' );
		$this->assertStringContainsString( 'Share this', $result, 'Expected the link text to be preserved.' );
		$this->assertStringContainsString( 'newspack-newsletters-share-block', $result, 'Expected the share-block paragraph class.' );
		$this->assertSame( 1, substr_count( $result, '<a ' ), 'Expected exactly one anchor in the share markup.' );
	}

	/**
	 * The share builder renders nothing when there is no href to link to.
	 */
	public function test_share_builder_empty_href_renders_nothing() {
		$this->assertSame( '', Share::build_share_html( '', 'Share this' ), 'Expected an empty href to render nothing.' );
	}

	/**
	 * A public newsletter renders the share anchor.
	 *
	 * With `is_public` meta truthy the override must emit the saved share anchor
	 * (the mailto link) into the rendered email.
	 */
	public function test_share_integration_public_renders_anchor() {
		Editor_Bootstrap::init();

		// Mirror production: `content` is an HTML-sourced RichText attribute, so it
		// is NOT serialized into the block comment — the link text lives only in the
		// saved anchor. The override must recover it from the inner HTML.
		$content = '<!-- wp:newspack-newsletters/share {"href":"mailto:?body=read-this"} -->'
			. '<p class="newspack-newsletters-share-block"><a href="mailto:?body=read-this">Share this</a></p>'
			. '<!-- /wp:newspack-newsletters/share -->';

		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Public share newsletter',
				'post_content' => $content,
			]
		);
		update_post_meta( $post_id, 'is_public', 1 );

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		$this->assertStringContainsString( 'mailto:?body=read-this', $html, 'Expected the share anchor href in a public newsletter email.' );
		$this->assertStringContainsString( 'Share this', $html, 'Expected the share link text in a public newsletter email.' );
	}

	/**
	 * A non-public newsletter renders no share anchor.
	 *
	 * Without `is_public` meta the share link points nowhere, so the override must
	 * emit nothing for the share block.
	 */
	public function test_share_integration_non_public_renders_no_anchor() {
		Editor_Bootstrap::init();

		// Mirror production: `content` is an HTML-sourced RichText attribute, so it
		// is NOT serialized into the block comment — the link text lives only in the
		// saved anchor. The override must recover it from the inner HTML.
		$content = '<!-- wp:newspack-newsletters/share {"href":"mailto:?body=read-this"} -->'
			. '<p class="newspack-newsletters-share-block"><a href="mailto:?body=read-this">Share this</a></p>'
			. '<!-- /wp:newspack-newsletters/share -->';

		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Non-public share newsletter',
				'post_content' => $content,
			]
		);

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		$this->assertStringNotContainsString( 'mailto:?body=read-this', $html, 'Expected no share anchor href in a non-public newsletter email.' );
	}
}
