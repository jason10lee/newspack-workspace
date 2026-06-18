<?php
/**
 * Newspack Insights — Prompts Metric orchestrator (NPPD-1607, Phase 2).
 *
 * Dispatches catalog queries to the Newspack Manager BigQuery proxy and shapes
 * each result for the React layer. Every method reports an explicit `state`:
 *   - 'error'     — the proxy/query failed (carries `error_code` + `error_message`)
 *   - 'empty'     — the query succeeded but returned no rows (collections only)
 *   - 'populated' — the query returned usable data
 *
 * This replaces the Phase 1 `pending: true` flag, which collapsed proxy errors,
 * legitimately-empty results, and malformed responses into one ambiguous
 * "No data yet" state and masked real failures. Scalar scorecards use
 * 'error' | 'populated' only ('empty' has no meaning for a single value — an
 * absent value renders as a non-computable zero).
 *
 * Mirrors {@see Gates_Metric} (Tab 4) one-for-one. Tab 5 carries four conversion
 * intents (registration, newsletter signup, donation, subscription) versus
 * Gates' two, so it has more scorecards and a three-table performance
 * breakdown — but the per-metric envelope is identical.
 *
 * Phase 2 wiring is now complete in this class: 10 scalar metrics (Task 3.1),
 * 8 paid conversion + revenue metrics joined via {@see Woo_Order_Resolver}
 * (Task 3.2), and 5 collection metrics — conversion funnel, exposures
 * distribution, and three performance breakdown tables (Task 3.3). The
 * performance-by-prompt table additionally augments each row with per-popup
 * donation and subscription conversion counts using a Woo join scoped to the
 * popup's intent; that augmentation degrades gracefully (engagement columns
 * still render with zeros in the Woo columns) if the conversion-attempt query
 * fails.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;
use Newspack\Insights\BigQuery_Proxy_Client;
use Newspack\Insights\Woo_Order_Resolver;

/**
 * Tab 5 metric orchestrator.
 *
 * @phpstan-type ScalarMetric array{
 *   state: string,
 *   value: int|float,
 *   computable: bool,
 *   denominator: int|null,
 *   placeholder_type: string,
 *   error_code?: string,
 *   error_message?: string,
 * }
 */
final class Prompts_Metric {

	/**
	 * Cache key prefix. Bumped when the response shape changes so
	 * cached payloads from a prior shape don't break a deploy.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'newspack_insights_tab5_v1:';

	/**
	 * Display labels for each `action_type` (intent) value. Spec §7.2 prescribes
	 * title-cased labels with `newsletters_subscription` rendered as the more
	 * publisher-friendly "Newsletter signup" (the auto-ucwords result —
	 * "Newsletters Subscription" — is awkward). Centralized so the per-intent
	 * table and any future intent display agree.
	 *
	 * @var array<string, string>
	 */
	private const INTENT_LABELS = [
		'donation'                 => 'Donation',
		'registration'             => 'Registration',
		'newsletters_subscription' => 'Newsletter signup',
	];

	/**
	 * Conversion blocks scanned by the per-intent capability gate (NPPD-1720),
	 * keyed by capability. A prompt is "capable" of an intent when any active
	 * prompt contains the matching block. Mirrors the three blocks newspack-popups
	 * watches in `Newspack_Popups_Data_Api::get_popup_metadata()` plus
	 * `newspack-blocks/checkout-button`, which that map omits — without it,
	 * checkout-button membership prompts would read as informational.
	 *
	 * @var array<string, string>
	 */
	private const WATCHED_BLOCKS = [
		'registration' => 'newspack/reader-registration',
		'donation'     => 'newspack-blocks/donate',
		'newsletter'   => 'newspack-newsletters/subscribe',
		'checkout'     => 'newspack-blocks/checkout-button',
	];

	/**
	 * Prompt CPT slug (newspack-popups' `Newspack_Popups::NEWSPACK_POPUPS_CPT`).
	 * Hardcoded so the capability scan can enumerate published prompts via core
	 * `get_posts()` without depending on the popups plugin class being loadable,
	 * and without the write side effect `retrieve_popups()` carries.
	 *
	 * @var string
	 */
	private const POPUPS_CPT = 'newspack_popups_cpt';

