/**
 * MetricCard (NPPD-1616).
 *
 * Scorecard atom: label + big value + optional previous-window delta.
 * Composed by ScorecardSection and RevenueSection.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	formatCurrency,
	formatNumber,
	formatPercent,
	formatDelta,
	deltaDirection,
} from './format';

export type MetricFormat = 'number' | 'currency' | 'percent';

export interface MetricCardProps {
	label: string;
	value: number;
	format: MetricFormat;
	previousValue?: number;
	description?: string;
}

const formatValue = ( v: number, fmt: MetricFormat ): string => {
	if ( fmt === 'currency' ) {
		return formatCurrency( v );
	}
	if ( fmt === 'percent' ) {
		return formatPercent( v );
	}
	return formatNumber( v );
};

const MetricCard = ( props: MetricCardProps ) => {
	const { label, value, format, previousValue, description } = props;
	const hasComparison = typeof previousValue === 'number';
	const delta = hasComparison ? formatDelta( value, previousValue as number ) : null;
	const direction = hasComparison ? deltaDirection( value, previousValue as number ) : 'flat';
	const deltaA11y = hasComparison && delta
		? sprintf(
			/* translators: %s: signed percent change from previous window */
			__( '%s vs previous window', 'newspack-plugin' ),
			delta
		)
		: null;

	return (
		<div className="newspack-insights__metric-card">
			<div className="newspack-insights__metric-card-label">{ label }</div>
			<div className="newspack-insights__metric-card-value">{ formatValue( value, format ) }</div>
			{ hasComparison && delta && (
				<div
					className={ `newspack-insights__metric-card-delta newspack-insights__metric-card-delta--${ direction }` }
					aria-label={ deltaA11y ?? undefined }
				>
					{ delta }
				</div>
			) }
			{ description && (
				<div className="newspack-insights__metric-card-description">{ description }</div>
			) }
		</div>
	);
};

export default MetricCard;
