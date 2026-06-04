/**
 * WindowedSection (NPPD-1616).
 *
 * All Tab 6 metrics that ARE scoped to the date range picker:
 * new/churned subscriber counts, gross/net subscription revenue,
 * refund rate, and failed payment retry rate. Renders 6 MetricCards
 * with a dynamic section heading that mirrors the active preset
 * ("In the last 30 days", "This month", "From Sep 5 to Oct 5", etc.).
 *
 * The heading repeats the time scope contextually so the cards
 * underneath are unambiguously windowed — even though the wizard
 * chrome already shows the picker selection at the top of the page.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { SubscribersWindow } from '../../api/subscribers';
import type { DateRange } from '../../state/useDateRange';
import MetricCard from './MetricCard';

export interface WindowedSectionProps {
	range: DateRange;
	current: SubscribersWindow;
	previous: SubscribersWindow | null;
}

const parseISO = ( s: string ): Date => {
	const [ y, m, d ] = s.split( '-' ).map( Number );
	return new Date( y, m - 1, d );
};

const formatShortDate = ( s: string ): string => new Intl.DateTimeFormat( undefined, { month: 'short', day: 'numeric' } ).format( parseISO( s ) );

const getHeading = ( range: DateRange ): string => {
	switch ( range.preset ) {
		case 'last-7':
			return __( 'In the last 7 days', 'newspack-plugin' );
		case 'last-30':
			return __( 'In the last 30 days', 'newspack-plugin' );
		case 'last-90':
			return __( 'In the last 90 days', 'newspack-plugin' );
		case 'this-month':
			return __( 'This month', 'newspack-plugin' );
		case 'last-month':
			return __( 'Last month', 'newspack-plugin' );
		case 'custom':
		default:
			return sprintf(
				/* translators: 1: start date formatted like "Sep 5", 2: end date formatted like "Oct 5" */
				__( 'From %1$s to %2$s', 'newspack-plugin' ),
				formatShortDate( range.start ),
				formatShortDate( range.end )
			);
	}
};

const WindowedSection = ( { range, current, previous }: WindowedSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--windowed" aria-labelledby="newspack-insights-windowed-heading">
		<h2 id="newspack-insights-windowed-heading" className="newspack-insights__section-heading">
			{ getHeading( range ) }
		</h2>
		<div className="newspack-insights__metric-grid">
			<MetricCard
				label={ __( 'New subscribers', 'newspack-plugin' ) }
				value={ current.new_subscribers }
				format="number"
				previousValue={ previous?.new_subscribers }
				description={ __( 'First-time subscribers', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Churned subscribers', 'newspack-plugin' ) }
				value={ current.churned_subscribers }
				format="number"
				previousValue={ previous?.churned_subscribers }
				lowerIsBetter
				description={ __( 'Subscribers who churned in this timeframe', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Gross revenue', 'newspack-plugin' ) }
				value={ current.revenue_gross }
				format="currency"
				previousValue={ previous?.revenue_gross }
				description={ __( 'Subscription orders before refunds', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Net revenue', 'newspack-plugin' ) }
				value={ current.revenue_net }
				format="currency"
				previousValue={ previous?.revenue_net }
				description={ __( 'Gross minus refunds processed', 'newspack-plugin' ) }
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

export default WindowedSection;
