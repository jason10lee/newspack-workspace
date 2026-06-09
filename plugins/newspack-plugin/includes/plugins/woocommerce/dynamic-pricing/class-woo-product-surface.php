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

	/**
	 * Request-scoped registry of publicized applies, keyed by cart item key.
	 * The cart filter callbacks read from this to render strikethrough + label.
	 *
	 * @var array<string, array{original: float, discounted: float, label: string, policy_id: string}>
	 */
	private static array $publicized_applies = [];

	public function id(): string        { return 'woo_product'; }
	public function is_stateful(): bool { return false; }
	public function triggers(): array   { return [ self::TRIGGER_CART ]; }

	public static function init(): void {
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'on_calculate_totals' ], 20, 1 );

		// Reset the per-request registry at the start of each cart calc so stale entries
		// from a previous iteration don't leak into the new render.
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'reset_publicized_registry' ], 19, 1 );

		// Reader-facing communication of publicized policies.
		add_filter( 'woocommerce_cart_item_subtotal', [ __CLASS__, 'filter_cart_item_subtotal' ], 20, 3 );
		add_filter( 'woocommerce_cart_item_name', [ __CLASS__, 'filter_cart_item_name' ], 20, 3 );
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

		// Record publicized applies so the cart filters can render strikethrough + label.
		if ( $d->publicize && isset( $ctx->target['key'] ) && is_string( $ctx->target['key'] ) ) {
			self::$publicized_applies[ $ctx->target['key'] ] = [
				'original'   => $ctx->base_price,
				'discounted' => $d->amount,
				'label'      => $d->label,
				'policy_id'  => (string) ( $d->policy_id ?? '' ),
			];
		}
	}

	/** @internal — reset state at the start of each cart calc pass. */
	public static function reset_publicized_registry( $cart ): void {
		if ( $cart instanceof \WC_Cart ) {
			self::$publicized_applies = [];
		}
	}

	/** @internal — for tests + filter callbacks. */
	public static function get_publicized_apply_for( string $cart_item_key ): ?array {
		return self::$publicized_applies[ $cart_item_key ] ?? null;
	}

	/**
	 * Filter: when a publicized policy applies, prepend the original price (strikethrough)
	 * to the rendered subtotal. Format respects the WCS recurring-price wrapper.
	 *
	 * @param string $subtotal       Already-formatted subtotal (may include WCS "/month" suffix).
	 * @param array  $cart_item      Cart item array.
	 * @param string $cart_item_key  Cart item key.
	 */
	public static function filter_cart_item_subtotal( string $subtotal, array $cart_item, string $cart_item_key ): string {
		$applied = self::get_publicized_apply_for( $cart_item_key );
		if ( ! $applied || abs( $applied['original'] - $applied['discounted'] ) < 0.01 ) {
			return $subtotal;
		}
		$qty           = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;
		$original_each = (float) $applied['original'];
		$original_line = $original_each * max( 1, $qty );

		return '<del style="opacity: 0.6">' . wc_price( $original_line ) . '</del> ' . $subtotal;
	}

	/**
	 * Filter: append a small label badge to the cart item name announcing the active policy.
	 */
	public static function filter_cart_item_name( string $name, array $cart_item, string $cart_item_key ): string {
		$applied = self::get_publicized_apply_for( $cart_item_key );
		if ( ! $applied || '' === $applied['label'] ) {
			return $name;
		}
		return $name . ' <span class="newspack-dp-badge" style="display:inline-block;padding:2px 8px;margin-left:6px;border-radius:10px;background:#e7f5ff;color:#1c7ed6;font-size:11px;font-weight:600;vertical-align:middle">' . esc_html( $applied['label'] ) . '</span>';
	}
}
