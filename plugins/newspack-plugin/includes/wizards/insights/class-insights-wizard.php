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
			// Tab visibility. Real computation (feature detection: GAM
			// dataset presence, scroll event presence, non-donation
			// subscription product count, donation activity count) needs
			// the BigQuery wrapper (NPPD-1598) plus Woo queries. Stubbed
			// to all-on for now per the prompt's scope note.
			'tabs'              => [
				'audience'    => true,
				'engagement'  => true,
				'conversion'  => true,
				'gates'       => true,
				'prompts'     => true,
				'subscribers' => true,
				'donors'      => true,
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
