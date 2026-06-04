/**
 * CancellationReasonsSection (NPPD-1616).
 *
 * Bucketed cancellation reasons in window. Reasons map to the
 * `newspack_subscriptions_cancellation_reason` postmeta; unset values
 * bucket as `'unknown'` (often substantial for cancellations
 * predating the feature).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { CancellationReasonRow } from '../../api/subscribers';
import { formatNumber } from './format';

export interface CancellationReasonsSectionProps {
	rows: CancellationReasonRow[];
}

const humanize = ( raw: string ): string => {
	if ( raw === 'unknown' ) {
		return __( 'Unknown', 'newspack-plugin' );
	}
	return raw.replace( /[_-]+/g, ' ' ).replace( /\b\w/g, c => c.toUpperCase() );
};

const CancellationReasonsSection = ( { rows }: CancellationReasonsSectionProps ) => {
	const max = useMemo( () => Math.max( ...rows.map( r => r.count ), 1 ), [ rows ] );

	if ( rows.length === 0 ) {
		return (
			<section
				className="newspack-insights__section newspack-insights__section--cancellation-reasons"
				aria-labelledby="newspack-insights-cancellations-heading"
			>
				<h2 id="newspack-insights-cancellations-heading" className="newspack-insights__section-heading">
					{ __( 'Cancellation reasons', 'newspack-plugin' ) }
				</h2>
				<p className="newspack-insights__section-empty">
					{ __( 'No cancellations in the selected window.', 'newspack-plugin' ) }
				</p>
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
				{ rows.map( row => (
					<li key={ row.cancellation_reason } className="newspack-insights__bar-list-item">
						<span className="newspack-insights__bar-list-label">{ humanize( row.cancellation_reason ) }</span>
						<span
							className="newspack-insights__bar-list-bar"
							style={ { width: `${ ( row.count / max ) * 100 }%` } }
							aria-hidden="true"
						/>
						<span className="newspack-insights__bar-list-value">{ formatNumber( row.count ) }</span>
					</li>
				) ) }
			</ul>
		</section>
	);
};

export default CancellationReasonsSection;
