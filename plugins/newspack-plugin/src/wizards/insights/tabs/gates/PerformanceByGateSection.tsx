/**
 * PerformanceByGateSection (NPPD-1604, Section 5).
 *
 * Full-width per-gate breakdown table. Phase 1 always renders the
 * empty-state copy from spec since `rows` is empty. When Phase 2
 * (NPPD-1630) populates `performance_by_gate.rows` from BQ + a
 * server-side `wp_posts.post_title` enrichment, the table will
 * render rows sorted by impressions DESC with em-dash cells where a
 * gate doesn't have the matching block (regwall or paywall).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { GatesPerformanceRow, GatesPerformanceTable } from '../../api/gates';
import { formatNumber, formatPercent } from '../components/format';

export interface PerformanceByGateSectionProps {
	data: GatesPerformanceTable;
}

const NotApplicable = () => (
	<span className="newspack-insights__table-na" aria-label={ __( 'Not applicable', 'newspack-plugin' ) }>
		—
	</span>
);

const renderCount = ( v: number | null ) => ( v === null ? <NotApplicable /> : formatNumber( v ) );
const renderPercent = ( v: number | null ) => ( v === null ? <NotApplicable /> : formatPercent( v ) );

const renderRow = ( row: GatesPerformanceRow ) => (
	<tr key={ row.gate_post_id }>
		<td>{ row.gate_name }</td>
		<td className="newspack-insights__table-num">{ formatNumber( row.impressions ) }</td>
		<td className="newspack-insights__table-num">{ formatNumber( row.unique_viewers ) }</td>
		<td className="newspack-insights__table-num">{ renderCount( row.regwall_conversions ) }</td>
		<td className="newspack-insights__table-num">{ renderPercent( row.regwall_conversion_rate ) }</td>
		<td className="newspack-insights__table-num">{ renderCount( row.paywall_conversions ) }</td>
		<td className="newspack-insights__table-num">{ renderPercent( row.paywall_conversion_rate ) }</td>
	</tr>
);

const PerformanceByGateSection = ( { data }: PerformanceByGateSectionProps ) => {
	const isEmpty = data.rows.length === 0;
	return (
		<section
			className="newspack-insights__section newspack-insights__section--performance"
			aria-labelledby="newspack-insights-gates-performance-heading"
		>
			<h2 id="newspack-insights-gates-performance-heading" className="newspack-insights__section-heading">
				{ __( 'Performance by gate', 'newspack-plugin' ) }
			</h2>
			<p className="newspack-insights__section-caption">
				{ __( 'Per-gate breakdown for the selected timeframe. Sorted by impressions, highest first.', 'newspack-plugin' ) }
			</p>
			<div className="newspack-insights__table-wrap">
				<table className="newspack-insights__table">
					<thead>
						<tr>
							<th scope="col">{ __( 'Gate name', 'newspack-plugin' ) }</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Impressions', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Unique viewers', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Regwall conversions', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Regwall conversion rate', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Paywall conversions', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Paywall conversion rate', 'newspack-plugin' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ isEmpty ? (
							<tr>
								<td colSpan={ 7 } className="newspack-insights__gates-performance-empty">
									{ __(
										'No gate data yet. Performance metrics will appear once readers begin interacting with your gates.',
										'newspack-plugin'
									) }
								</td>
							</tr>
						) : (
							data.rows.map( renderRow )
						) }
					</tbody>
				</table>
			</div>
		</section>
	);
};

export default PerformanceByGateSection;
