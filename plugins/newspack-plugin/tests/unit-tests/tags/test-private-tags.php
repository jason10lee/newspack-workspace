<?php
/**
 * Tests for the Newspack\Private_Tags class.
 *
 * @package Newspack\Tests
 */

use Newspack\Private_Tags;

require_once __DIR__ . '/traits/trait-private-tags-test-helper.php';

/**
 * Core Private_Tags class test matrix.
 *
 * @group private-tags
 */
class Test_Private_Tags extends WP_UnitTestCase {

	use Private_Tags_Test_Helper;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->enable_private_tags_feature();
		$this->reset_private_tags_state();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->reset_private_tags_state();
		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// Feature flag.
	// -----------------------------------------------------------------

	/**
	 * Is enabled returns true when constant defined.
	 */
	public function test_is_enabled_returns_true_when_constant_defined() {
		$this->assertTrue( Private_Tags::is_enabled() );
	}

	// -----------------------------------------------------------------
	// is_term_private().
	// -----------------------------------------------------------------

	/**
	 * Is term private true for marked tag.
	 */
	public function test_is_term_private_true_for_marked_tag() {
		$id = $this->make_private_tag( 'Beastie' );
		$this->assertTrue( Private_Tags::is_term_private( get_term( $id, 'post_tag' ) ) );
	}

	/**
	 * Is term private false for unmarked tag.
	 */
	public function test_is_term_private_false_for_unmarked_tag() {
		$id = $this->make_public_tag( 'Jazz' );
		$this->assertFalse( Private_Tags::is_term_private( get_term( $id, 'post_tag' ) ) );
	}

	/**
	 * Is term private false for non post tag taxonomy.
	 */
	public function test_is_term_private_false_for_non_post_tag_taxonomy() {
		$cat_id = $this->factory()->term->create( [ 'taxonomy' => 'category' ] );
		update_term_meta( $cat_id, Private_Tags::META_KEY, 1 );
		$this->assertFalse( Private_Tags::is_term_private( get_term( $cat_id, 'category' ) ) );
	}

	// -----------------------------------------------------------------
	// Default settings + is_behavior_enabled().
	// -----------------------------------------------------------------

	/**
	 * Default settings has expected keys.
	 */
	public function test_default_settings_has_expected_keys() {
		$settings = Private_Tags::get_settings();
		foreach ( [ 'all', 'archives', 'feeds', 'feed_terms', 'tag_links', 'tag_clouds', 'css_classes', 'gam_targeting', 'yoast_metadata', 'yoast_sitemap' ] as $key ) {
			$this->assertArrayHasKey( $key, $settings, "Default settings missing key: {$key}" );
			$this->assertTrue( $settings[ $key ], "Default for {$key} should be true" );
		}
	}

	/**
	 * Is behavior enabled returns true when all master is on.
	 */
	public function test_is_behavior_enabled_returns_true_when_all_master_is_on() {
		$this->set_private_tags_settings( [ 'all' => true ] );
		// Individual flag is off, but master 'all' overrides.
		$this->assertTrue( Private_Tags::is_behavior_enabled( 'tag_links' ) );
		$this->assertTrue( Private_Tags::is_behavior_enabled( 'feed_terms' ) );
	}

	/**
	 * Is behavior enabled uses individual flag when all off.
	 */
	public function test_is_behavior_enabled_uses_individual_flag_when_all_off() {
		$this->set_private_tags_settings(
			[
				'all'       => false,
				'tag_links' => true,
			]
		);
		$this->assertTrue( Private_Tags::is_behavior_enabled( 'tag_links' ) );
		$this->assertFalse( Private_Tags::is_behavior_enabled( 'feed_terms' ) );
	}

	// -----------------------------------------------------------------
	// sanitize_settings().
	// -----------------------------------------------------------------

	/**
	 * Sanitize settings whitelists known keys.
	 */
	public function test_sanitize_settings_whitelists_known_keys() {
		$raw = [
			'all'        => '1',
			'feeds'      => true,
			'unknownkey' => true,
		];
		$out = Private_Tags::sanitize_settings( $raw );
		$this->assertArrayHasKey( 'all', $out );
		$this->assertTrue( $out['all'] );
		$this->assertArrayHasKey( 'feeds', $out );
		$this->assertTrue( $out['feeds'] );
		$this->assertArrayNotHasKey( 'unknownkey', $out );
	}

