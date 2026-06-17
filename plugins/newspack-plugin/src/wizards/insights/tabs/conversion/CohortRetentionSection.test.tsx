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
} );
