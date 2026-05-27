<?php
/**
 * Newspack Emails Section.
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

use Newspack\Emails;
use Newspack\Wizards\Wizard_Section;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Emails Section Class.
 *
 * Surfaces the unified emails management UI in the Newspack > Settings >
 * Emails wizard tab. Backed by the unified `newspack_email_configs`
 * schema — no parallel registry.
 */
class Emails_Section extends Wizard_Section {
	/**
	 * Containing wizard slug.
	 *
	 * @var string
	 */
	protected $wizard_slug = 'newspack-settings';

	/**
	 * Register the endpoints needed for the wizard screens.
	 */
	public function register_rest_routes() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'wizard/' . $this->wizard_slug . '/emails',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_email_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
	}

	/**
	 * Get email settings.
	 *
	 * Returns the unified emails list for the wizard UI. Stubbed in this
	 * commit; commit 6 implements the unified-schema body.
	 *
	 * @return array
	 */
	public static function api_get_email_settings(): array {
		// TODO(commit 6): replace stub with unified-schema implementation
		// that reads from Emails::get_email_configs() and serializes via
		// Emails::serialize_email(). WC-source rows filtered out — slice 2
		// lifts that filter.
		return [
			'newspack_emails' => [],
			'post_type'       => Emails::POST_TYPE,
		];
	}
}
