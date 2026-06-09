<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class ModalCheckoutTest
 *
 * @package Newspack_Blocks
 */

/**
 * Modal checkout.
 */
class ModalCheckoutTest extends WP_UnitTestCase_Blocks { // phpcs:ignore
	/**
	 * Clean up request data.
	 */
	public function tear_down() {
		unset( $_POST['billing_email'], $_POST['post_data'], $_REQUEST['modal_checkout'], $_REQUEST['post_data'] );
		parent::tear_down();
	}

	/**
	 * Set serialized checkout data in the request.
	 *
	 * @param string $post_data Serialized checkout data.
	 */
	private function set_serialized_post_data( $post_data ) {
		$_POST['post_data']    = $post_data;
		$_REQUEST['post_data'] = $post_data;
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

		$this->set_serialized_post_data( 'billing_first_name=Repeat&billing_email=repeat%40example.com&modal_checkout=1' );

		$this->assertSame( $user_id, \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * It preserves plus-addresses in WooCommerce's serialized order review post_data.
	 */
	public function test_get_user_id_from_email_reads_plus_address_from_serialized_post_data() {
		$user_id = self::factory()->user->create(
			[
				'user_email' => 'admin+donationsrecaptcha@example.com',
			]
		);

		$this->set_serialized_post_data( 'billing_first_name=Repeat&billing_email=admin%2Bdonationsrecaptcha%40example.com&modal_checkout=1' );

		$this->assertSame( $user_id, \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * It prefers a top-level billing email over serialized post_data.
	 */
	public function test_get_user_id_from_email_prefers_top_level_billing_email() {
		$top_level_user_id = self::factory()->user->create(
			[
				'user_email' => 'top-level@example.com',
			]
		);
		self::factory()->user->create(
			[
				'user_email' => 'serialized@example.com',
			]
		);

		$_POST['billing_email'] = 'top-level@example.com';
		$this->set_serialized_post_data( 'billing_first_name=Repeat&billing_email=serialized%40example.com&modal_checkout=1' );

		$this->assertSame( $top_level_user_id, \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * It returns false when no billing email is present.
	 */
	public function test_get_user_id_from_email_returns_false_without_email() {
		$this->assertFalse( \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * It returns false when serialized post_data has no billing email.
	 */
	public function test_get_user_id_from_email_returns_false_for_post_data_without_billing_email() {
		$this->set_serialized_post_data( 'billing_first_name=Repeat&modal_checkout=1' );

		$this->assertFalse( \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * It ignores non-string request data.
	 */
	public function test_get_user_id_from_email_ignores_non_string_request_data() {
		$_POST['billing_email'] = [ 'repeat@example.com' ];
		$_POST['post_data']     = [ 'billing_email=repeat%40example.com&modal_checkout=1' ];

		$this->assertFalse( \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * Unknown emails should not resolve to a user.
	 */
	public function test_get_user_id_from_email_returns_false_for_unknown_email() {
		$this->set_serialized_post_data( 'billing_first_name=New&billing_email=fresh%40example.com&modal_checkout=1' );

		$this->assertFalse( \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * It associates modal checkout with an existing user found in serialized post_data.
	 */
	public function test_associate_existing_user_reads_serialized_post_data() {
		$user_id = self::factory()->user->create(
			[
				'user_email' => 'repeat@example.com',
			]
		);

		$this->set_serialized_post_data( 'billing_first_name=Repeat&billing_email=repeat%40example.com&modal_checkout=1' );

		$this->assertSame( $user_id, \Newspack_Blocks\Modal_Checkout::associate_existing_user( 0 ) );
	}

	/**
	 * It keeps the current customer ID when serialized post_data has a fresh email.
	 */
	public function test_associate_existing_user_keeps_customer_id_for_fresh_email() {
		$this->set_serialized_post_data( 'billing_first_name=New&billing_email=fresh%40example.com&modal_checkout=1' );

		$this->assertSame( 123, \Newspack_Blocks\Modal_Checkout::associate_existing_user( 123 ) );
	}
}
