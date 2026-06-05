/**
 * DirectVsInfluencedCallout (NPPD-1604).
 *
 * Small dismissable info callout immediately below the Section 1
 * caption, explaining the Direct vs Influenced distinction used by
 * Sections 2 and 3. Per spec, dismissal is session-only (no persisted
 * "don't show again" — the callout reappears on page reload).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Icon, closeSmall, info } from '@wordpress/icons';

const DirectVsInfluencedCallout = () => {
	const [ visible, setVisible ] = useState( true );
	if ( ! visible ) {
		return null;
	}
	return (
		<div className="newspack-insights__gates-callout" role="note">
			<Icon icon={ info } className="newspack-insights__gates-callout-icon" />
			<div className="newspack-insights__gates-callout-body">
				<p className="newspack-insights__gates-callout-title">
					<strong>{ __( 'About Direct vs Influenced conversion', 'newspack-plugin' ) }</strong>
				</p>
				<p>
					<strong>{ __( 'Direct', 'newspack-plugin' ) }</strong>{ ' ' }
					{ __(
						'conversions are tagged to a specific gate at the moment of conversion (a gate_post_id is captured on the registration or checkout event).',
						'newspack-plugin'
					) }
				</p>
				<p>
					<strong>{ __( 'Influenced', 'newspack-plugin' ) }</strong>{ ' ' }
					{ __(
						'conversions count readers who saw a gate within a lookback window (7 days for free, 14 days for paid) but converted later, possibly elsewhere on the site.',
						'newspack-plugin'
					) }
				</p>
				<p>
					{ __(
						'Influenced is broader than Direct. Use Direct for "this specific gate drove this specific conversion" attribution; use Influenced for "gates contributed to this conversion somewhere in the reader’s journey."',
						'newspack-plugin'
					) }
				</p>
			</div>
			<button
				type="button"
				className="newspack-insights__gates-callout-dismiss"
				onClick={ () => setVisible( false ) }
				aria-label={ __( 'Dismiss callout', 'newspack-plugin' ) }
			>
				<Icon icon={ closeSmall } />
			</button>
		</div>
	);
};

export default DirectVsInfluencedCallout;
