<?php
/**
 * Newspack Insights Wizard (NPPD-1602).
 *
 * Top-level wizard chrome for the Insights page. Tab routing happens
 * entirely on the React side via URL query persistence; this PHP wizard
 * registers the admin page and localizes the boot config (tab visibility,
 * default date range, timezone, settings URL).
 *
 * Section classes (Insights_Section_*) live alongside this file and exist
 * for future per-tab REST endpoint registration when each tab's data layer
 * lands in subsequent issues.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Insights Wizard.
 */
class Insights_Wizard extends Wizard {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-insights';

	/**
	 * Capability required to access this wizard.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * Parent menu item slug. Nests under the top-level Newspack admin menu,
	 * matching the Setup wizard's precedent.
	 *
	 * @var string
	 */
	public $parent_menu = 'newspack-dashboard';

	/**
	 * Checks if the feature is enabled.
	 *
	 * True when:
	 * - NEWSPACK_INSIGHTS_ENABLED is defined and true.
	 *
	 * Feature-flagged for gradual rollout.
	 * Remove this gate once fully released.
	 *
	 * @return bool True if the feature is enabled, false otherwise.
	 */
	public static function is_enabled() {
		/**
		 * Enables the Newspack Insights feature.
		 *
		 * @constant NEWSPACK_INSIGHTS_ENABLED
		 * @type     bool
		 * @default  Insights feature disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_INSIGHTS_ENABLED', true );
		 */
		return defined( 'NEWSPACK_INSIGHTS_ENABLED' ) && NEWSPACK_INSIGHTS_ENABLED;
	}

	/**
	 * Constructor.
	 *
	 * Bails before parent registration when the feature flag is disabled,
	 * so no menu item, asset enqueue, or admin hooks are registered.
	 */
	public function __construct() {
		if ( ! self::is_enabled() ) {
			return;
		}
		parent::__construct();
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string
	 */
	public function get_name() {
		return esc_html__( 'Insights', 'newspack-plugin' );
	}

	/**
	 * Enqueue the shared modern-wizard bundle and localize boot config.
	 *
	 * The React view is registered in src/wizards/index.tsx under the
	 * 'newspack-insights' key.
	 */
	public function enqueue_scripts_and_styles() {
		parent::enqueue_scripts_and_styles();

		if ( filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) !== $this->slug ) {
			return;
		}

		wp_enqueue_script( 'newspack-wizards' );

		wp_localize_script( 'newspack-wizards', 'newspackInsights', $this->get_boot_config() );
	}

	/**
	 * Cache key for the donation-activity detection result.
	 *
	 * @var string
	 */
	const DONATION_ACTIVITY_TRANSIENT = 'newspack_insights_has_donation_activity';

	/**
	 * Donors tab visibility. True when the publisher has at least one
	 * donation-related order or subscription in their history.
	 *
	 * Product existence is NOT a useful signal: every Newspack publisher
	 * receives the canonical donation product family on install regardless
	 * of whether they ever collect donations, so a product-existence
	 * check showed Tab 7 on every site, including the many publishers
	 * who have never taken a donation. Activity is the right heuristic —
	 * a single qualifying order or subscription gates the tab visible.
	 *
	 * Result is cached for 24h via {@see self::DONATION_ACTIVITY_TRANSIENT}.
	 * State transitions ("publisher started taking donations") are rare
	 * and one-way, so aggressive caching is correct. Tests / manual
	 * invalidation can call {@see self::force_refresh_donation_activity()}.
	 *
	 * Returns false immediately when the donation product ID set is
	 * empty (nothing the activity query could match) without running
	 * the EXISTS query. Falls back to true if the classifier class
	 * isn't loaded (defensive — preserves visibility so the missing
	 * dependency can be diagnosed rather than silently hiding the tab).
	 *
	 * @return bool
	 */
	private static function has_donation_activity(): bool {
		$cached = get_transient( self::DONATION_ACTIVITY_TRANSIENT );
		if ( 'yes' === $cached ) {
			return true;
		}
		if ( 'no' === $cached ) {
			return false;
		}

		$has_activity = self::compute_donation_activity();
		set_transient( self::DONATION_ACTIVITY_TRANSIENT, $has_activity ? 'yes' : 'no', DAY_IN_SECONDS );
		return $has_activity;
	}

	/**
	 * Force-recompute the donation activity flag, bypassing and
	 * refreshing the cache. Useful for tests and for the case where a
	 * publisher just received their first donation.
	 *
	 * @return bool The freshly computed activity flag.
	 */
	public static function force_refresh_donation_activity(): bool {
		delete_transient( self::DONATION_ACTIVITY_TRANSIENT );
		$has_activity = self::compute_donation_activity();
		set_transient( self::DONATION_ACTIVITY_TRANSIENT, $has_activity ? 'yes' : 'no', DAY_IN_SECONDS );
		return $has_activity;
	}

