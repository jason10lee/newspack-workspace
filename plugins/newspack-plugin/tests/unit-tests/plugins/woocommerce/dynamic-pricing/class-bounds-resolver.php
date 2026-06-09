<?php
/**
 * Tests for Newspack\Dynamic_Pricing\Bounds_Resolver.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Bounds_Resolver;

// WooCommerce is not loaded in the unit-test environment; mock the one
// helper that the resolver depends on if it is not already defined.
if ( ! function_exists( 'wc_get_product_term_ids' ) ) {
	function wc_get_product_term_ids( $product_id, $taxonomy ) {
		$terms = get_the_terms( $product_id, $taxonomy );
		return ( empty( $terms ) || is_wp_error( $terms ) ) ? [] : wp_list_pluck( $terms, 'term_id' );
	}
}

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Bounds_Resolver extends WP_UnitTestCase {
	public function set_up() {
		parent::set_up();
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			register_taxonomy( 'product_cat', 'product', [ 'hierarchical' => true ] );
		}
		if ( ! post_type_exists( 'product' ) ) {
			register_post_type( 'product', [ 'public' => true ] );
		}
		delete_option( 'newspack_dynamic_pricing_default_floor' );
		delete_option( 'newspack_dynamic_pricing_default_ceiling' );
	}

	/**
	 * Build a mock WC_Product backed by the given post id.
	 *
	 * @param int $post_id Product post id.
	 */
	private function mock_product( int $post_id ): \WC_Product {
		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$product->method( 'get_id' )->willReturn( $post_id );
		return $product;
	}

	public function test_returns_site_defaults_when_no_product_or_category_overrides() {
		update_option( 'newspack_dynamic_pricing_default_floor', 1.0 );
		update_option( 'newspack_dynamic_pricing_default_ceiling', 100.0 );
		$post_id = $this->factory->post->create( [ 'post_type' => 'product' ] );
		$product = $this->mock_product( $post_id );

		[ $floor, $ceiling ] = ( new Bounds_Resolver() )->for_product( $product );
		$this->assertSame( 1.0, $floor );
		$this->assertSame( 100.0, $ceiling );
	}

	public function test_product_overrides_site_defaults() {
		update_option( 'newspack_dynamic_pricing_default_floor', 1.0 );
		update_option( 'newspack_dynamic_pricing_default_ceiling', 100.0 );
		$post_id = $this->factory->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $post_id, '_dynamic_pricing_floor', 5.0 );
		update_post_meta( $post_id, '_dynamic_pricing_ceiling', 50.0 );
		$product = $this->mock_product( $post_id );

		[ $floor, $ceiling ] = ( new Bounds_Resolver() )->for_product( $product );
		$this->assertSame( 5.0, $floor );
		$this->assertSame( 50.0, $ceiling );
	}

	public function test_category_bounds_used_when_product_meta_absent() {
		$term = wp_insert_term( 'BoundedCat', 'product_cat' );
		update_term_meta( $term['term_id'], '_dynamic_pricing_floor', 3.0 );

		$post_id = $this->factory->post->create( [ 'post_type' => 'product' ] );
		wp_set_object_terms( $post_id, [ (int) $term['term_id'] ], 'product_cat' );
		$product = $this->mock_product( $post_id );

		[ $floor, ] = ( new Bounds_Resolver() )->for_product( $product );
		$this->assertSame( 3.0, $floor );
	}

	public function test_multi_category_widest_envelope() {
		$term_a = wp_insert_term( 'CatA', 'product_cat' );
		$term_b = wp_insert_term( 'CatB', 'product_cat' );
		update_term_meta( $term_a['term_id'], '_dynamic_pricing_floor', 3.0 );
		update_term_meta( $term_b['term_id'], '_dynamic_pricing_floor', 1.0 );
		update_term_meta( $term_a['term_id'], '_dynamic_pricing_ceiling', 50.0 );
		update_term_meta( $term_b['term_id'], '_dynamic_pricing_ceiling', 80.0 );

		$post_id = $this->factory->post->create( [ 'post_type' => 'product' ] );
		wp_set_object_terms( $post_id, [ (int) $term_a['term_id'], (int) $term_b['term_id'] ], 'product_cat' );
		$product = $this->mock_product( $post_id );

		[ $floor, $ceiling ] = ( new Bounds_Resolver() )->for_product( $product );
		$this->assertSame( 1.0, $floor, 'Multi-category floor: pick the lowest.' );
		$this->assertSame( 80.0, $ceiling, 'Multi-category ceiling: pick the highest.' );
	}
}
