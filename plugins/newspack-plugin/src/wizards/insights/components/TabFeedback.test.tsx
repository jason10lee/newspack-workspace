/**
 * Tests for TabFeedback (NPPD-1728) — the one-record tier-1/tier-2 flow.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';

/**
 * Internal dependencies
 */
import TabFeedback from './TabFeedback';
import { submitFeedback, beaconSentiment } from '../api/feedback';

jest.mock( '../api/feedback' );

const mockedSubmit = submitFeedback as jest.MockedFunction< typeof submitFeedback >;
const mockedBeacon = beaconSentiment as jest.MockedFunction< typeof beaconSentiment >;

const BEACON_URL = 'https://example.test/wp-json/newspack-insights/v1/feedback';
const BEACON_NONCE = 'nonce-xyz';

const renderTab = ( context: 'audience' | 'donors' = 'audience' ) =>
	render( <TabFeedback context={ context } beaconUrl={ BEACON_URL } beaconNonce={ BEACON_NONCE } /> );

const thumbUp = () => screen.getByRole( 'button', { name: 'Yes, this tab was useful' } );
const thumbDown = () => screen.getByRole( 'button', { name: 'No, this tab was not useful' } );
const modalTitle = () => screen.queryByText( 'Tell us more' );
const ack = () => screen.findByTestId( 'snackbar' );
const modalError = () => document.querySelector( '.newspack-insights__feedback-modal-error' );

describe( 'TabFeedback', () => {
	beforeEach( () => {
		mockedSubmit.mockResolvedValue( { success: true } );
	} );

	afterEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders the prompt and both thumb controls', () => {
		renderTab();
		expect( screen.getByText( 'Was this tab useful?' ) ).toBeInTheDocument();
		expect( thumbUp() ).toBeInTheDocument();
		expect( thumbDown() ).toBeInTheDocument();
	} );

	it( 'opens the tier-2 modal on a thumb click without posting yet', () => {
		renderTab();
		fireEvent.click( thumbUp() );

		expect( modalTitle() ).toBeInTheDocument();
		expect( mockedSubmit ).not.toHaveBeenCalled();
	} );

	it( 'shows a positive prompt for thumbs-up and a "missing" prompt for thumbs-down', () => {
		const { unmount } = renderTab();
		fireEvent.click( thumbUp() );
		expect( screen.getByText( 'What do you find most useful here?' ) ).toBeInTheDocument();
		unmount();

		renderTab();
		fireEvent.click( thumbDown() );
		expect( screen.getByText( 'What’s missing or frustrating about this tab?' ) ).toBeInTheDocument();
	} );

	it( 'posts one record with the freeform comment on submit', async () => {
		renderTab();
		fireEvent.click( thumbDown() );

		fireEvent.change( screen.getByRole( 'textbox' ), { target: { value: 'needs a CSV export' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Send comment' } ) );

		await waitFor( () => expect( mockedSubmit ).toHaveBeenCalledTimes( 1 ) );
		expect( mockedSubmit ).toHaveBeenCalledWith( {
			context: 'audience',
			sentiment: 'down',
			comment: 'needs a CSV export',
		} );
		expect( await ack() ).toHaveTextContent( 'Thanks for your feedback!' );
		expect( modalTitle() ).not.toBeInTheDocument();
	} );

	it( 'posts one sentiment-only record and stays silent when skipped', async () => {
		renderTab( 'donors' );
		fireEvent.click( thumbUp() );
		fireEvent.click( screen.getByRole( 'button', { name: 'Skip' } ) );

		await waitFor( () => expect( mockedSubmit ).toHaveBeenCalledTimes( 1 ) );
		expect( mockedSubmit ).toHaveBeenCalledWith( {
			context: 'donors',
			sentiment: 'up',
			comment: '',
		} );
		// Skip is silent — the rating lands, but no acknowledgment toast.
		expect( screen.queryByTestId( 'snackbar' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the modal open and preserves the comment on a submit failure, then retries', async () => {
		mockedSubmit.mockRejectedValueOnce( new Error( 'boom' ) );
		renderTab();
		fireEvent.click( thumbDown() );

		const textarea = screen.getByRole( 'textbox' );
		fireEvent.change( textarea, { target: { value: 'do not lose me' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Send comment' } ) );

		// Modal stays open, error shown, comment preserved.
		await waitFor( () => expect( modalError() ).toBeInTheDocument() );
		expect( modalTitle() ).toBeInTheDocument();
		expect( screen.getByRole( 'textbox' ) ).toHaveValue( 'do not lose me' );

		// Retry succeeds.
		fireEvent.click( screen.getByRole( 'button', { name: 'Send comment' } ) );
		await waitFor( () => expect( mockedSubmit ).toHaveBeenCalledTimes( 2 ) );
		expect( await ack() ).toHaveTextContent( 'Thanks for your feedback!' );
	} );

	it( 'beacons a sentiment-only record (with url + nonce) when the tab is closed mid-modal', () => {
		renderTab();
		fireEvent.click( thumbUp() );
		expect( modalTitle() ).toBeInTheDocument();

		act( () => {
			window.dispatchEvent( new Event( 'pagehide' ) );
		} );

		expect( mockedBeacon ).toHaveBeenCalledWith( { context: 'audience', sentiment: 'up' }, BEACON_URL, BEACON_NONCE );
		expect( mockedSubmit ).not.toHaveBeenCalled();
	} );

	it( 'does not beacon on a bfcache pagehide (persisted)', () => {
		renderTab();
		fireEvent.click( thumbUp() );

		act( () => {
			const event = new Event( 'pagehide' ) as PageTransitionEvent;
			Object.defineProperty( event, 'persisted', { value: true } );
			window.dispatchEvent( event );
		} );

		expect( mockedBeacon ).not.toHaveBeenCalled();
	} );
} );
