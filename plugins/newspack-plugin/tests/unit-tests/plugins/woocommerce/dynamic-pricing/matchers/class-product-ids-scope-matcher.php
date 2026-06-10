<?php
/**
 * Tests for Newspack\Dynamic_Pricing\Matchers\Product_Ids_Scope_Matcher.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Matchers\Product_Ids_Scope_Matcher;

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Product_Ids_Scope_Matcher extends WP_UnitTestCase {
	public function test_matches_when_product_id_in_list() {
		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$product->method( 'get_id' )->willReturn( 42 );
		$this->assertTrue( ( new Product_Ids_Scope_Matcher() )->matches( $product, [ 10, 42, 99 ] ) );
	}

	public function test_does_not_match_when_product_id_absent() {
		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$product->method( 'get_id' )->willReturn( 7 );
		$this->assertFalse( ( new Product_Ids_Scope_Matcher() )->matches( $product, [ 10, 42, 99 ] ) );
	}

	public function test_variation_matches_via_parent_product_id() {
		// Admins configure PARENT ids; surfaces resolve VARIATIONS — the parent
		// fallback is what makes variable subscriptions match.
		$variation = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$variation->method( 'get_id' )->willReturn( 4201 );
		$variation->method( 'get_parent_id' )->willReturn( 42 );
		$this->assertTrue( ( new Product_Ids_Scope_Matcher() )->matches( $variation, [ 42 ] ) );
	}

	public function test_variation_does_not_match_when_parent_absent_from_list() {
		$variation = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$variation->method( 'get_id' )->willReturn( 4201 );
		$variation->method( 'get_parent_id' )->willReturn( 43 );
		$this->assertFalse( ( new Product_Ids_Scope_Matcher() )->matches( $variation, [ 42 ] ) );
	}

	public function test_empty_list_matches_nothing() {
		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$product->method( 'get_id' )->willReturn( 42 );
		$this->assertFalse( ( new Product_Ids_Scope_Matcher() )->matches( $product, [] ) );
	}

	public function test_id_returns_stable_string() {
		$this->assertSame( 'product_ids', ( new Product_Ids_Scope_Matcher() )->id() );
	}
}
