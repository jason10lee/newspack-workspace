<?php
/**
 * Newspack Insights — Feedback router factory.
 *
 * Picks the active {@see Feedback_Router} so the destination is swappable
 * without the REST controller knowing which one is in play (NPPD-1728).
 *
 * Selection order:
 *   1. `newspack_insights_feedback_router` filter — a full override. Return a
 *      `Feedback_Router` instance to force it (tests, bespoke deployments).
 *   2. `NEWSPACK_INSIGHTS_FEEDBACK_FORCE_EMAIL` constant — pin to the email
 *      fallback (e.g. while the Manager relay is still pre-deploy), provided
 *      the email router is actually configured.
 *   3. The Manager relay when it's available (the default in production).
 *   4. The email fallback when the relay isn't available but email is.
 *   5. Null — nothing is configured; the controller surfaces a clear error.
 *
 * @package Newspack
 */

namespace Newspack\Insights\Feedback;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the active feedback router.
 */
class Feedback_Router_Factory {


	/**
	 * Resolve the router to use for this submission.
	 *
	 * @return Feedback_Router|null Null when no router is configured.
	 */
	public static function get_router(): ?Feedback_Router {
		/**
		 * Filter the active feedback router. Return a Feedback_Router instance
		 * to override the built-in selection entirely.
		 *
		 * @param Feedback_Router|null $router Router override, or null to use the default selection.
		 */
		$override = apply_filters( 'newspack_insights_feedback_router', null );
		if ( $override instanceof Feedback_Router ) {
			return $override;
		}

		$relay = new Manager_Relay_Router();
		$email = new Channel_Email_Router();

		$force_email = defined( 'NEWSPACK_INSIGHTS_FEEDBACK_FORCE_EMAIL' ) && NEWSPACK_INSIGHTS_FEEDBACK_FORCE_EMAIL;
		if ( $force_email && $email->is_available() ) {
			return $email;
		}

		if ( $relay->is_available() ) {
			return $relay;
		}
		if ( $email->is_available() ) {
			return $email;
		}
		return null;
	}
}
