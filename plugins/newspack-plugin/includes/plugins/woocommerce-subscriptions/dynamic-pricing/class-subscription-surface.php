<?php
/**
 * Subscription Surface — stateful: persist single recurring line item + idempotency + audit.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing\Subscriptions;

use Newspack\Dynamic_Pricing\Amount_Calculator;
use Newspack\Dynamic_Pricing\Policy;
use Newspack\Dynamic_Pricing\Price_Decision;
use Newspack\Dynamic_Pricing\Price_Surface;
use Newspack\Dynamic_Pricing\Pricing_Context;
use Newspack\Dynamic_Pricing\Pricing_Engine;
use Newspack\Dynamic_Pricing\Subscription_Pin;
use Newspack\Dynamic_Pricing\WooProduct_Surface;

defined( 'ABSPATH' ) || exit;

/**
 * Stateful price surface for WooCommerce Subscriptions.
 *
 * Owns the `woocommerce_subscription_payment_complete` listener and, when the
 * Pricing_Engine produces a durable decision, writes it to the subscription's
 * single recurring line item. Multi-line subscriptions are excluded upstream
 * by the engine, so this surface always operates on the first line item.
 *
 * Idempotency: an amount-equality short-circuit and a per-policy state map
 * stored on `_newspack_dynamic_pricing_state` ensure repeated invocations are
 * no-ops once a policy has applied at a given dimension value.
 */
final class Subscription_Surface implements Price_Surface {
	const STATE_META_KEY         = '_newspack_dynamic_pricing_state';
	const TRIGGER_SCHEDULED_STEP = 'scheduled_step';

	public function id(): string        { return 'subscription'; }
	public function is_stateful(): bool { return true; }
	public function triggers(): array   { return [ self::TRIGGER_SCHEDULED_STEP ]; }

	/**
	 * Bind the WCS payment-complete listener.
	 */
	public static function init(): void {
		add_action( 'woocommerce_subscription_payment_complete', [ __CLASS__, 'on_payment_complete' ], 20, 1 );

		// Audit trail: WCS fires this for each subscription created at checkout
		// (both classic and Store API flows route through WCS checkout creation).
		// The acquisition (cycle 1) price was applied by WooProduct_Surface on the
		// cart; without a note here the subscription has no record of why its
		// first cycle differs from the regular price.
		add_action( 'woocommerce_checkout_subscription_created', [ __CLASS__, 'note_acquisition_on_subscription' ], 20, 3 );

		// Policy pinning (docs 03): when the acquisition price came from a
		// deal-class policy, snapshot that policy's config onto the subscription —
		// renewals resolve from the snapshot, so later policy edits affect new
		// acquisitions only. Priority 25: the acquisition note above lands first.
		add_action( 'woocommerce_checkout_subscription_created', [ __CLASS__, 'pin_deal_on_subscription' ], 25, 3 );
	}

	/**
	 * Pin the winning deal-class policy onto a newly created subscription.
	 *
	 * The applied registry holds the winning decision's policy id per cart item
	 * key; the policy row is read fresh (same request as resolution) and its
	 * config snapshotted onto the matching recurring line item. Live-class
	 * policies never pin.
	 *
	 * @param \WC_Subscription $subscription   Newly created subscription.
	 * @param \WC_Order        $order          Parent order.
	 * @param \WC_Cart         $recurring_cart Recurring cart this subscription was created from.
	 */
	public static function pin_deal_on_subscription( $subscription, $order, $recurring_cart ): void {
		if ( ! $subscription instanceof \WC_Subscription || ! $recurring_cart instanceof \WC_Cart ) {
			return;
		}
		foreach ( $recurring_cart->get_cart() as $cart_item_key => $cart_item ) {
			$applied = WooProduct_Surface::get_applied_for( (string) $cart_item_key );
			if ( ! $applied || '' === (string) $applied['policy_id'] ) {
				continue;
			}
			$post = get_post( (int) $applied['policy_id'] );
			if ( ! $post ) {
				continue;
			}
			$policy = Policy::from_post( $post );
			if ( Policy::APPLICATION_LOCKED !== $policy->application ) {
				continue;
			}

			$line = self::find_line_for_cart_item( $subscription, $cart_item );
			if ( ! $line ) {
				continue;
			}
			Subscription_Pin::pin( $line, $policy );
			$subscription->add_order_note(
				sprintf(
					/* translators: 1: rule id */
					__( 'Newspack Dynamic Pricing [rule %1$s]: terms locked at purchase — renewals follow this rule as configured at purchase; later edits affect new purchases only.', 'newspack-plugin' ),
					$policy->id
				)
			);
		}
	}

