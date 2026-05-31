<?php
/**
 * Tests for WooCommerce_Content_Detector.
 *
 * @package Newspack
 */

use Newspack\WooCommerce_Content_Detector;

/**
 * Test WooCommerce content detection.
 */
class Newspack_Test_WooCommerce_Content_Detector extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		WooCommerce_Content_Detector::reset_memo();
	}

	public function tearDown(): void {
		WooCommerce_Content_Detector::reset_memo();
		remove_shortcode( 'products' );
		parent::tearDown();
	}

	/**
	 * A queried page containing a woocommerce/* block is detected,
	 * including when the block is nested inside another block.
	 */
	public function test_detects_wc_block_in_queried_post() {
		$page = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:woocommerce/product-category /--></div><!-- /wp:group -->',
			]
		);
		$this->go_to( get_permalink( $page ) );
		WooCommerce_Content_Detector::reset_memo();
		$this->assertTrue( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
	}

	/**
	 * A queried page containing a registered WooCommerce shortcode is detected.
	 */
	public function test_detects_wc_shortcode_in_queried_post() {
		add_shortcode( 'products', '__return_empty_string' );
		$page = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:paragraph --><p>[products limit="4"]</p><!-- /wp:paragraph -->',
			]
		);
		$this->go_to( get_permalink( $page ) );
		WooCommerce_Content_Detector::reset_memo();
		$this->assertTrue( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
	}

	/**
	 * A queried page with no WooCommerce content is not detected.
	 */
	public function test_clean_queried_post_is_not_detected() {
		$page = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:paragraph --><p>Just words.</p><!-- /wp:paragraph -->',
			]
		);
		$this->go_to( get_permalink( $page ) );
		WooCommerce_Content_Detector::reset_memo();
		$this->assertFalse( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
	}

	/**
	 * A singular custom post type is scanned the same way (post-type-agnostic).
	 */
	public function test_detects_wc_block_in_singular_cpt() {
		register_post_type( 'np_test_cpt', [ 'public' => true ] );
		$post = self::factory()->post->create(
			[
				'post_type'    => 'np_test_cpt',
				'post_content' => '<!-- wp:woocommerce/product-category /-->',
			]
		);
		$this->go_to( get_permalink( $post ) );
		WooCommerce_Content_Detector::reset_memo();
		$this->assertTrue( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
		unregister_post_type( 'np_test_cpt' );
	}

	/**
	 * A WooCommerce block in a widget assigned to an ACTIVE sidebar is detected.
	 */
	public function test_detects_wc_block_in_active_block_widget() {
		$clean = self::factory()->post->create( [ 'post_type' => 'page', 'post_content' => '<p>clean</p>' ] );
		$this->go_to( get_permalink( $clean ) );
		update_option(
			'widget_block',
			[
				2 => [ 'content' => '<!-- wp:paragraph --><p>nope</p><!-- /wp:paragraph -->' ],
				3 => [ 'content' => '<!-- wp:woocommerce/product-category /-->' ],
			]
		);
		wp_set_sidebars_widgets(
			[
				'sidebar-1'           => [ 'block-3' ],
				'wp_inactive_widgets' => [],
			]
		);
		WooCommerce_Content_Detector::reset_memo();
		$this->assertTrue( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
	}

	/**
	 * The SAME WooCommerce widget, present only in wp_inactive_widgets, is NOT
	 * detected (locks in the active-only scope — orphaned widgets must not veto
	 * the strip site-wide).
	 */
	public function test_inactive_block_widget_is_not_detected() {
		$clean = self::factory()->post->create( [ 'post_type' => 'page', 'post_content' => '<p>clean</p>' ] );
		$this->go_to( get_permalink( $clean ) );
		update_option(
			'widget_block',
			[ 3 => [ 'content' => '<!-- wp:woocommerce/product-category /-->' ] ]
		);
		wp_set_sidebars_widgets(
			[
				'sidebar-1'           => [],
				'wp_inactive_widgets' => [ 'block-3' ],
			]
		);
		WooCommerce_Content_Detector::reset_memo();
		$this->assertFalse( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
	}
}
