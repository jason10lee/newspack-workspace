/**
 * Audience › Reach (NPPD-1649, Section 1).
 *
 * How many readers and sessions you reached in this timeframe.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/audience';
import Scorecard from '../../components/Scorecard';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const ReachSection = ( { current, previous }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-reach">
		<h2 id="newspack-insights-audience-reach" className="newspack-insights__section-heading">
			{ __( 'Reach', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">
			{ __( 'How many readers and sessions you reached in this timeframe.', 'newspack-plugin' ) }
		</p>
		{ /* Max 4 cards per row (NPPD-1649 fix #1): 3 + 2. */ }
		<div className="newspack-insights__metric-grid newspack-insights__metric-grid--cols-3">
			<Scorecard
				label={ __( 'Active Readers', 'newspack-plugin' ) }
				description={ __( 'Distinct readers in this timeframe', 'newspack-plugin' ) }
				current={ current.active_readers }
				previous={ previous?.active_readers }
			/>
			<Scorecard
				label={ __( 'Sessions', 'newspack-plugin' ) }
				description={ __( 'Total visits in this timeframe', 'newspack-plugin' ) }
				current={ current.sessions }
				previous={ previous?.sessions }
			/>
			<Scorecard
				label={ __( 'Pageviews', 'newspack-plugin' ) }
				description={ __( 'Total page loads in this timeframe', 'newspack-plugin' ) }
				current={ current.pageviews }
				previous={ previous?.pageviews }
			/>
		</div>
		<div className="newspack-insights__metric-grid newspack-insights__metric-grid--cols-2">
			<Scorecard
				label={ __( 'Avg Sessions per Reader', 'newspack-plugin' ) }
				description={ __( 'How often a typical reader comes back', 'newspack-plugin' ) }
				current={ current.avg_sessions_per_reader }
				previous={ previous?.avg_sessions_per_reader }
			/>
			<Scorecard
				label={ __( 'Engaged Session Rate', 'newspack-plugin' ) }
				description={ __( '% of sessions that were engaged', 'newspack-plugin' ) }
				current={ current.engaged_session_rate }
				previous={ previous?.engaged_session_rate }
			/>
		</div>
	</section>
);

export default ReachSection;
