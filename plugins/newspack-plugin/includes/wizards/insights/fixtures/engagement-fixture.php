<?php
/**
 * Newspack Insights — Engagement (Tab 2) fixture payload (NPPD-1649).
 *
 * Realistic mock data for UI smoke testing without a GA4 connection,
 * served by Engagement_REST_Controller when NEWSPACK_INSIGHTS_FIXTURE_MODE
 * is truthy. Returns the same { current, previous } shape the live
 * controller assembles, computed date-relative so it never goes stale.
 *
 * Fully populated — no overlay/error states — so fixture mode renders the whole
 * tab cleanly. (Overlay/error render paths are covered by component unit tests.)
 *   - realistic values across scorecards and tables
 *   - hidden_in_v1 (skip-renders): the four BQ-only metrics
 *   - comparison deltas in both directions
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

$now   = function_exists( 'current_datetime' ) ? current_datetime() : new DateTimeImmutable();
$end   = $now;
$start = $now->modify( '-29 days' );

$scalar = function ( $value, $type ) {
	return [
		'value'      => $value,
		'computable' => true,
		'type'       => $type,
	];
};

$rate = function ( $numerator, $denominator ) {
	return [
		'value'       => $denominator > 0 ? $numerator / $denominator : 0,
		'computable'  => $denominator > 0,
		'type'        => 'rate',
		'numerator'   => $numerator,
		'denominator' => $denominator,
	];
};

$table = function ( array $rows ) {
	return [
		'rows'       => $rows,
		'computable' => true,
		'type'       => 'table',
	];
};

$hidden = [
	'value'        => null,
	'computable'   => false,
	'hidden_in_v1' => true,
];

$build = function ( float $f ) use ( $scalar, $rate, $table, $hidden, $start, $end ) {
	return [
		'window'                                => [
			'start' => $start->format( 'Y-m-d' ),
			'end'   => $end->format( 'Y-m-d' ),
		],

		// Overall engagement quality.
		'avg_pages_per_session'                 => $scalar( round( 2.34 * $f, 2 ), 'decimal' ),
		'avg_engaged_session_duration'          => $scalar( round( 142 * $f ), 'duration' ),
		'bounce_rate'                           => $rate( (int) round( 95600 * ( 2 - $f ) ), 281200 ),
		'article_completion_rate'               => $rate( (int) round( 71400 * $f ), 168000 ),

		// Content engagement.
		'most_read_articles'                    => $table(
			[
				[
					'post_id'                => '40122',
					'page_path'              => '/2026/05/city-budget-vote',
					'page_title'             => 'City council passes contested budget',
					'unique_readers'         => (int) round( 21900 * $f ),
					'avg_engagement_seconds' => round( 188 * $f ),
					'engagement_score'       => round( 41200 * $f ),
				],
				[
					'post_id'                => '39880',
					'page_path'              => '/2026/05/school-closures',
					'page_title'             => 'District announces three school closures',
					'unique_readers'         => (int) round( 16400 * $f ),
					'avg_engagement_seconds' => round( 162 * $f ),
					'engagement_score'       => round( 28900 * $f ),
				],
				[
					'post_id'                => '40310',
					'page_path'              => '/2026/06/heat-wave-guide',
					'page_title'             => 'Your guide to surviving the heat wave',
					'unique_readers'         => (int) round( 11200 * $f ),
					'avg_engagement_seconds' => round( 205 * $f ),
					'engagement_score'       => round( 24100 * $f ),
				],
			]
		),
		'articles_by_completion_rate'           => $table(
			[
				[
					'post_id'         => '40310',
					'page_path'       => '/2026/06/heat-wave-guide',
					'page_title'      => 'Your guide to surviving the heat wave',
					'readers'         => (int) round( 11200 * $f ),
					'completion_rate' => round( 0.62 * $f, 2 ),
				],
				[
					'post_id'         => '40122',
					'page_path'       => '/2026/05/city-budget-vote',
					'page_title'      => 'City council passes contested budget',
					'readers'         => (int) round( 21900 * $f ),
					'completion_rate' => round( 0.54 * $f, 2 ),
				],
				[
					'post_id'         => '39501',
					'page_path'       => '/2026/05/transit-expansion',
					'page_title'      => 'Transit expansion clears final hurdle',
					'readers'         => (int) round( 8100 * $f ),
					'completion_rate' => round( 0.47 * $f, 2 ),
				],
			]
		),
		'top_authors_by_avg_engagement_time'    => $table(
			[
				[
					'author'                 => 'Priya Nair',
					'unique_readers'         => (int) round( 12400 * $f ),
					'avg_engagement_seconds' => round( 246 * $f ),
				],
				[
					'author'                 => 'Maria Alvarez',
					'unique_readers'         => (int) round( 18900 * $f ),
					'avg_engagement_seconds' => round( 221 * $f ),
				],
				[
					'author'                 => 'Daniel Cho',
					'unique_readers'         => (int) round( 9700 * $f ),
					'avg_engagement_seconds' => round( 203 * $f ),
				],
				[
					'author'                 => 'James Okafor',
					'unique_readers'         => (int) round( 15200 * $f ),
					'avg_engagement_seconds' => round( 188 * $f ),
				],
				[
					'author'                 => 'Aisha Bello',
					'unique_readers'         => (int) round( 8300 * $f ),
					'avg_engagement_seconds' => round( 171 * $f ),
				],
			]
		),

		// Reader segments.
		'engagement_by_device_type'             => $table(
			[
				[
					'device'                 => 'mobile',
					'sessions'               => (int) round( 178000 * $f ),
					'avg_engagement_seconds' => round( 118 * $f ),
					'avg_pages_per_session'  => round( 2.1 * $f, 2 ),
				],
				[
					'device'                 => 'desktop',
					'sessions'               => (int) round( 84000 * $f ),
					'avg_engagement_seconds' => round( 196 * $f ),
					'avg_pages_per_session'  => round( 3.0 * $f, 2 ),
				],
				[
					'device'                 => 'tablet',
					'sessions'               => (int) round( 19200 * $f ),
					'avg_engagement_seconds' => round( 174 * $f ),
					'avg_pages_per_session'  => round( 2.6 * $f, 2 ),
				],
			]
		),
		'engagement_by_newsletter_status'       => $table(
			[
				[
					'segment'                => 'Subscriber',
					'sessions'               => (int) round( 61200 * $f ),
					'avg_pages_per_session'  => round( 3.4 * $f, 2 ),
					'avg_engagement_seconds' => round( 224 * $f ),
				],
				[
					'segment'                => 'Non-subscriber',
					'sessions'               => (int) round( 219800 * $f ),
					'avg_pages_per_session'  => round( 2.0 * $f, 2 ),
					'avg_engagement_seconds' => round( 121 * $f ),
				],
			]
		),
		'engagement_by_returning_vs_new'        => $table(
			[
				[
					'reader_type'            => 'new',
					'sessions'               => (int) round( 173000 * $f ),
					'avg_pages_per_session'  => round( 1.9 * $f, 2 ),
					'avg_engagement_seconds' => round( 96 * $f ),
				],
				[
					'reader_type'            => 'returning',
					'sessions'               => (int) round( 108200 * $f ),
					'avg_pages_per_session'  => round( 3.1 * $f, 2 ),
					'avg_engagement_seconds' => round( 211 * $f ),
				],
			]
		),

		// BQ-only — hidden in v1.
		'top_categories_by_engagement'          => $hidden,
		'mobile_vs_desktop_content_preferences' => $hidden,
		'top_authors_by_repeat_reader_rate'     => $hidden,
		'article_freshness_vs_engagement'       => $hidden,
	];
};

return [
	'current'  => $build( 1.0 ),
	'previous' => $build( 0.92 ),
];
