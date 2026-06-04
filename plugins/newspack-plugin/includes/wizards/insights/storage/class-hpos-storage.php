<?php
/**
 * Newspack Insights — HPOS Storage implementation (NPPD-1616).
 *
 * Implements {@see Storage_Interface} against the HPOS tables
 * (`{prefix}wc_orders`, `{prefix}wc_orders_meta`). SQL bodies adapted
 * from `~/Sites/insights-docs/formulas/tab-6-subscribers.md` with CTEs
 * unwound into inline subqueries for MySQL 5.7 compatibility.
 *
 * Product-id joins differ by query type:
 *
 *   - Subscription queries (active/new/churned subscribers, MRR/ARR,
 *     tenure, performance, retry, cancellation reasons, upcoming
 *     renewals) JOIN through `{prefix}woocommerce_order_items` +
 *     `{prefix}woocommerce_order_itemmeta._product_id`. The analytics
 *     lookup `{prefix}wc_order_product_lookup` is shop_order-only on
 *     production data (verified against Block Club Chicago and
 *     Richland Source — 39,461 / 13,279 shop_order rows respectively,
 *     and zero shop_subscription rows on either).
 *   - Revenue queries (gross/net/refund_rate) operate on shop_order
 *     and DO use `{prefix}wc_order_product_lookup`, which Woo populates
 *     correctly for shop_order line items.
 *
 * Donation product IDs are injected at construction; an empty set
 * coerces to `(0)` in the NOT IN list (never matches a real product),
 * which keeps SQL valid when a publisher has no donation products yet.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;
use Newspack\Logger;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared

/**
 * HPOS implementation of the Tab 6 storage contract.
 */
class HPOS_Storage implements Storage_Interface {

	/**
	 * Donation product IDs to exclude from non-donation metric queries.
	 *
	 * @var int[]
	 */
	private $donation_product_ids;

	/**
	 * Constructor.
	 *
	 * @param int[] $donation_product_ids Donation product IDs to exclude.
	 *                                    May be empty; the SQL helpers
	 *                                    coerce empty arrays to `(0)`.
	 */
	public function __construct( array $donation_product_ids ) {
		$this->donation_product_ids = array_map( 'intval', $donation_product_ids );
	}

	/**
	 * Build a SQL-safe `IN (...)` list from a list of integer product IDs.
	 *
	 * Empty input returns `(0)` so the resulting `NOT IN` clause stays
	 * valid syntactically while never matching a real product.
	 *
	 * @param int[] $ids List of integer IDs.
	 * @return string Comma-separated list of integers (or `0`), unparenthesized.
	 */
	private function id_list( array $ids ): string {
		if ( empty( $ids ) ) {
			return '0';
		}
		return implode( ',', array_map( 'intval', $ids ) );
	}

