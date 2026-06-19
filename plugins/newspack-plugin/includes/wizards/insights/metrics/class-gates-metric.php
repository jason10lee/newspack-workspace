<?php
/**
 * Newspack Insights — Gates Metric orchestrator (NPPD-1604).
 *
 * Dispatches catalog queries to the Newspack Manager BigQuery proxy and shapes
 * each result for the React layer. Every method reports an explicit `state`:
 *   - 'error'     — the proxy/query failed (carries `error_code` + `error_message`)
 *   - 'empty'     — the query succeeded but returned no rows
 *   - 'populated' — the query returned usable data
 *
 * This replaces the earlier `pending: true` flag, which collapsed proxy errors,
 * legitimately-empty results, and malformed responses into one ambiguous
 * "No data yet" state and masked real failures. Scalar scorecards use
 * 'error' | 'populated' only ('empty' has no meaning for a single value — an
 * absent value renders as a non-computable zero).
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;
use DateTimeZone;
use Newspack\Insights\BigQuery_Proxy_Client;
use Newspack\Insights\Woo_Order_Resolver;

/**
 * Tab 4 metric orchestrator.
 *
 * @phpstan-type ScalarMetric array{
 *   state: string,
 *   value: int|float,
 *   computable: bool,
 *   denominator: int|null,
 *   numerator: int|null,
 *   placeholder_type: string,
 *   error_code?: string,
 *   error_message?: string,
 * }
 */
final class Gates_Metric {

	/**
	 * Cache key prefix. Bumped when the response shape changes so
	 * cached payloads from a prior shape don't break a deploy.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'newspack_insights_tab4_v1:';

	/**
	 * Proxy client used to dispatch catalog queries to the hub.
	 *
	 * @var BigQuery_Proxy_Client
	 */
	private BigQuery_Proxy_Client $proxy;

	/**
	 * Resolver used to match BQ paywall attempts against Woo orders.
	 *
	 * @var Woo_Order_Resolver
	 */
	private Woo_Order_Resolver $woo_resolver;

	/**
	 * Per-request memoization for `fetch_paywall_direct_woo_join`.
	 *
	 * Keyed by `Ymd|Ymd` of the (start, end) UTC dates. The two revenue methods
	 * (`get_total_paywall_revenue_direct` and `get_avg_revenue_per_paywall_conversion`)
	 * both source from `gates_paywall_revenue_direct`; this cache avoids issuing
	 * two identical HTTP round-trips to the hub for the same window.
	 *
	 * @var array<string, array{rows:array, conversions:int, revenue:float}|\WP_Error>
	 */
	private array $paywall_direct_cache = [];

	/**
	 * Constructor. Optionally inject collaborators (used in tests).
	 *
	 * @param BigQuery_Proxy_Client|null $proxy        Injected proxy client, or null to lazy-resolve.
	 * @param Woo_Order_Resolver|null    $woo_resolver Injected Woo resolver, or null to lazy-create.
	 */
	public function __construct(
		?BigQuery_Proxy_Client $proxy = null,
		?Woo_Order_Resolver $woo_resolver = null
	) {
		$this->proxy        = $proxy ?? new BigQuery_Proxy_Client();
		$this->woo_resolver = $woo_resolver ?? new Woo_Order_Resolver();
	}

	/**
	 * Canned fixture payload for UI smoke testing without a BigQuery connection.
	 * Returned by the REST controller when NEWSPACK_INSIGHTS_FIXTURE_MODE is on.
	 * The variant selects a render path: 'populated' (default), 'empty', 'error'.
	 *
	 * @param string $variant One of 'populated', 'empty', 'error'.
	 * @param bool   $compare Whether comparison was requested; when false the
	 *                        `previous` window is null (no period-over-period deltas).
	 * @return array Full { tab_error, current, previous } response shape.
	 */
	public static function get_fixture( string $variant = 'populated', bool $compare = false ): array {
		$build = require NEWSPACK_ABSPATH . 'includes/wizards/insights/fixtures/gates-fixture.php';
		return $build( $variant, $compare );
	}

