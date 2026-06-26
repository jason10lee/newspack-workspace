<?php
/**
 * Test the Donors tab donation-activity visibility gate (NPPD-1737).
 *
 * The gate (Insights_Wizard::has_donation_activity / compute_donation_activity)
 * hides Tab 7 unless the publisher has active donation activity, defined the
 * same two-leg way the Donors metrics define an active donor: an active
 * donation subscription, OR a completed donation order in the trailing
 * DONATION_ACTIVITY_WINDOW_DAYS window. Its SQL bodies query WooCommerce order
 * tables, which the unit-test bootstrap does not install — consistent with the
 * rest of the insights storage tests, whose SQL bodies are verified via the
 * live-environment smoke test rather than here. What IS unit-testable:
 *
 *   - the empty-classifier short-circuit (no donation products → gate false,
 *     no SQL issued); and
 *   - the shape of the query built by build_donation_activity_sql(): both
 *     legs present (active subscription + recent completed order), the 365-day
 *     bound on the order leg, the analytics-lookup join, and the absence of
 *     refunded/lapsed statuses.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights_Wizard;
use Newspack\Insights\Donation_Product_Classifier;
use WP_UnitTestCase;

/**
 * Donation-activity gate test class.
 *
 * @group insights
 */
class Test_Donation_Activity_Gate extends WP_UnitTestCase {

	/**
	 * Invoke the private static SQL builder via reflection.
	 *
	 * @param string $backend        'hpos' or 'legacy'.
	 * @param string $donations_list Comma-separated product IDs.
	 * @return string
	 */
	private function build_sql( string $backend, string $donations_list ): string {
		$method = new \ReflectionMethod( Insights_Wizard::class, 'build_donation_activity_sql' );
		$method->setAccessible( true );
		return $method->invoke( null, $backend, $donations_list );
	}

	/**
	 * With no donation products configured the classifier set is empty, so the
	 * gate returns false without issuing the (WC-table) activity query — the
	 * no-product publisher correctly does not get the Donors tab.
	 */
	public function test_gate_is_false_without_donation_products() {
		if ( ! class_exists( Donation_Product_Classifier::class ) ) {
			$this->markTestSkipped( 'Donation_Product_Classifier not loaded in this bootstrap.' );
		}
		delete_option( 'newspack_donation_product_id' );
		// Reset every cache the classifier reads through: its own transient AND
		// Donations' in-memory flagged-product static. A prior test in the same
		// process can populate that static, and flush_cache() alone doesn't
		// clear it — which would otherwise leak a non-empty set into this test
		// (the cause of the original CI-only failure).
		if ( method_exists( '\Newspack\Donations', 'reset_flagged_donation_product_ids_cache' ) ) {
			\Newspack\Donations::reset_flagged_donation_product_ids_cache();
		}
		Donation_Product_Classifier::flush_cache();

		// Precondition: the classifier really resolves to an empty set, so the
		// short-circuit (not an incidental empty order result) is what's tested.
		$this->assertSame( [], Donation_Product_Classifier::get_donation_product_ids() );

		$this->assertFalse( Insights_Wizard::force_refresh_donation_activity() );
	}

	/**
	 * The HPOS query has both legs of the active-donor definition: an active
	 * donation subscription (any date) OR a completed/processing donation
	 * shop_order in the trailing 365 days, resolved via the analytics lookup.
	 * Refunded/lapsed statuses are excluded.
	 */
	public function test_hpos_sql_has_both_active_donor_legs() {
		$sql = $this->build_sql( 'hpos', '11,22,33' );

		// Leg A — active subscription, no date bound.
		$this->assertStringContainsString( "o.type = 'shop_subscription'", $sql, 'Includes the active-subscription leg.' );
		$this->assertStringContainsString( "o.status = 'wc-active'", $sql, 'Subscription leg keys on wc-active.' );
		// Leg B — recent completed order via the analytics lookup.
		$this->assertStringContainsString( "o.type = 'shop_order'", $sql, 'Includes the recent-order leg.' );
		$this->assertStringContainsString( 'wc_order_product_lookup', $sql, 'Order leg resolves products via the analytics lookup (matches the metrics).' );
		$this->assertStringContainsString( "o.status IN ('wc-completed', 'wc-processing')", $sql, 'Order leg keys on completed/processing only.' );
		$this->assertStringContainsString( 'date_created_gmt >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 365 DAY )', $sql, '365-day UTC recency bound on the order leg.' );
		$this->assertStringContainsString( 'IN (11,22,33)', $sql, 'Constrains both legs to the resolved donation product IDs.' );
		// Excluded.
		$this->assertStringNotContainsString( 'wc-refunded', $sql, 'Refunded orders are not active activity.' );
		$this->assertStringNotContainsString( 'wc-cancelled', $sql, 'Lapsed subscriptions are not active activity.' );
	}

	/**
	 * The legacy (CPT) query mirrors the HPOS one against wp_posts.
	 */
	public function test_legacy_sql_has_both_active_donor_legs() {
		$sql = $this->build_sql( 'legacy', '44,55' );

		$this->assertStringContainsString( "p.post_type = 'shop_subscription'", $sql, 'Includes the active-subscription leg.' );
		$this->assertStringContainsString( "p.post_status = 'wc-active'", $sql, 'Subscription leg keys on wc-active.' );
		$this->assertStringContainsString( "p.post_type = 'shop_order'", $sql, 'Includes the recent-order leg.' );
		$this->assertStringContainsString( 'wc_order_product_lookup', $sql, 'Order leg resolves products via the analytics lookup.' );
		$this->assertStringContainsString( "p.post_status IN ('wc-completed', 'wc-processing')", $sql, 'Order leg keys on completed/processing only.' );
		$this->assertStringContainsString( 'post_date_gmt >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 365 DAY )', $sql, '365-day UTC recency bound on the order leg.' );
		$this->assertStringContainsString( 'IN (44,55)', $sql, 'Constrains both legs to the resolved donation product IDs.' );
		$this->assertStringNotContainsString( 'wc-refunded', $sql, 'Refunded orders are not active activity.' );
	}

	/**
	 * The window constant is the 365-day active-donor recency precedent.
	 */
	public function test_window_constant_is_365() {
		$this->assertSame( 365, Insights_Wizard::DONATION_ACTIVITY_WINDOW_DAYS );
	}
}
