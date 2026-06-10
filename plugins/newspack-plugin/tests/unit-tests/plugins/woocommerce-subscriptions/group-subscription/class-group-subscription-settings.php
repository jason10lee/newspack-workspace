<?php
/**
 * Tests for Group_Subscription_Settings.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;

/**
 * Test Group_Subscription_Settings.
 */
class Test_Group_Subscription_Settings extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		// Include WC mocks.
		require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Set up: reset subscriptions and products databases.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database, $orders_database;
		$subscriptions_database = [];
		$products_database      = [];
		$orders_database        = [];

		// The meta-box registration is gated behind the content-gates feature flag.
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}

		// Start each meta-box test from a clean registry.
		$GLOBALS['wp_meta_boxes'] = [];
	}

	/**
	 * Tear down: reset subscriptions and products databases.
	 */
	public function tear_down() {
		global $subscriptions_database, $products_database, $orders_database;
		$subscriptions_database   = [];
		$products_database        = [];
		$orders_database          = [];
		$GLOBALS['wp_meta_boxes'] = [];
		$prefix                   = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;
		unset(
			$_POST['woocommerce_meta_nonce'],
			$_POST[ $prefix . 'meta_box' ],
			$_POST[ $prefix . 'enabled' ],
			$_POST[ $prefix . 'limit' ],
			$_POST[ $prefix . 'name' ]
		);
		parent::tear_down();
	}

	/**
	 * Build a subscription linked to a product, optionally setting group subscription
	 * meta on either side and arbitrary subscription data.
	 *
	 * Meta keys are passed without the GROUP_SUBSCRIPTION_META_PREFIX; the helper
	 * applies the prefix.
	 *
	 * @param array $product_meta      Map of meta key => value to set on the product.
	 * @param array $subscription_meta Map of meta key => value to set on the subscription.
	 * @param array $subscription_args Extra arguments merged into the subscription data
	 *                                 (e.g. billing_first_name, billing_last_name).
	 * @param array $product_args      Extra arguments merged into the mock product data
	 *                                 (e.g. name).
	 *
	 * @return WC_Subscription
	 */
	private function make_subscription_with_product( $product_meta = [], $subscription_meta = [], $subscription_args = [], $product_args = [] ) {
		$product_id            = 123;
		$prefixed_product_meta = [];
		foreach ( $product_meta as $key => $value ) {
			$prefixed_product_meta[ Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . $key ] = $value;
		}
		wc_create_mock_product(
			array_merge(
				[
					'id'   => $product_id,
					'meta' => $prefixed_product_meta,
				],
				$product_args
			)
		);

		$subscription = wcs_create_subscription(
			array_merge(
				[
					'customer_id'    => 1,
					'status'         => 'active',
					'billing_period' => 'month',
					'items'          => [
						new WC_Order_Item_Product( [ 'product_id' => $product_id ] ),
					],
				],
				$subscription_args
			)
		);

		foreach ( $subscription_meta as $key => $value ) {
			$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . $key, $value );
		}

		return $subscription;
	}

	/*
	 * --- 'limit' setting ---
	 */

	/**
	 * When a subscription has no limit override, the inherited product limit applies.
	 */
	public function test_inherits_product_limit_when_subscription_override_unset() {
		$subscription = $this->make_subscription_with_product(
			[
				'enabled' => 'yes',
				'limit'   => '10',
			]
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertSame( 10, $settings['limit'], 'Limit should be inherited from the product when no subscription override is set.' );
	}

	/**
	 * A subscription limit override of 0 takes precedence over a non-zero product limit.
	 */
	public function test_zero_subscription_limit_overrides_product_limit() {
		$subscription = $this->make_subscription_with_product(
			[
				'enabled' => 'yes',
				'limit'   => '10',
			],
			[ 'limit' => '0' ] // String, as stored by WooCommerce meta.
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertSame( 0, $settings['limit'], 'A subscription limit of 0 should override the product limit of 10.' );
	}

	/**
	 * A non-zero subscription limit override takes precedence over the product limit.
	 */
	public function test_nonzero_subscription_limit_overrides_product_limit() {
		$subscription = $this->make_subscription_with_product(
			[
				'enabled' => 'yes',
				'limit'   => '10',
			],
			[ 'limit' => '5' ]
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertSame( 5, $settings['limit'], 'A subscription limit of 5 should override the product limit of 10.' );
	}

	/*
	 * --- 'enabled' setting ---
	 */

	/**
	 * When a subscription has no enabled override, the inherited product value applies.
	 */
	public function test_inherits_product_enabled_when_subscription_override_unset() {
		$subscription = $this->make_subscription_with_product( [ 'enabled' => 'yes' ] );

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertTrue( $settings['enabled'], 'Enabled should be inherited from the product when no subscription override is set.' );
	}

	/**
	 * A subscription enabled override of 'no' takes precedence over a product 'yes'.
	 */
	public function test_no_subscription_enabled_overrides_product_yes() {
		$subscription = $this->make_subscription_with_product(
			[ 'enabled' => 'yes' ],
			[ 'enabled' => 'no' ]
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertFalse( $settings['enabled'], 'A subscription enabled value of "no" should override the product value of "yes".' );
	}

	/**
	 * A subscription enabled override of 'yes' takes effect when the product has no value set.
	 */
	public function test_yes_subscription_enabled_overrides_product_unset() {
		$subscription = $this->make_subscription_with_product(
			[],
			[ 'enabled' => 'yes' ]
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertTrue( $settings['enabled'], 'A subscription enabled value of "yes" should take effect when the product has no value set.' );
	}

	/*
	 * --- 'name' setting ---
	 */

	/**
	 * An explicit subscription name meta value is used as the group name.
	 */
	public function test_explicit_subscription_name_meta_wins() {
		$subscription = $this->make_subscription_with_product(
			[ 'enabled' => 'yes' ],
			[ 'name' => 'My Custom Group' ],
			[
				'billing_first_name' => 'Jane',
				'billing_last_name'  => 'Doe',
			]
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertSame( 'My Custom Group', $settings['name'], 'Explicit subscription name meta should be used as the group name even when an owner name is available.' );
	}

	/**
	 * Without an explicit name, the group name falls back to the product name.
	 */
	public function test_name_falls_back_to_product_name() {
		$subscription = $this->make_subscription_with_product(
			[ 'enabled' => 'yes' ],
			[],
			[],
			[ 'name' => 'Daily Reader' ]
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertSame( 'Daily Reader', $settings['name'], 'When no name meta is set, the group name should fall back to the product name.' );
	}

	/**
	 * Without an explicit name or a product name, the group name falls back to the publisher singular group label.
	 */
	public function test_name_falls_back_to_singular_group_label() {
		$subscription = $this->make_subscription_with_product( [ 'enabled' => 'yes' ] );

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertSame( Group_Subscription::get_label( 'singular' ), $settings['name'], 'When neither name meta nor a product name is set, the group name should fall back to the publisher singular group label.' );
	}

	/*
	 * --- Meta-box registration (Bug 1: HPOS-only meta box) ---
	 */

	/**
	 * Whether the group-subscription meta box is registered for a screen.
	 *
	 * @param string $screen The screen / post-type the box would render on.
	 * @return bool
	 */
	private function meta_box_is_registered( $screen = 'shop_subscription' ) {
		return isset( $GLOBALS['wp_meta_boxes'][ $screen ]['normal']['high']['newspack-group-subscription'] );
	}

	/**
	 * On classic order storage, WordPress core fires `add_meta_boxes` with a WP_Post
	 * (not a WC_Subscription). The box must still register so admins can view and edit
	 * a subscription's group settings.
	 */
	public function test_meta_box_registers_on_classic_order_storage() {
		$subscription = $this->make_subscription_with_product( [ 'enabled' => 'yes' ] );
		$post         = new WP_Post(
			(object) [
				'ID'        => $subscription->get_id(),
				'post_type' => 'shop_subscription',
			]
		);

		Group_Subscription_Settings::add_group_subscription_meta_box( 'shop_subscription', $post );

		$this->assertTrue( $this->meta_box_is_registered(), 'The group-subscription meta box should register when core passes a WP_Post on classic order storage.' );
	}

	/**
	 * Registration is not enough: on classic order storage WordPress also invokes the meta-box
	 * render callback with a WP_Post, so the callback must resolve it to a subscription and emit
	 * the markup (including the save sentinel). Otherwise the box renders empty and the save
	 * handler bails, so group config can never be edited on classic storage.
	 */
	public function test_meta_box_renders_sentinel_on_classic_order_storage() {
		$subscription = $this->make_subscription_with_product( [ 'enabled' => 'yes' ] );
		$post         = new WP_Post(
			(object) [
				'ID'        => $subscription->get_id(),
				'post_type' => 'shop_subscription',
			]
		);

		ob_start();
		Group_Subscription_Settings::add_group_subscription_options( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString(
			Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'meta_box',
			$output,
			'The render callback must resolve a WP_Post to its subscription and emit the meta-box sentinel on classic order storage.'
		);
	}

	/**
	 * On HPOS, WooCommerce fires `add_meta_boxes` with the wc-orders page screen id (NOT
	 * 'shop_subscription') and the WC_Subscription object. The box must register under that
	 * screen — gating on `$post_type === 'shop_subscription'` would wrongly drop it here.
	 */
	public function test_meta_box_registers_on_hpos() {
		$subscription = $this->make_subscription_with_product( [ 'enabled' => 'yes' ] );
		$hpos_screen  = 'woocommerce_page_wc-orders--shop_subscription';

		Group_Subscription_Settings::add_group_subscription_meta_box( $hpos_screen, $subscription );

		$this->assertTrue( $this->meta_box_is_registered( $hpos_screen ), 'The group-subscription meta box should register on the HPOS orders screen when WooCommerce passes a WC_Subscription.' );
	}

	/**
	 * The box must not register for a non-subscription order, under either storage mode. Because
	 * registration gates on wcs_is_subscription() (not the screen id), this must hold whether the
	 * order arrives as a classic WP_Post or an HPOS WC_Order object.
	 */
	public function test_meta_box_does_not_register_for_non_subscription_order() {
		// HPOS shape: a WC_Order object on the orders screen is not a subscription.
		$order = new WC_Order(
			[
				'customer_id' => 1,
				'status'      => 'completed',
				'total'       => 10,
			]
		);
		Group_Subscription_Settings::add_group_subscription_meta_box( 'woocommerce_page_wc-orders--shop_order', $order );
		$this->assertFalse( $this->meta_box_is_registered( 'woocommerce_page_wc-orders--shop_order' ), 'The meta box should not register for an order object (HPOS).' );

		// Classic shape: a WP_Post whose ID does not resolve to a subscription.
		$post = new WP_Post(
			(object) [
				'ID'        => 999999,
				'post_type' => 'shop_order',
			]
		);
		Group_Subscription_Settings::add_group_subscription_meta_box( 'shop_order', $post );
		$this->assertFalse( $this->meta_box_is_registered( 'shop_order' ), 'The meta box should not register for a non-subscription WP_Post (classic).' );
	}

	/*
	 * --- Save handler (Bug 2: fieldless save wipes group config) ---
	 */

	/**
	 * A save that does not originate from the group meta box (bulk status change,
	 * programmatic save, list-table inline edit) submits no group fields. Such a save
	 * must leave the existing group config untouched rather than resetting it.
	 */
	public function test_fieldless_save_preserves_group_config() {
		$subscription = $this->make_subscription_with_product(
			[],
			[
				'enabled' => 'yes',
				'limit'   => '10',
			]
		);

		// Simulate a valid subscription save that did not render the group meta box:
		// a valid nonce is present, but no group fields and no meta-box sentinel.
		$_POST['woocommerce_meta_nonce'] = wp_create_nonce( 'woocommerce_save_data' );

		Group_Subscription_Settings::save_group_subscription_meta( $subscription->get_id(), $subscription );

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );
		$this->assertTrue( $settings['enabled'], 'A save without the group meta box must not disable the group subscription.' );
		$this->assertSame( 10, $settings['limit'], 'A save without the group meta box must not reset the member limit.' );
	}

	/**
	 * A genuine meta-box save with the sentinel present but the "enabled" checkbox
	 * unchecked must still disable the group subscription (preserves edit-screen semantics).
	 */
	public function test_meta_box_save_applies_unchecked_checkbox() {
		$subscription = $this->make_subscription_with_product(
			[],
			[
				'enabled' => 'yes',
				'limit'   => '10',
			]
		);

		$_POST['woocommerce_meta_nonce'] = wp_create_nonce( 'woocommerce_save_data' );
		// The meta box rendered (sentinel present) but the enabled checkbox was unchecked
		// (absent from $_POST) and the limit field submitted as 5.
		$_POST[ Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'meta_box' ] = '1';
		$_POST[ Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit' ]    = '5';

		Group_Subscription_Settings::save_group_subscription_meta( $subscription->get_id(), $subscription );

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );
		$this->assertFalse( $settings['enabled'], 'An unchecked enabled checkbox on a real meta-box save should disable the group subscription.' );
		$this->assertSame( 5, $settings['limit'], 'The submitted limit value should be applied on a real meta-box save.' );

		unset( $_POST[ Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit' ] );
	}
}
