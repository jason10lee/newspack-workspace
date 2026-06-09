<?php
/**
 * Cart-time pricing surface for WooCommerce product purchases.
 *
 * Stateless: recomputes on every cart calculation, persists nothing. Hooks
 * `woocommerce_before_calculate_totals` and rewrites cart item prices in place
 * via `$cart_item['data']->set_price()` so WC totals are computed against the
 * policy-resolved amount.
 *
 * Acquisition contract: a cart-time context resolves with
 * `signals['completed_cycles'] = 1` so a `Stepped_By_Cycle_Strategy` policy
 * grants its `step_at_1` price at checkout (cycle 1 = first invoice). After
 * `payment_complete` fires, the stateful `Subscription_Surface` takes over for
 * cycles 2+.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

final class WooProduct_Surface implements Price_Surface {
	const TRIGGER_CART = 'cart';

	public function id(): string        { return 'woo_product'; }
	public function is_stateful(): bool { return false; }
	public function triggers(): array   { return [ self::TRIGGER_CART ]; }

	public static function init(): void {
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'on_calculate_totals' ], 20, 1 );
	}

	public static function on_calculate_totals( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		$surface = Pricing_Engine::instance()->surface( 'woo_product' );
		if ( ! $surface instanceof self ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! isset( $cart_item['data'] ) || ! ( $cart_item['data'] instanceof \WC_Product ) ) {
				continue;
			}
			$ctx = $surface->context( $cart_item, self::TRIGGER_CART );
			$d   = Pricing_Engine::instance()->resolve( $ctx );
			if ( $d ) {
				$surface->apply( $ctx, $d );
			}
		}
	}

	public function context( $target, string $trigger ): Pricing_Context {
		$product = $target['data'];

		// Catalog recurring price; same precedence as Subscription_Surface.
		$base_price = 0.0;
		if (
			class_exists( '\WC_Subscriptions_Product' )
			&& in_array( $product->get_type(), [ 'subscription', 'variable-subscription', 'subscription_variation' ], true )
		) {
			$base_price = (float) \WC_Subscriptions_Product::get_price( $product );
		}
		if ( $base_price <= 0 ) {
			$base_price = (float) $product->get_regular_price();
		}

		$customer = null;
		if ( function_exists( 'WC' ) && WC() && WC()->customer ) {
			$customer = WC()->customer;
		}

		return new Pricing_Context(
			$trigger,
			$product,
			$customer,
			$base_price,
			[ 'completed_cycles' => 1 ],
			$target
		);
	}

	public function apply( Pricing_Context $ctx, Price_Decision $d ): void {
		// DURABLE and ONE_TIME both map to "set price on this cart item for this
		// calculation pass." Stateless surface — recomputed each cart calc.
		if ( ! is_array( $ctx->target ) || ! isset( $ctx->target['data'] ) || ! ( $ctx->target['data'] instanceof \WC_Product ) ) {
			return;
		}
		$ctx->target['data']->set_price( $d->amount );
	}
}