	/**
	 * Sanitize settings casts missing keys to false.
	 */
	public function test_sanitize_settings_casts_missing_keys_to_false() {
		$out = Private_Tags::sanitize_settings( [ 'all' => true ] );
		// Missing 'archives' key should be coerced to false.
		$this->assertFalse( $out['archives'] );
		$this->assertFalse( $out['feed_terms'] );
	}

	/**
	 * Sanitize settings handles non array input.
	 */
	public function test_sanitize_settings_handles_non_array_input() {
		$out = Private_Tags::sanitize_settings( 'garbage' );
		$this->assertIsArray( $out );
		$this->assertFalse( $out['all'] );
	}

	// -----------------------------------------------------------------
	// maybe_append_private_label().
	// -----------------------------------------------------------------

	/**
	 * Maybe append private label adds suffix for private tag.
	 */
	public function test_maybe_append_private_label_adds_suffix_for_private_tag() {
		$id   = $this->make_private_tag( 'Beastie' );
		$name = Private_Tags::maybe_append_private_label( $id, 'Beastie' );
		$this->assertStringEndsWith( '(private)', $name );
	}

	/**
	 * Maybe append private label unchanged for public tag.
	 */
	public function test_maybe_append_private_label_unchanged_for_public_tag() {
		$id = $this->make_public_tag( 'Jazz' );
		$this->assertSame( 'Jazz', Private_Tags::maybe_append_private_label( $id, 'Jazz' ) );
	}

	/**
	 * Maybe append private label idempotent on already suffixed name.
	 */
	public function test_maybe_append_private_label_idempotent_on_already_suffixed_name() {
		$id   = $this->make_private_tag( 'Beastie' );
		$once = Private_Tags::maybe_append_private_label( $id, 'Beastie' );
		$twice = Private_Tags::maybe_append_private_label( $id, $once );
		$this->assertSame( $once, $twice );
	}

	/**
	 * Maybe append private label returns non string unchanged.
	 */
	public function test_maybe_append_private_label_returns_non_string_unchanged() {
		$id = $this->make_private_tag( 'Beastie' );
		$this->assertNull( Private_Tags::maybe_append_private_label( $id, null ) );
		$this->assertSame( [], Private_Tags::maybe_append_private_label( $id, [] ) );
	}

	/**
	 * Maybe append private label handles name containing private substring.
	 */
	public function test_maybe_append_private_label_handles_name_containing_private_substring() {
		// A name that contains "(private)" mid-string should still get the suffix appended.
		$id   = $this->make_private_tag( 'My (private) Notes' );
		$out  = Private_Tags::maybe_append_private_label( $id, 'My (private) Notes' );
		$this->assertStringEndsWith( '(private)', $out );
		$this->assertNotSame( 'My (private) Notes', $out );
	}

	// -----------------------------------------------------------------
	// Cache invalidation.
	// -----------------------------------------------------------------

	/**
	 * Clear cache drops stored ids.
	 */
	public function test_clear_cache_drops_stored_ids() {
		$id = $this->make_private_tag( 'Beastie' );
		// Prime cache.
		Private_Tags::maybe_append_private_label( $id, 'Beastie' );
		// Remove the meta directly + clear cache; next lookup should miss.
		delete_term_meta( $id, Private_Tags::META_KEY );
		Private_Tags::clear_cache();
		$this->assertSame( 'Beastie', Private_Tags::maybe_append_private_label( $id, 'Beastie' ) );
	}

	/**
	 * Maybe clear cache only fires for meta key.
	 */
	public function test_maybe_clear_cache_only_fires_for_meta_key() {
		$id      = $this->make_private_tag( 'Beastie' );
		$public  = $this->make_public_tag( 'Jazz' );
		// Prime cache.
		$this->assertStringEndsWith( '(private)', Private_Tags::maybe_append_private_label( $id, 'Beastie' ) );

		// Unrelated meta change: should NOT clear the cache. If it did, the next call
		// would re-query (still returning the same result). To detect a (wrong) cache
		// clear, we delete the private meta DIRECTLY via $wpdb so the action doesn't
		// fire, then flip an unrelated meta. If the unrelated meta change clears the
		// cache, the next lookup will re-query and miss; if not, the stale cache wins.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional bypass of meta hooks for test isolation.
		$wpdb->delete(
			$wpdb->termmeta,
			[
				'term_id'  => $id,
				'meta_key' => Private_Tags::META_KEY,
			]
		);
		wp_cache_delete( $id, 'term_meta' );
		update_term_meta( $public, 'unrelated_key', 'value' );

		// Cache should still be populated with the (now stale) private ID — label persists.
		$this->assertStringEndsWith(
			'(private)',
			Private_Tags::maybe_append_private_label( $id, 'Beastie' ),
			'Unrelated meta updates must not invalidate the private-tag cache.'
		);
	}

