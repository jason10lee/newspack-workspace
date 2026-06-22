<?php
/**
 * Newspack Insights — Tab 3 Conversion Journey REST controller (NPPD-1609, Phase 2).
 *
 * Single endpoint: `GET /newspack-insights/v1/conversion`. Same date-arg
 * validation, permission check, and date-parsing conventions as
 * {@see Prompts_REST_Controller} — Tab 3 mirrors the per-surface tabs'
 * request/response lifecycle exactly.
 *
 * Response shape (outer cache envelope):
 *   cache:  { source, computed_at, cooldown_until } — BigQuery cache metadata.
 *   data:   ConversionPayload
 *
 * ConversionPayload:
 *   tab_error:   bool          — true only when every section in the current
 *                                window failed to load; React renders a
 *                                tab-level error banner.
 *   current:     ConversionWindow — the eight sections' 23 metric payloads.
 *   previous:    ConversionWindow | null — only populated when the request
 *                                passes `compare_start` + `compare_end`.
 *                                Only Section 7 renders the deltas.
 *
 * Each metric from {@see Conversion_Metric} carries its own `state`
 * ('error' | 'empty' | 'populated' | 'coming_soon'); sections render their
 * own treatments, so the tab banner is reserved for the all-failed case.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Conversion Journey REST controller.
 */
class Conversion_REST_Controller extends WP_REST_Controller {

	use Cached_Controller_Trait;

	/**
	 * Shared Insights namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'newspack-insights/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'conversion';

	/**
	 * Cache source classification for this controller.
	 *
	 * @return string
	 */
	protected function cache_source(): string {
		return Cache::SOURCE_BIGQUERY;
	}

	/**
	 * Tab slug used as the cache namespace.
	 *
	 * @return string
	 */
	protected function tab_slug(): string {
		return 'conversion';
	}

