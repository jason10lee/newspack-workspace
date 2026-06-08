/**
 * MetricCard (NPPD-1616, extended for NPPD-1604 and NPPD-1649).
 *
 * Scorecard atom: label (top) → value + optional delta (vertically
 * centered hero region) → description (pinned to the bottom).
 *
 * `lowerIsBetter` flips the green/red delta tone for metrics where a
 * decrease is desirable (refund rate, churned subscriber count).
 *
 * `pending` (NPPD-1604) renders the value normally but suppresses the
 * comparison delta even when `previousValue` is supplied.
 *
 * `overlay` / `error` / `notConfigured` (NPPD-1649) render a single shared
 * graceful-failure treatment (see MetricNote) in place of the value. All are
 * additive — every existing call site leaves them undefined, unchanged.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatCurrency, formatDecimal, formatDuration, formatNumber, formatPercent, formatDelta, deltaTone } from './format';
import MetricNote from './MetricNote';

export type MetricFormat = 'number' | 'currency' | 'percent' | 'decimal' | 'duration';

export interface MetricCardOverlay {
	type: string;
	dimensions: string[];
}

export interface MetricCardProps {
	label: string;
	value?: number;
	format?: MetricFormat;
	/** Null is treated the same as undefined — no comparison delta is rendered. */
	previousValue?: number | null;
	description?: string;
	lowerIsBetter?: boolean;
	secondary?: string;
	pending?: boolean;
	/** Missing-custom-dimension state. */
	overlay?: MetricCardOverlay;
	/** Generic failure message (presence triggers the note; text shown in title). */
	error?: string;
	/** Metric needs configuration (e.g. coverage area not set). */
	notConfigured?: boolean;
}

const formatValue = ( v: number, fmt: MetricFormat ): string => {
	switch ( fmt ) {
		case 'currency':
			return formatCurrency( v );
		case 'percent':
			return formatPercent( v );
		case 'decimal':
			return formatDecimal( v );
		case 'duration':
			return formatDuration( v );
		default:
			return formatNumber( v );
	}
};

const MetricCard = ( props: MetricCardProps ) => {
	const {
		label,
		value = 0,
		format = 'number',
		previousValue,
		description,
		lowerIsBetter = false,
		secondary,
		pending = false,
		overlay,
		error,
		notConfigured,
	} = props;

	// Shared graceful-failure state (missing dimension / not configured / error).
	if ( overlay || error || notConfigured ) {
		return (
			<div className="newspack-insights__metric-card newspack-insights__metric-card--note">
				<div className="newspack-insights__metric-card-label">{ label }</div>
				<div className="newspack-insights__metric-card-body">
					<MetricNote overlay={ overlay } error={ !! error } notConfigured={ notConfigured } />
				</div>
				{ description && <div className="newspack-insights__metric-card-description">{ description }</div> }
			</div>
		);
	}

	const hasComparison = ! pending && typeof previousValue === 'number';
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

	return (
		<div className="newspack-insights__metric-card">
			<div className="newspack-insights__metric-card-label">{ label }</div>
			<div className="newspack-insights__metric-card-body">
				<div className="newspack-insights__metric-card-value">{ formatValue( value, format ) }</div>
				{ secondary && <div className="newspack-insights__metric-card-secondary">{ secondary }</div> }
				{ hasComparison && delta && (
					<div
						className={ `newspack-insights__metric-card-delta newspack-insights__metric-card-delta--${ tone }` }
						aria-label={ deltaA11y ?? undefined }
					>
						{ delta }
					</div>
				) }
			</div>
			{ description && <div className="newspack-insights__metric-card-description">{ description }</div> }
		</div>
	);
};

export default MetricCard;
