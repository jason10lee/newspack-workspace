<?php
/**
 * Cart-time pricing surface for WooCommerce product purchases.
 *
 * Stateless: recomputes on every cart calculation, persists nothing. Hooks
 * `woocommerce_before_calculate_totals` and rewrites cart item prices in place
 * via `$cart_item['data']->set_price()` so WC totals are computed against the
 * policy-resolved amount.
 *
 * Acquisition contract: this surface prices ACQUISITIONS ONLY — cart items
 * that create a new subscription. Contexts carry
 * `Pricing_Context::INTENT_ACQUISITION` and resolve with
 * `signals['completed_cycles'] = 1` so a `Stepped_By_Cycle_Strategy` policy
 * grants its `step_at_1` price at checkout (cycle 1 = first invoice). After
 * `payment_complete` fires, the stateful `Subscription_Surface` owns all
 * subsequent repricing (cycles 2+).
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
	 * Request-scoped memo of publicized applies, keyed by cart item key.
	 * `array` = publicized payload; `false` = resolved with nothing to publicize
	 * (negative memo so display filters don't re-run the engine per callback).
	 * The cart filter callbacks read from this to render strikethrough + label.
	 *
	 * @var array<string, array{original: float, discounted: float, label: string, policy_id: string}|false>
	 */
	private static array $publicized_applies = [];

	/**
	 * Request-scoped registry of EVERY applied decision, keyed by cart item key —
	 * including silent (non-publicized) policies. Publicize is reader-facing;
	 * this registry feeds the operator-facing audit trail (order/subscription
	 * notes at checkout), which must record all policy interactions.
	 *
	 * @var array<string, array{policy_id: string, label: string, reason: string, amount: float, original: float, item_name: string, quantity: int}>
	 */
	private static array $applied_decisions = [];

	public function id(): string        { return 'woo_product'; }
	public function is_stateful(): bool { return false; }
	public function triggers(): array   { return [ self::TRIGGER_CART ]; }

	public static function init(): void {
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'on_calculate_totals' ], 20, 1 );

		// Reset the per-request registry at the start of each cart calc so stale entries
		// from a previous iteration don't leak into the new render.
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'reset_publicized_registry' ], 19, 1 );

		// Legacy reader-facing communication (shortcode-based cart/checkout).
		add_filter( 'woocommerce_cart_item_subtotal', [ __CLASS__, 'filter_cart_item_subtotal' ], 20, 3 );
		add_filter( 'woocommerce_cart_item_name', [ __CLASS__, 'filter_cart_item_name' ], 20, 3 );

		// Newspack Blocks Modal Checkout — its JS does textContent = price_summary so our
		// PHP-filtered HTML in the <strong> wrapper gets overwritten. Hook the modal's own
		// summary filter and inject plain-text policy info.
		add_filter( 'newspack_modal_checkout_price_summary', [ __CLASS__, 'filter_modal_price_summary' ], 20, 2 );

		// WC Blocks Checkout / StoreAPI extension — register publicize data on the
		// cart-item endpoint so the React-rendered cart can display it via JS filters
		// (see src/other-scripts/dynamic-pricing-blocks-checkout/).
		// Note: `woocommerce_blocks_loaded` fires before our `plugins_loaded` priority 20,
		// so a delayed add_action would never run. Call directly when the registrar is
		// available; otherwise defer via the action for older WC versions where it fires later.
		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			self::register_store_api_extension();
		} else {
			add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_store_api_extension' ] );
		}
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_blocks_checkout_script' ] );

		// Operator-facing audit: note the acquisition pricing on the order created
		// at checkout. Classic checkout and Store API (WC Blocks) checkout fire
		// different hooks; the handler is shared and idempotent.
		add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'note_acquisition_on_order' ], 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'note_acquisition_on_order' ], 20, 1 );
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
			} elseif ( isset( $cart_item['key'] ) && is_string( $cart_item['key'] ) ) {
				// Negative memo: display filters must not re-run the engine for
				// items the totals pass already resolved to "no decision".
				self::$publicized_applies[ $cart_item['key'] ] = false;
			}
		}
	}

	/**
	 * Single resolution path for a cart item — used by the totals pass AND the
	 * lazy display resolver, so the price applied and the price communicated come
	 * from the same logic.
	 *
	 * No-harm clamp: at acquisition, a policy may only ever lower the price the
	 * customer would otherwise pay. The decision computes off the catalog base
	 * (regular/WCS price), so on a product with an active sale price the result
	 * could exceed the effective price the customer was shown — in that case the
	 * policy abstains and WC's native pricing stands. Strictly-greater comparison:
	 * a decision equal to the current price still applies (re-application on
	 * subsequent calc passes must keep populating the publicize memo).
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

		$effective = (float) $cart_item['data']->get_price();
		if ( $effective > 0 && $d->amount > $effective + 0.005 ) {
			return null;
		}

		return [ $ctx, $d ];
	}

	/**
	 * Whether this surface may price a cart item. See the file header for the
	 * acquisition contract this enforces. Shared by the totals pass and every
	 * display path (via get_publicized_apply_for) so the price applied and the
	 * price communicated can never disagree on eligibility.
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

		return new Pricing_Context(
			$trigger,
			$product,
			$customer,
			$base_price,
			[ 'completed_cycles' => 1 ],
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

		if ( isset( $ctx->target['key'] ) && is_string( $ctx->target['key'] ) ) {
			$key = $ctx->target['key'];

			// Record the publicize outcome (payload or negative memo) so the cart
			// filters can render strikethrough + label without re-resolving.
			self::$publicized_applies[ $key ] = $d->publicize ? self::publicized_payload( $ctx, $d ) : false;

			// Record EVERY apply (silent ones included) for the audit trail.
			self::$applied_decisions[ $key ] = [
				'policy_id' => (string) ( $d->policy_id ?? '' ),
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
	 * The applied decision for a cart item, silent policies included.
	 * Audit-trail consumers (checkout note writers) read this.
	 *
	 * @internal Public for the subscriptions layer and tests.
	 */
	public static function get_applied_for( string $cart_item_key ): ?array {
		return self::$applied_decisions[ $cart_item_key ] ?? null;
	}

	/**
	 * The one construction site for the publicized payload — used by apply() and
	 * the lazy display resolver.
	 */
	private static function publicized_payload( Pricing_Context $ctx, Price_Decision $d ): array {
		return [
			'original'   => $ctx->base_price,
			'discounted' => $d->amount,
			'label'      => $d->label,
			'policy_id'  => (string) ( $d->policy_id ?? '' ),
		];
	}

	/** @internal — reset request-scoped registries at the start of each cart calc pass. */
	public static function reset_publicized_registry( $cart ): void {
		if ( $cart instanceof \WC_Cart ) {
			self::$publicized_applies = [];
			self::$applied_decisions  = [];
		}
	}

	/**
	 * Audit trail: when the order created at checkout contains policy-priced
	 * lines, record one order note per line stating which policy set which price
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
			/* translators: 1: policy id, 2: product name, 3: charged price, 4: catalog price, 5: policy label or reason */
			__( 'Newspack Dynamic Pricing [policy %1$s]: "%2$s" priced at %3$s — catalog price %4$s (%5$s).', 'newspack-plugin' ),
			$applied['policy_id'],
			$applied['item_name'],
			wc_price( (float) $applied['amount'] ),
			wc_price( (float) $applied['original'] ),
			$descriptor
		);
	}

	/**
	 * Returns the publicized apply payload for a cart item, lazy-resolving via the
	 * engine if the registry hasn't been populated yet.
	 *
	 * Why lazy: on some render paths (notably Newspack Blocks modal checkout's
	 * `render_before_checkout_form`), the cart item filters fire BEFORE
	 * `WC_Cart::calculate_totals()` runs in the same request. The eager population
	 * in `apply()` only happens under `woocommerce_before_calculate_totals`, so
	 * the registry is empty when the filter callback runs. We re-resolve the policy
	 * from the cart item directly, memoize in the registry, and the second filter
	 * call (`woocommerce_cart_item_name` after `woocommerce_cart_item_subtotal`)
	 * hits the cache.
	 *
	 * @internal — used by both tests and the cart filter callbacks.
	 */
	public static function get_publicized_apply_for( string $cart_item_key ): ?array {
		if ( array_key_exists( $cart_item_key, self::$publicized_applies ) ) {
			$memo = self::$publicized_applies[ $cart_item_key ];
			return is_array( $memo ) ? $memo : null;
		}

		// Cart not booted yet — don't memoize, a later callback may succeed.
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return null;
		}

		$cart_item = WC()->cart->get_cart_item( $cart_item_key );
		if ( ! is_array( $cart_item ) || ! self::is_eligible_cart_item( $cart_item ) ) {
			self::$publicized_applies[ $cart_item_key ] = false;
			return null;
		}

		$resolved = self::resolve_for_cart_item( $cart_item );
		if ( ! $resolved || ! $resolved[1]->publicize ) {
			self::$publicized_applies[ $cart_item_key ] = false;
			return null;
		}

		self::$publicized_applies[ $cart_item_key ] = self::publicized_payload( $resolved[0], $resolved[1] );
		return self::$publicized_applies[ $cart_item_key ];
	}

	/**
	 * Filter: when a publicized policy applies, prepend the original price
	 * (strikethrough) and append a disclaimer noting the price is for THIS payment.
	 *
	 * The disclaimer is deliberately vague about future cycles — stepped pricing,
	 * percent discounts, and overlapping policies make any "renews at $X" claim
	 * fragile, especially under priority_exclusive / min() composition. We don't
	 * try to forecast the next cycle here; we just signal that the recurring
	 * framing from WCS doesn't reflect future cycles.
	 *
	 * @param string $subtotal       Already-formatted subtotal (includes WCS "/month").
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

		$disclaimer = esc_html__( 'Applies to this payment. Renewal price may differ.', 'newspack-plugin' );

		// wc_format_sale_price produces WC's standard <del>/<ins> sale markup
		// (with screen-reader text) that all WC themes style.
		return sprintf(
			'%s<br><small style="display:block;color:#666;font-weight:normal;margin-top:2px">%s</small>',
			wc_format_sale_price( wc_price( $original_line ), $subtotal ),
			$disclaimer
		);
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

	/**
	 * Filter: Newspack Blocks Modal Checkout price summary. Output is plain text
	 * (JS does textContent = price_summary), so we can't inject HTML; we append
	 * "(Label — was $original)" inline.
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
			$item_pid = (int) ( $cart_item['variation_id'] ?: $cart_item['product_id'] );
			if ( $item_pid !== $pid ) {
				continue;
			}
			$applied = self::get_publicized_apply_for( (string) $cart_item_key );
			if ( ! $applied ) {
				return $summary;
			}
			$original_plain = wp_strip_all_tags( html_entity_decode( wc_price( (float) $applied['original'] ), ENT_QUOTES ) );
			return sprintf(
				/* translators: 1: WCS-formatted summary, 2: policy label, 3: original price (plain text) */
				__( '%1$s (%2$s — was %3$s)', 'newspack-plugin' ),
				$summary,
				$applied['label'],
				$original_plain
			);
		}
		return $summary;
	}

	/**
	 * StoreAPI extension: attach publicize data to each cart item so WC Blocks
	 * Checkout can read it via `extensions['newspack-dynamic-pricing']`. The JS
	 * side registers checkout filters that consume this data — see
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
	}

	/**
	 * StoreAPI cart-item extension data payload.
	 */
	public static function store_api_cart_item_data( array $cart_item ): array {
		$key     = isset( $cart_item['key'] ) && is_string( $cart_item['key'] ) ? $cart_item['key'] : '';
		$applied = '' === $key ? null : self::get_publicized_apply_for( $key );
		if ( ! $applied ) {
			return [ 'publicized' => false ];
		}
		return [
			'publicized'         => true,
			'original'           => (float) $applied['original'],
			'discounted'         => (float) $applied['discounted'],
			'label'              => (string) $applied['label'],
			'original_formatted' => wp_strip_all_tags( html_entity_decode( wc_price( (float) $applied['original'] ), ENT_QUOTES ) ),
			// Display-ready, server-translated strings — the JS filters append these
			// verbatim instead of composing English client-side.
			'name_suffix'        => '' === $applied['label'] ? '' : sprintf( ' — %s', $applied['label'] ),
			'price_suffix'       => ' ' . __( '(this payment)', 'newspack-plugin' ),
		];
	}

	/**
	 * StoreAPI cart-item extension schema.
	 */
	public static function store_api_cart_item_schema(): array {
		return [
			'publicized'         => [
				'description' => __( 'Whether a publicized pricing policy is applied to this item.', 'newspack-plugin' ),
				'type'        => 'boolean',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'original'           => [
				'description' => __( 'Pre-policy (catalog) recurring price.', 'newspack-plugin' ),
				'type'        => 'number',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'discounted'         => [
				'description' => __( 'Policy-resolved amount.', 'newspack-plugin' ),
				'type'        => 'number',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'label'              => [
				'description' => __( 'Human-readable policy label.', 'newspack-plugin' ),
				'type'        => 'string',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'original_formatted' => [
				'description' => __( 'Original price as plain-text-formatted currency.', 'newspack-plugin' ),
				'type'        => 'string',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'name_suffix'        => [
				'description' => __( 'Display-ready suffix appended to the cart item name.', 'newspack-plugin' ),
				'type'        => 'string',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'price_suffix'       => [
				'description' => __( 'Display-ready, translated suffix appended to the price format.', 'newspack-plugin' ),
				'type'        => 'string',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
		];
	}

	/**
	 * Enqueue the WC Blocks Checkout filter JS on cart/checkout pages. The bundle is
	 * built by newspack-plugin's webpack as `dist/other-scripts/dynamic-pricing-blocks-checkout.js`.
	 */
	public static function enqueue_blocks_checkout_script(): void {
		if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
			return;
		}
		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}
		// No policies on the site → the filters would never have data to render.
		if ( ! CPT_Policy_Repository::has_policies() ) {
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
		// Explicitly declare wc-blocks-checkout so WC's dependency detection doesn't warn;
		// merge with whatever webpack inferred.
		$deps = array_unique( array_merge( [ 'wc-blocks-checkout' ], $asset['dependencies'] ?? [] ) );
		wp_enqueue_script(
			'newspack-dynamic-pricing-blocks-checkout',
			\Newspack\Newspack::plugin_url() . '/dist/other-scripts/dynamic-pricing-blocks-checkout.js',
			$deps,
			$asset['version'] ?? '1.0',
			true
		);
	}
}
