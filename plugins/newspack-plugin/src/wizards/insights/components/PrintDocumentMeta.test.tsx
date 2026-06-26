/**
 * Tests for PrintDocumentMeta (NPPD-1661).
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import PrintDocumentMeta from './PrintDocumentMeta';
import type { DateRange } from '../state/useDateRange';

const range = { preset: 'last-30', start: '2026-05-20', end: '2026-06-18' } as DateRange;
const previousRange = { preset: 'custom', start: '2026-04-20', end: '2026-05-19' } as DateRange;

describe( 'PrintDocumentMeta', () => {
	it( 'renders the publisher, tab title, and date range in the header', () => {
		const { container } = render(
			<PrintDocumentMeta tabLabel="Audience" publisherName="The Daily Example" range={ range } previousRange={ null } />
		);

		expect( screen.getByRole( 'heading', { name: 'Audience' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'The Daily Example' ) ).toBeInTheDocument();
		// Scope to the range line: the footer's generation date can coincide with
		// the range end date, so a bare getByText would match twice.
		expect( container.querySelector( '.newspack-insights__print-range' ) ).toHaveTextContent( 'May 20, 2026 – Jun 18, 2026' );
	} );

	it( 'omits the publisher line when the name is empty', () => {
		const { container } = render( <PrintDocumentMeta tabLabel="Audience" publisherName="" range={ range } previousRange={ null } /> );
		expect( container.querySelector( '.newspack-insights__print-publisher' ) ).toBeNull();
	} );

	it( 'shows the comparison period only when previousRange is set', () => {
		const { rerender } = render(
			<PrintDocumentMeta tabLabel="Audience" publisherName="The Daily Example" range={ range } previousRange={ null } />
		);
		expect( screen.queryByText( /Compared to/ ) ).toBeNull();

		rerender( <PrintDocumentMeta tabLabel="Audience" publisherName="The Daily Example" range={ range } previousRange={ previousRange } /> );
		expect( screen.getByText( /Compared to/ ) ).toBeInTheDocument();
	} );

	it( 'renders a generation-date footer', () => {
		render( <PrintDocumentMeta tabLabel="Audience" publisherName="The Daily Example" range={ range } previousRange={ null } /> );
		expect( screen.getByText( /Generated/ ) ).toBeInTheDocument();
	} );
} );
