/**
 * MetricCard (NPPD-1616).
 *
 * Scorecard atom: label + big value + optional previous-window delta.
 * Composed by ScorecardSection and RevenueSection.
 *
 * `primary` controls visual weight (44px value per spec value-lg; the
 * default 32px secondary uses value-md). `lowerIsBetter` flips the
 * green/red delta tone for metrics where a decrease is desirable
 * (refund rate, churned subscriber count).
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatCurrency, formatNumber, formatPercent, formatDelta, deltaTone } from './format';

export type MetricFormat = 'number' | 'currency' | 'percent';

export interface MetricCardProps {
	label: string;
	value: number;
	format: MetricFormat;
	previousValue?: number;
	description?: string;
	primary?: boolean;
	lowerIsBetter?: boolean;
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
	const { label, value, format, previousValue, description, primary = false, lowerIsBetter = false } = props;
	const hasComparison = typeof previousValue === 'number';
	const delta = hasComparison ? formatDelta( value, previousValue as number ) : null;
	const tone = hasComparison ? deltaTone( value, previousValue as number, lowerIsBetter ) : 'neutral';
	const deltaA11y =
		hasComparison && delta
			? sprintf(
					/* translators: %s: signed percent change from previous timeframe */
					__( '%s vs previous timeframe', 'newspack-plugin' ),
					delta
			  )
			: null;

	const cardClass = `newspack-insights__metric-card${ primary ? ' newspack-insights__metric-card--primary' : '' }`;

	return (
		<div className={ cardClass }>
			<div className="newspack-insights__metric-card-label">{ label }</div>
			<div className="newspack-insights__metric-card-value">{ formatValue( value, format ) }</div>
			{ hasComparison && delta && (
				<div
					className={ `newspack-insights__metric-card-delta newspack-insights__metric-card-delta--${ tone }` }
					aria-label={ deltaA11y ?? undefined }
				>
					{ delta }
				</div>
			) }
			{ description && <div className="newspack-insights__metric-card-description">{ description }</div> }
		</div>
	);
};

export default MetricCard;
