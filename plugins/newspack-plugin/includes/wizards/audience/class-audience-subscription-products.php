<?php
/**
 * Audience Subscription Products Wizard.
 *
 * Exploratory DataViews management page that lists Woo Subscriptions products with
 * a productized, consolidated model (price + period, active subscriber counts,
 * category, status) plus the RSM Layer 2 policy stack + effective price.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Audience Subscription Products Wizard.
 */
class Audience_Subscription_Products extends Wizard {
	/**
	 * Admin page slug. Must match the React page map key in src/wizards/index.tsx
	 * and the container div id rendered by Wizard::render_wizard().
	 *
	 * @var string
	 */
	protected $slug = 'newspack-audience-subscription-products';

	/**
	 * Parent slug.
	 *
	 * @var string
	 */
	protected $parent_slug = 'newspack-audience';

	/**
	 * Subscription product types we surface.
	 */
	const PRODUCT_TYPES = [ 'subscription', 'variable-subscription' ];

	/**
	 * Subscription statuses counted as "active" subscribers.
	 *
	 * Mirrors the active statuses used by the WooCommerce connection
	 * ({@see Newspack\WooCommerce_Connection}).
	 */
	const ACTIVE_SUBSCRIPTION_STATUSES = [ 'active', 'pending-cancel' ];

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		add_action( 'rest_api_init', [ $this, 'register_api_endpoints' ] );
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html__( 'Audience Management / Subscription Products', 'newspack-plugin' );
	}

	/**
	 * Register the endpoints needed for the wizard screens.
	 */
	public function register_api_endpoints() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/products',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_products' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
	}

	/**
	 * GET the list of subscription products in the consolidated model.
	 *
	 * @return \WP_REST_Response The response object.
	 */
	public function api_get_products() {
		$response = [
			'products'              => [],
			'currency'              => self::get_currency(),
			'policy_source_is_mock' => Subscription_Policy_Resolver::IS_MOCK,
		];

		if ( ! function_exists( 'wc_get_products' ) ) {
			return rest_ensure_response( $response );
		}

		$products = \wc_get_products(
			[
				'type'   => self::PRODUCT_TYPES,
				'status' => [ 'publish', 'private', 'draft', 'pending' ],
				'limit'  => -1,
			]
		);

		$response['products'] = array_map( [ $this, 'prepare_product' ], $products );
		return rest_ensure_response( $response );
	}

	/**
	 * Build the consolidated, productized row for a single subscription product.
	 *
	 * @param \WC_Product $product The product.
	 *
	 * @return array The row.
	 */
	public function prepare_product( $product ) {
		$type          = $product->get_type();
		$categories    = self::get_categories( $product->get_id() );
		$pricing       = self::get_pricing( $product );
		$currency_code = self::get_currency()['code'];

		// Layer 2: resolve the policy stack + effective price through the integration seam.
		// Variable subscriptions resolve PER VARIATION — each plan (monthly/annual/…) can
		// carry a different policy and effective price. The row-level policy reflects the
		// representative (lowest-price) variation; the full per-variation breakdown rides
		// on each variation and is surfaced in the edit modal.
		if ( $product->is_type( 'variable-subscription' ) ) {
			foreach ( $pricing['variations'] as $index => $variation ) {
				$pricing['variations'][ $index ]['policy'] = Subscription_Policy_Resolver::resolve(
					$variation['id'],
					[
						'base_price' => $variation['base_price'],
						'cycle'      => $variation['period'],
						'currency'   => $currency_code,
					]
				);
			}
			$policy = self::representative_variation_policy( $pricing['variations'], $pricing['base_price'], $currency_code );
		} else {
			$policy = Subscription_Policy_Resolver::resolve(
				$product->get_id(),
				[
					'base_price' => $pricing['base_price'],
					'cycle'      => $pricing['period'],
					'currency'   => $currency_code,
				]
			);
		}

		$availability = self::derive_availability( $pricing['base_price'], $categories );
		$gate_map     = self::get_product_gate_map();
		$unlocks      = isset( $gate_map[ $product->get_id() ] ) ? $gate_map[ $product->get_id() ] : [];

		return [
			'id'                   => $product->get_id(),
			'name'                 => $product->get_name(),
			'type'                 => $type,
			'type_label'           => self::get_type_label( $type ),
			// Canonical donation flag (the "designate as donation" product checkbox →
			// _newspack_is_donation meta, plus variation inheritance and legacy products).
			'is_donation'          => class_exists( 'Newspack\Donations' ) ? Donations::is_donation_product( $product->get_id() ) : false,
			// How the plan is offered/distributed (NOT content "access control" — see below).
			'availability'         => $availability,
			'availability_label'   => self::get_availability_label( $availability ),
			// Reverse lookup: the content-access gates this product unlocks (Access control feature).
			'unlocks'              => $unlocks,
			'unlocks_label'        => implode( ', ', wp_list_pluck( $unlocks, 'title' ) ),
			'status'               => $product->get_status(),
			'status_label'         => self::get_status_label( $product->get_status() ),
			'base_price'           => $pricing['base_price'],
			'price_label'          => $pricing['price_label'],
			'price_range_label'    => $pricing['price_range_label'],
			'period'               => $pricing['period'],
			'interval'             => $pricing['interval'],
			'variations'           => $pricing['variations'],
			'categories'           => $categories,
			'category_ids'         => wp_list_pluck( $categories, 'id' ),
			'category_label'       => implode( ', ', wp_list_pluck( $categories, 'name' ) ),
			'active_subscriptions' => self::get_active_subscription_count( $product ),
			'edit_url'             => html_entity_decode( (string) get_edit_post_link( $product->get_id(), 'raw' ) ),
			'policy'               => $policy,
		];
	}

	/**
	 * Get pricing details for a product, normalizing simple vs. variable subscriptions.
	 *
	 * For variable subscriptions, base_price is the lowest variation price (representative
	 * for sorting) and price_range_label spans the variation range.
	 *
	 * @param \WC_Product $product The product.
	 *
	 * @return array Pricing details.
	 */
	private static function get_pricing( $product ) {
		$pricing = [
			'base_price'        => null,
			'price_label'       => '',
			'price_range_label' => '',
			'period'            => '',
			'interval'          => 1,
			'variations'        => [],
		];

		if ( $product->is_type( 'variable-subscription' ) ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}
				$v_price    = self::read_subscription_price( $variation );
				$v_period   = $variation->get_meta( '_subscription_period' );
				$v_interval = (int) $variation->get_meta( '_subscription_period_interval' );

				$pricing['variations'][] = [
					'id'          => $variation_id,
					'name'        => $variation->get_name(),
					'base_price'  => $v_price,
					'period'      => $v_period,
					'interval'    => $v_interval,
					'price_label' => self::format_price_label( $v_price, $v_period, $v_interval ),
				];
			}

			// Build the range from the actual lowest- and highest-priced variations, each
			// labeled with its OWN billing period (a tier can span $12/month – $100/year).
			$priced = array_values(
				array_filter(
					$pricing['variations'],
					function( $variation ) {
						return null !== $variation['base_price'];
					}
				)
			);
			if ( ! empty( $priced ) ) {
				usort(
					$priced,
					function( $a, $b ) {
						return $a['base_price'] <=> $b['base_price'];
					}
				);
				$low  = $priced[0];
				$high = $priced[ count( $priced ) - 1 ];

				$pricing['base_price']  = $low['base_price'];
				$pricing['period']      = $low['period'];
				$pricing['interval']    = $low['interval'];
				$pricing['price_label'] = $low['price_label'];
				$pricing['price_range_label'] = $low['base_price'] === $high['base_price']
					? $low['price_label']
					: sprintf(
						/* translators: 1: lowest plan price label, 2: highest plan price label. */
						__( '%1$s – %2$s', 'newspack-plugin' ),
						$low['price_label'],
						$high['price_label']
					);
			}

			return $pricing;
		}

		// Simple subscription.
		$price    = self::read_subscription_price( $product );
		$period   = $product->get_meta( '_subscription_period' );
		$interval = (int) $product->get_meta( '_subscription_period_interval' );

		$pricing['base_price']  = $price;
		$pricing['period']      = $period;
		$pricing['interval']    = $interval ? $interval : 1;
		$pricing['price_label'] = self::format_price_label( $price, $period, $interval );

		return $pricing;
	}

	/**
	 * Pick the representative policy for a variable subscription row.
	 *
	 * The row in the table shows the entry (lowest-price) plan, so the row-level policy
	 * mirrors that variation. Falls back to the first variation, then to an empty
	 * resolution, so a variable product with no priced variations still renders cleanly.
	 *
	 * @param array      $variations    Variations, each already carrying a resolved 'policy'.
	 * @param float|null $base_price    The representative (lowest) base price.
	 * @param string     $currency_code The store currency code.
	 *
	 * @return array A policy resolution payload.
	 */
	private static function representative_variation_policy( $variations, $base_price, $currency_code ) {
		foreach ( $variations as $variation ) {
			if ( isset( $variation['policy'], $variation['base_price'] ) && $variation['base_price'] === $base_price ) {
				return $variation['policy'];
			}
		}
		if ( isset( $variations[0]['policy'] ) ) {
			return $variations[0]['policy'];
		}
		// No priced variations — return an empty (no-policy) resolution for the base price.
		return Subscription_Policy_Resolver::resolve(
			0,
			[
				'base_price' => $base_price,
				'cycle'      => '',
				'currency'   => $currency_code,
			]
		);
	}

	/**
	 * Read a product's base subscription price.
	 *
	 * Distinguishes "not set" (null) from an explicit 0 so the UI can render the
	 * difference faithfully.
	 *
	 * @param \WC_Product $product The product or variation.
	 *
	 * @return float|null The price, or null when not set.
	 */
	private static function read_subscription_price( $product ) {
		$raw = $product->get_meta( '_subscription_price' );
		if ( ! isset( $raw ) || '' === $raw ) {
			return null;
		}
		return (float) $raw;
	}

	/**
	 * Count active subscriptions for a product.
	 *
	 * Returns null (not zero) when WooCommerce Subscriptions is unavailable, so the UI
	 * can distinguish "unknown" from a genuine zero. For variable subscriptions, counts
	 * distinct subscriptions across the parent and all variation IDs.
	 *
	 * @param \WC_Product $product The product.
	 *
	 * @return int|null The active subscription count, or null when unavailable.
	 */
	private static function get_active_subscription_count( $product ) {
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			return null;
		}

		$product_ids = [ $product->get_id() ];
		if ( $product->is_type( 'variable-subscription' ) ) {
			$product_ids = array_merge( $product_ids, $product->get_children() );
		}

		$subscription_ids = [];
		foreach ( $product_ids as $product_id ) {
			$subscriptions = \wcs_get_subscriptions(
				[
					'product_id'             => $product_id,
					'subscription_status'    => self::ACTIVE_SUBSCRIPTION_STATUSES,
					'subscriptions_per_page' => -1,
				]
			);
			// wcs_get_subscriptions() is keyed by subscription id — dedupe across variations.
			foreach ( array_keys( $subscriptions ) as $subscription_id ) {
				$subscription_ids[ $subscription_id ] = true;
			}
		}

		return count( $subscription_ids );
	}

	/**
	 * Get product categories.
	 *
	 * @param int $product_id The product ID.
	 *
	 * @return array List of { id, name, slug }.
	 */
	private static function get_categories( $product_id ) {
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return [];
		}
		return array_map(
			function( $term ) {
				return [
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				];
			},
			$terms
		);
	}

	/**
	 * Build a human price label, e.g. "$10 / month" or "$20 / 2 months".
	 *
	 * @param float|null $price    The price.
	 * @param string     $period   The billing period slug.
	 * @param int        $interval The billing interval.
	 *
	 * @return string The label, or '' when price is not set.
	 */
	private static function format_price_label( $price, $period, $interval ) {
		if ( null === $price ) {
			return '';
		}

		$amount = self::format_amount( $price );

		if ( '' === $period ) {
			return $amount;
		}

		$interval     = $interval ? (int) $interval : 1;
		$period_label = function_exists( 'wcs_get_subscription_period_strings' )
			? wcs_get_subscription_period_strings( $interval, $period )
			: ( $interval > 1 ? $interval . ' ' . $period . 's' : $period );

		return sprintf(
			/* translators: 1: price amount, 2: billing period, e.g. "$10 / month". */
			__( '%1$s / %2$s', 'newspack-plugin' ),
			$amount,
			$period_label
		);
	}

	/**
	 * Format a bare currency amount using the store's currency symbol and decimals.
	 *
	 * @param float $price The price.
	 *
	 * @return string The formatted amount.
	 */
	private static function format_amount( $price ) {
		$currency = self::get_currency();
		$amount   = number_format_i18n( (float) $price, $currency['decimals'] );
		return $currency['symbol'] . $amount;
	}

	/**
	 * Get store currency details for the front end.
	 *
	 * @return array { code, symbol, decimals }.
	 */
	private static function get_currency() {
		return [
			'code'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'symbol'   => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '$',
			'decimals' => function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2,
		];
	}

	/**
	 * Derive an availability tier (public / private / free) for a product.
	 *
	 * NOTE ON NAMING: this is "availability" — how the plan is offered/distributed — and is
	 * deliberately NOT called "access". "Access control" is the separate Newspack
	 * content-gating feature (the sibling Audience page); the gates a product unlocks are
	 * surfaced separately as the "unlocks" field. Keeping the words distinct avoids
	 * conflating "how the plan is sold" with "what content it grants".
	 *
	 * DERIVATION (placeholder for a first-class entitlement attribute). Publishers
	 * encode this via product structure today — e.g. Lookout and Richland Source both use a
	 * "Private subscriptions" / "Free subscriptions" product_cat. This normalizes those
	 * conventions plus zero-price into one facet:
	 *   - free    : base price is 0, OR a category name contains "free".
	 *   - private : a category name contains "private" (the explicit publisher convention).
	 *   - public  : everything else (a normally purchasable paid subscription).
	 *
	 * NOTE: we deliberately do NOT infer "private" from catalog_visibility=hidden —
	 * Newspack hides donation/RAS products from the catalog for unrelated reasons, so that
	 * signal is too noisy. This is the signal publishers explicitly reach for; the real
	 * RSM/entitlement layer should own it as a typed field rather than inferring it.
	 *
	 * @param float|null $base_price The representative base price.
	 * @param array      $categories Category terms ({ id, name, slug }).
	 *
	 * @return string One of 'public', 'private', 'free'.
	 */
	private static function derive_availability( $base_price, $categories ) {
		$category_names = strtolower( implode( ' ', wp_list_pluck( $categories, 'name' ) ) );

		if ( ( null !== $base_price && 0.0 === (float) $base_price ) || false !== strpos( $category_names, 'free' ) ) {
			return 'free';
		}

		if ( false !== strpos( $category_names, 'private' ) ) {
			return 'private';
		}

		return 'public';
	}

	/**
	 * Human label for an availability tier.
	 *
	 * @param string $availability The availability tier.
	 *
	 * @return string The label.
	 */
	private static function get_availability_label( $availability ) {
		$labels = [
			'public'  => __( 'Public', 'newspack-plugin' ),
			'private' => __( 'Private', 'newspack-plugin' ),
			'free'    => __( 'Free', 'newspack-plugin' ),
		];
		return isset( $labels[ $availability ] ) ? $labels[ $availability ] : ucfirst( $availability );
	}

	/**
	 * Cached product → content-gates reverse map.
	 *
	 * @var array<int, array>|null
	 */
	private static $product_gate_map = null;

	/**
	 * Build a reverse map of product ID → content gates that require it.
	 *
	 * Content gates (the "Access control" feature) store their rules in the gate's
	 * `custom_access` meta as a grouped `access_rules` structure. The `subscription` rule's
	 * value is a list of (parent) product IDs the reader must be subscribed to. This walks
	 * every published gate and inverts that relationship so each product row can show what
	 * it unlocks. Built once per request and cached.
	 *
	 * @return array<int, array> Map of product ID → list of { id, title } gate entries.
	 */
	private static function get_product_gate_map() {
		if ( null !== self::$product_gate_map ) {
			return self::$product_gate_map;
		}

		$map = [];
		if ( ! class_exists( 'Newspack\Content_Gate' ) ) {
			self::$product_gate_map = $map;
			return $map;
		}

		$gates = get_posts(
			[
				'post_type'      => Content_Gate::get_gate_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			]
		);

		foreach ( $gates as $gate ) {
			$settings = Content_Gate::get_custom_access_settings( $gate->ID );
			if ( empty( $settings['access_rules'] ) || ! is_array( $settings['access_rules'] ) ) {
				continue;
			}
			foreach ( $settings['access_rules'] as $group ) {
				if ( ! is_array( $group ) ) {
					continue;
				}
				foreach ( $group as $rule ) {
					if ( ! isset( $rule['slug'] ) || 'subscription' !== $rule['slug'] || empty( $rule['value'] ) ) {
						continue;
					}
					$product_ids = is_array( $rule['value'] ) ? $rule['value'] : [ $rule['value'] ];
					foreach ( $product_ids as $product_id ) {
						$product_id = (int) $product_id;
						if ( ! isset( $map[ $product_id ] ) ) {
							$map[ $product_id ] = [];
						}
						// Keyed by gate ID to dedupe across groups/rules.
						$map[ $product_id ][ $gate->ID ] = [
							'id'    => $gate->ID,
							'title' => get_the_title( $gate->ID ),
						];
					}
				}
			}
		}

		// Reindex inner maps to plain lists.
		foreach ( $map as $product_id => $product_gates ) {
			$map[ $product_id ] = array_values( $product_gates );
		}

		self::$product_gate_map = $map;
		return $map;
	}

	/**
	 * Human label for a subscription product type.
	 *
	 * @param string $type The product type.
	 *
	 * @return string The label.
	 */
	private static function get_type_label( $type ) {
		$labels = [
			'subscription'          => __( 'Simple subscription', 'newspack-plugin' ),
			'variable-subscription' => __( 'Variable subscription', 'newspack-plugin' ),
		];
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * Human label for a product status.
	 *
	 * @param string $status The post status.
	 *
	 * @return string The label.
	 */
	private static function get_status_label( $status ) {
		$object = get_post_status_object( $status );
		return $object ? $object->label : ucfirst( $status );
	}

	/**
	 * Add the Subscription Products page.
	 */
	public function add_page() {
		add_submenu_page(
			$this->parent_slug,
			$this->get_name(),
			esc_html__( 'Subscription products', 'newspack-plugin' ),
			$this->capability,
			$this->slug,
			[ $this, 'render_wizard' ]
		);
	}

	/**
	 * Enqueue scripts and styles.
	 */
	public function enqueue_scripts_and_styles() {
		if ( ! $this->is_wizard_page() ) {
			return;
		}

		parent::enqueue_scripts_and_styles();
		wp_enqueue_script( 'newspack-wizards' );
		wp_localize_script(
			'newspack-wizards',
			'newspackAudienceSubscriptionProducts',
			[
				'new_product_url'                  => admin_url( 'post-new.php?post_type=product' ),
				'manage_products_url'              => admin_url( 'edit.php?post_type=product' ),
				'policy_source_is_mock'            => Subscription_Policy_Resolver::IS_MOCK,
				'woocommerce_subscriptions_active' => function_exists( 'wcs_get_subscriptions' ),
			]
		);
	}
}
