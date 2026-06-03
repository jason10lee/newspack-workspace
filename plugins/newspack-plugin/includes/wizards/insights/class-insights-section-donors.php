<?php
/**
 * Newspack Insights — Donors section (NPPD-1602).
 *
 * Donors tab scope: donation activity. One-time vs recurring, donor
 * mix, average gift size, retention of recurring donors. Like
 * Subscribers, local-DB-driven (WooCommerce + Donations). Visible only
 * when the publisher has donation activity in the period.
 *
 * Future REST endpoints for the Donors tab register from
 * {@see self::register_hooks()}. Currently a stub; the React side renders
 * a "Coming soon" placeholder for this tab.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Donors section.
 */
class Insights_Section_Donors {

	/**
	 * Display label for this tab. Must match the React tab label.
	 *
	 * @var string
	 */
	const SECTION_NAME = 'Donors';

	/**
	 * Initialize. Hooks REST endpoints for this tab when implementations
	 * arrive (NPPD-1618).
	 */
	public static function init() {
		self::register_hooks();
	}

	/**
	 * Register hooks for this section. Currently a stub.
	 */
	public static function register_hooks() {}
}
