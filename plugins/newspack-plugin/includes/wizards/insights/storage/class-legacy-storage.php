<?php
/**
 * Newspack Insights — Legacy CPT Storage implementation (NPPD-1616).
 *
 * Implements {@see Storage_Interface} against the pre-HPOS WooCommerce
 * order storage: orders/subscriptions live in `{prefix}posts` (typed by
 * `post_type`) and their metadata in `{prefix}postmeta`.
 *
 * Mirrors the HPOS implementation method-by-method, with the per-row
 * differences documented in the schema doc:
 * `~/Sites/insights-docs/formulas/subscription-donation-schema.md`
 *
 *   HPOS                          Legacy
 *   wc_orders.id                  posts.ID
 *   wc_orders.type                posts.post_type
 *   wc_orders.status              posts.post_status
 *   wc_orders.date_created_gmt    posts.post_date_gmt
 *   wc_orders.customer_id         postmeta._customer_user
 *   wc_orders.total_amount        postmeta._order_total (DECIMAL string)
 *   wc_orders.parent_order_id     posts.post_parent
 *   wc_orders_meta.*              postmeta.*
 *
 * Line-item tables `{prefix}woocommerce_order_items` and
 * `{prefix}woocommerce_order_itemmeta` are NOT HPOS-specific — they
 * pre-date HPOS and continue to hold line items for every order type
 * on both backends. Subscription-side queries here use them for the
 * same reason as in {@see HPOS_Storage}: production data confirms
 * `{prefix}wc_order_product_lookup` only ever holds shop_order rows
 * (Block Club Chicago: 39,461 shop_order / 0 shop_subscription;
 * Richland Source: 13,279 / 0).
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared

/**
 * Legacy CPT implementation of the Tab 6 storage contract.
 */
class Legacy_Storage implements Storage_Interface {

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
	 */
	public function __construct( array $donation_product_ids ) {
		$this->donation_product_ids = array_map( 'intval', $donation_product_ids );
	}