	/**
	 * Maps each conversion-tied scalar metric to the capability that gates it
	 * (NPPD-1720). `form_submission_rate` is capable when any *form-bearing* block
	 * is present ('form_bearing' = registration / donation / newsletter signup);
	 * checkout-button is a click-through to a checkout page, not an inline form, so
	 * it's excluded here to match the form-bearing nudge copy. Every other metric
	 * gates on its specific block. Non-conversion metrics (exposure, CTR, dismissal,
	 * the funnel, the tables) are absent here — they carry no `has_capability` flag
	 * and always render as long as there are prompts at all.
	 *
	 * @var array<string, string>
	 */
	private const METRIC_CAPABILITY = [
		'form_submission_rate'                       => 'form_bearing',
		'registration_conversion_direct'             => 'registration',
		'registration_conversion_influenced_7d'      => 'registration',
		'newsletter_signup_conversion_direct'        => 'newsletter',
		'newsletter_signup_conversion_influenced_7d' => 'newsletter',
		'donation_conversion_direct'                 => 'donation',
		'donation_conversion_influenced_14d'         => 'donation',
		'subscription_conversion_direct'             => 'checkout',
		'subscription_conversion_influenced_14d'     => 'checkout',
		'donation_revenue_direct'                    => 'donation',
		'donation_revenue_influenced_14d'            => 'donation',
		'subscription_revenue_direct'                => 'checkout',
		'subscription_revenue_influenced_14d'        => 'checkout',
	];

	/**
	 * Proxy client used to dispatch catalog queries to the hub.
	 *
	 * @var BigQuery_Proxy_Client
	 */
	private BigQuery_Proxy_Client $proxy;

	/**
	 * Resolver used to match BQ paid-conversion attempts against Woo orders.
	 *
	 * @var Woo_Order_Resolver
	 */
	private Woo_Order_Resolver $woo_resolver;

	/**
	 * Per-request memoization for paid-conversion BQ row fetches.
	 *
	 * Keyed by `<query_name>|Ymd|Ymd` of (query_name, start UTC, end UTC). The
	 * conversion-rate and revenue methods for the same intent + same window
	 * share one round-trip to the hub.
	 *
	 * @var array<string, array{rows:array, conversions:int, revenue:float}|\WP_Error>
	 */
	private array $paid_attempt_cache = [];

	/**
	 * Per-request memoization for the prompt-capability scan (NPPD-1720). Null
	 * until the first `compute_prompt_capabilities()` call; thereafter the four
	 * capability booleans are reused across both windows and all 13 gated metrics
	 * so the active-prompt enumeration runs at most once per request.
	 *
	 * @var array<string, bool>|null
	 */
	private ?array $prompt_capabilities = null;

	/**
	 * Active-prompt provider (test seam, NPPD-1720). When null, prompt content is
	 * read from published prompt CPT posts via `get_posts()`. Tests inject a
	 * callable returning canned `post_content` strings so the detector can be
	 * exercised without loading newspack-popups. Mirrors the proxy / Woo-resolver DI.
	 *
	 * @var callable|null
	 */
	private $prompt_provider;

	/**
	 * Constructor. Optionally inject collaborators (used in tests).
	 *
	 * @param BigQuery_Proxy_Client|null $proxy           Injected proxy client, or null to lazy-resolve.
	 * @param Woo_Order_Resolver|null    $woo_resolver    Injected Woo resolver, or null to lazy-create.
	 * @param callable|null              $prompt_provider Injected active-prompt provider (test seam),
	 *                                                    or null to read published prompt content via get_posts().
	 */
	public function __construct(
		?BigQuery_Proxy_Client $proxy = null,
		?Woo_Order_Resolver $woo_resolver = null,
		?callable $prompt_provider = null
	) {
		$this->proxy           = $proxy ?? new BigQuery_Proxy_Client();
		$this->woo_resolver    = $woo_resolver ?? new Woo_Order_Resolver();
		$this->prompt_provider = $prompt_provider;
	}

