/**
 * WC Blocks Cart & Checkout filters for dynamic pricing annotations.
 *
 * Appends the server-composed, server-translated suffixes from the
 * `newspack-dynamic-pricing` StoreAPI cart-item extension (see
 * WooProduct_Surface::store_api_cart_item_data) to the item name and price.
 * All copy lives in PHP; this file only concatenates.
 *
 * `@woocommerce/blocks-checkout` is not an npm dependency — it is provided at
 * runtime by the `wc-blocks-checkout` script handle (declared as a dependency
 * in WooProduct_Surface::enqueue_blocks_checkout_script), exposed on
 * `window.wc.blocksCheckout`.
 */

const NAMESPACE = 'newspack-dynamic-pricing';

const { registerCheckoutFilters } = window.wc?.blocksCheckout || {};

const getAnnotation = extensions => {
	const data = extensions?.[ NAMESPACE ];
	return data?.publicized ? data : null;
};

if ( registerCheckoutFilters ) {
	registerCheckoutFilters( NAMESPACE, {
		itemName: ( value, extensions ) => {
			const annotation = getAnnotation( extensions );
			return annotation?.name_suffix ? value + annotation.name_suffix : value;
		},
		cartItemPrice: ( value, extensions ) => {
			const annotation = getAnnotation( extensions );
			return annotation?.price_suffix ? value + annotation.price_suffix : value;
		},
	} );
}
