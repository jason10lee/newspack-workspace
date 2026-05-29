<?php
/**
 * Tests for Print_Section format setting validation.
 *
 * @package Newspack\Tests
 */

use Newspack\Wizards\Newspack\Print_Section;

/**
 * Test class for Print_Section.
 */
class Newspack_Test_Print_Section extends WP_UnitTestCase {

	/**
	 * Clean up the format option between tests so each starts fresh.
	 */
	public function tear_down() {
		parent::tear_down();
		delete_option( Print_Section::SETTING_FORMAT );
	}

	/**
	 * get_format() defaults to 'tagged-text' when no option is set.
	 */
	public function test_get_format_default_is_tagged_text() {
		$this->assertSame( 'tagged-text', Print_Section::get_format() );
	}

	/**
	 * get_format() returns 'xml' when the option is set to 'xml'.
	 */
	public function test_get_format_returns_xml_when_set() {
		update_option( Print_Section::SETTING_FORMAT, 'xml' );
		$this->assertSame( 'xml', Print_Section::get_format() );
	}

	/**
	 * get_format() falls back to 'tagged-text' when the option holds an
	 * unknown value (defends against arbitrary writes to the option).
	 */
	public function test_get_format_falls_back_on_invalid_option() {
		update_option( Print_Section::SETTING_FORMAT, 'malicious-value' );
		$this->assertSame( 'tagged-text', Print_Section::get_format() );
	}

	/**
	 * get_format() falls back when the filter callback returns an invalid value.
	 */
	public function test_get_format_falls_back_on_invalid_filter_return() {
		update_option( Print_Section::SETTING_FORMAT, 'xml' );
		add_filter( 'newspack_indesign_export_format', fn() => 'not-a-real-format' );
		$this->assertSame( 'tagged-text', Print_Section::get_format() );
		remove_all_filters( 'newspack_indesign_export_format' );
	}

	/**
	 * api_update_print_settings() rejects a non-string / non-whitelisted format.
	 */
	public function test_api_update_rejects_invalid_format() {
		$section = new Print_Section();

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'format', 'badname' );

		$result = $section->api_update_print_settings( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_param', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'tagged-text', Print_Section::get_format() );
	}

	/**
	 * api_update_print_settings() accepts a valid format and persists it.
	 */
	public function test_api_update_accepts_valid_format() {
		$section = new Print_Section();

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'format', 'xml' );

		$result = $section->api_update_print_settings( $request );

		$this->assertIsArray( $result );
		$this->assertSame( 'xml', $result['format'] );
		$this->assertSame( 'xml', Print_Section::get_format() );
	}

	/**
	 * Invalid format doesn't partially update the module toggle in the same call.
	 *
	 * Regression guard: an invalid format param should reject the whole request,
	 * not silently apply other valid params.
	 */
	public function test_api_update_invalid_format_does_not_toggle_module() {
		// Capture baseline state.
		$module_was_active = \Newspack\Optional_Modules::is_optional_module_active( \Newspack\Optional_Modules\InDesign_Exporter::MODULE_NAME );

		$section = new Print_Section();

		// Try to flip the module AND pass an invalid format in one request.
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'module_enabled_print', ! $module_was_active );
		$request->set_param( 'format', 'definitely-not-valid' );

		$result = $section->api_update_print_settings( $request );

		$this->assertInstanceOf( WP_Error::class, $result );

		// Module state must NOT have been toggled by the partially-valid request.
		// (Current implementation toggles module first then validates format —
		// this test pins the contract that callers can rely on for atomicity.)
		$now_active = \Newspack\Optional_Modules::is_optional_module_active( \Newspack\Optional_Modules\InDesign_Exporter::MODULE_NAME );

		// Restore module state regardless of outcome so we don't leak between tests.
		if ( $now_active !== $module_was_active ) {
			if ( $module_was_active ) {
				\Newspack\Optional_Modules::activate_optional_module( \Newspack\Optional_Modules\InDesign_Exporter::MODULE_NAME );
			} else {
				\Newspack\Optional_Modules::deactivate_optional_module( \Newspack\Optional_Modules\InDesign_Exporter::MODULE_NAME );
			}
		}

		$this->assertSame( $module_was_active, $now_active, 'Invalid format param should not leave the module toggled.' );
	}
}