	/**
	 * The subscription line item matching a recurring-cart item's product, with
	 * a first-line fallback (multi-line subscriptions are excluded upstream).
	 *
	 * @param \WC_Subscription $subscription Subscription to search.
	 * @param array            $cart_item    Recurring cart item.
	 * @return \WC_Order_Item_Product|null
	 */
	private static function find_line_for_cart_item( \WC_Subscription $subscription, array $cart_item ): ?\WC_Order_Item_Product {
		$target_pid = (int) ( ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : ( $cart_item['product_id'] ?? 0 ) );
		foreach ( $subscription->get_items( 'line_item' ) as $line ) {
			$line_pid = (int) ( $line->get_variation_id() ?: $line->get_product_id() );
			if ( 0 === $target_pid || $line_pid === $target_pid ) {
				return $line;
			}
		}
		return null;
	}

	/**
	 * Note the acquisition pricing on a newly created subscription.
	 *
	 * Recurring carts are keyed clones of the main cart, so their item keys map
	 * directly onto WooProduct_Surface's applied-decisions registry.
	 *
	 * @param \WC_Subscription $subscription   Newly created subscription.
	 * @param \WC_Order        $order          Parent order.
	 * @param \WC_Cart         $recurring_cart Recurring cart this subscription was created from.
	 */
	public static function note_acquisition_on_subscription( $subscription, $order, $recurring_cart ): void {
		if ( ! $subscription instanceof \WC_Subscription || ! $recurring_cart instanceof \WC_Cart ) {
			return;
		}
		foreach ( $recurring_cart->get_cart() as $cart_item_key => $cart_item ) {
			$applied = WooProduct_Surface::get_applied_for( (string) $cart_item_key );
			if ( ! $applied ) {
				continue;
			}
			$subscription->add_order_note(
				sprintf(
					/* translators: 1: acquisition audit line (policy, product, prices) */
					__( '%1$s Applied at acquisition (cycle 1); renewals are repriced after each payment.', 'newspack-plugin' ),
					WooProduct_Surface::acquisition_note( $applied )
				)
			);
		}
	}

	/**
	 * WCS payment-complete callback — resolves a decision and applies it.
	 */
	public static function on_payment_complete( \WC_Subscription $sub ): void {
		$surface = Pricing_Engine::instance()->surface( 'subscription' );
		if ( ! $surface instanceof self ) {
			return;
		}

		// Guard: bail if the subscription has no recurring line item, or its product
		// has been deleted (`wc_get_product()` returns false). The Pricing_Context
		// constructor declares $product as non-nullable, so this guard prevents a
		// TypeError on the payment_complete hook.
		$line = $surface->get_recurring_line_item( $sub );
		if ( ! $line ) {
			return;
		}
		$product = wc_get_product( $line->get_variation_id() ?: $line->get_product_id() );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$ctx = $surface->context( $sub, self::TRIGGER_SCHEDULED_STEP );
		$d   = Pricing_Engine::instance()->resolve( $ctx );
		if ( $d ) {
			$surface->apply( $ctx, $d );
		}
	}

