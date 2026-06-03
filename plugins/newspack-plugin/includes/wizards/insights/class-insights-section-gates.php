<?php
/**
 * Newspack Insights — Gates section (NPPD-1602).
 *
 * Gates tab scope: per-gate performance. Regwall, paywall, soft/hard
 * gates — impressions, registrations, paid checkouts, attributed
 * revenue. The "instrumentation surface" view of the conversion
 * journey, broken down per gate configuration.
 *
 * Future REST endpoints for the Gates tab register from
 * {@see self::register_hooks()}. Currently a stub; the React side renders
 * a "Coming soon" placeholder for this tab.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Gates section.
 */
class Insights_Section_Gates {

	/**
	 * Display label for this tab. Must match the React tab label.
	 *
	 * @var string
	 */
	const SECTION_NAME = 'Gates';

	/**
	 * Initialize. Hooks REST endpoints for this tab when implementations
	 * arrive (NPPD-1609).
	 */
	public static function init() {
		self::register_hooks();
	}

	/**
	 * Register hooks for this section. Currently a stub.
	 */
	public static function register_hooks() {}
}
