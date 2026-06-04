/**
 * TenureSection (NPPD-1616).
 *
 * Tenure of the active non-donation subscriber base. Server returns one
 * row per active subscription `{ product_name, tenure_days }`; this
 * component computes median + quartiles + day-bucket counts client-side
 * so the raw distribution remains available for future drill-downs.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf, _n } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { TenureDistributionRow } from '../../api/subscribers';
import { formatNumber } from './format';

export interface TenureSectionProps {
	rows: TenureDistributionRow[];
}

interface TenureBucket {
	key: string;
	label: string;
	count: number;
}

const BUCKETS: { key: string; label: string; min: number; max: number }[] = [
	{ key: '0-30', label: __( '0–30 days', 'newspack-plugin' ), min: 0, max: 30 },
	{ key: '31-90', label: __( '31–90 days', 'newspack-plugin' ), min: 31, max: 90 },
	{ key: '91-180', label: __( '91–180 days', 'newspack-plugin' ), min: 91, max: 180 },
	{ key: '181-365', label: __( '181–365 days', 'newspack-plugin' ), min: 181, max: 365 },
	{ key: '365+', label: __( 'Over 1 year', 'newspack-plugin' ), min: 366, max: Infinity },
];

const percentile = ( sorted: number[], p: number ): number => {
	if ( sorted.length === 0 ) {
		return 0;
	}
	if ( sorted.length === 1 ) {
		return sorted[ 0 ];
	}
	const rank = ( sorted.length - 1 ) * p;
	const lower = Math.floor( rank );
	const upper = Math.ceil( rank );
	const weight = rank - lower;
	return sorted[ lower ] * ( 1 - weight ) + sorted[ upper ] * weight;
};

const TenureSection = ( { rows }: TenureSectionProps ) => {
	const stats = useMemo( () => {
		if ( rows.length === 0 ) {
			return null;
		}
		const days = rows
			.map( r => r.tenure_days )
			.filter( ( d ): d is number => Number.isFinite( d ) )
			.sort( ( a, b ) => a - b );
		const buckets: TenureBucket[] = BUCKETS.map( b => ( {
			key: b.key,
			label: b.label,
			count: days.filter( d => d >= b.min && d <= b.max ).length,
		} ) );
		const max = Math.max( ...buckets.map( b => b.count ), 1 );
		return {
			count: days.length,
			p25: Math.round( percentile( days, 0.25 ) ),
			median: Math.round( percentile( days, 0.5 ) ),
			p75: Math.round( percentile( days, 0.75 ) ),
			buckets,
			max,
		};
	}, [ rows ] );

	if ( ! stats ) {
		return (
			<section className="newspack-insights__section newspack-insights__section--tenure" aria-labelledby="newspack-insights-tenure-heading">
				<h2 id="newspack-insights-tenure-heading" className="newspack-insights__section-heading">
					{ __( 'Subscriber tenure', 'newspack-plugin' ) }
				</h2>
				<p className="newspack-insights__section-empty">
					{ __( 'No subscribers yet — tenure data will appear once subscriptions exist.', 'newspack-plugin' ) }
				</p>
			</section>
		);
	}

	return (
		<section className="newspack-insights__section newspack-insights__section--tenure" aria-labelledby="newspack-insights-tenure-heading">
			<h2 id="newspack-insights-tenure-heading" className="newspack-insights__section-heading">
				{ __( 'Subscriber tenure', 'newspack-plugin' ) }
			</h2>
			<dl className="newspack-insights__stats-summary">
				<div>
					<dt>{ __( 'Median tenure', 'newspack-plugin' ) }</dt>
					<dd>
						{ sprintf(
							/* translators: %d: number of days */
							_n( '%d day', '%d days', stats.median, 'newspack-plugin' ),
							stats.median
						) }
					</dd>
				</div>
				<div>
					<dt>{ __( '25th percentile', 'newspack-plugin' ) }</dt>
					<dd>
						{
							/* translators: %d: number of days */
							sprintf( _n( '%d day', '%d days', stats.p25, 'newspack-plugin' ), stats.p25 )
						}
					</dd>
				</div>
				<div>
					<dt>{ __( '75th percentile', 'newspack-plugin' ) }</dt>
					<dd>
						{
							/* translators: %d: number of days */
							sprintf( _n( '%d day', '%d days', stats.p75, 'newspack-plugin' ), stats.p75 )
						}
					</dd>
				</div>
			</dl>
			<ul className="newspack-insights__bar-list" aria-label={ __( 'Tenure buckets', 'newspack-plugin' ) }>
				{ stats.buckets.map( b => (
					<li key={ b.key } className="newspack-insights__bar-list-item">
						<span className="newspack-insights__bar-list-label">{ b.label }</span>
						<span
							className="newspack-insights__bar-list-bar"
							style={ { width: `${ ( b.count / stats.max ) * 100 }%` } }
							aria-hidden="true"
						/>
						<span className="newspack-insights__bar-list-value">{ formatNumber( b.count ) }</span>
					</li>
				) ) }
			</ul>
		</section>
	);
};

export default TenureSection;
