/**
 * RevenueSection (NPPD-1616).
 *
 * In-window subscription revenue: gross, net (after refunds), and the
 * two health metrics — refund rate and failed-payment retry rate.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { SubscribersWindow } from '../../api/subscribers';
import MetricCard from './MetricCard';

export interface RevenueSectionProps {
	current: SubscribersWindow;
	previous: SubscribersWindow | null;
}

const RevenueSection = ( { current, previous }: RevenueSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--revenue" aria-labelledby="newspack-insights-revenue-heading">
		<h2 id="newspack-insights-revenue-heading" className="newspack-insights__section-heading">
			{ __( 'Subscription revenue', 'newspack-plugin' ) }
		</h2>
		<div className="newspack-insights__metric-grid">
			<MetricCard
				label={ __( 'Gross revenue', 'newspack-plugin' ) }
				value={ current.revenue_gross }
				format="currency"
				previousValue={ previous?.revenue_gross }
				description={ __( 'Subscription orders in selected timeframe (before refunds)', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Net revenue', 'newspack-plugin' ) }
				value={ current.revenue_net }
				format="currency"
				previousValue={ previous?.revenue_net }
				description={ __( 'Gross minus refunds processed in selected timeframe', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Refund rate', 'newspack-plugin' ) }
				value={ current.refund_rate }
				format="percent"
				previousValue={ previous?.refund_rate }
				lowerIsBetter
				description={ __( 'Refunds ÷ subscription orders', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Failed payment recovery', 'newspack-plugin' ) }
				value={ current.failed_payment_retry_rate }
				format="percent"
				previousValue={ previous?.failed_payment_retry_rate }
				description={ __( 'Recovered retries ÷ retry attempts', 'newspack-plugin' ) }
			/>
		</div>
	</section>
);

export default RevenueSection;
