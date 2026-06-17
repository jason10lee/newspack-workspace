/**
 * Tests for HowLongConversionsTakeSection (Section 4).
 *
 * Covers:
 *   - Section structure (heading, four curve titles)
 *   - 4.1 populated render path
 *   - 4.1 empty render path
 *   - 4.4 visibility gate (hidden / visible)
 *   - 4.2 / 4.3 / (4.4 visible) coming_soon treatment
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import HowLongConversionsTakeSection from './HowLongConversionsTakeSection';
import { makeConversionWindow } from './fixtures';

describe( 'HowLongConversionsTakeSection', () => {
	it( 'renders the heading and all four curve titles', () => {
		render( <HowLongConversionsTakeSection current={ makeConversionWindow() } /> );
		expect( screen.getByRole( 'heading', { name: 'How long conversions take' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Time to register' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Time to subscribe' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Time to donate' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Subscriber → donor lag' ) ).toBeInTheDocument();
	} );

	it( 'renders 4.1 empty treatment when time-to-register state is empty', () => {
		render( <HowLongConversionsTakeSection current={ makeConversionWindow( { timeToRegisterState: 'empty' } ) } /> );
		expect( screen.getByText( 'Time-to-register data will appear once registrations occur in this window.' ) ).toBeInTheDocument();
	} );

	it( 'renders 4.1 error treatment when time-to-register state is error', () => {
		render( <HowLongConversionsTakeSection current={ makeConversionWindow( { timeToRegisterState: 'error' } ) } /> );
		expect( screen.getByRole( 'alert' ) ).toBeInTheDocument();
		expect( screen.getByText( /Unable to load this section/ ) ).toBeInTheDocument();
	} );

	it( 'shows the lag gated note when 4.4 visibility is hidden', () => {
		render( <HowLongConversionsTakeSection current={ makeConversionWindow( { lagVisibility: 'hidden' } ) } /> );
		expect( screen.getByText( /Subscriber-to-donor lag appears when at least 50 readers/ ) ).toBeInTheDocument();
	} );

	it( 'shows the coming_soon treatment for 4.2 and 4.3 (time-to-subscribe/donate)', () => {
		render( <HowLongConversionsTakeSection current={ makeConversionWindow() } /> );
		// time_to_subscribe_distribution and time_to_donate_distribution are coming_soon in the default fixture.
		// The lag (4.4) is hidden so shows the gated note. Only 4.2 and 4.3 should show coming_soon.
		expect( screen.getAllByText( /Coming soon/ ) ).toHaveLength( 2 );
	} );

	it( 'shows the coming_soon treatment for 4.4 when visible but coming_soon', () => {
		render( <HowLongConversionsTakeSection current={ makeConversionWindow( { lagVisibility: 'visible' } ) } /> );
		// lag is visible but state is coming_soon — all three (4.2, 4.3, 4.4) show coming_soon.
		expect( screen.getAllByText( /Coming soon/ ) ).toHaveLength( 3 );
	} );
} );
