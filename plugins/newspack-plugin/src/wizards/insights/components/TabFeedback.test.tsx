/**
 * Tests for TabFeedback (NPPD-1728, Slice 1) — the tier-1 thumb.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

/**
 * Internal dependencies
 */
import TabFeedback from './TabFeedback';
import { submitFeedback } from '../api/feedback';

jest.mock( '../api/feedback' );

const mockedSubmit = submitFeedback as jest.MockedFunction< typeof submitFeedback >;

const thumbUp = () => screen.getByRole( 'button', { name: 'Yes, this tab was useful' } );
const thumbDown = () => screen.getByRole( 'button', { name: 'No, this tab was not useful' } );
const ack = () => screen.findByTestId( 'snackbar' );

describe( 'TabFeedback', () => {
	afterEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders the prompt and both thumb controls', () => {
		render( <TabFeedback context="audience" /> );
		expect( screen.getByText( 'Was this tab useful?' ) ).toBeInTheDocument();
		expect( thumbUp() ).toBeInTheDocument();
		expect( thumbDown() ).toBeInTheDocument();
	} );

	it( 'submits the sentiment with the tab context and acknowledges on success', async () => {
		mockedSubmit.mockResolvedValue( { success: true } );
		render( <TabFeedback context="engagement" /> );

		fireEvent.click( thumbUp() );

		await waitFor( () => expect( mockedSubmit ).toHaveBeenCalledWith( { context: 'engagement', sentiment: 'up' } ) );
		expect( await ack() ).toHaveTextContent( 'Thanks for your feedback!' );
	} );

	it( 'submits a thumbs-down with the down sentiment', async () => {
		mockedSubmit.mockResolvedValue( { success: true } );
		render( <TabFeedback context="donors" /> );

		fireEvent.click( thumbDown() );

		await waitFor( () => expect( mockedSubmit ).toHaveBeenCalledWith( { context: 'donors', sentiment: 'down' } ) );
	} );

	it( 'surfaces an error and allows a retry on failure', async () => {
		mockedSubmit.mockRejectedValueOnce( new Error( 'nope' ) );
		render( <TabFeedback context="gates" /> );

		fireEvent.click( thumbUp() );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent( 'Could not send. Try again.' );

		// The control re-opens for a retry; a second click goes through.
		mockedSubmit.mockResolvedValueOnce( { success: true } );
		fireEvent.click( thumbUp() );

		await waitFor( () => expect( mockedSubmit ).toHaveBeenCalledTimes( 2 ) );
	} );
} );
