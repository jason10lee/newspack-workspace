<?php
/**
 * Print Section Object.
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

/**
 * WordPress dependencies
 */

use Newspack\Optional_Modules;
use Newspack\Optional_Modules\InDesign_Exporter;
use WP_REST_Server;

/**
 * Internal dependencies
 */
use Newspack\Wizards\Wizard_Section;

/**
 * Print Section Object.
 *
 * @package Newspack\Wizards\Newspack
 */
class Print_Section extends Wizard_Section {

	/**
	 * Option key for the InDesign export format setting.
	 *
	 * @var string
	 */
	const SETTING_FORMAT = 'newspack_indesign_export_format';

	/**
	 * Containing wizard slug.
	 *
	 * @var string
	 */
	protected $wizard_slug = 'newspack-settings';

	/**
	 * Get the current InDesign export format setting.
	 *
	 * @return string 'tagged-text' (default) or 'xml'.
	 */
	public static function get_format() {
		$format = get_option( self::SETTING_FORMAT, 'tagged-text' );
		/**
		 * Filters the InDesign export format.
		 *
		 * @param string $format Export format. Either 'tagged-text' or 'xml'.
		 */
		$format = apply_filters( 'newspack_indesign_export_format', $format );
		return in_array( $format, [ 'tagged-text', 'xml' ], true ) ? $format : 'tagged-text';
	}

	/**
	 * Register Wizard Section specific endpoints.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/print',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_print_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/print',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_print_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'module_enabled_print' => [
						'type' => 'boolean',
					],
					'format'               => [
						'type' => 'string',
						'enum' => [ 'tagged-text', 'xml' ],
					],
				],
			]
		);
	}

	/**
	 * Get print settings.
	 *
	 * @return array
	 */
	public function api_get_print_settings() {
		return [
			'module_enabled_print' => Optional_Modules::is_optional_module_active( InDesign_Exporter::MODULE_NAME ),
			'format'               => self::get_format(),
		];
	}

	/**
	 * Update print settings.
	 *
	 * Validates all submitted params before applying any of them. A request
	 * with a valid module_enabled_print but an invalid format is rejected
	 * without toggling the module — the partial-state leak that gets caught
	 * in regression review.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return array|\WP_Error
	 */
	public function api_update_print_settings( $request ) {
		$has_module = $request->has_param( 'module_enabled_print' );
		$has_format = $request->has_param( 'format' );

		if ( $has_module ) {
			$module_enabled_print = $request->get_param( 'module_enabled_print' );
			if ( ! is_bool( $module_enabled_print ) ) {
				return new \WP_Error( 'invalid_param', __( 'Invalid parameter for module_enabled_print.', 'newspack' ), [ 'status' => 400 ] );
			}
		}

		if ( $has_format ) {
			$format = $request->get_param( 'format' );
			if ( ! in_array( $format, [ 'tagged-text', 'xml' ], true ) ) {
				return new \WP_Error( 'invalid_param', __( 'Invalid parameter for format.', 'newspack' ), [ 'status' => 400 ] );
			}
		}

		// All validation passed — apply the changes.
		if ( $has_module ) {
			if ( $module_enabled_print ) {
				Optional_Modules::activate_optional_module( InDesign_Exporter::MODULE_NAME );
			} else {
				Optional_Modules::deactivate_optional_module( InDesign_Exporter::MODULE_NAME );
			}
		}

		if ( $has_format ) {
			update_option( self::SETTING_FORMAT, $format );
		}

		return $this->api_get_print_settings();
	}
}