	/**
	 * Register the Tab 3 routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_conversion_data' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => $this->get_collection_params(),
				],
			]
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/refresh',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'refresh_conversion_data' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => $this->get_collection_params(),
				],
			]
		);
	}

	/**
	 * Permission check.
	 *
	 * This route is intentionally callable via application passwords. The
	 * BQ-side rate limit is the 10-minute cooldown enforced in
	 * {@see Cache::refresh()}, not a per-route rate limiter — any caller
	 * authenticated as a user with `manage_options` (whether via cookie +
	 * nonce or an application password) can trigger a refresh, and the
	 * cooldown applies uniformly.
	 *
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'newspack_insights_rest_forbidden',
				__( 'You do not have permission to view Insights data.', 'newspack-plugin' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * GET handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_conversion_data( WP_REST_Request $request ) {
		// Dev smoke-test path: serve canned fixture data so the UI renders without
		// a BigQuery proxy connection. The optional _fixture_state param selects a
		// render path ('populated' | 'empty' | 'error'). Never enable in production.
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			$parsed = $this->parse_window_args( $request );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			[ , , $compare_start, $compare_end ] = $parsed;
			$variant  = (string) ( $request->get_param( '_fixture_state' ) ?? 'populated' );
			$compare  = null !== $compare_start && null !== $compare_end;
			$response = rest_ensure_response(
				[
					'cache' => [
						'source'         => Cache::SOURCE_LOCAL,
						'computed_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
						'cooldown_until' => null,
					],
					'data'  => Conversion_Metric::get_fixture( $variant, $compare ),
				]
			);
			$response->header( 'Cache-Control', 'no-store, private' );
			return $response;
		}

		$parsed = $this->parse_window_args( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		[ $start, $end, $compare_start, $compare_end ] = $parsed;

		$metric = new Conversion_Metric();
		return $this->cached_response(
			$request,
			function () use ( $metric, $start, $end, $compare_start, $compare_end ) {
				return $this->build_response( $metric, $start, $end, $compare_start, $compare_end );
			}
		);
	}

	/**
	 * POST /conversion/refresh handler — bypass cache and recompute.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function refresh_conversion_data( WP_REST_Request $request ) {
		// Fixture mode: delegate to GET so refresh is a no-op cache bypass.
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			return $this->get_conversion_data( $request );
		}
		$parsed = $this->parse_window_args( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		[ $start, $end, $compare_start, $compare_end ] = $parsed;
		$metric = new Conversion_Metric();
		return $this->refresh_response(
			$request,
			function () use ( $metric, $start, $end, $compare_start, $compare_end ) {
				return $this->build_response( $metric, $start, $end, $compare_start, $compare_end );
			}
		);
	}

	/**
	 * Validate and parse the window args. Returns [start, end, compare_start, compare_end] on
	 * success; WP_Error on validation failure.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array|WP_Error
	 */
	private function parse_window_args( WP_REST_Request $request ) {
		$tz = $this->site_timezone();
		try {
			$start = $this->parse_date( $request->get_param( 'start' ), $tz, false );
			$end   = $this->parse_date( $request->get_param( 'end' ), $tz, true );
		} catch ( Exception $e ) {
			return new WP_Error( 'newspack_insights_invalid_date', $e->getMessage(), [ 'status' => 400 ] );
		}
		if ( $start > $end ) {
			return new WP_Error(
				'newspack_insights_invalid_window',
				__( 'Start date must be on or before end date.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}

		$compare_start_param = $request->get_param( 'compare_start' );
		$compare_end_param   = $request->get_param( 'compare_end' );
		$compare_start       = null;
		$compare_end         = null;
		if ( $compare_start_param || $compare_end_param ) {
			if ( ! $compare_start_param || ! $compare_end_param ) {
				return new WP_Error(
					'newspack_insights_invalid_comparison',
					__( 'Both compare_start and compare_end must be provided to enable comparison mode.', 'newspack-plugin' ),
					[ 'status' => 400 ]
				);
			}
			try {
				$compare_start = $this->parse_date( $compare_start_param, $tz, false );
				$compare_end   = $this->parse_date( $compare_end_param, $tz, true );
			} catch ( Exception $e ) {
				return new WP_Error( 'newspack_insights_invalid_date', $e->getMessage(), [ 'status' => 400 ] );
			}
			if ( $compare_start > $compare_end ) {
				return new WP_Error(
					'newspack_insights_invalid_comparison_window',
					__( 'compare_start must be on or before compare_end.', 'newspack-plugin' ),
					[ 'status' => 400 ]
				);
			}
		}

		return [ $start, $end, $compare_start, $compare_end ];
	}

	/**
	 * Assemble the top-level response.
	 *
	 * `tab_error` is true only when every **hub-backed** metric in the current
	 * window reports `state: 'error'` — i.e. the hub (BigQuery proxy) is
	 * down/misconfigured. Local (Woo order-meta / storage-layer) cards survive a
	 * hub outage and do not suppress the banner (NPPD-1745; see
	 * {@see self::is_window_all_error()}). React renders a tab-level error banner
	 * in that case; otherwise each section renders its own error/empty/populated
	 * treatment.
	 *
	 * Snapshot metrics (Section 5 cohorts, Sections 8.1–8.3) are
	 * current-state and window-independent, so compute them once and share
	 * the same payload across both windows. In comparison mode this avoids
	 * re-running them for `previous`, where they become the tab's most
	 * expensive Woo/BQ queries returning identical data.
	 *
	 * @param Conversion_Metric      $metric        Orchestrator.
	 * @param DateTimeImmutable      $start         Current window start.
	 * @param DateTimeImmutable      $end           Current window end.
	 * @param DateTimeImmutable|null $compare_start Prior window start.
	 * @param DateTimeImmutable|null $compare_end   Prior window end.
	 * @return array
	 */
	private function build_response(
		Conversion_Metric $metric,
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		?DateTimeImmutable $compare_start,
		?DateTimeImmutable $compare_end
	): array {
		// Snapshot metrics (Section 5 cohorts, Sections 8.1–8.3) are
		// current-state and window-independent, so compute them once and share
		// the same payload across both windows. In comparison mode this avoids
		// re-running them for `previous`, where (in Phase 2) they become the
		// tab's most expensive Woo/BQ queries returning identical data.
		$snapshot = $this->build_snapshot( $metric, $start, $end );

		$current  = $this->build_window( $metric, $start, $end ) + $snapshot;
		$response = [
			'tab_error' => self::is_window_all_error( $current, $metric->woocommerce_active() ),
			'current'   => $current,
			'previous'  => null,
		];
		if ( $compare_start && $compare_end ) {
			$response['previous'] = $this->build_window( $metric, $compare_start, $compare_end ) + $snapshot;
		}
		return $response;
	}

	/**
	 * Whether every **hub-backed** metric in a window reports `state: 'error'`.
	 *
	 * Scoped to hub-backed metrics (NPPD-1745): Tab 3 has many Woo-local cards
	 * (subscriber→donor funnel, opportunity counts, coming_soon stubs). Under the
	 * old "every metric errored" rule the banner could never fire when the hub is
	 * down — a local card always survives. So the banner now fires when all
	 * hub-backed (and hybrid-when-WC-active) metrics error, even though surviving
	 * local cards still render. The classification is declared explicitly on
	 * {@see \Newspack\Insights\Conversion_Metric::METRIC_SOURCES}.
	 *
	 * Returns `false` as soon as any hub-backed metric is not in the error state.
	 * Returns `false` for a window with no recognizable hub-backed metric at all
	 * (nothing to declare failed).
	 *
	 * NPPD-1745 (banner hole on non-WC): a `hybrid` card (local order-meta numerator
	 * over a hub denominator) short-circuits to a not-applicable empty state on a
	 * non-WooCommerce publisher BEFORE it ever calls the hub — so a hub outage
	 * can't make it error, and treating its surviving empty state as a healthy
	 * hub-backed card would mask the outage and suppress the banner. On a non-WC
	 * publisher, hybrid cards are therefore skipped (treated like `local`); the
	 * genuinely hub-backed cards (pure `hub`) still drive the decision.
	 *
	 * @param array $window             The shape returned by `build_window()` merged with `build_snapshot()`.
	 * @param bool  $woocommerce_active Whether WooCommerce is active for this publisher.
	 * @return bool
	 */
	private static function is_window_all_error( array $window, bool $woocommerce_active ): bool {
		$saw_hub_backed = false;
		foreach ( Conversion_Metric::METRIC_SOURCES as $key => $source ) {
			if ( 'local' === $source ) {
				continue;
			}
			// On a non-WC publisher a hybrid card never reaches the hub (it empties out
			// first), so it can't error on a hub outage — don't let its surviving empty
			// state mask the outage.
			if ( ! $woocommerce_active && 'hybrid' === $source ) {
				continue;
			}
			if ( ! isset( $window[ $key ] ) || ! is_array( $window[ $key ] ) || ! isset( $window[ $key ]['state'] ) ) {
				continue;
			}
			$saw_hub_backed = true;
			if ( 'error' !== $window[ $key ]['state'] ) {
				return false;
			}
		}
		return $saw_hub_backed;
	}

	/**
	 * Window-bound payload: the 18 metrics that depend on the selected window,
	 * plus the `window` echo. The 5 window-independent snapshot metrics are
	 * merged in by {@see self::build_response()} via {@see self::build_snapshot()}.
	 *
	 * @param Conversion_Metric $metric Orchestrator.
	 * @param DateTimeImmutable $start  Start.
	 * @param DateTimeImmutable $end    End.
	 * @return array
	 */
	private function build_window( Conversion_Metric $metric, DateTimeImmutable $start, DateTimeImmutable $end ): array {
		return [
			'window'                               => [
				'start' => $start->format( 'Y-m-d' ),
				'end'   => $end->format( 'Y-m-d' ),
			],
			// Section 1 — The reader lifecycle.
			'reader_lifecycle_funnel'              => $metric->get_reader_lifecycle_funnel( $start, $end ),
			// Section 2 — Per-journey conversion funnels.
			'anonymous_to_registered_funnel'       => $metric->get_anonymous_to_registered_funnel( $start, $end ),
			'registered_to_subscriber_funnel'      => $metric->get_registered_to_subscriber_funnel( $start, $end ),
			'registered_to_donor_funnel'           => $metric->get_registered_to_donor_funnel( $start, $end ),
			'subscriber_to_donor_funnel'           => $metric->get_subscriber_to_donor_funnel( $start, $end ),
			// Section 3 — Where conversions come from.
			'source_mix_registrations'             => $metric->get_source_mix_registrations( $start, $end ),
			'source_mix_subscribers'               => $metric->get_source_mix_subscribers( $start, $end ),
			'source_mix_donors'                    => $metric->get_source_mix_donors( $start, $end ),
			// Section 4 — How long conversions take (cumulative LineCharts).
			'time_to_register_distribution'        => $metric->get_time_to_register_distribution( $start, $end ),
			'time_to_subscribe_distribution'       => $metric->get_time_to_subscribe_distribution( $start, $end ),
			'time_to_donate_distribution'          => $metric->get_time_to_donate_distribution( $start, $end ),
			'subscriber_to_donor_lag_distribution' => $metric->get_subscriber_to_donor_lag_distribution( $start, $end ),
			// Section 6 — Conversion rate trends.
			'weekly_conversion_rates'              => $metric->get_weekly_conversion_rates( $start, $end ),
			// Section 7 — Cross-tab influenced attribution (comparison-enabled).
			'influenced_registration_rate_7d'      => $metric->get_influenced_registration_rate_7d( $start, $end ),
			'influenced_subscription_rate_14d'     => $metric->get_influenced_subscription_rate_14d( $start, $end ),
			'influenced_donation_rate_14d'         => $metric->get_influenced_donation_rate_14d( $start, $end ),
			'influenced_newsletter_rate_7d'        => $metric->get_influenced_newsletter_rate_7d( $start, $end ),
			// Section 8.4 — Top pages that don't convert (windowed table).
			'top_pages_no_conversion'              => $metric->get_top_pages_no_conversion( $start, $end ),
		];
	}

	/**
	 * Window-independent snapshot metrics: Section 5 cohort retention (5.1,
	 * 5.2) and Sections 8.1–8.3 opportunity counts. These are current-state —
	 * the orchestrator methods accept the window for signature parity but
	 * ignore it — so the controller computes them once and reuses the result
	 * for both the current and comparison windows.
	 *
	 * @param Conversion_Metric $metric Orchestrator.
	 * @param DateTimeImmutable $start  Current window start (passed for parity; ignored by the metrics).
	 * @param DateTimeImmutable $end    Current window end (passed for parity; ignored by the metrics).
	 * @return array
	 */
	private function build_snapshot( Conversion_Metric $metric, DateTimeImmutable $start, DateTimeImmutable $end ): array {
		return [
			// Section 5 — Cohort retention (snapshot).
			'registration_to_conversion_cohort' => $metric->get_registration_to_conversion_cohort( $start, $end ),
			'subscriber_retention_cohort'       => $metric->get_subscriber_retention_cohort( $start, $end ),
			// Sections 8.1–8.3 — Opportunity buckets (snapshot counts).
			'stale_registered_count'            => $metric->get_stale_registered_count( $start, $end ),
			'at_risk_subscriber_count'          => $metric->get_at_risk_subscriber_count( $start, $end ),
			'lapsed_donor_count'                => $metric->get_lapsed_donor_count( $start, $end ),
		];
	}

	/**
	 * Args spec.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$base = [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => [ $this, 'validate_date_string' ],
		];
		return [
			'start'         => array_merge(
				$base,
				[
					'description' => __( 'Inclusive window start date (YYYY-MM-DD, site timezone).', 'newspack-plugin' ),
					'required'    => true,
				]
			),
			'end'           => array_merge(
				$base,
				[
					'description' => __( 'Inclusive window end date (YYYY-MM-DD, site timezone).', 'newspack-plugin' ),
					'required'    => true,
				]
			),
			'compare_start' => array_merge(
				$base,
				[
					'description' => __( 'Optional comparison window start. Must pair with compare_end.', 'newspack-plugin' ),
					'required'    => false,
				]
			),
			'compare_end'   => array_merge(
				$base,
				[
					'description' => __( 'Optional comparison window end. Must pair with compare_start.', 'newspack-plugin' ),
					'required'    => false,
				]
			),
		];
	}

	/**
	 * REST validate_callback.
	 *
	 * @param mixed $value Value.
	 * @return bool|WP_Error
	 */
	public function validate_date_string( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return new WP_Error(
				'newspack_insights_invalid_date',
				__( 'Date must be a non-empty YYYY-MM-DD string.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d', $value, $this->site_timezone() );
		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $value ) {
			return new WP_Error(
				'newspack_insights_invalid_date',
				/* translators: %s: the invalid date string */
				sprintf( __( 'Invalid date "%s". Expected YYYY-MM-DD.', 'newspack-plugin' ), $value ),
				[ 'status' => 400 ]
			);
		}
		return true;
	}

	/**
	 * Parse a Y-m-d string into a DateTimeImmutable.
	 *
	 * @param mixed        $value      Raw value.
	 * @param DateTimeZone $tz         Timezone.
	 * @param bool         $end_of_day If true, 23:59:59; else 00:00:00.
	 * @return DateTimeImmutable
	 * @throws Exception On parse failure.
	 */
	private function parse_date( $value, DateTimeZone $tz, bool $end_of_day ): DateTimeImmutable {
		if ( ! is_string( $value ) || '' === $value ) {
			throw new Exception( esc_html__( 'Missing date value.', 'newspack-plugin' ) );
		}
		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d', $value, $tz );
		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $value ) {
			/* translators: %s: the invalid date string */
			throw new Exception( esc_html( sprintf( __( 'Invalid date "%s". Expected YYYY-MM-DD.', 'newspack-plugin' ), $value ) ) );
		}
		return $end_of_day ? $parsed->setTime( 23, 59, 59 ) : $parsed->setTime( 0, 0, 0 );
	}

	/**
	 * Site timezone.
	 *
	 * @return DateTimeZone
	 */
	private function site_timezone(): DateTimeZone {
		return wp_timezone();
	}
}
