/**
 * PaidReaderConversionSection (NPPD-1604, Section 3; empty states NPPD-1694).
 *
 * Four scorecards in a single row covering paywall-gate conversion
 * (Direct attribution, Influenced 14-day lookback) plus revenue
 * from same-session paywall conversions.
 *
 * When the section would render as a row of zeros it swaps the grid for a
 * single `<EmptyMetricSection>` (detection stays here, not in the orchestrator):
 *   - no paywall attempts in the window → `no_opportunity`
 *   - attempts but no conversions       → `no_conversions` (with the attempt count)
 *   - otherwise the four scorecards, each carrying its count fallback so an
 *     individual zero card reads as "0 of N" / "0 conversions" rather than 0%/$0.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { GatesWindow } from '../../api/gates';
import MetricCard from '../components/MetricCard';
import SectionHeading from '../components/SectionHeading';
import EmptyMetricSection from '../components/EmptyMetricSection';
import { scalarToMetricCardProps } from './scalarToCard';

export interface PaidReaderConversionSectionProps {
	current: GatesWindow;
	previous: GatesWindow | null;
}

const HEADING_ID = 'newspack-insights-gates-paid-heading';

const PaidReaderConversionSection = ( { current, previous }: PaidReaderConversionSectionProps ) => {
	const title = __( 'Paid reader conversion', 'newspack-plugin' );
	const caption = __(
		'How effectively paywall gates convert visitors into paying subscribers. Direct counts subscriptions that happened in the same session as a paywall impression. Influenced counts subscriptions that happened in a later session within 14 days of a paywall impression. Revenue is computed from actual Woo orders, not gate-event amounts.',
		'newspack-plugin'
	);
	const attemptsLabel = __( 'paywall attempts', 'newspack-plugin' );
	const conversionsLabel = __( 'conversions', 'newspack-plugin' );

	const attempts = current.paywall_attempts_total;
	const conversions = current.paywall_conversions_total;

	// The section totals are derived from the Direct scalar's denominator/numerator,
	// which are null when that query errors — coercing both totals to 0. A zero total
	// is only a *genuine* empty state when the Direct metric actually computed;
	// otherwise we fall through to the scorecards so each card surfaces its own error
	// treatment rather than a misleading "no paywall attempts" empty state.
	const dataKnown = current.paywall_conversion_direct.state !== 'error';

	// Empty states (NPPD-1694). Order matters: no opportunity before no conversions.
	if ( dataKnown && attempts === 0 ) {
		return (
			<EmptyMetricSection
				title={ title }
				caption={ caption }
				state="no_opportunity"
				body={ __(
					'No paywall attempts in this window. Your paywall gates may not be reaching readers — could be a placement question, a frequency question, or simply that the date range doesn’t include enough traffic. See the per-gate breakdown below for configuration details.',
					'newspack-plugin'
				) }
			/>
		);
	}
	if ( dataKnown && conversions === 0 ) {
		return (
			<EmptyMetricSection
				title={ title }
				caption={ caption }
				state="no_conversions"
				signalCount={ attempts }
				body={ __(
					'No paywall conversions in this window. Your paywall reached {N} readers, but none completed a paid subscription within the 14-day attribution window. Worth a look at your checkout flow or pricing. See the per-gate breakdown below.',
					'newspack-plugin'
				) }
			/>
		);
	}

	return (
		<section className="newspack-insights__section newspack-insights__section--paid-reader" aria-labelledby={ HEADING_ID }>
			<SectionHeading id={ HEADING_ID } title={ title } description={ caption } />
			<div className="newspack-insights__metric-grid">
				<MetricCard
					{ ...scalarToMetricCardProps( {
						label: __( 'Paywall Conversion (Direct)', 'newspack-plugin' ),
						description: __(
							'Sessions with a subscription after a paywall impression ÷ sessions with a paywall impression',
							'newspack-plugin'
						),
						current: current.paywall_conversion_direct,
						previous: previous?.paywall_conversion_direct,
						zeroFallback: {
							numerator: current.paywall_conversion_direct.numerator ?? undefined,
							denominator: current.paywall_conversion_direct.denominator ?? undefined,
							attemptsLabel,
						},
					} ) }
				/>
				<MetricCard
					{ ...scalarToMetricCardProps( {
						label: __( 'Paywall Conversion (Influenced, 14d)', 'newspack-plugin' ),
						description: __(
							'Readers who subscribed in a later session within 14 days of seeing a paywall ÷ readers who saw a paywall',
							'newspack-plugin'
						),
						current: current.paywall_conversion_influenced_14d,
						previous: previous?.paywall_conversion_influenced_14d,
						zeroFallback: {
							numerator: current.paywall_conversion_influenced_14d.numerator ?? undefined,
							denominator: current.paywall_conversion_influenced_14d.denominator ?? undefined,
							attemptsLabel,
						},
					} ) }
				/>
				<MetricCard
					{ ...scalarToMetricCardProps( {
						label: __( 'Total Paywall Revenue (Direct)', 'newspack-plugin' ),
						description: __(
							'Sum of Woo order totals from subscriptions completed in the same session as a paywall impression',
							'newspack-plugin'
						),
						current: current.total_paywall_revenue_direct,
						previous: previous?.total_paywall_revenue_direct,
						// Currency total: conversions ride on the scalar's `denominator`;
						// attempts come from the section total — but only when the Direct
						// scalar computed. Otherwise `attempts` is an unreliable 0, so pass
						// undefined and let the card render its own value/error treatment
						// instead of a misleading "No paywall attempts".
						zeroFallback: {
							numerator: current.total_paywall_revenue_direct.denominator ?? undefined,
							denominator: dataKnown ? attempts : undefined,
							currencyRole: 'total',
							attemptsLabel,
							conversionsLabel,
						},
					} ) }
				/>
				<MetricCard
					{ ...scalarToMetricCardProps( {
						label: __( 'Avg Revenue per Paywall Conversion', 'newspack-plugin' ),
						description: __( 'Total paywall revenue ÷ paywall conversions', 'newspack-plugin' ),
						current: current.avg_revenue_per_paywall_conversion,
						previous: previous?.avg_revenue_per_paywall_conversion,
						zeroFallback: {
							numerator: current.avg_revenue_per_paywall_conversion.denominator ?? undefined,
							denominator: dataKnown ? attempts : undefined,
							currencyRole: 'average',
							attemptsLabel,
							conversionsLabel,
						},
					} ) }
				/>
			</div>
		</section>
	);
};

export default PaidReaderConversionSection;
