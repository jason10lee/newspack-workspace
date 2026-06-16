<?php
/**
 * Test Conversion_Metric (NPPD-1609, Phase 1 + Phase 2A).
 *
 * Phase 1 orchestrator returns placeholder envelopes only — no proxy, no
 * SQL. These tests pin the envelope shape every Phase 2 swap must preserve:
 * scalar scorecards carry `value` / `pending` / `computable` /
 * `placeholder_type`; the five funnels carry `pending` + ordered zeroed
 * stages (two of them visibility-gated); the three PieCharts carry zeroed
 * source slices; the weekly trends carry empty `weeks` + the series keys;
 * and the opportunity table carries empty `rows` + a threshold.
 *
 * Phase 2A (C2–C5): four methods are now wired to BigQuery via the proxy.
 * Their old `pending: true` placeholders are replaced by state-envelope
 * tests that cover populated / empty / error paths.
 *
 * Phase B deferred (C20–C24): the five Phase-B sections now return
 * `state: 'coming_soon'` with empty collections and preserved extra keys
 * (visibility/visibility_reason for 4.4; reference_line for 5.1/5.2).
 * Tests assert the `coming_soon` shape and absence of `pending`.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use DateTimeImmutable;
use DateTimeZone;
use Newspack\Insights\BigQuery_Proxy_Client;
use Newspack\Insights\Conversion_Metric;
use Newspack\Insights\Donors_Metric;
use Newspack\Insights\Subscribers_Metric;
use Newspack\Insights\Woo_Order_Resolver;
use WP_UnitTestCase;

/**
 * Conversion_Metric test class.
 *
 * @group insights
 */
class Test_Conversion_Metric extends WP_UnitTestCase {

	/**
	 * Subject under test.
	 *
	 * @var Conversion_Metric
	 */
	private $metric;

