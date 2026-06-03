<?php
/**
 * Newspack Insights — Subscribers section (NPPD-1602).
 *
 * Subscribers tab scope: paid subscriber base health. Active subs,
 * MRR / ARPU, churn, cohort retention, plan mix. Local-DB-driven
 * (WooCommerce + Memberships); does NOT need BigQuery. Visible only
 * when the publisher has non-donation subscription products.
 *
 * Future REST endpoints for the Subscribers tab register from
 * {@see self::register_hooks()}. Currently a stub; the React side renders
 * a "Coming soon" placeholder for this tab.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Subscribers section.
 */
class Insights_Section_Subscribers {

	/**
	 * Display label for this tab. Must match the React tab label.
	 *
	 * @var string
	 */
	const SECTION_NAME = 'Subscribers';

	/**
	 * Initialize. Hooks REST endpoints for this tab when implementations
	 * arrive (NPPD-1617).
	 */
	public static function init() {
		self::register_hooks();
	}

	/**
	 * Register hooks for this section. Currently a stub.
	 */
	public static function register_hooks() {}
}
