<?php
/**
 * Newspack Insights — Audience section (NPPD-1602).
 *
 * Audience tab scope: who is reading, where they come from, how
 * engaged the audience is at a high level. The "lobby" of the Insights
 * page — answers "what's the shape of my audience this period?"
 *
 * Future REST endpoints for the Audience tab register from
 * {@see self::register_hooks()}. Currently a stub; the React side renders
 * a "Coming soon" placeholder for this tab.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Audience section.
 */
class Insights_Section_Audience {

	/**
	 * Display label for this tab. Must match the React tab label.
	 *
	 * @var string
	 */
	const SECTION_NAME = 'Audience';

	/**
	 * Initialize. Hooks REST endpoints for this tab when implementations
	 * arrive (NPPD-1608).
	 */
	public static function init() {
		if ( ! Insights_Wizard::is_enabled() ) {
			return;
		}
		self::register_hooks();
	}

	/**
	 * Register hooks for this section. Currently a stub.
	 */
	public static function register_hooks() {}
}
