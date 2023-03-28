<?php
/**
 * Class TestTaxonomy
 *
 * @package Newspack_Multibranded_Site
 */

use Newspack_Multibranded_Site\Taxonomy;

/**
 * Sample test case.
 */
class TestTaxonomy extends WP_UnitTestCase {

	/**
	 * A simple test to see if the taxonomy was registered.
	 */
	public function test_taxonomy_exists() {
		$this->assertTrue( taxonomy_exists( Taxonomy::SLUG ), 'The taxonomy is not registered' );
	}

	/**
	 * Test get primary.
	 */
	public function test_get_primary() {
		$term1 = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$term2 = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$term3 = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );

		$primary = Taxonomy::get_primary();
		$this->assertSame( $term1->term_id, $primary->term_id, 'If no primary was set, should return the first' );

		add_term_meta( $term3->term_id, Taxonomy::PRIMARY_META_KEY, true );

		$primary = Taxonomy::get_primary();
		$this->assertSame( $term3->term_id, $primary->term_id );

		add_term_meta( $term2->term_id, Taxonomy::PRIMARY_META_KEY, true );

		$primary = Taxonomy::get_primary();
		$this->assertSame( $term2->term_id, $primary->term_id );
	}

	/**
	 * Test reset primary on setting a new primary.
	 */
	public function test_reset_primary_on_add() {
		$term1 = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$term2 = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$term3 = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );

		global $wpdb;
		$query = $wpdb->prepare( "SELECT count(term_id) FROM $wpdb->termmeta WHERE meta_key = %s", Taxonomy::PRIMARY_META_KEY );

		// phpcs:disable
		$this->assertSame( 0, (int) $wpdb->get_var( $query ), 'No primary term should be set' );

		add_term_meta( $term1->term_id, Taxonomy::PRIMARY_META_KEY, true );
		$this->assertSame( 1, (int) $wpdb->get_var( $query ), 'One primary term should be set' );

		add_term_meta( $term2->term_id, Taxonomy::PRIMARY_META_KEY, true );
		$this->assertSame( 1, (int) $wpdb->get_var( $query ), 'One primary term should be set' );

		add_term_meta( $term3->term_id, Taxonomy::PRIMARY_META_KEY, true );
		$this->assertSame( 1, (int) $wpdb->get_var( $query ), 'One primary term should be set' );
		// phpcs:enable
	}

	/**
	 * Test set primary.
	 */
	public function test_set_primary() {
		$term1    = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$term2    = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$category = $this->factory->term->create_and_get( array( 'taxonomy' => 'category' ) );

		$this->assertTrue( Taxonomy::set_primary( $term1->term_id ), 'Should be able to set a primary term from term id' );
		$primary = Taxonomy::get_primary();
		$this->assertSame( $term1->term_id, $primary->term_id );

		$this->assertTrue( Taxonomy::set_primary( $term2 ), 'Should be able to set a primary term from an object' );
		$primary = Taxonomy::get_primary();
		$this->assertSame( $term2->term_id, $primary->term_id );

		$this->assertFalse( Taxonomy::set_primary( $category ), 'Should not be able to set a primary term from a category' );
		$primary = Taxonomy::get_primary();
		$this->assertSame( $term2->term_id, $primary->term_id );

		$this->assertFalse( Taxonomy::set_primary( array( 'asd' ) ), 'Should return false for invalid input' );
	}

	/**
	 * Test get_current_brand_for_post
	 */
	public function test_get_current_brand_for_post() {
		$term1   = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$term2   = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$primary = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );

		Taxonomy::set_primary( $primary );

		$post     = $this->factory->post->create_and_get( array( 'post_title' => 'Post 1' ) );
		$page     = $this->factory->post->create_and_get(
			array(
				'post_title' => 'Post 2',
				'post_type'  => 'page',
			)
		);
		$other_pt = $this->factory->post->create_and_get(
			array(
				'post_title' => 'Post 3',
				'post_type'  => 'nav_menu',
			)
		);

		$this->assertSame( $primary->term_id, Taxonomy::get_current_brand_for_post( $post->ID )->term_id, 'Primary should be returned if none is set' );

		wp_set_post_terms( $post->ID, $term1->term_id, Taxonomy::SLUG );
		$this->assertSame( $term1->term_id, Taxonomy::get_current_brand_for_post( $post->ID )->term_id, 'Related brand should be returned if ony one is added' );

		wp_set_post_terms( $post->ID, [ $term1->term_id, $term2->term_id ], Taxonomy::SLUG );
		$this->assertSame( $primary->term_id, Taxonomy::get_current_brand_for_post( $post->ID )->term_id, 'Primary should be returned if more than on brand is set' );

		wp_set_post_terms( $page->ID, $term2->term_id, Taxonomy::SLUG );
		$this->assertSame( $term2->term_id, Taxonomy::get_current_brand_for_post( $page->ID )->term_id, 'Related brand should be returned if ony one is added' );

		wp_set_post_terms( $other_pt->ID, $term1->term_id, Taxonomy::SLUG );
		$this->assertSame( $primary->term_id, Taxonomy::get_current_brand_for_post( $other_pt->ID )->term_id, 'Primary should be returned for other post types' );
	}

	/**
	 * Test get_current_brand_for_term
	 */
	public function test_get_current_brand_for_term() {
		$term1   = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$term2   = $this->factory->term->create_and_get( array( 'taxonomy' => 'category' ) );
		$primary = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );

		Taxonomy::set_primary( $primary );

		$this->assertSame( $term1->term_id, Taxonomy::get_current_brand_for_term( $term1->term_id )->term_id, 'Term should be returned if is a brand' );
		$this->assertSame( $primary->term_id, Taxonomy::get_current_brand_for_term( $term2->term_id )->term_id, 'Primary should be returned if other taxonomy' );
	}

	/**
	 * Tests get current brand and determine current brand methods
	 */
	public function test_determine_current_brand() {
		$author        = $this->factory->user->create_and_get();
		$term1         = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$term2         = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$category      = $this->factory->term->create_and_get( array( 'taxonomy' => 'category' ) );
		$primary       = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$post          = $this->factory->post->create_and_get(
			array(
				'post_title'  => 'Post 1',
				'post_author' => $author->ID,
			)
		);
		$post_2_brands = $this->factory->post->create_and_get(
			array(
				'post_title'  => 'Post 2',
				'post_author' => $author->ID,
			)
		);
		$page          = $this->factory->post->create_and_get(
			array(
				'post_title' => 'Post 2',
				'post_type'  => 'page',
			)
		);

		Taxonomy::set_primary( $primary );
		wp_set_post_terms( $post->ID, $term1->term_id, Taxonomy::SLUG );
		wp_set_post_terms( $post->ID, $category->term_id, 'category' );
		wp_set_post_terms( $post_2_brands->ID, [ $term1->term_id, $term2->term_id ], Taxonomy::SLUG );
		wp_set_post_terms( $post_2_brands->ID, $category->term_id, 'category' );

		// home.
		$this->go_to( '/' );
		$this->assertSame( $primary->term_id, Taxonomy::get_current()->term_id, 'Primary should be returned if on home' );

		// search.
		$this->go_to( '/?s=asd' );
		$this->assertSame( $primary->term_id, Taxonomy::get_current()->term_id, 'Primary should be returned if on search' );

		// category archive.
		$this->go_to( get_term_link( $category ) );
		$this->assertSame( $primary->term_id, Taxonomy::get_current()->term_id, 'Primary should be returned if on category archive' );

		// Brand archive.
		$this->go_to( get_term_link( $term1 ) );
		$this->assertSame( $term1->term_id, Taxonomy::get_current()->term_id, 'Brand should be returned if on brand archive' );

		// Post with one brand.
		$this->go_to( get_permalink( $post->ID ) );
		$this->assertSame( $term1->term_id, Taxonomy::get_current()->term_id, 'Brand should be returned if on post with one brand' );

		// Post with two brands.
		$this->go_to( get_permalink( $post_2_brands->ID ) );
		$this->assertSame( $primary->term_id, Taxonomy::get_current()->term_id, 'Primary should be returned if on post with two brands' );

		// author archive.
		$this->go_to( get_author_posts_url( $author->ID ) );
		$this->assertSame( $primary->term_id, Taxonomy::get_current()->term_id, 'Primary should be returned if on author archive' );
	}
}