	/**
	 * Format a datetime for SQL comparison.
	 *
	 * @param DateTimeInterface $dt DateTime to format.
	 * @return string `Y-m-d H:i:s` formatted string.
	 */
	private function fmt( DateTimeInterface $dt ): string {
		return $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_active_non_donation_subscribers(): int {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		$sql = "SELECT COUNT(DISTINCT o.customer_id)
			FROM {$prefix}wc_orders o
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			WHERE o.type = 'shop_subscription'
			  AND o.status = 'wc-active'
			  AND oim.meta_value NOT IN ($donations)";

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return int
	 */
	public function get_new_subscribers_in_window( DateTimeInterface $start, DateTimeInterface $end ): int {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Customers whose earliest non-donation subscription's _schedule_start
		// falls in the window. Inner aggregate computes first-start per
		// customer; outer count filters that to the window.
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM (
				SELECT o.customer_id, MIN(om.meta_value) AS first_start
				FROM {$prefix}wc_orders o
				JOIN {$prefix}wc_orders_meta om
					ON om.order_id = o.id AND om.meta_key = '_schedule_start'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE o.type = 'shop_subscription'
				  AND oim.meta_value NOT IN ($donations)
				GROUP BY o.customer_id
			) AS first_subs
			WHERE first_subs.first_start BETWEEN %s AND %s",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return int
	 */
	public function get_churned_subscribers_in_window( DateTimeInterface $start, DateTimeInterface $end ): int {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Customers whose non-donation subscriptions cancelled/expired in
		// window AND who have no remaining active non-donation subscriptions.
		$sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT cancellations.customer_id) FROM (
				SELECT o.customer_id
				FROM {$prefix}wc_orders o
				JOIN {$prefix}wc_orders_meta om
					ON om.order_id = o.id AND om.meta_key = '_schedule_cancelled'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE o.type = 'shop_subscription'
				  AND o.status IN ('wc-cancelled', 'wc-expired')
				  AND oim.meta_value NOT IN ($donations)
				  AND om.meta_value BETWEEN %s AND %s
				  AND om.meta_value != ''
			) AS cancellations
			WHERE cancellations.customer_id NOT IN (
				SELECT DISTINCT o2.customer_id
				FROM {$prefix}wc_orders o2
				JOIN {$prefix}woocommerce_order_items oi2
					ON oi2.order_id = o2.id AND oi2.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim2
					ON oim2.order_item_id = oi2.order_item_id AND oim2.meta_key = '_product_id'
				WHERE o2.type = 'shop_subscription'
				  AND o2.status = 'wc-active'
				  AND oim2.meta_value NOT IN ($donations)
			)",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_mrr(): float {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Normalize each active subscription's total to a monthly rate.
		// The CASE statement covers all documented Woo billing periods
		// at any positive interval N. Daily subscriptions multiply by
		// thirty and divide by N, treating a month as thirty days.
		// Weekly subscriptions multiply by fifty-two over twelve and
		// divide by N. Monthly subscriptions divide by N. Yearly
		// subscriptions divide by twelve times N.
		// The ELSE branch is now TRULY conservative — it falls through to
		// `total / 12`, which undercounts MRR for anything except yearly
		// (a publisher with weird intervals will see slightly lower MRR
		// than reality rather than the previous behavior of multiplying
		// everything to look monthly). A separate diagnostic query below
		// counts how many subscriptions hit this fallback and logs a
		// notice via Newspack\Logger so the publisher can correct the
		// product configuration.
		//
		// DISTINCT id-subselect dedupes orders that have more than one
		// non-donation line item so MRR isn't multiplied.
		$sql = "SELECT SUM(
				CASE
					WHEN bp.meta_value = 'day'   AND CAST(bi.meta_value AS UNSIGNED) > 0
						THEN o.total_amount * 30 / CAST(bi.meta_value AS UNSIGNED)
					WHEN bp.meta_value = 'week'  AND CAST(bi.meta_value AS UNSIGNED) > 0
						THEN o.total_amount * (52/12) / CAST(bi.meta_value AS UNSIGNED)
					WHEN bp.meta_value = 'month' AND CAST(bi.meta_value AS UNSIGNED) > 0
						THEN o.total_amount / CAST(bi.meta_value AS UNSIGNED)
					WHEN bp.meta_value = 'year'  AND CAST(bi.meta_value AS UNSIGNED) > 0
						THEN o.total_amount / (12 * CAST(bi.meta_value AS UNSIGNED))
					ELSE o.total_amount / 12
				END
			)
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_orders_meta bp
				ON bp.order_id = o.id AND bp.meta_key = '_billing_period'
			JOIN {$prefix}wc_orders_meta bi
				ON bi.order_id = o.id AND bi.meta_key = '_billing_interval'
			WHERE o.type = 'shop_subscription'
			  AND o.status = 'wc-active'
			  AND o.id IN (
				SELECT DISTINCT oi.order_id
				FROM {$prefix}woocommerce_order_items oi
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE oi.order_item_type = 'line_item'
				  AND oim.meta_value NOT IN ($donations)
			  )";

		$mrr = (float) $wpdb->get_var( $sql );

		// Diagnostic: count active non-donation subscriptions whose
		// _billing_period or _billing_interval is unrecognized. If any
		// exist, their MRR contribution was the conservative fallback —
		// surface so the publisher can fix the product config.
		$unrecognized = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT o.id)
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_orders_meta bp ON bp.order_id = o.id AND bp.meta_key = '_billing_period'
			JOIN {$prefix}wc_orders_meta bi ON bi.order_id = o.id AND bi.meta_key = '_billing_interval'
			WHERE o.type = 'shop_subscription'
			  AND o.status = 'wc-active'
			  AND (
				bp.meta_value NOT IN ('day', 'week', 'month', 'year')
				OR CAST(bi.meta_value AS UNSIGNED) = 0
			  )
			  AND o.id IN (
				SELECT DISTINCT oi.order_id
				FROM {$prefix}woocommerce_order_items oi
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE oi.order_item_type = 'line_item'
				  AND oim.meta_value NOT IN ($donations)
			  )"
		);

