<?php
/**
 * Shared reader-population query for Insights order-storage backends.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery

/**
 * Provides get_reader_registration_dates() to both order-storage backends.
 *
 * The reader population is queried entirely from wp_users / wp_usermeta, which
 * are identical regardless of WooCommerce order storage (HPOS vs legacy CPT),
 * so the query lives here once and is shared by HPOS_Storage and Legacy_Storage
 * via `use` rather than duplicated. Self-contained — depends on no property or
 * helper of the using class.
 */
trait Reader_Population_Trait {

	/**
	 * Registration dates of READER accounts created in the trailing 365 days,
	 * keyed by user ID. "Reader" matches the base population of
	 * get_stale_registered_users(): users bearing the `np_reader` meta, or
	 * holding a 'subscriber'/'customer' role, excluding administrators and
	 * editors. The full reader set is returned — converters AND non-converters —
	 * because 5.1 uses it as the cohort denominator.
	 *
	 * Phase-A reader-role approximation applies (hardcoded roles, no filter
	 * layer), same caveat as get_stale_registered_users().
	 *
	 * @return array<int, \DateTimeImmutable> user_id => user_registered (UTC).
	 */
	public function get_reader_registration_dates(): array {
		global $wpdb;
		$prefix = $wpdb->prefix;

		// Trailing-365-day cutoff computed in PHP (UTC) and bound via prepare,
		// not SQL NOW(), so the window is stable regardless of the MySQL session
		// timezone. user_registered is stored in UTC.
		$cutoff = gmdate(
			'Y-m-d H:i:s',
			( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->modify( '-365 days' )->getTimestamp()
		);

		// Reader base population mirrors get_stale_registered_users(): np_reader
		// meta OR subscriber/customer role, excluding admins/editors. No
		// conversion exclusions — the full reader set is the 5.1 denominator.
		$sql = $wpdb->prepare(
			"SELECT u.ID, u.user_registered
			FROM {$prefix}users u
			WHERE (
				EXISTS (
					SELECT 1 FROM {$prefix}usermeta um
					WHERE um.user_id = u.ID
					  AND um.meta_key = 'np_reader'
					  AND um.meta_value != ''
				)
				OR EXISTS (
					SELECT 1 FROM {$prefix}usermeta um2
					WHERE um2.user_id = u.ID
					  AND um2.meta_key = '{$prefix}capabilities'
					  AND (
						um2.meta_value LIKE '%\"subscriber\"%'
						OR um2.meta_value LIKE '%\"customer\"%'
					  )
				)
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$prefix}usermeta um3
				WHERE um3.user_id = u.ID
				  AND um3.meta_key = '{$prefix}capabilities'
				  AND (
					um3.meta_value LIKE '%\"administrator\"%'
					OR um3.meta_value LIKE '%\"editor\"%'
				  )
			)
			AND u.user_registered >= %s",
			$cutoff
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$map  = [];
		foreach ( (array) $rows as $row ) {
			if ( empty( $row['user_registered'] ) ) {
				continue;
			}
			$map[ (int) $row['ID'] ] = new \DateTimeImmutable( $row['user_registered'], new \DateTimeZone( 'UTC' ) );
		}
		return $map;
	}
}
