<?php
/**
 * Jetpack AI Section Object.
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

use Newspack\Optional_Modules;
use WP_REST_Server;

use Newspack\Wizards\Wizard_Section;

/**
 * Jetpack AI Section Object.
 *
 * Toggles the `jetpack-ai` optional module, which gates Jetpack AI Assistant
 * (off by default fleet-wide; publishers opt in). See NPPM-2915.
 *
 * @package Newspack\Wizards\Newspack
 */
class Jetpack_AI_Section extends Wizard_Section {

	/**
	 * Optional-module slug backing this toggle.
	 *
	 * Hyphenated to match the other optional-module slugs; the REST/JS contract
	 * exposes the underscore form `module_enabled_jetpack_ai` (a JS property
	 * cannot contain a hyphen).
	 *
	 * @var string
	 */
	const MODULE_NAME = 'jetpack-ai';

	/**
	 * REST/JS property name for the toggle.
	 *
	 * @var string
	 */
	const REST_KEY = 'module_enabled_jetpack_ai';

	/**
	 * Containing wizard slug.
	 *
	 * @var string
	 */
	protected $wizard_slug = 'newspack-settings';

	/**
	 * Register Wizard Section specific endpoints.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/jetpack-ai',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/jetpack-ai',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					self::REST_KEY => [
						'required' => true,
						'type'     => 'boolean',
					],
				],
			]
		);
	}

	/**
	 * Get Jetpack AI settings.
	 *
	 * @return array
	 */
	public function api_get_settings() {
		return [
			self::REST_KEY => Optional_Modules::is_optional_module_active( self::MODULE_NAME ),
		];
	}

	/**
	 * Update Jetpack AI settings.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return array|\WP_Error
	 */
	public function api_update_settings( $request ) {
		$enabled = $request->get_param( self::REST_KEY );
		if ( ! is_bool( $enabled ) ) {
			return new \WP_Error( 'invalid_param', __( 'Invalid parameter for module_enabled_jetpack_ai.', 'newspack' ), [ 'status' => 400 ] );
		}

		if ( $enabled ) {
			Optional_Modules::activate_optional_module( self::MODULE_NAME );
		} else {
			Optional_Modules::deactivate_optional_module( self::MODULE_NAME );
		}

		return [
			self::REST_KEY => $enabled,
		];
	}
}
