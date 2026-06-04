/**
 * PerformanceSection (NPPD-1616).
 *
 * Per-product breakdown for non-donation subscription products. Top 50
 * by active subscriber count (already limited server-side). Rendered as
 * a sortable-by-server table; client-side sorting is intentionally out
 * of scope for v1 to keep the implementation contained.
 *
 * lifetime_revenue is an approximation (sum of renewal-amount rows
 * across active + churned subs); a true LTV waits on the BigQuery
 * wrapper in v1.1.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { PerformanceRow } from '../../api/subscribers';
import { formatCurrency, formatNumber } from './format';

export interface PerformanceSectionProps {
	rows: PerformanceRow[];
}

const PerformanceSection = ( { rows }: PerformanceSectionProps ) => {
	if ( rows.length === 0 ) {
		return (
			<section
				className="newspack-insights__section newspack-insights__section--performance"
				aria-labelledby="newspack-insights-performance-heading"
			>
				<h2 id="newspack-insights-performance-heading" className="newspack-insights__section-heading">
					{ __( 'Performance by product', 'newspack-plugin' ) }
				</h2>
				<p className="newspack-insights__section-empty">
					{ __( 'No subscription product activity to report.', 'newspack-plugin' ) }
				</p>
			</section>
		);
	}

	return (
		<section
			className="newspack-insights__section newspack-insights__section--performance"
			aria-labelledby="newspack-insights-performance-heading"
		>
			<h2 id="newspack-insights-performance-heading" className="newspack-insights__section-heading">
				{ __( 'Performance by product', 'newspack-plugin' ) }
			</h2>
			<div className="newspack-insights__table-wrap">
				<table className="newspack-insights__table">
					<thead>
						<tr>
							<th scope="col">{ __( 'Product', 'newspack-plugin' ) }</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Active subs', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Churned subs', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Active value', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Lifetime revenue', 'newspack-plugin' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( row => (
							<tr key={ row.product_id }>
								<td>{ row.product_name }</td>
								<td className="newspack-insights__table-num">{ formatNumber( row.active_subs ) }</td>
								<td className="newspack-insights__table-num">{ formatNumber( row.churned_subs ) }</td>
								<td className="newspack-insights__table-num">{ formatCurrency( row.active_value ) }</td>
								<td className="newspack-insights__table-num">{ formatCurrency( row.lifetime_revenue ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</section>
	);
};

export default PerformanceSection;
