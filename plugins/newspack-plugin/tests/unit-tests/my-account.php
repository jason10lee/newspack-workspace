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
}
