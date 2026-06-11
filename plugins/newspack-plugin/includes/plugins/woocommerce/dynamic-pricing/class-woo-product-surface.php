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
	 * checkout) — which records every rule interaction unconditionally — and,
	 * for entries with `publicize`, the reader-facing line annotations.
	 *
	 * @var array<string, array{rule_id: string, label: string, reason: string, amount: float, original: float, item_name: string, quantity: int, publicize: bool}>
	 */
	private static array $applied_decisions = [];

	/**
	 * Display-only memo for lazy annotation resolution, keyed by cart item key.
	 * `array` = resolved entry; `false` = resolved to "no decision" (negative
	 * memo so display filters don't re-run the engine per callback). Separate
	 * from $applied_decisions, which records actual applies for the audit trail.
	 *
	 * @var array<string, array|false>
	 */
	private static array $display_memo = [];

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

		// Reader-facing price display, Layer 2 cart-totals slice (specs 05 §5):
		// when the price changes again BEYOND the next renewal (multi-step
		// schedules), the single WCS "Recurring total" number cannot carry the
		// story — disclose the full sequence in a row under it. Priority 15:
		// WCS renders its recurring totals at 10 on these same hooks.
		// Legacy-only for now: the WC Blocks recurring panel is WCS's own React
		// component and needs a JS slice. The Newspack modal checkout wraps the
		// legacy review table, so it gets this row for free.
		add_action( 'woocommerce_cart_totals_after_order_total', [ __CLASS__, 'render_schedule_rows' ], 15 );
		add_action( 'woocommerce_review_order_after_order_total', [ __CLASS__, 'render_schedule_rows' ], 15 );

		// Reader-facing price display, Layer 1 (specs 05 §4): annotate the
		// PURCHASE line of publicize-flagged rules with the regular-price
		// comparison and the rule's name. Annotation only — the totals section
		// (Layer 0) and the schedule row (Layer 2a) own the renewal story.
		add_filter( 'woocommerce_cart_item_subtotal', [ __CLASS__, 'filter_cart_item_subtotal' ], 20, 3 );
		add_filter( 'woocommerce_cart_item_name', [ __CLASS__, 'filter_cart_item_name' ], 20, 3 );
		// The legacy cart's per-unit price column carries the SAME misleading
		// "every month" suffix as the subtotal does. Strip it (no qualifier
		// here — the subtotal column owns that copy in the same row).
		add_filter( 'woocommerce_cart_item_price', [ __CLASS__, 'filter_cart_item_price' ], 20, 3 );

		// Newspack Blocks Modal Checkout — its JS does textContent = price_summary
		// so HTML from the filters above is stripped from the <strong> wrapper.
		// Hook the modal's own summary filter and append plain-text rule info.
		add_filter( 'newspack_modal_checkout_price_summary', [ __CLASS__, 'filter_modal_price_summary' ], 20, 2 );

		// WC Blocks Cart & Checkout — StoreAPI cart-item extension + JS filters
		// (see src/other-scripts/dynamic-pricing-blocks-checkout/). Strings are
		// composed and translated server-side; the JS only appends them.
		// `woocommerce_blocks_loaded` fires before our plugins_loaded priority,
		// so call directly when the registrar exists; defer otherwise.
		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			self::register_store_api_extension();
		} else {
			add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_store_api_extension' ] );
		}
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_blocks_checkout_script' ] );
	}

	/**
	 * Render a "Price schedule" totals row per cart item whose price changes
	 * again beyond the next renewal. Single-step rules render nothing — the
	 * WCS recurring total already tells their whole story.
	 */
	public static function render_schedule_rows(): void {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! self::is_eligible_cart_item( $cart_item ) ) {
				continue;
			}
			if ( ! class_exists( '\WC_Subscriptions_Product' ) || ! \WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
				continue;
			}
			$segments = Schedule_Projector::project_for_cart_item( $cart_item );
			if ( ! Schedule_Projector::has_undisclosed_changes( $segments ) ) {
				continue;
			}
			printf(
				'<tr class="newspack-dp-schedule"><th>%s</th><td data-title="%s"><small>%s</small></td></tr>',
				esc_html__( 'Price schedule', 'newspack-plugin' ),
				esc_attr__( 'Price schedule', 'newspack-plugin' ),
				esc_html( self::schedule_sentence( $segments, $cart_item['data'] ) )
			);
		}
	}

	/**
	 * Human-readable schedule: "$5.00 today, then $7.50 for 1 renewal, then
	 * $10.00 / month". Anchored at today's charge so the sequence reads as one
	 * narrative that agrees with the order total above it, then walks every
	 * renewal price through the stabilized ongoing amount.
	 *
	 * @internal Public for tests.
	 *
	 * @param array       $segments Output of Schedule_Projector::project_for_cart_item().
	 * @param \WC_Product $product  Subscription product (for the billing period label).
	 */
	public static function schedule_sentence( array $segments, \WC_Product $product ): string {
		if ( empty( $segments ) ) {
			return '';
		}
		$renewal_segments = Schedule_Projector::renewal_segments( $segments );
		$per              = self::billing_period_label( $product );

		$today   = wp_strip_all_tags( html_entity_decode( wc_price( $segments[0]['amount'] ), ENT_QUOTES ) );
		$phrases = [
			/* translators: %s: the price charged at checkout, e.g. "$5.00 today" */
			sprintf( __( '%s today', 'newspack-plugin' ), $today ),
		];

		$count = count( $renewal_segments );
		foreach ( $renewal_segments as $i => $segment ) {
			$price = wp_strip_all_tags( html_entity_decode( wc_price( $segment['amount'] ), ENT_QUOTES ) );
			if ( $i === $count - 1 ) {
				/* translators: 1: price, 2: billing period — the ongoing price, e.g. "$10.00 / month" */
				$phrases[] = sprintf( __( '%1$s / %2$s', 'newspack-plugin' ), $price, $per );
				break;
			}
			$length    = $renewal_segments[ $i + 1 ]['from_cycle'] - $segment['from_cycle'];
			/* translators: 1: price, 2: number of renewals at that price */
			$phrases[] = sprintf( _n( '%1$s for %2$d renewal', '%1$s for %2$d renewals', $length, 'newspack-plugin' ), $price, $length );
		}

		/* translators: used to join schedule phrases: "$5.00 today, then $7.50 for 1 renewal, then $10.00 / month" */
		return implode( __( ', then ', 'newspack-plugin' ), $phrases );
	}

	public static function on_calculate_totals( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		$surface = Pricing_Engine::instance()->surface( 'woo_product' );
		if ( ! $surface instanceof self ) {
			return;
		}

		foreach ( $cart->get_cart() as $key => $cart_item ) {
			if ( ! self::is_eligible_cart_item( $cart_item ) ) {
				continue;
			}

			// Recurring carts are shallow clones sharing the main cart's product
			// OBJECTS. Writing the projection price (cycle 2) to the shared
			// instance would leak into the main cart's line-item display: stored
			// totals keep the charged amount, but the item column re-reads the
			// product object. Price a private copy instead.
			if ( self::is_recurring_totals_pass() ) {
				$cart_item['data'] = clone $cart_item['data'];
				if ( isset( $cart->cart_contents[ $key ] ) ) {
					$cart->cart_contents[ $key ]['data'] = $cart_item['data'];
				}
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
			self::$applied_decisions[ $ctx->target['key'] ] = self::decision_entry( $ctx, $d );
		}
	}

	/**
	 * The one construction site for a decision-registry entry — used by apply()
	 * and the lazy annotation resolver so audit and display can never disagree.
	 */
	private static function decision_entry( Pricing_Context $ctx, Price_Decision $d ): array {
		return [
			'rule_id'   => (string) ( $d->rule_id ?? '' ),
			'label'     => $d->label,
			'reason'    => $d->reason,
			'amount'    => $d->amount,
			'original'  => $ctx->base_price,
			'item_name' => (string) $ctx->product->get_name(),
			'quantity'  => isset( $ctx->target['quantity'] ) ? max( 1, (int) $ctx->target['quantity'] ) : 1,
			'publicize' => (bool) $d->publicize,
		];
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
			self::$display_memo      = [];
		}
	}

	/**
	 * The reader-facing annotation for a cart item: the applied (or lazily
	 * resolved) decision entry of a publicize-flagged rule, or null.
	 *
	 * Why lazy: on some render paths (notably the Newspack modal checkout's
	 * `render_before_checkout_form`), cart item filters fire BEFORE this
	 * request's `calculate_totals()` populates the applied registry. Re-resolve
	 * from the cart item directly and memoize; later callbacks hit the memo.
	 *
	 * @internal Public for tests.
	 */
	public static function get_annotation_for( string $cart_item_key ): ?array {
		$entry = self::$applied_decisions[ $cart_item_key ] ?? null;

		if ( null === $entry && array_key_exists( $cart_item_key, self::$display_memo ) ) {
			$memo  = self::$display_memo[ $cart_item_key ];
			$entry = is_array( $memo ) ? $memo : null;
		} elseif ( null === $entry ) {
			// Cart not booted yet — don't memoize, a later callback may succeed.
			if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
				return null;
			}
			$cart_item = WC()->cart->get_cart_item( $cart_item_key );
			$resolved  = is_array( $cart_item ) && self::is_eligible_cart_item( $cart_item )
				? self::resolve_for_cart_item( $cart_item )
				: null;
			if ( ! $resolved ) {
				self::$display_memo[ $cart_item_key ] = false;
				return null;
			}
			$entry = self::decision_entry( $resolved[0], $resolved[1] );

			self::$display_memo[ $cart_item_key ] = $entry;
		}

		return $entry && ! empty( $entry['publicize'] ) ? $entry : null;
	}

	/**
	 * Filter: when a publicized rule prices a line, show the regular price
	 * struck through next to the charged subtotal, plus a "first month"
	 * qualifier when the purchase price does not recur. No renewal forecasting
	 * here — the recurring totals and the schedule row own that story.
	 *
	 * @param string $subtotal      Already-formatted subtotal (includes WCS "/ month").
	 * @param array  $cart_item     Cart item array.
	 * @param string $cart_item_key Cart item key.
	 */
	public static function filter_cart_item_subtotal( string $subtotal, array $cart_item, string $cart_item_key ): string {
		$a = self::get_annotation_for( $cart_item_key );
		if ( ! $a || abs( $a['original'] - $a['amount'] ) < 0.01 ) {
			return $subtotal;
		}
		$qty           = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
		$original_line = (float) $a['original'] * $qty;

		// "first month" replaces the period suffix when the charged price is
		// purchase-only; without that swap, the line reads "$5.00 / month" and
		// claims the intro price recurs. The recurring totals + schedule rows
		// below carry the renewal story unambiguously.
		$qualifier = self::first_cycle_qualifier( $cart_item );
		if ( '' !== $qualifier && $cart_item['data'] instanceof \WC_Product ) {
			$subtotal = self::strip_period_suffix( $subtotal, $cart_item['data'] );
		}

		// wc_format_sale_price produces WC's standard <del>/<ins> sale markup
		// (with screen-reader text) that all WC themes style.
		$html = function_exists( 'wc_format_sale_price' )
			? wc_format_sale_price( wc_price( $original_line ), $subtotal )
			: $subtotal;

		if ( '' !== $qualifier ) {
			$html .= ' <small class="newspack-dp-first-cycle" style="display:block;color:#666;font-weight:normal">' . esc_html( $qualifier ) . '</small>';
		}

		return $html;
	}

	/**
	 * Filter: strip WCS's "/ month" / "every month" period suffix from the
	 * legacy cart's PER-UNIT price column (PRODUCT column). The subtotal
	 * column (the row's TOTAL) already carries the strikethrough + qualifier;
	 * here we just clean the suffix so the row doesn't double-claim "every
	 * month" on a price that doesn't recur.
	 *
	 * @param string $price          Per-unit price HTML (may already include WCS's <span class="subscription-details">).
	 * @param array  $cart_item      Cart item array.
	 * @param string $cart_item_key  Cart item key.
	 */
	public static function filter_cart_item_price( string $price, array $cart_item, string $cart_item_key ): string {
		$a = self::get_annotation_for( $cart_item_key );
		if ( ! $a || abs( $a['original'] - $a['amount'] ) < 0.01 ) {
			return $price;
		}
		if ( ! ( $cart_item['data'] instanceof \WC_Product ) ) {
			return $price;
		}
		$qualifier = self::first_cycle_qualifier( $cart_item );
		if ( '' === $qualifier ) {
			return $price; // Charged price recurs; keep WCS's period suffix here.
		}
		return self::strip_period_suffix( $price, $cart_item['data'] );
	}

	/**
	 * Filter: append a small badge with the rule's reader-facing name to the
	 * cart item name. Label-less publicized rules get the price annotation only.
	 */
	public static function filter_cart_item_name( string $name, array $cart_item, string $cart_item_key ): string {
		$a = self::get_annotation_for( $cart_item_key );
		if ( ! $a || '' === (string) $a['label'] ) {
			return $name;
		}
		return $name . ' <span class="newspack-dp-badge" style="display:inline-block;padding:2px 8px;margin-left:6px;border-radius:10px;background:#e7f5ff;color:#1c7ed6;font-size:11px;font-weight:600;vertical-align:middle">' . esc_html( $a['label'] ) . '</span>';
	}

	/**
	 * Filter: Newspack Blocks Modal Checkout price summary. Output is plain text
	 * (the modal's JS does textContent = price_summary), so no HTML — append
	 * "(Label — regularly $10.00)" inline.
	 *
	 * @param string $summary    Pre-formatted summary like "Test Subscription: $5.00 / month".
	 * @param int    $product_id Product (or variation) id displayed in the modal.
	 */
	public static function filter_modal_price_summary( string $summary, $product_id ): string {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return $summary;
		}
		$pid = (int) $product_id;
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$item_pid = (int) ( ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : ( $cart_item['product_id'] ?? 0 ) );
			if ( $item_pid !== $pid ) {
				continue;
			}
			$a = self::get_annotation_for( (string) $cart_item_key );
			if ( ! $a || abs( $a['original'] - $a['amount'] ) < 0.01 ) {
				return $summary;
			}
			$qualifier = self::first_cycle_qualifier( $cart_item );
			if ( '' !== $qualifier && $cart_item['data'] instanceof \WC_Product ) {
				$summary = self::strip_period_suffix( $summary, $cart_item['data'] );
			}
			$original_plain = wp_strip_all_tags( html_entity_decode( wc_price( (float) $a['original'] ), ENT_QUOTES ) );
			$parts          = [];
			if ( '' !== (string) $a['label'] ) {
				$parts[] = $a['label'];
			}
			/* translators: %s: regular price */
			$parts[] = sprintf( __( 'regularly %s', 'newspack-plugin' ), $original_plain );
			if ( '' !== $qualifier ) {
				$parts[] = $qualifier;
			}
			return sprintf( '%1$s (%2$s)', $summary, implode( ' — ', $parts ) );
		}
		return $summary;
	}

	/**
	 * "first month" / "first 2 months" when the purchase price does not carry
	 * into the first renewal — the qualifier that REPLACES WCS's native "/ month"
	 * suffix on the purchase line (which otherwise implies the charged price
	 * recurs). Empty when it does carry; the suffix stays in that case.
	 *
	 * Display-path code: never allowed to take checkout down, so projection
	 * failures degrade to no qualifier.
	 */
	public static function first_cycle_qualifier( array $cart_item ): string {
		try {
			$segments = Schedule_Projector::project_for_cart_item( $cart_item );
		} catch ( \Throwable $e ) {
			return '';
		}
		if ( empty( $segments ) ) {
			return '';
		}
		$renewals = Schedule_Projector::renewal_segments( $segments );
		if ( empty( $renewals ) || abs( $segments[0]['amount'] - $renewals[0]['amount'] ) < 0.01 ) {
			return '';
		}
		/* translators: %s: billing period, e.g. "month" or "2 months" — qualifies the charged price as purchase-only */
		return sprintf( __( 'first %s', 'newspack-plugin' ), self::billing_period_label( $cart_item['data'] ) );
	}

	/**
	 * The exact period suffix WCS appends after the price in `get_price_string`
	 * — RAW, which may include WCS's `<span class="subscription-details">`
	 * wrapper. Examples in English:
	 *   - cart subtotal (HTML):       ` <span class="subscription-details"> / month</span>`
	 *   - plain-text contexts:        ` / month`
	 *   - blocks line price:          ` every month`
	 * The plain version is `trim( wp_strip_all_tags( ... ) )` of the raw.
	 * Used by `strip_period_suffix` (both legacy + modal) and by the StoreAPI
	 * payload (sent plain so the blocks JS can match what it actually receives).
	 *
	 * @return string Raw suffix (possibly HTML-wrapped), or empty.
	 */
	private static function wcs_period_suffix( \WC_Product $product ): string {
		if ( ! class_exists( '\WC_Subscriptions_Product' ) || ! method_exists( '\WC_Subscriptions_Product', 'get_price_string' ) ) {
			return '';
		}
		try {
			$placeholder = '##NEWSPACK_DP_PRICE##';
			$with_period = (string) \WC_Subscriptions_Product::get_price_string(
				$product,
				[
					'price'               => $placeholder,
					'sign_up_fee'         => false,
					'trial_length'        => false,
					'subscription_period' => true,
					'subscription_length' => false,
				]
			);
		} catch ( \Throwable $e ) {
			return '';
		}
		$idx = strpos( $with_period, $placeholder );
		if ( false === $idx ) {
			return '';
		}
		return substr( $with_period, $idx + strlen( $placeholder ) );
	}

	/**
	 * Strip the WCS period suffix from a formatted price string. Used on the
	 * purchase line whenever the charged amount doesn't carry into renewal —
	 * the "/ month" / "every month" implies it does. Tries the raw suffix
	 * first (matches HTML-formatted cart subtotals), then the tag-stripped
	 * plain-text version (matches plain-text inputs like the modal summary).
	 * Recurring totals (Layer 0) still carry the period; this is purchase-line
	 * only.
	 */
	private static function strip_period_suffix( string $formatted, \WC_Product $product ): string {
		$raw = self::wcs_period_suffix( $product );
		if ( '' === $raw ) {
			return $formatted;
		}
		$plain = trim( wp_strip_all_tags( $raw ) );
		foreach ( array_unique( array_filter( [ $raw, $plain === '' ? '' : ' ' . $plain, $plain ] ) ) as $candidate ) {
			$stripped = str_replace( $candidate, '', $formatted );
			if ( $stripped !== $formatted ) {
				return $stripped;
			}
		}
		return $formatted;
	}

	/**
	 * "month" or, for interval > 1, "2 months" — shared by the schedule
	 * sentence and the first-cycle qualifier.
	 */
	private static function billing_period_label( \WC_Product $product ): string {
		$period   = class_exists( '\WC_Subscriptions_Product' ) ? (string) \WC_Subscriptions_Product::get_period( $product ) : '';
		$interval = class_exists( '\WC_Subscriptions_Product' ) ? max( 1, (int) \WC_Subscriptions_Product::get_interval( $product ) ) : 1;
		return 1 === $interval
			? $period
			/* translators: 1: interval, 2: billing period (e.g. "2 months") */
			: sprintf( _x( '%1$d %2$ss', 'billing interval, e.g. "2 months"', 'newspack-plugin' ), $interval, $period );
	}

	/**
	 * StoreAPI extension: attach annotation data to each cart item so WC Blocks
	 * Cart/Checkout can read it via `extensions['newspack-dynamic-pricing']`.
	 * The JS side appends the server-composed strings — see
	 * `src/other-scripts/dynamic-pricing-blocks-checkout/`.
	 */
	public static function register_store_api_extension(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		\woocommerce_store_api_register_endpoint_data(
			[
				'endpoint'        => 'cart-item',
				'namespace'       => 'newspack-dynamic-pricing',
				'data_callback'   => [ __CLASS__, 'store_api_cart_item_data' ],
				'schema_callback' => [ __CLASS__, 'store_api_cart_item_schema' ],
			]
		);
		// Cart-level extension for the schedule row (Layer 2b). The legacy
		// cart/checkout templates render the schedule as a totals row from
		// `render_schedule_rows`; the blocks slot reads this payload from the
		// cart-level extension and renders an ExperimentalOrderMeta fill.
		\woocommerce_store_api_register_endpoint_data(
			[
				'endpoint'        => 'cart',
				'namespace'       => 'newspack-dynamic-pricing',
				'data_callback'   => [ __CLASS__, 'store_api_cart_data' ],
				'schema_callback' => [ __CLASS__, 'store_api_cart_schema' ],
			]
		);
	}

	/**
	 * StoreAPI cart-item extension payload. Strings are display-ready and
	 * server-translated; the JS filters append them verbatim.
	 */
	public static function store_api_cart_item_data( array $cart_item ): array {
		$key = isset( $cart_item['key'] ) && is_string( $cart_item['key'] ) ? $cart_item['key'] : '';
		$a   = '' === $key ? null : self::get_annotation_for( $key );
		if ( ! $a || abs( $a['original'] - $a['amount'] ) < 0.01 ) {
			return [ 'publicized' => false ];
		}
		$original_plain = wp_strip_all_tags( html_entity_decode( wc_price( (float) $a['original'] ), ENT_QUOTES ) );
		$qualifier      = $cart_item['data'] instanceof \WC_Product ? self::first_cycle_qualifier( $cart_item ) : '';
		// When the charged price is purchase-only, the JS strips WCS's period
		// suffix (" every month" / " / month") from the price string and
		// substitutes our qualifier. The recurring totals + schedule rows still
		// own the renewal story; the line stays focused on what's charged today.
		// Send the plain-text suffix to JS — blocks usually renders plain text
		// for the cart-item price, so the raw HTML form would not match.
		$period_suffix  = ( '' !== $qualifier && $cart_item['data'] instanceof \WC_Product ) ? trim( wp_strip_all_tags( self::wcs_period_suffix( $cart_item['data'] ) ) ) : '';
		$descriptor     = sprintf( __( 'regularly %s', 'newspack-plugin' ), $original_plain );
		$inline         = '' !== $qualifier ? $descriptor . ' — ' . $qualifier : $descriptor;
		return [
			'publicized'    => true,
			'name_suffix'   => '' === (string) $a['label'] ? '' : sprintf( ' — %s', $a['label'] ),
			'price_suffix'  => ' ' . sprintf( '(%s)', $inline ),
			'period_suffix' => $period_suffix,
		];
	}

	/**
	 * StoreAPI cart extension payload — schedule sentences for every cart item
	 * whose price changes again beyond the next renewal. Single-step rules and
	 * flat-unlimited rules contribute nothing (same gate as the legacy totals
	 * row in render_schedule_rows). Display-only — never throws.
	 *
	 * @return array{schedule_sentences: array<int, array{key: string, item_name: string, sentence: string}>, schedule_label: string}
	 */
	public static function store_api_cart_data(): array {
		$sentences = [];
		if ( function_exists( 'WC' ) && WC() && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $key => $cart_item ) {
				if ( ! self::is_eligible_cart_item( $cart_item ) ) {
					continue;
				}
				if ( ! class_exists( '\WC_Subscriptions_Product' ) || ! \WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
					continue;
				}
				try {
					$segments = Schedule_Projector::project_for_cart_item( $cart_item );
				} catch ( \Throwable $e ) {
					continue;
				}
				if ( ! Schedule_Projector::has_undisclosed_changes( $segments ) ) {
					continue;
				}
				$sentences[] = [
					'key'       => (string) $key,
					'item_name' => (string) $cart_item['data']->get_name(),
					'sentence'  => self::schedule_sentence( $segments, $cart_item['data'] ),
				];
			}
		}
		return [
			'schedule_sentences' => $sentences,
			'schedule_label'     => __( 'Price schedule', 'newspack-plugin' ),
		];
	}

	/**
	 * StoreAPI cart extension schema.
	 */
	public static function store_api_cart_schema(): array {
		return [
			'schedule_sentences' => [
				'description' => __( 'Per-item schedule sentences for items whose price changes again beyond the next renewal.', 'newspack-plugin' ),
				'type'        => 'array',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
				'items'       => [
					'type'       => 'object',
					'properties' => [
						'key'       => [ 'type' => 'string' ],
						'item_name' => [ 'type' => 'string' ],
						'sentence'  => [ 'type' => 'string' ],
					],
				],
			],
			'schedule_label'     => [
				'description' => __( 'Translated label for the schedule row.', 'newspack-plugin' ),
				'type'        => 'string',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
		];
	}

	/**
	 * StoreAPI cart-item extension schema.
	 */
	public static function store_api_cart_item_schema(): array {
		return [
			'publicized'   => [
				'description' => __( 'Whether a publicized pricing rule is applied to this item.', 'newspack-plugin' ),
				'type'        => 'boolean',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'name_suffix'  => [
				'description' => __( 'Display-ready, translated suffix appended to the cart item name.', 'newspack-plugin' ),
				'type'        => 'string',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'price_suffix' => [
				'description' => __( 'Display-ready, translated suffix appended to the item price.', 'newspack-plugin' ),
				'type'        => 'string',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'period_suffix' => [
				'description' => __( 'WCS-rendered period suffix (e.g., " / month") to strip from the item price before appending price_suffix; empty when the charged price recurs.', 'newspack-plugin' ),
				'type'        => 'string',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
		];
	}

	/**
	 * Enqueue the WC Blocks Cart/Checkout filter JS. The bundle is built by
	 * newspack-plugin's webpack as `dist/other-scripts/dynamic-pricing-blocks-checkout.js`;
	 * absent build output no-ops gracefully.
	 */
	public static function enqueue_blocks_checkout_script(): void {
		if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
			return;
		}
		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}
		// No rules on the site → the filters would never have data to render.
		if ( ! CPT_Pricing_Rule_Repository::has_policies() ) {
			return;
		}
		if ( ! class_exists( '\Newspack\Newspack' ) || ! defined( 'NEWSPACK_ABSPATH' ) ) {
			return;
		}
		$asset_file = NEWSPACK_ABSPATH . 'dist/other-scripts/dynamic-pricing-blocks-checkout.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = include $asset_file;
		// Explicitly declare runtime script handles for the globals the bundle
		// consumes: `wc-blocks-checkout` (window.wc.blocksCheckout, for
		// registerCheckoutFilters + ExperimentalOrderMeta), `wp-plugins`
		// (window.wp.plugins.registerPlugin for the slot fill), and `wp-element`
		// (window.wp.element.createElement for the fill component). Merged with
		// anything webpack inferred via the assets manifest.
		$deps = array_unique( array_merge( [ 'wc-blocks-checkout', 'wp-plugins', 'wp-element' ], $asset['dependencies'] ?? [] ) );
		wp_enqueue_script(
			'newspack-dynamic-pricing-blocks-checkout',
			\Newspack\Newspack::plugin_url() . '/dist/other-scripts/dynamic-pricing-blocks-checkout.js',
			$deps,
			$asset['version'] ?? '1.0',
			true
		);
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
