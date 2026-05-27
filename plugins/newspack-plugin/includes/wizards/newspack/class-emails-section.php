<?php
/**
 * Newspack Emails Section.
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

use Newspack\Emails;
use Newspack\Reader_Activation;
use Newspack\Reader_Revenue_Emails;
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
	 * Builds the unified emails list directly from the
	 * `newspack_email_configs` schema — no parallel registry, no join.
	 * WooCommerce-source rows are filtered out at this layer; slice 2
	 * removes that filter when the WC surface lands.
	 *
	 * @return array
	 */
	public static function api_get_email_settings(): array {
		$configs = Emails::get_email_configs();

		// Slice 1 surfaces only Newspack-source emails. Slice 2 lifts this.
		$configs = array_filter(
			$configs,
			fn( $config ) => ( $config['source'] ?? 'newspack' ) !== 'woocommerce'
		);

		// Without Reader Activation, the auth/account flows are unused —
		// scope the visible set to reader-revenue configs only. Mirrors the
		// legacy slice 1 behavior.
		if ( ! Reader_Activation::is_enabled() ) {
			$allowed = array_values( Reader_Revenue_Emails::EMAIL_TYPES );
			$configs = array_intersect_key( $configs, array_flip( $allowed ) );
		}

		// Resolve each newspack-source config to a Newspack post + serialized
		// payload via the existing Emails::get_emails() pipeline. The
		// serialized output now carries the four new schema fields per the
		// commit 1 patch to Emails::serialize_email().
		$types  = array_keys( $configs );
		$emails = Emails::get_emails( $types, false );

		$newspack_emails = [];
		foreach ( $emails as $type => $email ) {
			// registry_slug is just the config key — kept as a stable string
			// identifier the frontend reads for reset eligibility.
			$email['registry_slug'] = $type;
			$newspack_emails[]      = $email;
		}

		// Single category-only sort: reader-revenue → reader-activation → other.
		$category_order = [
			'reader-revenue'    => 0,
			'reader-activation' => 1,
		];
		usort(
			$newspack_emails,
			function ( $a, $b ) use ( $category_order ) {
				$order_a = $category_order[ $a['category'] ?? '' ] ?? 2;
				$order_b = $category_order[ $b['category'] ?? '' ] ?? 2;
				return $order_a - $order_b;
			}
		);

		return [
			'newspack_emails' => $newspack_emails,
			'post_type'       => Emails::POST_TYPE,
		];
	}
}