	/**
	 * Build a SQL-safe `IN (...)` list from integer IDs. Empty -> `0`.
	 *
	 * @param int[] $ids List of integer IDs.
	 * @return string Comma-separated integers (or `0`), unparenthesized.
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
	 * @return string `Y-m-d H:i:s`.
	 */
	private function fmt( DateTimeInterface $dt ): string {
		return $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Look up subscription product type IDs (same logic as HPOS — uses the
	 * shared product_type taxonomy, not order-storage-specific tables).
	 *
	 * @return string Comma-separated integer IDs (or `0` if none).
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
	 */
	public function get_active_non_donation_subscribers(): int {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		$sql = "SELECT COUNT(DISTINCT cust.meta_value)
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta cust
				ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			WHERE p.post_type = 'shop_subscription'
			  AND p.post_status = 'wc-active'
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

		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM (
				SELECT cust.meta_value AS customer_id, MIN(start.meta_value) AS first_start
				FROM {$prefix}posts p
				JOIN {$prefix}postmeta cust
					ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
				JOIN {$prefix}postmeta start
					ON start.post_id = p.ID AND start.meta_key = '_schedule_start'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE p.post_type = 'shop_subscription'
				  AND oim.meta_value NOT IN ($donations)
				GROUP BY cust.meta_value
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

		$sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT cancellations.customer_id) FROM (
				SELECT cust.meta_value AS customer_id
				FROM {$prefix}posts p
				JOIN {$prefix}postmeta cust
					ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
				JOIN {$prefix}postmeta cancelled
					ON cancelled.post_id = p.ID AND cancelled.meta_key = '_schedule_cancelled'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE p.post_type = 'shop_subscription'
				  AND p.post_status IN ('wc-cancelled', 'wc-expired')
				  AND oim.meta_value NOT IN ($donations)
				  AND cancelled.meta_value BETWEEN %s AND %s
				  AND cancelled.meta_value != ''
			) AS cancellations
			WHERE cancellations.customer_id NOT IN (
				SELECT DISTINCT cust2.meta_value
				FROM {$prefix}posts p2
				JOIN {$prefix}postmeta cust2
					ON cust2.post_id = p2.ID AND cust2.meta_key = '_customer_user'
				JOIN {$prefix}woocommerce_order_items oi2
					ON oi2.order_id = p2.ID AND oi2.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim2
					ON oim2.order_item_id = oi2.order_item_id AND oim2.meta_key = '_product_id'
				WHERE p2.post_type = 'shop_subscription'
				  AND p2.post_status = 'wc-active'
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

		// Same CASE-on-period logic as HPOS, but the total amount is
		// retrieved via _order_total postmeta (stored as DECIMAL string).
		// DISTINCT id-subselect dedupes subscriptions with more than one
		// non-donation line item so MRR isn't multiplied.
		$sql = "SELECT SUM(
				CASE
					WHEN bp.meta_value = 'month' AND bi.meta_value = '1' THEN CAST(tot.meta_value AS DECIMAL(15,2))
					WHEN bp.meta_value = 'year'  AND bi.meta_value = '1' THEN CAST(tot.meta_value AS DECIMAL(15,2)) / 12
					WHEN bp.meta_value = 'month' AND bi.meta_value = '3' THEN CAST(tot.meta_value AS DECIMAL(15,2)) / 3
					WHEN bp.meta_value = 'month' AND bi.meta_value = '6' THEN CAST(tot.meta_value AS DECIMAL(15,2)) / 6
					WHEN bp.meta_value = 'week'  AND bi.meta_value = '1' THEN CAST(tot.meta_value AS DECIMAL(15,2)) * 4.345
					ELSE CAST(tot.meta_value AS DECIMAL(15,2))
				END
			)
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta bp
				ON bp.post_id = p.ID AND bp.meta_key = '_billing_period'
			JOIN {$prefix}postmeta bi
				ON bi.post_id = p.ID AND bi.meta_key = '_billing_interval'
			JOIN {$prefix}postmeta tot
				ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
			WHERE p.post_type = 'shop_subscription'
			  AND p.post_status = 'wc-active'
			  AND p.ID IN (
				SELECT DISTINCT oi.order_id
				FROM {$prefix}woocommerce_order_items oi
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE oi.order_item_type = 'line_item'
				  AND oim.meta_value NOT IN ($donations)
			  )";

		return (float) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_arr(): float {
		return $this->get_mrr() * 12;
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

		$sql = $wpdb->prepare(
			"SELECT SUM(CAST(tot.meta_value AS DECIMAL(15,2)))
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta tot
				ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
			WHERE p.post_type = 'shop_order'
			  AND p.post_status IN ('wc-completed', 'wc-processing')
			  AND p.post_date_gmt BETWEEN %s AND %s
			  AND p.ID IN (
				SELECT DISTINCT order_id
				FROM {$prefix}wc_order_product_lookup
				WHERE product_id IN ($subscription_p)
			  )
			  AND p.ID NOT IN (
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

		// Sum across shop_order + shop_order_refund. Refund totals are
		// negative so SUM yields the right net.
		$sql = $wpdb->prepare(
			"SELECT SUM(CAST(tot.meta_value AS DECIMAL(15,2)))
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta tot
				ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
			WHERE p.post_type IN ('shop_order', 'shop_order_refund')
			  AND p.post_status IN ('wc-completed', 'wc-processing')
			  AND p.post_date_gmt BETWEEN %s AND %s
			  AND (
				(
					p.post_type = 'shop_order'
					AND p.ID IN (
						SELECT DISTINCT order_id
						FROM {$prefix}wc_order_product_lookup
						WHERE product_id IN ($subscription_p)
						  AND product_id NOT IN ($donations)
					)
				)
				OR (
					p.post_type = 'shop_order_refund'
					AND p.post_parent IN (
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

		$orders_sql = $wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$prefix}posts p
			WHERE p.post_type = 'shop_order'
			  AND p.post_status IN ('wc-completed', 'wc-processing')
			  AND p.post_date_gmt BETWEEN %s AND %s
			  AND p.ID IN (
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

		$refunds_sql = $wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$prefix}posts p
			WHERE p.post_type = 'shop_order_refund'
			  AND p.post_date_gmt BETWEEN %s AND %s
			  AND p.post_parent IN (
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

		// One row per active subscription line item; the React layer groups
		// client-side and computes box-plot quartiles.
		$sql = "SELECT
				prod.post_title AS product_name,
				TIMESTAMPDIFF(DAY, start.meta_value, NOW()) AS tenure_days
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta start
				ON start.post_id = p.ID AND start.meta_key = '_schedule_start'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			JOIN {$prefix}posts prod ON prod.ID = CAST(oim.meta_value AS UNSIGNED)
			WHERE p.post_type = 'shop_subscription'
			  AND p.post_status = 'wc-active'
			  AND oim.meta_value NOT IN ($donations)
			  AND start.meta_value != ''
			  AND start.meta_value < NOW()";

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
		// subscription is counted once and its _order_total isn't summed twice.
		$row = $wpdb->get_row(
			"SELECT
				COUNT(*) AS upcoming_count,
				COALESCE(SUM(CAST(tot.meta_value AS DECIMAL(15,2))), 0) AS upcoming_value
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta next
				ON next.post_id = p.ID AND next.meta_key = '_schedule_next_payment'
			JOIN {$prefix}postmeta tot
				ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
			WHERE p.post_type = 'shop_subscription'
			  AND p.post_status = 'wc-active'
			  AND p.ID IN (
				SELECT DISTINCT oi.order_id
				FROM {$prefix}woocommerce_order_items oi
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE oi.order_item_type = 'line_item'
				  AND oim.meta_value NOT IN ($donations)
			  )
			  AND next.meta_value BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)",
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

		// DISTINCT id-subselect for the non-donation filter so a
		// multi-line-item subscription doesn't show up as multiple retries.
		$sql = $wpdb->prepare(
			"SELECT
				COUNT(*) AS retry_attempts,
				SUM(CASE WHEN sub.post_status = 'wc-active' THEN 1 ELSE 0 END) AS recoveries
			FROM (
				SELECT DISTINCT p.ID AS subscription_id
				FROM {$prefix}posts p
				JOIN {$prefix}postmeta retry
					ON retry.post_id = p.ID AND retry.meta_key = '_schedule_payment_retry'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE p.post_type = 'shop_subscription'
				  AND oim.meta_value NOT IN ($donations)
				  AND retry.meta_value BETWEEN %s AND %s
				  AND retry.meta_value != ''
			) AS retries
			JOIN {$prefix}posts sub ON sub.ID = retries.subscription_id",
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

		// Each subscription line item is counted toward the product it
		// references. Multi-product subs contribute to each product's
		// counts and amounts; SUM uses the subscription's _order_total so
		// the per-product active_value is attributed once per product
		// (a documented v1 simplification).
		$sql = "SELECT
				prod.ID AS product_id,
				prod.post_title AS product_name,
				COUNT(DISTINCT CASE WHEN p.post_status = 'wc-active' THEN p.ID END) AS active_subs,
				COUNT(DISTINCT CASE WHEN p.post_status IN ('wc-cancelled', 'wc-expired') THEN p.ID END) AS churned_subs,
				COALESCE(SUM(CASE WHEN p.post_status = 'wc-active' THEN CAST(tot.meta_value AS DECIMAL(15,2)) END), 0) AS active_value,
				COALESCE(SUM(CAST(tot.meta_value AS DECIMAL(15,2))), 0) AS lifetime_revenue
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta tot
				ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			JOIN {$prefix}posts prod ON prod.ID = CAST(oim.meta_value AS UNSIGNED)
			WHERE p.post_type = 'shop_subscription'
			  AND oim.meta_value NOT IN ($donations)
			GROUP BY prod.ID, prod.post_title
			ORDER BY active_subs DESC
			LIMIT 50";

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
				COALESCE(reason.meta_value, 'unknown') AS cancellation_reason,
				COUNT(*) AS count
			FROM {$prefix}posts p
			LEFT JOIN {$prefix}postmeta reason
				ON reason.post_id = p.ID AND reason.meta_key = 'newspack_subscriptions_cancellation_reason'
			JOIN {$prefix}postmeta cancelled
				ON cancelled.post_id = p.ID AND cancelled.meta_key = '_schedule_cancelled'
			WHERE p.post_type = 'shop_subscription'
			  AND p.post_status IN ('wc-cancelled', 'wc-expired')
			  AND p.ID IN (
				SELECT DISTINCT oi.order_id
				FROM {$prefix}woocommerce_order_items oi
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE oi.order_item_type = 'line_item'
				  AND oim.meta_value NOT IN ($donations)
			  )
			  AND cancelled.meta_value BETWEEN %s AND %s
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
