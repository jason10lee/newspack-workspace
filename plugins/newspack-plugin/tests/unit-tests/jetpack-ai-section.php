<?php
/**
 * Tests the Jetpack_AI_Section REST section.
 *
 * @package Newspack\Tests
 */

use Newspack\Wizards\Newspack\Jetpack_AI_Section;
use Newspack\Optional_Modules;

/**
 * Tests the Jetpack_AI_Section REST section.
 */
class Newspack_Test_Jetpack_AI_Section extends WP_UnitTestCase {
	/**
	 * Reset settings before each test.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Optional_Modules::OPTION_NAME );
	}

	/**
	 * GET returns the flag (underscore key), default false.
	 */
	public function test_get_settings_default() {
		$section = new Jetpack_AI_Section();
		$result  = $section->api_get_settings();
		self::assertIsArray( $result );
		self::assertArrayHasKey( 'module_enabled_jetpack_ai', $result );
		self::assertFalse( $result['module_enabled_jetpack_ai'] );
	}

	/**
	 * POST true activates the module; POST false deactivates it.
	 */
	public function test_update_settings_toggles_module() {
		$section = new Jetpack_AI_Section();

		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_jetpack_ai', true );
		$result = $section->api_update_settings( $request );
		self::assertTrue( $result['module_enabled_jetpack_ai'] );
		self::assertTrue( Optional_Modules::is_optional_module_active( 'jetpack-ai' ) );

		$request->set_param( 'module_enabled_jetpack_ai', false );
		$result = $section->api_update_settings( $request );
		self::assertFalse( $result['module_enabled_jetpack_ai'] );
		self::assertFalse( Optional_Modules::is_optional_module_active( 'jetpack-ai' ) );
	}

	/**
	 * POST with a non-boolean param returns a 400 error.
	 */
	public function test_update_settings_rejects_non_boolean() {
		$section = new Jetpack_AI_Section();
		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_jetpack_ai', 'not-a-bool' );
		$result = $section->api_update_settings( $request );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertEquals( 400, $result->get_error_data()['status'] );
	}
}
