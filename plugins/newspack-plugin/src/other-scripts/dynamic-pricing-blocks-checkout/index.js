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
const { registerCheckoutFilters, ExperimentalOrderMeta } = blocksCheckout;
const { registerPlugin } = window.wp?.plugins || {};
const { createElement: el, Fragment } = window.wp?.element || {};

/* --- Layer 1: purchase-line annotation --- */

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
			if ( ! annotation ) {
				return value;
			}
			// Strip WCS's period suffix (" every month" / " / month") when the
			// charged price is purchase-only — it otherwise implies the intro
			// recurs. PHP-derived so it matches the active locale and interval;
			// we try the suffix with and without a leading space because
			// `value` may arrive with either pattern.
			let result = value;
			const suffix = annotation.period_suffix;
			if ( suffix ) {
				for ( const candidate of [ ' ' + suffix, suffix ] ) {
					if ( result.includes( candidate ) ) {
						result = result.split( candidate ).join( '' );
						break;
					}
				}
			}
			return annotation.price_suffix ? result + annotation.price_suffix : result;
		},
	} );
}

/* --- Layer 2b: schedule row (ExperimentalOrderMeta fill) --- */

/**
 * Render the schedule row. The Slot passes the cart `extensions` object as a
 * prop to its children.
 *
 * @param {{extensions: object}} props
 */
const ScheduleFill = ( { extensions } ) => {
	const data = extensions?.[ NAMESPACE ];
	const sentences = data?.schedule_sentences || [];
	if ( ! sentences.length ) {
		return null;
	}
	// One row per item; for the typical single-subscription cart this is one
	// line. Multiple items get one row each, prefixed with the item name to
	// disambiguate — the legacy template renders the same way (one <tr> each).
	const label = data.schedule_label || '';
	return el(
		Fragment,
		null,
		sentences.map( s =>
			el(
				'div',
				{ key: s.key, className: 'wc-block-components-totals-item newspack-dp-schedule' },
				el( 'span', { className: 'wc-block-components-totals-item__label' }, label ),
				el(
					'span',
					{ className: 'wc-block-components-totals-item__value' },
					sentences.length > 1 ? `${ s.item_name }: ${ s.sentence }` : s.sentence
				)
			)
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
