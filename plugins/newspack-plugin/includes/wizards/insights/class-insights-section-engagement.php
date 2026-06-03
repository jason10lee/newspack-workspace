<?php
/**
 * Newspack Insights — Engagement section (NPPD-1602).
 *
 * Engagement tab scope: how deeply readers interact with content —
 * scroll depth, time on page, return visits. Distinct from Audience
 * (who) by focusing on behavior depth (how).
 *
 * Future REST endpoints for the Engagement tab register from
 * {@see self::register_hooks()}. Currently a stub; the React side renders
 * a "Coming soon" placeholder for this tab.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Engagement section.
 */
class Insights_Section_Engagement {

	/**
	 * Display label for this tab. Must match the React tab label.
	 *
	 * @var string
	 */
	const SECTION_NAME = 'Engagement';

	/**
	 * Initialize. Hooks REST endpoints for this tab when implementations
	 * arrive (NPPD-1607).
	 */
	public static function init() {
		self::register_hooks();
	}

	/**
	 * Register hooks for this section. Currently a stub.
	 */
	public static function register_hooks() {}
}
