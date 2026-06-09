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
		 * Append the policy label as a suffix to the cart item name. WCS's native
		 * sale UI handles the visual price strikethrough when `set_price()` differs
		 * from `regular_price`, so we don't prepend the original amount here.
		 */
		itemName: ( defaultValue, extensions ) => {
			const data = extensions && extensions[ NAMESPACE ];
			if ( ! data || ! data.publicized ) {
				return defaultValue;
			}
			return `${ defaultValue } — ${ data.label }`;
		},

		/**
		 * The format string we receive is something like `<price/> every month` (WCS
		 * recurring framing). That's misleading under stepped pricing where only
		 * cycle 1 charges this amount. Append a clarifier — the WC Blocks filter
		 * only requires `<price/>` to remain present; surrounding text is free.
		 */
		subtotalPriceFormat: ( defaultValue, extensions ) => {
			const data = extensions && extensions[ NAMESPACE ];
			if ( ! data || ! data.publicized ) {
				return defaultValue;
			}
			return `${ defaultValue } (this payment)`;
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
