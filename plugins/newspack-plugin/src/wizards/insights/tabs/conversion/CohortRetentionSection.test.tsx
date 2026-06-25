/**
 * Tests for CohortRetentionSection (Section 5).
 *
 * Both 5.1 and 5.2 are Phase-B coming_soon metrics. Covers:
 *   - Section structure (heading, both cohort titles)
 *   - coming_soon treatment (default fixture)
 *   - empty treatment
 *   - error treatment
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import CohortRetentionSection from './CohortRetentionSection';
import { makeConversionWindow } from './fixtures';
import type { ConversionCohortData, ConversionReferenceLine } from '../../api/conversion';

const populatedCohort = ( referenceLine: ConversionReferenceLine | null ): ConversionCohortData => ( {
	state: 'populated',
	cohorts: [
		{
			label: '2026-01',
			points: [
				{ period: 0, value: 0 },
				{ period: 1, value: 0.02 },
			],
		},
	],
	reference_line: referenceLine,
} );

describe( 'CohortRetentionSection', () => {
	it( 'renders the heading and both cohort titles', () => {
		render( <CohortRetentionSection current={ makeConversionWindow() } /> );
		expect( screen.getByRole( 'heading', { name: 'Cohort retention' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Registration → conversion' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Subscriber retention' ) ).toBeInTheDocument();
	} );

	it( 'renders the coming_soon treatment for both cohort charts (Phase B)', () => {
		render( <CohortRetentionSection current={ makeConversionWindow( { cohortState: 'coming_soon' } ) } /> );
		// Both charts should show coming_soon
		expect( screen.getAllByText( /Coming soon/ ) ).toHaveLength( 2 );
	} );

	it( 'renders the empty treatment when state is empty', () => {
		render( <CohortRetentionSection current={ makeConversionWindow( { cohortState: 'empty' } ) } /> );
		expect( screen.getAllByText( 'Cohort data will appear after the first weekly refresh.' ) ).toHaveLength( 2 );
	} );

	it( 'renders the error treatment when state is error', () => {
		render( <CohortRetentionSection current={ makeConversionWindow( { cohortState: 'error' } ) } /> );
		expect( screen.getAllByRole( 'alert' ) ).toHaveLength( 2 );
		expect( screen.getAllByText( /Unable to load this section/ ) ).toHaveLength( 2 );
	} );

	it( 'shows the 5.2 reference line but suppresses the 5.1 one even when present', () => {
		const current = {
			...makeConversionWindow( { cohortState: 'populated' } ),
			// 5.1 deliberately carries a value to prove the parent never wires a
			// 5.1 reference line, independent of payload (real payload is null).
			registration_to_conversion_cohort: populatedCohort( { value: 0.15, label: '15% at 6 months' } ),
			subscriber_retention_cohort: populatedCohort( { value: 0.7, label: '70% at 12 months' } ),
		};
		const { container } = render( <CohortRetentionSection current={ current } /> );
		expect( screen.getByText( '70% at 12 months' ) ).toBeInTheDocument();
		expect( screen.queryByText( '15% at 6 months' ) ).not.toBeInTheDocument();
		expect( container.querySelectorAll( '.newspack-insights__line-reference' ) ).toHaveLength( 1 );
		expect( container.querySelectorAll( '.newspack-insights__line-reference-label' ) ).toHaveLength( 1 );
	} );

	it( 'autoscales the 5.1 axis so small-magnitude cohort curves fill the chart', () => {
		const current = {
			...makeConversionWindow( { cohortState: 'populated' } ),
			registration_to_conversion_cohort: populatedCohort( null ),
			subscriber_retention_cohort: populatedCohort( { value: 0.7, label: '70% at 12 months' } ),
		};
		const { container } = render( <CohortRetentionSection current={ current } /> );
		// First cohort cell is 5.1 (Registration → conversion).
		const cell51 = container.querySelectorAll( '.newspack-insights__conversion-cohort-cell' )[ 0 ];
		const ys = Array.from( cell51.querySelectorAll( '.newspack-insights__line-point' ) ).map( p => parseFloat( p.getAttribute( 'cy' ) ?? '0' ) );
		// Autoscaled: the 0.02 peak reaches the top band (cy ≈ 8). With the old
		// yMax=1 it would sit near the bottom (cy ≈ 149 of the 160-tall chart),
		// so a tight ceiling guards against a regression to the fixed axis.
		expect( Math.min( ...ys ) ).toBeLessThan( 20 );
	} );
} );
