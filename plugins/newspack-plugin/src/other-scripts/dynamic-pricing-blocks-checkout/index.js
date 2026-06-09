/**
 * WC Blocks Checkout — registers `newspack-dynamic-pricing` namespace filters that
 * read the cart-item StoreAPI extension and surface the policy publicize state in
 * the React-rendered cart/checkout.
 *
 * Server-side: see WooProduct_Surface::register_store_api_extension() for the data
 * payload shape ({publicized, original, discounted, label, original_formatted}).
 *
 * @package Newspack
 */

const NAMESPACE = 'newspack-dynamic-pricing';

const log = ( ...args ) => {
	if ( typeof window !== 'undefined' && window.console ) {
		window.console.log( '[newspack-dynamic-pricing]', ...args );
	}
};

const registerFilters = () => {
	const blocksCheckout = window.wc && window.wc.blocksCheckout;
	if ( ! blocksCheckout || typeof blocksCheckout.registerCheckoutFilters !== 'function' ) {
		return false;
	}

	log( 'registering checkout filters' );

	blocksCheckout.registerCheckoutFilters( NAMESPACE, {
		/**
		 * Append the policy label to the cart item name.
		 */
		itemName: ( defaultValue, extensions ) => {
			const data = extensions && extensions[ NAMESPACE ];
			log( 'itemName fired', { defaultValue, hasData: !! data, publicized: !! ( data && data.publicized ) } );
			if ( ! data || ! data.publicized ) {
				return defaultValue;
			}
			return `${ defaultValue } — ${ data.label }`;
		},

		/**
		 * Subtotal price format used by the Checkout block summary. WC Blocks REQUIRES
		 * the `<price/>` placeholder to remain in the string — without it, WC silently
		 * falls back to the default format.
		 */
		subtotalPriceFormat: ( defaultValue, extensions ) => {
			const data = extensions && extensions[ NAMESPACE ];
			log( 'subtotalPriceFormat fired', { defaultValue, hasData: !! data, publicized: !! ( data && data.publicized ) } );
			if ( ! data || ! data.publicized || ! data.original_formatted ) {
				return defaultValue;
			}
			const result = `${ data.original_formatted } → ${ defaultValue }`;
			log( 'subtotalPriceFormat returning', result );
			return result;
		},

		/**
		 * Cart block uses cartItemPrice (singular line-item price), not subtotalPriceFormat.
		 * Same validation rule: must preserve `<price/>`.
		 */
		cartItemPrice: ( defaultValue, extensions ) => {
			const data = extensions && extensions[ NAMESPACE ];
			log( 'cartItemPrice fired', { defaultValue, hasData: !! data, publicized: !! ( data && data.publicized ) } );
			if ( ! data || ! data.publicized || ! data.original_formatted ) {
				return defaultValue;
			}
			return `${ data.original_formatted } → ${ defaultValue }`;
		},
	} );

	log( 'filters registered for namespace', NAMESPACE );
	return true;
};

if ( ! registerFilters() ) {
	const onReady = () => {
		if ( registerFilters() ) {
			return;
		}
		let tries = 0;
		const interval = setInterval( () => {
			tries++;
			if ( registerFilters() || tries > 40 ) {
				clearInterval( interval );
			}
		}, 50 );
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', onReady );
	} else {
		onReady();
	}
}
