/**
 * Audience › Time trends (NPPD-1649, Section 3).
 *
 * When your readers show up — over the period, by day, and by hour.
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
import { formatShortDate } from '../../components/format';
import LineChart from '../viz/LineChart';
import BarChart from '../viz/BarChart';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const TimeTrendsSection = ( { current }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-trends">
		<h2 id="newspack-insights-audience-trends" className="newspack-insights__section-heading">
			{ __( 'Time trends', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">
			{ __( 'When your readers show up over the period, by day of week, and by hour.', 'newspack-plugin' ) }
		</p>
		<div className="newspack-insights__chart-grid newspack-insights__chart-grid--cols-2">
			<ChartCard title={ __( 'Active Readers Over Time', 'newspack-plugin' ) } payload={ current.active_readers_over_time }>
				<LineChart points={ toSeries( current.active_readers_over_time, 'date', 'active_readers' ) } formatLabel={ formatShortDate } />
			</ChartCard>
			<ChartCard title={ __( 'New vs Returning Over Time', 'newspack-plugin' ) } payload={ current.new_vs_returning_over_time }>
				<LineChart points={ toSeries( current.new_vs_returning_over_time, 'date', 'readers' ) } formatLabel={ formatShortDate } />
			</ChartCard>
			<ChartCard title={ __( 'Readership by Day of Week', 'newspack-plugin' ) } payload={ current.readership_by_day_of_week }>
				<BarChart bars={ toSeries( current.readership_by_day_of_week, 'day_of_week', 'active_readers' ) } />
			</ChartCard>
			<ChartCard title={ __( 'Readership by Hour of Day', 'newspack-plugin' ) } payload={ current.readership_by_hour_of_day }>
				<BarChart bars={ toSeries( current.readership_by_hour_of_day, 'hour', 'active_readers' ) } />
			</ChartCard>
		</div>
	</section>
);

export default TimeTrendsSection;
