<?php
/**
 * Tests the Frontend Reader Registration REST endpoint.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation;

/**
 * Tests the Frontend Reader Registration REST endpoint.
 *
 * @group frontend-registration
 */
class Newspack_Test_Frontend_Registration_Endpoint extends WP_UnitTestCase {
	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Route for the registration endpoint.
	 *
	 * @var string
	 */
	private static $route = '/newspack/v1/reader-activation/register';

	/**
	 * Test reader email.
	 *
	 * @var string
	 */
	private static $reader_email = 'integration-reader@test.com';

	/**
	 * Test integration ID.
	 *
	 * @var string
	 */
	private static $integration_id = 'test-integration';

	/**
	 * Register the test integration via filter.
	 *
	 * @param array $integrations Registered integrations.
	 * @return array
	 */
	public static function register_test_integration( $integrations ) {
		$integrations[ self::$integration_id ] = 'Test Integration';
		return $integrations;
	}

	/**
	 * Generate the expected HMAC key for a given integration ID.
	 *
	 * @param string $id Integration ID.
	 * @return string
	 */
	private static function generate_key( $id ) {
		return hash_hmac( 'sha256', $id, wp_salt( 'auth' ) );
	}

	/**
	 * Set up each test.
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		add_filter( 'newspack_frontend_registration_integrations', [ __CLASS__, 'register_test_integration' ] );
		// Ensure routes are registered — Reader_Activation::init() may have run
		// before IS_TEST_ENV was defined, skipping the rest_api_init hook.
		add_action( 'rest_api_init', [ Reader_Activation::class, 'register_routes' ] );
		do_action( 'rest_api_init' );
		wp_set_current_user( 0 );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_filter( 'newspack_frontend_registration_integrations', [ __CLASS__, 'register_test_integration' ] );
		remove_action( 'rest_api_init', [ Reader_Activation::class, 'register_routes' ] );
		$user = get_user_by( 'email', self::$reader_email );
		if ( $user ) {
			wp_delete_user( $user->ID );
		}
		// Reset rate limit state.
		delete_transient( 'newspack_reg_ip_' . md5( '127.0.0.1' ) );
		wp_cache_delete( 'newspack_reg_ip_' . md5( '127.0.0.1' ), 'newspack_rate_limit' );
		// Clean up any $_POST pollution.
		unset( $_POST['g-recaptcha-response'] );
		parent::tear_down();
	}

	/**
	 * Helper to make a registration request.
	 *
	 * @param array $body Request body.
	 * @return WP_REST_Response
	 */
	private function do_register_request( $body = [] ) {
		$request = new WP_REST_Request( 'POST', self::$route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return $this->server->dispatch( $request );
	}

	/**
	 * Test successful reader registration.
	 */
	public function test_register_new_reader() {
		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'created', $data['status'] );
		$this->assertEquals( self::$reader_email, $data['email'] );
		$this->assertInstanceOf( 'WP_User', get_user_by( 'email', self::$reader_email ) );
	}

