<?php
/**
 * Tests the Jetpack AI Assistant runtime gate.
 *
 * @package Newspack\Tests
 */

use Newspack\Jetpack;
use Newspack\Optional_Modules;

/**
 * Tests the Jetpack AI Assistant runtime gate.
 */
class Newspack_Test_Jetpack_AI extends WP_UnitTestCase {
	/**
	 * Reset settings before each test.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Optional_Modules::OPTION_NAME );
	}

	/**
	 * By default (not opted in), the gate disables Jetpack AI.
	 */
	public function test_ai_disabled_by_default() {
		self::assertFalse(
			Jetpack::maybe_disable_ai_assistant( true ),
			'Gate returns false when the publisher has not opted in.'
		);
		self::assertFalse(
			apply_filters( 'jetpack_ai_enabled', true ),
			'jetpack_ai_enabled filters to false by default.'
		);
	}

	/**
	 * When opted in, the gate passes the incoming value through.
	 */
	public function test_ai_enabled_when_opted_in() {
		Optional_Modules::activate_optional_module( 'jetpack-ai' );
		self::assertTrue(
			Jetpack::maybe_disable_ai_assistant( true ),
			'Gate passes through when the publisher has opted in.'
		);
		self::assertTrue(
			apply_filters( 'jetpack_ai_enabled', true ),
			'jetpack_ai_enabled is unchanged when opted in.'
		);
	}
}
