/**
 * SubscribersTab (NPPD-1616).
 *
 * Orchestrates the Tab 6 view: fetches data for the active range +
 * comparison range, then composes the classification banner + five
 * sections (scorecard, revenue, tenure, performance, cancellations).
 *
 * Loading / error states are local to this tab; the wizard chrome
 * (date picker, comparison toggle, tab navigation) stays interactive
 * while the tab body is in any state.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import useSubscribersData from '../hooks/useSubscribersData';
import ClassificationBanner from './subscribers/ClassificationBanner';
import ScorecardSection from './subscribers/ScorecardSection';
import RevenueSection from './subscribers/RevenueSection';
import TenureSection from './subscribers/TenureSection';
import PerformanceSection from './subscribers/PerformanceSection';
import CancellationReasonsSection from './subscribers/CancellationReasonsSection';
import './subscribers/subscribers.scss';

export interface SubscribersTabProps {
	range: DateRange;
	previousRange: DateRange | null;
}

const SubscribersTab = ( { range, previousRange }: SubscribersTabProps ) => {
	const { status, data, error } = useSubscribersData( range, previousRange );

	if ( status === 'loading' && ! data ) {
		return (
			<div className="newspack-insights__tab-loading" role="status" aria-live="polite">
				{ __( 'Loading subscriber data…', 'newspack-plugin' ) }
			</div>
		);
	}

	if ( status === 'error' ) {
		return (
			<div className="newspack-insights__tab-error" role="alert">
				<p>{ __( 'Could not load subscriber data.', 'newspack-plugin' ) }</p>
				{ error && <p className="newspack-insights__tab-error-detail">{ error }</p> }
			</div>
		);
	}

	if ( ! data ) {
		return null;
	}

	return (
		<div className="newspack-insights__subscribers-tab">
			<ClassificationBanner classification={ data.classification } />
			<ScorecardSection snapshot={ data.snapshot } current={ data.current } previous={ data.previous } />
			<RevenueSection current={ data.current } previous={ data.previous } />
			<TenureSection rows={ data.snapshot.tenure_distribution } />
			<PerformanceSection rows={ data.current.performance_by_product } />
			<CancellationReasonsSection rows={ data.current.cancellation_reasons } />
		</div>
	);
};

export default SubscribersTab;
