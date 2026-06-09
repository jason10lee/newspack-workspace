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
		if ( isset( self::$publicized_applies[ $cart_item_key ] ) ) {
			return self::$publicized_applies[ $cart_item_key ];
		}

		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return null;
		}
		$cart_item = WC()->cart->get_cart_item( $cart_item_key );
		if ( ! is_array( $cart_item ) || ! isset( $cart_item['data'] ) || ! ( $cart_item['data'] instanceof \WC_Product ) ) {
			return null;
		}

		$surface = Pricing_Engine::instance()->surface( 'woo_product' );
		if ( ! $surface instanceof self ) {
			return null;
		}
		$ctx = $surface->context( $cart_item, self::TRIGGER_CART );
		$d   = Pricing_Engine::instance()->resolve( $ctx );
		if ( ! $d || ! $d->publicize ) {
			return null;
		}

		self::$publicized_applies[ $cart_item_key ] = [
			'original'   => $ctx->base_price,
			'discounted' => $d->amount,
			'label'      => $d->label,
			'policy_id'  => (string) ( $d->policy_id ?? '' ),
		];
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

		return sprintf(
			'<del style="opacity: 0.6">%s</del> %s<br><small style="display:block;color:#666;font-weight:normal;margin-top:2px">%s</small>',
			wc_price( $original_line ),
			$subtotal,
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