	/**
	 * Return the canned fixture payload for the Prompts tab.
	 *
	 * Returned by the REST controller when NEWSPACK_INSIGHTS_FIXTURE_MODE is on.
	 * The variant selects a render path: 'populated' (default), 'empty', 'error',
	 * 'not_capable' (NPPD-1720), 'not_computable' (NPPD-1704).
	 *
	 * @param string $variant One of 'populated', 'empty', 'error', 'not_capable',
	 *                        'not_computable'.
	 * @param bool   $compare Whether comparison was requested; when false the
	 *                        `previous` window is null (no period-over-period deltas).
	 * @return array Full { tab_error, current, previous } response shape.
	 */
	public static function get_fixture( string $variant = 'populated', bool $compare = false ): array {
		$build = require NEWSPACK_ABSPATH . 'includes/wizards/insights/fixtures/prompts-fixture.php';
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
	 * @return array
	 */
	private function populated_scalar( $value, bool $computable, ?int $denominator, string $placeholder_type ): array {
		return [
			'state'            => 'populated',
			'value'            => $value,
			'computable'       => $computable,
			'denominator'      => $denominator,
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
	 * Detect which conversion intents the publisher's active prompts can drive
	 * (NPPD-1720). Enumerates published prompts and tests each for the four
	 * watched conversion blocks; an intent is "capable" once any prompt carries
	 * its block. Result is memoized per request.
	 *
	 * Uses raw block presence via `has_block()` rather than newspack-popups'
	 * `action_type`, which collapses a multi-block prompt to 'undefined' (see the
	 * `@TODO: How to handle prompts with more than one block?` in
	 * `class-newspack-popups-data-api.php`); a prompt with both a donate and a
	 * subscribe block must register as capable of both.
	 *
	 * "Active" = `post_status = 'publish'`. No `frequency` filter is applied: an
	 * audit of real publishers found no published prompts left in `frequency =
	 * 'never'` test mode, so it would add complexity without changing results.
	 *
	 * Fails open: if newspack-popups isn't active the prompt CPT isn't registered
	 * and the prompts can't be inspected, so every intent is assumed capable rather
	 * than falsely gating every conversion card on a misconfiguration.
	 *
	 * @return array<string, bool> Capability keyed by 'registration'|'donation'|'newsletter'|'checkout'.
	 */
	private function compute_prompt_capabilities(): array {
		if ( null !== $this->prompt_capabilities ) {
			return $this->prompt_capabilities;
		}

		// Fail open when prompts can't be inspected (popups inactive → CPT absent,
		// and no injected provider): assume every intent is possible rather than
		// falsely gating every conversion card on a misconfiguration. Keyed on the
		// CPT registration rather than an empty query so an inactive plugin reads as
		// "can't tell" (all-capable), not "no conversions possible" (all-gated).
		if ( null === $this->prompt_provider && ! post_type_exists( self::POPUPS_CPT ) ) {
			$this->prompt_capabilities = array_fill_keys( array_keys( self::WATCHED_BLOCKS ), true );
			return $this->prompt_capabilities;
		}

		$capabilities = array_fill_keys( array_keys( self::WATCHED_BLOCKS ), false );
		// Blocks still to look for; entries drop out as soon as one is found so
		// later prompts skip the already-satisfied checks.
		$remaining = self::WATCHED_BLOCKS;

		foreach ( $this->get_active_prompt_contents() as $content ) {
			if ( empty( $remaining ) ) {
				break; // Every capability satisfied — stop scanning prompts.
			}
			if ( '' === $content ) {
				continue;
			}
			foreach ( $remaining as $capability => $block_name ) {
				if ( \has_block( $block_name, $content ) ) {
					$capabilities[ $capability ] = true;
					unset( $remaining[ $capability ] );
				}
			}
		}

		$this->prompt_capabilities = $capabilities;
		return $capabilities;
	}

	/**
	 * Raw block markup of every active (published) prompt.
	 *
	 * Reads via a plain `get_posts()` on the prompt CPT + `get_post_field()` rather
	 * than `Newspack_Popups_Model::retrieve_popups()` on purpose: that method routes
	 * through `deprecate_test_never_manual()`, which writes post meta and can demote
	 * legacy `never`/`test` prompts to draft — a write side effect we must not
	 * trigger from a read-only analytics scan (and which would fire on a cached GET).
	 * The direct query also drops retrieve_popups()'s 1000-prompt cap and its unused
	 * taxonomy hydration.
	 *
	 * Tests inject a provider that returns canned content strings (newspack-popups
	 * isn't loaded in the unit suite).
	 *
	 * @return string[] `post_content` for each published prompt.
	 */
	private function get_active_prompt_contents(): array {
		if ( null !== $this->prompt_provider ) {
			return ( $this->prompt_provider )();
		}

		$ids = get_posts(
			[
				'post_type'        => self::POPUPS_CPT,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			]
		);

		return array_map(
			static function ( $id ) {
				return (string) get_post_field( 'post_content', $id );
			},
			$ids
		);
	}

	/**
	 * Per-metric capability flags for the Prompts envelope (NPPD-1720). Maps each
	 * of the 13 conversion-tied scalar keys to a boolean the REST controller
	 * stamps onto the metric. `form_submission_rate` is capable when any form-bearing
	 * block is present; every other metric gates on its specific block.
	 *
	 * @return array<string, bool> Metric key => has_capability.
	 */
	public function get_capability_flags(): array {
		$capabilities = $this->compute_prompt_capabilities();
		// Form-bearing = blocks that submit an inline form (registration / donation /
		// newsletter signup). Checkout-button is excluded — it's a click-through to a
		// checkout page, not a form — matching the form-bearing nudge copy.
		$form_bearing = $capabilities['registration'] || $capabilities['donation'] || $capabilities['newsletter'];

		$flags = [];
		foreach ( self::METRIC_CAPABILITY as $metric_key => $capability ) {
			$flags[ $metric_key ] = 'form_bearing' === $capability
				? $form_bearing
				: ( $capabilities[ $capability ] ?? false );
		}
		return $flags;
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
	 * Fetch paid-conversion rows for a given query and Woo-join them.
	 *
	 * Returns { rows, conversions, revenue } on success or a WP_Error on proxy
	 * failure. Used by both the conversion-rate and revenue methods for the
	 * same intent + direction; the per-(query_name, window) memoization avoids
	 * a redundant round-trip to the hub when both methods run in one request.
	 *
	 * @param string            $query_name One of the 4 distinct paid query names
	 *                                      (the 4 revenue aliases share rows with
	 *                                      their conversion counterparts; pass the
	 *                                      conversion name so the cache is shared).
	 * @param DateTimeInterface $start      Window start.
	 * @param DateTimeInterface $end        Window end.
	 * @return array{rows:array, conversions:int, revenue:float}|\WP_Error
	 */
	private function fetch_paid_attempts_woo_join(
		string $query_name,
		DateTimeInterface $start,
		DateTimeInterface $end
	) {
		// Normalize both bounds to UTC Ymd so callers passing different timezone
		// objects don't bust the cache for the same logical window. Matches the
		// proxy client's own UTC normalization.
		$utc       = new \DateTimeZone( 'UTC' );
		$cache_key = $query_name . '|'
			. \DateTimeImmutable::createFromInterface( $start )->setTimezone( $utc )->format( 'Ymd' )
			. '|'
			. \DateTimeImmutable::createFromInterface( $end )->setTimezone( $utc )->format( 'Ymd' );

		if ( array_key_exists( $cache_key, $this->paid_attempt_cache ) ) {
			return $this->paid_attempt_cache[ $cache_key ];
		}

		$rows = $this->proxy->query( $query_name, $start, $end );
		if ( is_wp_error( $rows ) ) {
			$this->paid_attempt_cache[ $cache_key ] = $rows;
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
		$this->paid_attempt_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Compute per-popup Woo-completed counts for an intent's attempt rows.
	 *
	 * Fetches the intent's BQ attempt rows (cached via fetch_paid_attempts_woo_join),
	 * groups them by popup_id, and returns a map<popup_id, completed_count>.
	 * Returns an empty map on proxy failure so the per-prompt breakdown still
	 * renders the engagement columns even if the Woo-join augmentation fails —
	 * the engagement data is the load-bearing part of the table; the Woo
	 * augmentation is a bonus.
	 *
	 * @param string            $query_name Catalog `query_name` (e.g. 'prompts_donation_conversion_direct').
	 * @param DateTimeInterface $start      Window start.
	 * @param DateTimeInterface $end        Window end.
	 * @return array<int, int> popup_id => completed_count
	 */
	private function fetch_per_popup_woo_counts(
		string $query_name,
		DateTimeInterface $start,
		DateTimeInterface $end
	): array {
		$joined = $this->fetch_paid_attempts_woo_join( $query_name, $start, $end );
		if ( is_wp_error( $joined ) ) {
			return []; // Graceful degradation: render zeros, not error the whole table.
		}
		$by_popup = [];
		foreach ( $joined['rows'] as $row ) {
			$popup_id = (int) ( $row['popup_id'] ?? 0 );
			if ( $popup_id <= 0 ) {
				continue;
			}
			$by_popup[ $popup_id ][] = $row;
		}
		$counts = [];
		foreach ( $by_popup as $popup_id => $rows_for_popup ) {
			$counts[ $popup_id ] = $this->woo_resolver->count_completed_orders( $rows_for_popup );
		}
		return $counts;
	}

	// --- Section 1: Prompt exposure -------------------------------------

	/**
	 * Total prompt impressions in window.
	 *
	 * Dispatches `prompts_total_impressions`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_total_prompt_impressions( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_total_impressions', 'prompt_impressions', 'count', $start, $end );
	}

	/**
	 * Unique readers who saw at least one prompt.
	 *
	 * Dispatches `prompts_unique_viewers`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_unique_readers_reached( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_unique_viewers', 'unique_prompt_viewers', 'count', $start, $end );
	}

	/**
	 * Average prompt exposures per reader.
	 *
	 * Dispatches `prompts_avg_prompts_per_reader`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_avg_prompts_per_reader( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_avg_prompts_per_reader', 'avg_prompts_per_reader', 'decimal', $start, $end );
	}

	// --- Section 2: Prompt engagement -----------------------------------

	/**
	 * Click-through rate (clicks ÷ impressions).
	 *
	 * Dispatches `prompts_click_through_rate`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_click_through_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_click_through_rate', 'click_through_rate', 'rate', $start, $end );
	}

	/**
	 * Form submission rate (submissions ÷ form-bearing impressions).
	 *
	 * Dispatches `prompts_form_submission_rate`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_form_submission_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_form_submission_rate', 'form_submission_rate', 'rate', $start, $end );
	}

	/**
	 * Dismissal rate (explicit dismissals ÷ impressions).
	 *
	 * Dispatches `prompts_dismissal_rate`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_dismissal_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_dismissal_rate', 'dismissal_rate', 'rate', $start, $end );
	}

	// --- Section 3: Free reader conversion ------------------------------

	/**
	 * Registration conversion rate, direct attribution.
	 *
	 * Dispatches `prompts_registration_conversion_direct`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_registration_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_registration_conversion_direct', 'registration_conversion_direct', 'rate', $start, $end );
	}

	/**
	 * Registration conversion rate, influenced (7-day lookback).
	 *
	 * Dispatches `prompts_registration_conversion_influenced_7d`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_registration_conversion_influenced_7d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_registration_conversion_influenced_7d', 'registration_conversion_influenced', 'rate', $start, $end );
	}

	/**
	 * Newsletter signup conversion rate, direct attribution.
	 *
	 * Dispatches `prompts_newsletter_signup_conversion_direct`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_newsletter_signup_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_newsletter_signup_conversion_direct', 'newsletter_signup_conversion_direct', 'rate', $start, $end );
	}

	/**
	 * Newsletter signup conversion rate, influenced (7-day lookback).
	 *
	 * Dispatches `prompts_newsletter_signup_conversion_influenced_7d`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_newsletter_signup_conversion_influenced_7d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_newsletter_signup_conversion_influenced_7d', 'newsletter_signup_conversion_influenced', 'rate', $start, $end );
	}

	// --- Section 4: Paid reader conversion ------------------------------
	//
	// Each rate dispatches the BQ "attempt" query for the intent + direction,
	// Woo-joins the rows via Woo_Order_Resolver, and returns
	// `conversions / attempts`. The BQ-side `action_type` filter on the hub
	// already scopes attempts to the right product intent (donation vs
	// subscription), so the resolver's product-type-agnostic match is safe.
	//
	// v1 simplification: we use attempts as the denominator (not impressions).
	// The spec acknowledges this as a known trade-off (see formulas-doc
	// Section 4). The follow-up is a separate impression-count query; not
	// blocking for this task.

	/**
	 * Donation conversion rate, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donation_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paid_attempts_woo_join( 'prompts_donation_conversion_direct', $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'rate', $joined );
		}
		$denominator = count( $joined['rows'] );
		$numerator   = $joined['conversions'];
		return $this->populated_scalar(
			$denominator > 0 ? $numerator / $denominator : 0.0,
			$denominator > 0,
			$denominator,
			'rate'
		);
	}

	/**
	 * Donation conversion rate, influenced (14-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donation_conversion_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paid_attempts_woo_join( 'prompts_donation_conversion_influenced_14d', $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'rate', $joined );
		}
		$denominator = count( $joined['rows'] );
		$numerator   = $joined['conversions'];
		return $this->populated_scalar(
			$denominator > 0 ? $numerator / $denominator : 0.0,
			$denominator > 0,
			$denominator,
			'rate'
		);
	}

	/**
	 * Subscription conversion rate, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscription_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paid_attempts_woo_join( 'prompts_subscription_conversion_direct', $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'rate', $joined );
		}
		$denominator = count( $joined['rows'] );
		$numerator   = $joined['conversions'];
		return $this->populated_scalar(
			$denominator > 0 ? $numerator / $denominator : 0.0,
			$denominator > 0,
			$denominator,
			'rate'
		);
	}

	/**
	 * Subscription conversion rate, influenced (14-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscription_conversion_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paid_attempts_woo_join( 'prompts_subscription_conversion_influenced_14d', $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'rate', $joined );
		}
		$denominator = count( $joined['rows'] );
		$numerator   = $joined['conversions'];
		return $this->populated_scalar(
			$denominator > 0 ? $numerator / $denominator : 0.0,
			$denominator > 0,
			$denominator,
			'rate'
		);
	}

	// --- Section 5: Revenue from prompts --------------------------------
	//
	// Each revenue method dispatches the underlying CONVERSION query name (not
	// the byte-identical hub `*_revenue_*` alias) so the per-window cache is
	// shared with the matching rate method — both can be computed from one
	// proxy round-trip when they appear in the same response.

	/**
	 * Total donation revenue from prompts, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donation_revenue_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paid_attempts_woo_join( 'prompts_donation_conversion_direct', $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'currency', $joined );
		}
		return $this->populated_scalar( $joined['revenue'], true, $joined['conversions'], 'currency' );
	}

	/**
	 * Total donation revenue from prompts, influenced (14-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donation_revenue_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paid_attempts_woo_join( 'prompts_donation_conversion_influenced_14d', $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'currency', $joined );
		}
		return $this->populated_scalar( $joined['revenue'], true, $joined['conversions'], 'currency' );
	}

	/**
	 * Total subscription revenue from prompts, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscription_revenue_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paid_attempts_woo_join( 'prompts_subscription_conversion_direct', $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'currency', $joined );
		}
		return $this->populated_scalar( $joined['revenue'], true, $joined['conversions'], 'currency' );
	}

	/**
	 * Total subscription revenue from prompts, influenced (14-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscription_revenue_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paid_attempts_woo_join( 'prompts_subscription_conversion_influenced_14d', $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'currency', $joined );
		}
		return $this->populated_scalar( $joined['revenue'], true, $joined['conversions'], 'currency' );
	}

	// --- Section 6: How readers convert ---------------------------------

	/**
	 * Conversion funnel — three stages (impression → engagement → conversion).
	 *
	 * Dispatches `prompts_funnel` and normalizes the single-row response into a
	 * stage list with each stage's count and proportion-of-top-stage.
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
		$rows = $this->proxy->query( 'prompts_funnel', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'stages', $rows );
		}
		if ( ! is_array( $rows ) || ( ! empty( $rows ) && ! is_array( $rows[0] ) ) ) {
			// Successful response in an unexpected shape — a data-quality bug,
			// not a legitimately empty window. Surface it as an error.
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
	 * Dispatches `prompts_exposures_before_conversion`. The hub emits one row
	 * per non-zero bucket; we always render the full ordered set so missing
	 * buckets show as zero rather than disappearing.
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
		$rows = $this->proxy->query( 'prompts_exposures_before_conversion', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'buckets', $rows );
		}
		if ( ! is_array( $rows ) ) {
			return $this->malformed_collection( 'buckets' );
		}

		$by_bucket = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// Hub returned a row in an unexpected shape — a data-quality bug,
				// not a legitimately empty bucket. Surface as malformed so the
				// regression isn't masked as missing buckets.
				return $this->malformed_collection( 'buckets' );
			}
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

	// --- Section 7: Performance breakdown -------------------------------

	/**
	 * Per-prompt breakdown, augmented with per-popup Woo-completed donation and
	 * subscription counts (intent-scoped: donation_conversions are non-zero
	 * only for `intent === 'donation'` rows; subscription_conversions only for
	 * `intent === 'registration'` rows — subscription-intent prompts share the
	 * registration `action_type` in the data model, see spec §"Subscription-
	 * intent vs registration-intent prompts").
	 *
	 * The Woo augmentation degrades gracefully: if either conversion-attempt
	 * query fails, the matching column renders as 0 conversions / null rate
	 * (React renders null as the em-dash) rather than blanking the whole table.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, rows: array, error_code?: string, error_message?: string}
	 */
	public function get_performance_by_prompt( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'prompts_performance_by_prompt', $start, $end );
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

		// Fetch per-popup Woo augmentation once for each direction; the cache
		// in fetch_paid_attempts_woo_join means this is free if the matching
		// paid-rate / revenue method has already run this request.
		$donation_counts     = $this->fetch_per_popup_woo_counts( 'prompts_donation_conversion_direct', $start, $end );
		$subscription_counts = $this->fetch_per_popup_woo_counts( 'prompts_subscription_conversion_direct', $start, $end );

		$mapped = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// Hub returned a row in an unexpected shape — a data-quality bug,
				// not a legitimately empty table. Surface as malformed so the
				// regression isn't masked as missing prompts.
				return $this->malformed_collection( 'rows' );
			}
			$popup_id    = (int) ( $row['popup_id'] ?? 0 );
			$intent      = (string) ( $row['intent'] ?? '' );
			$impressions = (int) ( $row['impressions'] ?? 0 );

			$donation_conversions = 'donation' === $intent ? (int) ( $donation_counts[ $popup_id ] ?? 0 ) : 0;
			// Subscription-intent prompts share `action_type=registration` at
			// the data layer; the Woo product-type filter on the hub-side
			// subscription query already scopes attempts correctly.
			$subscription_conversions = 'registration' === $intent ? (int) ( $subscription_counts[ $popup_id ] ?? 0 ) : 0;

			// When the popup's intent matches but the Woo-side proxy failed (degraded),
			// the rate is a real 0.0 — the impression denominator is still applicable;
			// the null branch covers only intent mismatch (the column is N/A).
			$mapped[] = [
				'popup_id'                     => $popup_id,
				'prompt_title'                 => (string) ( $row['prompt_title'] ?? '' ),
				'intent'                       => $intent,
				'placement'                    => (string) ( $row['placement'] ?? '' ),
				'impressions'                  => $impressions,
				'unique_viewers'               => (int) ( $row['unique_viewers'] ?? 0 ),
				'ctr'                          => isset( $row['ctr'] ) && null !== $row['ctr'] ? (float) $row['ctr'] : null,
				'form_submission_rate'         => isset( $row['form_submission_rate'] ) && null !== $row['form_submission_rate'] ? (float) $row['form_submission_rate'] : null,
				'dismissal_rate'               => isset( $row['dismissal_rate'] ) && null !== $row['dismissal_rate'] ? (float) $row['dismissal_rate'] : null,
				'registrations'                => (int) ( $row['registrations'] ?? 0 ),
				'newsletter_signups'           => (int) ( $row['newsletter_signups'] ?? 0 ),
				'donation_conversions'         => $donation_conversions,
				'donation_conversion_rate'     => ( 'donation' === $intent && $impressions > 0 ) ? $donation_conversions / $impressions : null,
				'subscription_conversions'     => $subscription_conversions,
				'subscription_conversion_rate' => ( 'registration' === $intent && $impressions > 0 ) ? $subscription_conversions / $impressions : null,
			];
		}

		return [
			'state' => 'populated',
			'rows'  => $mapped,
		];
	}

	/**
	 * Per-intent breakdown (donation / registration / newsletter signup).
	 *
	 * Pure BQ → row mapping; no Woo or WP enrichment. Intent labels come from
	 * {@see self::INTENT_LABELS}; an unknown `intent` value falls back to its
	 * raw form so a hub-side catalog change isn't silently swallowed.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, rows: array, error_code?: string, error_message?: string}
	 */
	public function get_performance_by_intent( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'prompts_performance_by_intent', $start, $end );
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
			if ( ! is_array( $row ) ) {
				// Hub returned a row in an unexpected shape — surface as malformed.
				return $this->malformed_collection( 'rows' );
			}
			$intent   = (string) ( $row['intent'] ?? '' );
			$mapped[] = [
				'intent'               => $intent,
				'intent_label'         => self::INTENT_LABELS[ $intent ] ?? $intent,
				'impressions'          => (int) ( $row['impressions'] ?? 0 ),
				'unique_viewers'       => (int) ( $row['unique_viewers'] ?? 0 ),
				'ctr'                  => isset( $row['ctr'] ) && null !== $row['ctr'] ? (float) $row['ctr'] : null,
				'form_submission_rate' => isset( $row['form_submission_rate'] ) && null !== $row['form_submission_rate'] ? (float) $row['form_submission_rate'] : null,
				'dismissal_rate'       => isset( $row['dismissal_rate'] ) && null !== $row['dismissal_rate'] ? (float) $row['dismissal_rate'] : null,
			];
		}

		return [
			'state' => 'populated',
			'rows'  => $mapped,
		];
	}

	/**
	 * Per-placement breakdown (overlay / inline / above-header / etc.).
	 *
	 * Pure BQ → row mapping. Placement labels are humanized inline
	 * (`above-header` → `Above header`); the raw value is also carried through
	 * for any UI that needs to filter on it. This table intentionally has no
	 * `form_submission_rate` column per spec §"Performance by Prompt Placement".
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, rows: array, error_code?: string, error_message?: string}
	 */
	public function get_performance_by_placement( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'prompts_performance_by_placement', $start, $end );
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
			if ( ! is_array( $row ) ) {
				// Hub returned a row in an unexpected shape — surface as malformed.
				return $this->malformed_collection( 'rows' );
			}
			$placement = (string) ( $row['placement'] ?? '' );
			$mapped[]  = [
				'placement'       => $placement,
				'placement_label' => '' === $placement ? '' : ucfirst( str_replace( '-', ' ', $placement ) ),
				'impressions'     => (int) ( $row['impressions'] ?? 0 ),
				'unique_viewers'  => (int) ( $row['unique_viewers'] ?? 0 ),
				'ctr'             => isset( $row['ctr'] ) && null !== $row['ctr'] ? (float) $row['ctr'] : null,
				'dismissal_rate'  => isset( $row['dismissal_rate'] ) && null !== $row['dismissal_rate'] ? (float) $row['dismissal_rate'] : null,
			];
		}

		return [
			'state' => 'populated',
			'rows'  => $mapped,
		];
	}
}