	/**
	 * Run the activity query without consulting the cache.
	 *
	 * @return bool
	 */
	private static function compute_donation_activity(): bool {
		if ( ! class_exists( '\Newspack\Insights\Donation_Product_Classifier' ) ) {
			// Defensive: keep tab visible so the missing dep can be diagnosed.
			return true;
		}
		$donation_ids = \Newspack\Insights\Donation_Product_Classifier::get_donation_product_ids();
		if ( empty( $donation_ids ) ) {
			return false;
		}

		global $wpdb;
		$donations_list = implode( ',', array_map( 'intval', $donation_ids ) );

		// Dispatch by backend so we read from the authoritative orders
		// source rather than scanning a potentially stale legacy CPT
		// table on HPOS sites (or vice versa).
		$backend = class_exists( '\Newspack\Insights\Storage_Detector' )
			? \Newspack\Insights\Storage_Detector::detect()
			: 'legacy';

		// Constrain to statuses that represent actual donation activity:
		// completed/processing/refunded one-time orders, and subscriptions that
		// have genuinely existed (active through expired). This keeps failed,
		// pending, trash, auto-draft, and checkout-draft objects from surfacing
		// the tab on a site that never actually took a donation.
		if ( 'hpos' === $backend ) {
			$sql = "SELECT EXISTS (
				SELECT 1 FROM {$wpdb->prefix}wc_orders o
				JOIN {$wpdb->prefix}woocommerce_order_items items ON items.order_id = o.id
				JOIN {$wpdb->prefix}woocommerce_order_itemmeta meta
					ON meta.order_item_id = items.order_item_id
					AND meta.meta_key = '_product_id'
				WHERE (
					( o.type = 'shop_order' AND o.status IN ('wc-completed', 'wc-processing', 'wc-refunded') )
					OR ( o.type = 'shop_subscription' AND o.status IN ('wc-active', 'wc-on-hold', 'wc-pending-cancel', 'wc-cancelled', 'wc-expired') )
				)
				  AND meta.meta_value IN ($donations_list)
				LIMIT 1
			) AS has_activity";
		} else {
			$sql = "SELECT EXISTS (
				SELECT 1 FROM {$wpdb->prefix}posts p
				JOIN {$wpdb->prefix}woocommerce_order_items items ON items.order_id = p.ID
				JOIN {$wpdb->prefix}woocommerce_order_itemmeta meta
					ON meta.order_item_id = items.order_item_id
					AND meta.meta_key = '_product_id'
				WHERE (
					( p.post_type = 'shop_order' AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-refunded') )
					OR ( p.post_type = 'shop_subscription' AND p.post_status IN ('wc-active', 'wc-on-hold', 'wc-pending-cancel', 'wc-cancelled', 'wc-expired') )
				)
				  AND meta.meta_value IN ($donations_list)
				LIMIT 1
			) AS has_activity";
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) (int) $wpdb->get_var( $sql );
		// phpcs:enable
	}

	/**
	 * Build the boot config consumed by the React entry.
	 *
	 * @return array
	 */
	protected function get_boot_config() {
		// current_datetime() returns DateTimeImmutable; modify() returns a new
		// instance and does not mutate $today. -29 days yields an inclusive
		// 30-day window ending today (today + 29 prior days = 30 days).
		$today      = current_datetime();
		$thirty_ago = $today->modify( '-29 days' );

		return [
			// Tab visibility. The audience/engagement/conversion/gates/
			// prompts/advertising tabs are stubbed to true until their
			// data layers land (each needs BQ for proper feature
			// detection, NPPD-1598). Subscribers stays all-on for now;
			// Tab 6 visibility detection (non-donation subscription
			// product presence) is a separate follow-up. Donors hides
			// when there are no donation products on the publisher,
			// using the shared Donation_Product_Classifier (cached 1h)
			// as the single source of truth.
			'tabs'              => [
				'audience'    => true,
				'engagement'  => true,
				'conversion'  => true,
				'gates'       => true,
				'prompts'     => true,
				'subscribers' => true,
				'donors'      => self::has_donation_activity(),
				'advertising' => true,
			],
			'defaultDateRange'  => [
				'preset' => 'last-30',
				'start'  => $thirty_ago->format( 'Y-m-d' ),
				'end'    => $today->format( 'Y-m-d' ),
			],
			'defaultComparison' => false,
			'timezone'          => wp_timezone_string(),
			'settingsUrl'       => admin_url( 'admin.php?page=newspack-settings' ),
		];
	}
}
