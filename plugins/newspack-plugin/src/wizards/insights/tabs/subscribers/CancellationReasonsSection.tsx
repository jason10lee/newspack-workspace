/**
 * CancellationReasonsSection (NPPD-1616).
 *
 * Bucketed cancellation reasons in window. Reasons map to the
 * `newspack_subscriptions_cancellation_reason` postmeta; unset values
 * bucket as `'unknown'` (often substantial for cancellations
 * predating the feature).
 *
 * Bars scale relative to `activeSubscribers` (the current snapshot
 * total) rather than the max reason count, so a single cancellation
 * among many subscribers renders as a small bar — communicating "this
 * is rare" instead of "this is the dominant reason." For populated
 * data the per-bar denominator stays the same across rows, so the
 * relative ordering between reasons remains visible.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { CancellationReasonRow } from '../../api/subscribers';
import { formatNumber } from './format';

export interface CancellationReasonsSectionProps {
	rows: CancellationReasonRow[];
	activeSubscribers: number;
}

const humanize = ( raw: string ): string => {
	if ( raw === 'unknown' ) {
		return __( 'Unknown', 'newspack-plugin' );
	}
	return raw.replace( /[_-]+/g, ' ' ).replace( /\b\w/g, c => c.toUpperCase() );
};

const CancellationReasonsSection = ( { rows, activeSubscribers }: CancellationReasonsSectionProps ) => {
	// Denominator: use the active subscriber count if we have one,
	// otherwise fall back to the max reason count so the bars still
	// produce a meaningful relative ordering when there's no active
	// base to compare against (e.g. a defunct product still shows
	// historical cancellations).
	const denominator = activeSubscribers > 0 ? activeSubscribers : Math.max( ...rows.map( r => r.count ), 1 );

	if ( rows.length === 0 ) {
		return (
			<section
				className="newspack-insights__section newspack-insights__section--cancellation-reasons"
				aria-labelledby="newspack-insights-cancellations-heading"
			>
				<h2 id="newspack-insights-cancellations-heading" className="newspack-insights__section-heading">
					{ __( 'Cancellation reasons', 'newspack-plugin' ) }
				</h2>
				<p className="newspack-insights__section-empty">{ __( 'No cancellations in the selected timeframe.', 'newspack-plugin' ) }</p>
			</section>
		);
	}

	return (
		<section
			className="newspack-insights__section newspack-insights__section--cancellation-reasons"
			aria-labelledby="newspack-insights-cancellations-heading"
		>
			<h2 id="newspack-insights-cancellations-heading" className="newspack-insights__section-heading">
				{ __( 'Cancellation reasons', 'newspack-plugin' ) }
			</h2>
			<ul className="newspack-insights__bar-list" aria-label={ __( 'Cancellation reasons', 'newspack-plugin' ) }>
				{ rows.map( row => {
					// Clamp the visual width to [0, 100]. The active subscriber
					// denominator could theoretically be smaller than a reason
					// count if the window's churn produced more cancellations
					// than the current active base (mass-churn scenario).
					const widthPct = Math.min( 100, ( row.count / denominator ) * 100 );
					return (
						<li key={ row.cancellation_reason } className="newspack-insights__bar-list-item">
							<span className="newspack-insights__bar-list-label">{ humanize( row.cancellation_reason ) }</span>
							<span className="newspack-insights__bar-list-bar" style={ { width: `${ widthPct }%` } } aria-hidden="true" />
							<span className="newspack-insights__bar-list-value">{ formatNumber( row.count ) }</span>
						</li>
					);
				} ) }
			</ul>
		</section>
	);
};

export default CancellationReasonsSection;
