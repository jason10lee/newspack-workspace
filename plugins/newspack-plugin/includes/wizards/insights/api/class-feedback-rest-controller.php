<?php
/**
 * Newspack Insights — Feedback REST controller (NPPD-1728).
 *
 * Single endpoint: `POST /newspack-insights/v1/feedback`. Accepts a per-tab
 * feedback submission, assembles a server-stamped record, and hands it to the
 * configured {@see \Newspack\Insights\Feedback\Feedback_Router}.
 *
 * Attribution is stamped server-side and never trusted from the client: the
 * publisher domain comes from `get_site_url()`. (v1 attribution is domain-only;
 * role is intentionally not stamped — every caller already has
 * `manage_options`, so role would be noise. See NPPD-1728.)
 *
 * Routing is Slack-only in v1 via the router seam; this controller stores
 * nothing and the routers store nothing. Durable capture is a deliberate v2
 * decision.
 *
 * Permission mirrors the data tabs (`manage_options`); the form is only
 * reachable by users who can see Insights.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use Newspack\Insights\Feedback\Feedback_Router_Factory;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WP_REST_Controller;

/**
 * Feedback REST controller.
 */
class Feedback_REST_Controller extends WP_REST_Controller {


	/**
	 * Shared Insights namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'newspack-insights/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'feedback';

	/**
	 * Allowed `context` values — the Insights tab slugs the affordance can be
	 * mounted on. Keeps `context` to a known set rather than free text, while
	 * staying the seam a later product surface can extend.
	 *
	 * @var string[]
	 */
	const ALLOWED_CONTEXTS = [
		'audience',
		'engagement',
		'conversion',
		'gates',
		'prompts',
		'subscribers',
		'donors',
		'advertising',
	];

	/**
	 * Allowed `sentiment` values for the tier-1 thumb.
	 *
	 * @var string[]
	 */
	const ALLOWED_SENTIMENTS = [ 'up', 'down' ];

	/**
	 * Register the feedback route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'submit_feedback' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => $this->get_endpoint_args(),
				],
			]
		);
	}

	/**
	 * Permission check. Mirrors the Insights data controllers: any user who can
	 * see Insights (`manage_options`) can submit feedback.
	 *
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'newspack_insights_rest_forbidden',
				__( 'You do not have permission to submit Insights feedback.', 'newspack-plugin' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * POST handler. Assembles the record, routes it, and returns a thin success
	 * envelope the client uses to fire its acknowledgment.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function submit_feedback( WP_REST_Request $request ) {
		$record = $this->build_record( $request );

		$router = Feedback_Router_Factory::get_router();
		if ( null === $router ) {
			return new WP_Error(
				'newspack_insights_feedback_no_router',
				__( 'Feedback routing is not configured for this site.', 'newspack-plugin' ),
				[ 'status' => 503 ]
			);
		}

		$result = $router->send( $record );
		if ( is_wp_error( $result ) ) {
			// Surface a generic 502 to the client; the specific failure is
			// logged server-side by the router. The submitter shouldn't see
			// relay internals.
			return new WP_Error(
				'newspack_insights_feedback_routing_failed',
				__( 'Your feedback could not be sent. Please try again later.', 'newspack-plugin' ),
				[ 'status' => 502 ]
			);
		}

		return rest_ensure_response( [ 'success' => true ] );
	}

	/**
	 * Assemble the sanitized, server-stamped record from the request. The
	 * domain is always stamped from `get_site_url()`; the client is never
	 * trusted to assert attribution.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return array{
	 *     context: string,
	 *     sentiment: string,
	 *     comment: string,
	 *     domain: string,
	 *     submitted_at: string
	 * }
	 */
	private function build_record( WP_REST_Request $request ): array {
		return [
			'context'      => (string) $request->get_param( 'context' ),
			'sentiment'    => (string) $request->get_param( 'sentiment' ),
			'comment'      => (string) ( $request->get_param( 'comment' ) ?? '' ),
			'domain'       => get_site_url(),
			'submitted_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		];
	}

	/**
	 * Endpoint args spec. `context` and `sentiment` are validated against
	 * closed allow-lists; the optional tier-2 `comment` is sanitized free text.
	 *
	 * @return array
	 */
	private function get_endpoint_args(): array {
		return [
			'context'   => [
				'type'              => 'string',
				'required'          => true,
				'enum'              => self::ALLOWED_CONTEXTS,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
				'description'       => __( 'Insights tab the feedback is about.', 'newspack-plugin' ),
			],
			'sentiment' => [
				'type'              => 'string',
				'required'          => true,
				'enum'              => self::ALLOWED_SENTIMENTS,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
				'description'       => __( 'Tier-1 thumb sentiment.', 'newspack-plugin' ),
			],
			'comment'   => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
				'description'       => __( 'Tier-2 freeform comment. Empty when the modal is dismissed.', 'newspack-plugin' ),
			],
		];
	}
}
