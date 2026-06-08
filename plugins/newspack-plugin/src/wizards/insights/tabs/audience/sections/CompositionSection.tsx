/**
 * Audience › Audience composition (NPPD-1649, Section 2).
 *
 * Who your readers are — subscribers, logged-in, devices.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/audience';
import ChartCard from '../../components/ChartCard';
import { toSeries } from '../../components/metrics';
import PieChart from '../viz/PieChart';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const CompositionSection = ( { current }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-composition">
		<h2 id="newspack-insights-audience-composition" className="newspack-insights__section-heading">
			{ __( 'Audience composition', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">
			{ __( 'Who your readers are — subscribers, logged-in status, and devices.', 'newspack-plugin' ) }
		</p>
		<div className="newspack-insights__chart-grid newspack-insights__chart-grid--cols-4">
			<ChartCard title={ __( 'Newsletter Subscriber Composition', 'newspack-plugin' ) } payload={ current.newsletter_subscriber_composition }>
				<PieChart segments={ toSeries( current.newsletter_subscriber_composition, 'label', 'value' ) } />
			</ChartCard>
			<ChartCard title={ __( 'Logged-In vs Anonymous', 'newspack-plugin' ) } payload={ current.logged_in_vs_anonymous_composition }>
				<PieChart segments={ toSeries( current.logged_in_vs_anonymous_composition, 'label', 'value' ) } />
			</ChartCard>
			<ChartCard title={ __( 'Device Breakdown', 'newspack-plugin' ) } payload={ current.device_breakdown }>
				<PieChart segments={ toSeries( current.device_breakdown, 'device', 'readers' ) } />
			</ChartCard>
			<ChartCard
				title={ __( 'Supporter Type', 'newspack-plugin' ) }
				caption={ __( 'Subscribers, donors, and registered readers among your logged-in audience.', 'newspack-plugin' ) }
				payload={ current.supporter_type }
			>
				<PieChart segments={ toSeries( current.supporter_type, 'label', 'value' ) } />
			</ChartCard>
		</div>
	</section>
);

export default CompositionSection;