	/**
	 * Cache invalidated when private meta changes.
	 */
	public function test_cache_invalidated_when_private_meta_changes() {
		$id = $this->make_public_tag( 'Jazz' );
		// Prime cache as non-private.
		$this->assertSame( 'Jazz', Private_Tags::maybe_append_private_label( $id, 'Jazz' ) );
		// Mark private — this should trigger added_term_meta → maybe_clear_cache.
		update_term_meta( $id, Private_Tags::META_KEY, 1 );
		$this->assertStringEndsWith( '(private)', Private_Tags::maybe_append_private_label( $id, 'Jazz' ) );
	}

	// -----------------------------------------------------------------
	// filter_tag_links().
	// -----------------------------------------------------------------

	/**
	 * Filter tag links returns unchanged in admin.
	 */
	public function test_filter_tag_links_returns_unchanged_in_admin() {
		set_current_screen( 'edit-post' );
		$links = [ '<a href="x">Private</a>', '<a href="y">Public</a>' ];
		$this->assertSame( $links, Private_Tags::filter_tag_links( $links ) );
		set_current_screen( 'front' );
	}

	/**
	 * Filter tag links returns unchanged when behavior disabled.
	 */
	public function test_filter_tag_links_returns_unchanged_when_behavior_disabled() {
		$this->set_private_tags_settings(
			[
				'all'       => false,
				'tag_links' => false,
			] 
		);
		$links = [ '<a href="x">Private</a>' ];
		$this->assertSame( $links, Private_Tags::filter_tag_links( $links ) );
	}

	/**
	 * Filter tag links removes private tag and keeps public.
	 */
	public function test_filter_tag_links_removes_private_tag_and_keeps_public() {
		$private = $this->make_private_tag( 'Beastie' );
		$public  = $this->make_public_tag( 'Jazz' );
		$post_id = $this->factory()->post->create();
		wp_set_object_terms( $post_id, [ $private, $public ], 'post_tag' );

		// Simulate "in the loop" for this post.
		global $wp_query, $post;
		$prev_post  = $post;
		$post       = get_post( $post_id );
		$wp_query->in_the_loop = true;
		$GLOBALS['post']       = $post;

		$out = Private_Tags::filter_tag_links( [ '<a>placeholder</a>' ] );

		$wp_query->in_the_loop = false;
		$GLOBALS['post']       = $prev_post;

		$this->assertCount( 1, $out, 'Only the public tag should remain.' );
		$this->assertStringContainsString( 'Jazz', $out[0] );
		$this->assertStringNotContainsString( 'Beastie', $out[0] );
	}

	/**
	 * Filter tag links empty when all tags private.
	 */
	public function test_filter_tag_links_empty_when_all_tags_private() {
		$private = $this->make_private_tag( 'Beastie' );
		$post_id = $this->factory()->post->create();
		wp_set_object_terms( $post_id, [ $private ], 'post_tag' );

		global $wp_query, $post;
		$prev_post  = $post;
		$post       = get_post( $post_id );
		$wp_query->in_the_loop = true;
		$GLOBALS['post']       = $post;

		$out = Private_Tags::filter_tag_links( [ '<a>placeholder</a>' ] );

		$wp_query->in_the_loop = false;
		$GLOBALS['post']       = $prev_post;

		$this->assertSame( [], $out );
	}

	// -----------------------------------------------------------------
	// filter_tag_cloud().
	// -----------------------------------------------------------------

