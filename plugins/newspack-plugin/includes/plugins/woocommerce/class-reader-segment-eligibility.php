<?php
/**
 * Reader Segment Eligibility — "is this reader in any of these segments?" against
 * the reader's segment snapshot. No dynamic-pricing-engine dependency, so it
 * loads and unit-tests without the engine present; the engine-coupled matcher
 * (Reader_Segment_Condition_Matcher) adapts it.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Pure segment-membership check against the reader-data snapshot.
 */
final class Reader_Segment_Eligibility {
	/**
	 * Whether the reader's last-known segment snapshot intersects any selected ID.
	 *
	 * @param int   $user_id      Reader user ID (0 / guest → false).
	 * @param array $selected_ids Segment IDs to match against (any-of).
	 * @return bool
	 */
	public static function is_in_any( int $user_id, array $selected_ids ): bool {
		if ( $user_id <= 0 || empty( $selected_ids ) ) {
			return false;
		}
		$selected = array_map( 'strval', $selected_ids );
		return (bool) array_intersect( $selected, Reader_Data::get_matched_segments( $user_id ) );
	}
}
