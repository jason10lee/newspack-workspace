<?php
/**
 * Tests for the Newspack My Account core shell.
 *
 * @package Newspack\Tests
 */

use Newspack\My_Account;

/**
 * Test the My_Account class.
 */
class Newspack_Test_My_Account extends WP_UnitTestCase {
	/**
	 * The class should exist and expose its public accessors.
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'Newspack\My_Account' ) );
		$this->assertTrue( method_exists( 'Newspack\My_Account', 'get_page_id' ) );
		$this->assertTrue( method_exists( 'Newspack\My_Account', 'is_account_page' ) );
		$this->assertTrue( method_exists( 'Newspack\My_Account', 'get_endpoint_url' ) );
	}

	/**
	 * Native page ID comes from the Newspack option when Woo is absent.
	 */
	public function test_get_page_id_native() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( My_Account::PAGE_ID_OPTION, $page_id );

		$this->assertSame( $page_id, My_Account::get_page_id() );

		delete_option( My_Account::PAGE_ID_OPTION );
		$this->assertSame( 0, My_Account::get_page_id() );
	}

	/**
	 * is_account_page() is true on the native account page and false elsewhere.
	 */
	public function test_is_account_page_native() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$page_id  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$other_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( My_Account::PAGE_ID_OPTION, $page_id );

		$this->go_to( get_permalink( $page_id ) );
		$this->assertTrue( My_Account::is_account_page() );

		$this->go_to( get_permalink( $other_id ) );
		$this->assertFalse( My_Account::is_account_page() );

		delete_option( My_Account::PAGE_ID_OPTION );
	}

	/**
	 * get_endpoint_url() returns the base permalink for the empty endpoint and a
	 * sub-path for a named endpoint.
	 */
	public function test_get_endpoint_url_native() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( My_Account::PAGE_ID_OPTION, $page_id );

		$base = My_Account::get_endpoint_url();
		$this->assertSame( get_permalink( $page_id ), $base );

		$edit = My_Account::get_endpoint_url( 'edit-account' );
		$this->assertStringContainsString( 'edit-account', $edit );
		$this->assertStringStartsWith( rtrim( get_permalink( $page_id ), '/' ), rtrim( $edit, '/' ) );

		delete_option( My_Account::PAGE_ID_OPTION );
	}

	/**
	 * get_or_create_page() creates a page once and reuses it afterward.
	 */
	public function test_get_or_create_page_native() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		delete_option( My_Account::PAGE_ID_OPTION );

		$page_id = My_Account::get_or_create_page();
		$this->assertGreaterThan( 0, $page_id );
		$this->assertSame( 'page', get_post_type( $page_id ) );
		$this->assertStringContainsString( '[newspack_my_account]', get_post( $page_id )->post_content );

		// Second call must not create a new page.
		$this->assertSame( $page_id, My_Account::get_or_create_page() );

		wp_delete_post( $page_id, true );
		delete_option( My_Account::PAGE_ID_OPTION );
	}

	/**
	 * The [newspack_my_account] shortcode is registered and renders a container.
	 */
	public function test_shortcode_registered() {
		My_Account::register_shortcode();
		$this->assertTrue( shortcode_exists( 'newspack_my_account' ) );

		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$html = do_shortcode( '[newspack_my_account]' );
		$this->assertStringContainsString( 'newspack-my-account', $html );
	}
}
