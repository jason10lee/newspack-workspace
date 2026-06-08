<?php
/**
 * Test Gates_Metric.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use DateTimeImmutable;
use DateTimeZone;
use Newspack\Insights\BigQuery_Proxy_Client;
use Newspack\Insights\Gates_Metric;
use WP_UnitTestCase;

/**
 * Gates_Metric test class.
 *
 * @group insights
 */
class Test_Gates_Metric extends WP_UnitTestCase {

	/**
	 * Make a UTC DateTimeImmutable from a YYYY-MM-DD string.
	 *
	 * @param string $ymd Date string.
	 * @return DateTimeImmutable
	 */
	protected function make_date( string $ymd ): DateTimeImmutable {
		return new DateTimeImmutable( $ymd, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Constructor accepts an injected proxy client (test seam).
	 */
	public function test_constructor_accepts_injected_proxy() {
		$proxy  = $this->createMock( BigQuery_Proxy_Client::class );
		$metric = new Gates_Metric( $proxy );
		$this->assertInstanceOf( Gates_Metric::class, $metric );
	}

	/**
	 * Scorecards return real values on a successful proxy response.
	 *
	 * @dataProvider provide_scorecard_success_cases
	 * @param string $method           Method on Gates_Metric to call.
	 * @param string $query_name       Catalog query name the orchestrator should dispatch.
	 * @param string $row_key          Column the orchestrator reads from row 0.
	 * @param mixed  $row_value        Value the mock client returns for that column.
	 * @param string $placeholder_type Expected `placeholder_type` in the payload.
	 * @param mixed  $expected_value   Expected `value` in the payload.
	 */
	public function test_scorecard_returns_real_value_on_success( string $method, string $query_name, string $row_key, $row_value, string $placeholder_type, $expected_value ) {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( $query_name, $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn( [ [ $row_key => $row_value ] ] );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->$method( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( $expected_value, $result['value'] );
		$this->assertFalse( $result['pending'] );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( $placeholder_type, $result['placeholder_type'] );
	}

	/**
	 * Data provider for scorecard success cases.
	 *
	 * @return array
	 */
	public function provide_scorecard_success_cases(): array {
		return [
			'total_impressions'  => [ 'get_total_gate_impressions', 'gates_total_impressions', 'gate_impressions', 12345, 'count', 12345 ],
			'unique_viewers'     => [ 'get_unique_readers_reached', 'gates_unique_viewers', 'unique_gate_viewers', 678, 'count', 678 ],
			'avg_exposures'      => [ 'get_avg_exposures_per_reader', 'gates_avg_exposures_per_reader', 'avg_exposures_per_reader', 3.5, 'decimal', 3.5 ],
			'pct_sessions'       => [ 'get_sessions_with_gate', 'gates_sessions_with_gate', 'pct_sessions_with_gate', 0.42, 'rate', 0.42 ],
			'regwall_direct'     => [ 'get_regwall_conversion_direct', 'gates_regwall_conversion_direct', 'regwall_conversion_rate_direct', 0.18, 'rate', 0.18 ],
			'regwall_influenced' => [ 'get_regwall_conversion_influenced_7d', 'gates_regwall_conversion_influenced_7d', 'regwall_conversion_influenced', 0.31, 'rate', 0.31 ],
		];
	}

	/**
	 * Scorecards fall back to placeholder on proxy error.
	 *
	 * @dataProvider provide_scorecard_method_names
	 * @param string $method Method on Gates_Metric to call.
	 */
	public function test_scorecard_falls_back_to_placeholder_on_error( string $method ) {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( new \WP_Error( 'bigquery_query_failed', 'BQ down' ) );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->$method( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertTrue( $result['pending'] );
		$this->assertFalse( $result['computable'] );
	}

	/**
	 * Data provider for scorecard method names.
	 *
	 * @return array
	 */
	public function provide_scorecard_method_names(): array {
		return [
			[ 'get_total_gate_impressions' ],
			[ 'get_unique_readers_reached' ],
			[ 'get_avg_exposures_per_reader' ],
			[ 'get_sessions_with_gate' ],
			[ 'get_regwall_conversion_direct' ],
			[ 'get_regwall_conversion_influenced_7d' ],
		];
	}

	/**
	 * Section 1 scorecards fall back to placeholder on empty rows.
	 */
	public function test_section_1_falls_back_on_empty_rows() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( [] );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_total_gate_impressions( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertTrue( $result['pending'] );
	}

	/**
	 * Section 1 scorecards fall back to placeholder when the catalog returns a non-numeric value.
	 *
	 * Covers the `! is_numeric()` branch in `compute_metric_from_proxy()`. This protects
	 * against upstream catalog drift where a builder might return a string or null.
	 */
	public function test_section_1_falls_back_on_non_numeric_value() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( [ [ 'gate_impressions' => 'banana' ] ] );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_total_gate_impressions( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertTrue( $result['pending'] );
		$this->assertFalse( $result['computable'] );
	}

	/**
	 * Section 1 count metrics fall back when the catalog returns a non-integer numeric value.
	 *
	 * A `count` should always be a whole number; a float (e.g. 3.7) indicates upstream
	 * catalog drift and is rejected to surface the contract break rather than silently
	 * truncate.
	 */
	public function test_section_1_count_metric_rejects_non_integer() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( [ [ 'gate_impressions' => 3.7 ] ] );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_total_gate_impressions( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertTrue( $result['pending'] );
	}
}
