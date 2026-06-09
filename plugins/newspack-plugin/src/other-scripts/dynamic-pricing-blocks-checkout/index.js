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

const registerFilters = () => {
	const blocksCheckout = window.wc && window.wc.blocksCheckout;
	if ( ! blocksCheckout || typeof blocksCheckout.registerCheckoutFilters !== 'function' ) {
		return false;
	}

	blocksCheckout.registerCheckoutFilters( NAMESPACE, {
		/**
		 * Append the policy label to the cart item name.
		 *
		 * @param {string} defaultValue Existing item name.
		 * @param {Object} extensions   StoreAPI extension payload keyed by namespace.
		 */
		itemName: ( defaultValue, extensions ) => {
			const data = extensions && extensions[ NAMESPACE ];
			if ( ! data || ! data.publicized ) {
				return defaultValue;
			}
			return `${ defaultValue } — ${ data.label }`;
		},

		/**
		 * Reformat the subtotal price to include the original (struck-through-ish)
		 * before the resolved amount.
		 *
		 * WC Blocks subtotal format accepts a string with `<price/>` placeholder.
		 * The placeholder is replaced with the actual formatted price by WC.
		 *
		 * @param {string} defaultValue The default format string (typically `<price/>`).
		 * @param {Object} extensions   StoreAPI extension payload keyed by namespace.
		 */
		subtotalPriceFormat: ( defaultValue, extensions ) => {
			const data = extensions && extensions[ NAMESPACE ];
			if ( ! data || ! data.publicized || ! data.original_formatted ) {
				return defaultValue;
			}
			// Inline strikethrough rendering uses Unicode (~~) lookalike since WC Blocks
			// renders this string as text; HTML tags would be escaped.
			return `${ data.original_formatted } → ${ defaultValue }`;
		},
	} );

	return true;
};

// WC Blocks Checkout JS may not be on the page yet when this script runs. Retry
// briefly on DOMContentLoaded; fall back to a one-shot wait via requestAnimationFrame.
if ( ! registerFilters() ) {
	const onReady = () => {
		if ( registerFilters() ) {
			return;
		}
		// Retry a few frames in case WC Blocks loads after us.
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
