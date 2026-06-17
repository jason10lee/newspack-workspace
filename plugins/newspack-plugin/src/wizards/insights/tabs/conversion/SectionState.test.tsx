/**
 * Tests for the conversion-tab SectionState component.
 *
 * Covers all four state arms: populated, empty, error, coming_soon.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import SectionState, { SECTION_ERROR_MESSAGE } from './SectionState';

const CHILDREN_TEXT = 'section-content-sentinel';

describe( 'SectionState (conversion)', () => {
	it( 'renders children when state is populated', () => {
		render(
			<SectionState state="populated" emptyMessage="No data">
				<span>{ CHILDREN_TEXT }</span>
			</SectionState>
		);
		expect( screen.getByText( CHILDREN_TEXT ) ).toBeInTheDocument();
	} );

	it( 'renders the empty message when state is empty', () => {
		render(
			<SectionState state="empty" emptyMessage="Nothing here yet.">
				<span>{ CHILDREN_TEXT }</span>
			</SectionState>
		);
		expect( screen.getByText( 'Nothing here yet.' ) ).toBeInTheDocument();
		expect( screen.queryByText( CHILDREN_TEXT ) ).not.toBeInTheDocument();
	} );

	it( 'renders the error message when state is error', () => {
		render(
			<SectionState state="error" emptyMessage="No data">
				<span>{ CHILDREN_TEXT }</span>
			</SectionState>
		);
		expect( screen.getByRole( 'alert' ) ).toBeInTheDocument();
		expect( screen.getByText( SECTION_ERROR_MESSAGE ) ).toBeInTheDocument();
		expect( screen.queryByText( CHILDREN_TEXT ) ).not.toBeInTheDocument();
	} );

	it( 'renders the coming_soon message when state is coming_soon', () => {
		render(
			<SectionState state="coming_soon" emptyMessage="No data">
				<span>{ CHILDREN_TEXT }</span>
			</SectionState>
		);
		expect( screen.getByRole( 'note' ) ).toBeInTheDocument();
		expect( screen.getByText( /Coming soon/ ) ).toBeInTheDocument();
		expect( screen.queryByText( CHILDREN_TEXT ) ).not.toBeInTheDocument();
	} );

	it( 'does not render an alert for coming_soon', () => {
		render(
			<SectionState state="coming_soon" emptyMessage="No data">
				<span>{ CHILDREN_TEXT }</span>
			</SectionState>
		);
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
	} );
} );
