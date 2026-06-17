/**
 * Catalog-wide impact preview — a portfolio overview rendered above the Pricing
 * Rules list. Fetches the standalone plugin's catalog impact endpoint and shows
 * how many products the active rules affect, with a small composed-price sample.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf, _n } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Spinner } from '@wordpress/components';

const API_PATH = '/wc-dynamic-pricing/v1/impact-preview';

function formatPrice( amount: number, currency: PricingRulesCurrency ): string {
	return currency.symbol + amount.toFixed( currency.decimals );
}

/**
 * The resulting-price cell: flat rules show the single adjusted price; scheduled
 * rules show each cycle's price (cycle 1 = purchase, later cycles = renewals).
 */
function describeResulting( row: CatalogImpactRow, currency: PricingRulesCurrency ): string {
	if ( row.segments.length <= 1 ) {
		return formatPrice( row.adjusted, currency );
	}
	return row.segments
		.map( seg =>
			seg.from_cycle === 1
				? formatPrice( seg.amount, currency )
				: sprintf(
						/* translators: 1: a formatted price, 2: a billing cycle number. */
						__( 'then %1$s from cycle %2$d', 'newspack-plugin' ),
						formatPrice( seg.amount, currency ),
						seg.from_cycle
				  )
		)
		.join( ' · ' );
}

export default function CatalogImpact() {
	const [ data, setData ] = useState< CatalogImpactResponse | null >( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ hasError, setHasError ] = useState( false );

	useEffect( () => {
		apiFetch< CatalogImpactResponse >( { path: API_PATH } )
			.then( setData )
			.catch( () => setHasError( true ) )
			.finally( () => setIsLoading( false ) );
	}, [] );

	if ( isLoading ) {
		return (
			<div className="newspack-pricing-rules__impact newspack-pricing-rules__impact--loading">
				<Spinner />
			</div>
		);
	}

	// Never block the list on a preview failure or an unsupported result.
	if ( hasError || ! data || ! data.supported ) {
		return null;
	}

	if ( data.total_matching === 0 ) {
		return (
			<div className="newspack-pricing-rules__impact">
				<p className="newspack-pricing-rules__muted">{ __( 'No active pricing rules are affecting products yet.', 'newspack-plugin' ) }</p>
			</div>
		);
	}

	const count = data.count_limited
		? sprintf(
				/* translators: %d: a number of products (a lower bound). */
				__( '%d+ products', 'newspack-plugin' ),
				data.total_matching
		  )
		: sprintf(
				/* translators: %d: a number of products. */
				_n( '%d product', '%d products', data.total_matching, 'newspack-plugin' ),
				data.total_matching
		  );

	return (
		<div className="newspack-pricing-rules__impact">
			<h3 className="newspack-pricing-rules__impact-title">
				{ sprintf(
					/* translators: %s: a product count like "142 products". */
					__( '%s affected by your active pricing rules', 'newspack-plugin' ),
					count
				) }
			</h3>
			{ data.preview_limited && (
				<p className="newspack-pricing-rules__muted">
					{ sprintf(
						/* translators: %d: number of sampled products shown. */
						__( 'Showing a sample of %d.', 'newspack-plugin' ),
						data.sample_count
					) }
				</p>
			) }
			<table className="newspack-pricing-rules__impact-table">
				<thead>
					<tr>
						<th>{ __( 'Product', 'newspack-plugin' ) }</th>
						<th>{ __( 'Regular', 'newspack-plugin' ) }</th>
						<th>{ __( 'Resulting price', 'newspack-plugin' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ data.sample.map( row => (
						<tr key={ row.product_id }>
							<td>{ row.edit_link ? <a href={ row.edit_link }>{ row.name }</a> : row.name }</td>
							<td>{ formatPrice( row.regular, data.currency ) }</td>
							<td>{ describeResulting( row, data.currency ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