	/**
	 * Build a Pricing_Context from a subscription + trigger.
	 *
	 * `completed_cycles` is `completed_payment_count + 1` — the *upcoming* cycle
	 * the surface is pricing, not the cycle that just paid. Strategies key off
	 * this value (stepped pricing dimension anchor).
	 *
	 * @param mixed  $sub     A WC_Subscription instance.
	 * @param string $trigger Lifecycle event id.
	 */
	public function context( $sub, string $trigger ): Pricing_Context {
		$upcoming_cycle = $sub->get_payment_count( 'completed' ) + 1;

		$line     = $this->get_recurring_line_item( $sub );
		$product  = $line ? wc_get_product( $line->get_variation_id() ?: $line->get_product_id() ) : null;
		$customer = $sub->get_user_id() ? new \WC_Customer( $sub->get_user_id() ) : null;

		$base_price = $product ? Amount_Calculator::base_price_for( $product ) : 0.0;

		return new Pricing_Context(
			$trigger,
			$product,
			$customer,
			$base_price,
			[ 'completed_cycles' => $upcoming_cycle ],
			$sub,
			Pricing_Context::INTENT_RENEWAL,
			$this->is_stateful()
		);
	}

	/**
	 * Persist a durable decision onto the recurring line item.
	 *
	 * One-time decisions (`Price_Decision::ONE_TIME`) are not the
	 * subscription surface's concern — they are handled at checkout. Decisions
	 * whose amount already matches the current line subtotal short-circuit
	 * before any writes (no audit note, no state). Decisions whose
	 * `(policy_id, dimension_value, amount)` triple already appears in the
	 * state map also short-circuit (idempotency).
	 *
	 * @param Pricing_Context $ctx Context (target is the \WC_Subscription).
	 * @param Price_Decision  $d   Resolved decision from the Pricing_Engine.
	 */
	public function apply( Pricing_Context $ctx, Price_Decision $d ): void {
		if ( Price_Decision::DURABLE !== $d->durability ) {
			return;
		}

		$sub  = $ctx->target;
		$line = $this->get_recurring_line_item( $sub );
		if ( ! $line ) {
			return;
		}

		// The decision amount is PER UNIT; line subtotal/total aggregate quantity.
		$qty         = max( 1, (int) $line->get_quantity() );
		$line_amount = round( $d->amount * $qty, 2 );

		if ( abs( (float) $line->get_subtotal() - $line_amount ) < 0.01 ) {
			return;
		}

		if ( $this->already_applied( $sub, $d ) ) {
			return;
		}

		$line->set_subtotal( $line_amount );
		$line->set_total( $line_amount );
		$line->save();

		$sub->calculate_totals();
		$sub->add_order_note(
			sprintf(
				/* translators: 1: rule id, 2: formatted price, 3: human label */
				__( 'Newspack Dynamic Pricing [rule %1$s]: recurring price set to %2$s (%3$s).', 'newspack-plugin' ),
				$d->policy_id,
				wc_price( $d->amount ),
				$d->label
			)
		);
		$this->record_state( $sub, $d );
		$sub->save();
	}

	/**
	 * Has this `(policy, dimension_value, amount)` triple already been applied?
	 */
	private function already_applied( \WC_Subscription $sub, Price_Decision $d ): bool {
		$state = $sub->get_meta( self::STATE_META_KEY );
		if ( ! is_array( $state ) ) {
			return false;
		}
		$prior = $state[ $d->policy_id ] ?? null;
		return $prior
			&& ( $prior['dimension_value'] ?? null ) === $d->dimension_value
			&& abs( (float) ( $prior['amount'] ?? 0 ) - $d->amount ) < 0.01;
	}

	/**
	 * Record an applied decision on the subscription's state meta map.
	 */
	private function record_state( \WC_Subscription $sub, Price_Decision $d ): void {
		$state = $sub->get_meta( self::STATE_META_KEY );
		if ( ! is_array( $state ) ) {
			$state = [];
		}
		$state[ $d->policy_id ] = [
			'strategy_id'     => $d->strategy_id,
			'amount'          => $d->amount,
			'dimension_value' => $d->dimension_value,
			'reason'          => $d->reason,
			'applied_at'      => current_time( 'mysql', true ),
		];
		$sub->update_meta_data( self::STATE_META_KEY, $state );
	}

	/**
	 * Return the first (only) recurring line item; multi-line subs are excluded
	 * upstream by Pricing_Engine::is_excluded().
	 *
	 * @return \WC_Order_Item_Product|null
	 */
	private function get_recurring_line_item( \WC_Subscription $sub ): ?\WC_Order_Item_Product {
		foreach ( $sub->get_items( 'line_item' ) as $item ) {
			return $item;
		}
		return null;
	}
}
