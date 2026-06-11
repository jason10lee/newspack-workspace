/**
 * WC Blocks Cart & Checkout integrations for dynamic pricing.
 *
 * Two slices, both server-composed (all copy and translation in PHP):
 *
 * - Layer 1 — purchase-line annotation: appends `name_suffix` and `price_suffix`
 *   from the `newspack-dynamic-pricing` cart-item extension via
 *   registerCheckoutFilters. See WooProduct_Surface::store_api_cart_item_data.
 *
 * - Layer 2b — schedule row: reads `schedule_sentences` from the same
 *   namespace's cart-level extension and renders an ExperimentalOrderMeta fill
 *   so it appears in both Cart and Checkout blocks. See
 *   WooProduct_Surface::store_api_cart_data.
 *
 * None of these packages are npm dependencies in this repo — they are runtime
 * globals provided by the script handles declared in
 * WooProduct_Surface::enqueue_blocks_checkout_script:
 *   - wc-blocks-checkout → window.wc.blocksCheckout
 *   - wp-plugins         → window.wp.plugins
 *   - wp-element         → window.wp.element
 */

const NAMESPACE = 'newspack-dynamic-pricing';

const blocksCheckout = window.wc?.blocksCheckout || {};
const { registerCheckoutFilters, ExperimentalOrderMeta, TotalsItem } = blocksCheckout;
const { registerPlugin } = window.wp?.plugins || {};
const { createElement: el, Fragment } = window.wp?.element || {};

/* --- Layer 1: purchase-line annotation --- */

const getAnnotation = extensions => {
	const data = extensions?.[ NAMESPACE ];
	return data?.publicized ? data : null;
};

if ( registerCheckoutFilters ) {
	// Strip the WCS-injected period suffix from `value`. WCS's u() helper uses
	// a NON-BREAKING space (U+00A0,  ) between `<price/>` and the
	// separator (deliberate — keeps the price and period together visually);
	// the space between the separator and the period word is a regular space.
	// We have to match nbsp-leading candidates as well as regular-space ones.
	// The annotation must carry a non-empty `period_suffix` — that doubles as
	// the "purchase price does not recur" gate; flat-unlimited rules skip.
	const NBSP = ' ';
	const stripPeriod = ( value, annotation ) => {
		if ( ! annotation || ! annotation.period_suffix ) {
			return value;
		}
		const word = annotation.period_word;
		const candidates = [];
		if ( word ) {
			// WCS subtotalPriceFormat: `<price/> every month`
			candidates.push( `${ NBSP }every ${ word }`, ` every ${ word }` );
			// WCS saleBadgePriceFormat: `<price/> / month`
			candidates.push( `${ NBSP }/ ${ word }`, ` / ${ word }`, `/ ${ word }` );
		}
		// Already-rendered single-token form (legacy paths and our PHP-side suffix).
		candidates.push( NBSP + annotation.period_suffix, ' ' + annotation.period_suffix, annotation.period_suffix );
		let result = value;
		for ( const candidate of candidates ) {
			if ( candidate && result.includes( candidate ) ) {
				result = result.split( candidate ).join( '' );
				break;
			}
		}
		return result;
	};

	registerCheckoutFilters( NAMESPACE, {
		itemName: ( value, extensions ) => {
			const annotation = getAnnotation( extensions );
			return annotation?.name_suffix ? value + annotation.name_suffix : value;
		},
		// The row's main price column (top-right of the cart line). WCS only
		// modifies this when sign-up fees exist; for the typical case the
		// strip is a no-op. We append our suffix unconditionally for the
		// annotated row.
		cartItemPrice: ( value, extensions ) => {
			const annotation = getAnnotation( extensions );
			if ( ! annotation ) {
				return value;
			}
			const result = stripPeriod( value, annotation );
			return annotation.price_suffix ? result + annotation.price_suffix : result;
		},
		// The smaller "$10.00 $5.00 every month" price line under the item
		// name. WCS injects "every <period>" here via its filter; ours runs
		// AFTER WCS's (via the wc-blocks-integration script dep) and strips it.
		subtotalPriceFormat: ( value, extensions ) => stripPeriod( value, getAnnotation( extensions ) ),
		// "Save <price/> / month" badge. WCS injects "/ <period>"; ours runs
		// after and strips it. The "Save $X" label itself remains — accurate
		// for the purchase (the discount IS applied at checkout) and any
		// stronger suppression would lose useful framing.
		saleBadgePriceFormat: ( value, extensions ) => stripPeriod( value, getAnnotation( extensions ) ),
	} );
}

/* --- Layer 2b: schedule row (ExperimentalOrderMeta fill) --- */

/**
 * Render the schedule row using WC Blocks' own `TotalsItem` component so it
 * picks up the same border/padding/label styling as the recurring totals row
 * WCS renders just above it. The Slot passes the cart `extensions` object as
 * a prop to its children.
 *
 * Schedule sentences are passed as `description` (rendered below the label) so
 * the long copy wraps naturally instead of cramming into a right-aligned
 * value column.
 *
 * @param {{extensions: object}} props
 */
const ScheduleFill = ( { extensions } ) => {
	const data = extensions?.[ NAMESPACE ];
	const sentences = data?.schedule_sentences || [];
	if ( ! sentences.length || ! TotalsItem ) {
		return null;
	}
	const label = data.schedule_label || '';
	return el(
		Fragment,
		null,
		sentences.map( s =>
			el( TotalsItem, {
				key: s.key,
				className: 'newspack-dp-schedule',
				label: sentences.length > 1 ? `${ label }: ${ s.item_name }` : label,
				value: ' ',
				description: s.sentence,
			} )
		)
	);
};

if ( registerPlugin && ExperimentalOrderMeta && el ) {
	const Render = () => el( ExperimentalOrderMeta, null, el( ScheduleFill ) );
	registerPlugin( 'newspack-dp-schedule', {
		render: Render,
		scope: 'woocommerce-checkout',
	} );
}
