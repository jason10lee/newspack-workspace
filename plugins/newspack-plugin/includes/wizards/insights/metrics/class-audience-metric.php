<?php
/**
 * Newspack Insights — Audience Metric orchestrator (Tab 1, NPPD-1648).
 *
 * Composes GA4 Data API `runReport` bodies (translated from
 * `~/Sites/insights-docs/formulas/tab-1-audience.md`) and returns
 * MetricCard-ready payloads. Dispatches between GA4 (v1, default) and the
 * BigQuery proxy (v1.1, NPPD-1630 — stubbed here) per the
 * `NEWSPACK_INSIGHTS_AUDIENCE_USE_GA4` constant (default true).
 *
 * Consumes the GA4 client landed in NPPD-1647
 * ({@see \Newspack\Insights\GA4\Client}) and its authoritative
 * custom-dimension detection ({@see \Newspack\GA4_Custom_Dimensions}).
 *
 * Payload shapes:
 *   scalar  : { value, computable, type: count|decimal }
 *   rate    : { value (0-1), computable, type: rate[, numerator, denominator] }
 *             (numerator/denominator included where meaningful; some rates that
 *             come straight from a GA4 metric omit them)
 *   rows    : { rows: [...], computable, type: breakdown|table|timeseries }
 *   overlay : { value: null, computable: false, overlay: { type, dimensions } }
 *   hidden  : { value: null, computable: false, hidden_in_v1: true }
 *
 * @package Newspack
 */

namespace Newspack\Insights;

use Newspack\Insights\GA4\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Audience (Tab 1) metric orchestrator.
 */
final class Audience_Metric {

	const CACHE_TTL        = 15 * MINUTE_IN_SECONDS;
	const CACHE_KEY_PREFIX = 'newspack_insights_audience_v1:';

	/**
	 * Whether this tab uses the GA4 path. Default true; flip the constant
	 * to false once the BQ catalog (NPPD-1630) ships and is validated.
	 *
	 * @return bool
	 */
	private static function use_ga4(): bool {
		return ! defined( 'NEWSPACK_INSIGHTS_AUDIENCE_USE_GA4' ) || NEWSPACK_INSIGHTS_AUDIENCE_USE_GA4;
	}

	/**
	 * Resolve the GA4 property ID from Site Kit's stored settings.
	 *
	 * @return string Numeric property ID, or '' when none is connected.
	 */
	private static function resolve_property_id(): string {
		$settings = get_option( 'googlesitekit_analytics-4_settings', [] );
		return is_array( $settings ) && ! empty( $settings['propertyID'] ) ? (string) $settings['propertyID'] : '';
	}

	/**
	 * Tab-level connection check. Returns a tab_error payload when the GA4
	 * path is active but no usable Google connection / property exists, else
	 * null. Checking once up front avoids N rejected runReport calls.
	 *
	 * @return array|null
	 */
	public static function connection_error(): ?array {
		if ( ! self::use_ga4() ) {
			return null;
		}
		$connected = class_exists( '\Newspack\Google_OAuth' )
			&& \Newspack\Google_OAuth::is_oauth_configured()
			&& '' !== self::resolve_property_id();
		if ( $connected ) {
			return null;
		}
		return [
			'tab_error'   => 'oauth_not_connected',
			'banner_text' => __( 'Connect Google Analytics in Newspack → Connections to see this tab.', 'newspack-plugin' ),
		];
	}

	/**
	 * Full tab payload for a window. When $compare is true, also computes the
	 * immediately-preceding same-length window under the `compare` key.
	 *
	 * @param string $start_date YYYY-MM-DD (site timezone).
	 * @param string $end_date   YYYY-MM-DD (site timezone).
	 * @param bool   $compare    Whether to attach the prior-period payload.
	 * @return array
	 */
	public static function get_all( string $start_date, string $end_date, bool $compare = false ): array {
		$error = self::connection_error();
		if ( null !== $error ) {
			return $error;
		}

		$payload = self::compute_window_cached( $start_date, $end_date );

		if ( $compare ) {
			[ $prior_start, $prior_end ] = self::prior_period( $start_date, $end_date );
			$payload['compare']          = self::compute_window_cached( $prior_start, $prior_end );
		}

		return $payload;
	}

