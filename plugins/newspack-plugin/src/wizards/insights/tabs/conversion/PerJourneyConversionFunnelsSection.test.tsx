/**
 * Tests for PerJourneyConversionFunnelsSection (Section 2).
 *
 * Covers section structure, 2.4 visibility gate, and state-driven rendering.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import PerJourneyConversionFunnelsSection from './PerJourneyConversionFunnelsSection';
import { makeConversionWindow } from './fixtures';

describe( 'PerJourneyConversionFunnelsSection', () => {
	it( 'renders the heading and all four journey funnel titles', () => {
		render( <PerJourneyConversionFunnelsSection current={ makeConversionWindow() } /> );
		expect( screen.getByRole( 'heading', { name: 'Per-journey conversion funnels' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { name: 'Anonymous → Registered' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { name: 'Registered → Subscriber' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { name: 'Registered → Donor' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { name: 'Subscriber → Donor (cross-upsell)' } ) ).toBeInTheDocument();
	} );

	it( 'shows the cross-upsell gated note when 2.4 visibility is hidden', () => {
		render( <PerJourneyConversionFunnelsSection current={ makeConversionWindow( { crossUpsellVisibility: 'hidden' } ) } /> );
		expect( screen.getByText( /Cross-upsell view appears when both subscription and donation programs/ ) ).toBeInTheDocument();
	} );

	it( 'shows the funnel when 2.4 visibility is visible and state is populated', () => {
		render( <PerJourneyConversionFunnelsSection current={ makeConversionWindow( { crossUpsellVisibility: 'visible' } ) } /> );
		expect( screen.queryByText( /Cross-upsell view appears/ ) ).not.toBeInTheDocument();
	} );
} );
