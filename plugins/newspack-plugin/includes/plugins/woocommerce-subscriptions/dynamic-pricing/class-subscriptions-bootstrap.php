<?php
/**
 * Subscriptions layer bootstrap for Dynamic Pricing.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing\Subscriptions;

use Newspack\Dynamic_Pricing\Pricing_Engine;

defined( 'ABSPATH' ) || exit;

final class Subscriptions_Bootstrap {
	public static function init(): void {
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return;
		}
		$engine = Pricing_Engine::instance();
		$engine->add_surface( new Subscription_Surface() );
		$engine->register( new Stepped_By_Cycle_Strategy() );
		Subscription_Surface::init();
	}
}

// File-end bootstrap idiom; priority 21 ensures the foundation engine (priority 20) has already wired.
add_action( 'plugins_loaded', [ Subscriptions_Bootstrap::class, 'init' ], 21 );
