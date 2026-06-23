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
	/** Custom = the full advanced form (nothing preset or hidden). */
	isCustom: boolean;
}

export const RECIPES: Record< PricingPath, Recipe > = {
	new_subscriptions: { lifecycleCondition: 'first_time_only', application: 'locked', requiresSegment: false, isCustom: false },
	retention: { lifecycleCondition: null, application: 'current', requiresSegment: true, isCustom: false },
	save: { lifecycleCondition: 'pending_cancellation', application: 'locked', requiresSegment: false, isCustom: false },
	winback: { lifecycleCondition: 'lapsed_subscriber', application: 'locked', requiresSegment: false, isCustom: false },
	custom: { lifecycleCondition: null, application: null, requiresSegment: false, isCustom: true },
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