	/**
	 * Test duplicate email returns 409.
	 */
	public function test_register_duplicate_email() {
		// Create the user directly to avoid register_reader()'s RAS-enabled check.
		self::factory()->user->create(
			[
				'user_email' => self::$reader_email,
				'role'       => 'subscriber',
			]
		);
		wp_set_current_user( 0 );

		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 409, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'reader_already_exists', $data['code'] );
	}

	/**
	 * Test missing email returns 400.
	 */
	public function test_register_missing_email() {
		$response = $this->do_register_request(
			[
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_email', $data['code'] );
	}

	/**
	 * Test invalid email returns 400.
	 */
	public function test_register_invalid_email() {
		$response = $this->do_register_request(
			[
				'npe'             => 'not-an-email',
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_email', $data['code'] );
	}

	/**
	 * Test missing integration ID returns 400.
	 */
	public function test_register_missing_integration_id() {
		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_key' => 'anything',
			]
		);
		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_integration', $data['code'] );
	}

	/**
	 * Test unregistered integration ID returns 400.
	 */
	public function test_register_unknown_integration_id() {
		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_id'  => 'unknown-tool',
				'integration_key' => self::generate_key( 'unknown-tool' ),
			]
		);
		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_integration', $data['code'] );
	}

	/**
	 * Test wrong integration key returns 403.
	 */
	public function test_register_wrong_integration_key() {
		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => 'wrong-key',
			]
		);
		$this->assertEquals( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_integration_key', $data['code'] );
	}

	/**
	 * Test honeypot field triggers fake success.
	 */
	public function test_honeypot_returns_fake_success() {
		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'email'           => 'bot-filled@spam.com',
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		// Verify user was NOT actually created.
		$this->assertFalse( get_user_by( 'email', self::$reader_email ) );
	}

	/**
	 * Test logged-in user returns 403.
	 */
	public function test_register_while_logged_in() {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'already_logged_in', $data['code'] );

		wp_delete_user( $admin_id );
	}

	/**
	 * Test registration with profile fields.
	 */
	public function test_register_with_profile_fields() {
		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
				'first_name'      => 'Jane',
				'last_name'       => 'Doe',
			]
		);
		$this->assertEquals( 201, $response->get_status() );
		$user = get_user_by( 'email', self::$reader_email );
		$this->assertInstanceOf( 'WP_User', $user );
		$this->assertEquals( 'Jane', $user->first_name );
		$this->assertEquals( 'Doe', $user->last_name );
		$this->assertStringContainsString( 'Jane', $user->display_name );
	}

	/**
	 * Test registration stores the integration-based registration method.
	 */
	public function test_register_stores_registration_method() {
		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 201, $response->get_status() );
		$user = get_user_by( 'email', self::$reader_email );
		$this->assertEquals(
			'integration-registration-' . self::$integration_id,
			get_user_meta( $user->ID, Reader_Activation::REGISTRATION_METHOD, true )
		);
	}

	/**
	 * Test RAS disabled returns 403.
	 *
	 * Skipped in the test environment because Reader_Activation::is_enabled()
	 * short-circuits to true when IS_TEST_ENV is defined, bypassing the filter.
	 */
	public function test_register_when_ras_disabled() {
		if ( defined( 'IS_TEST_ENV' ) && IS_TEST_ENV ) {
			$this->markTestSkipped( 'is_enabled() always returns true when IS_TEST_ENV is defined.' );
		}

		add_filter( 'newspack_reader_activation_enabled', '__return_false' );

		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'reader_activation_disabled', $data['code'] );

		remove_filter( 'newspack_reader_activation_enabled', '__return_false' );
	}

	/**
	 * Test per-IP rate limiting returns 429.
	 */
	public function test_rate_limit_exceeded() {
		// Lower limit to 2 for testing.
		$set_limit = function() {
			return 2;
		};
		add_filter( 'newspack_frontend_registration_rate_limit', $set_limit );

		$base_body = [
			'integration_id'  => self::$integration_id,
			'integration_key' => self::generate_key( self::$integration_id ),
		];

		// First two requests should succeed or return non-429 errors.
		// Reset current user between requests since successful registration authenticates the reader.
		$this->do_register_request( array_merge( $base_body, [ 'npe' => 'rate1@test.com' ] ) );
		wp_set_current_user( 0 );
		$this->do_register_request( array_merge( $base_body, [ 'npe' => 'rate2@test.com' ] ) );
		wp_set_current_user( 0 );

		// Third request should be rate-limited.
		$response = $this->do_register_request( array_merge( $base_body, [ 'npe' => 'rate3@test.com' ] ) );
		$this->assertEquals( 429, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'rate_limit_exceeded', $data['code'] );

		remove_filter( 'newspack_frontend_registration_rate_limit', $set_limit );

		// Clean up created users.
		foreach ( [ 'rate1@test.com', 'rate2@test.com' ] as $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				wp_delete_user( $user->ID );
			}
		}
	}
}
