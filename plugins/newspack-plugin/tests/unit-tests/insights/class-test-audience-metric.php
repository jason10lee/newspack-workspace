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
use Newspack\Insights\Donation_Product_Classifier;
use WP_UnitTestCase;

// Supporter-type detection (NPPD-1767) reads `WC_Subscriptions` + `wc_get_products`;
// the unit bootstrap loads neither, so pull in the shared WC stubs for those tests.
require_once __DIR__ . '/../../mocks/wc-mocks.php';

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

	/**
	 * Reset the supporter-detection seams between tests: the staged subscription
	 * product IDs, the classifier cache, and the canonical donation option.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['newspack_test_wc_products'] );
		delete_transient( Donation_Product_Classifier::TRANSIENT_KEY );
		delete_option( 'newspack_donation_product_id' );
		parent::tear_down();
	}

	/**
	 * Invoke the private `detect_supporter_products()` directly.
	 *
	 * @return array{subscriptions:bool,donations:bool}
	 */
	private function detect_supporter_products(): array {
		$method = new \ReflectionMethod( Audience_Metric::class, 'detect_supporter_products' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	/**
	 * Seed the donation classifier's cache so `get_donation_product_ids()` returns
	 * the given set without touching real products/options.
	 *
	 * @param int[] $ids Donation product IDs.
	 */
	private function set_donation_product_ids( array $ids ): void {
		set_transient( Donation_Product_Classifier::TRANSIENT_KEY, $ids, HOUR_IN_SECONDS );
	}

	/**
	 * NPPD-1767: donations are detected via the shared classifier, so a product
	 * designated a donation ONLY via the `_newspack_is_donation` checkbox (no
	 * canonical `newspack_donation_product_id` option) still registers — matching
	 * Donors/Tab 7. The raw-option check this replaced would have read false.
	 */
	public function test_supporter_products_detects_checkbox_only_donations() {
		delete_option( 'newspack_donation_product_id' );
		$this->set_donation_product_ids( [ 555 ] );

		$result = $this->detect_supporter_products();

		$this->assertTrue( $result['donations'], 'checkbox-flagged donation (no canonical option) registers as a donation' );
	}

	/**
	 * No products in the classifier set → no donation slice, even if some unrelated
	 * option exists. (The classifier is the single source of truth.)
	 */
	public function test_supporter_products_no_donations_when_classifier_empty() {
		$this->set_donation_product_ids( [] );

		$result = $this->detect_supporter_products();

		$this->assertFalse( $result['donations'] );
	}

	/**
	 * NPPD-1767: a subscription-type product the publisher flagged as a donation is
	 * counted as a donation here, NOT a subscription — keeping the pie's categories
	 * complementary and consistent with Tabs 6/7. A genuine (non-donation)
	 * subscription product still counts as a subscription.
	 */
	public function test_supporter_products_flagged_subscription_is_donation_not_subscription() {
		// The only published subscription-type product (700) is flagged as a donation.
		$this->set_donation_product_ids( [ 700 ] );
		$GLOBALS['newspack_test_wc_products'] = [ 700 ];

		$result = $this->detect_supporter_products();

		$this->assertTrue( $result['donations'], 'the flagged product is a donation' );
		$this->assertFalse( $result['subscriptions'], 'a donation-flagged subscription product is not double-counted as a subscription' );

		// Add a genuine, non-donation subscription product (800): now subscriptions exist.
		$GLOBALS['newspack_test_wc_products'] = [ 700, 800 ];

		$result = $this->detect_supporter_products();

		$this->assertTrue( $result['subscriptions'], 'a non-donation subscription product counts as a subscription' );
	}
}
