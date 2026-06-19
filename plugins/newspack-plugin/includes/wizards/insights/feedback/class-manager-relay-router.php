<?php
/**
 * Newspack Insights — Manager relay feedback router (primary).
 *
 * Routes a publisher feedback record to Newspack Manager, which holds the
 * single Slack incoming-webhook secret, formats the Slack message, posts it,
 * and stores nothing. The webhook secret therefore never lives on a publisher
 * site — only Manager has it (NPPD-1728).
 *
 * Mirrors {@see \Newspack\Insights\BigQuery_Proxy_Client}: same auth resolution
 * (`Newspack_Manager::authenticate_manager_admin_url()` + api key), same
 * `wp_remote_post` envelope, same identifying User-Agent, same WP_Error-on-
 * every-failure-path discipline.
 *
 * The Manager-side relay endpoint (`insights-feedback`) is a separate
 * workstream in the Manager repo; until it's deployed this router reports
 * `is_available() === false` when Manager isn't connected, and the factory
 * falls back to {@see Channel_Email_Router} so GA is never hard-blocked on
 * Manager deploy timing.
 *
 * @package Newspack
 */

namespace Newspack\Insights\Feedback;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Forwards feedback to the Manager relay endpoint.
 */
class Manager_Relay_Router implements Feedback_Router {


	/**
	 * Manager relay route, appended to the authenticated admin URL. Manager
	 * owns the Slack webhook behind this; the plugin only forwards.
	 *
	 * @var string
	 */
	const RELAY_ROUTE = '/wp-json/newspack-manager-admin/v1/insights-feedback';

	/**
	 * Request timeout in seconds. Kept short — the relay is posted inline during
	 * the publisher's submit, so a slow hub must not stall that request. (v2
	 * should move this to an async dispatch and drop the wait entirely.)
	 *
	 * @var int
	 */
	const REQUEST_TIMEOUT = 5;

	/**
	 * Logger header so all relay failures land in one Logstash bucket.
	 *
	 * @var string
	 */
	const LOGGER_HEADER = 'NEWSPACK-INSIGHTS-FEEDBACK';

	/**
	 * The authenticated hub endpoint URL (api_key already appended), or null
	 * when Manager isn't loaded / connected.
	 *
	 * @var string|null
	 */
	private ?string $endpoint_url;

	/**
	 * The hub api key, used as a configuration sentinel (the URL already
	 * embeds it).
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Constructor.
	 *
	 * Resolves the hub URL via `Newspack_Manager::authenticate_manager_admin_url()`
	 * when not explicitly provided. Pass explicit values in tests.
	 *
	 * @param string|null $endpoint_url Pre-authenticated hub URL (with api_key). Null to resolve at construction.
	 * @param string|null $api_key      The hub API key. Null to resolve at construction.
	 */
	public function __construct( ?string $endpoint_url = null, ?string $api_key = null ) {
		if ( null === $endpoint_url || null === $api_key ) {
			[ $resolved_url, $resolved_key ] = self::resolve_config();
			$endpoint_url                    = $endpoint_url ?? $resolved_url;
			$api_key                         = $api_key ?? $resolved_key;
		}
		$this->endpoint_url = '' === (string) $endpoint_url ? null : (string) $endpoint_url;
		$this->api_key      = (string) $api_key;
	}

	/**
	 * Resolve the hub URL and api key from the running `Newspack_Manager` plugin.
	 *
	 * @return array{0: string|null, 1: string} `[ $url, $api_key ]`. URL is null if Manager isn't loaded or isn't connected.
	 */
	private static function resolve_config(): array {
		if ( ! class_exists( '\Newspack_Manager' ) ) {
			return [ null, '' ];
		}
		$url     = \Newspack_Manager::authenticate_manager_admin_url( self::RELAY_ROUTE );
		$api_key = \Newspack_Manager::get_manager_admin_api_key();
		return [ false === $url ? null : (string) $url, (string) $api_key ];
	}

	/**
	 * Whether the relay has both a URL and a key (i.e. can attempt a call).
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return null !== $this->endpoint_url && '' !== $this->api_key;
	}

	/**
	 * Forward the record to the Manager relay. Returns true on a 2xx, WP_Error
	 * on any failure path.
	 *
	 * @param  array $record Assembled feedback record.
	 * @return true|WP_Error
	 */
	public function send( array $record ) {
		if ( ! $this->is_available() ) {
			$error = new WP_Error(
				'newspack_insights_feedback_relay_unconfigured',
				__( 'Newspack Manager is not configured for feedback routing.', 'newspack-plugin' )
			);
			$this->log_failure( $error, $record );
			return $error;
		}

		$response = wp_remote_post(
			$this->endpoint_url,
			[
				'headers' => [
					'Content-Type' => 'application/json',
					'User-Agent'   => 'Newspack-Insights/1.0; ' . home_url(),
				],
				'body'    => wp_json_encode( $record ),
				'timeout' => self::REQUEST_TIMEOUT,
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->log_failure( $response, $record );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$raw     = wp_remote_retrieve_body( $response );
			$decoded = json_validate( $raw ) ? json_decode( $raw, true ) : null;
			$message = is_array( $decoded ) && isset( $decoded['message'] )
			? (string) $decoded['message']
			: sprintf( 'HTTP %d', $code );
			$error   = new WP_Error( 'newspack_insights_feedback_relay_http_error', $message, [ 'status' => $code ] );
			$this->log_failure( $error, $record );
			return $error;
		}

		return true;
	}

	/**
	 * Emit a `newspack_log` action about a relay failure. Picked up by Newspack
	 * Manager and forwarded to Logstash. Safe to call even when Logger isn't
	 * loaded (no-op).
	 *
	 * The record's free-text comment is deliberately omitted from the log to
	 * avoid duplicating publisher prose into Logstash; only routing context is
	 * logged.
	 *
	 * @param  WP_Error $error  The error being returned to the caller.
	 * @param  array    $record The record that failed to route.
	 * @return void
	 */
	private function log_failure( WP_Error $error, array $record ): void {
		if ( ! class_exists( '\Newspack\Logger' ) ) {
			return;
		}
		\Newspack\Logger::newspack_log(
			'newspack_insights_feedback_relay_failed',
			sprintf( '%s: %s', $error->get_error_code(), $error->get_error_message() ),
			[
				'context'     => $record['context'] ?? null,
				'sentiment'   => $record['sentiment'] ?? null,
				'error_code'  => $error->get_error_code(),
				'http_status' => $error->get_error_data()['status'] ?? null,
				'header'      => self::LOGGER_HEADER,
			],
			'error'
		);
	}
}
