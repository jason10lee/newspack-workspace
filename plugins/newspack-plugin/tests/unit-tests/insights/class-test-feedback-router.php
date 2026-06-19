<?php
/**
 * Test the feedback router seam (NPPD-1728).
 *
 * Covers the factory's selection order, the Manager relay's HTTP envelope
 * (modeled on BigQuery_Proxy_Client), and the email fallback's wp_mail path.
 * No real Slack/Manager/SMTP call is made: HTTP is intercepted via
 * `pre_http_request` and mail via `pre_wp_mail`.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Feedback\Channel_Email_Router;
use Newspack\Insights\Feedback\Feedback_Router;
use Newspack\Insights\Feedback\Feedback_Router_Factory;
use Newspack\Insights\Feedback\Manager_Relay_Router;
use WP_UnitTestCase;

/**
 * Feedback router seam test class.
 *
 * @group insights
 */
class Test_Feedback_Router extends WP_UnitTestCase {

	/**
	 * Captured HTTP request args from pre_http_request.
	 *
	 * @var array|null
	 */
	private $captured_http = null;

	/**
	 * Captured wp_mail atts from pre_wp_mail.
	 *
	 * @var array|null
	 */
	private $captured_mail = null;

	/**
	 * A representative assembled record.
	 *
	 * @return array
	 */
	private function record(): array {
		return [
			'context'      => 'audience',
			'sentiment'    => 'up',
			'comment'      => '',
			'domain'       => 'https://example.test',
			'submitted_at' => '2026-06-18T00:00:00Z',
		];
	}

	/* --- Manager relay ------------------------------------------------- */

	/**
	 * The relay is unavailable with no URL / key, and a send attempt returns a
	 * WP_Error rather than making a request.
	 */
	public function test_relay_unconfigured_is_unavailable() {
		$router = new Manager_Relay_Router( '', '' );
		$this->assertFalse( $router->is_available() );
		$result = $router->send( $this->record() );
		$this->assertWPError( $result );
	}

	/**
	 * A configured relay POSTs the JSON record to the authenticated URL and
	 * returns true on a 2xx.
	 */
	public function test_relay_posts_record_and_succeeds() {
		add_filter( 'pre_http_request', [ $this, 'capture_http_ok' ], 10, 3 );

		$router = new Manager_Relay_Router( 'https://hub.test/relay?api_key=k', 'k' );
		$this->assertTrue( $router->is_available() );
		$result = $router->send( $this->record() );

		remove_filter( 'pre_http_request', [ $this, 'capture_http_ok' ], 10 );

		$this->assertTrue( $result );
		$this->assertNotNull( $this->captured_http );
		$this->assertSame( 'POST', $this->captured_http['args']['method'] );
		$this->assertSame( 'https://hub.test/relay?api_key=k', $this->captured_http['url'] );
		$decoded = json_decode( $this->captured_http['args']['body'], true );
		$this->assertSame( 'audience', $decoded['context'] );
		$this->assertSame( 'up', $decoded['sentiment'] );
	}

	/**
	 * A non-2xx from the relay returns a WP_Error.
	 */
	public function test_relay_http_error_returns_wp_error() {
		add_filter( 'pre_http_request', [ $this, 'capture_http_500' ], 10, 3 );
		$router = new Manager_Relay_Router( 'https://hub.test/relay?api_key=k', 'k' );
		$result = $router->send( $this->record() );
		remove_filter( 'pre_http_request', [ $this, 'capture_http_500' ], 10 );
		$this->assertWPError( $result );
	}

	/* --- Email fallback ------------------------------------------------ */

	/**
	 * The email router is unavailable until a valid channel address is
	 * configured.
	 */
	public function test_email_unconfigured_is_unavailable() {
		$this->assertFalse( ( new Channel_Email_Router() )->is_available() );
	}

	/**
	 * With a configured channel address, the email router sends a wp_mail to
	 * that address and returns true.
	 */
	public function test_email_sends_to_configured_channel() {
		add_filter( 'newspack_insights_feedback_channel_email', [ $this, 'channel_address' ] );
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );

		$router = new Channel_Email_Router();
		$this->assertTrue( $router->is_available() );
		$result = $router->send( $this->record() );

		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		remove_filter( 'newspack_insights_feedback_channel_email', [ $this, 'channel_address' ] );

		$this->assertTrue( $result );
		$this->assertNotNull( $this->captured_mail );
		$this->assertSame( 'channel@example.test', $this->captured_mail['to'] );
		$this->assertStringContainsString( 'audience', $this->captured_mail['subject'] );
	}

	/* --- Factory selection --------------------------------------------- */

	/**
	 * A filter override wins outright.
	 */
	public function test_factory_honors_override_filter() {
		$double = new class() implements Feedback_Router {
			/**
			 * Always available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * No-op send.
			 *
			 * @param array $record Assembled feedback record.
			 * @return true
			 */
			public function send( array $record ) {
				return true;
			}
		};
		add_filter(
			'newspack_insights_feedback_router',
			function () use ( $double ) {
				return $double;
			}
		);
		$router = Feedback_Router_Factory::get_router();
		remove_all_filters( 'newspack_insights_feedback_router' );
		$this->assertSame( $double, $router );
	}

	/**
	 * With the relay unavailable but a channel address configured, the factory
	 * falls back to the email router.
	 */
	public function test_factory_falls_back_to_email() {
		add_filter( 'newspack_insights_feedback_channel_email', [ $this, 'channel_address' ] );
		$router = Feedback_Router_Factory::get_router();
		remove_filter( 'newspack_insights_feedback_channel_email', [ $this, 'channel_address' ] );
		$this->assertInstanceOf( Channel_Email_Router::class, $router );
	}

	/**
	 * With nothing configured, the factory returns null.
	 */
	public function test_factory_returns_null_when_unconfigured() {
		$this->assertNull( Feedback_Router_Factory::get_router() );
	}

	/* --- Filter / capture callbacks ------------------------------------ */

	/**
	 * Channel address filter callback.
	 *
	 * @return string
	 */
	public function channel_address(): string {
		return 'channel@example.test';
	}

	/**
	 * Capture an HTTP request and return a 200.
	 *
	 * @param mixed  $preempt Pre-emptive response (unused).
	 * @param array  $args    Request args.
	 * @param string $url     Request URL.
	 * @return array
	 */
	public function capture_http_ok( $preempt, $args, $url ): array {
		$this->captured_http = [
			'args' => $args,
			'url'  => $url,
		];
		return [
			'response' => [ 'code' => 200 ],
			'body'     => '{"ok":true}',
		];
	}

	/**
	 * Capture an HTTP request and return a 500.
	 *
	 * @param mixed  $preempt Pre-emptive response (unused).
	 * @param array  $args    Request args.
	 * @param string $url     Request URL.
	 * @return array
	 */
	public function capture_http_500( $preempt, $args, $url ): array {
		$this->captured_http = [
			'args' => $args,
			'url'  => $url,
		];
		return [
			'response' => [ 'code' => 500 ],
			'body'     => '{"message":"server error"}',
		];
	}

	/**
	 * Short-circuit wp_mail and capture its atts.
	 *
	 * @param null  $preempt Short-circuit value (unused).
	 * @param array $atts    wp_mail atts.
	 * @return bool
	 */
	public function capture_mail( $preempt, $atts ): bool {
		$this->captured_mail = $atts;
		return true;
	}
}