		if ( $unrecognized > 0 && class_exists( Logger::class ) ) {
			Logger::log(
				sprintf(
					'%d active non-donation subscription(s) have unrecognized _billing_period/_billing_interval combinations. Their MRR contribution fell through to the conservative annual-amortized fallback (total / 12). Review product configuration.',
					$unrecognized
				),
				'NEWSPACK-INSIGHTS'
			);
		}

		return $mrr;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_arr(): float {
		return $this->get_mrr() * 12;
	}

	/**
	 * Compute the ID set of subscription-type products (subscription,
	 * variable-subscription, subscription_variation) for use in the
	 * subscription-product filter clauses. Live query per call; callers
	 * should be cached.
	 *
	 * @return string Comma-separated list of integer product IDs, or `0`
	 *                when the publisher has none.
	 */
	private function subscription_product_ids_sql(): string {
		global $wpdb;
		$prefix = $wpdb->prefix;

		$rows = $wpdb->get_col(
			"SELECT p.ID
			FROM {$prefix}posts p
			JOIN {$prefix}term_relationships tr ON p.ID = tr.object_id
			JOIN {$prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			JOIN {$prefix}terms t ON tt.term_id = t.term_id
			WHERE tt.taxonomy = 'product_type'
			  AND t.slug IN ('subscription', 'variable-subscription', 'subscription_variation')"
		);

		return $this->id_list( array_map( 'intval', (array) $rows ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return float
	 */
	public function get_subscription_revenue_gross( DateTimeInterface $start, DateTimeInterface $end ): float {
		global $wpdb;
		$prefix         = $wpdb->prefix;
		$donations      = $this->id_list( $this->donation_product_ids );
		$subscription_p = $this->subscription_product_ids_sql();

		// Sum shop_order totals where order contains a subscription product
		// AND no donation product. Two separate filters on the lookup table.
		$sql = $wpdb->prepare(
			"SELECT SUM(o.total_amount)
			FROM {$prefix}wc_orders o
			WHERE o.type = 'shop_order'
			  AND o.status IN ('wc-completed', 'wc-processing')
			  AND o.date_created_gmt BETWEEN %s AND %s
			  AND o.id IN (
				SELECT DISTINCT order_id
				FROM {$prefix}wc_order_product_lookup
				WHERE product_id IN ($subscription_p)
			  )
			  AND o.id NOT IN (
				SELECT DISTINCT order_id
				FROM {$prefix}wc_order_product_lookup
				WHERE product_id IN ($donations)
			  )",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		return (float) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return float
	 */
	public function get_subscription_revenue_net( DateTimeInterface $start, DateTimeInterface $end ): float {
		global $wpdb;
		$prefix         = $wpdb->prefix;
		$donations      = $this->id_list( $this->donation_product_ids );
		$subscription_p = $this->subscription_product_ids_sql();

		// Sum across shop_order + shop_order_refund rows. Refunds carry
		// negative totals so SUM yields correct net.
		$sql = $wpdb->prepare(
			"SELECT SUM(o.total_amount)
			FROM {$prefix}wc_orders o
			WHERE o.type IN ('shop_order', 'shop_order_refund')
			  AND o.status IN ('wc-completed', 'wc-processing')
			  AND o.date_created_gmt BETWEEN %s AND %s
			  AND (
				(
					o.type = 'shop_order'
					AND o.id IN (
						SELECT DISTINCT order_id
						FROM {$prefix}wc_order_product_lookup
						WHERE product_id IN ($subscription_p)
						  AND product_id NOT IN ($donations)
					)
				)
				OR (
					o.type = 'shop_order_refund'
					AND o.parent_order_id IN (
						SELECT DISTINCT order_id
						FROM {$prefix}wc_order_product_lookup
						WHERE product_id IN ($subscription_p)
						  AND product_id NOT IN ($donations)
					)
				)
			  )",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		return (float) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return float
	 */
	public function get_subscription_refund_rate( DateTimeInterface $start, DateTimeInterface $end ): float {
		global $wpdb;
		$prefix         = $wpdb->prefix;
		$donations      = $this->id_list( $this->donation_product_ids );
		$subscription_p = $this->subscription_product_ids_sql();

		// Count subscription orders in window.
		$orders_sql = $wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$prefix}wc_orders
			WHERE type = 'shop_order'
			  AND status IN ('wc-completed', 'wc-processing')
			  AND date_created_gmt BETWEEN %s AND %s
			  AND id IN (
				SELECT DISTINCT order_id
				FROM {$prefix}wc_order_product_lookup
				WHERE product_id IN ($subscription_p)
				  AND product_id NOT IN ($donations)
			  )",
			$this->fmt( $start ),
			$this->fmt( $end )
		);
		$orders     = (int) $wpdb->get_var( $orders_sql );

		if ( 0 === $orders ) {
			return 0.0;
		}

		// Count refunds in window whose parent order had a subscription product.
		$refunds_sql = $wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$prefix}wc_orders r
			WHERE r.type = 'shop_order_refund'
			  AND r.date_created_gmt BETWEEN %s AND %s
			  AND r.parent_order_id IN (
				SELECT DISTINCT order_id
				FROM {$prefix}wc_order_product_lookup
				WHERE product_id IN ($subscription_p)
				  AND product_id NOT IN ($donations)
			  )",
			$this->fmt( $start ),
			$this->fmt( $end )
		);
		$refunds     = (int) $wpdb->get_var( $refunds_sql );

		return $refunds / $orders;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_subscription_tenure_distribution(): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Tenure days since _schedule_start for each active non-donation
		// subscription line item, grouped client-side by product_name.
		// Excludes empty or future start dates (data-corruption edge case).
		$sql = "SELECT
				p.post_title AS product_name,
				TIMESTAMPDIFF(DAY, om.meta_value, NOW()) AS tenure_days
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_orders_meta om
				ON om.order_id = o.id AND om.meta_key = '_schedule_start'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			JOIN {$prefix}posts p ON p.ID = CAST(oim.meta_value AS UNSIGNED)
			WHERE o.type = 'shop_subscription'
			  AND o.status = 'wc-active'
			  AND oim.meta_value NOT IN ($donations)
			  AND om.meta_value != ''
			  AND om.meta_value < NOW()";

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( empty( $rows ) ) {
			return [];
		}
		return array_map(
			function ( $row ) {
				return [
					'product_name' => (string) $row['product_name'],
					'tenure_days'  => (int) $row['tenure_days'],
				];
			},
			$rows
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_upcoming_renewals_30d(): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// DISTINCT id-subselect for the non-donation filter so a multi-line-item
		// subscription is counted once and its total_amount isn't summed twice.
		$row = $wpdb->get_row(
			"SELECT
				COUNT(*) AS upcoming_count,
				COALESCE(SUM(o.total_amount), 0) AS upcoming_value
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_orders_meta om
				ON om.order_id = o.id AND om.meta_key = '_schedule_next_payment'
			WHERE o.type = 'shop_subscription'
			  AND o.status = 'wc-active'
			  AND o.id IN (
				SELECT DISTINCT oi.order_id
				FROM {$prefix}woocommerce_order_items oi
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE oi.order_item_type = 'line_item'
				  AND oim.meta_value NOT IN ($donations)
			  )
			  AND om.meta_value BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)",
			ARRAY_A
		);

		return [
			'count'       => (int) ( $row['upcoming_count'] ?? 0 ),
			'total_value' => (float) ( $row['upcoming_value'] ?? 0 ),
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return float
	 */
	public function get_failed_payment_retry_rate( DateTimeInterface $start, DateTimeInterface $end ): float {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Count retry-scheduled subscriptions in window vs. how many ended
		// the window with status wc-active (= successful recovery).
		// DISTINCT id-subselect for the non-donation filter so a
		// multi-line-item subscription doesn't show up as multiple retries.
		$sql = $wpdb->prepare(
			"SELECT
				COUNT(*) AS retry_attempts,
				SUM(CASE WHEN sub.status = 'wc-active' THEN 1 ELSE 0 END) AS recoveries
			FROM (
				SELECT DISTINCT o.id AS subscription_id
				FROM {$prefix}wc_orders o
				JOIN {$prefix}wc_orders_meta om
					ON om.order_id = o.id AND om.meta_key = '_schedule_payment_retry'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE o.type = 'shop_subscription'
				  AND oim.meta_value NOT IN ($donations)
				  AND om.meta_value BETWEEN %s AND %s
				  AND om.meta_value != ''
			) AS retries
			JOIN {$prefix}wc_orders sub ON sub.id = retries.subscription_id",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		$row     = $wpdb->get_row( $sql, ARRAY_A );
		$attempt = (int) ( $row['retry_attempts'] ?? 0 );
		$success = (int) ( $row['recoveries'] ?? 0 );

		return 0 === $attempt ? 0.0 : $success / $attempt;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_performance_by_product( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Column scope:
		// active_subs       — current state (independent of window)
		// active_value      — current state
		// lifetime_revenue  — lifetime sum of subscription-record totals
		// attributed per product; not windowed by
		// design (true LTV waits on the v1.1 BQ wrapper)
		// churned_subs      — WINDOWED to {start, end} via the
		// `_schedule_cancelled` meta join below
		//
		// Each subscription line item is counted toward the product it
		// references. A subscription with two non-donation line items
		// contributes to both products' counts and amounts; SUM uses
		// `o.total_amount` so a multi-product sub does NOT inflate the
		// per-product active_value beyond the subscription's actual total
		// (instead it's attributed once per product — a simplification).
		//
		// The LEFT JOIN to `_schedule_cancelled` is required for window
		// scoping. Active subscriptions don't have this meta set, so the
		// left-joined row is NULL and the churned CASE naturally rejects
		// them. Subscription Woo writes one `_schedule_cancelled` row per
		// subscription at most, so no row multiplication.
		$sql = $wpdb->prepare(
			"SELECT
				p.ID AS product_id,
				p.post_title AS product_name,
				COUNT(DISTINCT CASE WHEN o.status = 'wc-active' THEN o.id END) AS active_subs,
				COUNT(DISTINCT CASE
					WHEN o.status IN ('wc-cancelled', 'wc-expired')
					 AND sch.meta_value BETWEEN %s AND %s
					THEN o.id
				END) AS churned_subs,
				COALESCE(SUM(CASE WHEN o.status = 'wc-active' THEN o.total_amount END), 0) AS active_value,
				COALESCE(SUM(o.total_amount), 0) AS lifetime_revenue
			FROM {$prefix}wc_orders o
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			JOIN {$prefix}posts p ON p.ID = CAST(oim.meta_value AS UNSIGNED)
			LEFT JOIN {$prefix}wc_orders_meta sch
				ON sch.order_id = o.id AND sch.meta_key = '_schedule_cancelled'
			WHERE o.type = 'shop_subscription'
			  AND oim.meta_value NOT IN ($donations)
			GROUP BY p.ID, p.post_title
			ORDER BY active_subs DESC
			LIMIT 50",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( empty( $rows ) ) {
			return [];
		}
		return array_map(
			function ( $row ) {
				return [
					'product_id'       => (int) $row['product_id'],
					'product_name'     => (string) $row['product_name'],
					'active_subs'      => (int) $row['active_subs'],
					'churned_subs'     => (int) $row['churned_subs'],
					'active_value'     => (float) $row['active_value'],
					'lifetime_revenue' => (float) $row['lifetime_revenue'],
				];
			},
			$rows
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_cancellation_reasons( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// DISTINCT id-subselect on the non-donation filter so a sub with
		// multiple line items doesn't get counted multiple times under the
		// same reason.
		$sql = $wpdb->prepare(
			"SELECT
				COALESCE(om.meta_value, 'unknown') AS cancellation_reason,
				COUNT(*) AS count
			FROM {$prefix}wc_orders o
			LEFT JOIN {$prefix}wc_orders_meta om
				ON om.order_id = o.id AND om.meta_key = 'newspack_subscriptions_cancellation_reason'
			JOIN {$prefix}wc_orders_meta sch
				ON sch.order_id = o.id AND sch.meta_key = '_schedule_cancelled'
			WHERE o.type = 'shop_subscription'
			  AND o.status IN ('wc-cancelled', 'wc-expired')
			  AND o.id IN (
				SELECT DISTINCT oi.order_id
				FROM {$prefix}woocommerce_order_items oi
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE oi.order_item_type = 'line_item'
				  AND oim.meta_value NOT IN ($donations)
			  )
			  AND sch.meta_value BETWEEN %s AND %s
			GROUP BY cancellation_reason
			ORDER BY count DESC",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( empty( $rows ) ) {
			return [];
		}
		return array_map(
			function ( $row ) {
				return [
					'cancellation_reason' => (string) $row['cancellation_reason'],
					'count'               => (int) $row['count'],
				];
			},
			$rows
		);
	}
}
