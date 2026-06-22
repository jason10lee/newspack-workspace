<?php
/**
 * Class Theme Native Editor Test
 *
 * @package Newspack_Newsletters
 */

/**
 * Guard test for irreducible email-structural constraints under the WC renderer.
 *
 * These constraints must hold regardless of which theme is active or whether the
 * theme-native flag is on or off: the newsletter canvas is always capped at 600 px
 * and the allowed-block list is always restricted to email-safe blocks.
 */
class Test_Theme_Native_Editor extends WP_UnitTestCase {

	/**
	 * Create a newsletter CPT post and return its WP_Post object.
	 *
	 * @return \WP_Post
	 */
	private function create_newsletter_post(): \WP_Post {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'draft',
				'post_title'  => 'Guard-test newsletter',
			]
		);
		return get_post( $post_id );
	}

	/**
	 * Build a minimal block-editor-settings array that mirrors what
	 * block_editor_settings_all passes into override_email_editor_settings().
	 *
	 * The method inspects $editor_settings['__experimentalFeatures']['layout']
	 * to apply the 600 px override, so that key must exist (any non-empty values
	 * are fine — the method replaces them).
	 *
	 * @return array
	 */
	private function make_editor_settings(): array {
		return [
			'__experimentalFeatures' => [
				'layout'     => [
					'contentSize' => '1200px',
					'wideSize'    => '1200px',
				],
				'typography' => [
					'fontFamilies' => [
						[
							'slug' => 'inter',
							'name' => 'Inter',
						],
					],
				],
			],
		];
	}

	/**
	 * Build a WP_Block_Editor_Context with the newsletter post attached,
	 * mirroring what WordPress passes to block_editor_settings_all for a
	 * newsletter editor request.
	 *
	 * @param \WP_Post $post Newsletter post.
	 * @return \WP_Block_Editor_Context
	 */
	private function make_editor_context( \WP_Post $post ): \WP_Block_Editor_Context {
		return new \WP_Block_Editor_Context( [ 'post' => $post ] );
	}

	// -------------------------------------------------------------------------
	// Email-width constraints.
	// -------------------------------------------------------------------------

	/**
	 * Verify that contentSize is pinned to 600 px for a newsletter CPT context.
	 *
	 * The override_email_editor_settings() method must force contentSize to 600 px
	 * so the editor canvas always matches the email max-width, regardless of what
	 * the active theme declares. This is a structural invariant: even with the
	 * theme-native flag ON the email canvas must be 600 px wide (email clients
	 * clip wider content).
	 */
	public function test_override_sets_content_size_to_600px_for_newsletter_post() {
		$post     = $this->create_newsletter_post();
		$settings = $this->make_editor_settings();
		$context  = $this->make_editor_context( $post );

		$result = \Newspack_Newsletters_Editor::override_email_editor_settings( $settings, $context );

		$this->assertSame(
			'600px',
			$result['__experimentalFeatures']['layout']['contentSize'],
			'contentSize must be 600 px for a newsletter editor regardless of theme.'
		);
	}

	/**
	 * Verify that wideSize is pinned to 600 px for a newsletter CPT context.
	 *
	 * The override_email_editor_settings() method must force wideSize to 600 px
	 * so a "wide" block alignment cannot exceed the 600 px email envelope.
	 */
	public function test_override_sets_wide_size_to_600px_for_newsletter_post() {
		$post     = $this->create_newsletter_post();
		$settings = $this->make_editor_settings();
		$context  = $this->make_editor_context( $post );

		$result = \Newspack_Newsletters_Editor::override_email_editor_settings( $settings, $context );

		$this->assertSame(
			'600px',
			$result['__experimentalFeatures']['layout']['wideSize'],
			'wideSize must be 600 px for a newsletter editor regardless of theme.'
		);
	}

	/**
	 * Verify that non-newsletter posts are not affected by the email width override.
	 *
	 * The override_email_editor_settings() method must be a no-op for a regular
	 * post so that standard post editors retain their theme-supplied layout sizes.
	 */
	public function test_override_does_not_affect_non_newsletter_post() {
		$post_id  = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$post     = get_post( $post_id );
		$settings = $this->make_editor_settings();
		$context  = $this->make_editor_context( $post );

		$result = \Newspack_Newsletters_Editor::override_email_editor_settings( $settings, $context );

		$this->assertSame(
			'1200px',
			$result['__experimentalFeatures']['layout']['contentSize'],
			'contentSize must not be altered for a non-newsletter post type.'
		);
	}

	// -------------------------------------------------------------------------
	// Allowed-block constraints.
	// -------------------------------------------------------------------------

	/**
	 * Verify that newsletters_allowed_block_types() returns an array, not true.
	 *
	 * Returning boolean true would allow every registered block, which is wrong
	 * for email because many blocks (gallery, video, table-of-contents, etc.)
	 * have no email-safe rendering path. The method must restrict to the curated
	 * email-safe subset.
	 *
	 * Note: is_editing_email() uses get_the_ID() which reads from the global
	 * $post; we prime it via setup_postdata() so the method resolves the CPT
	 * correctly in the test environment (no HTTP request / $pagenow available).
	 */
	public function test_allowed_block_types_returns_array_not_true_for_newsletter() {
		$newsletter = $this->create_newsletter_post();

		// Prime the global $post so get_the_ID() returns the newsletter post.
		global $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post = $newsletter;
		setup_postdata( $newsletter );

		$result = \Newspack_Newsletters_Editor::newsletters_allowed_block_types( true, $newsletter );

		wp_reset_postdata();

		$this->assertIsArray(
			$result,
			'newsletters_allowed_block_types() must return an array, not boolean true, for newsletters.'
		);
	}

	/**
	 * Verify that the newsletter allow-list includes core structural blocks.
	 *
	 * Paragraph, heading, group, columns, and buttons are required by every
	 * newsletter. Their absence from the allow-list would break the editor.
	 */
	public function test_allowed_block_types_includes_core_structural_blocks() {
		$newsletter = $this->create_newsletter_post();

		global $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post = $newsletter;
		setup_postdata( $newsletter );

		$result = \Newspack_Newsletters_Editor::newsletters_allowed_block_types( true, $newsletter );

		wp_reset_postdata();

		$required_blocks = [
			'core/paragraph',
			'core/heading',
			'core/group',
			'core/columns',
			'core/column',
			'core/buttons',
			'core/button',
		];
		foreach ( $required_blocks as $block ) {
			$this->assertContains(
				$block,
				$result,
				"Required block '$block' must be in the newsletter allow-list."
			);
		}
	}

	/**
	 * Verify that non-newsletter posts are not subject to the block restriction.
	 *
	 * The newsletters_allowed_block_types() method must pass through the incoming
	 * value unchanged for post types that are not email editor CPTs, so standard
	 * post editors retain access to all registered blocks.
	 */
	public function test_allowed_block_types_passes_through_for_non_newsletter() {
		$post_id      = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$regular_post = get_post( $post_id );

		global $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post = $regular_post;
		setup_postdata( $regular_post );

		$result = \Newspack_Newsletters_Editor::newsletters_allowed_block_types( true, $regular_post );

		wp_reset_postdata();

		$this->assertTrue(
			$result,
			'newsletters_allowed_block_types() must not restrict blocks for a non-newsletter post.'
		);
	}
}
