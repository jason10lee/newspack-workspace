/**
 * Insights feedback API client (NPPD-1728).
 *
 * Thin wrapper around `@wordpress/api-fetch` for the single feedback
 * endpoint: `POST /newspack-insights/v1/feedback`. The server stamps
 * attribution (publisher domain) and routes the record to Slack via the
 * Manager relay (or the email fallback) — the client only submits.
 *
 * One record per event: the thumb stages `context` + `sentiment`; `comment`
 * carries the tier-2 text on submit, and is empty/omitted when the modal is
 * skipped or the tab is closed mid-modal.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

export type FeedbackSentiment = 'up' | 'down';

export interface FeedbackPayload {
	/** Insights tab id the feedback is about (the affordance's `context`). */
	context: string;
	/** Tier-1 thumb sentiment. */
	sentiment: FeedbackSentiment;
	/** Tier-2 freeform comment; empty/omitted when the modal is skipped. */
	comment?: string;
}

export interface FeedbackResponse {
	success: boolean;
}

const ENDPOINT = '/newspack-insights/v1/feedback';

/**
 * Submit a feedback record. Resolves on a 2xx; rejects (apiFetch throws) on a
 * non-2xx so the caller can surface a retry.
 */
export const submitFeedback = async ( payload: FeedbackPayload ): Promise< FeedbackResponse > =>
	apiFetch< FeedbackResponse >( {
		path: ENDPOINT,
		method: 'POST',
		data: payload,
	} );

/**
 * Send a sentiment-only record via `navigator.sendBeacon` for the abandoner
 * case — the publisher who opens the tier-2 modal and closes the tab without
 * resolving it. A normal fetch wouldn't survive unload; the beacon does.
 *
 * The absolute REST URL and a `wp_rest` nonce come from the boot config (the
 * page doesn't enqueue `window.wpApiSettings`, and a beacon can't set the
 * `X-WP-Nonce` header), so the nonce rides as a `_wpnonce` query param, which
 * WordPress cookie auth reads. Returns true if the beacon was queued.
 */
export const beaconSentiment = ( payload: FeedbackPayload, beaconUrl: string, nonce: string ): boolean => {
	if ( ! beaconUrl || ! nonce || typeof navigator === 'undefined' || ! navigator.sendBeacon ) {
		return false;
	}
	const url = `${ beaconUrl }?_wpnonce=${ encodeURIComponent( nonce ) }`;
	const blob = new Blob( [ JSON.stringify( payload ) ], { type: 'application/json' } );
	return navigator.sendBeacon( url, blob );
};
