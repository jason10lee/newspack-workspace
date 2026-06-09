<?php
/**
 * Tests for Newspack\Dynamic_Pricing\Matchers\All_Subscriptions_Scope_Matcher.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Matchers\All_Subscriptions_Scope_Matcher;

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_All_Subscriptions_Scope_Matcher extends WP_UnitTestCase {
	public function test_matches_subscription_product() {
		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$product->method( 'get_type' )->willReturn( 'subscription' );
		$this->assertTrue( ( new All_Subscriptions_Scope_Matcher() )->matches( $product, null ) );
	}

	public function test_matches_variable_subscription_product() {
		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$product->method( 'get_type' )->willReturn( 'variable-subscription' );
		$this->assertTrue( ( new All_Subscriptions_Scope_Matcher() )->matches( $product, null ) );
	}

	public function test_does_not_match_simple_product() {
		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$product->method( 'get_type' )->willReturn( 'simple' );
		$this->assertFalse( ( new All_Subscriptions_Scope_Matcher() )->matches( $product, null ) );
	}

	public function test_id_returns_stable_string() {
		$this->assertSame( 'all_subscriptions', ( new All_Subscriptions_Scope_Matcher() )->id() );
	}
}
