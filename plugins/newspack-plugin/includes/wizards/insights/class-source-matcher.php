<?php
/**
 * Newspack Insights — Source Matcher.
 *
 * Attaches a BigQuery-derived conversion source (gate / prompt / direct) to a
 * local record (a Woo order or a WP user) by TIMESTAMP PROXIMITY — never by a BQ
 * identifier. BQ's `user_id`/`user_pseudo_id` is a GA client cookie, not a WP/Woo
 * customer ID, so the old `(int)$uid → customer_id` join silently dropped ~all
 * rows. Instead we anchor on the local record's own timestamp (`date_created` for
 * orders, `user_registered` for users) and find the BQ source event that falls in
 * the record's window. Pure: no DB access, fully unit-testable with arrays.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

/**
 * Matches local records to BigQuery source events by timestamp window.
 */
class Source_Matcher {

	/** Symmetric ±window (seconds) for the user_registered anchor (registration ≈ reg event). */
	const WINDOW_REGISTRATION_SECONDS = 120;

	/** Window (seconds) for the order anchor; event must precede the order (attempt → completion). */
	const WINDOW_ORDER_SECONDS = 1800;

	/** Source bucket for records with no matching event. */
	const SOURCE_DIRECT = 'direct';

	/** All source buckets, in display order. */
	const SOURCES = [ 'gate', 'prompt', self::SOURCE_DIRECT ];

	/**
	 * Attach a source to each record by finding a BQ event in [ts − before, ts + after].
	 *
	 * Each event is consumed at most once (assigned to the nearest eligible record)
	 * to limit over-attribution on dense windows. Records with no eligible event get
	 * SOURCE_DIRECT.
	 *
	 * @param array $records        [ [ 'key' => int|string, 'ts' => int ], … ] (ts = epoch seconds).
	 * @param array $events         [ [ 'ts' => int, 'source' => string ], … ] (ts = epoch seconds).
	 * @param int   $before_seconds Seconds before the record's ts an event may fall.
	 * @param int   $after_seconds  Seconds after the record's ts an event may fall.
	 * @return array<int|string,string> Map of record key => source.
	 */
	public static function attach_sources( array $records, array $events, int $before_seconds, int $after_seconds ): array {
		$result = [];
		foreach ( $records as $record ) {
			$result[ $record['key'] ] = self::SOURCE_DIRECT;
		}

		// Greedy nearest-match: for each event, claim the nearest unclaimed record in range.
		$claimed = [];
		foreach ( $events as $event ) {
			$best_key  = null;
			$best_dist = null;
			foreach ( $records as $record ) {
				$key = $record['key'];
				if ( isset( $claimed[ $key ] ) ) {
					continue;
				}
				$lo = $record['ts'] - $before_seconds;
				$hi = $record['ts'] + $after_seconds;
				if ( $event['ts'] < $lo || $event['ts'] > $hi ) {
					continue;
				}
				$dist = abs( $event['ts'] - $record['ts'] );
				if ( null === $best_dist || $dist < $best_dist ) {
					$best_dist = $dist;
					$best_key  = $key;
				}
			}
			if ( null !== $best_key ) {
				$result[ $best_key ] = $event['source'];
				$claimed[ $best_key ] = true;
			}
		}

		return $result;
	}

	/**
	 * Tally a key=>source map into per-source counts (all buckets zero-filled).
	 *
	 * @param array<int|string,string> $key_to_source Output of attach_sources().
	 * @return array<string,int>
	 */
	public static function count_by_source( array $key_to_source ): array {
		$counts = array_fill_keys( self::SOURCES, 0 );
		foreach ( $key_to_source as $source ) {
			if ( isset( $counts[ $source ] ) ) {
				++$counts[ $source ];
			}
		}
		return $counts;
	}
}
