/**
 * RSM Layer 2 cell renderers: applied-policy chips and base → effective price.
 *
 * These read only the `policy` field of a product row, which comes from the PHP
 * integration seam (Subscription_Policy_Resolver) — now backed by the live
 * pricing-rule engine. The chips list the applied rules with the winner flagged.
 */

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
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
		return <span className="newspack-subscription-products__muted">{ __( 'No rules', 'newspack-plugin' ) }</span>;
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
 * Build the multi-line tooltip text for a multi-cycle price schedule. Cycle 1 is
 * the purchase; later cycles are renewals.
 */
function buildScheduleText( schedule: SubscriptionPolicySegment[], currency: SubscriptionProductsCurrency ): string {
	return schedule
		.map( seg => {
			const when =
				seg.from_cycle === 1
					? __( 'At purchase', 'newspack-plugin' )
					: sprintf(
							/* translators: %d: renewal number. */
							__( 'From renewal %d', 'newspack-plugin' ),
							seg.from_cycle - 1
					  );
			const price = formatAmount( seg.amount, currency );
			return seg.rule_label ? `${ when }: ${ price } — ${ seg.rule_label }` : `${ when }: ${ price }`;
		} )
		.join( '\n' );
}

/**
 * Renders the base price and, when rules change it, the resulting effective price.
 * A multi-cycle schedule (the price changes across renewals) gets a hover tooltip
 * with the full per-cycle trajectory + winning rule, since it can't fit the column.
 */
export function EffectivePrice( { policy, currency }: { policy: SubscriptionPolicyResolution; currency: SubscriptionProductsCurrency } ) {
	if ( ! policy || policy.base_price === null || policy.base_price === undefined ) {
		return <span className="newspack-subscription-products__muted">&mdash;</span>;
	}

	const baseLabel = formatAmount( policy.base_price, currency );
	const effectiveLabel = formatAmount( policy.effective_price, currency );
	const schedule = policy.schedule ?? [];
	const hasSchedule = schedule.length > 1;
	const changed = policy.effective_price !== policy.base_price;

	// Flat, unchanged price across all cycles — nothing to expand.
	if ( ! changed && ! hasSchedule ) {
		return <strong>{ baseLabel }</strong>;
	}

	const wrapperClass = classnames( 'newspack-subscription-products__effective-price', {
		'has-schedule': hasSchedule,
	} );
	const tooltip = hasSchedule ? { title: buildScheduleText( schedule, currency ) } : {};

	// A schedule that starts at the base price but changes later: show the value
	// with the tooltip, no strikethrough (the purchase price equals the base).
	if ( ! changed ) {
		return (
			<strong className={ wrapperClass } { ...tooltip }>
				{ effectiveLabel }
			</strong>
		);
	}

	return (
		<span className={ wrapperClass } { ...tooltip }>
			<span className="newspack-subscription-products__base-price">{ baseLabel }</span>
			<span aria-hidden="true"> → </span>
			<strong className="newspack-subscription-products__effective-price-value">{ effectiveLabel }</strong>
		</span>
	);
}
