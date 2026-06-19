/**
 * TabFeedback — per-tab feedback affordance (NPPD-1728).
 *
 * Footer-anchored "Was this tab useful?" thumb that, on click, stages the
 * sentiment and expands the tier-2 freeform modal. It carries the tab id as
 * `context` so feedback is attributed per tab, and fires an immediate Snackbar
 * acknowledgment on resolve. Styled to the muted MetricNote scale so it reads
 * as native chrome.
 *
 * One record per event (the deliberate data model — keeps sentiment and the
 * comment on the same row, posts to Slack once):
 *   - Thumb click stages the sentiment and opens the modal; nothing is posted
 *     yet.
 *   - Submitting the modal posts the record (sentiment + comment); dismissing
 *     it (Cancel / Esc / overlay) posts a sentiment-only record; closing the
 *     tab mid-modal posts sentiment-only via `navigator.sendBeacon`.
 *   - On a submit failure the modal stays open so the typed comment survives a
 *     retry; only a successful submit (or a dismiss) closes it.
 *
 * The thumb always opens the modal — there's no rate-limit. A publisher who
 * only wants to react dismisses in one click and their sentiment still lands,
 * and anyone with detailed feedback is never locked out (NPPD-1728 decision:
 * no cooldown).
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
import FeedbackModal, { type FeedbackDetail } from './FeedbackModal';
import { submitFeedback, beaconSentiment, type FeedbackSentiment } from '../api/feedback';

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
	/** Absolute REST URL for the abandon beacon. */
	beaconUrl: string;
	/** `wp_rest` nonce for the abandon beacon. */
	beaconNonce: string;
}

const TabFeedback = ( { context, beaconUrl, beaconNonce }: TabFeedbackProps ) => {
	const [ status, setStatus ] = useState< Status >( 'idle' );
	const [ chosen, setChosen ] = useState< FeedbackSentiment | null >( null );
	const [ modalOpen, setModalOpen ] = useState( false );
	const [ showAck, setShowAck ] = useState( false );

	// Refs that async resolution / the unload beacon read without re-binding:
	// the staged sentiment, a one-shot guard so a record posts exactly once per
	// event, and a mount flag so post-await setters don't run after unmount.
	const chosenRef = useRef< FeedbackSentiment | null >( null );
	const resolvedRef = useRef( false );
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

	// Abandoner: the publisher who opens the modal and closes the tab without
	// resolving. A normal fetch wouldn't survive unload, so post sentiment-only
	// via sendBeacon. Skip bfcache (`event.persisted`) — the page may be
	// restored with the modal still open, and sending then would lock out the
	// real submit. Guarded so it never double-posts with a real resolve.
	//
	// `pagehide` (not `visibilitychange`) on purpose: visibilitychange→hidden
	// also fires on an ordinary tab switch, which would beacon prematurely and
	// lock out a publisher who tabs away and comes back to finish. That trade
	// favors this desktop-admin surface; the cost is missing the rare mobile
	// background-then-discard abandon, which is acceptable here.
	useEffect( () => {
		if ( ! modalOpen ) {
			return;
		}
		const onHide = ( event: PageTransitionEvent ) => {
			if ( event.persisted || resolvedRef.current || ! chosenRef.current ) {
				return;
			}
			resolvedRef.current = true;
			beaconSentiment( { context, sentiment: chosenRef.current }, beaconUrl, beaconNonce );
		};
		window.addEventListener( 'pagehide', onHide );
		return () => window.removeEventListener( 'pagehide', onHide );
	}, [ modalOpen, context, beaconUrl, beaconNonce ] );

	// Post the single record for this event. `detail` is empty for a
	// sentiment-only resolve (modal skipped). `isSubmit` distinguishes an
	// explicit "Send comment" from a skip/dismiss: a submit shows the ack on
	// success and keeps the modal open on failure (so the typed comment survives
	// a retry); a skip is silent (no ack — the rating still lands) and just
	// closes on failure since there's nothing to preserve.
	const resolve = useCallback(
		async ( detail: FeedbackDetail, isSubmit: boolean ): Promise< void > => {
			if ( resolvedRef.current || ! chosenRef.current ) {
				return;
			}
			resolvedRef.current = true;
			setStatus( 'submitting' );
			try {
				await submitFeedback( { context, sentiment: chosenRef.current, comment: detail.comment } );
				if ( ! mountedRef.current ) {
					return;
				}
				setModalOpen( false );
				setStatus( 'submitted' );
				if ( isSubmit ) {
					setShowAck( true );
				}
			} catch {
				if ( ! mountedRef.current ) {
					return;
				}
				// Re-open the control for a retry; the failure is logged
				// server-side by the router.
				setStatus( 'error' );
				resolvedRef.current = false;
				if ( ! isSubmit ) {
					setModalOpen( false );
					chosenRef.current = null;
					setChosen( null );
				}
			}
		},
		[ context ]
	);

	// A vote is in motion or recorded — lock the thumbs. An error on dismiss
	// re-opens them.
	const locked = chosen !== null;

	const handleVote = ( sentiment: FeedbackSentiment ): void => {
		// Gate on the ref (set synchronously) so a same-tick double-click can't
		// open two modals before `chosen` state settles.
		if ( chosenRef.current ) {
			return;
		}
		chosenRef.current = sentiment;
		resolvedRef.current = false;
		setChosen( sentiment );
		setModalOpen( true );
	};

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
				{ status === 'error' && ! modalOpen && (
					<span className="newspack-insights__tab-feedback-error" role="alert">
						{ COPY.error }
					</span>
				) }
			</div>
			{ modalOpen && chosen && (
				<FeedbackModal
					sentiment={ chosen }
					submitting={ status === 'submitting' }
					errored={ status === 'error' }
					onSubmit={ detail => resolve( detail, true ) }
					onDismiss={ () => resolve( { comment: '' }, false ) }
				/>
			) }
			{ showAck && (
				<div className="newspack-insights__tab-feedback-snackbar" role="status">
					<Snackbar onRemove={ () => setShowAck( false ) }>{ COPY.ack }</Snackbar>
				</div>
			) }
		</div>
	);
};

export default TabFeedback;