	/**
	 * Realistic fixture payload for UI smoke testing without a GA4 connection.
	 * Returned by the REST controller when NEWSPACK_INSIGHTS_FIXTURE_MODE is on.
	 * Same { current, previous } shape the live controller assembles.
	 *
	 * @return array
	 */
	public static function get_fixture(): array {
		return require NEWSPACK_ABSPATH . 'includes/wizards/insights/fixtures/audience-fixture.php';
	}

	/**
	 * Immediately-preceding window of equal length.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return string[] [ prior_start, prior_end ] as YYYY-MM-DD.
	 */
	private static function prior_period( string $start_date, string $end_date ): array {
		$start = new \DateTimeImmutable( $start_date );
		$end   = new \DateTimeImmutable( $end_date );
		$days  = (int) $start->diff( $end )->format( '%a' ) + 1;
		$prior_end   = $start->modify( '-1 day' );
		$prior_start = $prior_end->modify( '-' . ( $days - 1 ) . ' days' );
		return [ $prior_start->format( 'Y-m-d' ), $prior_end->format( 'Y-m-d' ) ];
	}

	/**
	 * Cached single-window computation, keyed by window + backend.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function compute_window_cached( string $start_date, string $end_date ): array {
		$use_ga4   = self::use_ga4();
		$cache_key = self::CACHE_KEY_PREFIX . md5( $start_date . '|' . $end_date . '|' . ( $use_ga4 ? 'ga4' : 'bq' ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		$payload = $use_ga4
			? self::compute_via_ga4( $start_date, $end_date )
			: self::compute_via_bq( $start_date, $end_date );
		set_transient( $cache_key, $payload, self::CACHE_TTL );
		return $payload;
	}

	/**
	 * GA4 path — composes every metric for the window.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function compute_via_ga4( string $start_date, string $end_date ): array {
		$pid = self::resolve_property_id();
		return [
			'window'                             => [
				'start' => $start_date,
				'end'   => $end_date,
			],
			// Reach.
			'active_readers'                     => self::active_readers_via_ga4( $pid, $start_date, $end_date ),
			'sessions'                           => self::sessions_via_ga4( $pid, $start_date, $end_date ),
			'pageviews'                          => self::pageviews_via_ga4( $pid, $start_date, $end_date ),
			'avg_sessions_per_reader'            => self::avg_sessions_per_reader_via_ga4( $pid, $start_date, $end_date ),
			'engaged_session_rate'               => self::engaged_session_rate_via_ga4( $pid, $start_date, $end_date ),
			// Time trends.
			'active_readers_over_time'           => self::active_readers_over_time_via_ga4( $pid, $start_date, $end_date ),
			'new_vs_returning_counts'            => self::new_vs_returning_counts_via_ga4( $pid, $start_date, $end_date ),
			'new_vs_returning_over_time'         => self::new_vs_returning_over_time_via_ga4( $pid, $start_date, $end_date ),
			'readership_by_day_of_week'          => self::readership_by_day_of_week_via_ga4( $pid, $start_date, $end_date ),
			'readership_by_hour_of_day'          => self::readership_by_hour_of_day_via_ga4( $pid, $start_date, $end_date ),
			// Traffic sources.
			'traffic_sources_breakdown'          => self::traffic_sources_breakdown_via_ga4( $pid, $start_date, $end_date ),
			'top_campaigns'                      => self::top_campaigns_via_ga4( $pid, $start_date, $end_date ),
			// Composition.
			'device_breakdown'                   => self::device_breakdown_via_ga4( $pid, $start_date, $end_date ),
			'newsletter_subscriber_rate'         => self::newsletter_subscriber_rate_via_ga4( $pid, $start_date, $end_date ),
			'newsletter_subscriber_composition'  => self::newsletter_subscriber_composition_via_ga4( $pid, $start_date, $end_date ),
			'logged_in_reader_rate'              => self::logged_in_reader_rate_via_ga4( $pid, $start_date, $end_date ),
			'logged_in_vs_anonymous_composition' => self::logged_in_vs_anonymous_composition_via_ga4( $pid, $start_date, $end_date ),
			// Geographic.
			'top_regions'                        => self::top_regions_via_ga4( $pid, $start_date, $end_date ),
			'top_cities'                         => self::top_cities_via_ga4( $pid, $start_date, $end_date ),
			'local_reader_rate'                  => self::local_reader_rate_via_ga4( $pid, $start_date, $end_date ),
			// Content performance.
			'top_pages'                          => self::top_pages_via_ga4( $pid, $start_date, $end_date ),
			'top_authors_by_reader_count'        => self::top_authors_by_reader_count_via_ga4( $pid, $start_date, $end_date ),
			// BQ-only (hidden in v1).
			'returning_reader_rate_strict'       => self::hidden_in_v1_payload(),
		];
	}

	/**
	 * BQ path — v1 stub. Every metric returns a not_implemented error with the
	 * same key set as the GA4 path; NPPD-1630 fills these in. BQ-only metrics
	 * stay hidden_in_v1 even here (their GA4 counterpart can never compute them).
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function compute_via_bq( string $start_date, string $end_date ): array {
		$keys = [
			'active_readers',
			'sessions',
			'pageviews',
			'avg_sessions_per_reader',
			'engaged_session_rate',
			'active_readers_over_time',
			'new_vs_returning_counts',
			'new_vs_returning_over_time',
			'readership_by_day_of_week',
			'readership_by_hour_of_day',
			'traffic_sources_breakdown',
			'top_campaigns',
			'device_breakdown',
			'newsletter_subscriber_rate',
			'newsletter_subscriber_composition',
			'logged_in_reader_rate',
			'logged_in_vs_anonymous_composition',
			'top_regions',
			'top_cities',
			'local_reader_rate',
			'top_pages',
			'top_authors_by_reader_count',
		];
		$payload = [
			'window' => [
				'start' => $start_date,
				'end'   => $end_date,
			],
		];
		foreach ( $keys as $key ) {
			$payload[ $key ] = self::not_implemented_payload();
		}
		$payload['returning_reader_rate_strict'] = self::hidden_in_v1_payload();
		return $payload;
	}

	/*
	===================================================================
	 * GA4-standard metrics
	 * ===================================================================
	 */

