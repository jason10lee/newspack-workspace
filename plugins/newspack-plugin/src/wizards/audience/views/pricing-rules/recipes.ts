/**
 * Pricing-path recipes — the intent-first map that turns the advanced rule form
 * into a recipe. Each named path presets the lifecycle matcher + application and
 * hides them; Custom presets nothing. See DP spec 20.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

export type PricingPath = 'new_subscriptions' | 'retention' | 'save' | 'winback' | 'custom';

type ConditionsMap = { [ id: string ]: boolean | number | number[] | null };

/** The mutually-exclusive boolean lifecycle condition matchers a path owns. */
export const LIFECYCLE_CONDITIONS = [ 'first_time_only', 'lapsed_subscriber', 'pending_cancellation' ] as const;

export interface Recipe {
	/** Condition matcher id forced on for this path, or null (retention/custom). */
	lifecycleCondition: string | null;
	/** Application forced for this path, or null when the user picks it (custom). */
	application: 'locked' | 'current' | null;
	/** Whether a reader_segment selection is required (retention). */
	requiresSegment: boolean;
	/** Default scope applied when the path is chosen — subscription presets target all subscriptions. */
	defaultScope: string;
	/** Custom = the full advanced form (nothing preset or hidden). */
	isCustom: boolean;
}

export const RECIPES: Record< PricingPath, Recipe > = {
	new_subscriptions: { lifecycleCondition: 'first_time_only', application: 'locked', requiresSegment: false, defaultScope: 'all_subscriptions', isCustom: false },
	retention: { lifecycleCondition: null, application: 'current', requiresSegment: true, defaultScope: 'all_subscriptions', isCustom: false },
	save: { lifecycleCondition: 'pending_cancellation', application: 'locked', requiresSegment: false, defaultScope: 'all_subscriptions', isCustom: false },
	winback: { lifecycleCondition: 'lapsed_subscriber', application: 'locked', requiresSegment: false, defaultScope: 'all_subscriptions', isCustom: false },
	custom: { lifecycleCondition: null, application: null, requiresSegment: false, defaultScope: 'all_products', isCustom: true },
};

/** Path options for the editor's first SelectControl (ordered). */
export function pathOptions(): { label: string; value: PricingPath }[] {
	return [
		{ label: __( 'New subscriptions', 'newspack-plugin' ), value: 'new_subscriptions' },
		{ label: __( 'Subscription retention', 'newspack-plugin' ), value: 'retention' },
		{ label: __( 'Save', 'newspack-plugin' ), value: 'save' },
		{ label: __( 'Win-back', 'newspack-plugin' ), value: 'winback' },
		{ label: __( 'Custom', 'newspack-plugin' ), value: 'custom' },
	];
}

/**
 * Apply a path's recipe to a conditions map: clear every lifecycle matcher, then
 * set the path's one (if any). Non-lifecycle conditions (reader_segment, cohort)
 * are preserved.
 */
export function applyRecipeConditions( path: PricingPath, conditions: ConditionsMap ): ConditionsMap {
	const next: ConditionsMap = { ...conditions };
	LIFECYCLE_CONDITIONS.forEach( id => {
		delete next[ id ];
	} );
	const { lifecycleCondition } = RECIPES[ path ];
	if ( lifecycleCondition ) {
		next[ lifecycleCondition ] = true;
	}
	return next;
}

/**
 * Which condition field_types are editable for a path. Named paths expose only
 * segmentation ('select'); Custom exposes everything.
 */
export function isConditionVisible( path: PricingPath, fieldType: string ): boolean {
	return RECIPES[ path ].isCustom ? true : 'select' === fieldType;
}

/**
 * Whether the chosen path's required segment is satisfied. Retention requires a
 * non-empty reader_segment selection; all other paths pass.
 */
export function segmentSatisfied( path: PricingPath, conditions: ConditionsMap ): boolean {
	if ( ! RECIPES[ path ].requiresSegment ) {
		return true;
	}
	const seg = conditions.reader_segment;
	return Array.isArray( seg ) && seg.length > 0;
}

/** Human label for a stored intent value (falls back to the raw value). */
export function intentLabel( value: string ): string {
	return pathOptions().find( o => o.value === value )?.label ?? value;
}

/** A plain-language explanation of a path's use and what it presets, shown under the goal picker. */
export function pathDescription( path: PricingPath ): string {
	const map: Record< PricingPath, string > = {
		new_subscriptions: __(
			'Acquisition pricing for first-time subscribers — an intro or stepped offer. Presets new-subscribers-only eligibility, locks the price in at purchase, and targets all subscriptions.',
			'newspack-plugin'
		),
		retention: __(
			'A renewal discount to keep existing, at-risk subscribers. Stays “always current” so it re-applies at every renewal, targets all subscriptions, and needs a reader segment to define who is at risk.',
			'newspack-plugin'
		),
		save: __(
			'A last-chance offer at the cancellation moment. Applies when a pending-cancel subscriber reactivates, locks the saved price in, and targets all subscriptions.',
			'newspack-plugin'
		),
		winback: __(
			'Re-acquisition pricing to win back lapsed subscribers. Applies to readers with no active subscription when they resubscribe, locks the price in at purchase, and targets all subscriptions.',
			'newspack-plugin'
		),
		custom: __(
			'Full manual control — nothing is preset. Set the eligibility matcher, lock behavior, scope, and pricing yourself.',
			'newspack-plugin'
		),
	};
	return map[ path ];
}
