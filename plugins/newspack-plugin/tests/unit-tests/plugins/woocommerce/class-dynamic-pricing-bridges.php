<?php
/**
 * Tests for Newspack\Dynamic_Pricing_Bridges.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing_Bridges;

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Dynamic_Pricing_Bridges extends WP_UnitTestCase {
	public function set_up() {
		parent::set_up();
		if ( ! post_type_exists( 'product' ) ) {
			register_post_type( 'product', [ 'public' => true ] );
		}
		Dynamic_Pricing_Bridges::init();
	}

	public function tear_down() {
		remove_filter( 'newspack_dynamic_pricing_is_excluded', [ Dynamic_Pricing_Bridges::class, 'exclude_donations' ], 10 );
		remove_filter( 'newspack_dynamic_pricing_is_excluded', [ Dynamic_Pricing_Bridges::class, 'exclude_group_subscriptions' ], 10 );
		remove_filter( 'newspack_dynamic_pricing_is_excluded', [ Dynamic_Pricing_Bridges::class, 'exclude_paused_subscriptions' ], 10 );
		parent::tear_down();
	}

	public function test_excludes_donation_products() {
		$post_id = $this->factory->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $post_id, '_newspack_is_donation', 'yes' );

		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$product->method( 'get_id' )->willReturn( $post_id );

		$excluded = apply_filters( 'newspack_dynamic_pricing_is_excluded', false, $product, null );
		$this->assertTrue( $excluded, 'Donation products must be excluded.' );
	}

	public function test_does_not_exclude_non_donation_products() {
		$post_id = $this->factory->post->create( [ 'post_type' => 'product' ] );

		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$product->method( 'get_id' )->willReturn( $post_id );

		$excluded = apply_filters( 'newspack_dynamic_pricing_is_excluded', false, $product, null );
		$this->assertFalse( $excluded );
	}

	public function test_excludes_paused_subscriptions() {
		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$sub     = $this->getMockBuilder( \WC_Subscription::class )->disableOriginalConstructor()->getMock();
		// The Group_Subscription bridge also calls get_items() / get_meta() — stub them so it falls through cleanly.
		$sub->method( 'get_items' )->willReturn( [] );
		$sub->method( 'get_meta' )->willReturnCallback(
			function ( $key ) {
				return Dynamic_Pricing_Bridges::PAUSED_META_KEY === $key;
			}
		);

		$excluded = apply_filters( 'newspack_dynamic_pricing_is_excluded', false, $product, $sub );
		$this->assertTrue( $excluded, 'Paused subscriptions (via _newspack_dynamic_pricing_paused meta) must be excluded.' );
	}

	public function test_does_not_exclude_unpaused_subscriptions() {
		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$sub     = $this->getMockBuilder( \WC_Subscription::class )->disableOriginalConstructor()->getMock();
		// The Group_Subscription bridge also calls get_items() / get_meta() — stub them so it falls through cleanly.
		$sub->method( 'get_items' )->willReturn( [] );
		$sub->method( 'get_meta' )->willReturn( false );

		$excluded = apply_filters( 'newspack_dynamic_pricing_is_excluded', false, $product, $sub );
		$this->assertFalse( $excluded );
	}

	public function test_short_circuits_when_already_excluded() {
		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$excluded = apply_filters( 'newspack_dynamic_pricing_is_excluded', true, $product, null );
		$this->assertTrue( $excluded );
	}
}
