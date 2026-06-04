<?php
/**
 * Tests integration My Account dispatch without WooCommerce.
 *
 * @package Newspack\Tests
 */

use Newspack\My_Account;

/**
 * Test integration My Account dispatch without WooCommerce.
 *
 * @group reader-activation
 */
class Newspack_Test_Integration_My_Account_Dispatch extends WP_UnitTestCase {
	/**
	 * Integration-declared endpoints appear in My_Account::get_endpoints()
	 * via the newspack_my_account_endpoints filter.
	 */
	public function test_integration_endpoint_contributed() {
		add_filter(
			'newspack_my_account_endpoints',
			function ( $endpoints ) {
				$endpoints['newsletters'] = 'Newsletters';
				return $endpoints;
			}
		);
		$this->assertArrayHasKey( 'newsletters', My_Account::get_endpoints() );
	}
}