	/**
	 * Active Readers — totalUsers.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function active_readers_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [], [ 'totalUsers' ] ) );
		return self::scalar( $result, 'count' );
	}

	/**
	 * Sessions — sessions.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function sessions_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [], [ 'sessions' ] ) );
		return self::scalar( $result, 'count' );
	}

	/**
	 * Pageviews — screenPageViews (conventional Data API metric).
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function pageviews_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [], [ 'screenPageViews' ] ) );
		return self::scalar( $result, 'count' );
	}

	/**
	 * Avg Sessions per Reader — sessions / totalUsers (single report).
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function avg_sessions_per_reader_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [], [ 'sessions', 'totalUsers' ] ) );
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		$row      = $result['raw']['rows'][0] ?? null;
		$sessions = (int) ( $row['metricValues'][0]['value'] ?? 0 );
		$users    = (int) ( $row['metricValues'][1]['value'] ?? 0 );
		return [
			'value'       => $users > 0 ? $sessions / $users : 0,
			'computable'  => $users > 0,
			'type'        => 'decimal',
			'numerator'   => $sessions,
			'denominator' => $users,
		];
	}

	/**
	 * Engaged Session Rate — engagementRate (already a 0-1 ratio).
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function engaged_session_rate_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [], [ 'engagementRate' ] ) );
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		$raw = $result['raw']['rows'][0]['metricValues'][0]['value'] ?? null;
		return [
			'value'      => null === $raw ? 0.0 : (float) $raw,
			'computable' => null !== $raw,
			'type'       => 'rate',
		];
	}

	/**
	 * Active Readers Over Time — date / totalUsers.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function active_readers_over_time_via_ga4( string $pid, string $s, string $e ): array {
		$body                = self::body( $s, $e, [ 'date' ], [ 'totalUsers' ] );
		$body['orderBys']    = [ [ 'dimension' => [ 'dimensionName' => 'date' ] ] ];
		$result              = self::safe_run_report( $pid, $body );
		return self::rows( $result, [ 'date' ], [ 'active_readers' ], 'timeseries' );
	}

	/**
	 * New vs Returning Counts — newVsReturning / totalUsers (pie).
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function new_vs_returning_counts_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [ 'newVsReturning' ], [ 'totalUsers' ] ) );
		return self::rows( $result, [ 'reader_type' ], [ 'readers' ], 'breakdown' );
	}

	/**
	 * New vs Returning Over Time — date + newVsReturning / totalUsers.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function new_vs_returning_over_time_via_ga4( string $pid, string $s, string $e ): array {
		$body             = self::body( $s, $e, [ 'date', 'newVsReturning' ], [ 'totalUsers' ] );
		$body['orderBys'] = [ [ 'dimension' => [ 'dimensionName' => 'date' ] ] ];
		$result           = self::safe_run_report( $pid, $body );
		return self::rows( $result, [ 'date', 'reader_type' ], [ 'readers' ], 'timeseries' );
	}

	/**
	 * Readership by Day of Week — dayOfWeekName / totalUsers.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function readership_by_day_of_week_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [ 'dayOfWeekName' ], [ 'totalUsers' ] ) );
		return self::rows( $result, [ 'day_of_week' ], [ 'active_readers' ], 'breakdown' );
	}

	/**
	 * Readership by Hour of Day — hour / totalUsers.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function readership_by_hour_of_day_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [ 'hour' ], [ 'totalUsers' ] ) );
		return self::rows( $result, [ 'hour' ], [ 'active_readers' ], 'breakdown' );
	}

	/**
	 * Traffic Sources Breakdown — sessionDefaultChannelGroup / totalUsers.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function traffic_sources_breakdown_via_ga4( string $pid, string $s, string $e ): array {
		$body   = self::body( $s, $e, [ 'sessionDefaultChannelGroup' ], [ 'totalUsers' ] );
		$body  += self::order_by_metric_desc( 'totalUsers' );
		$body['limit'] = 20;
		$result = self::safe_run_report( $pid, $body );
		return self::rows( $result, [ 'channel' ], [ 'readers' ], 'breakdown' );
	}

	/**
	 * Top Campaigns — sessionSource/Medium/CampaignName / totalUsers + sessions.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function top_campaigns_via_ga4( string $pid, string $s, string $e ): array {
		$body          = self::body( $s, $e, [ 'sessionSource', 'sessionMedium', 'sessionCampaignName' ], [ 'totalUsers', 'sessions' ] );
		$body         += self::order_by_metric_desc( 'totalUsers' );
		$body['limit'] = 50;
		$result        = self::safe_run_report( $pid, $body );
		return self::rows( $result, [ 'source', 'medium', 'campaign' ], [ 'readers', 'sessions' ], 'table' );
	}

	/**
	 * Device Breakdown — deviceCategory / totalUsers.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function device_breakdown_via_ga4( string $pid, string $s, string $e ): array {
		$body   = self::body( $s, $e, [ 'deviceCategory' ], [ 'totalUsers' ] );
		$body  += self::order_by_metric_desc( 'totalUsers' );
		$result = self::safe_run_report( $pid, $body );
		return self::rows( $result, [ 'device' ], [ 'readers' ], 'breakdown' );
	}

	/**
	 * Top Regions/States — country, region / totalUsers.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function top_regions_via_ga4( string $pid, string $s, string $e ): array {
		$body          = self::body( $s, $e, [ 'country', 'region' ], [ 'totalUsers' ] );
		$body         += self::order_by_metric_desc( 'totalUsers' );
		$body['limit'] = 50;
		$result        = self::safe_run_report( $pid, $body );
		return self::rows( $result, [ 'country', 'region' ], [ 'readers' ], 'table' );
	}

	/**
	 * Top Cities — country, region, city / totalUsers.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function top_cities_via_ga4( string $pid, string $s, string $e ): array {
		$body          = self::body( $s, $e, [ 'country', 'region', 'city' ], [ 'totalUsers' ] );
		$body         += self::order_by_metric_desc( 'totalUsers' );
		$body['limit'] = 50;
		$result        = self::safe_run_report( $pid, $body );
		return self::rows( $result, [ 'country', 'region', 'city' ], [ 'readers' ], 'table' );
	}

	/*
	===================================================================
	 * GA4-conditional metrics (custom dimensions)
	 * ===================================================================
	 */

