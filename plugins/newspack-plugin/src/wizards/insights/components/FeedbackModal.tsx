/**
 * FeedbackModal — tier-2 freeform feedback (NPPD-1728).
 *
 * The light modal that expands from a tier-1 thumb: a single optional freeform
 * textarea with a sentiment-aware prompt — 👍 asks what's useful, 👎 asks what's
 * missing or frustrating. Keeps the publisher on the page.
 *
 * Freeform, not multiple-choice: routing is Slack-only, so a human reads every
 * message and fixed categories buy no aggregation; freeform captures the
 * surprise insight a fixed list can't; and the taxonomy is better designed in
 * v2 from real answers than guessed now.
 *
 * The modal owns its own field state, plus the `submitting` / `errored` props
 * the parent (`TabFeedback`) drives: on a submit failure the parent keeps the
 * modal open so the publisher's typed comment survives a retry. Resolution
 * follows the one-record model — submitting sends the comment, dismissing
 * (X / Esc / overlay / Skip) sends a sentiment-only record.
 *
 * Assembled from `@wordpress/components` (the upstream of the newspack
 * design-system wrappers) — consistent with how `CooldownNotice` and
 * `InfoCallout` use WP-core Notice directly, and avoiding the router coupling
 * the newspack Button wrapper carries.
 *
 * User-facing copy is finalized (NPPD-1728), isolated in the COPY block.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Modal, TextareaControl, Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { FeedbackSentiment } from '../api/feedback';

/**
 * User-facing copy (NPPD-1728). The two prompt strings are sentiment-aware; the
 * rest is shared.
 */
const COPY = {
	title: __( 'Tell us more', 'newspack-plugin' ),
	// The thumb rating is the feedback; this comment is optional. Because
	// skipping or closing the tab still sends the rating (and the close-tab path
	// has no UI of its own), say so up front so "Skip" / closing never reads as
	// "discard".
	intro: __( 'Your rating has been recorded. While optional, we’d love to hear more.', 'newspack-plugin' ),
	promptPositive: __( 'What do you find most useful here?', 'newspack-plugin' ),
	promptNegative: __( 'What’s missing or frustrating about this tab?', 'newspack-plugin' ),
	submit: __( 'Send comment', 'newspack-plugin' ),
	skip: __( 'Skip', 'newspack-plugin' ),
	error: __( 'Could not send. Try again.', 'newspack-plugin' ),
};

export interface FeedbackDetail {
	comment: string;
}

export interface FeedbackModalProps {
	/** Thumb sentiment — selects the prompt wording. */
	sentiment: FeedbackSentiment;
	/** A submit is in flight — disable the submit control. */
	submitting?: boolean;
	/** The last submit failed — show a retry error (the comment is preserved). */
	errored?: boolean;
	/** Submit with the freeform comment. */
	onSubmit: ( detail: FeedbackDetail ) => void;
	/** Dismiss (X / Esc / overlay / Skip) — resolves sentiment-only. */
	onDismiss: () => void;
}

const FeedbackModal = ( { sentiment, submitting = false, errored = false, onSubmit, onDismiss }: FeedbackModalProps ) => {
	const [ comment, setComment ] = useState( '' );
	const prompt = 'up' === sentiment ? COPY.promptPositive : COPY.promptNegative;
	// The intro carries the load-bearing "Skip isn't discard" reassurance, so
	// wire it to the dialog's accessible description (WP Modal forwards
	// `aria.describedby` onto the dialog) — otherwise a screen reader hears only
	// the title and can skip past it.
	const introId = `newspack-insights__feedback-modal-intro-${ sentiment }`;

	return (
		<Modal title={ COPY.title } onRequestClose={ onDismiss } className="newspack-insights__feedback-modal" aria={ { describedby: introId } }>
			<p id={ introId } className="newspack-insights__feedback-modal-intro">
				{ COPY.intro }
			</p>
			<TextareaControl className="newspack-insights__feedback-modal-comment" label={ prompt } value={ comment } onChange={ setComment } />
			{ errored && (
				// role="alert" so the failure is announced (WP Notice is not a live
				// region on its own) and the publisher knows to retry.
				<div className="newspack-insights__feedback-modal-error" role="alert">
					<Notice status="error" isDismissible={ false }>
						{ COPY.error }
					</Notice>
				</div>
			) }
			<div className="newspack-insights__feedback-modal-actions">
				<Button variant="tertiary" onClick={ onDismiss }>
					{ COPY.skip }
				</Button>
				<Button variant="primary" isBusy={ submitting } disabled={ submitting } onClick={ () => onSubmit( { comment } ) }>
					{ COPY.submit }
				</Button>
			</div>
		</Modal>
	);
};

export default FeedbackModal;