	/**
	 * Error payload for a scalar scorecard metric. Carries the proxy's error
	 * code + message so the UI can render an error treatment (without exposing
	 * internals to the reader) instead of a misleading zero.
	 *
	 * @param string    $placeholder_type One of 'count', 'rate', 'currency', 'decimal'.
	 * @param \WP_Error $error            The originating proxy error.
	 * @return array
	 */
	private function error_scalar( string $placeholder_type, \WP_Error $error ): array {
		return [
			'state'            => 'error',
			'value'            => 'decimal' === $placeholder_type ? 0.0 : 0,
			'computable'       => false,
			'denominator'      => null,
			'numerator'        => null,
			'placeholder_type' => $placeholder_type,
			'error_code'       => $error->get_error_code(),
			'error_message'    => $error->get_error_message(),
		];
	}

	/**
	 * Populated payload for a scalar scorecard metric. A successful query that
	 * yields no usable value is still 'populated' — it renders as a
	 * non-computable zero ('empty' has no meaning for a single scalar).
	 *
	 * @param int|float $value            Metric value.
	 * @param bool      $computable       Whether the value is a real computed figure.
	 * @param int|null  $denominator      Optional denominator.
	 * @param string    $placeholder_type One of 'count', 'rate', 'currency', 'decimal'.
	 * @param int|null  $numerator        Optional numerator (NPPD-1694). Surfaced only
	 *                                    for rate scorecards whose underlying count is
	 *                                    computed locally (the paywall Woo join); null
	 *                                    everywhere the count isn't available, e.g. the
	 *                                    precomputed-rate regwall cards.
	 * @return array
	 */
	private function populated_scalar( $value, bool $computable, ?int $denominator, string $placeholder_type, ?int $numerator = null ): array {
		return [
			'state'            => 'populated',
			'value'            => $value,
			'computable'       => $computable,
			'denominator'      => $denominator,
			'numerator'        => $numerator,
			'placeholder_type' => $placeholder_type,
		];
	}

	/**
	 * Error payload for a collection metric (funnel / distribution / table).
	 *
	 * @param string    $rows_key Key holding the (empty) collection: 'stages'|'buckets'|'rows'.
	 * @param \WP_Error $error    The originating proxy error.
	 * @return array
	 */
	private function error_collection( string $rows_key, \WP_Error $error ): array {
		return [
			'state'         => 'error',
			'error_code'    => $error->get_error_code(),
			'error_message' => $error->get_error_message(),
			$rows_key       => [],
		];
	}

	/**
	 * Error payload for a collection whose query succeeded but returned an
	 * unexpected (non-array) shape — a data-quality bug, not an empty window.
	 *
	 * @param string $rows_key Key holding the (empty) collection: 'stages'|'buckets'|'rows'.
	 * @return array
	 */
	private function malformed_collection( string $rows_key ): array {
		return $this->error_collection(
			$rows_key,
			new \WP_Error( 'bigquery_proxy_malformed_rows', __( 'The query returned an unexpected shape.', 'newspack-plugin' ) )
		);
	}

	/**
	 * Run a scalar catalog query and extract a single value from the first row.
	 *
	 * A proxy WP_Error becomes state 'error'. A successful query with no usable
	 * value (empty rows, missing key, non-numeric, or count drift) becomes a
	 * 'populated' non-computable zero.
	 *
	 * @param string            $query_name        Catalog `query_name`.
	 * @param string            $row_key           Column to extract from the first row.
	 * @param string            $placeholder_type  'count' | 'rate' | 'currency' | 'decimal'.
	 * @param DateTimeInterface $start             Window start.
	 * @param DateTimeInterface $end               Window end.
	 * @return array
	 */
	private function compute_metric_from_proxy(
		string $query_name,
		string $row_key,
		string $placeholder_type,
		DateTimeInterface $start,
		DateTimeInterface $end
	): array {
		$rows = $this->proxy->query( $query_name, $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_scalar( $placeholder_type, $rows );
		}
		$zero = 'decimal' === $placeholder_type ? 0.0 : 0;
		if ( empty( $rows ) || ! is_array( $rows[0] ) || ! array_key_exists( $row_key, $rows[0] ) ) {
			// Query succeeded with no usable value → non-computable zero.
			return $this->populated_scalar( $zero, false, null, $placeholder_type );
		}
		$value = $rows[0][ $row_key ];
		// SAFE_DIVIDE returns NULL when the denominator is zero — a legitimate
		// "no eligible events to compute a rate" case, not a schema regression.
		// Same handling as the missing-key branch above: non-computable zero.
		if ( null === $value ) {
			return $this->populated_scalar( $zero, false, null, $placeholder_type );
		}
		// Non-numeric, or (for counts) a non-integer value, signals catalog/schema
		// drift — malformed data, not an empty window. Surface it as an error so a
		// real data-quality regression isn't masked as a benign zero.
		if ( ! is_numeric( $value ) || ( 'count' === $placeholder_type && (float) $value !== (float) (int) $value ) ) {
			return $this->error_scalar(
				$placeholder_type,
				new \WP_Error( 'bigquery_proxy_malformed_value', __( 'The query returned a non-numeric value.', 'newspack-plugin' ) )
			);
		}
		return $this->populated_scalar( 'count' === $placeholder_type ? (int) $value : (float) $value, true, null, $placeholder_type );
	}