	/**
	 * Newsletter Subscriber Rate — customEvent:is_newsletter_subscriber.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function newsletter_subscriber_rate_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [ 'customEvent:is_newsletter_subscriber' ], [ 'totalUsers' ] ) );
		return self::yes_rate( $result );
	}

	/**
	 * Newsletter Subscriber Composition — same query, two-slice pie.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function newsletter_subscriber_composition_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [ 'customEvent:is_newsletter_subscriber' ], [ 'totalUsers' ] ) );
		return self::yes_composition( $result, __( 'Newsletter subscriber', 'newspack-plugin' ), __( 'Not subscribed', 'newspack-plugin' ) );
	}

	/**
	 * Logged-In Reader Rate — customEvent:logged_in.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function logged_in_reader_rate_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [ 'customEvent:logged_in' ], [ 'totalUsers' ] ) );
		return self::yes_rate( $result );
	}

	/**
	 * Logged-In vs Anonymous Composition — same query, two-slice pie.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function logged_in_vs_anonymous_composition_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [ 'customEvent:logged_in' ], [ 'totalUsers' ] ) );
		return self::yes_composition( $result, __( 'Logged in', 'newspack-plugin' ), __( 'Anonymous', 'newspack-plugin' ) );
	}

	/**
	 * Top Pages — customEvent:post_id (degrade to all URLs if missing). Ranked by
	 * unique readers; returns both reader and pageview counts.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function top_pages_via_ga4( string $pid, string $s, string $e ): array {
		$body                    = self::body( $s, $e, [ 'customEvent:post_id', 'pagePath', 'pageTitle' ], [ 'totalUsers', 'screenPageViews' ] );
		$body['dimensionFilter'] = self::custom_event_present_filter( 'post_id' );
		$body                   += self::order_by_metric_desc( 'totalUsers' );
		$body['limit']           = 50;
		$result                  = self::safe_run_report( $pid, $body );

		if ( self::is_custom_dimension_missing( $result ) ) {
			$degraded          = self::body( $s, $e, [ 'pagePath', 'pageTitle' ], [ 'totalUsers', 'screenPageViews' ] );
			$degraded         += self::order_by_metric_desc( 'totalUsers' );
			$degraded['limit'] = 50;
			$out               = self::rows( self::safe_run_report( $pid, $degraded ), [ 'page_path', 'page_title' ], [ 'unique_readers', 'pageviews' ], 'table' );
			return self::mark_degraded( $out, __( 'Singular content filter unavailable; showing all URLs.', 'newspack-plugin' ) );
		}
		return self::rows( $result, [ 'post_id', 'page_path', 'page_title' ], [ 'unique_readers', 'pageviews' ], 'table' );
	}

	/**
	 * Top Authors by Reader Count — customEvent:author + post_id (overlay if missing).
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function top_authors_by_reader_count_via_ga4( string $pid, string $s, string $e ): array {
		$body                    = self::body( $s, $e, [ 'customEvent:author' ], [ 'totalUsers', 'screenPageViews' ] );
		$body['dimensionFilter'] = [
			'andGroup' => [
				'expressions' => [
					self::custom_event_present_expression( 'post_id' ),
					self::custom_event_present_expression( 'author' ),
				],
			],
		];
		$body         += self::order_by_metric_desc( 'totalUsers' );
		$body['limit'] = 25;
		$result        = self::safe_run_report( $pid, $body );
		return self::rows( $result, [ 'author' ], [ 'unique_readers', 'pageviews' ], 'table' );
	}

	/**
	 * Local Reader Rate — geo report merged in PHP against configured tuples.
	 * Hidden (not_configured) when no coverage area is set.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function local_reader_rate_via_ga4( string $pid, string $s, string $e ): array {
		$filters = get_option( 'newspack_insights_local_geo_filters', [] );
		if ( ! is_array( $filters ) || empty( $filters ) ) {
			return [
				'value'          => null,
				'computable'     => false,
				'type'           => 'rate',
				'not_configured' => true,
			];
		}

		$body          = self::body( $s, $e, [ 'country', 'region', 'city', 'metro' ], [ 'totalUsers' ] );
		$body['limit'] = 100000;
		$result        = self::safe_run_report( $pid, $body );
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}

		// This rate is computed from the full geo row set in PHP, so a truncated
		// response would silently skew the numerator/denominator. GA4 reports the
		// total matching row count in `rowCount`; if it exceeds the rows actually
		// returned, bail with an explicit error rather than reporting a wrong rate.
		$returned_rows = count( $result['raw']['rows'] ?? [] );
		$row_count     = isset( $result['raw']['rowCount'] ) ? (int) $result['raw']['rowCount'] : $returned_rows;
		if ( $row_count > $returned_rows ) {
			return [
				'value'      => null,
				'computable' => false,
				'type'       => 'rate',
				'error'      => __( 'Local Reader Rate could not be computed: the geo breakdown exceeded the row limit and was truncated.', 'newspack-plugin' ),
			];
		}

		$total = 0;
		$local = 0;
		foreach ( $result['raw']['rows'] ?? [] as $row ) {
			$geo   = [
				'country' => $row['dimensionValues'][0]['value'] ?? '',
				'region'  => $row['dimensionValues'][1]['value'] ?? '',
				'city'    => $row['dimensionValues'][2]['value'] ?? '',
				'metro'   => $row['dimensionValues'][3]['value'] ?? '',
			];
			$users = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			$total += $users;
			if ( self::geo_matches_any( $geo, $filters ) ) {
				$local += $users;
			}
		}
		return [
			'value'       => $total > 0 ? $local / $total : 0,
			'computable'  => $total > 0,
			'type'        => 'rate',
			'numerator'   => $local,
			'denominator' => $total,
		];
	}

	/**
	 * Whether a geo row matches any configured filter tuple. Each tuple's
	 * present (non-empty) fields must all equal the row's values.
	 *
	 * @param array $geo     Row geo [country, region, city, metro].
	 * @param array $filters Array of partial geo tuples.
	 * @return bool
	 */
	private static function geo_matches_any( array $geo, array $filters ): bool {
		foreach ( $filters as $tuple ) {
			if ( ! is_array( $tuple ) ) {
				continue;
			}
			$match = true;
			foreach ( [ 'country', 'region', 'city', 'metro' ] as $field ) {
				if ( ! empty( $tuple[ $field ] ) && $tuple[ $field ] !== ( $geo[ $field ] ?? '' ) ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				return true;
			}
		}
		return false;
	}

	/*
	===================================================================
	 * Shared helpers
	 * ===================================================================
	 */

	/**
	 * Build a base runReport body.
	 *
	 * @param string   $s        Start date YYYY-MM-DD.
	 * @param string   $e        End date YYYY-MM-DD.
	 * @param string[] $dims     Dimension names.
	 * @param string[] $metrics  Metric names.
	 * @return array
	 */
	private static function body( string $s, string $e, array $dims, array $metrics ): array {
		$body = [
			'dateRanges' => [
				[
					'startDate' => $s,
					'endDate'   => $e,
				],
			],
			'metrics'    => array_map(
				function ( $m ) {
					return [ 'name' => $m ];
				},
				$metrics
			),
		];
		if ( ! empty( $dims ) ) {
			$body['dimensions'] = array_map(
				function ( $d ) {
					return [ 'name' => $d ];
				},
				$dims
			);
		}
		return $body;
	}

	/**
	 * Build an orderBys fragment: a single metric, descending.
	 *
	 * @param string $metric Metric name.
	 * @return array
	 */
	private static function order_by_metric_desc( string $metric ): array {
		return [
			'orderBys' => [
				[
					'metric' => [ 'metricName' => $metric ],
					'desc'   => true,
				],
			],
		];
	}

	/**
	 * Build a dimensionFilter asserting a customEvent dimension is present.
	 *
	 * @param string $param Event parameter name.
	 * @return array
	 */
	private static function custom_event_present_filter( string $param ): array {
		return [ 'filter' => self::custom_event_present_expression( $param )['filter'] ];
	}

	/**
	 * A single FilterExpression asserting a customEvent dimension is present.
	 *
	 * @param string $param Event parameter name.
	 * @return array
	 */
	private static function custom_event_present_expression( string $param ): array {
		return [
			'filter' => [
				'fieldName'    => 'customEvent:' . $param,
				'stringFilter' => [
					'matchType' => 'FULL_REGEXP',
					'value'     => '.+',
				],
			],
		];
	}

	/**
	 * Run a report, normalizing WP_Error into payload-shaped failures.
	 *
	 * @param string $property_id Property ID.
	 * @param array  $body        runReport body.
	 * @return array { raw } on success, or { error } / { overlay } on failure.
	 */
	private static function safe_run_report( string $property_id, array $body ): array {
		$result = Client::run_report( $property_id, $body );

		if ( is_wp_error( $result ) ) {
			if ( 'custom_dimension_missing' === $result->get_error_code() ) {
				$data = $result->get_error_data();
				return [
					'value'      => null,
					'computable' => false,
					'overlay'    => [
						'type'       => 'custom_dimension_missing',
						'dimensions' => is_array( $data ) && isset( $data['dimensions'] ) ? $data['dimensions'] : [],
					],
				];
			}
			return [
				'value'      => null,
				'computable' => false,
				'error'      => $result->get_error_message(),
			];
		}

		return [ 'raw' => $result ];
	}

	/**
	 * Whether a safe_run_report result is a custom_dimension_missing overlay.
	 *
	 * @param array $result safe_run_report result.
	 * @return bool
	 */
	private static function is_custom_dimension_missing( array $result ): bool {
		return isset( $result['overlay']['type'] ) && 'custom_dimension_missing' === $result['overlay']['type'];
	}

	/**
	 * Transform a single scalar metric value.
	 *
	 * @param array  $result safe_run_report result.
	 * @param string $type   'count' or 'decimal'.
	 * @return array
	 */
	private static function scalar( array $result, string $type ): array {
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		$raw   = $result['raw']['rows'][0]['metricValues'][0]['value'] ?? null;
		$value = null === $raw
			? ( 'decimal' === $type ? 0.0 : 0 )
			: ( 'decimal' === $type ? (float) $raw : (int) $raw );
		return [
			'value'      => $value,
			'computable' => true,
			'type'       => $type,
		];
	}

	/**
	 * Transform report rows into a list of associative rows.
	 *
	 * @param array    $result      safe_run_report result.
	 * @param string[] $dim_keys    Output keys for each dimension (in order).
	 * @param string[] $metric_keys Output keys for each metric (in order).
	 * @param string   $type        Payload type token.
	 * @return array
	 */
	private static function rows( array $result, array $dim_keys, array $metric_keys, string $type ): array {
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		$out = [];
		foreach ( $result['raw']['rows'] ?? [] as $row ) {
			$entry = [];
			foreach ( $dim_keys as $i => $key ) {
				$entry[ $key ] = $row['dimensionValues'][ $i ]['value'] ?? null;
			}
			foreach ( $metric_keys as $i => $key ) {
				$raw           = $row['metricValues'][ $i ]['value'] ?? null;
				$entry[ $key ] = null === $raw ? null : ( str_contains( (string) $raw, '.' ) ? (float) $raw : (int) $raw );
			}
			$out[] = $entry;
		}
		return [
			'rows'       => $out,
			'computable' => true,
			'type'       => $type,
		];
	}

	/**
	 * Rate from a yes/no custom-dimension split (numerator = 'yes').
	 *
	 * @param array $result safe_run_report result.
	 * @return array
	 */
	private static function yes_rate( array $result ): array {
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		$num   = 0;
		$total = 0;
		foreach ( $result['raw']['rows'] ?? [] as $row ) {
			$dim   = $row['dimensionValues'][0]['value'] ?? '';
			$users = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			$total += $users;
			if ( 'yes' === $dim ) {
				$num += $users;
			}
		}
		return [
			'value'       => $total > 0 ? $num / $total : 0,
			'computable'  => $total > 0,
			'type'        => 'rate',
			'numerator'   => $num,
			'denominator' => $total,
		];
	}

	/**
	 * Two-slice composition (yes vs everything else) for a pie.
	 *
	 * @param array  $result    safe_run_report result.
	 * @param string $yes_label Label for the 'yes' slice.
	 * @param string $no_label  Label for the remainder.
	 * @return array
	 */
	private static function yes_composition( array $result, string $yes_label, string $no_label ): array {
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		$yes = 0;
		$no  = 0;
		foreach ( $result['raw']['rows'] ?? [] as $row ) {
			$dim   = $row['dimensionValues'][0]['value'] ?? '';
			$users = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			if ( 'yes' === $dim ) {
				$yes += $users;
			} else {
				$no += $users;
			}
		}
		return [
			'rows'       => [
				[
					'label' => $yes_label,
					'value' => $yes,
				],
				[
					'label' => $no_label,
					'value' => $no,
				],
			],
			'computable' => ( $yes + $no ) > 0,
			'type'       => 'breakdown',
		];
	}

	/**
	 * Attach a degraded-state overlay to an otherwise-successful rows payload.
	 *
	 * @param array  $payload rows() output.
	 * @param string $message Overlay message.
	 * @return array
	 */
	private static function mark_degraded( array $payload, string $message ): array {
		if ( isset( $payload['error'] ) || isset( $payload['overlay'] ) ) {
			return $payload;
		}
		$payload['degraded'] = true;
		$payload['overlay']  = [
			'type'    => 'degraded',
			'message' => $message,
		];
		return $payload;
	}

	/**
	 * Standard payload for BQ-only metrics: hidden in v1 (UI skips rendering).
	 *
	 * @return array
	 */
	private static function hidden_in_v1_payload(): array {
		return [
			'value'        => null,
			'computable'   => false,
			'hidden_in_v1' => true,
		];
	}

	/**
	 * Standard payload for the v1 BQ stub path.
	 *
	 * @return array
	 */
	private static function not_implemented_payload(): array {
		return [
			'value'      => null,
			'computable' => false,
			'error'      => __( 'BQ path not yet implemented. See NPPD-1630.', 'newspack-plugin' ),
		];
	}
}
