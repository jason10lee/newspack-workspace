/**
 * WC Blocks Cart & Checkout filters for dynamic pricing annotations.
 *
 * Appends the server-composed, server-translated suffixes from the
 * `newspack-dynamic-pricing` StoreAPI cart-item extension (see
 * WooProduct_Surface::store_api_cart_item_data) to the item name and price.
 * All copy lives in PHP; this file only concatenates.
 */

/**
 * External dependencies
 */
import { registerCheckoutFilters } from '@woocommerce/blocks-checkout';

const NAMESPACE = 'newspack-dynamic-pricing';

const getAnnotation = extensions => {
	const data = extensions?.[ NAMESPACE ];
	return data?.publicized ? data : null;
};

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
