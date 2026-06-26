<?php
/**
 * Newspack Insights — Channel-email feedback router (fallback).
 *
 * Sends the feedback record via `wp_mail` to a Slack channel's email-to-channel
 * address. This is a real, working path — not a stub — so the feature can ship
 * at GA even if the Manager relay (the primary router) isn't deployed yet. The
 * message renders as an email card in the Slack channel; plainer than the
 * relay's formatted post, but enough for triage (NPPD-1728).
 *
 * The destination address is configuration, never hardcoded:
 *   - `NEWSPACK_INSIGHTS_FEEDBACK_CHANNEL_EMAIL` constant (wp-config), or
 *   - the `newspack_insights_feedback_channel_email` filter (wins over the constant).
 *
 * @package Newspack
 */

namespace Newspack\Insights\Feedback;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Routes feedback to a Slack channel email address via wp_mail.
 */
class Channel_Email_Router implements Feedback_Router {


	/**
	 * Resolve the destination channel email address.
	 *
	 * @return string Empty string when not configured.
	 */
	private function channel_address(): string {
		$address = defined( 'NEWSPACK_INSIGHTS_FEEDBACK_CHANNEL_EMAIL' )
		? (string) NEWSPACK_INSIGHTS_FEEDBACK_CHANNEL_EMAIL
		: '';
		/**
		 * Filter the Slack channel email address feedback is routed to when the
		 * email fallback router is active.
		 *
		 * @param string $address Configured channel email address.
		 */
		$address = (string) apply_filters( 'newspack_insights_feedback_channel_email', $address );
		return is_email( $address ) ? $address : '';
	}

	/**
	 * Available when a valid channel email address is configured.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return '' !== $this->channel_address();
	}

	/**
	 * Email the record to the configured channel address.
	 *
	 * @param  array $record Assembled feedback record.
	 * @return true|WP_Error
	 */
	public function send( array $record ) {
		$address = $this->channel_address();
		if ( '' === $address ) {
			return new WP_Error(
				'newspack_insights_feedback_email_unconfigured',
				__( 'No feedback channel email address is configured.', 'newspack-plugin' )
			);
		}

		// One transactional email per feedback submission (a single admin's
		// thumb click), never bulk — the VIP bulk-mail caution doesn't apply.
		$sent = wp_mail( $address, $this->build_subject( $record ), $this->build_body( $record ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		if ( ! $sent ) {
			return new WP_Error(
				'newspack_insights_feedback_email_failed',
				__( 'Feedback email could not be sent.', 'newspack-plugin' )
			);
		}
		return true;
	}

	/**
	 * Build the email subject line.
	 *
	 * @param  array $record Assembled feedback record.
	 * @return string
	 */
	private function build_subject( array $record ): string {
		$sentiment = 'up' === ( $record['sentiment'] ?? '' )
		? __( '👍 positive', 'newspack-plugin' )
		: __( '👎 negative', 'newspack-plugin' );
		return sprintf(
		/* translators: 1: tab id (e.g. "audience"); 2: sentiment label. */
			__( 'Insights feedback — %1$s (%2$s)', 'newspack-plugin' ),
			(string) ( $record['context'] ?? '' ),
			$sentiment
		);
	}

	/**
	 * Build the plain-text email body. Mirrors the record fields the relay
	 * would format for Slack; attribution is the server-stamped publisher
	 * domain.
	 *
	 * @param  array $record Assembled feedback record.
	 * @return string
	 */
	private function build_body( array $record ): string {
		$lines = [
			sprintf( '%s: %s', __( 'Tab', 'newspack-plugin' ), (string) ( $record['context'] ?? '' ) ),
			sprintf( '%s: %s', __( 'Sentiment', 'newspack-plugin' ), (string) ( $record['sentiment'] ?? '' ) ),
			sprintf( '%s: %s', __( 'From', 'newspack-plugin' ), (string) ( $record['domain'] ?? __( 'unknown', 'newspack-plugin' ) ) ),
			sprintf( '%s: %s', __( 'Submitted', 'newspack-plugin' ), (string) ( $record['submitted_at'] ?? '' ) ),
		];
		if ( '' !== (string) ( $record['comment'] ?? '' ) ) {
			$lines[] = sprintf( "%s:\n%s", __( 'Comment', 'newspack-plugin' ), (string) $record['comment'] );
		}
		return implode( "\n", $lines );
	}
}
