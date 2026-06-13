<?php
/**
 * Minimal WooCommerce Memberships test doubles for the Newspack plugin.
 *
 * Models only what the update-payment-notice membership-equivalence logic
 * needs (NPPM-2926 Gap A): plan->product mapping, active-membership checks,
 * and the WC Memberships <-> Subscriptions integration chain that
 * Memberships::get_user_subscription_for_membership_plan() walks.
 *
 * @package Newspack\Tests
 */

// Mock registries. Tests reset these in set_up().
global $wc_memberships_plans, $wc_memberships_active_memberships, $wc_memberships_plan_subscription_products;
$wc_memberships_plans                       = []; // Newspack_Mock_Membership_Plan[]
$wc_memberships_active_memberships          = []; // [ user_id => [ plan_id, ... ] ]
$wc_memberships_plan_subscription_products  = []; // [ plan_id => [ product_id, ... ] ]

// Satisfies Memberships::is_active()'s class_exists( 'WC_Memberships' ) check.
if ( ! class_exists( 'WC_Memberships' ) ) {
	class WC_Memberships {}
}

/**
 * A membership plan: knows which product IDs grant it.
 */
class Newspack_Mock_Membership_Plan {
	private $id;
	private $product_ids;
	public function __construct( $id, $product_ids ) {
		$this->id          = $id;
		$this->product_ids = $product_ids;
	}
	public function get_id() {
		return $this->id;
	}
	public function has_product( $product_id ) {
		return in_array( (int) $product_id, array_map( 'intval', $this->product_ids ), true );
	}
	public function get_product_ids() {
		return $this->product_ids;
	}
}

/**
 * The subscriptions-integration view of a plan: its required subscription products.
 */
if ( ! class_exists( 'WC_Memberships_Integration_Subscriptions_Membership_Plan' ) ) {
	class WC_Memberships_Integration_Subscriptions_Membership_Plan {
		private $plan_id;
		public function __construct( $plan_id ) {
			$this->plan_id = $plan_id;
		}
		public function get_subscription_product_ids() {
			global $wc_memberships_plan_subscription_products;
			return $wc_memberships_plan_subscription_products[ $this->plan_id ] ?? [];
		}
	}
}

/**
 * Subscriptions integration instance.
 */
class Newspack_Mock_WCM_Subscriptions_Integration {
	public function has_membership_plan_subscription( $plan_id ) {
		global $wc_memberships_plan_subscription_products;
		return ! empty( $wc_memberships_plan_subscription_products[ $plan_id ] );
	}
}

/**
 * Integrations registry.
 */
class Newspack_Mock_WCM_Integrations {
	public function get_subscriptions_instance() {
		return new Newspack_Mock_WCM_Subscriptions_Integration();
	}
}

/**
 * Main wc_memberships() accessor.
 */
class Newspack_Mock_WCM_Main {
	public function get_integrations_instance() {
		return new Newspack_Mock_WCM_Integrations();
	}
}

if ( ! function_exists( 'wc_memberships' ) ) {
	function wc_memberships() {
		return new Newspack_Mock_WCM_Main();
	}
}

if ( ! function_exists( 'wc_memberships_get_membership_plans' ) ) {
	function wc_memberships_get_membership_plans() {
		global $wc_memberships_plans;
		return $wc_memberships_plans;
	}
}

if ( ! function_exists( 'wc_memberships_is_user_active_member' ) ) {
	function wc_memberships_is_user_active_member( $user_id, $plan ) {
		global $wc_memberships_active_memberships;
		$plan_id = is_object( $plan ) ? $plan->get_id() : (int) $plan;
		$plans   = $wc_memberships_active_memberships[ $user_id ] ?? [];
		return in_array( $plan_id, array_map( 'intval', $plans ), true );
	}
}

/**
 * Register a membership plan that the given product IDs grant. Optionally mark
 * those products as the plan's subscription products (for the Layer-2 chain).
 *
 * @param int   $plan_id              Plan ID.
 * @param int[] $product_ids          Products that grant the plan.
 * @param bool  $is_subscription_plan Whether the plan is granted via subscriptions.
 * @return Newspack_Mock_Membership_Plan
 */
function newspack_register_mock_membership_plan( $plan_id, $product_ids, $is_subscription_plan = true ) {
	global $wc_memberships_plans, $wc_memberships_plan_subscription_products;
	$plan                          = new Newspack_Mock_Membership_Plan( $plan_id, $product_ids );
	$wc_memberships_plans[]        = $plan;
	if ( $is_subscription_plan ) {
		$wc_memberships_plan_subscription_products[ $plan_id ] = $product_ids;
	}
	return $plan;
}
