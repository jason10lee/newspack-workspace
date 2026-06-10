/**
 * WC Blocks Checkout — registers `newspack-dynamic-pricing` namespace filters that
 * read the cart-item StoreAPI extension and surface the policy publicize state in
 * the React-rendered cart/checkout.
 *
 * Server-side: see WooProduct_Surface::store_api_cart_item_data() for the payload
 * shape. All reader-facing copy (`name_suffix`, `price_suffix`) is composed and
 * translated server-side — this file only appends the provided strings.
 *
 * The script is enqueued with an explicit `wc-blocks-checkout` dependency, so
 * `window.wc.blocksCheckout` is guaranteed to exist when this module executes.
 *
 * @package Newspack
 */

const NAMESPACE = 'newspack-dynamic-pricing';

const { registerCheckoutFilters } = window.wc?.blocksCheckout || {};

if ( typeof registerCheckoutFilters === 'function' ) {
	registerCheckoutFilters( NAMESPACE, {
		/**
		 * Append the policy label to the cart item name. WCS's native sale UI
		 * handles the visual price strikethrough when `set_price()` differs from
		 * `regular_price`, so we don't prepend the original amount here.
		 */
		itemName: ( defaultValue, extensions ) => {
			const data = extensions?.[ NAMESPACE ];
			if ( ! data?.publicized || ! data.name_suffix ) {
				return defaultValue;
			}
			return `${ defaultValue }${ data.name_suffix }`;
		},

		/**
		 * The format string we receive is something like `<price/> every month`
		 * (WCS recurring framing). That's misleading under stepped pricing where
		 * only cycle 1 charges this amount — append the server-translated
		 * clarifier. The WC Blocks filter only requires `<price/>` to remain
		 * present; surrounding text is free.
		 */
		subtotalPriceFormat: ( defaultValue, extensions ) => {
			const data = extensions?.[ NAMESPACE ];
			if ( ! data?.publicized || ! data.price_suffix ) {
				return defaultValue;
			}
			return `${ defaultValue }${ data.price_suffix }`;
		},
	} );
}
