/**
 * Tests for PaidReaderConversionSection empty states (NPPD-1694): the render
 * branches (no_opportunity / no_conversions / errored / normal) and the per-card
 * count fallback surviving inside a normal section render.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import PaidReaderConversionSection from './PaidReaderConversionSection';
import type { GatesScalarMetric, GatesWindow } from '../../api/gates';

const scalar = ( over: Partial< GatesScalarMetric > = {} ): GatesScalarMetric => ( {
	state: 'populated',
	value: 0,
	computable: true,
	denominator: null,
	numerator: null,
	placeholder_type: 'rate',
	...over,
} );

const makeWindow = ( over: Partial< GatesWindow > = {} ): GatesWindow => ( {
	window: { start: '2026-03-01', end: '2026-03-31' },
	total_gate_impressions: scalar( { placeholder_type: 'count', value: 100 } ),
	unique_readers_reached: scalar( { placeholder_type: 'count' } ),
	avg_exposures_per_reader: scalar( { placeholder_type: 'decimal' } ),
	sessions_with_gate: scalar(),
	regwall_conversion_direct: scalar(),
	regwall_conversion_influenced_7d: scalar(),
	paywall_conversion_direct: scalar(),
	paywall_conversion_influenced_14d: scalar(),
	total_paywall_revenue_direct: scalar( { placeholder_type: 'currency' } ),
	avg_revenue_per_paywall_conversion: scalar( { placeholder_type: 'currency' } ),
	paywall_attempts_total: 0,
	paywall_conversions_total: 0,
	conversion_funnel: { state: 'empty', stages: [] },
	exposures_distribution: { state: 'empty', buckets: [] },
	performance_by_gate: { state: 'empty', rows: [] },
	...over,
} );

describe( 'PaidReaderConversionSection empty states', () => {
	it( 'renders no_opportunity when there are no paywall attempts', () => {
		const current = makeWindow( { paywall_attempts_total: 0, paywall_conversions_total: 0 } );
		const { container } = render( <PaidReaderConversionSection current={ current } previous={ null } /> );

		expect( container.querySelector( '[data-empty-state="no_opportunity"]' ) ).toBeInTheDocument();
		// Body is asserted on the container — the Notice's speak() duplicates it into a
		// global live-region, so a screen-level text query would match twice.
		expect( container ).toHaveTextContent( 'No paywall attempts in this timeframe' );
		expect( screen.queryByText( 'Paywall Conversion (Direct)' ) ).not.toBeInTheDocument();
	} );

	it( 'renders no_conversions with the attempt count interpolated when attempts > 0 and conversions = 0', () => {
		const current = makeWindow( { paywall_attempts_total: 17, paywall_conversions_total: 0 } );
		const { container } = render( <PaidReaderConversionSection current={ current } previous={ null } /> );

		expect( container.querySelector( '[data-empty-state="no_conversions"]' ) ).toBeInTheDocument();
		expect( container ).toHaveTextContent( 'Your paywall reached 17 readers' );
		expect( screen.queryByText( 'Paywall Conversion (Direct)' ) ).not.toBeInTheDocument();
	} );

	it( 'renders the four scorecards (no empty state) when the section has data', () => {
		const current = makeWindow( {
			paywall_attempts_total: 320,
			paywall_conversions_total: 11,
			paywall_conversion_direct: scalar( { value: 0.021, numerator: 7, denominator: 320 } ),
			paywall_conversion_influenced_14d: scalar( { value: 0.038, numerator: 11, denominator: 290 } ),
			total_paywall_revenue_direct: scalar( { placeholder_type: 'currency', value: 4180.5, denominator: 7 } ),
			avg_revenue_per_paywall_conversion: scalar( { placeholder_type: 'currency', value: 88.95, denominator: 7 } ),
		} );
		const { container } = render( <PaidReaderConversionSection current={ current } previous={ null } /> );

		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Paywall Conversion (Direct)' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Total Paywall Revenue (Direct)' ) ).toBeInTheDocument();
	} );

	it( 'does NOT show an empty state when the Direct scalar errored — renders the scorecards instead', () => {
		// An errored Direct query leaves paywall_attempts_total at 0 (null denominator).
		// That must not be mistaken for a genuine "no paywall attempts" empty state — the
		// cards should render so the errored one surfaces its own error treatment.
		const current = makeWindow( {
			paywall_attempts_total: 0,
			paywall_conversions_total: 0,
			paywall_conversion_direct: scalar( { state: 'error', computable: false, error_message: 'BQ down' } ),
		} );
		const { container } = render( <PaidReaderConversionSection current={ current } previous={ null } /> );

		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		expect( container ).not.toHaveTextContent( 'No paywall attempts in this timeframe' );
		expect( screen.getByText( 'Paywall Conversion (Direct)' ) ).toBeInTheDocument();
	} );

	it( 'does NOT show an empty state when the Influenced scalar errored — renders the scorecards instead', () => {
		// Direct computes a genuine 0 conversions while Influenced errors independently
		// (its null numerator coerces to 0 in paywall_section_totals). The section must
		// not render the no_conversions empty state and hide the errored Influenced card.
		const current = makeWindow( {
			paywall_attempts_total: 17,
			paywall_conversions_total: 0,
			paywall_conversion_direct: scalar( { value: 0, numerator: 0, denominator: 17 } ),
			paywall_conversion_influenced_14d: scalar( { state: 'error', computable: false, error_message: 'BQ down' } ),
		} );
		const { container } = render( <PaidReaderConversionSection current={ current } previous={ null } /> );

		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		// Assert on the container, not `screen`: the Notice's speak() leaves the
		// no_conversions copy in a global live-region from an earlier test.
		expect( container ).not.toHaveTextContent( 'No paywall conversions in this timeframe' );
		// Scorecards render; the errored Influenced card shows the shared error note.
		expect( screen.getByText( 'Paywall Conversion (Influenced, 14d)' ) ).toBeInTheDocument();
	} );

	it( 'applies the per-card count fallback inside a normal section render', () => {
		// Section has data (Influenced converted) so it renders scorecards, but the
		// Direct rate card has 0 of 320 and the Direct revenue card has 0 conversions.
		const current = makeWindow( {
			paywall_attempts_total: 320,
			paywall_conversions_total: 11,
			paywall_conversion_direct: scalar( { value: 0, computable: true, numerator: 0, denominator: 320 } ),
			paywall_conversion_influenced_14d: scalar( { value: 0.038, numerator: 11, denominator: 290 } ),
			total_paywall_revenue_direct: scalar( { placeholder_type: 'currency', value: 0, denominator: 0 } ),
			avg_revenue_per_paywall_conversion: scalar( { placeholder_type: 'currency', value: 0, denominator: 0 } ),
		} );
		render( <PaidReaderConversionSection current={ current } previous={ null } /> );

		expect( screen.getByText( '0 of 320' ) ).toBeInTheDocument();
		expect( screen.getByText( '0 conversions' ) ).toBeInTheDocument();
	} );
} );
