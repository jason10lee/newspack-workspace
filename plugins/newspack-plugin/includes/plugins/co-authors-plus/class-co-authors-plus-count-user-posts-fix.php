<?php
/**
 * Co-Authors Plus count_user_posts dedup integration.
 *
 * CAP's filter_count_user_posts adds a user's own post_author count to the
 * count of posts attached to a linked guest author's taxonomy term without
 * deduplication. On sites where the editorial workflow tags the same posts
 * with both attribution paths (e.g. MinnPost), this inflates the wp-admin
 * Users-list "Posts" column.
 *
 * This filter recomputes count_user_posts as COUNT(DISTINCT) over the union
 * of (post_author = user) and (attached to GA term), so the same post is
 * counted once regardless of how it's attributed.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Deduplicates count_user_posts for WP users linked to a CAP guest author.
 */
class Co_Authors_Plus_Count_User_Posts_Fix {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Priority 11 runs after CAP's own filter_count_user_posts at priority 10.
		add_filter( 'get_usernumposts', [ __CLASS__, 'dedup_linked_author_count' ], 11, 4 );
	}

	/**
	 * Replace the count CAP produced with a deduplicated union count.
	 *
	 * @param int|string $count       The count value from previous filters (CAP at priority 10).
	 * @param int        $user_id     The user ID.
	 * @param string     $post_type   The post type to count.
	 * @param bool       $public_only Whether to restrict the count to public-status posts.
	 * @return int|string The deduplicated count, or the original $count if no linked GA.
	 */
	public static function dedup_linked_author_count( $count, $user_id, $post_type, $public_only ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return $count;
		}

		$ga_term_taxonomy_id = self::get_linked_guest_author_term_taxonomy_id( $user->user_login );
		if ( ! $ga_term_taxonomy_id ) {
			return $count;
		}

		return self::count_distinct_attributed_posts( $user_id, $ga_term_taxonomy_id, $post_type, (bool) $public_only );
	}

	/**
	 * Find the term_taxonomy_id of the author term belonging to a guest author
	 * whose cap-linked_account meta matches the given user_login.
	 *
	 * @param string $user_login The WP user's user_login value.
	 * @return int|null term_taxonomy_id, or null if no linked GA exists.
	 */
	private static function get_linked_guest_author_term_taxonomy_id( $user_login ) {
		if ( ! post_type_exists( 'guest-author' ) || ! taxonomy_exists( 'author' ) ) {
			return null;
		}

		global $wpdb;

		// Find the GA CPT post whose cap-linked_account meta matches the user's login.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- WP_Query + meta_query would be markedly slower; this fires per count_user_posts call.
		$ga_post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = 'guest-author'
				AND p.post_status != 'trash'
				AND pm.meta_key = 'cap-linked_account'
				AND pm.meta_value = %s
				LIMIT 1",
				$user_login
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		if ( ! $ga_post_id ) {
			return null;
		}

		$terms = wp_get_object_terms( (int) $ga_post_id, 'author', [ 'fields' => 'tt_ids' ] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		return (int) $terms[0];
	}

	/**
	 * Run a COUNT(DISTINCT) over the union of (post_author = user) and
	 * (attached to GA term), honoring post type and public-only filters.
	 *
	 * @param int             $user_id              The user ID.
	 * @param int             $ga_term_taxonomy_id  The author term's term_taxonomy_id.
	 * @param string|string[] $post_type            The post type(s) to count (WP's count_user_posts accepts either).
	 * @param bool            $public_only          Restrict to public-status posts.
	 * @return int The deduplicated count.
	 */
	private static function count_distinct_attributed_posts( $user_id, $ga_term_taxonomy_id, $post_type, $public_only ) {
		global $wpdb;

		$type_sql   = self::build_in_list_fragment( $post_type );
		$status_sql = self::build_in_list_fragment( self::resolve_post_statuses( $public_only ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- IN clauses are built from a known-safe esc_sql'd allow-list (post types and registered post statuses); direct query needed for COUNT(DISTINCT) over the union.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->term_relationships} tr
					ON tr.object_id = p.ID AND tr.term_taxonomy_id = %d
				WHERE p.post_type IN ({$type_sql})
				AND p.post_status IN ({$status_sql})
				AND ( p.post_author = %d OR tr.object_id IS NOT NULL )",
				$ga_term_taxonomy_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		return (int) $count;
	}

	/**
	 * Resolve the post statuses to count over, matching WP's count_user_posts behavior.
	 *
	 * @param bool $public_only Restrict to public statuses when true.
	 * @return string[] Post status names.
	 */
	private static function resolve_post_statuses( $public_only ) {
		return $public_only ? get_post_stati( [ 'public' => true ] ) : get_post_stati();
	}

	/**
	 * Build a comma-separated list of quoted, escaped values for safe SQL interpolation.
	 *
	 * @param string|string[] $values One value or an array of values.
	 * @return string e.g. "'post','page'"
	 */
	private static function build_in_list_fragment( $values ) {
		if ( ! is_array( $values ) ) {
			$values = [ $values ];
		}
		$escaped = array_map( 'esc_sql', $values );
		return "'" . implode( "','", $escaped ) . "'";
	}
}
Co_Authors_Plus_Count_User_Posts_Fix::init();
