<?php
/**
 * Tests for Newspack\Dynamic_Pricing\Matchers\Category_Scope_Matcher.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Matchers\Category_Scope_Matcher;

// WooCommerce is not loaded in the unit-test environment; mock the one
// helper that the matcher depends on if it is not already defined.
if ( ! function_exists( 'wc_get_product_term_ids' ) ) {
	function wc_get_product_term_ids( $product_id, $taxonomy ) {
		$terms = get_the_terms( $product_id, $taxonomy );
		return ( empty( $terms ) || is_wp_error( $terms ) ) ? [] : wp_list_pluck( $terms, 'term_id' );
	}
}

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Category_Scope_Matcher extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			register_taxonomy( 'product_cat', 'product', [ 'hierarchical' => true ] );
		}
		if ( ! post_type_exists( 'product' ) ) {
			register_post_type( 'product', [ 'public' => true ] );
		}
	}

	public function test_matches_when_product_belongs_to_a_scoped_category() {
		$term    = wp_insert_term( 'Tech', 'product_cat' );
		$post_id = $this->factory->post->create( [ 'post_type' => 'product' ] );
		wp_set_object_terms( $post_id, [ (int) $term['term_id'] ], 'product_cat' );

		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$product->method( 'get_id' )->willReturn( $post_id );

		$this->assertTrue( ( new Category_Scope_Matcher() )->matches( $product, [ (int) $term['term_id'] ] ) );
	}

	public function test_variation_matches_via_parent_product_terms() {
		// product_cat terms live on the PARENT; a variation must match through it.
		$term      = wp_insert_term( 'News', 'product_cat' );
		$parent_id = $this->factory->post->create( [ 'post_type' => 'product' ] );
		wp_set_object_terms( $parent_id, [ (int) $term['term_id'] ], 'product_cat' );

		$variation = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$variation->method( 'get_id' )->willReturn( $parent_id + 1000 ); // variation post id — has no terms.
		$variation->method( 'get_parent_id' )->willReturn( $parent_id );

		$this->assertTrue( ( new Category_Scope_Matcher() )->matches( $variation, [ (int) $term['term_id'] ] ) );
	}

	public function test_does_not_match_when_product_lacks_scoped_category() {
		$post_id = $this->factory->post->create( [ 'post_type' => 'product' ] );

		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$product->method( 'get_id' )->willReturn( $post_id );

		$this->assertFalse( ( new Category_Scope_Matcher() )->matches( $product, [ 999 ] ) );
	}

	public function test_id_returns_stable_string() {
		$this->assertSame( 'category', ( new Category_Scope_Matcher() )->id() );
	}
}
