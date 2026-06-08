<?php
/**
 * Newspack Insights — Audience (Tab 1) fixture payload (NPPD-1649).
 *
 * Realistic mock data for UI smoke testing without a GA4 connection,
 * served by Audience_REST_Controller when NEWSPACK_INSIGHTS_FIXTURE_MODE
 * is truthy. Returns the same { current, previous } shape the live
 * controller assembles. Values are computed date-relative so the fixture
 * never goes stale.
 *
 * Deliberately exercises every render path:
 *   - realistic, varied values across scorecards, tables, charts, pies
 *   - custom_dimension_missing overlay: newsletter_subscriber_rate
 *   - generic error: local_reader_rate
 *   - hidden_in_v1: returning_reader_rate_strict
 *   - comparison deltas in both directions (previous window differs)
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

$breakdown = function ( array $rows ) {
	return [
		'rows'       => $rows,
		'computable' => true,
		'type'       => 'breakdown',
	];
};

$table = function ( array $rows ) {
	return [
		'rows'       => $rows,
		'computable' => true,
		'type'       => 'table',
	];
};

// Daily time series of $count points ending today, varying around $base.
$series = function ( int $count, int $base, float $jitter ) use ( $end ) {
	$rows = [];
	for ( $i = $count - 1; $i >= 0; $i-- ) {
		$day = $end->modify( "-$i days" );
		// Deterministic pseudo-variation: weekday dip + slow wave, no RNG.
		$dow    = (int) $day->format( 'N' );
		$weekend = ( $dow >= 6 ) ? 0.7 : 1.0;
		$wave    = 1 + ( $jitter * sin( $i / 4.0 ) );
		$rows[]  = [
			'date'           => $day->format( 'Ymd' ),
			'active_readers' => (int) round( $base * $weekend * $wave ),
		];
	}
	return $rows;
};

$build = function ( float $f ) use ( $scalar, $rate, $breakdown, $table, $series, $start, $end ) {
	return [
		'window'                             => [
			'start' => $start->format( 'Y-m-d' ),
			'end'   => $end->format( 'Y-m-d' ),
		],

		// Reach.
		'active_readers'                     => $scalar( (int) round( 128430 * $f ), 'count' ),
		'sessions'                           => $scalar( (int) round( 281200 * $f ), 'count' ),
		'pageviews'                          => $scalar( (int) round( 612900 * $f ), 'count' ),
		'avg_sessions_per_reader'            => $scalar( round( 2.19 * $f, 2 ), 'decimal' ),
		'engaged_session_rate'               => $rate( (int) round( 168720 * $f ), 281200 ),

		// Time trends.
		'active_readers_over_time'           => [
			'rows'       => $series( 30, (int) round( 4300 * $f ), 0.25 ),
			'computable' => true,
			'type'       => 'timeseries',
		],
		'new_vs_returning_counts'            => $breakdown(
			[
				[
					'reader_type' => 'new',
					'readers'     => (int) round( 79400 * $f ),
				],
				[
					'reader_type' => 'returning',
					'readers'     => (int) round( 49030 * $f ),
				],
			]
		),
		'new_vs_returning_over_time'         => [
			'rows'       => array_map(
				function ( $point ) {
					return [
						'date'    => $point['date'],
						'readers' => $point['active_readers'],
					];
				},
				$series( 30, (int) round( 4300 * $f ), 0.25 )
			),
			'computable' => true,
			'type'       => 'timeseries',
		],
		'readership_by_day_of_week'          => $breakdown(
			[
				[
					'day_of_week'    => 'Monday',
					'active_readers' => (int) round( 21300 * $f ),
				],
				[
					'day_of_week'    => 'Tuesday',
					'active_readers' => (int) round( 22800 * $f ),
				],
				[
					'day_of_week'    => 'Wednesday',
					'active_readers' => (int) round( 23100 * $f ),
				],
				[
					'day_of_week'    => 'Thursday',
					'active_readers' => (int) round( 21950 * $f ),
				],
				[
					'day_of_week'    => 'Friday',
					'active_readers' => (int) round( 18700 * $f ),
				],
				[
					'day_of_week'    => 'Saturday',
					'active_readers' => (int) round( 11200 * $f ),
				],
				[
					'day_of_week'    => 'Sunday',
					'active_readers' => (int) round( 12500 * $f ),
				],
			]
		),
		'readership_by_hour_of_day'          => $breakdown(
			array_map(
				function ( $h ) use ( $f ) {
					$shape = 0.4 + 0.6 * ( sin( ( $h - 6 ) / 24 * M_PI ) ** 2 );
					return [
						'hour'           => str_pad( (string) $h, 2, '0', STR_PAD_LEFT ),
						'active_readers' => (int) round( 5200 * $shape * $f ),
					];
				},
				range( 0, 23 )
			)
		),

		// Traffic sources.
		'traffic_sources_breakdown'          => $breakdown(
			[
				[
					'channel' => 'Organic Search',
					'readers' => (int) round( 51200 * $f ),
				],
				[
					'channel' => 'Direct',
					'readers' => (int) round( 33800 * $f ),
				],
				[
					'channel' => 'Social',
					'readers' => (int) round( 21400 * $f ),
				],
				[
					'channel' => 'Email',
					'readers' => (int) round( 12900 * $f ),
				],
				[
					'channel' => 'Referral',
					'readers' => (int) round( 6300 * $f ),
				],
				[
					'channel' => 'Paid Search',
					'readers' => (int) round( 2830 * $f ),
				],
			]
		),
		'top_campaigns'                      => $table(
			[
				[
					'source'   => 'newsletter',
					'medium'   => 'email',
					'campaign' => 'weekly-digest',
					'readers'  => (int) round( 8200 * $f ),
					'sessions' => (int) round( 11400 * $f ),
				],
				[
					'source'   => 'facebook',
					'medium'   => 'social',
					'campaign' => 'election-coverage',
					'readers'  => (int) round( 6100 * $f ),
					'sessions' => (int) round( 7300 * $f ),
				],
				[
					'source'   => 'google',
					'medium'   => 'cpc',
					'campaign' => 'membership-drive',
					'readers'  => (int) round( 3400 * $f ),
					'sessions' => (int) round( 3900 * $f ),
				],
				[
					'source'   => 'twitter',
					'medium'   => 'social',
					'campaign' => 'breaking-news',
					'readers'  => (int) round( 2900 * $f ),
					'sessions' => (int) round( 3300 * $f ),
				],
				[
					'source'   => 'partner-site',
					'medium'   => 'referral',
					'campaign' => '(no campaign)',
					'readers'  => (int) round( 1500 * $f ),
					'sessions' => (int) round( 1700 * $f ),
				],
			]
		),

		// Composition.
		'device_breakdown'                   => $breakdown(
			[
				[
					'device'  => 'mobile',
					'readers' => (int) round( 89400 * $f ),
				],
				[
					'device'  => 'desktop',
					'readers' => (int) round( 32100 * $f ),
				],
				[
					'device'  => 'tablet',
					'readers' => (int) round( 6930 * $f ),
				],
			]
		),
		// Overlay state: custom dimension not registered.
		'newsletter_subscriber_rate'         => [
			'value'      => null,
			'computable' => false,
			'overlay'    => [
				'type'       => 'custom_dimension_missing',
				'dimensions' => [ 'is_newsletter_subscriber' ],
			],
		],
		'newsletter_subscriber_composition'  => [
			'value'      => null,
			'computable' => false,
			'overlay'    => [
				'type'       => 'custom_dimension_missing',
				'dimensions' => [ 'is_newsletter_subscriber' ],
			],
		],
		'logged_in_reader_rate'              => $rate( (int) round( 38500 * $f ), 128430 ),
		'logged_in_vs_anonymous_composition' => $breakdown(
			[
				[
					'label' => 'Logged in',
					'value' => (int) round( 38500 * $f ),
				],
				[
					'label' => 'Anonymous',
					'value' => (int) round( 89930 * $f ),
				],
			]
		),

		// Geographic.
		'top_regions'                        => $table(
			[
				[
					'country' => 'United States',
					'region'  => 'Illinois',
					'readers' => (int) round( 41200 * $f ),
				],
				[
					'country' => 'United States',
					'region'  => 'California',
					'readers' => (int) round( 18700 * $f ),
				],
				[
					'country' => 'United States',
					'region'  => 'New York',
					'readers' => (int) round( 12300 * $f ),
				],
				[
					'country' => 'United States',
					'region'  => 'Texas',
					'readers' => (int) round( 9800 * $f ),
				],
				[
					'country' => 'United States',
					'region'  => 'Indiana',
					'readers' => (int) round( 7600 * $f ),
				],
			]
		),
		'top_cities'                         => $table(
			[
				[
					'country' => 'United States',
					'region'  => 'Illinois',
					'city'    => 'Chicago',
					'readers' => (int) round( 33400 * $f ),
				],
				[
					'country' => 'United States',
					'region'  => 'Illinois',
					'city'    => 'Evanston',
					'readers' => (int) round( 4100 * $f ),
				],
				[
					'country' => 'United States',
					'region'  => 'California',
					'city'    => 'Los Angeles',
					'readers' => (int) round( 6800 * $f ),
				],
				[
					'country' => 'United States',
					'region'  => 'New York',
					'city'    => 'New York',
					'readers' => (int) round( 9200 * $f ),
				],
				[
					'country' => 'United States',
					'region'  => 'Indiana',
					'city'    => 'Indianapolis',
					'readers' => (int) round( 3300 * $f ),
				],
			]
		),
		// Error state: coverage area not configured.
		'local_reader_rate'                  => [
			'value'      => null,
			'computable' => false,
			'type'       => 'rate',
			'error'      => 'Local Reader Rate is unavailable: no coverage area configured.',
		],

		// Content performance.
		'top_pages'                          => $table(
			[
				[
					'post_id'        => '40122',
					'page_path'      => '/2026/05/city-budget-vote',
					'page_title'     => 'City council passes contested budget',
					'unique_readers' => (int) round( 21900 * $f ),
					'pageviews'      => (int) round( 28400 * $f ),
				],
				[
					'post_id'        => '39880',
					'page_path'      => '/2026/05/school-closures',
					'page_title'     => 'District announces three school closures',
					'unique_readers' => (int) round( 16400 * $f ),
					'pageviews'      => (int) round( 19100 * $f ),
				],
				[
					'post_id'        => '40310',
					'page_path'      => '/2026/06/heat-wave-guide',
					'page_title'     => 'Your guide to surviving the heat wave',
					'unique_readers' => (int) round( 11200 * $f ),
					'pageviews'      => (int) round( 12700 * $f ),
				],
				[
					'post_id'        => '39501',
					'page_path'      => '/2026/05/transit-expansion',
					'page_title'     => 'Transit expansion clears final hurdle',
					'unique_readers' => (int) round( 8100 * $f ),
					'pageviews'      => (int) round( 9800 * $f ),
				],
			]
		),
		'top_authors_by_reader_count'        => $table(
			[
				[
					'author'         => 'Maria Alvarez',
					'unique_readers' => (int) round( 34200 * $f ),
					'pageviews'      => (int) round( 51200 * $f ),
				],
				[
					'author'         => 'James Okafor',
					'unique_readers' => (int) round( 28700 * $f ),
					'pageviews'      => (int) round( 39800 * $f ),
				],
				[
					'author'         => 'Priya Nair',
					'unique_readers' => (int) round( 19300 * $f ),
					'pageviews'      => (int) round( 24100 * $f ),
				],
			]
		),

		// BQ-only — hidden in v1 (UI skips rendering).
		'returning_reader_rate_strict'       => [
			'value'        => null,
			'computable'   => false,
			'hidden_in_v1' => true,
		],
	];
};

return [
	'current'  => $build( 1.0 ),
	'previous' => $build( 0.88 ),
];
