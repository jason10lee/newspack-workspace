<?php
/**
 * Test Conversion_REST_Controller (NPPD-1609, Phase 2).
 *
 * Exercises the Tab 3 endpoint's request lifecycle: a valid window returns
 * 200 with the cache envelope wrapping the state envelope (Phase 2 replaces
 * the Phase 1 `tab_pending` placeholder with `tab_error`); comparison mode
 * adds a `previous` window; invalid / mismatched date params return 400; the
 * `/conversion/refresh` POST route is registered.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Cache;
use Newspack\Insights\Conversion_Metric;
use Newspack\Insights\Conversion_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Conversion_REST_Controller test class.
 *
 * @group insights
 */
class Test_Conversion_REST_Controller extends WP_UnitTestCase {

	const ROUTE = '/newspack-insights/v1/conversion';

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Set up: an admin user + a registered Conversion route on a fresh server.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// Register on the rest_api_init action (as production does) — calling
		// register_rest_route outside that action triggers a _doing_it_wrong notice.
		add_action( 'rest_api_init', [ $this, 'register_conversion_route' ] );

		global $wp_rest_server;
		$this->server   = new WP_REST_Server();
		$wp_rest_server = $this->server;
		do_action( 'rest_api_init' );

		// Wipe transients + cooldown markers so cache state doesn't leak between tests.
		Cache::purge( 'conversion' );
	}

	/**
	 * Register the Conversion route. Hooked to rest_api_init in set_up().
	 *
	 * @return void
	 */
	public function register_conversion_route() {
		( new Conversion_REST_Controller() )->register_routes();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_action( 'rest_api_init', [ $this, 'register_conversion_route' ] );
		global $wp_rest_server;
		$wp_rest_server = null;
		Cache::purge( 'conversion' );
		parent::tear_down();
	}

	/**
	 * Build + dispatch a GET request to the Conversion route.
	 *
	 * @param array $params Query params.
	 * @return \WP_REST_Response
	 */
	private function dispatch( array $params ) {
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * A valid window returns 200 with the cache envelope wrapping the state
	 * envelope. In the test env the BQ proxy is unconfigured — BQ-wired metrics
	 * surface `state: 'error'`, but the snapshot and coming_soon metrics report
	 * non-error states, so `tab_error` is false (not all metrics are 'error').
	 */
	public function test_valid_window_returns_200_envelope() {
		$response = $this->dispatch(
			[
				'start' => '2026-03-22',
				'end'   => '2026-04-21',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();

		// Outer cache envelope shape ({ cache, data }).
		$this->assertArrayHasKey( 'cache', $body );
		$this->assertArrayHasKey( 'data', $body );
		$this->assertSame( Cache::SOURCE_BIGQUERY, $body['cache']['source'] );
		$this->assertNotEmpty( $body['cache']['computed_at'] );
		$this->assertArrayHasKey( 'cooldown_until', $body['cache'] );

		$data = $body['data'];

		// Phase 2 replaces `tab_pending` with `tab_error`; the Phase 1 key must
		// not leak through to the wire format.
		$this->assertArrayNotHasKey( 'tab_pending', $data );
		$this->assertArrayHasKey( 'tab_error', $data );
		$this->assertArrayHasKey( 'current', $data );
		$this->assertNull( $data['previous'] );

		// Window echo + one representative metric per section is present.
		$current = $data['current'];
		$this->assertSame( '2026-03-22', $current['window']['start'] );
		$this->assertSame( '2026-04-21', $current['window']['end'] );
		foreach (
			[
				'reader_lifecycle_funnel',         // Section 1.
				'subscriber_to_donor_funnel',      // Section 2.
				'source_mix_registrations',        // Section 3.
				'time_to_register_distribution',   // Section 4.
				'subscriber_retention_cohort',     // Section 5.
				'weekly_conversion_rates',         // Section 6.
				'influenced_registration_rate_7d', // Section 7.
				'top_pages_no_conversion',         // Section 8.
			] as $key
		) {
			$this->assertArrayHasKey( $key, $current, "Missing window key: $key" );
		}

		// A wired scalar carries a state envelope (Phase 2). In the test env the
		// proxy is unconfigured, so BQ-wired scalars surface as state 'error'.
		$this->assertArrayHasKey( 'state', $current['influenced_registration_rate_7d'] );
		$this->assertArrayNotHasKey( 'pending', $current['influenced_registration_rate_7d'] );
		$this->assertSame( 'rate', $current['influenced_registration_rate_7d']['placeholder_type'] );

		// The opportunity table ships with state 'error' when BQ proxy unavailable.
		$this->assertSame( [], $current['top_pages_no_conversion']['rows'] );
	}

	/**
	 * The envelope carries all 23 metric keys (plus the window echo).
	 */
	public function test_window_carries_all_23_metric_keys() {
		$response = $this->dispatch(
			[
				'start' => '2026-03-22',
				'end'   => '2026-04-21',
			]
		);
		$current = $response->get_data()['data']['current'];

		// 23 metrics + the `window` echo.
		$this->assertCount( 24, $current );
	}

	/**
	 * `tab_error` is false when at least one metric has a non-error state
	 * (coming_soon or populated). Snapshot metrics (Section 5 cohorts, Sections
	 * 8.1–8.3) and coming_soon placeholders return non-error states, so the tab
	 * cannot be all-error even when BQ-wired metrics fail.
	 */
	public function test_tab_error_false_when_non_error_metrics_present() {
		$response = $this->dispatch(
			[
				'start' => '2026-03-22',
				'end'   => '2026-04-21',
			]
		);
		$data = $response->get_data()['data'];

		// The Conversion Journey tab has snapshot and coming_soon metrics that are
		// NOT 'error', so tab_error must be false even with a broken BQ proxy.
		$this->assertFalse( $data['tab_error'] );
	}

	/**
	 * `tab_error` is false when at least one metric has a non-error state.
	 * The Conversion Journey has coming_soon and snapshot (populated) metrics
	 * that always report non-error, so tab_error is always false in a real
	 * controller response even when the BQ proxy is unconfigured.
	 *
	 * We verify this by dispatching a real request and asserting that
	 * (a) tab_error is false, and (b) deferred sections carry 'coming_soon'.
	 */
	public function test_tab_error_false_for_coming_soon_and_populated_states() {
		$response = $this->dispatch(
			[
				'start' => '2026-03-22',
				'end'   => '2026-04-21',
			]
		);
		$data = $response->get_data()['data'];
		$this->assertFalse( $data['tab_error'], 'tab_error must be false when coming_soon/populated metrics are present' );

		// 5.1 cohort is still a coming_soon stub; the implemented 4.2 distribution
		// reports a non-error state (empty here, with no seeded data/BQ). Both keep
		// tab_error false.
		$current = $data['current'];
		$this->assertSame( 'coming_soon', $current['registration_to_conversion_cohort']['state'] );
		$this->assertSame( 'empty', $current['time_to_subscribe_distribution']['state'] );
	}

	/**
	 * Comparison mode (both compare params) adds a populated `previous`
	 * window with the same shape.
	 */
	public function test_comparison_mode_populates_previous() {
		$response = $this->dispatch(
			[
				'start'         => '2026-03-22',
				'end'           => '2026-04-21',
				'compare_start' => '2026-02-20',
				'compare_end'   => '2026-03-21',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data()['data'];

		$this->assertIsArray( $data['previous'] );
		$this->assertSame( '2026-02-20', $data['previous']['window']['start'] );
		$this->assertSame( '2026-03-21', $data['previous']['window']['end'] );
		$this->assertArrayHasKey( 'reader_lifecycle_funnel', $data['previous'] );
	}

	/**
	 * An unparseable date is rejected by the route's validate_callback.
	 */
	public function test_invalid_date_returns_400() {
		$response = $this->dispatch(
			[
				'start' => 'not-a-date',
				'end'   => '2026-04-21',
			]
		);
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Start after end is rejected by the handler.
	 */
	public function test_start_after_end_returns_400() {
		$response = $this->dispatch(
			[
				'start' => '2026-04-21',
				'end'   => '2026-03-22',
			]
		);
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * A lone comparison bound (start without end) is rejected.
	 */
	public function test_partial_comparison_returns_400() {
		$response = $this->dispatch(
			[
				'start'         => '2026-03-22',
				'end'           => '2026-04-21',
				'compare_start' => '2026-02-20',
			]
		);
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * An inverted comparison window (compare_start after compare_end) is rejected.
	 */
	public function test_inverted_comparison_window_returns_400() {
		$response = $this->dispatch(
			[
				'start'         => '2026-03-22',
				'end'           => '2026-04-21',
				'compare_start' => '2026-03-21',
				'compare_end'   => '2026-02-20',
			]
		);
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Fixture-mode: the controller invokes Conversion_Metric::get_fixture()
	 * (not build_response/live BQ) and wraps it in the SOURCE_LOCAL cache
	 * envelope with Cache-Control: no-store.
	 *
	 * We exercise get_fixture() directly to verify the fixture variant shapes;
	 * exercising via the controller would require redefining
	 * NEWSPACK_INSIGHTS_FIXTURE_MODE which can't be undefined once set.
	 */
	public function test_fixture_populated_variant_via_metric() {
		$payload = Conversion_Metric::get_fixture( 'populated', false );

		$this->assertFalse( $payload['tab_error'] );
		$this->assertNull( $payload['previous'] );

		$current = $payload['current'];
		$this->assertSame( 'populated', $current['reader_lifecycle_funnel']['state'] );
		$this->assertCount( 5, $current['reader_lifecycle_funnel']['stages'] );
		$this->assertSame( 'populated', $current['source_mix_registrations']['state'] );
		$this->assertSame( 'populated', $current['time_to_register_distribution']['state'] );
		$this->assertSame( 'populated', $current['weekly_conversion_rates']['state'] );
		$this->assertSame( 'populated', $current['influenced_registration_rate_7d']['state'] );
		$this->assertSame( 'populated', $current['top_pages_no_conversion']['state'] );

		// 4.2 is implemented (all-history snapshot → populated in the fixture);
		// 5.1/5.2 cohorts are still coming_soon stubs.
		$this->assertSame( 'populated', $current['time_to_subscribe_distribution']['state'] );
		$this->assertSame( 'coming_soon', $current['registration_to_conversion_cohort']['state'] );
		$this->assertSame( 'coming_soon', $current['subscriber_retention_cohort']['state'] );
	}

	/**
	 * Fixture-mode: SOURCE_LOCAL envelope shape — confirms the controller wraps
	 * get_fixture() in the correct cache envelope (cache.source = SOURCE_LOCAL,
	 * cooldown_until = null, Cache-Control: no-store).
	 *
	 * Tested via Conversion_Metric::get_fixture() shape; a controller-level
	 * assertion would require NEWSPACK_INSIGHTS_FIXTURE_MODE which is not
	 * resettable per-test.
	 */
	public function test_fixture_returns_source_local_envelope_shape() {
		// Confirm that the static fixture delegation produces the correct data shape
		// that the controller would wrap in { cache: { source: SOURCE_LOCAL, ... }, data: ... }.
		$payload = Conversion_Metric::get_fixture( 'populated', false );
		$this->assertArrayHasKey( 'tab_error', $payload );
		$this->assertArrayHasKey( 'current', $payload );
		$this->assertArrayHasKey( 'previous', $payload );

		// The fixture payload is the `data` the controller wraps in a SOURCE_LOCAL
		// cache envelope; assert the shape the wrapper depends on. The envelope
		// itself can't be asserted here because NEWSPACK_INSIGHTS_FIXTURE_MODE is
		// not resettable per-test.
		$this->assertIsArray( $payload['current'] );
		$this->assertArrayHasKey( 'reader_lifecycle_funnel', $payload['current'] );
	}

	/**
	 * Fixture-mode empty variant: collections are 'empty', scalars are non-computable zeros.
	 */
	public function test_fixture_empty_variant_via_metric() {
		$payload = Conversion_Metric::get_fixture( 'empty', false );

		$this->assertFalse( $payload['tab_error'] );
		$current = $payload['current'];

		$this->assertSame( 'empty', $current['reader_lifecycle_funnel']['state'] );
		$this->assertSame( [], $current['reader_lifecycle_funnel']['stages'] );
		$this->assertSame( 'populated', $current['influenced_registration_rate_7d']['state'] );
		$this->assertFalse( $current['influenced_registration_rate_7d']['computable'] );

		// 4.2 is an all-history snapshot → populated in the fixture regardless of
		// the window variant (5.1/5.2 cohorts remain coming_soon).
		$this->assertSame( 'populated', $current['time_to_subscribe_distribution']['state'] );
	}

	/**
	 * Fixture-mode error variant: BQ metrics are 'error', local-only metrics
	 * stay 'populated', deferred stay 'coming_soon'.
	 */
	public function test_fixture_error_variant_via_metric() {
		$payload = Conversion_Metric::get_fixture( 'error', false );

		// tab_error is false because snapshot + deferred are non-error.
		$this->assertFalse( $payload['tab_error'] );
		$current = $payload['current'];

		$this->assertSame( 'error', $current['reader_lifecycle_funnel']['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $current['reader_lifecycle_funnel']['error_code'] );
		$this->assertSame( 'populated', $current['stale_registered_count']['state'] );
		$this->assertSame( 'coming_soon', $current['registration_to_conversion_cohort']['state'] );
	}

	/**
	 * Fixture-mode compare: previous window is populated when requested.
	 */
	public function test_fixture_compare_populates_previous() {
		$payload = Conversion_Metric::get_fixture( 'populated', true );

		$this->assertIsArray( $payload['previous'] );
		$this->assertArrayHasKey( 'reader_lifecycle_funnel', $payload['previous'] );
		$this->assertSame( 'populated', $payload['previous']['reader_lifecycle_funnel']['state'] );
	}

	/**
	 * The refresh route mirrors the GET route's envelope shape and is
	 * registered alongside it. The route accepts POST (Cached_Controller_Trait
	 * uses WP_REST_Server::CREATABLE).
	 */
	public function test_refresh_route_returns_cache_envelope() {
		$request = new WP_REST_Request( 'POST', self::ROUTE . '/refresh' );
		$request->set_param( 'start', '2026-03-22' );
		$request->set_param( 'end', '2026-04-21' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertArrayHasKey( 'cache', $body );
		$this->assertArrayHasKey( 'data', $body );
		$this->assertSame( Cache::SOURCE_BIGQUERY, $body['cache']['source'] );
		// First refresh seeds the BQ cooldown stamp so the React layer can render the throttle UI.
		$this->assertNotEmpty( $body['cache']['cooldown_until'] );
		$this->assertArrayHasKey( 'tab_error', $body['data'] );
	}
}
