<?php
/**
 * Class TestLogoCustomization
 *
 * @package Newspack_Multibranded_Site
 */

use Newspack_Multibranded_Site\Taxonomy;
use Newspack_Multibranded_Site\Meta\Logo;

/**
 * Test the Logo filter.
 */
class TestLogoCustomization extends WP_UnitTestCase {

	/**
	 * Tests get current brand and determine current brand methods
	 */
	public function test_filter_logo() {
		$term_without_custom_logo = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );
		$term_with_custom_logo    = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );
		add_term_meta( $term_with_custom_logo->term_id, Logo::get_key(), 123 );

		Taxonomy::set_primary( $term_without_custom_logo );
		$this->assertSame( false, get_theme_mod( 'custom_logo' ) );

		Taxonomy::set_primary( $term_with_custom_logo );
		$this->go_to( '/' ); // Resets the current brand.
		$this->assertSame( '123', get_theme_mod( 'custom_logo' ) );
	}
}
