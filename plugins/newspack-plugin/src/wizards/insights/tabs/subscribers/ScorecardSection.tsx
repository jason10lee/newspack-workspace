/**
 * ScorecardSection (NPPD-1616).
 *
 * Top-line subscriber numbers. Three "snapshot" cards (active subs,
 * MRR, ARR) that don't depend on the window, plus two windowed cards
 * (new / churned) that show a delta against the previous window when
 * comparison mode is on.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { SubscribersSnapshot, SubscribersWindow } from '../../api/subscribers';
import MetricCard from './MetricCard';

export interface ScorecardSectionProps {
	snapshot: SubscribersSnapshot;
	current: SubscribersWindow;
	previous: SubscribersWindow | null;
}

const ScorecardSection = ( { snapshot, current, previous }: ScorecardSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--scorecard" aria-labelledby="newspack-insights-scorecard-heading">
		<h2 id="newspack-insights-scorecard-heading" className="newspack-insights__section-heading">
			{ __( 'Subscribers at a glance', 'newspack-plugin' ) }
		</h2>
		<div className="newspack-insights__metric-grid">
			<MetricCard
				primary
				label={ __( 'Active subscribers', 'newspack-plugin' ) }
				value={ snapshot.active_subscribers }
				format="number"
				description={ __( 'Distinct customers with at least one active non-donation subscription', 'newspack-plugin' ) }
			/>
			<MetricCard
				primary
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
				label={ __( 'New subscribers', 'newspack-plugin' ) }
				value={ current.new_subscribers }
				format="number"
				previousValue={ previous?.new_subscribers }
				description={ __( 'First-time non-donation subscribers in selected timeframe', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Churned subscribers', 'newspack-plugin' ) }
				value={ current.churned_subscribers }
				format="number"
				previousValue={ previous?.churned_subscribers }
				lowerIsBetter
				description={ __( 'Lost all active subscriptions in selected timeframe', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Upcoming renewals (30d)', 'newspack-plugin' ) }
				value={ snapshot.upcoming_renewals_30d.count }
				format="number"
				description={ __( 'Count of active subs renewing in the next 30 days', 'newspack-plugin' ) }
			/>
		</div>
	</section>
);

export default ScorecardSection;
