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
		 * Append the policy label as a suffix to the cart item name. We deliberately
		 * do NOT modify `subtotalPriceFormat` / `cartItemPrice` — when `set_price()`
		 * differs from `regular_price`, WCS already renders its native sale UI
		 * (`<del>original</del> new + "Save $X / month"` tag), and prepending the
		 * original a second time is redundant noise. The label suffix here is the
		 * only signal we add in Blocks contexts.
		 *
		 * Legacy contexts (cart/checkout shortcodes, Newspack-Blocks modal) keep
		 * their PHP-rendered strikethrough + disclaimer via `woocommerce_cart_item_*`
		 * filters in WooProduct_Surface — those paths don't get the WCS native UI.
		 */
		itemName: ( defaultValue, extensions ) => {
			const data = extensions && extensions[ NAMESPACE ];
			if ( ! data || ! data.publicized ) {
				return defaultValue;
			}
			return `${ defaultValue } — ${ data.label }`;
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
