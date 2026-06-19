<?php
/**
 * Newspack Insights — Feedback feature loader (NPPD-1728).
 *
 * Wires up the per-tab publisher feedback affordance's server side: the router
 * seam (interface + Manager relay + email fallback + factory) and the
 * `POST /newspack-insights/v1/feedback` REST endpoint.
 *
 * Unlike the `Insights_Section_*` classes this is not a tab — there's no
 * metric data layer and no tab visibility. It's a cross-cutting affordance the
 * React chrome renders into every tab's footer, so it loads once alongside the
 * sections rather than per tab. Self-gates on the Insights feature flag like
 * the sections do.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Insights\Feedback_REST_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Feedback feature loader.
 */
class Insights_Feedback {

	/**
	 * Initialize. Bails early when the parent Insights feature flag is off.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! Insights_Wizard::is_enabled() ) {
			return;
		}
		self::load_dependencies();
		self::register_hooks();
	}

	/**
	 * Include the feedback PHP files.
	 *
	 * @return void
	 */
	private static function load_dependencies(): void {
		$base = NEWSPACK_ABSPATH . 'includes/wizards/insights/';
		include_once $base . 'feedback/interface-feedback-router.php';
		include_once $base . 'feedback/class-manager-relay-router.php';
		include_once $base . 'feedback/class-channel-email-router.php';
		include_once $base . 'feedback/class-feedback-router-factory.php';
		include_once $base . 'api/class-feedback-rest-controller.php';
	}

	/**
	 * Register the feedback REST route.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_feedback_route' ] );
	}

	/**
	 * Register the feedback REST controller's routes. Hooked to rest_api_init.
	 *
	 * @return void
	 */
	public static function register_feedback_route(): void {
		( new Feedback_REST_Controller() )->register_routes();
	}
}
