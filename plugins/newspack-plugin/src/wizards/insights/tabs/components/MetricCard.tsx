/**
 * MetricCard (NPPD-1616, extended for NPPD-1604).
 *
 * Scorecard atom: label (top) → value + optional delta (vertically
 * centered hero region) → description (pinned to the bottom). Every
 * card carries the brand-color top accent so all cards in a row read
 * as a single coherent unit, and the hero numbers line up at the same
 * vertical position regardless of label or description height.
 *
 * `lowerIsBetter` flips the green/red delta tone for metrics where a
 * decrease is desirable (refund rate, churned subscriber count).
 *
 * `pending` (NPPD-1604) renders the value normally but suppresses the
 * comparison delta even when `previousValue` is supplied. Used by Tab
 * 4's Phase 1 placeholder cards: the value is a real "0" / "0%" /
 * etc., so it visually matches the surrounding chrome — the
 * top-of-tab banner is the only Phase 1 signal. Additive: Tab 6/7
 * never set `pending`, so their rendering is unchanged.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatCurrency, formatDecimal, formatNumber, formatPercent, formatDelta, deltaTone } from './format';

export type MetricFormat = 'number' | 'currency' | 'percent' | 'decimal';

export interface MetricCardProps {
	label: string;
	value: number;
	format: MetricFormat;
	previousValue?: number;
	description?: string;
	lowerIsBetter?: boolean;
	/**
	 * Short secondary snippet rendered below the value, before the
	 * delta. Used for compressed paired metrics (e.g. "$X annualized"
	 * under MRR, "$Y one-time + $Z recurring" under Total Revenue, or
	 * "N active recurring" under Active Donors) so we can ship the
	 * paired insight without spending a whole card on it.
	 */
	secondary?: string;
	/**
	 * Phase 1 placeholder marker (NPPD-1604). When true, the card
	 * renders the value normally but suppresses the comparison delta
	 * even if `previousValue` is provided — there's no real delta to
	 * show while a metric is pending real data.
	 */
	pending?: boolean;
}

const formatValue = ( v: number, fmt: MetricFormat ): string => {
	if ( fmt === 'currency' ) {
		return formatCurrency( v );
	}
	if ( fmt === 'percent' ) {
		return formatPercent( v );
	}
	if ( fmt === 'decimal' ) {
		return formatDecimal( v );
	}
	return formatNumber( v );
};

const MetricCard = ( props: MetricCardProps ) => {
	const { label, value, format, previousValue, description, lowerIsBetter = false, secondary, pending = false } = props;
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
