/**
 * FeedbackShipCallback — closing the loop (NPPD-1728).
 *
 * Renders the dismissible top-of-tab "you asked for X, we shipped it" callout
 * for a tab when one is configured, reusing the existing `InfoCallout`. The
 * registry (`lib/feedbackCallbacks`) is empty at launch, so this renders
 * nothing today — it's the plumbing, ready for the first feedback-driven ship.
 *
 * Dismissal persists per-publisher (InfoCallout's `storageKey` localStorage),
 * namespaced by tab + callback id so a later callback on the same tab
 * re-announces instead of inheriting an old dismissal.
 */

/**
 * Internal dependencies
 */
import type { TabKey } from './InsightsWizard';
import InfoCallout from '../tabs/components/InfoCallout';
import { getShipCallback } from '../lib/feedbackCallbacks';

export interface FeedbackShipCallbackProps {
	/** The tab id to look up a ship callback for. */
	context: TabKey;
}

const FeedbackShipCallback = ( { context }: FeedbackShipCallbackProps ) => {
	const callback = getShipCallback( context );
	if ( ! callback ) {
		return null;
	}
	return (
		<InfoCallout
			heading={ callback.heading }
			dismissible
			persist
			storageKey={ `feedback-callback-${ context }-${ callback.id }` }
			className="newspack-insights__feedback-callback"
		>
			<p>{ callback.body }</p>
		</InfoCallout>
	);
};

export default FeedbackShipCallback;
