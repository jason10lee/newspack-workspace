/**
 * Insights feedback API client (NPPD-1728).
 *
 * Thin wrapper around `@wordpress/api-fetch` for the single feedback
 * endpoint: `POST /newspack-insights/v1/feedback`. The server stamps
 * attribution (publisher domain) and routes the record to Slack via the
 * Manager relay (or the email fallback) — the client only submits.
 *
 * Slice 1 sends the tier-1 thumb (`context` + `sentiment`). `comment` is part
 * of the payload contract so Slice 2's tier-2 form can populate it without an
 * API change; it's optional and omitted by the tier-1 thumb.
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
	/** Tier-2 freeform comment (Slice 2); omitted by the tier-1 thumb. */
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