	/**
	 * Compute a regwall conversion rate from a precomputed hub rate, optionally
	 * surfacing the new count fields the empty-state pattern needs (NPPD-1702).
	 *
	 * Unlike the paywall rate (computed locally from a Woo join), the regwall rate
	 * is precomputed server-side by the hub. This method reads that rate exactly as
	 * `compute_metric_from_proxy` would, then *additionally* reads two integer
	 * columns the hub query will start returning once Derrick's Newspack Manager
	 * change ships: `registration_impressions_total` (the denominator / {N}) and
	 * `registrations_total` (the numerator).
	 *
	 * The production-safety crux: those columns do not exist in the hub response
	 * yet. When they are absent, this returns numerator + denominator as `null` —
	 * byte-for-byte today's regwall envelope — so the React layer renders the
	 * percentage scorecards and no empty state (graceful degradation). An absent
	 * field is NOT a zero: a present `0` is a real "no impressions" signal, a
	 * missing field is "the hub hasn't deployed yet." The two are kept distinct all
	 * the way to the component. The fields are read as a pair: a half-populated
	 * response (one present, one absent/non-numeric) is treated as absent so a
	 * malformed envelope degrades rather than half-renders a count fallback.
	 *
	 * @param string            $query_name Catalog name (`gates_regwall_conversion_*`).
	 * @param string            $rate_key   Column holding the precomputed rate.
	 * @param DateTimeInterface $start      Window start.
	 * @param DateTimeInterface $end        Window end.
	 * @return array
	 */
	private function compute_regwall_rate_from_proxy(
		string $query_name,
		string $rate_key,
		DateTimeInterface $start,
		DateTimeInterface $end
	): array {
		$rows = $this->proxy->query( $query_name, $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_scalar( 'rate', $rows );
		}
		if ( empty( $rows ) || ! is_array( $rows[0] ) || ! array_key_exists( $rate_key, $rows[0] ) ) {
			// Query succeeded with no usable value → non-computable zero. Mirrors
			// compute_metric_from_proxy exactly, including the int 0 it uses for a
			// non-decimal placeholder (a rate). Counts stay null: nothing to surface.
			return $this->populated_scalar( 0, false, null, 'rate' );
		}
		$row   = $rows[0];
		$value = $row[ $rate_key ];
		// SAFE_DIVIDE NULL: legitimate "no eligible events", non-computable zero.
		if ( null === $value ) {
			return $this->populated_scalar( 0, false, null, 'rate' );
		}
		// Non-numeric rate signals catalog/schema drift — surface as an error so a
		// real data-quality regression isn't masked as a benign zero.
		if ( ! is_numeric( $value ) ) {
			return $this->error_scalar(
				'rate',
				new \WP_Error( 'bigquery_proxy_malformed_value', __( 'The query returned a non-numeric value.', 'newspack-plugin' ) )
			);
		}
		// Read the new count columns iff BOTH are present and integer-valued.
		// Absent (pre-hub-deploy) → null counts → today's envelope, today's render.
		$impressions = $this->read_optional_count( $row, 'registration_impressions_total' );
		$registrations = $this->read_optional_count( $row, 'registrations_total' );
		if ( null === $impressions || null === $registrations ) {
			return $this->populated_scalar( (float) $value, true, null, 'rate' );
		}
		return $this->populated_scalar( (float) $value, true, $impressions, 'rate', $registrations );
	}