	/**
	 * Filter tag cloud removes private tags.
	 */
	public function test_filter_tag_cloud_removes_private_tags() {
		$private = $this->make_private_tag( 'Beastie' );
		$public  = $this->make_public_tag( 'Jazz' );
		$tags    = [ get_term( $private, 'post_tag' ), get_term( $public, 'post_tag' ) ];

		$out = Private_Tags::filter_tag_cloud( $tags );

		$this->assertCount( 1, $out );
		$this->assertSame( 'Jazz', reset( $out )->name );
	}

	/**
	 * Filter tag cloud keeps terms from other taxonomies.
	 */
	public function test_filter_tag_cloud_keeps_terms_from_other_taxonomies() {
		$private = $this->make_private_tag( 'Beastie' );
		$cat_id  = $this->factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'News',
			] 
		);

		$tags = [ get_term( $private, 'post_tag' ), get_term( $cat_id, 'category' ) ];
		$out  = Private_Tags::filter_tag_cloud( $tags );

		// Private tag removed; category preserved.
		$this->assertCount( 1, $out );
		$this->assertSame( 'News', reset( $out )->name );
	}

	/**
	 * Filter tag cloud returns unchanged when behavior disabled.
	 */
	public function test_filter_tag_cloud_returns_unchanged_when_behavior_disabled() {
		$this->set_private_tags_settings(
			[
				'all'        => false,
				'tag_clouds' => false,
			] 
		);
		$private = $this->make_private_tag( 'Beastie' );
		$tags    = [ get_term( $private, 'post_tag' ) ];
		$this->assertSame( $tags, Private_Tags::filter_tag_cloud( $tags ) );
	}

	// -----------------------------------------------------------------
	// disable_tag_archives() — archive vs feed gating.
	// -----------------------------------------------------------------

	/**
	 * Build a WP_Query mock that simulates a main-query tag archive (optionally a feed).
	 *
	 * @param int  $term_id The tag term ID returned by get_queried_object().
	 * @param bool $is_feed Whether the simulated request is a feed.
	 * @return WP_Query
	 */
	private function build_archive_query_stub( $term_id, $is_feed = false ) {
		$term  = get_term( $term_id, 'post_tag' );
		$query = $this->getMockBuilder( WP_Query::class )
			->onlyMethods( [ 'is_main_query', 'is_tag', 'is_feed', 'get_queried_object' ] )
			->getMock();
		$query->method( 'is_main_query' )->willReturn( true );
		$query->method( 'is_tag' )->willReturn( true );
		$query->method( 'is_feed' )->willReturn( $is_feed );
		$query->method( 'get_queried_object' )->willReturn( $term );
		return $query;
	}

	/**
	 * Disable tag archives sets 404 for private tag archive.
	 */
	public function test_disable_tag_archives_sets_404_for_private_tag_archive() {
		$private = $this->make_private_tag( 'Beastie' );
		$query   = $this->build_archive_query_stub( $private, false );

		Private_Tags::disable_tag_archives( $query );

		$this->assertTrue( $query->is_404(), 'Private tag archive should set 404.' );
	}

	/**
	 * Disable tag archives does not 404 public tag.
	 */
	public function test_disable_tag_archives_does_not_404_public_tag() {
		$public = $this->make_public_tag( 'Jazz' );
		$query  = $this->build_archive_query_stub( $public, false );

		Private_Tags::disable_tag_archives( $query );

		$this->assertFalse( $query->is_404() );
	}

	/**
	 * Disable tag archives respects archives behavior flag.
	 */
	public function test_disable_tag_archives_respects_archives_behavior_flag() {
		$this->set_private_tags_settings(
			[
				'all'      => false,
				'archives' => false,
				'feeds'    => true,
			] 
		);
		$private = $this->make_private_tag( 'Beastie' );
		$query   = $this->build_archive_query_stub( $private, false );

		Private_Tags::disable_tag_archives( $query );

		// Archive 404 disabled — private archive should pass through.
		$this->assertFalse( $query->is_404() );
	}

	/**
	 * Disable tag archives 404s feed when feeds enabled.
	 */
	public function test_disable_tag_archives_404s_feed_when_feeds_enabled() {
		$private = $this->make_private_tag( 'Beastie' );
		$query   = $this->build_archive_query_stub( $private, true );

		Private_Tags::disable_tag_archives( $query );

		$this->assertTrue( $query->is_404() );
	}

	/**
	 * Disable tag archives respects feeds behavior flag.
	 */
	public function test_disable_tag_archives_respects_feeds_behavior_flag() {
		$this->set_private_tags_settings(
			[
				'all'      => false,
				'archives' => true,
				'feeds'    => false,
			] 
		);
		$private = $this->make_private_tag( 'Beastie' );
		$query   = $this->build_archive_query_stub( $private, true );

		Private_Tags::disable_tag_archives( $query );

		// Feeds 404 disabled — private feed should pass through.
		$this->assertFalse( $query->is_404() );
	}

	// -----------------------------------------------------------------
	// post_class / body_class — CSS class stripping.
	// -----------------------------------------------------------------

	/**
	 * Filter post class strips private tag class.
	 */
	public function test_filter_post_class_strips_private_tag_class() {
		$private = $this->make_private_tag( 'Beastie' );
		$slug    = get_term( $private, 'post_tag' )->slug;
		$classes = [ 'post', 'tag-jazz', 'tag-' . $slug ];

		$out = Private_Tags::filter_post_class( $classes );

		$this->assertNotContains( 'tag-' . $slug, $out );
		$this->assertContains( 'tag-jazz', $out );
		$this->assertContains( 'post', $out );
	}

	/**
	 * Filter body class strips private tag class.
	 */
	public function test_filter_body_class_strips_private_tag_class() {
		$private = $this->make_private_tag( 'Beastie' );
		$slug    = get_term( $private, 'post_tag' )->slug;
		$classes = [ 'tag-' . $slug, 'home' ];

		$out = Private_Tags::filter_body_class( $classes );

		$this->assertNotContains( 'tag-' . $slug, $out );
		$this->assertContains( 'home', $out );
	}

	/**
	 * Filter post class unchanged when css classes disabled.
	 */
	public function test_filter_post_class_unchanged_when_css_classes_disabled() {
		$this->set_private_tags_settings(
			[
				'all'         => false,
				'css_classes' => false,
			] 
		);
		$private = $this->make_private_tag( 'Beastie' );
		$slug    = get_term( $private, 'post_tag' )->slug;
		$classes = [ 'tag-' . $slug ];

		$this->assertSame( $classes, Private_Tags::filter_post_class( $classes ) );
	}

	// -----------------------------------------------------------------
	// filter_ad_targeting() — GAM integration.
	// -----------------------------------------------------------------

	/**
	 * Filter ad targeting strips private tag slugs.
	 */
	public function test_filter_ad_targeting_strips_private_tag_slugs() {
		$private = $this->make_private_tag( 'Beastie' );
		$slug    = get_term( $private, 'post_tag' )->slug;
		$tgt     = [ 'tag' => [ $slug, 'jazz' ] ];

		$out = Private_Tags::filter_ad_targeting( $tgt, [] );

		$this->assertSame( [ 'jazz' ], $out['tag'] );
	}

	/**
	 * Filter ad targeting unsets tag key when all private.
	 */
	public function test_filter_ad_targeting_unsets_tag_key_when_all_private() {
		$private = $this->make_private_tag( 'Beastie' );
		$slug    = get_term( $private, 'post_tag' )->slug;
		$tgt     = [ 'tag' => [ $slug ] ];

		$out = Private_Tags::filter_ad_targeting( $tgt, [] );

		$this->assertArrayNotHasKey( 'tag', $out );
	}

	/**
	 * Filter ad targeting unchanged when behavior disabled.
	 */
	public function test_filter_ad_targeting_unchanged_when_behavior_disabled() {
		$this->set_private_tags_settings(
			[
				'all'           => false,
				'gam_targeting' => false,
			] 
		);
		$private = $this->make_private_tag( 'Beastie' );
		$slug    = get_term( $private, 'post_tag' )->slug;
		$tgt     = [ 'tag' => [ $slug ] ];

		$this->assertSame( $tgt, Private_Tags::filter_ad_targeting( $tgt, [] ) );
	}

	/**
	 * Filter ad targeting unchanged when no tag key.
	 */
	public function test_filter_ad_targeting_unchanged_when_no_tag_key() {
		$this->make_private_tag( 'Beastie' );
		$tgt = [ 'category' => [ 'news' ] ];
		$this->assertSame( $tgt, Private_Tags::filter_ad_targeting( $tgt, [] ) );
	}

	// -----------------------------------------------------------------
	// Yoast integrations.
	// -----------------------------------------------------------------

	/**
	 * Filter yoast schema article strips private tag names.
	 */
	public function test_filter_yoast_schema_article_strips_private_tag_names() {
		$private = $this->make_private_tag( 'Beastie' );
		$public  = $this->make_public_tag( 'Jazz' );
		$post_id = $this->factory()->post->create();
		wp_set_object_terms( $post_id, [ $private, $public ], 'post_tag' );

		// filter_yoast_schema_article relies on get_queried_object_id().
		$this->go_to( get_permalink( $post_id ) );

		$data = [ 'keywords' => [ 'Beastie', 'Jazz' ] ];
		$out  = Private_Tags::filter_yoast_schema_article( $data, null );

		$this->assertSame( [ 'Jazz' ], $out['keywords'] );
	}

	/**
	 * Filter yoast schema article unsets keywords when all private.
	 */
	public function test_filter_yoast_schema_article_unsets_keywords_when_all_private() {
		$private = $this->make_private_tag( 'Beastie' );
		$post_id = $this->factory()->post->create();
		wp_set_object_terms( $post_id, [ $private ], 'post_tag' );

		$this->go_to( get_permalink( $post_id ) );

		$data = [ 'keywords' => [ 'Beastie' ] ];
		$out  = Private_Tags::filter_yoast_schema_article( $data, null );

		$this->assertArrayNotHasKey( 'keywords', $out );
	}

	/**
	 * Filter yoast schema article unchanged when behavior disabled.
	 */
	public function test_filter_yoast_schema_article_unchanged_when_behavior_disabled() {
		$this->set_private_tags_settings(
			[
				'all'            => false,
				'yoast_metadata' => false,
			] 
		);
		$private = $this->make_private_tag( 'Beastie' );
		$post_id = $this->factory()->post->create();
		wp_set_object_terms( $post_id, [ $private ], 'post_tag' );
		$this->go_to( get_permalink( $post_id ) );

		$data = [ 'keywords' => [ 'Beastie' ] ];
		$this->assertSame( $data, Private_Tags::filter_yoast_schema_article( $data, null ) );
	}

	/**
	 * Filter yoast sitemap term ids adds private ids.
	 */
	public function test_filter_yoast_sitemap_term_ids_adds_private_ids() {
		$private = $this->make_private_tag( 'Beastie' );
		$out     = Private_Tags::filter_yoast_sitemap_term_ids( [ 999 ] );
		$this->assertContains( 999, $out );
		$this->assertContains( $private, $out );
	}

	/**
	 * Filter yoast sitemap term ids dedupes.
	 */
	public function test_filter_yoast_sitemap_term_ids_dedupes() {
		$private = $this->make_private_tag( 'Beastie' );
		$out     = Private_Tags::filter_yoast_sitemap_term_ids( [ $private, 5 ] );
		$count   = array_count_values( $out )[ $private ] ?? 0;
		$this->assertSame( 1, $count );
	}

	/**
	 * Filter yoast sitemap term ids unchanged when behavior disabled.
	 */
	public function test_filter_yoast_sitemap_term_ids_unchanged_when_behavior_disabled() {
		$this->set_private_tags_settings(
			[
				'all'           => false,
				'yoast_sitemap' => false,
			] 
		);
		$this->make_private_tag( 'Beastie' );
		$existing = [ 1, 2, 3 ];
		$this->assertSame( $existing, Private_Tags::filter_yoast_sitemap_term_ids( $existing ) );
	}

	// -----------------------------------------------------------------
	// REST label.
	// -----------------------------------------------------------------

	/**
	 * Append private label to rest labels private tag for editor.
	 */
	public function test_append_private_label_to_rest_labels_private_tag_for_editor() {
		$editor = $this->factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );

		$private = $this->make_private_tag( 'Beastie' );
		$term    = get_term( $private, 'post_tag' );

		$response       = new WP_REST_Response( [ 'name' => 'Beastie' ] );
		$out            = Private_Tags::append_private_label_to_rest( $response, $term );
		$this->assertStringEndsWith( '(private)', $out->data['name'] );
	}

	/**
	 * Append private label to rest no op for subscriber.
	 */
	public function test_append_private_label_to_rest_no_op_for_subscriber() {
		$subscriber = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$private = $this->make_private_tag( 'Beastie' );
		$term    = get_term( $private, 'post_tag' );

		$response = new WP_REST_Response( [ 'name' => 'Beastie' ] );
		$out      = Private_Tags::append_private_label_to_rest( $response, $term );
		$this->assertSame( 'Beastie', $out->data['name'] );
	}

	/**
	 * Append private label to rest skips when name missing.
	 */
	public function test_append_private_label_to_rest_skips_when_name_missing() {
		$editor = $this->factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );

		$private = $this->make_private_tag( 'Beastie' );
		$term    = get_term( $private, 'post_tag' );

		$response = new WP_REST_Response( [ 'id' => $private ] );
		$out      = Private_Tags::append_private_label_to_rest( $response, $term );
		$this->assertArrayNotHasKey( 'name', $out->data );
	}

	// -----------------------------------------------------------------
	// Admin column.
	// -----------------------------------------------------------------

	/**
	 * Add private column appends column.
	 */
	public function test_add_private_column_appends_column() {
		$out = Private_Tags::add_private_column(
			[
				'name' => 'Name',
				'slug' => 'Slug',
			] 
		);
		$this->assertArrayHasKey( 'np_private', $out );
	}

	/**
	 * Render private column shows marker for private tag.
	 */
	public function test_render_private_column_shows_marker_for_private_tag() {
		$private = $this->make_private_tag( 'Beastie' );
		$out     = Private_Tags::render_private_column( '', 'np_private', $private );
		$this->assertStringContainsString( 'data-np-private="1"', $out );
	}

	/**
	 * Render private column blank marker for public tag.
	 */
	public function test_render_private_column_blank_marker_for_public_tag() {
		$public = $this->make_public_tag( 'Jazz' );
		$out    = Private_Tags::render_private_column( '', 'np_private', $public );
		$this->assertStringContainsString( 'data-np-private="0"', $out );
	}

	/**
	 * Render private column passes through other columns.
	 */
	public function test_render_private_column_passes_through_other_columns() {
		$private = $this->make_private_tag( 'Beastie' );
		$out     = Private_Tags::render_private_column( 'orig', 'name', $private );
		$this->assertSame( 'orig', $out );
	}

	// -----------------------------------------------------------------
	// term_name filter (admin label).
	// -----------------------------------------------------------------

	/**
	 * Append private label to name in admin via term id call.
	 */
	public function test_append_private_label_to_name_in_admin_via_term_id_call() {
		set_current_screen( 'edit-tags' );
		$private = $this->make_private_tag( 'Beastie' );
		$out     = Private_Tags::append_private_label_to_name( 'Beastie', $private, 'post_tag' );
		$this->assertStringEndsWith( '(private)', $out );
		set_current_screen( 'front' );
	}

	/**
	 * Append private label to name skips on frontend.
	 */
	public function test_append_private_label_to_name_skips_on_frontend() {
		set_current_screen( 'front' );
		$private = $this->make_private_tag( 'Beastie' );
		$out     = Private_Tags::append_private_label_to_name( 'Beastie', $private, 'post_tag' );
		$this->assertSame( 'Beastie', $out );
	}

	/**
	 * Append private label to name handles wp term arg.
	 */
	public function test_append_private_label_to_name_handles_wp_term_arg() {
		set_current_screen( 'edit-tags' );
		$private = $this->make_private_tag( 'Beastie' );
		$term    = get_term( $private, 'post_tag' );
		$out     = Private_Tags::append_private_label_to_name( 'Beastie', $term );
		$this->assertStringEndsWith( '(private)', $out );
		set_current_screen( 'front' );
	}

	/**
	 * Append private label to name ignores non post tag taxonomy.
	 */
	public function test_append_private_label_to_name_ignores_non_post_tag_taxonomy() {
		set_current_screen( 'edit-tags' );
		$cat_id = $this->factory()->term->create( [ 'taxonomy' => 'category' ] );
		update_term_meta( $cat_id, Private_Tags::META_KEY, 1 );
		$out = Private_Tags::append_private_label_to_name( 'News', $cat_id, 'category' );
		$this->assertSame( 'News', $out );
		set_current_screen( 'front' );
	}
}