	/**
	 * Mock proxy stub that returns the given value for every query() call.
	 *
	 * @param mixed $return Value the mock proxy returns (rows array or WP_Error).
	 * @return BigQuery_Proxy_Client
	 */
	private function proxy_returning( $return ): BigQuery_Proxy_Client {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( $return );
		return $proxy;
	}

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->metric = new Conversion_Metric();
	}

	/**
	 * Make a UTC DateTimeImmutable from a YYYY-MM-DD string.
	 *
	 * @param string $ymd Date string.
	 * @return DateTimeImmutable
	 */
	private function make_date( string $ymd ): DateTimeImmutable {
		return new DateTimeImmutable( $ymd, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * The window start/end passed to every method under test.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 */
	private function window(): array {
		return [ $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) ];
	}

	// --- C1 scaffolding tests ------------------------------------------------

	/**
	 * Constructor accepts an injected proxy client (test seam).
	 */
	public function test_constructor_accepts_injected_proxy() {
		$proxy  = $this->createMock( BigQuery_Proxy_Client::class );
		$metric = new Conversion_Metric( $proxy );
		$this->assertInstanceOf( Conversion_Metric::class, $metric );
	}

	/**
	 * Constructor accepts an injected Woo resolver (test seam).
	 */
	public function test_constructor_accepts_injected_woo_resolver() {
		$proxy        = $this->createMock( BigQuery_Proxy_Client::class );
		$woo_resolver = $this->createMock( Woo_Order_Resolver::class );
		$metric       = new Conversion_Metric( $proxy, $woo_resolver );
		$this->assertInstanceOf( Conversion_Metric::class, $metric );
	}

	/**
	 * Injected proxy is stored and actually used when a wired method is called.
	 *
	 * Verifies via reflection that $proxy is stored in the private property,
	 * and via a mock expectation that its query() method is called when
	 * compute_metric_from_proxy is exercised indirectly through a future wired
	 * method. For Phase 1, we assert the proxy property is set correctly since
	 * no public method calls the proxy yet.
	 */
	public function test_injected_proxy_is_stored_on_private_property() {
		$proxy  = $this->createMock( BigQuery_Proxy_Client::class );
		$metric = new Conversion_Metric( $proxy );

		$reflection = new \ReflectionProperty( Conversion_Metric::class, 'proxy' );
		$this->assertSame( $proxy, $reflection->getValue( $metric ) );
	}

	/**
	 * Injected Woo resolver is stored on the private property.
	 */
	public function test_injected_woo_resolver_is_stored_on_private_property() {
		$proxy        = $this->createMock( BigQuery_Proxy_Client::class );
		$woo_resolver = $this->createMock( Woo_Order_Resolver::class );
		$metric       = new Conversion_Metric( $proxy, $woo_resolver );

		$reflection = new \ReflectionProperty( Conversion_Metric::class, 'woo_resolver' );
		$this->assertSame( $woo_resolver, $reflection->getValue( $metric ) );
	}

	/**
	 * Default constructor (no injected deps) creates a BigQuery_Proxy_Client
	 * and a Woo_Order_Resolver and stores them on the private properties.
	 */
	public function test_default_constructor_creates_default_deps() {
		$metric = new Conversion_Metric();

		$proxy_ref = new \ReflectionProperty( Conversion_Metric::class, 'proxy' );
		$this->assertInstanceOf( BigQuery_Proxy_Client::class, $proxy_ref->getValue( $metric ) );

		$woo_ref = new \ReflectionProperty( Conversion_Metric::class, 'woo_resolver' );
		$this->assertInstanceOf( Woo_Order_Resolver::class, $woo_ref->getValue( $metric ) );
	}

	// --- Existing Phase 1 placeholder tests ----------------------------------

	// --- C20: get_time_to_subscribe_distribution --------------------------

	/**
	 * C20 coming_soon: returns state 'coming_soon' with an empty 'groups'
	 * collection and no 'pending' key.
	 */
	public function test_time_to_subscribe_distribution_is_coming_soon() {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->get_time_to_subscribe_distribution( $start, $end );

		$this->assertSame( 'coming_soon', $result['state'] );
		$this->assertArrayHasKey( 'groups', $result );
		$this->assertSame( [], $result['groups'] );
		$this->assertArrayNotHasKey( 'pending', $result );
	}

	// --- C21: get_time_to_donate_distribution ------------------------------

	/**
	 * C21 coming_soon: returns state 'coming_soon' with an empty 'groups'
	 * collection and no 'pending' key.
	 */
	public function test_time_to_donate_distribution_is_coming_soon() {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->get_time_to_donate_distribution( $start, $end );

		$this->assertSame( 'coming_soon', $result['state'] );
		$this->assertArrayHasKey( 'groups', $result );
		$this->assertSame( [], $result['groups'] );
		$this->assertArrayNotHasKey( 'pending', $result );
	}

	// --- C22: get_subscriber_to_donor_lag_distribution ---------------------

	/**
	 * C22 coming_soon: returns state 'coming_soon' with an empty 'points'
	 * collection, preserved visibility keys, and no 'pending' key.
	 */
	public function test_lag_distribution_is_coming_soon_with_visibility_keys() {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->get_subscriber_to_donor_lag_distribution( $start, $end );

		$this->assertSame( 'coming_soon', $result['state'] );
		$this->assertArrayHasKey( 'points', $result );
		$this->assertSame( [], $result['points'] );
		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertSame( 'insufficient_data', $result['visibility_reason'] );
		$this->assertArrayNotHasKey( 'pending', $result );
	}

	// --- C23: get_registration_to_conversion_cohort ------------------------

	/**
	 * C23 coming_soon: returns state 'coming_soon' with an empty 'cohorts'
	 * collection, preserved reference_line (0.15), and no 'pending' key.
	 */
	public function test_registration_to_conversion_cohort_is_coming_soon() {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->get_registration_to_conversion_cohort( $start, $end );

		$this->assertSame( 'coming_soon', $result['state'] );
		$this->assertArrayHasKey( 'cohorts', $result );
		$this->assertSame( [], $result['cohorts'] );
		$this->assertArrayHasKey( 'reference_line', $result );
		$this->assertSame( 0.15, $result['reference_line']['value'] );
		$this->assertNotSame( '', $result['reference_line']['label'] );
		$this->assertArrayNotHasKey( 'pending', $result );
	}

	// --- C24: get_subscriber_retention_cohort ------------------------------

	/**
	 * C24 coming_soon: returns state 'coming_soon' with an empty 'cohorts'
	 * collection, preserved reference_line (0.70), and no 'pending' key.
	 */
	public function test_subscriber_retention_cohort_is_coming_soon() {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->get_subscriber_retention_cohort( $start, $end );

		$this->assertSame( 'coming_soon', $result['state'] );
		$this->assertArrayHasKey( 'cohorts', $result );
		$this->assertSame( [], $result['cohorts'] );
		$this->assertArrayHasKey( 'reference_line', $result );
		$this->assertSame( 0.70, $result['reference_line']['value'] );
		$this->assertNotSame( '', $result['reference_line']['label'] );
		$this->assertArrayNotHasKey( 'pending', $result );
	}

	// C6 weekly trends — see test_weekly_conversion_rates_* tests below.

	// C9 top-pages table — see test_top_pages_no_conversion_* tests below.

	// --- Phase 2A wired method tests (C2–C5) --------------------------------

	// --- C2: get_reader_lifecycle_funnel ------------------------------------

	/**
	 * C2 populated: proxy returns one row with five step counts → builds five
	 * stages with correct labels, counts, and pct_of_top proportions.
	 */
	public function test_lifecycle_funnel_returns_populated_stages_on_success() {
		$row    = [
			'step_1_anonymous'  => 1000,
			'step_2_engaged'    => 600,
			'step_3_registered' => 300,
			'step_4_subscriber' => 120,
			'step_5_supporter'  => 60,
		];
		$metric = new Conversion_Metric( $this->proxy_returning( [ $row ] ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_reader_lifecycle_funnel( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertCount( 5, $result['stages'] );

		// Stage 1: top of funnel → pct_of_top must be 1.0.
		$this->assertSame( 1000, $result['stages'][0]['count'] );
		$this->assertSame( 1.0, $result['stages'][0]['pct_of_top'] );

		// Stage 5: 60 / 1000 = 0.06.
		$this->assertSame( 60, $result['stages'][4]['count'] );
		$this->assertEqualsWithDelta( 0.06, $result['stages'][4]['pct_of_top'], 1e-9 );

		// Every stage must have a non-empty label.
		foreach ( $result['stages'] as $stage ) {
			$this->assertNotSame( '', $stage['label'] );
		}
	}

	/**
	 * C2 populated: zero anonymous readers (division-by-zero guard) → pct_of_top
	 * for all stages is 0.0, not NaN/Inf.
	 */
	public function test_lifecycle_funnel_guards_zero_top_stage() {
		$row    = [
			'step_1_anonymous'  => 0,
			'step_2_engaged'    => 0,
			'step_3_registered' => 0,
			'step_4_subscriber' => 0,
			'step_5_supporter'  => 0,
		];
		$metric = new Conversion_Metric( $this->proxy_returning( [ $row ] ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_reader_lifecycle_funnel( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		foreach ( $result['stages'] as $stage ) {
			$this->assertSame( 0.0, $stage['pct_of_top'] );
		}
	}

	/**
	 * C2 empty: proxy returns [] → state 'empty', empty stages array.
	 */
	public function test_lifecycle_funnel_returns_empty_state_on_no_rows() {
		$metric          = new Conversion_Metric( $this->proxy_returning( [] ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_reader_lifecycle_funnel( $start, $end );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( [], $result['stages'] );
	}

	/**
	 * C2 error: proxy returns WP_Error → state 'error' with code/message.
	 */
	public function test_lifecycle_funnel_returns_error_state_on_proxy_error() {
		$wp_error        = new \WP_Error( 'bigquery_proxy_http_error', 'HTTP 500' );
		$metric          = new Conversion_Metric( $this->proxy_returning( $wp_error ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_reader_lifecycle_funnel( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'HTTP 500', $result['error_message'] );
		$this->assertSame( [], $result['stages'] );
	}

	// --- C3: get_anonymous_to_registered_funnel ----------------------------

	/**
	 * C3 populated: proxy returns one row with three step counts → three stages.
	 */
	public function test_anon_to_registered_funnel_returns_populated_stages_on_success() {
		$row    = [
			'step_1_anonymous'              => 500,
			'step_2_saw_conversion_surface' => 200,
			'step_3_registered'             => 80,
		];
		$metric = new Conversion_Metric( $this->proxy_returning( [ $row ] ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_anonymous_to_registered_funnel( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertCount( 3, $result['stages'] );

		// Stage 1: top → pct_of_top must be 1.0.
		$this->assertSame( 500, $result['stages'][0]['count'] );
		$this->assertSame( 1.0, $result['stages'][0]['pct_of_top'] );

		// Stage 3: 80 / 500 = 0.16.
		$this->assertSame( 80, $result['stages'][2]['count'] );
		$this->assertEqualsWithDelta( 0.16, $result['stages'][2]['pct_of_top'], 1e-9 );

		// Every stage must have a non-empty label.
		foreach ( $result['stages'] as $stage ) {
			$this->assertNotSame( '', $stage['label'] );
		}
	}

	/**
	 * C3 populated: zero anonymous (division-by-zero guard).
	 */
	public function test_anon_to_registered_funnel_guards_zero_top_stage() {
		$row    = [
			'step_1_anonymous'              => 0,
			'step_2_saw_conversion_surface' => 0,
			'step_3_registered'             => 0,
		];
		$metric = new Conversion_Metric( $this->proxy_returning( [ $row ] ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_anonymous_to_registered_funnel( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		foreach ( $result['stages'] as $stage ) {
			$this->assertSame( 0.0, $stage['pct_of_top'] );
		}
	}

	/**
	 * C3 empty: proxy returns [] → state 'empty', empty stages array.
	 */
	public function test_anon_to_registered_funnel_returns_empty_state_on_no_rows() {
		$metric          = new Conversion_Metric( $this->proxy_returning( [] ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_anonymous_to_registered_funnel( $start, $end );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( [], $result['stages'] );
	}

	/**
	 * C3 error: proxy returns WP_Error → state 'error' with code/message.
	 */
	public function test_anon_to_registered_funnel_returns_error_state_on_proxy_error() {
		$wp_error        = new \WP_Error( 'bigquery_proxy_http_error', 'HTTP 503' );
		$metric          = new Conversion_Metric( $this->proxy_returning( $wp_error ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_anonymous_to_registered_funnel( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'HTTP 503', $result['error_message'] );
		$this->assertSame( [], $result['stages'] );
	}

	// --- C4: get_source_mix_registrations ----------------------------------

	/**
	 * C4 populated: proxy returns rows of {source, registrations} → correct
	 * total and per-slice counts and pct values.
	 */
	public function test_source_mix_registrations_returns_populated_slices_on_success() {
		$rows   = [
			[
				'source'        => 'gate',
				'registrations' => 400,
			],
			[
				'source'        => 'prompt',
				'registrations' => 350,
			],
			[
				'source'        => 'direct',
				'registrations' => 250,
			],
		];
		$metric = new Conversion_Metric( $this->proxy_returning( $rows ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_source_mix_registrations( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertSame( 1000, $result['total'] );
		$this->assertCount( 3, $result['slices'] );

		// Check gate slice.
		$by_source = array_column( $result['slices'], null, 'source' );
		$this->assertSame( 400, $by_source['gate']['count'] );
		$this->assertEqualsWithDelta( 0.4, $by_source['gate']['pct'], 1e-9 );
		$this->assertSame( 350, $by_source['prompt']['count'] );
		$this->assertEqualsWithDelta( 0.35, $by_source['prompt']['pct'], 1e-9 );
		$this->assertSame( 250, $by_source['direct']['count'] );
		$this->assertEqualsWithDelta( 0.25, $by_source['direct']['pct'], 1e-9 );
	}

	/**
	 * C4 empty: proxy returns [] → state 'empty', slices and total zeroed.
	 */
	public function test_source_mix_registrations_returns_empty_state_on_no_rows() {
		$metric          = new Conversion_Metric( $this->proxy_returning( [] ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_source_mix_registrations( $start, $end );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( 0, $result['total'] );
		$this->assertSame( [], $result['slices'] );
	}

	/**
	 * C4 error: proxy returns WP_Error → state 'error' with code/message.
	 */
	public function test_source_mix_registrations_returns_error_state_on_proxy_error() {
		$wp_error        = new \WP_Error( 'bigquery_proxy_http_error', 'timeout' );
		$metric          = new Conversion_Metric( $this->proxy_returning( $wp_error ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_source_mix_registrations( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'timeout', $result['error_message'] );
		$this->assertSame( [], $result['slices'] );
	}

	// --- C5: get_time_to_register_distribution -----------------------------

	/**
	 * C5 populated: proxy returns per-day rows → CDF computed in PHP, sorted
	 * by day, each point has cumulative_pct rounded to 4 decimal places.
	 */
	public function test_time_to_register_returns_cdf_points_on_success() {
		// 100 conversions: 50 on day 1, 30 on day 3, 20 on day 7.
		$rows   = [
			[
				'days'        => 3,
				'conversions' => 30,
			],
			[
				'days'        => 7,
				'conversions' => 20,
			],
			[
				'days'        => 1,
				'conversions' => 50,
			],
		];
		$metric = new Conversion_Metric( $this->proxy_returning( $rows ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_time_to_register_distribution( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertArrayHasKey( 'points', $result );

		// Points must be sorted by day.
		$points = $result['points'];
		$this->assertCount( 3, $points );
		$this->assertSame( 1, $points[0]['day'] );
		$this->assertSame( 3, $points[1]['day'] );
		$this->assertSame( 7, $points[2]['day'] );

		// CDF: day 1 = 50/100 = 0.5, day 3 = 80/100 = 0.8, day 7 = 100/100 = 1.0.
		$this->assertSame( 0.5, $points[0]['cumulative_pct'] );
		$this->assertSame( 0.8, $points[1]['cumulative_pct'] );
		$this->assertSame( 1.0, $points[2]['cumulative_pct'] );
	}

	/**
	 * C5 populated: cumulative_pct values are rounded to 4 decimal places.
	 */
	public function test_time_to_register_rounds_cumulative_pct_to_4dp() {
		// 3 conversions: 1+1+1 → each point is 1/3, 2/3, 3/3.
		$rows   = [
			[
				'days'        => 1,
				'conversions' => 1,
			],
			[
				'days'        => 2,
				'conversions' => 1,
			],
			[
				'days'        => 3,
				'conversions' => 1,
			],
		];
		$metric = new Conversion_Metric( $this->proxy_returning( $rows ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_time_to_register_distribution( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		// 1/3 ≈ 0.3333, 2/3 ≈ 0.6667, 3/3 = 1.0.
		$this->assertSame( round( 1 / 3, 4 ), $result['points'][0]['cumulative_pct'] );
		$this->assertSame( round( 2 / 3, 4 ), $result['points'][1]['cumulative_pct'] );
		$this->assertSame( 1.0, $result['points'][2]['cumulative_pct'] );
	}

	/**
	 * C5 empty: proxy returns [] → state 'empty', empty points.
	 */
	public function test_time_to_register_returns_empty_state_on_no_rows() {
		$metric          = new Conversion_Metric( $this->proxy_returning( [] ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_time_to_register_distribution( $start, $end );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( [], $result['points'] );
	}

	/**
	 * C5 empty: rows sum to zero conversions → state 'empty'.
	 */
	public function test_time_to_register_returns_empty_state_on_zero_total() {
		$rows            = [
			[
				'days'        => 1,
				'conversions' => 0,
			],
			[
				'days'        => 2,
				'conversions' => 0,
			],
		];
		$metric          = new Conversion_Metric( $this->proxy_returning( $rows ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_time_to_register_distribution( $start, $end );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( [], $result['points'] );
	}

	/**
	 * C5 error: proxy returns WP_Error → state 'error' with code/message.
	 */
	public function test_time_to_register_returns_error_state_on_proxy_error() {
		$wp_error        = new \WP_Error( 'bigquery_proxy_http_error', 'Bad gateway' );
		$metric          = new Conversion_Metric( $this->proxy_returning( $wp_error ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_time_to_register_distribution( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'Bad gateway', $result['error_message'] );
		$this->assertSame( [], $result['points'] );
	}

	// --- Phase 2A wired method tests (C6–C9) --------------------------------

	// --- C6: get_weekly_conversion_rates -----------------------------------

	/**
	 * C6 populated: proxy returns rows of {week_start,
	 * registration_conversion_rate, subscription_attempt_rate} → state
	 * 'populated', `weeks` carries each row keyed by the three columns.
	 */
	public function test_weekly_conversion_rates_returns_populated_weeks_on_success() {
		$rows   = [
			[
				'week_start'                   => '2026-03-22',
				'registration_conversion_rate' => 0.12,
				'subscription_attempt_rate'    => 0.08,
			],
			[
				'week_start'                   => '2026-03-29',
				'registration_conversion_rate' => 0.15,
				'subscription_attempt_rate'    => 0.09,
			],
		];
		$metric = new Conversion_Metric( $this->proxy_returning( $rows ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_weekly_conversion_rates( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertSame( [ 'registration_rate', 'subscription_attempt_rate' ], $result['series'] );
		$this->assertCount( 2, $result['weeks'] );

		// First week row: keys and cast values.
		$week0 = $result['weeks'][0];
		$this->assertSame( '2026-03-22', $week0['week'] );
		$this->assertEqualsWithDelta( 0.12, $week0['registration_conversion_rate'], 1e-9 );
		$this->assertEqualsWithDelta( 0.08, $week0['subscription_attempt_rate'], 1e-9 );
	}

	/**
	 * C6 empty: proxy returns [] → state 'empty', empty weeks, series keys preserved.
	 */
	public function test_weekly_conversion_rates_returns_empty_state_on_no_rows() {
		$metric          = new Conversion_Metric( $this->proxy_returning( [] ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_weekly_conversion_rates( $start, $end );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( [], $result['weeks'] );
		$this->assertSame( [ 'registration_rate', 'subscription_attempt_rate' ], $result['series'] );
	}

	/**
	 * C6 error: proxy returns WP_Error → state 'error' with code/message,
	 * and `series` is present (React reads it unconditionally to build the legend).
	 */
	public function test_weekly_conversion_rates_returns_error_state_on_proxy_error() {
		$wp_error        = new \WP_Error( 'bigquery_proxy_http_error', 'HTTP 502' );
		$metric          = new Conversion_Metric( $this->proxy_returning( $wp_error ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_weekly_conversion_rates( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'HTTP 502', $result['error_message'] );
		$this->assertSame( [], $result['weeks'] );
		$this->assertSame( [ 'registration_rate', 'subscription_attempt_rate' ], $result['series'] );
	}

	// --- C7: get_influenced_registration_rate_7d ---------------------------

	/**
	 * C7 populated: proxy returns one row with influenced_registration_rate →
	 * state 'populated', computable, correct float value.
	 */
	public function test_influenced_registration_rate_7d_returns_populated_scalar_on_success() {
		$metric          = new Conversion_Metric( $this->proxy_returning( [ [ 'influenced_registration_rate' => 0.37 ] ] ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_influenced_registration_rate_7d( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 'rate', $result['placeholder_type'] );
		$this->assertEqualsWithDelta( 0.37, $result['value'], 1e-9 );
	}

	/**
	 * C7 empty: proxy returns [] → populated non-computable zero.
	 */
	public function test_influenced_registration_rate_7d_returns_non_computable_zero_on_empty() {
		$metric          = new Conversion_Metric( $this->proxy_returning( [] ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_influenced_registration_rate_7d( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'] );
		$this->assertEqualsWithDelta( 0.0, $result['value'], 1e-9 );
	}

	/**
	 * C7 error: proxy returns WP_Error → state 'error'.
	 */
	public function test_influenced_registration_rate_7d_returns_error_state_on_proxy_error() {
		$wp_error        = new \WP_Error( 'bigquery_proxy_http_error', 'timeout' );
		$metric          = new Conversion_Metric( $this->proxy_returning( $wp_error ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_influenced_registration_rate_7d( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'timeout', $result['error_message'] );
	}

	// --- C8: get_influenced_newsletter_rate_7d -----------------------------

	/**
	 * C8 populated: proxy returns one row with influenced_newsletter_rate →
	 * state 'populated', computable, correct float value.
	 */
	public function test_influenced_newsletter_rate_7d_returns_populated_scalar_on_success() {
		$metric          = new Conversion_Metric( $this->proxy_returning( [ [ 'influenced_newsletter_rate' => 0.22 ] ] ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_influenced_newsletter_rate_7d( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 'rate', $result['placeholder_type'] );
		$this->assertEqualsWithDelta( 0.22, $result['value'], 1e-9 );
	}

	/**
	 * C8 empty: proxy returns [] → populated non-computable zero.
	 */
	public function test_influenced_newsletter_rate_7d_returns_non_computable_zero_on_empty() {
		$metric          = new Conversion_Metric( $this->proxy_returning( [] ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_influenced_newsletter_rate_7d( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'] );
		$this->assertEqualsWithDelta( 0.0, $result['value'], 1e-9 );
	}

	/**
	 * C8 error: proxy returns WP_Error → state 'error'.
	 */
	public function test_influenced_newsletter_rate_7d_returns_error_state_on_proxy_error() {
		$wp_error        = new \WP_Error( 'bigquery_proxy_http_error', 'timeout' );
		$metric          = new Conversion_Metric( $this->proxy_returning( $wp_error ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_influenced_newsletter_rate_7d( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'timeout', $result['error_message'] );
	}

	// --- C12: get_source_mix_subscribers -----------------------------------

	/**
	 * Build sample BQ attempt rows for subscriber/donor source-mix tests.
	 * Two gate rows, one prompt row, one direct row.
	 *
	 * @return array
	 */
	private function source_mix_rows(): array {
		return [
			[
				'uid'          => '101',
				'session_id'   => 's1',
				'attempt_ts'   => 1717000000000000,
				'gate_post_id' => '55',
				'popup_id'     => '',
			],
			[
				'uid'          => '102',
				'session_id'   => 's2',
				'attempt_ts'   => 1717001000000000,
				'gate_post_id' => '55',
				'popup_id'     => '42',
			],
			[
				'uid'          => '103',
				'session_id'   => 's3',
				'attempt_ts'   => 1717002000000000,
				'gate_post_id' => '',
				'popup_id'     => '42',
			],
			[
				'uid'          => '104',
				'session_id'   => 's4',
				'attempt_ts'   => 1717003000000000,
				'gate_post_id' => '',
				'popup_id'     => '',
			],
		];
	}

	/**
	 * C12 populated: proxy returns attempt rows; resolver assigns 1 order per
	 * non-empty source bucket → correct total, slices, pct values.
	 */
	public function test_source_mix_subscribers_returns_populated_slices_on_success() {
		$rows  = $this->source_mix_rows();
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )
			->with( 'conversion_journey_source_mix_subscribers' )
			->willReturn( $rows );

		// Each bucket gets count_completed_orders called once; return 1 for gate
		// (2 rows), 1 for prompt (1 row), 1 for direct (1 row).
		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_completed_orders' )->willReturn( 1 );

		$metric          = new Conversion_Metric( $proxy, $resolver );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_source_mix_subscribers( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertSame( 3, $result['total'] ); // 1 gate + 1 prompt + 1 direct.
		$this->assertCount( 3, $result['slices'] );

		$by_source = array_column( $result['slices'], null, 'source' );
		$this->assertSame( 1, $by_source['gate']['count'] );
		$this->assertSame( 1, $by_source['prompt']['count'] );
		$this->assertSame( 1, $by_source['direct']['count'] );
		// pct = 1/3 each.
		$this->assertEqualsWithDelta( 1 / 3, $by_source['gate']['pct'], 1e-9 );
	}

	/**
	 * C12 empty: proxy returns [] → state 'empty', total=0, slices=[].
	 */
	public function test_source_mix_subscribers_returns_empty_state_on_no_rows() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( [] );

		$resolver        = $this->createMock( Woo_Order_Resolver::class );
		$metric          = new Conversion_Metric( $proxy, $resolver );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_source_mix_subscribers( $start, $end );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( 0, $result['total'] );
		$this->assertSame( [], $result['slices'] );
	}

	/**
	 * C12 error: proxy returns WP_Error → state 'error' with code/message, slices=[].
	 */
	public function test_source_mix_subscribers_returns_error_state_on_proxy_error() {
		$wp_error = new \WP_Error( 'bigquery_proxy_http_error', 'HTTP 500' );
		$proxy    = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( $wp_error );

		$resolver        = $this->createMock( Woo_Order_Resolver::class );
		$metric          = new Conversion_Metric( $proxy, $resolver );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_source_mix_subscribers( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'HTTP 500', $result['error_message'] );
		$this->assertSame( [], $result['slices'] );
	}

	/**
	 * C12 populated: zero-total guard — when all buckets resolve to 0 orders,
	 * total=0 and pct=0.0 for all slices (no div-by-zero).
	 */
	public function test_source_mix_subscribers_guards_zero_total() {
		$rows  = $this->source_mix_rows();
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( $rows );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_completed_orders' )->willReturn( 0 );

		$metric          = new Conversion_Metric( $proxy, $resolver );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_source_mix_subscribers( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 0, $result['total'] );
		foreach ( $result['slices'] as $slice ) {
			$this->assertSame( 0.0, $slice['pct'] );
		}
	}

	// --- C13: get_source_mix_donors -----------------------------------------

	/**
	 * C13 populated: identical logic to C12, different query name.
	 */
	public function test_source_mix_donors_returns_populated_slices_on_success() {
		$rows  = $this->source_mix_rows();
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )
			->with( 'conversion_journey_source_mix_donors' )
			->willReturn( $rows );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_completed_orders' )->willReturn( 1 );

		$metric          = new Conversion_Metric( $proxy, $resolver );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_source_mix_donors( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertSame( 3, $result['total'] );
		$this->assertCount( 3, $result['slices'] );
	}

	/**
	 * C13 empty: proxy returns [] → state 'empty'.
	 */
	public function test_source_mix_donors_returns_empty_state_on_no_rows() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( [] );

		$resolver        = $this->createMock( Woo_Order_Resolver::class );
		$metric          = new Conversion_Metric( $proxy, $resolver );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_source_mix_donors( $start, $end );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( 0, $result['total'] );
		$this->assertSame( [], $result['slices'] );
	}

	/**
	 * C13 error: proxy returns WP_Error → state 'error'.
	 */
	public function test_source_mix_donors_returns_error_state_on_proxy_error() {
		$wp_error = new \WP_Error( 'bigquery_proxy_http_error', 'HTTP 503' );
		$proxy    = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( $wp_error );

		$resolver        = $this->createMock( Woo_Order_Resolver::class );
		$metric          = new Conversion_Metric( $proxy, $resolver );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_source_mix_donors( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'HTTP 503', $result['error_message'] );
		$this->assertSame( [], $result['slices'] );
	}

	// --- C14: get_influenced_subscription_rate_14d --------------------------

	/**
	 * C14 populated: both proxies return rows; numerator = count_unique_completed_users
	 * of influenced rows; denominator = count_unique_completed_users of source_mix_subscribers rows.
	 */
	public function test_influenced_subscription_rate_14d_returns_populated_rate_on_success() {
		$influenced_rows = [
			[
				'uid'          => '101',
				'session_id'   => 's1',
				'attempt_ts'   => 1717000000000000,
				'gate_post_id' => '55',
				'popup_id'     => '',
			],
		];
		$all_rows        = $this->source_mix_rows(); // 4 rows.

		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )
			->willReturnCallback(
				function ( string $query_name ) use ( $influenced_rows, $all_rows ) {
					if ( 'conversion_journey_influenced_subscription_14d' === $query_name ) {
						return $influenced_rows;
					}
					if ( 'conversion_journey_source_mix_subscribers' === $query_name ) {
						return $all_rows;
					}
					return [];
				}
			);

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_unique_completed_users' )
			->willReturnOnConsecutiveCalls( 1, 4 ); // numerator=1, denominator=4 (first call influenced, second source_mix).

		$metric          = new Conversion_Metric( $proxy, $resolver );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_influenced_subscription_rate_14d( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 'rate', $result['placeholder_type'] );
		$this->assertSame( 4, $result['denominator'] );
		$this->assertEqualsWithDelta( 0.25, $result['value'], 1e-9 ); // 1/4.
	}

	/**
	 * C14 zero denominator: denominator query returns rows but resolver returns 0 unique users
	 * → non-computable zero (not an error).
	 */
	public function test_influenced_subscription_rate_14d_returns_noncomputable_zero_when_denominator_is_zero() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( $this->source_mix_rows() );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_unique_completed_users' )->willReturn( 0 ); // Both numerator and denominator = 0.

		$metric          = new Conversion_Metric( $proxy, $resolver );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_influenced_subscription_rate_14d( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'] );
		$this->assertSame( 0, $result['denominator'] );
		$this->assertSame( 0.0, $result['value'] );
	}

	/**
	 * C14 error: influenced proxy returns WP_Error → state 'error'.
	 */
	public function test_influenced_subscription_rate_14d_returns_error_on_influenced_proxy_error() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )
			->willReturnCallback(
				function ( string $query_name ) {
					if ( 'conversion_journey_influenced_subscription_14d' === $query_name ) {
						return new \WP_Error( 'bigquery_proxy_http_error', 'HTTP 500' );
					}
					return $this->source_mix_rows();
				}
			);

		$resolver        = $this->createMock( Woo_Order_Resolver::class );
		$metric          = new Conversion_Metric( $proxy, $resolver );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_influenced_subscription_rate_14d( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'rate', $result['placeholder_type'] );
	}

	/**
	 * C14 error: source_mix_subscribers proxy returns WP_Error → state 'error'.
	 */
	public function test_influenced_subscription_rate_14d_returns_error_on_denominator_proxy_error() {
		$influenced_rows = [
			[
				'uid'        => '101',
				'session_id' => 's1',
				'attempt_ts' => 1717000000000000,
			],
		];
		$proxy           = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )
			->willReturnCallback(
				function ( string $query_name ) use ( $influenced_rows ) {
					if ( 'conversion_journey_influenced_subscription_14d' === $query_name ) {
						return $influenced_rows;
					}
					return new \WP_Error( 'bigquery_proxy_http_error', 'HTTP 503' );
				}
			);

		$resolver        = $this->createMock( Woo_Order_Resolver::class );
		$metric          = new Conversion_Metric( $proxy, $resolver );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_influenced_subscription_rate_14d( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'rate', $result['placeholder_type'] );
	}

	// --- C15: get_influenced_donation_rate_14d ------------------------------

	/**
	 * C15 populated: identical logic to C14 with donation queries.
	 */
	public function test_influenced_donation_rate_14d_returns_populated_rate_on_success() {
		$influenced_rows = [
			[
				'uid'        => '101',
				'session_id' => 's1',
				'attempt_ts' => 1717000000000000,
			],
			[
				'uid'        => '102',
				'session_id' => 's2',
				'attempt_ts' => 1717001000000000,
			],
		];
		$all_rows        = $this->source_mix_rows();

		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )
			->willReturnCallback(
				function ( string $query_name ) use ( $influenced_rows, $all_rows ) {
					if ( 'conversion_journey_influenced_donation_14d' === $query_name ) {
						return $influenced_rows;
					}
					if ( 'conversion_journey_source_mix_donors' === $query_name ) {
						return $all_rows;
					}
					return [];
				}
			);

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_unique_completed_users' )
			->willReturnOnConsecutiveCalls( 2, 4 ); // numerator=2, denominator=4.

		$metric          = new Conversion_Metric( $proxy, $resolver );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_influenced_donation_rate_14d( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 'rate', $result['placeholder_type'] );
		$this->assertSame( 4, $result['denominator'] );
		$this->assertEqualsWithDelta( 0.5, $result['value'], 1e-9 ); // 2/4.
	}

	/**
	 * C15 error: donation proxy returns WP_Error → state 'error'.
	 */
	public function test_influenced_donation_rate_14d_returns_error_on_proxy_error() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( new \WP_Error( 'bigquery_proxy_http_error', 'timeout' ) );

		$resolver        = $this->createMock( Woo_Order_Resolver::class );
		$metric          = new Conversion_Metric( $proxy, $resolver );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_influenced_donation_rate_14d( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'rate', $result['placeholder_type'] );
	}

	/**
	 * C15 zero denominator: both queries succeed but resolver returns 0 unique users
	 * → non-computable zero.
	 */
	public function test_influenced_donation_rate_14d_returns_noncomputable_zero_when_denominator_is_zero() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( $this->source_mix_rows() );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_unique_completed_users' )->willReturn( 0 );

		$metric          = new Conversion_Metric( $proxy, $resolver );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_influenced_donation_rate_14d( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'] );
		$this->assertSame( 0, $result['denominator'] );
		$this->assertSame( 0.0, $result['value'] );
	}

	// --- C10: get_registered_to_subscriber_funnel --------------------------

	/**
	 * Build a mock Subscribers_Metric that stubs count_active_non_donation_subscribers_by_customer_ids.
	 *
	 * @param int $return Value to return for the stub.
	 * @return Subscribers_Metric
	 */
	private function subscribers_metric_returning_count( int $return ): Subscribers_Metric {
		$mock = $this->createMock( Subscribers_Metric::class );
		$mock->method( 'count_active_non_donation_subscribers_by_customer_ids' )->willReturn( $return );
		return $mock;
	}

	/**
	 * Build a mock Donors_Metric that stubs count_completed_donation_order_customers_by_customer_ids.
	 *
	 * @param int $return Value to return for the stub.
	 * @return Donors_Metric
	 */
	private function donors_metric_returning_count( int $return ): Donors_Metric {
		$mock = $this->createMock( Donors_Metric::class );
		$mock->method( 'count_completed_donation_order_customers_by_customer_ids' )->willReturn( $return );
		return $mock;
	}

	/**
	 * C10 populated: proxy returns rows with uid + saw_subscription_surface →
	 * three stages built with correct counts and labels.
	 */
	public function test_registered_to_subscriber_funnel_returns_populated_stages_on_success() {
		$rows = [
			[
				'uid'                      => 1,
				'saw_subscription_surface' => 1,
			],
			[
				'uid'                      => 2,
				'saw_subscription_surface' => 1,
			],
			[
				'uid'                      => 3,
				'saw_subscription_surface' => 0,
			],
			[
				'uid'                      => 4,
				'saw_subscription_surface' => 0,
			],
		];

		$proxy = $this->proxy_returning( $rows );
		$subs  = $this->createMock( Subscribers_Metric::class );
		$subs->method( 'count_active_non_donation_subscribers_by_customer_ids' )->willReturn( 1 );

		$metric          = new Conversion_Metric( $proxy, null, $subs );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_registered_to_subscriber_funnel( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertCount( 3, $result['stages'] );

		// step_1: total rows = 4; pct_of_top = 1.0.
		$this->assertSame( 4, $result['stages'][0]['count'] );
		$this->assertSame( 1.0, $result['stages'][0]['pct_of_top'] );

		// step_2: sum of saw_subscription_surface = 2; pct = 2/4 = 0.5.
		$this->assertSame( 2, $result['stages'][1]['count'] );
		$this->assertEqualsWithDelta( 0.5, $result['stages'][1]['pct_of_top'], 1e-9 );

		// step_3: from mock = 1; pct = 1/4 = 0.25.
		$this->assertSame( 1, $result['stages'][2]['count'] );
		$this->assertEqualsWithDelta( 0.25, $result['stages'][2]['pct_of_top'], 1e-9 );

		// Every stage must have a non-empty label.
		foreach ( $result['stages'] as $stage ) {
			$this->assertNotSame( '', $stage['label'] );
		}
	}

	/**
	 * C10 empty: proxy returns [] → state 'empty', empty stages.
	 */
	public function test_registered_to_subscriber_funnel_returns_empty_state_on_no_rows() {
		$metric          = new Conversion_Metric( $this->proxy_returning( [] ), null, $this->createMock( Subscribers_Metric::class ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_registered_to_subscriber_funnel( $start, $end );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( [], $result['stages'] );
	}

	/**
	 * C10 error: proxy returns WP_Error → state 'error'.
	 */
	public function test_registered_to_subscriber_funnel_returns_error_on_proxy_error() {
		$wp_error        = new \WP_Error( 'bigquery_proxy_http_error', 'HTTP 500' );
		$metric          = new Conversion_Metric( $this->proxy_returning( $wp_error ), null, $this->createMock( Subscribers_Metric::class ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_registered_to_subscriber_funnel( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( [], $result['stages'] );
	}

	/**
	 * C10 malformed: proxy returns non-array first row → state 'error' (malformed).
	 */
	public function test_registered_to_subscriber_funnel_returns_error_on_malformed_rows() {
		$metric          = new Conversion_Metric( $this->proxy_returning( [ 'not-an-array' ] ), null, $this->createMock( Subscribers_Metric::class ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_registered_to_subscriber_funnel( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_malformed_rows', $result['error_code'] );
		$this->assertSame( [], $result['stages'] );
	}

	/**
	 * C10 malformed tail row: valid first row followed by a non-array tail row →
	 * state 'error' (malformed), matching the per-row is_array guard inside the loop.
	 */
	public function test_registered_to_subscriber_funnel_returns_error_on_malformed_tail_row() {
		$valid_row = [
			'uid'                      => 1,
			'saw_subscription_surface' => 1,
		];
		$metric          = new Conversion_Metric( $this->proxy_returning( [ $valid_row, 'not-an-array' ] ), null, $this->createMock( Subscribers_Metric::class ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_registered_to_subscriber_funnel( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_malformed_rows', $result['error_code'] );
		$this->assertSame( [], $result['stages'] );
	}

	// --- C11: get_registered_to_donor_funnel --------------------------------

	/**
	 * C11 populated: proxy returns rows with uid + saw_donation_surface →
	 * three stages built with correct counts.
	 */
	public function test_registered_to_donor_funnel_returns_populated_stages_on_success() {
		$rows = [
			[
				'uid'                  => 1,
				'saw_donation_surface' => 1,
			],
			[
				'uid'                  => 2,
				'saw_donation_surface' => 1,
			],
			[
				'uid'                  => 3,
				'saw_donation_surface' => 1,
			],
			[
				'uid'                  => 4,
				'saw_donation_surface' => 0,
			],
		];

		$proxy  = $this->proxy_returning( $rows );
		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'count_completed_donation_order_customers_by_customer_ids' )->willReturn( 2 );

		$metric          = new Conversion_Metric( $proxy, null, null, $donors );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_registered_to_donor_funnel( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertCount( 3, $result['stages'] );

		// step_1: 4 rows; pct_of_top = 1.0.
		$this->assertSame( 4, $result['stages'][0]['count'] );
		$this->assertSame( 1.0, $result['stages'][0]['pct_of_top'] );

		// step_2: sum of saw_donation_surface = 3; pct = 3/4 = 0.75.
		$this->assertSame( 3, $result['stages'][1]['count'] );
		$this->assertEqualsWithDelta( 0.75, $result['stages'][1]['pct_of_top'], 1e-9 );

		// step_3: from mock = 2; pct = 2/4 = 0.5.
		$this->assertSame( 2, $result['stages'][2]['count'] );
		$this->assertEqualsWithDelta( 0.5, $result['stages'][2]['pct_of_top'], 1e-9 );

		foreach ( $result['stages'] as $stage ) {
			$this->assertNotSame( '', $stage['label'] );
		}
	}

	/**
	 * C11 empty: proxy returns [] → state 'empty', empty stages.
	 */
	public function test_registered_to_donor_funnel_returns_empty_state_on_no_rows() {
		$metric          = new Conversion_Metric( $this->proxy_returning( [] ), null, null, $this->createMock( Donors_Metric::class ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_registered_to_donor_funnel( $start, $end );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( [], $result['stages'] );
	}

	/**
	 * C11 error: proxy returns WP_Error → state 'error'.
	 */
	public function test_registered_to_donor_funnel_returns_error_on_proxy_error() {
		$wp_error        = new \WP_Error( 'bigquery_proxy_http_error', 'HTTP 502' );
		$metric          = new Conversion_Metric( $this->proxy_returning( $wp_error ), null, null, $this->createMock( Donors_Metric::class ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_registered_to_donor_funnel( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( [], $result['stages'] );
	}

	/**
	 * C11 malformed: proxy returns non-array first row → state 'error' (malformed).
	 */
	public function test_registered_to_donor_funnel_returns_error_on_malformed_rows() {
		$metric          = new Conversion_Metric( $this->proxy_returning( [ 'not-an-array' ] ), null, null, $this->createMock( Donors_Metric::class ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_registered_to_donor_funnel( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_malformed_rows', $result['error_code'] );
		$this->assertSame( [], $result['stages'] );
	}

	/**
	 * C11 malformed tail row: valid first row followed by a non-array tail row →
	 * state 'error' (malformed), matching the per-row is_array guard inside the loop.
	 */
	public function test_registered_to_donor_funnel_returns_error_on_malformed_tail_row() {
		$valid_row = [
			'uid'                  => 1,
			'saw_donation_surface' => 1,
		];
		$metric          = new Conversion_Metric( $this->proxy_returning( [ $valid_row, 'not-an-array' ] ), null, null, $this->createMock( Donors_Metric::class ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_registered_to_donor_funnel( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_malformed_rows', $result['error_code'] );
		$this->assertSame( [], $result['stages'] );
	}

	// --- C16: get_at_risk_subscriber_count ----------------------------------

	/**
	 * C16 populated: Subscribers_Metric::get_at_risk_subscribers() returns
	 * a count → populated scalar with that count.
	 */
	public function test_at_risk_subscriber_count_returns_populated_scalar() {
		$subs = $this->createMock( Subscribers_Metric::class );
		$subs->method( 'get_at_risk_subscribers' )->willReturn( 42 );

		$metric          = new Conversion_Metric( null, null, $subs );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_at_risk_subscriber_count( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 'count', $result['placeholder_type'] );
		$this->assertSame( 42, $result['value'] );
		$this->assertNull( $result['denominator'] );
	}

	// --- C17: get_lapsed_donor_count ----------------------------------------

	/**
	 * C17 populated: Donors_Metric::get_lapsed_donors_in_window() returns a
	 * count → populated scalar with that count.
	 */
	public function test_lapsed_donor_count_returns_populated_scalar() {
		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'get_lapsed_donors_in_window' )->willReturn( 17 );

		$metric          = new Conversion_Metric( null, null, null, $donors );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_lapsed_donor_count( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 'count', $result['placeholder_type'] );
		$this->assertSame( 17, $result['value'] );
		$this->assertNull( $result['denominator'] );
	}

	// --- C18: get_stale_registered_count ------------------------------------

	/**
	 * C18 populated: Subscribers_Metric::get_stale_registered_users() returns
	 * a count → populated scalar with that count.
	 */
	public function test_stale_registered_count_returns_populated_scalar() {
		$subs = $this->createMock( Subscribers_Metric::class );
		$subs->method( 'get_stale_registered_users' )->willReturn( 123 );

		$metric          = new Conversion_Metric( null, null, $subs );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_stale_registered_count( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 'count', $result['placeholder_type'] );
		$this->assertSame( 123, $result['value'] );
		$this->assertNull( $result['denominator'] );
	}

	// --- C19: get_subscriber_to_donor_funnel --------------------------------

	/**
	 * C19 visible: both active_subs and active_donors ≥ 50 → state 'populated',
	 * visibility 'visible', two stages with correct counts.
	 */
	public function test_subscriber_to_donor_funnel_returns_visible_populated_result() {
		$subscriber_ids = range( 1, 60 ); // 60 subscriber IDs.

		$subs = $this->createMock( Subscribers_Metric::class );
		$subs->method( 'get_active_non_donation_subscriber_customer_ids' )->willReturn( $subscriber_ids );

		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'get_active_donors' )->willReturn( 55 );
		$donors->method( 'get_subscriber_donors_in_window' )->willReturn( 12 );

		$metric          = new Conversion_Metric( null, null, $subs, $donors );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_subscriber_to_donor_funnel( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertSame( 'visible', $result['visibility'] );
		$this->assertCount( 2, $result['stages'] );

		// step_1: active_subs = 60; pct_of_top = 1.0.
		$this->assertSame( 60, $result['stages'][0]['count'] );
		$this->assertSame( 1.0, $result['stages'][0]['pct_of_top'] );

		// step_2: subscriber donors = 12; pct = 12/60 = 0.2.
		$this->assertSame( 12, $result['stages'][1]['count'] );
		$this->assertEqualsWithDelta( 0.2, $result['stages'][1]['pct_of_top'], 1e-9 );

		foreach ( $result['stages'] as $stage ) {
			$this->assertNotSame( '', $stage['label'] );
		}
	}

	/**
	 * C19 hidden (below active_subs threshold): active_subs < 50 → visibility
	 * 'hidden' with 'insufficient_data' reason.
	 */
	public function test_subscriber_to_donor_funnel_is_hidden_when_active_subs_below_threshold() {
		$subs = $this->createMock( Subscribers_Metric::class );
		$subs->method( 'get_active_non_donation_subscriber_customer_ids' )->willReturn( range( 1, 30 ) ); // 30 < 50.

		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'get_active_donors' )->willReturn( 80 ); // ≥ 50, but subs below.

		$metric          = new Conversion_Metric( null, null, $subs, $donors );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_subscriber_to_donor_funnel( $start, $end );

		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertSame( 'insufficient_data', $result['visibility_reason'] );
	}

	/**
	 * C19 hidden (below active_donors threshold): active_donors < 50 → visibility
	 * 'hidden' with 'insufficient_data' reason.
	 */
	public function test_subscriber_to_donor_funnel_is_hidden_when_active_donors_below_threshold() {
		$subs = $this->createMock( Subscribers_Metric::class );
		$subs->method( 'get_active_non_donation_subscriber_customer_ids' )->willReturn( range( 1, 70 ) ); // 70 ≥ 50.

		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'get_active_donors' )->willReturn( 20 ); // 20 < 50.

		$metric          = new Conversion_Metric( null, null, $subs, $donors );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_subscriber_to_donor_funnel( $start, $end );

		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertSame( 'insufficient_data', $result['visibility_reason'] );
	}

	/**
	 * C19 hidden (exactly at threshold on both sides — boundary): active_subs = 49
	 * or active_donors = 49 → hidden.
	 */
	public function test_subscriber_to_donor_funnel_is_hidden_at_boundary_of_49() {
		$subs = $this->createMock( Subscribers_Metric::class );
		$subs->method( 'get_active_non_donation_subscriber_customer_ids' )->willReturn( range( 1, 49 ) ); // exactly 49.

		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'get_active_donors' )->willReturn( 49 ); // exactly 49.

		$metric          = new Conversion_Metric( null, null, $subs, $donors );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_subscriber_to_donor_funnel( $start, $end );

		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertSame( 'insufficient_data', $result['visibility_reason'] );
	}

	// --- C9: get_top_pages_no_conversion -----------------------------------

	/**
	 * C9 populated: proxy returns rows of {post_id, page_url, page_title,
	 * pageviews, unique_readers, conversion_rate} → state 'populated', rows
	 * with correct casts and threshold preserved.
	 */
	public function test_top_pages_no_conversion_returns_populated_rows_on_success() {
		$rows   = [
			[
				'post_id'         => '42',
				'page_url'        => '/article/foo',
				'page_title'      => 'Foo Article',
				'pageviews'       => '5000',
				'unique_readers'  => '3200',
				'conversion_rate' => '0.0045',
			],
			[
				'post_id'         => '99',
				'page_url'        => '/article/bar',
				'page_title'      => 'Bar Article',
				'pageviews'       => '2100',
				'unique_readers'  => '1800',
				'conversion_rate' => '0.0012',
			],
		];
		$metric = new Conversion_Metric( $this->proxy_returning( $rows ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_top_pages_no_conversion( $start, $end );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertArrayNotHasKey( 'pending', $result );
		$this->assertSame( 100, $result['threshold_pageviews'] );
		$this->assertCount( 2, $result['rows'] );

		// First row: verify type casts.
		$row0 = $result['rows'][0];
		$this->assertSame( 42, $row0['post_id'] );
		$this->assertSame( '/article/foo', $row0['page_url'] );
		$this->assertSame( 'Foo Article', $row0['page_title'] );
		$this->assertSame( 5000, $row0['pageviews'] );
		$this->assertSame( 3200, $row0['unique_readers'] );
		$this->assertEqualsWithDelta( 0.0045, $row0['conversion_rate'], 1e-9 );
	}

	/**
	 * C9 empty: proxy returns [] → state 'empty', empty rows, threshold preserved.
	 */
	public function test_top_pages_no_conversion_returns_empty_state_on_no_rows() {
		$metric          = new Conversion_Metric( $this->proxy_returning( [] ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_top_pages_no_conversion( $start, $end );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( [], $result['rows'] );
		$this->assertSame( 100, $result['threshold_pageviews'] );
	}

	/**
	 * C9 error: proxy returns WP_Error → state 'error' with code/message.
	 */
	public function test_top_pages_no_conversion_returns_error_state_on_proxy_error() {
		$wp_error        = new \WP_Error( 'bigquery_proxy_http_error', 'HTTP 503' );
		$metric          = new Conversion_Metric( $this->proxy_returning( $wp_error ) );
		[ $start, $end ] = $this->window();
		$result          = $metric->get_top_pages_no_conversion( $start, $end );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'HTTP 503', $result['error_message'] );
		$this->assertSame( [], $result['rows'] );
	}

	// --- Fixture tests (get_fixture delegates to conversion-fixture.php) --------

	/**
	 * Returns the full { tab_error, current, previous } outer shape.
	 *
	 * Smoke-test that the fixture file loads correctly.
	 */
	public function test_get_fixture_returns_outer_shape() {
		$payload = Conversion_Metric::get_fixture( 'populated', false );

		$this->assertArrayHasKey( 'tab_error', $payload );
		$this->assertArrayHasKey( 'current', $payload );
		$this->assertArrayHasKey( 'previous', $payload );
	}

	/**
	 * Populated fixture: tab_error is false; Phase-A metrics carry
	 * state:'populated'; deferred sections carry state:'coming_soon' in ALL
	 * three variants.
	 */
	public function test_fixture_populated_variant() {
		$payload = Conversion_Metric::get_fixture( 'populated', false );

		$this->assertFalse( $payload['tab_error'] );
		$this->assertNull( $payload['previous'] );

		$current = $payload['current'];

		// Section 1 — lifecycle funnel has 5 stages.
		$this->assertSame( 'populated', $current['reader_lifecycle_funnel']['state'] );
		$this->assertCount( 5, $current['reader_lifecycle_funnel']['stages'] );

		// Section 2 — per-journey funnels.
		$this->assertSame( 'populated', $current['anonymous_to_registered_funnel']['state'] );
		$this->assertCount( 3, $current['anonymous_to_registered_funnel']['stages'] );
		$this->assertSame( 'populated', $current['registered_to_subscriber_funnel']['state'] );
		$this->assertSame( 'populated', $current['registered_to_donor_funnel']['state'] );

		// 2.4 — Subscriber → Donor: visible with stages.
		$this->assertSame( 'populated', $current['subscriber_to_donor_funnel']['state'] );
		$this->assertSame( 'visible', $current['subscriber_to_donor_funnel']['visibility'] );
		$this->assertNotEmpty( $current['subscriber_to_donor_funnel']['stages'] );

		// Section 3 — source-mix pies: total + slices.
		$this->assertSame( 'populated', $current['source_mix_registrations']['state'] );
		$this->assertGreaterThan( 0, $current['source_mix_registrations']['total'] );
		$this->assertCount( 3, $current['source_mix_registrations']['slices'] );
		$this->assertSame( 'populated', $current['source_mix_subscribers']['state'] );
		$this->assertSame( 'populated', $current['source_mix_donors']['state'] );

		// Section 4.1 — time-to-register CDF: monotonic points.
		$this->assertSame( 'populated', $current['time_to_register_distribution']['state'] );
		$points      = $current['time_to_register_distribution']['points'];
		$point_count = count( $points );
		$this->assertNotEmpty( $points );
		for ( $i = 1; $i < $point_count; $i++ ) {
			$this->assertGreaterThanOrEqual(
				$points[ $i - 1 ]['cumulative_pct'],
				$points[ $i ]['cumulative_pct'],
				'CDF points must be monotonically non-decreasing'
			);
		}

		// Section 6 — weekly rates: weeks array + series keys.
		$this->assertSame( 'populated', $current['weekly_conversion_rates']['state'] );
		$this->assertNotEmpty( $current['weekly_conversion_rates']['weeks'] );
		$this->assertSame( [ 'registration_rate', 'subscription_attempt_rate' ], $current['weekly_conversion_rates']['series'] );

		// Section 7 — influenced scalars.
		$this->assertSame( 'populated', $current['influenced_registration_rate_7d']['state'] );
		$this->assertSame( 'rate', $current['influenced_registration_rate_7d']['placeholder_type'] );
		$this->assertSame( 'populated', $current['influenced_subscription_rate_14d']['state'] );
		$this->assertSame( 'populated', $current['influenced_donation_rate_14d']['state'] );
		$this->assertSame( 'populated', $current['influenced_newsletter_rate_7d']['state'] );

		// Sections 8.1–8.3 — opportunity counts.
		$this->assertSame( 'populated', $current['stale_registered_count']['state'] );
		$this->assertSame( 'count', $current['stale_registered_count']['placeholder_type'] );
		$this->assertSame( 'populated', $current['at_risk_subscriber_count']['state'] );
		$this->assertSame( 'populated', $current['lapsed_donor_count']['state'] );

		// Section 8.4 — top pages table.
		$this->assertSame( 'populated', $current['top_pages_no_conversion']['state'] );
		$this->assertNotEmpty( $current['top_pages_no_conversion']['rows'] );
		$this->assertSame( 100, $current['top_pages_no_conversion']['threshold_pageviews'] );
	}

	/**
	 * Deferred (Phase-B) sections are 'coming_soon' in the populated variant and
	 * carry their preserved extra keys.
	 */
	public function test_fixture_populated_deferred_sections_are_coming_soon() {
		$current = Conversion_Metric::get_fixture( 'populated', false )['current'];

		// 4.2 and 4.3 — time distributions (groups key).
		$this->assertSame( 'coming_soon', $current['time_to_subscribe_distribution']['state'] );
		$this->assertSame( [], $current['time_to_subscribe_distribution']['groups'] );
		$this->assertSame( 'coming_soon', $current['time_to_donate_distribution']['state'] );
		$this->assertSame( [], $current['time_to_donate_distribution']['groups'] );

		// 4.4 — lag distribution (points + visibility keys).
		$this->assertSame( 'coming_soon', $current['subscriber_to_donor_lag_distribution']['state'] );
		$this->assertSame( [], $current['subscriber_to_donor_lag_distribution']['points'] );
		$this->assertSame( 'hidden', $current['subscriber_to_donor_lag_distribution']['visibility'] );
		$this->assertSame( 'insufficient_data', $current['subscriber_to_donor_lag_distribution']['visibility_reason'] );

		// 5.1 — registration cohort (reference_line).
		$this->assertSame( 'coming_soon', $current['registration_to_conversion_cohort']['state'] );
		$this->assertSame( [], $current['registration_to_conversion_cohort']['cohorts'] );
		$this->assertSame( 0.15, $current['registration_to_conversion_cohort']['reference_line']['value'] );

		// 5.2 — subscriber retention cohort (reference_line).
		$this->assertSame( 'coming_soon', $current['subscriber_retention_cohort']['state'] );
		$this->assertSame( [], $current['subscriber_retention_cohort']['cohorts'] );
		$this->assertSame( 0.70, $current['subscriber_retention_cohort']['reference_line']['value'] );
	}

	/**
	 * Empty fixture: BQ-backed collections carry state:'empty' with empty
	 * collections; scalars carry non-computable zeros; tab_error is false;
	 * deferred sections stay 'coming_soon'.
	 */
	public function test_fixture_empty_variant() {
		$payload = Conversion_Metric::get_fixture( 'empty', false );

		$this->assertFalse( $payload['tab_error'] );
		$current = $payload['current'];

		// BQ-backed funnels → empty.
		$this->assertSame( 'empty', $current['reader_lifecycle_funnel']['state'] );
		$this->assertSame( [], $current['reader_lifecycle_funnel']['stages'] );
		$this->assertSame( 'empty', $current['anonymous_to_registered_funnel']['state'] );
		$this->assertSame( 'empty', $current['registered_to_subscriber_funnel']['state'] );
		$this->assertSame( 'empty', $current['registered_to_donor_funnel']['state'] );

		// Source-mix pies → empty.
		$this->assertSame( 'empty', $current['source_mix_registrations']['state'] );
		$this->assertSame( 0, $current['source_mix_registrations']['total'] );
		$this->assertSame( [], $current['source_mix_registrations']['slices'] );

		// Time-to-register → empty.
		$this->assertSame( 'empty', $current['time_to_register_distribution']['state'] );
		$this->assertSame( [], $current['time_to_register_distribution']['points'] );

		// Influenced scalars → non-computable zeros (populated).
		$this->assertSame( 'populated', $current['influenced_registration_rate_7d']['state'] );
		$this->assertFalse( $current['influenced_registration_rate_7d']['computable'] );
		$this->assertEqualsWithDelta( 0.0, $current['influenced_registration_rate_7d']['value'], 1e-9 );

		// Top pages → empty.
		$this->assertSame( 'empty', $current['top_pages_no_conversion']['state'] );
		$this->assertSame( [], $current['top_pages_no_conversion']['rows'] );

		// Deferred sections stay 'coming_soon'.
		$this->assertSame( 'coming_soon', $current['time_to_subscribe_distribution']['state'] );
		$this->assertSame( 'coming_soon', $current['registration_to_conversion_cohort']['state'] );
	}

	/**
	 * Error fixture: BQ-backed metrics carry state:'error'; local-only metrics
	 * (subscriber-to-donor funnel, opportunity counts) stay 'populated'; tab_error
	 * is false because deferred + local metrics are non-error; deferred sections
	 * stay 'coming_soon'.
	 */
	public function test_fixture_error_variant() {
		$payload = Conversion_Metric::get_fixture( 'error', false );

		// tab_error is false — snapshot and deferred metrics are non-error.
		$this->assertFalse( $payload['tab_error'] );
		$current = $payload['current'];

		// BQ-backed metrics → error.
		$this->assertSame( 'error', $current['reader_lifecycle_funnel']['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $current['reader_lifecycle_funnel']['error_code'] );
		$this->assertSame( 'error', $current['source_mix_registrations']['state'] );
		$this->assertSame( 'error', $current['time_to_register_distribution']['state'] );
		$this->assertSame( 'error', $current['weekly_conversion_rates']['state'] );
		$this->assertSame( 'error', $current['influenced_registration_rate_7d']['state'] );
		$this->assertSame( 'error', $current['top_pages_no_conversion']['state'] );

		// Local-only metric (2.4) stays populated with visibility keys.
		$this->assertSame( 'populated', $current['subscriber_to_donor_funnel']['state'] );
		$this->assertArrayHasKey( 'visibility', $current['subscriber_to_donor_funnel'] );

		// Opportunity counts stay populated.
		$this->assertSame( 'populated', $current['stale_registered_count']['state'] );
		$this->assertSame( 'populated', $current['at_risk_subscriber_count']['state'] );
		$this->assertSame( 'populated', $current['lapsed_donor_count']['state'] );

		// Deferred sections stay 'coming_soon'.
		$this->assertSame( 'coming_soon', $current['time_to_subscribe_distribution']['state'] );
		$this->assertSame( 'coming_soon', $current['registration_to_conversion_cohort']['state'] );
	}

	/**
	 * The fixture populates `previous` when comparison is requested.
	 */
	public function test_fixture_compare_populates_previous() {
		$payload = Conversion_Metric::get_fixture( 'populated', true );

		$this->assertIsArray( $payload['previous'] );
		$this->assertArrayHasKey( 'reader_lifecycle_funnel', $payload['previous'] );
		$this->assertSame( 'populated', $payload['previous']['reader_lifecycle_funnel']['state'] );
		$this->assertArrayHasKey( 'window', $payload['previous'] );
	}

	/**
	 * The fixture current window contains exactly 24 keys (23 metrics + window echo),
	 * matching the controller's expected key count.
	 */
	public function test_fixture_current_window_has_24_keys() {
		$current = Conversion_Metric::get_fixture( 'populated', false )['current'];
		$this->assertCount( 24, $current );
	}
}
