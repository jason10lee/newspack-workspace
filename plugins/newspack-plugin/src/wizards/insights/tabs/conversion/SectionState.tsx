/**
 * SectionState (NPPD-1609, Phase 2) — tab-local to Conversion Journey.
 *
 * Renders the right treatment for a collection metric's `state`:
 *   - 'error'       → a publisher-friendly error note (no internal codes)
 *   - 'empty'       → the section's own "no data yet" copy
 *   - 'coming_soon' → a "Coming soon" placeholder distinct from empty/error
 *   - 'populated'   → the section's content (children)
 *
 * Mirrors the tab-local Prompts `SectionState` and adds the
 * `coming_soon` arm for Phase-B deferred metrics.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Icon, caution, scheduled } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { ConversionMetricState } from '../../api/conversion';
import SectionEmpty from '../components/SectionEmpty';

/** Publisher-facing copy for a section that failed to load (no internal codes). */
export const SECTION_ERROR_MESSAGE = __( 'Unable to load this section. Newspack Manager may need attention.', 'newspack-plugin' );

export interface SectionStateProps {
	state: ConversionMetricState;
	/** Copy shown when the query succeeded with no rows. */
	emptyMessage: string;
	children: React.ReactNode;
}

const SectionState = ( { state, emptyMessage, children }: SectionStateProps ) => {
	if ( state === 'error' ) {
		return (
			<p className="newspack-insights__section-error" role="alert">
				<Icon icon={ caution } size={ 20 } />
				<span>{ SECTION_ERROR_MESSAGE }</span>
			</p>
		);
	}
	if ( state === 'empty' ) {
		return <SectionEmpty>{ emptyMessage }</SectionEmpty>;
	}
	if ( state === 'coming_soon' ) {
		return (
			<p className="newspack-insights__section-coming-soon" role="note">
				<Icon icon={ scheduled } size={ 20 } />
				<span>{ __( 'Coming soon. This metric is being built and will be available in a future update.', 'newspack-plugin' ) }</span>
			</p>
		);
	}
	return <>{ children }</>;
};

export default SectionState;
