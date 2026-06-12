<?php
/**
 * Tests the WooCommerce Update Payment Notice status gate (NPPM-2926, Gap B).
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_Update_Payment_Notice;

require_once __DIR__ . '/../../mocks/wc-mocks.php';

/**
 * @group update_payment_notice
 */
class Newspack_Test_WooCommerce_Update_Payment_Notice extends WP_UnitTestCase {
	/**
	 * Reset the mock subscription registry before each test.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database, $orders_database;
		$subscriptions_database = [];
		$products_database      = [];
		$orders_database        = [];
	}

	/**
	 * Call the private get_notices() via reflection.
	 *
	 * @return string[]
	 */
	private function get_notices() {
		$method = new ReflectionMethod( WooCommerce_Update_Payment_Notice::class, 'get_notices' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	/**
	 * Register a logged-in customer and return its ID.
	 *
	 * @return int
	 */
	private function make_current_customer() {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Build a minimal line item exposing the methods the notice path uses.
	 *
	 * @param int $product_id Product ID.
	 * @return object
	 */
	private function make_line_item( $product_id ) {
		return new class( $product_id ) {
			private $product_id;
			public function __construct( $product_id ) {
				$this->product_id = $product_id;
			}
			public function get_product_id() {
				return $this->product_id;
			}
			public function get_product() {
				return wc_get_product( $this->product_id );
			}
		};
	}

	/**
	 * Register a subscription that needs payment, owned by the current customer.
	 *
	 * @param int    $customer_id Customer ID.
	 * @param string $status      Subscription status.
	 * @param int    $product_id  Product ID granted by the subscription.
	 * @return WC_Subscription
	 */
	private function make_needs_payment_subscription( $customer_id, $status, $product_id ) {
		return wcs_create_subscription(
			[
				'customer_id'   => $customer_id,
				'status'        => $status,
				'needs_payment' => true,
				'products'      => [ $product_id ],
				'items'         => [ $this->make_line_item( $product_id ) ],
			]
		);
	}

	/**
	 * Statuses that must NOT produce a notice even when needs_payment() is true.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function terminal_status_provider() {
		return [
			'expired'        => [ 'expired' ],
			'switched'       => [ 'switched' ],
			'active'         => [ 'active' ],
			'pending-cancel' => [ 'pending-cancel' ],
			'cancelled'      => [ 'cancelled' ],
		];
	}

	/**
	 * @dataProvider terminal_status_provider
	 *
	 * @param string $status Subscription status.
	 */
	public function test_no_notice_for_non_recoverable_status( $status ) {
		$customer_id = $this->make_current_customer();
		$product     = wc_create_mock_product( [ 'id' => 4242, 'name' => 'Newsroom Pro' ] );
		$this->make_needs_payment_subscription( $customer_id, $status, $product->get_id() );

		$this->assertSame( [], $this->get_notices(), "Status '$status' must not produce a payment notice." );
	}

	/**
	 * The documented incident: an expired subscription with a stale unpaid renewal order.
	 */
	public function test_no_notice_for_expired_with_stale_unpaid_order() {
		$customer_id = $this->make_current_customer();
		$product     = wc_create_mock_product( [ 'id' => 54427, 'name' => 'Newsroom Pro – Monthly' ] );
		// needs_payment() true models the stale unpaid failed-renewal order still attached.
		$this->make_needs_payment_subscription( $customer_id, 'expired', $product->get_id() );

		$this->assertSame( [], $this->get_notices(), 'An expired subscription with a stale unpaid order must not nag.' );
	}

	/**
	 * Recoverable statuses still fire (regression guard).
	 */
	public function test_notice_fires_for_on_hold() {
		$customer_id = $this->make_current_customer();
		$product     = wc_create_mock_product( [ 'id' => 4242, 'name' => 'Newsroom Pro' ] );
		$this->make_needs_payment_subscription( $customer_id, 'on-hold', $product->get_id() );

		$notices = $this->get_notices();
		$this->assertCount( 1, $notices, 'An on-hold subscription that needs payment must produce a notice.' );
		$this->assertStringContainsString( 'Newsroom Pro', $notices[0] );
	}

	public function test_notice_fires_for_pending() {
		$customer_id = $this->make_current_customer();
		$product     = wc_create_mock_product( [ 'id' => 4242, 'name' => 'Newsroom Pro' ] );
		$this->make_needs_payment_subscription( $customer_id, 'pending', $product->get_id() );

		$this->assertCount( 1, $this->get_notices(), 'A pending subscription that needs payment must produce a notice.' );
	}

	/**
	 * A recoverable status that does NOT need payment produces nothing.
	 */
	public function test_no_notice_when_payment_not_needed() {
		$customer_id = $this->make_current_customer();
		$product     = wc_create_mock_product( [ 'id' => 4242, 'name' => 'Newsroom Pro' ] );
		wcs_create_subscription(
			[
				'customer_id'   => $customer_id,
				'status'        => 'on-hold',
				'needs_payment' => false,
				'products'      => [ $product->get_id() ],
				'items'         => [ $this->make_line_item( $product->get_id() ) ],
			]
		);

		$this->assertSame( [], $this->get_notices(), 'No notice when the subscription does not need payment.' );
	}
}
