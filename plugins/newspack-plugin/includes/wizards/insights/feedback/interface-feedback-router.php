<?php
/**
 * Newspack Insights — Feedback router contract.
 *
 * A router is the swappable destination for a publisher feedback record.
 * Implementations forward the record to a triage surface (Slack, today) and
 * return success or a `WP_Error`. Routers are deliberately stateless: they
 * forward and forget. Durable storage / aggregation is a separate v2 decision
 * and must NOT be added here (NPPD-1728).
 *
 * @package Newspack
 */

namespace Newspack\Insights\Feedback;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Swappable feedback destination.
 */
interface Feedback_Router {


	/**
	 * Whether this router is configured and can attempt a send right now.
	 *
	 * Used by the factory to pick a usable router (e.g. fall back to email
	 * when the Manager relay isn't connected yet).
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Forward a single feedback record to the triage surface.
	 *
	 * The record is the server-assembled, server-stamped payload (see
	 * {@see \Newspack\Insights\Feedback_REST_Controller::build_record()}):
	 * already sanitized, with `domain` stamped server-side — never trust the
	 * client for attribution. Keys: `context`, `sentiment`, `comment`,
	 * `domain`, `submitted_at`.
	 *
	 * @param array $record Assembled feedback record.
	 * @return true|WP_Error True on success; WP_Error on any failure path.
	 */
	public function send( array $record );
}
