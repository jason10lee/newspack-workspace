/**
 * RSM Layer 2 cell renderers: applied-policy chips and base → effective price.
 *
 * These read only the `policy` field of a product row, which comes from the PHP
 * integration seam (Subscription_Policy_Resolver). When that seam swaps from mock
 * data to the real policy-engine read API, these components need no change.
 */

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Icon, check } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Badge } from '../../../../../packages/components/src';

/**
 * Format a number as a currency amount using the store currency.
 */
export function formatAmount( amount: number, currency: SubscriptionProductsCurrency ): string {
	return `${ currency.symbol }${ amount.toFixed( currency.decimals ) }`;
}

/**
 * Renders the applied pricing policies as chips, visually distinguishing the
 * winning policy (the one that sets the effective price).
 */
export function PolicyChips( { policy }: { policy: SubscriptionPolicyResolution } ) {
	if ( ! policy?.policies?.length ) {
		return <span className="newspack-subscription-products__muted">{ __( 'No policies', 'newspack-plugin' ) }</span>;
	}

	return (
		<div className="newspack-subscription-products__policy-chips">
			{ policy.policies.map( p => (
				<span
					key={ p.id }
					className={ classnames( 'newspack-subscription-products__policy-chip', `is-${ p.type }`, {
						'is-winning': p.is_winning,
					} ) }
					title={ `${ p.label } — ${ p.adjustment_label }` }
				>
					{ p.is_winning && <Icon icon={ check } size={ 16 } /> }
					<Badge level={ p.is_winning ? 'success' : 'default' } text={ p.label } />
				</span>
			) ) }
		</div>
	);
}

/**
 * Renders the base price and, when a policy changes it, the resulting effective price.
 */
export function EffectivePrice( { policy, currency }: { policy: SubscriptionPolicyResolution; currency: SubscriptionProductsCurrency } ) {
	if ( ! policy || policy.base_price === null || policy.base_price === undefined ) {
		return <span className="newspack-subscription-products__muted">&mdash;</span>;
	}

	const baseLabel = formatAmount( policy.base_price, currency );
	const changed = policy.effective_price !== policy.base_price;

	if ( ! changed ) {
		return <strong>{ baseLabel }</strong>;
	}

	return (
		<span className="newspack-subscription-products__effective-price">
			<span className="newspack-subscription-products__base-price">{ baseLabel }</span>
			<span aria-hidden="true"> → </span>
			<strong className="newspack-subscription-products__effective-price-value">{ formatAmount( policy.effective_price, currency ) }</strong>
		</span>
	);
}
