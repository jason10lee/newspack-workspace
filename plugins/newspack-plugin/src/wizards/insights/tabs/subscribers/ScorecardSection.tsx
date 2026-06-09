/**
 * ScorecardSection (NPPD-1616).
 *
 * "Subscribers at a glance" — current-state metrics that do NOT depend
 * on the date range picker. Active subscribers and MRR/ARR reflect
 * what's true right now; upcoming renewals (30d) is a forward-looking
 * snapshot of currently-active subscriptions but is also independent
 * of the picker.
 *
 * Window-scoped metrics (new/churned, gross/net revenue, refund rate,
 * retry rate) live in {@see WindowedSection} below this one.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { SubscribersSnapshot } from '../../api/subscribers';
import MetricCard from '../components/MetricCard';

export interface ScorecardSectionProps {
	snapshot: SubscribersSnapshot;
}

const ScorecardSection = ( { snapshot }: ScorecardSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--scorecard" aria-labelledby="newspack-insights-scorecard-heading">
		<h2 id="newspack-insights-scorecard-heading" className="newspack-insights__section-heading">
			{ __( 'Subscribers at a glance', 'newspack-plugin' ) }
		</h2>
		<div className="newspack-insights__metric-grid">
			<MetricCard
				label={ __( 'Active subscribers', 'newspack-plugin' ) }
				value={ snapshot.active_subscribers }
				format="number"
				description={ __( 'Distinct customers with at least one active subscription', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Monthly recurring revenue', 'newspack-plugin' ) }
				value={ snapshot.mrr }
				format="currency"
				description={ __( 'Normalized across billing periods', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Annual recurring revenue', 'newspack-plugin' ) }
				value={ snapshot.arr }
				format="currency"
				description={ __( 'MRR × 12', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Upcoming renewals (30d)', 'newspack-plugin' ) }
				value={ snapshot.upcoming_renewals_30d.count }
				format="number"
				description={ __( 'Active subscriptions due to renew in the next 30 days', 'newspack-plugin' ) }
			/>
		</div>
	</section>
);

export default ScorecardSection;