	/**
	 * Read an optional non-negative integer column from a proxy row (NPPD-1702).
	 *
	 * Returns the int when the key is present and integer-valued (a float like 3.7
	 * is rejected as catalog drift), or `null` when the key is absent or not a
	 * clean integer. Distinguishing "absent" from "0" is the whole point: callers
	 * use `null` to mean "the hub hasn't deployed this field yet."
	 *
	 * @param array  $row Proxy row.
	 * @param string $key Column name.
	 * @return int|null
	 */
	private function read_optional_count( array $row, string $key ): ?int {
		if ( ! array_key_exists( $key, $row ) || null === $row[ $key ] ) {
			return null;
		}
		$raw = $row[ $key ];
		if ( ! is_numeric( $raw ) || (float) $raw !== (float) (int) $raw ) {
			return null;
		}
		return (int) $raw;
	}

	/**
	 * Compute a paywall conversion rate from BQ rows + Woo completion join.
	 *
	 * @param string            $query_name Catalog name (`gates_paywall_conversion_*`).
	 * @param DateTimeInterface $start      Window start.
	 * @param DateTimeInterface $end        Window end.
	 * @return array
	 */
	private function compute_paywall_rate_from_proxy(
		string $query_name,
		DateTimeInterface $start,
		DateTimeInterface $end
	): array {
		$rows = $this->proxy->query( $query_name, $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_scalar( 'rate', $rows );
		}
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			// No paywall attempts in the window → a real 0% rate. Numerator and
			// denominator are both an explicit 0 so the card's count fallback can
			// tell "no attempts" (denominator 0) from "0 of N" (NPPD-1694).
			return $this->populated_scalar( 0.0, false, 0, 'rate', 0 );
		}
		$denominator = count( $rows );
		$numerator   = $this->woo_resolver->count_completed_orders( $rows );
		// Surface the numerator (matched Woo orders) alongside the denominator so
		// the card can render "0 of {denominator}" when no attempt converted.
		return $this->populated_scalar( $denominator > 0 ? $numerator / $denominator : 0.0, true, $denominator, 'rate', $numerator );
	}

	/**
	 * Fetch paywall direct rows and return matched-order count + summed revenue.
	 *
	 * Used by both `get_total_paywall_revenue_direct` and
	 * `get_avg_revenue_per_paywall_conversion` (derived).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{rows:array, conversions:int, revenue:float}|\WP_Error WP_Error on proxy failure.
	 */
	private function fetch_paywall_direct_woo_join(
		DateTimeInterface $start,
		DateTimeInterface $end
	) {
		// Normalize both bounds to UTC Ymd so callers passing different timezone
		// objects don't bust the cache for the same logical window. Matches the
		// proxy client's own UTC normalization.
		$utc       = new \DateTimeZone( 'UTC' );
		$cache_key = \DateTimeImmutable::createFromInterface( $start )->setTimezone( $utc )->format( 'Ymd' )
			. '|'
			. \DateTimeImmutable::createFromInterface( $end )->setTimezone( $utc )->format( 'Ymd' );

		if ( array_key_exists( $cache_key, $this->paywall_direct_cache ) ) {
			return $this->paywall_direct_cache[ $cache_key ];
		}

		$rows = $this->proxy->query( 'gates_paywall_revenue_direct', $start, $end );
		if ( is_wp_error( $rows ) ) {
			$this->paywall_direct_cache[ $cache_key ] = $rows;
			return $rows;
		}

		// A successful but empty response is real "no conversions", not an error:
		// zero conversions / zero revenue, which the callers render as $0.00.
		$rows   = is_array( $rows ) ? $rows : [];
		$result = [
			'rows'        => $rows,
			'conversions' => $this->woo_resolver->count_completed_orders( $rows ),
			'revenue'     => $this->woo_resolver->sum_completed_revenue( $rows ),
		];
		$this->paywall_direct_cache[ $cache_key ] = $result;
		return $result;
	}

	// --- Section 1: Gate exposure ---------------------------------------

	/**
	 * Total gate impressions in window.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_total_gate_impressions( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'gates_total_impressions', 'gate_impressions', 'count', $start, $end );
	}

	/**
	 * Unique readers who saw at least one gate.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_unique_readers_reached( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'gates_unique_viewers', 'unique_gate_viewers', 'count', $start, $end );
	}

	/**
	 * Average gate exposures per reader.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_avg_exposures_per_reader( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'gates_avg_exposures_per_reader', 'avg_exposures_per_reader', 'decimal', $start, $end );
	}

	/**
	 * Percentage of sessions that hit at least one gate.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_sessions_with_gate( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'gates_sessions_with_gate', 'pct_sessions_with_gate', 'rate', $start, $end );
	}

	// --- Section 2: Free reader conversion ------------------------------

	/**
	 * Regwall conversion rate, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_regwall_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_regwall_rate_from_proxy( 'gates_regwall_conversion_direct', 'regwall_conversion_rate_direct', $start, $end );
	}

	/**
	 * Regwall conversion rate, influenced (7-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_regwall_conversion_influenced_7d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_regwall_rate_from_proxy( 'gates_regwall_conversion_influenced_7d', 'regwall_conversion_influenced', $start, $end );
	}

	// --- Section 3: Paid reader conversion ------------------------------

	/**
	 * Paywall conversion rate, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_paywall_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_paywall_rate_from_proxy( 'gates_paywall_conversion_direct', $start, $end );
	}

	/**
	 * Paywall conversion rate, influenced (14-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_paywall_conversion_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_paywall_rate_from_proxy( 'gates_paywall_conversion_influenced_14d', $start, $end );
	}

	/**
	 * Total revenue from paywall conversions, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_total_paywall_revenue_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paywall_direct_woo_join( $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'currency', $joined );
		}
		return $this->populated_scalar( $joined['revenue'], true, $joined['conversions'], 'currency' );
	}

	/**
	 * Average revenue per paywall conversion.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_avg_revenue_per_paywall_conversion( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paywall_direct_woo_join( $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'currency', $joined );
		}
		$conversions = $joined['conversions'];
		// No conversions → a real $0.00 average, flagged non-computable.
		return $this->populated_scalar(
			$conversions > 0 ? $joined['revenue'] / $conversions : 0.0,
			$conversions > 0,
			$conversions,
			'currency'
		);
	}

	/**
	 * Paid-section totals for the empty-state gate (NPPD-1694).
	 *
	 * Pure derivation from already-computed scalars — no extra query. The
	 * section component reads these to choose between the normal scorecard
	 * render and an `<EmptyMetricSection>`:
	 *   - `paywall_attempts_total` = the Direct rate denominator (sessions with a
	 *     paywall impression; this is the {N} in the "no conversions" copy).
	 *   - `paywall_conversions_total` = the most inclusive conversion count across
	 *     attributions (max of Direct and Influenced numerators), so a section
	 *     with Influenced-only conversions still renders its scorecards rather
	 *     than hiding real data behind a "no conversions" empty state.
	 *
	 * @param array $direct     The `paywall_conversion_direct` scalar payload.
	 * @param array $influenced The `paywall_conversion_influenced_14d` scalar payload.
	 * @return array{paywall_attempts_total:int, paywall_conversions_total:int}
	 */
	public static function paywall_section_totals( array $direct, array $influenced ): array {
		return [
			'paywall_attempts_total'    => (int) ( $direct['denominator'] ?? 0 ),
			'paywall_conversions_total' => max(
				(int) ( $direct['numerator'] ?? 0 ),
				(int) ( $influenced['numerator'] ?? 0 )
			),
		];
	}

	/**
	 * Free-section totals for the empty-state gate (NPPD-1702).
	 *
	 * The Free counterpart to `paywall_section_totals`, with one deliberate and
	 * load-bearing difference: it returns `int|null`, never coercing a missing
	 * count to `0`. `null` means "the hub hasn't deployed the count fields yet"
	 * (the `compute_regwall_rate_from_proxy` numerator/denominator are null in that
	 * case); the React layer reads it to *degrade to today's percentage render*
	 * rather than show a false `no_opportunity`. Coercing to 0 here — as the
	 * paywall helper does, where the denominator always exists because it's
	 * computed locally — would silently break a working production section the
	 * moment this ships, with no hub change involved. An absent field is not a zero.
	 *
	 *   - `registration_impressions_total` = the Direct rate denominator (sessions
	 *     with a registration gate impression; the {N} in the "no registrations"
	 *     copy). Mirrors paywall keying attempts off the Direct denominator.
	 *   - `registrations_total` = the most inclusive registration count across
	 *     attributions (max of Direct and Influenced numerators), so a section with
	 *     Influenced-only registrations still renders its scorecards. `null` only
	 *     when neither attribution carries a count (fields absent).
	 *
	 * @param array $direct     The `regwall_conversion_direct` scalar payload.
	 * @param array $influenced The `regwall_conversion_influenced_7d` scalar payload.
	 * @return array{registration_impressions_total:int|null, registrations_total:int|null}
	 */
	public static function regwall_section_totals( array $direct, array $influenced ): array {
		// `isset` is intentional: it treats a present-but-null denominator the same
		// as a missing key — both mean "no count from the hub" → null. A present 0
		// passes through as 0 (a real "no impressions" signal, NOT degradation).
		$impressions = isset( $direct['denominator'] ) ? (int) $direct['denominator'] : null;

		$direct_regs     = $direct['numerator'] ?? null;
		$influenced_regs = $influenced['numerator'] ?? null;
		$registrations   = ( null === $direct_regs && null === $influenced_regs )
			? null
			: max( (int) $direct_regs, (int) $influenced_regs );

		return [
			'registration_impressions_total' => $impressions,
			'registrations_total'            => $registrations,
		];
	}

	// --- Section 4: How readers convert ---------------------------------

	/**
	 * Conversion funnel — three ordered stages (impression → engagement →
	 * conversion). Returns state: 'error' (proxy failure / malformed row),
	 * 'empty' (query succeeded, no rows), or 'populated' (stages with counts).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{
	 *   state: string,
	 *   stages: array<int, array{label: string, count: int, pct_of_top: float}>,
	 *   error_code?: string,
	 *   error_message?: string
	 * }
	 */
	public function get_conversion_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'gates_funnel', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'stages', $rows );
		}
		if ( ! is_array( $rows ) || ( ! empty( $rows ) && ! is_array( $rows[0] ) ) ) {
			// Successful response in an unexpected shape — a data-quality bug, not
			// a legitimately empty window. Surface it as an error.
			return $this->malformed_collection( 'stages' );
		}
		if ( empty( $rows ) ) {
			return [
				'state'  => 'empty',
				'stages' => [],
			];
		}
		$row   = $rows[0];
		$step1 = (int) ( $row['step_1_impression'] ?? 0 );
		$step2 = (int) ( $row['step_2_engagement'] ?? 0 );
		$step3 = (int) ( $row['step_3_conversion'] ?? 0 );
		$top   = $step1 > 0 ? $step1 : 1; // Avoid div-by-zero in pct_of_top.
		return [
			'state'  => 'populated',
			'stages' => [
				[
					'label'      => __( 'Impression', 'newspack-plugin' ),
					'count'      => $step1,
					'pct_of_top' => 1.0,
				],
				[
					'label'      => __( 'Engagement', 'newspack-plugin' ),
					'count'      => $step2,
					'pct_of_top' => $step2 / $top,
				],
				[
					'label'      => __( 'Conversion', 'newspack-plugin' ),
					'count'      => $step3,
					'pct_of_top' => $step3 / $top,
				],
			],
		];
	}

	/**
	 * Exposures-before-conversion distribution buckets.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{
	 *   state: string,
	 *   buckets: array<int, array{label: string, count: int, pct: float}>,
	 *   error_code?: string,
	 *   error_message?: string
	 * }
	 */
	public function get_exposures_distribution( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'gates_exposures_before_conversion', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'buckets', $rows );
		}
		if ( ! is_array( $rows ) ) {
			return $this->malformed_collection( 'buckets' );
		}

		$by_bucket = [];
		foreach ( $rows as $row ) {
			if ( isset( $row['bucket'] ) ) {
				$by_bucket[ $row['bucket'] ] = [
					'count' => (int) ( $row['converters_in_bucket'] ?? 0 ),
					'pct'   => (float) ( $row['pct_of_converters'] ?? 0.0 ),
				];
			}
		}

		if ( empty( $by_bucket ) ) {
			return [
				'state'   => 'empty',
				'buckets' => [],
			];
		}

		$ordered_keys   = [ '1', '2', '3-5', '6+' ];
		$ordered_labels = [
			'1'   => __( '1 exposure', 'newspack-plugin' ),
			'2'   => __( '2 exposures', 'newspack-plugin' ),
			'3-5' => __( '3–5 exposures', 'newspack-plugin' ),
			'6+'  => __( '6+ exposures', 'newspack-plugin' ),
		];
		$buckets        = [];
		foreach ( $ordered_keys as $key ) {
			$buckets[] = [
				'label' => $ordered_labels[ $key ],
				'count' => $by_bucket[ $key ]['count'] ?? 0,
				'pct'   => $by_bucket[ $key ]['pct'] ?? 0.0,
			];
		}
		return [
			'state'   => 'populated',
			'buckets' => $buckets,
		];
	}

	// --- Section 5: Performance by gate ---------------------------------

	/**
	 * Per-gate breakdown, enriched server-side with each gate's
	 * `wp_posts.post_title` keyed on `gate_post_id`. Returns state: 'error'
	 * (proxy failure / malformed), 'empty' (no rows), or 'populated'.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, rows: array, error_code?: string, error_message?: string}
	 */
	public function get_performance_by_gate( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'gates_performance_by_gate', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'rows', $rows );
		}
		if ( ! is_array( $rows ) ) {
			return $this->malformed_collection( 'rows' );
		}
		if ( empty( $rows ) ) {
			return [
				'state' => 'empty',
				'rows'  => [],
			];
		}

		$mapped = [];
		foreach ( $rows as $row ) {
			$gate_post_id = (int) ( $row['gate_post_id'] ?? 0 );
			$mapped[]     = [
				'gate_post_id'            => $gate_post_id,
				'gate_name'               => null, // filled below by enrich_with_gate_titles().
				'impressions'             => (int) ( $row['impressions'] ?? 0 ),
				'unique_viewers'          => (int) ( $row['unique_viewers'] ?? 0 ),
				'registrations'           => (int) ( $row['registrations'] ?? 0 ),
				'regwall_conversion_rate' => isset( $row['regwall_conversion_rate'] ) && null !== $row['regwall_conversion_rate'] ? (float) $row['regwall_conversion_rate'] : null,
				'paywall_attempts'        => (int) ( $row['paywall_attempts'] ?? 0 ),
				'paywall_attempt_rate'    => isset( $row['paywall_attempt_rate'] ) && null !== $row['paywall_attempt_rate'] ? (float) $row['paywall_attempt_rate'] : null,
			];
		}

		return [
			'state' => 'populated',
			'rows'  => $this->enrich_with_gate_titles( $mapped ),
		];
	}

	/**
	 * Enrich performance rows with the `post_title` of each `gate_post_id`.
	 *
	 * @param array $rows Rows containing `gate_post_id` int.
	 * @return array Rows with `gate_name` filled in.
	 */
	private function enrich_with_gate_titles( array $rows ): array {
		$ids = array_filter( array_unique( array_column( $rows, 'gate_post_id' ) ) );
		if ( empty( $ids ) ) {
			return $rows;
		}
		// Use `get_post()` per unique ID rather than `get_posts( post_type => 'any' )`:
		// the popup CPT (`newspack_popups_cpt`) is registered with
		// `exclude_from_search = true`, which excludes it from the `'any'` query.
		// `get_post()` works regardless of post-type registration and is cached
		// by `WP_Object_Cache`, so repeat lookups in the same request are free.
		$titles = [];
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post && '' !== $post->post_title ) {
				$titles[ $id ] = $post->post_title;
			}
		}
		foreach ( $rows as &$row ) {
			$id               = $row['gate_post_id'];
			$row['gate_name'] = isset( $titles[ $id ] )
				? $titles[ $id ]
				/* translators: %d is a Newspack popup post ID. */
				: sprintf( __( 'Gate #%d', 'newspack-plugin' ), $id );
		}
		unset( $row );
		return $rows;
	}
}
