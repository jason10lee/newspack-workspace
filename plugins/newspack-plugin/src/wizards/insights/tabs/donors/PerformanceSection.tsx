/**
 * PerformanceSection (NPPD-1617).
 *
 * Donations by tier — table identical in shape to Tab 6's Performance
 * by product, with nested variation rows. Parent rows aggregate the
 * SUM of their variations; standalone products render as a single
 * row. Sorted by lifetime_donation_revenue DESC, top 50 server-side.
 *
 * Most columns are window-scoped to the date picker, but Lifetime
 * Revenue is all-time. A caption above the table makes that mixed
 * temporal scope explicit so publishers don't read the columns as
 * uniformly scoped.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { DonorsTierRow } from '../../api/donors';
import { formatCurrency, formatNumber } from '../components/format';

export interface PerformanceSectionProps {
	rows: DonorsTierRow[];
}

const PerformanceSection = ( { rows }: PerformanceSectionProps ) => {
	if ( rows.length === 0 ) {
		return (
			<section
				className="newspack-insights__section newspack-insights__section--performance"
				aria-labelledby="newspack-insights-donors-performance-heading"
			>
				<h2 id="newspack-insights-donors-performance-heading" className="newspack-insights__section-heading">
					{ __( 'Donations by tier', 'newspack-plugin' ) }
				</h2>
				<p className="newspack-insights__section-empty">{ __( 'No donation activity yet.', 'newspack-plugin' ) }</p>
			</section>
		);
	}

	return (
		<section
			className="newspack-insights__section newspack-insights__section--performance"
			aria-labelledby="newspack-insights-donors-performance-heading"
		>
			<h2 id="newspack-insights-donors-performance-heading" className="newspack-insights__section-heading">
				{ __( 'Donations by tier', 'newspack-plugin' ) }
			</h2>
			<p className="newspack-insights__section-caption">
				{ __(
					'Active recurring donors, new donors, one-time gifts, and recurring revenue are scoped to the selected timeframe. Lifetime revenue is the all-time total per product.',
					'newspack-plugin'
				) }
			</p>
			<div className="newspack-insights__table-wrap">
				<table className="newspack-insights__table">
					<thead>
						<tr>
							<th scope="col">{ __( 'Product', 'newspack-plugin' ) }</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Active recurring donors', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'New donors', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'One-time gifts', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Recurring revenue', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Lifetime revenue', 'newspack-plugin' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( row => (
							<Fragment key={ row.product_id }>
								<tr>
									<td>{ row.name }</td>
									<td className="newspack-insights__table-num">{ formatNumber( row.active_recurring_donors ) }</td>
									<td className="newspack-insights__table-num">{ formatNumber( row.new_donors_in_window ) }</td>
									<td className="newspack-insights__table-num">{ formatNumber( row.one_time_gifts_in_window ) }</td>
									<td className="newspack-insights__table-num">{ formatCurrency( row.recurring_revenue_in_window ) }</td>
									<td className="newspack-insights__table-num">{ formatCurrency( row.lifetime_donation_revenue ) }</td>
								</tr>
								{ row.is_parent &&
									row.variations?.map( v => (
										<tr key={ `${ row.product_id }-${ v.variation_id }` } className="newspack-insights__table-row--variation">
											<td>{ v.label }</td>
											<td className="newspack-insights__table-num">{ formatNumber( v.active_recurring_donors ) }</td>
											<td className="newspack-insights__table-num">{ formatNumber( v.new_donors_in_window ) }</td>
											<td className="newspack-insights__table-num">{ formatNumber( v.one_time_gifts_in_window ) }</td>
											<td className="newspack-insights__table-num">{ formatCurrency( v.recurring_revenue_in_window ) }</td>
											<td className="newspack-insights__table-num">{ formatCurrency( v.lifetime_donation_revenue ) }</td>
										</tr>
									) ) }
							</Fragment>
						) ) }
					</tbody>
				</table>
			</div>
		</section>
	);
};

export default PerformanceSection;
