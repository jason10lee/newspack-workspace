<?php
/**
 * Test Conversion_Metric (NPPD-1609, Phase 1 + Phase 2A).
 *
 * Phase 1 orchestrator returns placeholder envelopes only — no proxy, no
 * SQL. These tests pin the envelope shape every Phase 2 swap must preserve:
 * scalar scorecards carry `value` / `pending` / `computable` /
 * `placeholder_type`; the five funnels carry `pending` + ordered zeroed
 * stages (two of them visibility-gated); the three PieCharts carry zeroed
 * source slices; the four cumulative distributions carry empty point/series
 * collections (4.4 visibility-gated); the two cohorts carry empty `cohorts`
 * + a hardcoded reference line; the weekly trends carry empty `weeks` + the
 * series keys; and the opportunity table carries empty `rows` + a threshold.
 *
 * Phase 2A (C2–C5): four methods are now wired to BigQuery via the proxy.
 * Their old `pending: true` placeholders are replaced by state-envelope
 * tests that cover populated / empty / error paths.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use DateTimeImmutable;
use DateTimeZone;
use Newspack\Insights\BigQuery_Proxy_Client;
use Newspack\Insights\Conversion_Metric;
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

	/**
	 * Every scalar scorecard returns the Phase 1 placeholder envelope: a
	 * non-computable, pending, zero value of the documented type.
	 *
	 * @dataProvider provide_scalar_methods
	 * @param string $method           Method on Conversion_Metric to call.
	 * @param string $placeholder_type Expected `placeholder_type`.
	 */
	public function test_scalar_returns_placeholder_envelope( string $method, string $placeholder_type ) {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->$method( $start, $end );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'value', $result );
		$this->assertArrayHasKey( 'computable', $result );
		$this->assertArrayHasKey( 'pending', $result );
		$this->assertArrayHasKey( 'denominator', $result );
		$this->assertArrayHasKey( 'placeholder_type', $result );

		$this->assertTrue( $result['pending'], "$method should be pending in Phase 1" );
		$this->assertFalse( $result['computable'], "$method should be non-computable in Phase 1" );
		$this->assertNull( $result['denominator'] );
		$this->assertSame( $placeholder_type, $result['placeholder_type'] );

		if ( 'decimal' === $placeholder_type ) {
			$this->assertSame( 0.0, $result['value'] );
		} else {
			$this->assertSame( 0, $result['value'] );
		}
	}

	/**
	 * The three scalar scorecards still on placeholders: three Section 8
	 * opportunity snapshot counts. C7 (influenced_registration_rate_7d), C8
	 * (influenced_newsletter_rate_7d), C14 (influenced_subscription_rate_14d),
	 * and C15 (influenced_donation_rate_14d) have been wired and are tested
	 * separately.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public function provide_scalar_methods(): array {
		return [
			// Section 8 — opportunity buckets (snapshot counts).
			'stale_registered_count'   => [ 'get_stale_registered_count', 'count' ],
			'at_risk_subscriber_count' => [ 'get_at_risk_subscriber_count', 'count' ],
			'lapsed_donor_count'       => [ 'get_lapsed_donor_count', 'count' ],
		];
	}

	/**
	 * Every funnel returns a pending envelope with the expected number of
	 * ordered, zeroed stages.
	 *
	 * @dataProvider provide_funnel_methods
	 * @param string $method      Method on Conversion_Metric to call.
	 * @param int    $stage_count Expected number of stages.
	 */
	public function test_funnel_returns_zeroed_stages( string $method, int $stage_count ) {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->$method( $start, $end );

		$this->assertTrue( $result['pending'] );
		$this->assertCount( $stage_count, $result['stages'] );
		foreach ( $result['stages'] as $stage ) {
			$this->assertArrayHasKey( 'label', $stage );
			$this->assertNotSame( '', $stage['label'] );
			$this->assertSame( 0, $stage['count'] );
			$this->assertSame( 0.0, $stage['pct_of_top'] );
		}
	}

	/**
	 * The still-pending funnel methods: two Section 2 per-journey funnels
	 * (3/3/2) plus the visibility-gated cross-upsell funnel. The lifecycle
	 * (C2) and anon-to-registered (C3) funnels have been wired and are tested
	 * separately below.
	 *
	 * @return array<string, array{0:string,1:int}>
	 */
	public function provide_funnel_methods(): array {
		return [
			'registered_to_subscriber' => [ 'get_registered_to_subscriber_funnel', 3 ],
			'registered_to_donor'      => [ 'get_registered_to_donor_funnel', 3 ],
			'subscriber_to_donor'      => [ 'get_subscriber_to_donor_funnel', 2 ],
		];
	}

	/**
	 * The visibility-gated Section 2.4 funnel is hidden in Phase 1, with the
	 * `insufficient_data` reason the React side reads for its empty state.
	 */
	public function test_cross_upsell_funnel_is_hidden_in_phase_1() {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->get_subscriber_to_donor_funnel( $start, $end );

		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertSame( 'insufficient_data', $result['visibility_reason'] );
	}

	/**
	 * The two single-series Section 4 distributions return a pending
	 * envelope with an empty `points` array.
	 *
	 * @dataProvider provide_single_series_distribution_methods
	 * @param string $method Method on Conversion_Metric to call.
	 */
	public function test_single_series_distribution_is_empty( string $method ) {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->$method( $start, $end );

		$this->assertTrue( $result['pending'] );
		$this->assertArrayHasKey( 'points', $result );
		$this->assertSame( [], $result['points'] );
	}

	/**
	 * The still-pending single-series cumulative distributions (4.4 only).
	 * The time-to-register distribution (C5) has been wired and is tested separately below.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_single_series_distribution_methods(): array {
		return [
			'subscriber_to_donor_lag' => [ 'get_subscriber_to_donor_lag_distribution' ],
		];
	}

	/**
	 * The two multi-series Section 4 distributions return a pending envelope
	 * with three empty per-source series (gate / prompt / direct).
	 *
	 * @dataProvider provide_multi_series_distribution_methods
	 * @param string $method Method on Conversion_Metric to call.
	 */
	public function test_multi_series_distribution_is_empty( string $method ) {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->$method( $start, $end );

		$this->assertTrue( $result['pending'] );
		$this->assertCount( 3, $result['groups'] );
		$this->assertSame(
			[ 'gate', 'prompt', 'direct' ],
			array_column( $result['groups'], 'label' )
		);
		foreach ( $result['groups'] as $group ) {
			$this->assertSame( [], $group['points'] );
		}
	}

	/**
	 * The multi-series cumulative distributions (4.2 and 4.3).
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_multi_series_distribution_methods(): array {
		return [
			'time_to_subscribe' => [ 'get_time_to_subscribe_distribution' ],
			'time_to_donate'    => [ 'get_time_to_donate_distribution' ],
		];
	}

	/**
	 * The visibility-gated Section 4.4 lag distribution is hidden in Phase 1.
	 */
	public function test_lag_distribution_is_hidden_in_phase_1() {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->get_subscriber_to_donor_lag_distribution( $start, $end );

		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertSame( 'insufficient_data', $result['visibility_reason'] );
	}

	/**
	 * Each Section 5 cohort returns a pending envelope with empty `cohorts`
	 * and the spec's hardcoded reference line.
	 *
	 * @dataProvider provide_cohort_methods
	 * @param string $method         Method on Conversion_Metric to call.
	 * @param float  $expected_value Expected reference-line value.
	 */
	public function test_cohort_returns_empty_with_reference_line( string $method, float $expected_value ) {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->$method( $start, $end );

		$this->assertTrue( $result['pending'] );
		$this->assertSame( [], $result['cohorts'] );
		$this->assertArrayHasKey( 'reference_line', $result );
		$this->assertSame( $expected_value, $result['reference_line']['value'] );
		$this->assertNotSame( '', $result['reference_line']['label'] );
	}

	/**
	 * The two Section 5 cohorts and their hardcoded reference-line values.
	 *
	 * @return array<string, array{0:string,1:float}>
	 */
	public function provide_cohort_methods(): array {
		return [
			'registration_to_conversion' => [ 'get_registration_to_conversion_cohort', 0.15 ],
			'subscriber_retention'       => [ 'get_subscriber_retention_cohort', 0.70 ],
		];
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
}
