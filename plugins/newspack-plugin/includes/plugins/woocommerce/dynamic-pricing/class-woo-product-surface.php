<?php
/**
 * Cart-time pricing surface for WooCommerce product purchases.
 *
 * Stateless: recomputes on every cart calculation, persists nothing. Hooks
 * `woocommerce_before_calculate_totals` and rewrites cart item prices in place
 * via `$cart_item['data']->set_price()` so WC totals are computed against the
 * rule-resolved amount.
 *
 * Acquisition contract: this surface prices ACQUISITIONS ONLY — cart items
 * that create a new subscription. Contexts carry
 * `Pricing_Context::INTENT_ACQUISITION` and resolve with
 * `signals['completed_cycles'] = 1` so a `Stepped_By_Cycle_Strategy` rule
 * grants its `step_at_1` price at checkout (cycle 1 = first invoice). After
 * `payment_complete` fires, the stateful `Subscription_Surface` owns all
 * subsequent repricing (cycles 2+).
 *
 * Recurring-totals projection: WCS clones the cart per billing group and
 * recalculates the clone with `WC_Subscriptions_Cart::get_calculation_type()
 * === 'recurring_total'`. Those recurring carts feed every "Recurring totals"
 * display (legacy cart/checkout, the WC Blocks recurring panel) AND the
 * subscription's initial line items at checkout. During that pass this surface
 * resolves `completed_cycles = 2` — the upcoming renewal — so the recurring
 * total shown to the reader and the subscription's created recurring price are
 * the cycle-2 amount, matching exactly what Subscription_Surface will charge
 * (it already reprices to cycle 2 when the parent payment completes). The
 * projection pass never touches the audit registry and skips the no-harm clamp
 * (it is a forecast, not a charge).
 *
 * Two classes of cart item are therefore ineligible (see is_eligible_cart_item):
 *
 * 1. WCS renewal-family items (`subscription_renewal`, `subscription_resubscribe`,
 *    `subscription_switch`) — not acquisitions. WCS seeds their price from the
 *    existing subscription; repricing them here would grant the cycle-1 intro
 *    price to a manual/early renewal, a lapsed-subscriber resubscribe (a
 *    cancel-and-resubscribe loophole around stepped pricing), or a prorated switch.
 *
 * 2. Items whose future subscription the renewal surface will refuse to manage —
 *    the acquisition-side mirror of Pricing_Engine::is_excluded(): gifted items
 *    (recipient ≠ purchaser) and items that will be grouped into a multi-line
 *    subscription. Granting an intro price at cart while the renewal surface is
 *    excluded would freeze the subscription at the intro amount forever.
 *    (Currency has no cart mirror: a subscription is created in the current
 *    store currency, so a mismatch cannot exist at acquisition time.)
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

final class WooProduct_Surface implements Price_Surface {
	const TRIGGER_CART = 'cart';

	/**
	 * Request-scoped registry of every applied decision, keyed by cart item key.
	 * Feeds the operator-facing audit trail (order/subscription notes at
	 * checkout) — which records every rule interaction unconditionally.
	 *
	 * @var array<string, array{rule_id: string, label: string, reason: string, amount: float, original: float, item_name: string, quantity: int}>
	 */
	private static array $applied_decisions = [];

	public function id(): string        { return 'woo_product'; }
	public function is_stateful(): bool { return false; }
	public function triggers(): array   { return [ self::TRIGGER_CART ]; }

	/**
	 * Whether the current cart calculation is WCS's recurring-totals projection
	 * pass (a cloned cart representing future renewals) rather than the main
	 * cart (the purchase being charged now). See the file header.
	 */
	private static function is_recurring_totals_pass(): bool {
		return class_exists( '\WC_Subscriptions_Cart' )
			&& method_exists( '\WC_Subscriptions_Cart', 'get_calculation_type' )
			&& 'recurring_total' === \WC_Subscriptions_Cart::get_calculation_type();
	}

	public static function init(): void {
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'on_calculate_totals' ], 20, 1 );

		// Reset the per-request registry at the start of each cart calc so stale entries
		// from a previous iteration don't leak into the new render.
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'reset_applied_registry' ], 19, 1 );

		// Operator-facing audit: note the acquisition pricing on the order created
		// at checkout. Classic checkout and Store API (WC Blocks) checkout fire
		// different hooks; the handler is shared and idempotent.
		add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'note_acquisition_on_order' ], 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'note_acquisition_on_order' ], 20, 1 );

		// Reader-facing surfaces (cart strikethrough, modal summary, WC Blocks
		// checkout filters, StoreAPI extension) were removed pending a rework
		// that accounts for stepped pricing and per-cycle limits in the
		// recurring-total display. The Pricing_Rule::$publicize flag remains in
		// the entity and the snapshot for forward compatibility; the rule edit
		// UI shows the option disabled.
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
			if ( ! self::is_eligible_cart_item( $cart_item ) ) {
				continue;
			}
			$resolved = self::resolve_for_cart_item( $cart_item );
			if ( $resolved ) {
				$surface->apply( $resolved[0], $resolved[1] );
			}
		}
	}

	/**
	 * Single resolution path for a cart item — used by the totals pass.
	 *
	 * No-harm clamp (main pass only): at acquisition, a rule may only ever lower
	 * the price the customer would otherwise pay. The decision computes off the
	 * catalog base (regular/WCS price), so on a product with an active sale price
	 * the result could exceed the effective price the customer was shown — in
	 * that case the rule abstains and WC's native pricing stands. The recurring
	 * projection pass is exempt: it forecasts what renewals will charge (which
	 * can legitimately exceed the discounted purchase price — a stepped rule
	 * stepping up IS the product), and the clone's product objects already carry
	 * the main pass's written price, so clamping against them would freeze the
	 * projection at cycle 1.
	 *
	 * @param array $cart_item Cart item array (must already be eligible).
	 * @return array{0: Pricing_Context, 1: Price_Decision}|null
	 */
	private static function resolve_for_cart_item( array $cart_item ): ?array {
		$surface = Pricing_Engine::instance()->surface( 'woo_product' );
		if ( ! $surface instanceof self ) {
			return null;
		}
		$ctx = $surface->context( $cart_item, self::TRIGGER_CART );
		$d   = Pricing_Engine::instance()->resolve( $ctx );
		if ( ! $d ) {
			return null;
		}

		if ( ! self::is_recurring_totals_pass() ) {
			$effective = (float) $cart_item['data']->get_price();
			if ( $effective > 0 && $d->amount > $effective + 0.005 ) {
				return null;
			}
		}

		return [ $ctx, $d ];
	}

	/**
	 * Whether this surface may price a cart item. See the file header for the
	 * acquisition contract this enforces.
	 *
	 * @internal Public for tests.
	 *
	 * @param mixed $cart_item Cart item array.
	 */
	public static function is_eligible_cart_item( $cart_item ): bool {
		if ( ! is_array( $cart_item ) || ! isset( $cart_item['data'] ) || ! ( $cart_item['data'] instanceof \WC_Product ) ) {
			return false;
		}

		// WCS renewal-family items are not acquisitions.
		foreach ( [ 'subscription_renewal', 'subscription_resubscribe', 'subscription_switch' ] as $key ) {
			if ( ! empty( $cart_item[ $key ] ) ) {
				return false;
			}
		}

		// Gifted: the resulting subscription belongs to the recipient and the
		// renewal surface excludes it (Pricing_Engine::is_excluded), so an intro
		// grant here could never be stepped afterwards.
		if ( ! empty( $cart_item['wcsg_gift_recipients_email'] ) ) {
			return false;
		}

		// Multi-line-bound: WCS groups cart items sharing a recurring cart key
		// into ONE subscription with multiple line items, which the renewal
		// surface excludes. Don't grant at cart what renewal can't manage.
		if (
			class_exists( '\WC_Subscriptions_Cart' )
			&& class_exists( '\WC_Subscriptions_Product' )
			&& method_exists( '\WC_Subscriptions_Cart', 'get_recurring_cart_key' )
			&& \WC_Subscriptions_Product::is_subscription( $cart_item['data'] )
			&& function_exists( 'WC' ) && WC() && WC()->cart
		) {
			$item_key = \WC_Subscriptions_Cart::get_recurring_cart_key( $cart_item );
			$siblings = 0;
			foreach ( WC()->cart->get_cart() as $other ) {
				if (
					isset( $other['data'] )
					&& $other['data'] instanceof \WC_Product
					&& \WC_Subscriptions_Product::is_subscription( $other['data'] )
					&& \WC_Subscriptions_Cart::get_recurring_cart_key( $other ) === $item_key
				) {
					$siblings++;
				}
			}
			if ( $siblings > 1 ) {
				return false;
			}
		}

		return true;
	}

	public function context( $target, string $trigger ): Pricing_Context {
		$product    = $target['data'];
		$base_price = Amount_Calculator::base_price_for( $product );

		$customer = null;
		if ( function_exists( 'WC' ) && WC() && WC()->customer ) {
			$customer = WC()->customer;
		}

		// Main pass prices the purchase (cycle 1). The recurring projection pass
		// prices the upcoming renewal (cycle 2) — the same cycle the renewal
		// surface resolves when the parent payment completes, so the displayed
		// recurring total and the created subscription match the first renewal
		// charge. Intent stays ACQUISITION on both: the projection forecasts the
		// future of the purchase being made now, and rule sourcing must mirror
		// what will actually be locked at checkout.
		$upcoming_cycle = self::is_recurring_totals_pass() ? 2 : 1;

		return new Pricing_Context(
			$trigger,
			$product,
			$customer,
			$base_price,
			[ 'completed_cycles' => $upcoming_cycle ],
			$target,
			Pricing_Context::INTENT_ACQUISITION,
			$this->is_stateful()
		);
	}

	public function apply( Pricing_Context $ctx, Price_Decision $d ): void {
		// DURABLE and ONE_TIME both map to "set price on this cart item for this
		// calculation pass." Stateless surface — recomputed each cart calc.
		if ( ! is_array( $ctx->target ) || ! isset( $ctx->target['data'] ) || ! ( $ctx->target['data'] instanceof \WC_Product ) ) {
			return;
		}
		$ctx->target['data']->set_price( $d->amount );

		// The recurring projection pass shares the main cart's item keys (the
		// clone copies them) and runs AFTER the main pass within the same
		// calculate_totals() call — recording it would overwrite the charged
		// cycle-1 amounts in the audit registry with cycle-2 forecasts.
		if ( self::is_recurring_totals_pass() ) {
			return;
		}

		if ( isset( $ctx->target['key'] ) && is_string( $ctx->target['key'] ) ) {
			self::$applied_decisions[ $ctx->target['key'] ] = [
				'rule_id'   => (string) ( $d->rule_id ?? '' ),
				'label'     => $d->label,
				'reason'    => $d->reason,
				'amount'    => $d->amount,
				'original'  => $ctx->base_price,
				'item_name' => (string) $ctx->product->get_name(),
				'quantity'  => isset( $ctx->target['quantity'] ) ? max( 1, (int) $ctx->target['quantity'] ) : 1,
			];
		}
	}

	/**
	 * The applied decision for a cart item. Audit-trail consumers (checkout
	 * note writers) read this.
	 *
	 * @internal Public for the subscriptions layer and tests.
	 */
	public static function get_applied_for( string $cart_item_key ): ?array {
		return self::$applied_decisions[ $cart_item_key ] ?? null;
	}

	/** @internal — reset the request-scoped registry at the start of each cart calc pass. */
	public static function reset_applied_registry( $cart ): void {
		// The recurring projection pass fires this hook for its cloned carts
		// INSIDE the main calculate_totals() call — resetting there would wipe
		// the charged amounts the checkout note writers are about to read.
		if ( $cart instanceof \WC_Cart && ! self::is_recurring_totals_pass() ) {
			self::$applied_decisions = [];
		}
	}

	/**
	 * Audit trail: when the order created at checkout contains rule-priced
	 * lines, record one order note per line stating which rule set which price
	 * — silent policies included. Without this, an operator looking at a $5
	 * parent order of a $10 product has no way to tell why.
	 *
	 * Hooked to both `woocommerce_checkout_order_processed` (classic checkout,
	 * first arg is the order id) and `woocommerce_store_api_checkout_order_processed`
	 * (WC Blocks checkout, first arg is the order). Both fire in the same request
	 * as the final cart calculation, so the applied registry is populated.
	 *
	 * @param int|\WC_Order $order_or_id Order id (classic) or order (Store API).
	 */
	public static function note_acquisition_on_order( $order_or_id ): void {
		$order = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
		if ( ! $order || empty( self::$applied_decisions ) ) {
			return;
		}
		// Idempotency across checkout flows that might fire both hooks.
		if ( $order->get_meta( '_newspack_dp_acquisition_noted' ) ) {
			return;
		}

		foreach ( self::$applied_decisions as $applied ) {
			$order->add_order_note( self::acquisition_note( $applied ) );
		}
		$order->update_meta_data( '_newspack_dp_acquisition_noted', '1' );
		$order->save();
	}

	/**
	 * Operator-facing audit copy for an acquisition apply. Shared by the order
	 * note and the subscriptions layer's acquisition note.
	 *
	 * @internal Public for the subscriptions layer and tests.
	 *
	 * @param array $applied Entry from the applied-decisions registry.
	 */
	public static function acquisition_note( array $applied ): string {
		$descriptor = '' !== (string) $applied['label'] ? (string) $applied['label'] : (string) $applied['reason'];
		return sprintf(
			/* translators: 1: rule id, 2: product name, 3: charged price, 4: regular price, 5: rule label or reason */
			__( 'Newspack Dynamic Pricing [rule %1$s]: "%2$s" priced at %3$s — regular price %4$s (%5$s).', 'newspack-plugin' ),
			$applied['rule_id'],
			$applied['item_name'],
			wc_price( (float) $applied['amount'] ),
			wc_price( (float) $applied['original'] ),
			$descriptor
		);
	}

}
