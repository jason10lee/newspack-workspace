/**
 * The impact table shared by the editor preview and the catalog panel: products
 * × regular vs. resulting price, with per-cycle segments. Used once for the
 * baseline and once per reader-segment group.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatPrice, formatSegment } from './impact-format';

function SegmentsCell( { row, currency }: { row: CatalogImpactRow; currency: PricingRulesCurrency } ) {
	if ( row.segments.length <= 1 ) {
		return <>{ formatPrice( row.adjusted, currency ) }</>;
	}
	return (
		<>
			{ row.segments.map( ( seg, i ) => (
				<span key={ i } className={ seg.changed ? 'is-changed' : undefined }>
					{ i > 0 ? ' · ' : '' }
					{ formatSegment( seg, currency ) }
				</span>
			) ) }
		</>
	);
}

export default function ImpactTable( { rows, currency }: { rows: CatalogImpactRow[]; currency: PricingRulesCurrency } ) {
	return (
		<table className="newspack-pricing-rules__impact-table">
			<thead>
				<tr>
					<th>{ __( 'Product', 'newspack-plugin' ) }</th>
					<th>{ __( 'Regular', 'newspack-plugin' ) }</th>
					<th>{ __( 'Resulting price', 'newspack-plugin' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( row => (
					<tr key={ row.product_id } className={ row.changed ? 'is-changed' : undefined }>
						<td>{ row.edit_link ? <a href={ row.edit_link }>{ row.name }</a> : row.name }</td>
						<td>{ formatPrice( row.regular, currency ) }</td>
						<td>
							<SegmentsCell row={ row } currency={ currency } />
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}
