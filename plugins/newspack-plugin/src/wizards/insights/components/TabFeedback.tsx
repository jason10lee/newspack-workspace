/**
 * TabFeedback — tier-1 per-tab feedback affordance (NPPD-1728, Slice 1).
 *
 * Footer-anchored "Was this tab useful?" prompt with a thumbs up / thumbs
 * down control, rendered into every Insights tab's footer by `TabSection`.
 * Carries the tab id as `context` so feedback is attributed per tab. Clicking
 * a thumb submits the sentiment to `/newspack-insights/v1/feedback` (the
 * server stamps the publisher domain and routes to Slack) and fires an
 * immediate Snackbar acknowledgment — never the void.
 *
 * Styled to the muted MetricNote scale (small, gray) so it reads as native
 * chrome. The thumbs use the `@wordpress/components` Button directly (icon +
 * isPressed accessibility, no router coupling), matching how `CooldownNotice`
 * uses the WP-core Notice directly.
 *
 * Slice 1 is the tier-1 thumb only: clicking a thumb submits sentiment
 * immediately. The tier-2 freeform comment modal lands in Slice 2.
 *
 * User-facing copy is finalized (NPPD-1728), isolated in the COPY block.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button, Snackbar } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { thumbsUp, thumbsDown } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { TabKey } from './InsightsWizard';
import { submitFeedback, type FeedbackSentiment } from '../api/feedback';

/**
 * User-facing copy (NPPD-1728).
 */
const COPY = {
	prompt: __( 'Was this tab useful?', 'newspack-plugin' ),
	thumbUp: __( 'Yes, this tab was useful', 'newspack-plugin' ),
	thumbDown: __( 'No, this tab was not useful', 'newspack-plugin' ),
	ack: __( 'Thanks for your feedback!', 'newspack-plugin' ),
	error: __( 'Could not send. Try again.', 'newspack-plugin' ),
};

/** How long the acknowledgment Snackbar stays up, in ms. */
const ACK_TIMEOUT_MS = 5000;

type Status = 'idle' | 'submitting' | 'submitted' | 'error';

export interface TabFeedbackProps {
	/** The tab id, carried through as the feedback `context`. */
	context: TabKey;
}

const TabFeedback = ( { context }: TabFeedbackProps ) => {
	const [ status, setStatus ] = useState< Status >( 'idle' );
	const [ chosen, setChosen ] = useState< FeedbackSentiment | null >( null );
	const [ showAck, setShowAck ] = useState( false );

	// A one-shot in-flight guard (set synchronously so a same-tick double-click
	// can't submit twice) and a mount flag so post-await setters don't run after
	// unmount.
	const inFlightRef = useRef( false );
	const mountedRef = useRef( true );

	useEffect(
		() => () => {
			mountedRef.current = false;
		},
		[]
	);

	// Auto-dismiss the acknowledgment after a beat.
	useEffect( () => {
		if ( ! showAck ) {
			return;
		}
		const timer = setTimeout( () => setShowAck( false ), ACK_TIMEOUT_MS );
		return () => clearTimeout( timer );
	}, [ showAck ] );

	const handleVote = useCallback(
		async ( sentiment: FeedbackSentiment ): Promise< void > => {
			if ( inFlightRef.current ) {
				return;
			}
			inFlightRef.current = true;
			setChosen( sentiment );
			setStatus( 'submitting' );
			try {
				await submitFeedback( { context, sentiment } );
				if ( ! mountedRef.current ) {
					return;
				}
				setStatus( 'submitted' );
				setShowAck( true );
			} catch {
				// Re-open the control for a retry; the failure is logged
				// server-side by the router.
				inFlightRef.current = false;
				if ( ! mountedRef.current ) {
					return;
				}
				setStatus( 'error' );
			}
		},
		[ context ]
	);

	// One signal per tab view: lock the thumbs once a vote is in flight or
	// recorded. An error re-opens them for a retry.
	const locked = status === 'submitting' || status === 'submitted';

	const promptId = `newspack-insights__tab-feedback-prompt-${ context }`;

	return (
		<div className="newspack-insights__tab-feedback">
			<div className="newspack-insights__tab-feedback-row" role="group" aria-labelledby={ promptId }>
				<span id={ promptId } className="newspack-insights__tab-feedback-prompt">
					{ COPY.prompt }
				</span>
				<div className="newspack-insights__tab-feedback-actions">
					<Button
						className="newspack-insights__tab-feedback-thumb"
						icon={ thumbsUp }
						label={ COPY.thumbUp }
						isPressed={ chosen === 'up' }
						disabled={ locked }
						accessibleWhenDisabled
						onClick={ () => handleVote( 'up' ) }
					/>
					<Button
						className="newspack-insights__tab-feedback-thumb"
						icon={ thumbsDown }
						label={ COPY.thumbDown }
						isPressed={ chosen === 'down' }
						disabled={ locked }
						accessibleWhenDisabled
						onClick={ () => handleVote( 'down' ) }
					/>
				</div>
				{ status === 'error' && (
					<span className="newspack-insights__tab-feedback-error" role="alert">
						{ COPY.error }
					</span>
				) }
			</div>
			{ showAck && (
				<div className="newspack-insights__tab-feedback-snackbar" role="status">
					<Snackbar onRemove={ () => setShowAck( false ) }>{ COPY.ack }</Snackbar>
				</div>
			) }
		</div>
	);
};

export default TabFeedback;
