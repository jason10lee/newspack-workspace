/**
 * Eligible-audience preview for the rule editor: how many existing subscribers
 * the rule catches at renewal vs. how many the cohort gate protects. Debounce-
 * POSTs the unsaved scope + conditions to the plugin's audience route and renders
 * one line. Subscriptions-only — the route returns no `audience` block without
 * WooCommerce Subscriptions, and we render nothing.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const AUDIENCE_PATH = '/wc-dynamic-pricing/v1/rules/audience';
const DEBOUNCE_MS = 500;

interface RuleAudienceProps {
	scopeType: string;
	scopeIds: number[];
	conditions: { [ id: string ]: boolean | number | null };
	application?: string;
	ruleId?: number;
}

export default function RuleAudience( { scopeType, scopeIds, conditions, application, ruleId }: RuleAudienceProps ) {
	const [ audience, setAudience ] = useState< RuleAudienceData | null >( null );
	const [ isLoading, setIsLoading ] = useState( false );
	const timer = useRef< ReturnType< typeof setTimeout > >();

	const scopeKey = JSON.stringify( scopeIds );
	const condKey = JSON.stringify( conditions );

	useEffect( () => {
		if ( timer.current ) {
			clearTimeout( timer.current );
		}
		let cancelled = false;
		timer.current = setTimeout( () => {
			setIsLoading( true );
			apiFetch< RuleAudienceResponse >( {
				path: AUDIENCE_PATH,
				method: 'POST',
				data: { id: ruleId, scope_type: scopeType, scope_ids: scopeIds, conditions, application },
			} )
				.then( res => {
					if ( ! cancelled ) {
						setAudience( res.audience ?? null );
					}
				} )
				.catch( () => {
					if ( ! cancelled ) {
						setAudience( null );
					}
				} )
				.finally( () => {
					if ( ! cancelled ) {
						setIsLoading( false );
					}
				} );
		}, DEBOUNCE_MS );
		return () => {
			cancelled = true;
			if ( timer.current ) {
				clearTimeout( timer.current );
			}
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ scopeType, scopeKey, condKey, application, ruleId ] );

	if ( ! audience?.supported ) {
		return null;
	}

	const total = audience.count_limited ? `${ audience.total }+` : `${ audience.total }`;
	const caughtLabel = 'locked' === audience.application ? __( 'in cohort', 'newspack-plugin' ) : __( 'eligible at renewal', 'newspack-plugin' );

	return (
		<p className={ `newspack-pricing-rules__audience${ isLoading ? ' is-loading' : '' }` }>
			{ sprintf(
				/* translators: 1: total subscribers, 2: caught count, 3: caught label, 4: protected count. */
				__( 'Existing subscribers in scope: %1$s — %2$s %3$s · %4$s protected', 'newspack-plugin' ),
				total,
				String( audience.caught ),
				caughtLabel,
				String( audience.protected )
			) }
		</p>
	);
}
