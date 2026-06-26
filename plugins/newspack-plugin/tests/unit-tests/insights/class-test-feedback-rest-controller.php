<?php
/**
 * Test Feedback_REST_Controller (NPPD-1728).
 *
 * Exercises the feedback endpoint: route registration, the `manage_options`
 * permission gate, server-side attribution stamping (domain stamped from the
 * site), the freeform `comment` field, closed-allow-list validation of
 * `context` / `sentiment`, and the routing outcomes (success envelope, router
 * failure → 502, no router configured → 503). Routing is exercised through a
 * capturing test double installed via the `newspack_insights_feedback_router`
 * filter so no real Slack/Manager call is made.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Feedback\Feedback_Router;
use Newspack\Insights\Feedback_REST_Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Feedback_REST_Controller test class.
 *
 * @group insights
 */
class Test_Feedback_REST_Controller extends WP_UnitTestCase {

	const ROUTE = '/newspack-insights/v1/feedback';

	/**
	 * Records captured by the test-double router installed via the
	 * `newspack_insights_feedback_router` filter. Static so the test-double
	 * router can reach it.
	 *
	 * @var array[]
	 */
	public static $captured = [];

	/**
	 * Result the test-double router returns from send() (true or a WP_Error).
	 *
	 * @var true|WP_Error
	 */
	public static $send_result = true;

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Set up: an admin user, a registered feedback route, and a capturing
	 * router installed by default.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		add_action( 'rest_api_init', [ $this, 'register_feedback_route' ] );

		global $wp_rest_server;
		$this->server   = new WP_REST_Server();
		$wp_rest_server = $this->server;
		do_action( 'rest_api_init' );

		self::$captured    = [];
		self::$send_result = true;
		add_filter( 'newspack_insights_feedback_router', [ $this, 'use_capturing_router' ] );
	}

	/**
	 * Register the feedback route. Hooked to rest_api_init in set_up().
	 *
	 * @return void
	 */
	public function register_feedback_route() {
		( new Feedback_REST_Controller() )->register_routes();
	}

	/**
	 * Filter callback: force a capturing test-double router that records the
	 * assembled record into the test's statics and returns the configured
	 * result.
	 *
	 * @return Feedback_Router
	 */
	public function use_capturing_router(): Feedback_Router {
		return new class() implements Feedback_Router {
			/**
			 * Always available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Capture the record and return the configured result.
			 *
			 * @param array $record Assembled feedback record.
			 * @return true|\WP_Error
			 */
			public function send( array $record ) {
				Test_Feedback_REST_Controller::$captured[] = $record;
				return Test_Feedback_REST_Controller::$send_result;
			}
		};
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'newspack_insights_feedback_router', [ $this, 'use_capturing_router' ] );
		remove_action( 'rest_api_init', [ $this, 'register_feedback_route' ] );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Build + dispatch a POST to the feedback route.
	 *
	 * @param array $params Body params.
	 * @return \WP_REST_Response
	 */
	private function post( array $params ) {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * The route is registered.
	 */
	public function test_route_is_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( self::ROUTE, $routes );
	}

	/**
	 * A valid tier-1 thumb returns 200 with a success envelope and hands the
	 * router a record stamped with the site domain.
	 */
	public function test_valid_submission_routes_and_stamps_domain() {
		$response = $this->post(
			[
				'context'   => 'audience',
				'sentiment' => 'up',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'success' => true ], $response->get_data() );

		$this->assertCount( 1, self::$captured );
		$record = self::$captured[0];
		$this->assertSame( 'audience', $record['context'] );
		$this->assertSame( 'up', $record['sentiment'] );
		$this->assertSame( '', $record['comment'] );
		$this->assertSame( get_site_url(), $record['domain'] );
		$this->assertNotEmpty( $record['submitted_at'] );
	}

	/**
	 * The freeform comment is sanitized and carried through to the record.
	 */
	public function test_comment_is_recorded() {
		$this->post(
			[
				'context'   => 'engagement',
				'sentiment' => 'down',
				'comment'   => 'A subscriber churn breakdown would help.',
			]
		);

		$record = self::$captured[0];
		$this->assertSame( 'A subscriber churn breakdown would help.', $record['comment'] );
	}

	/**
	 * The client cannot assert the domain — only the server stamps it. A
	 * `domain` param in the request body is ignored.
	 */
	public function test_client_supplied_domain_is_ignored() {
		$this->post(
			[
				'context'   => 'audience',
				'sentiment' => 'up',
				'domain'    => 'https://evil.example',
			]
		);

		$record = self::$captured[0];
		$this->assertSame( get_site_url(), $record['domain'] );
	}

	/**
	 * A non-admin cannot submit.
	 */
	public function test_requires_manage_options() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$response = $this->post(
			[
				'context'   => 'audience',
				'sentiment' => 'up',
			]
		);
		$this->assertSame( 403, $response->get_status() );
		$this->assertCount( 0, self::$captured );
	}

	/**
	 * An out-of-allow-list context is rejected at validation (400).
	 */
	public function test_invalid_context_is_rejected() {
		$response = $this->post(
			[
				'context'   => 'not-a-tab',
				'sentiment' => 'up',
			]
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertCount( 0, self::$captured );
	}

	/**
	 * An out-of-allow-list sentiment is rejected at validation (400).
	 */
	public function test_invalid_sentiment_is_rejected() {
		$response = $this->post(
			[
				'context'   => 'audience',
				'sentiment' => 'meh',
			]
		);
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * A router failure surfaces a generic 502 (router internals stay
	 * server-side).
	 */
	public function test_router_failure_returns_502() {
		self::$send_result = new WP_Error( 'boom', 'nope' );
		$response                          = $this->post(
			[
				'context'   => 'audience',
				'sentiment' => 'up',
			]
		);
		$this->assertSame( 502, $response->get_status() );
	}

	/**
	 * With no router configured, the endpoint reports 503 rather than silently
	 * dropping the submission.
	 */
	public function test_no_router_returns_503() {
		remove_filter( 'newspack_insights_feedback_router', [ $this, 'use_capturing_router' ] );
		$response = $this->post(
			[
				'context'   => 'audience',
				'sentiment' => 'up',
			]
		);
		$this->assertSame( 503, $response->get_status() );
	}
}
