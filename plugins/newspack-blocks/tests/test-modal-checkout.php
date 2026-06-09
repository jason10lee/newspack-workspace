<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class ModalCheckoutTest
 *
 * @package Newspack_Blocks
 */

/**
 * Modal checkout.
 */
class ModalCheckoutTest extends WP_UnitTestCase { // phpcs:ignore
	/**
	 * Clean up request data.
	 */
	public function tear_down() {
		unset( $_POST['billing_email'], $_POST['post_data'] );
		parent::tear_down();
	}

	/**
	 * It finds users from a top-level billing email field.
	 */
	public function test_get_user_id_from_email_reads_top_level_billing_email() {
		$user_id = self::factory()->user->create(
			[
				'user_email' => 'repeat@example.com',
			]
		);

		$_POST['billing_email'] = 'repeat@example.com';

		$this->assertSame( $user_id, \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * It finds users from WooCommerce's serialized order review post_data.
	 */
	public function test_get_user_id_from_email_reads_serialized_post_data() {
		$user_id = self::factory()->user->create(
			[
				'user_email' => 'repeat@example.com',
			]
		);

		$_POST['post_data'] = 'billing_first_name=Repeat&billing_email=repeat%40example.com&modal_checkout=1';

		$this->assertSame( $user_id, \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * Unknown emails should not resolve to a user.
	 */
	public function test_get_user_id_from_email_returns_false_for_unknown_email() {
		$_POST['post_data'] = 'billing_first_name=New&billing_email=fresh%40example.com&modal_checkout=1';

		$this->assertFalse( \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}
}
