/**
 * Phase 2 fixtures for Tab 3 (Conversion Journey) React tests.
 *
 * Mirrors the shape `Conversion_REST_Controller` assembles — the default
 * `makeConversionWindow()` carries `state: 'coming_soon'` for Phase-B metrics
 * (4.2, 4.3, 4.4, 5.1, 5.2) and `state: 'populated'` for Phase-A metrics.
 *
 * Helper overrides let individual tests exercise 'empty' and 'error' states
 * on any collection metric.
 *
 * Not a `*.test.*` file, so it is never collected as a suite.
 */

import type {
	ConversionCohortData,
	ConversionCumulativeMulti,
	ConversionFunnelData,
	ConversionGatedCumulativeSingle,
	ConversionGatedFunnelData,
	ConversionMetricState,
	ConversionScalarMetric,
	ConversionSourceMixData,
	ConversionTopPagesTable,
	ConversionVisibility,
	ConversionWeeklyTrendsData,
	ConversionWindow,
	ConversionCumulativeSingle,
	ConversionPlaceholderType,
} from '../../api/conversion';

const scalar = (
	placeholder_type: ConversionPlaceholderType,
	state: 'error' | 'populated' | 'coming_soon' = 'coming_soon'
): ConversionScalarMetric => ( {
	state,
	value: placeholder_type === 'decimal' ? 0.0 : 0,
	computable: state === 'populated',
	denominator: null,
	placeholder_type,
} );

const funnel = ( state: ConversionMetricState, ...labels: string[] ): ConversionFunnelData => ( {
	state,
	stages: labels.map( label => ( { label, count: 0, pct_of_top: 0 } ) ),
} );

const gatedFunnel = ( visibility: ConversionVisibility, state: ConversionMetricState, ...labels: string[] ): ConversionGatedFunnelData => ( {
	...funnel( state, ...labels ),
	visibility,
	visibility_reason: visibility === 'hidden' ? 'insufficient_data' : null,
} );

const sourceMix = ( state: ConversionMetricState = 'coming_soon' ): ConversionSourceMixData => ( {
	state,
	total: 0,
	slices: [
		{ source: 'gate', count: 0, pct: 0 },
		{ source: 'prompt', count: 0, pct: 0 },
		{ source: 'direct', count: 0, pct: 0 },
	],
} );

const cumulativeSingle = ( state: ConversionMetricState = 'coming_soon' ): ConversionCumulativeSingle => ( { state, points: [] } );

const gatedCumulativeSingle = (
	visibility: ConversionVisibility,
	state: ConversionMetricState = 'coming_soon'
): ConversionGatedCumulativeSingle => ( {
	state,
	points: [],
	visibility,
	visibility_reason: visibility === 'hidden' ? 'insufficient_data' : null,
} );

const cumulativeMulti = ( state: ConversionMetricState = 'coming_soon' ): ConversionCumulativeMulti => ( {
	state,
	groups: [
		{ label: 'gate', points: [] },
		{ label: 'prompt', points: [] },
		{ label: 'direct', points: [] },
	],
} );

const cohort = ( value: number, label: string, state: ConversionMetricState = 'coming_soon' ): ConversionCohortData => ( {
	state,
	cohorts: [],
	reference_line: { value, label },
} );

const weekly = ( state: ConversionMetricState = 'coming_soon' ): ConversionWeeklyTrendsData => ( {
	state,
	weeks: [],
	series: [ 'registration_rate', 'subscription_attempt_rate' ],
} );

const topPages = ( state: ConversionMetricState = 'coming_soon' ): ConversionTopPagesTable => ( { state, rows: [], threshold_pageviews: 100 } );

export interface ConversionWindowOverrides {
	crossUpsellVisibility?: ConversionVisibility;
	lagVisibility?: ConversionVisibility;
	/** Override the lifecycle funnel state (Section 1). */
	lifecycleState?: ConversionMetricState;
	/** Override the source-mix state (Section 3, all three pies). */
	sourceMixState?: ConversionMetricState;
	/** Override the time-to-register distribution state (Section 4.1). */
	timeToRegisterState?: ConversionMetricState;
	/** Override the weekly-trends state (Section 6). */
	weeklyTrendsState?: ConversionMetricState;
	/** Override the cohort retention states (Section 5.1 and 5.2). */
	cohortState?: ConversionMetricState;
	/** Override the top-pages table state (Section 8.4). */
	topPagesState?: ConversionMetricState;
}

/** Build a full Phase 2 window, with optional state and visibility overrides. */
export const makeConversionWindow = ( overrides: ConversionWindowOverrides = {} ): ConversionWindow => ( {
	window: { start: '2026-03-22', end: '2026-04-21' },
	// Section 1 — Phase A, wired.
	reader_lifecycle_funnel: funnel(
		overrides.lifecycleState ?? 'populated',
		'Anonymous reader',
		'Engaged reader',
		'Registered reader',
		'Newsletter subscriber',
		'Subscriber or donor'
	),
	// Section 2 — Phase A, wired.
	anonymous_to_registered_funnel: funnel( 'populated', 'Anonymous', 'Saw a conversion surface', 'Registered' ),
	registered_to_subscriber_funnel: funnel( 'populated', 'Registered', 'Saw a subscription-intent surface', 'Became subscriber' ),
	registered_to_donor_funnel: funnel( 'populated', 'Registered', 'Saw a donation-intent surface', 'Became donor' ),
	subscriber_to_donor_funnel: gatedFunnel( overrides.crossUpsellVisibility ?? 'hidden', 'populated', 'Active subscriber', 'Also donor' ),
	// Section 3 — Phase A, wired.
	source_mix_registrations: sourceMix( overrides.sourceMixState ?? 'populated' ),
	source_mix_subscribers: sourceMix( overrides.sourceMixState ?? 'populated' ),
	source_mix_donors: sourceMix( overrides.sourceMixState ?? 'populated' ),
	// Section 4 — 4.1 Phase A; 4.2, 4.3, 4.4 Phase B coming_soon.
	time_to_register_distribution: cumulativeSingle( overrides.timeToRegisterState ?? 'populated' ),
	time_to_subscribe_distribution: cumulativeMulti( 'coming_soon' ),
	time_to_donate_distribution: cumulativeMulti( 'coming_soon' ),
	subscriber_to_donor_lag_distribution: gatedCumulativeSingle( overrides.lagVisibility ?? 'hidden', 'coming_soon' ),
	// Section 5 — Phase B coming_soon.
	registration_to_conversion_cohort: cohort( 0.15, '15% at 6 months', overrides.cohortState ?? 'coming_soon' ),
	subscriber_retention_cohort: cohort( 0.7, '70% at 12 months', overrides.cohortState ?? 'coming_soon' ),
	// Section 6 — Phase A, wired.
	weekly_conversion_rates: weekly( overrides.weeklyTrendsState ?? 'populated' ),
	// Section 7 — Phase A, wired (scalar).
	influenced_registration_rate_7d: scalar( 'rate', 'populated' ),
	influenced_subscription_rate_14d: scalar( 'rate', 'populated' ),
	influenced_donation_rate_14d: scalar( 'rate', 'populated' ),
	influenced_newsletter_rate_7d: scalar( 'rate', 'populated' ),
	// Section 8 — Phase A, wired (scalar + table).
	stale_registered_count: scalar( 'count', 'populated' ),
	at_risk_subscriber_count: scalar( 'count', 'populated' ),
	lapsed_donor_count: scalar( 'count', 'populated' ),
	top_pages_no_conversion: topPages( overrides.topPagesState ?? 'populated' ),
} );
