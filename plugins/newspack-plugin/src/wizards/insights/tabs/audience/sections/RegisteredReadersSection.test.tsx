/**
 * Tests for RegisteredReadersSection (NPPD-1733).
 *
 * Covers the two cards across populated / honest-zero / not-computable states
 * and the new-readers delta suppression on an honest zero.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import RegisteredReadersSection from './RegisteredReadersSection';
import type { MetricPayload, RegisteredReaders } from '../../../api/audience';

const count = ( value: number | null, computable = true ): MetricPayload => ( { value, computable, type: 'count' } );

const make = ( total: MetricPayload, current: MetricPayload, previous: MetricPayload | null = null ): RegisteredReaders => ( {
	total,
	new: { current, previous },
} );

describe( 'RegisteredReadersSection', () => {
	it( 'renders the heading and both cards with formatted counts', () => {
		render( <RegisteredReadersSection registeredReaders={ make( count( 24680 ), count( 412 ), count( 357 ) ) } showComparison /> );
		expect( screen.getByRole( 'heading', { name: 'Registered readers' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Total registered readers' ) ).toBeInTheDocument();
		expect( screen.getByText( '24,680' ) ).toBeInTheDocument();
		expect( screen.getByText( 'New registered readers' ) ).toBeInTheDocument();
		expect( screen.getByText( '412' ) ).toBeInTheDocument();
	} );

	it( 'renders a new-publisher zero as an honest 0 with no period delta', () => {
		render( <RegisteredReadersSection registeredReaders={ make( count( 0 ), count( 0 ), count( 0 ) ) } showComparison /> );
		expect( screen.getAllByText( '0' ).length ).toBeGreaterThanOrEqual( 2 );
		expect( screen.queryByText( '↑' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( '↓' ) ).not.toBeInTheDocument();
	} );

	it( 'renders the not-computable em-dash + line when a count fails server-side', () => {
		render( <RegisteredReadersSection registeredReaders={ make( count( null, false ), count( null, false ), null ) } showComparison /> );
		expect( screen.getAllByLabelText( 'Not applicable' ) ).toHaveLength( 2 );
		expect( screen.getAllByText( 'Registered reader count is unavailable right now.' ) ).toHaveLength( 2 );
	} );

	it( 'suppresses the new-readers delta when none were created this timeframe', () => {
		render( <RegisteredReadersSection registeredReaders={ make( count( 100 ), count( 0 ), count( 40 ) ) } showComparison /> );
		// A real prior value exists, but an honest zero must not render "↓100%".
		expect( screen.queryByText( '↓' ) ).not.toBeInTheDocument();
	} );

	it( 'omits the new-readers delta when the comparison toggle is off', () => {
		render( <RegisteredReadersSection registeredReaders={ make( count( 100 ), count( 60 ), count( 40 ) ) } showComparison={ false } /> );
		expect( screen.queryByText( '↑' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( '↓' ) ).not.toBeInTheDocument();
	} );
} );
