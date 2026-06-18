/**
 * PaidReaderConversionSection (NPPD-1607, Section 4).
 *
 * Four scorecards covering paid-conversion intents — donation and
 * subscription — each in Direct and Influenced (14-day lookback)
 * attribution. Completion (not just attempt) is established via the
 * Woo join in Phase 2.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { PromptsWindow } from '../../api/prompts';
import MetricCard from '../components/MetricCard';
import SectionHeading from '../components/SectionHeading';
import { NOT_CAPABLE_COPY } from './notCapableCopy';
import { NOT_COMPUTABLE_COPY } from './notComputableCopy';
import { scalarToMetricCardProps } from './scalarToCard';

export interface PaidReaderConversionSectionProps {
	current: PromptsWindow;
	previous: PromptsWindow | null;
}

const PaidReaderConversionSection = ( { current, previous }: PaidReaderConversionSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--paid-reader" aria-labelledby="newspack-insights-prompts-paid-heading">
		<SectionHeading
			id="newspack-insights-prompts-paid-heading"
			title={ __( 'Paid reader conversion', 'newspack-plugin' ) }
			description={ __(
				'How effectively prompts convert readers into donors and subscribers. Direct counts conversions in the same session as a prompt impression. Influenced counts conversions in a later session within 14 days of seeing a prompt.',
				'newspack-plugin'
			) }
		/>
		<div className="newspack-insights__metric-grid">
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Donation Conversion (Direct)', 'newspack-plugin' ),
					description: __(
						'Sessions with a completed donation after a donation-intent prompt impression ÷ sessions with a donation-intent prompt impression',
						'newspack-plugin'
					),
					current: current.donation_conversion_direct,
					previous: previous?.donation_conversion_direct,
					notCapableMessage: NOT_CAPABLE_COPY.donation,
					notComputableMessage: NOT_COMPUTABLE_COPY.donation,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Donation Conversion (Influenced, 14d)', 'newspack-plugin' ),
					description: __(
						'Readers who completed a donation in a later session within 14 days of seeing a donation-intent prompt ÷ readers who saw a donation-intent prompt',
						'newspack-plugin'
					),
					current: current.donation_conversion_influenced_14d,
					previous: previous?.donation_conversion_influenced_14d,
					notCapableMessage: NOT_CAPABLE_COPY.donation,
					notComputableMessage: NOT_COMPUTABLE_COPY.donation,
				} ) }
			/>
			{ /* Block-name vs intent-name asymmetry: NOT_CAPABLE keys off the block that
			     grants capability (checkout); NOT_COMPUTABLE keys off the metric's intent
			     (subscription). Same pattern in RevenueFromPromptsSection. */ }
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Subscription Conversion (Direct)', 'newspack-plugin' ),
					description: __(
						'Sessions with a completed subscription after a subscription-intent prompt impression ÷ sessions with a subscription-intent prompt impression',
						'newspack-plugin'
					),
					current: current.subscription_conversion_direct,
					previous: previous?.subscription_conversion_direct,
					notCapableMessage: NOT_CAPABLE_COPY.checkout,
					notComputableMessage: NOT_COMPUTABLE_COPY.subscription,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Subscription Conversion (Influenced, 14d)', 'newspack-plugin' ),
					description: __(
						'Readers who completed a subscription in a later session within 14 days of seeing a subscription-intent prompt ÷ readers who saw a subscription-intent prompt',
						'newspack-plugin'
					),
					current: current.subscription_conversion_influenced_14d,
					previous: previous?.subscription_conversion_influenced_14d,
					notCapableMessage: NOT_CAPABLE_COPY.checkout,
					notComputableMessage: NOT_COMPUTABLE_COPY.subscription,
				} ) }
			/>
		</div>
	</section>
);

export default PaidReaderConversionSection;
