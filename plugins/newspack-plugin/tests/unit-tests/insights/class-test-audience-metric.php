<?php
/**
 * Test Audience_Metric registered-readers counts (NPPD-1733).
 *
 * The one Audience metric sourced from local wp_users rather than GA4. Covers
 * the is_user_reader()-equivalent role filter (reader roles minus staff), the
 * inclusive registration window, the honest-zero baseline, and the
 * not-computable guard.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Audience_Metric;
use WP_UnitTestCase;

/**
 * Audience_Metric registered-readers test class.
 *
 * @group insights
 */
class Test_Audience_Metric extends WP_UnitTestCase {

	/**
	 * Create a user with a role and an explicit registration datetime (UTC, as
	 * WordPress stores `user_registered`).
	 *
	 * @param string $role       Role slug.
	 * @param string $registered `Y-m-d H:i:s` in UTC.
	 * @return int User ID.
	 */
	private function make_user( string $role, string $registered ): int {
		return self::factory()->user->create(
			[
				'role'            => $role,
				'user_registered' => $registered,
			]
		);
	}

	/**
	 * Total counts the configured reader roles (subscriber + customer) and
	 * excludes staff (administrator + editor) and non-reader roles (author).
	 */
	public function test_total_counts_reader_roles_excluding_staff() {
		$this->make_user( 'subscriber', '2026-01-10 12:00:00' );
		$this->make_user( 'subscriber', '2026-02-10 12:00:00' );
		$this->make_user( 'customer', '2026-03-10 12:00:00' );
		$this->make_user( 'administrator', '2026-01-05 12:00:00' );
		$this->make_user( 'editor', '2026-01-06 12:00:00' );
		$this->make_user( 'author', '2026-01-07 12:00:00' );

		$payload = Audience_Metric::registered_readers_total();

		$this->assertTrue( $payload['computable'] );
		$this->assertSame( 'count', $payload['type'] );
		$this->assertSame( 3, $payload['value'], 'Counts subscriber + customer; excludes admin, editor, author.' );
	}

	/**
	 * New counts only accounts registered within the window.
	 */
	public function test_new_counts_only_within_window() {
		$this->make_user( 'subscriber', '2026-01-15 12:00:00' );
		$this->make_user( 'customer', '2026-01-20 12:00:00' );
		$this->make_user( 'subscriber', '2025-12-31 12:00:00' );
		$this->make_user( 'subscriber', '2026-02-01 12:00:00' );

		$payload = Audience_Metric::registered_readers_new( '2026-01-01', '2026-01-31' );

		$this->assertTrue( $payload['computable'] );
		$this->assertSame( 2, $payload['value'] );
	}

	/**
	 * The window is inclusive of both calendar boundaries (00:00:00 → 23:59:59).
	 */
	public function test_window_bounds_are_inclusive() {
		$this->make_user( 'subscriber', '2026-01-01 00:00:00' );
		$this->make_user( 'subscriber', '2026-01-31 23:59:59' );

		$payload = Audience_Metric::registered_readers_new( '2026-01-01', '2026-01-31' );

		$this->assertSame( 2, $payload['value'] );
	}

	/**
	 * No reader accounts → an honest, computable 0 (NOT a not-computable state):
	 * a new publisher's real zero, per NPPD-1733's empty-state contract.
	 */
	public function test_new_publisher_zero_is_computable() {
		$payload = Audience_Metric::registered_readers_total();

		$this->assertTrue( $payload['computable'] );
		$this->assertSame( 0, $payload['value'] );
	}

	/**
	 * When no reader roles are configured the count is genuinely unknowable, so
	 * the metric reports not-computable (the UI's em-dash treatment) rather than a
	 * misleading 0.
	 */
	public function test_not_computable_when_no_reader_roles() {
		add_filter( 'newspack_reader_user_roles', '__return_empty_array' );
		$payload = Audience_Metric::registered_readers_total();
		remove_filter( 'newspack_reader_user_roles', '__return_empty_array' );

		$this->assertFalse( $payload['computable'] );
		$this->assertNull( $payload['value'] );
	}
}
