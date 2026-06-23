/**
 * A plain-language sentence describing a rule's pricing model, for the Pricing
 * Rules list's "Pricing model" column. A flat rule reads as a single adjustment
 * (e.g. "50% of regular price", "$5.00 off regular price"); a stepped rule reads
 * as a per-cycle progression (e.g. "$80.00 → $90.00 → $100.00 by cycle"). Falls
 * back to the bare strategy label when the params are missing or use an unknown
 * calc type.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatPrice } from './impact-format';

/** A flat adjustment as a standalone phrase. Null for an unknown calc type. */
function flatPhrase( calcType: string, value: number, currency: PricingRulesCurrency ): string | null {
	switch ( calcType ) {
		case 'fixed_price':
			/* translators: %s: a formatted price. */
			return sprintf( __( 'Set price to %s', 'newspack-plugin' ), formatPrice( value, currency ) );
		case 'percent_of_base':
			/* translators: %s: a percentage number, e.g. "80". */
			return sprintf( __( '%s%% of regular price', 'newspack-plugin' ), String( value ) );
		case 'discount_fixed':
			/* translators: %s: a formatted price. */
			return sprintf( __( '%s off regular price', 'newspack-plugin' ), formatPrice( value, currency ) );
		default:
			return null;
	}
}

/** One step's compact value expression, for the stepped progression. Null for an unknown calc type. */
function stepExpr( calcType: string, value: number, currency: PricingRulesCurrency ): string | null {
	switch ( calcType ) {
		case 'fixed_price':
			return formatPrice( value, currency );
		case 'percent_of_base':
			/* translators: %s: a percentage number, e.g. "80". */
			return sprintf( __( '%s%%', 'newspack-plugin' ), String( value ) );
		case 'discount_fixed':
			/* translators: %s: a formatted price. */
			return sprintf( __( '%s off', 'newspack-plugin' ), formatPrice( value, currency ) );
		default:
			return null;
	}
}

export function pricingModelSentence( item: PricingRuleRow, currency: PricingRulesCurrency ): string {
	const { steps, simple } = item;

	// Stepped: a per-cycle progression of each step's value expression.
	if ( item.is_stepped && steps && steps.length ) {
		const parts = steps.map( step => stepExpr( step.calc_type, step.value, currency ) );
		if ( parts.some( part => part === null ) ) {
			return item.strategy_label;
		}
		return sprintf(
			/* translators: %s: a per-cycle price progression like "$80.00 → $90.00 → $100.00". */
			__( '%s by cycle', 'newspack-plugin' ),
			parts.join( ' → ' )
		);
	}

	// Flat: a single adjustment, optionally limited to the first N cycles.
	if ( simple ) {
		const phrase = flatPhrase( simple.calc_type, simple.value, currency );
		if ( ! phrase ) {
			return item.strategy_label;
		}
		if ( simple.cycles_limit > 0 ) {
			return sprintf(
				/* translators: 1: a pricing phrase, 2: a number of billing cycles. */
				_n( '%1$s · first %2$d cycle', '%1$s · first %2$d cycles', simple.cycles_limit, 'newspack-plugin' ),
				phrase,
				simple.cycles_limit
			);
		}
		return phrase;
	}

	return item.strategy_label;
}
