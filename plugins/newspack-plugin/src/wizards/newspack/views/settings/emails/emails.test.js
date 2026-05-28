// @jest-environment jsdom

/**
 * External dependencies
 */
import { render, screen, waitFor } from '@testing-library/react';

jest.mock( './emails.scss', () => ( {} ) );

// Use mock-prefixed names so Jest's hoisted jest.mock can close over them.
const mockWizardApiFetch = jest.fn();
const mockResetError = jest.fn();
let mockErrorMessage = null;

jest.mock( '../../../../hooks/use-wizard-api-fetch', () => ( {
	useWizardApiFetch: () => ( {
		wizardApiFetch: ( ...args ) => mockWizardApiFetch( ...args ),
		isFetching: false,
		errorMessage: mockErrorMessage,
		resetError: ( ...args ) => mockResetError( ...args ),
	} ),
} ) );

jest.mock( '@wordpress/icons', () => ( {
	Icon: ( { icon } ) => <span data-testid="icon">{ icon }</span>,
	envelope: 'envelope',
} ) );

jest.mock( '@wordpress/dataviews', () => ( {
	filterSortAndPaginate: data => ( {
		data,
		paginationInfo: { totalItems: data.length, totalPages: 1 },
	} ),
} ) );

// Use mock-prefixed name so Jest's hoisted jest.mock can close over it.
let mockCapturedActions = [];

jest.mock( '../../../../../../packages/components/src', () => {
	function renderField( field, item ) {
		if ( field.render ) {
			return field.render( { item } );
		}
		if ( field.getValue ) {
			return field.getValue( { item } );
		}
		return null;
	}
	return {
		Badge: ( { text } ) => <span>{ text }</span>,
		DataViews: ( { data, fields, actions } ) => {
			mockCapturedActions = actions || [];
			return (
				<table data-testid="dataviews">
					<tbody>
						{ data.map( ( item, i ) => (
							<tr key={ i }>
								{ fields.map( field => (
									<td key={ field.id }>{ renderField( field, item ) }</td>
								) ) }
							</tr>
						) ) }
					</tbody>
				</table>
			);
		},
		Card: ( { children } ) => <div data-testid="card">{ children }</div>,
		Notice: ( { noticeText } ) => <div data-testid="notice">{ noticeText }</div>,
		utils: {
			confirmAction: jest.fn( () => true ),
		},
	};
} );

jest.mock(
	'../../../../wizards-plugin-card',
	() =>
		function MockPluginCard( props ) {
			return <div data-testid="plugin-card">{ props.slug }</div>;
		}
);

// Slice 1 surfaces only Newspack-source emails. WC fixtures land with slice 2.
const mockEmails = [
	{
		label: 'Payment receipt',
		post_id: 1,
		edit_link: '/edit/1',
		status: 'publish',
		type: 'receipt',
		category: 'reader-revenue',
		trigger_description: 'Sent after a successful payment.',
		registry_slug: 'receipt',
		recipient: 'reader',
		source: 'newspack',
	},
	{
		label: 'Cancellation confirmation',
		post_id: 2,
		edit_link: '/edit/2',
		status: 'publish',
		type: 'cancellation',
		category: 'reader-revenue',
		trigger_description: 'Sent when a reader cancels their subscription.',
		registry_slug: 'cancellation',
		recipient: 'reader',
		source: 'newspack',
	},
	{
		label: 'Reader verification',
		post_id: 3,
		edit_link: '/edit/3',
		status: 'publish',
		type: 'reader-activation-verification',
		category: 'reader-activation',
		trigger_description: 'Sent when a reader needs to verify their email address.',
		registry_slug: 'reader-activation-verification',
		recipient: 'reader',
		source: 'newspack',
	},
	{
		label: 'Account deletion',
		post_id: 4,
		edit_link: '/edit/4',
		status: 'draft',
		type: 'reader-activation-delete-account',
		category: 'reader-activation',
		trigger_description: 'Sent when a reader requests to delete their account.',
		registry_slug: 'reader-activation-delete-account',
		recipient: 'reader',
		source: 'newspack',
	},
	{
		label: 'Welcome email',
		post_id: 5,
		edit_link: '/edit/5',
		status: 'draft',
		type: 'welcome',
		category: 'reader-revenue',
		trigger_description: 'Sent to new supporters after their first payment.',
		registry_slug: 'welcome',
		recipient: 'reader',
		source: 'newspack',
	},
];

describe( 'Emails', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		mockErrorMessage = null;
		window.newspackSettings = {
			emails: {
				sections: {
					emails: {
						dependencies: {
							newspackNewsletters: true,
						},
						postType: 'newspack_rr_email',
						isEmailEnhancementsActive: false,
					},
				},
			},
		};
		mockWizardApiFetch.mockImplementation( ( opts, callbacks ) => {
			if ( opts.path === '/newspack/v1/wizard/newspack-settings/emails' ) {
				callbacks?.onSuccess?.( {
					newspack_emails: mockEmails,
					post_type: 'newspack_rr_email',
				} );
			}
			return Promise.resolve();
		} );
	} );

	it( 'renders all emails in a single view', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByText( 'Payment receipt' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Cancellation confirmation' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Reader verification' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Account deletion' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Welcome email' ) ).toBeInTheDocument();
		} );
	} );

	it( 'renders Recipient column with Reader for newspack-source emails', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			const readerCells = screen.getAllByText( 'Reader' );
			expect( readerCells.length ).toBeGreaterThanOrEqual( 5 );
		} );
	} );

	it( 'renders status as Enabled / Disabled', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			const enabledCells = screen.getAllByText( 'Enabled' );
			expect( enabledCells.length ).toBeGreaterThanOrEqual( 3 );
			const disabledCells = screen.getAllByText( 'Disabled' );
			expect( disabledCells.length ).toBeGreaterThanOrEqual( 1 );
		} );
	} );

	it( 'deactivate action calls wizardApiFetch with draft status', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		const deactivate = mockCapturedActions.find( a => a.id === 'deactivate' );
		deactivate.callback( [ mockEmails[ 0 ] ] );

		expect( mockWizardApiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/wp/v2/newspack_rr_email/1',
				method: 'POST',
				data: { status: 'draft' },
			} ),
			expect.objectContaining( {
				onSuccess: expect.any( Function ),
			} )
		);
	} );

	it( 'displays error notice when hook reports an error', async () => {
		mockErrorMessage = 'Something went wrong';

		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'notice' ) ).toBeInTheDocument();
			expect( screen.getByTestId( 'notice' ) ).toHaveTextContent( 'Something went wrong' );
		} );
	} );

	it( 'activate action calls wizardApiFetch with publish status', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		const activate = mockCapturedActions.find( a => a.id === 'activate' );
		// mockEmails[4] (Welcome email) is newspack + reader-revenue + draft —
		// actually eligible for activate (category !== 'reader-activation').
		// Verifies the callback wiring on an item that would pass `isEligible`.
		expect( activate.isEligible( mockEmails[ 4 ] ) ).toBe( true );
		activate.callback( [ mockEmails[ 4 ] ] );

		expect( mockWizardApiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/wp/v2/newspack_rr_email/5',
				method: 'POST',
				data: { status: 'publish' },
			} ),
			expect.objectContaining( {
				onSuccess: expect.any( Function ),
			} )
		);
	} );

	it( 'deactivate/activate are not eligible for reader-activation emails', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		const deactivate = mockCapturedActions.find( a => a.id === 'deactivate' );
		const activate = mockCapturedActions.find( a => a.id === 'activate' );

		// Reader-activation emails cannot be toggled.
		expect( deactivate.isEligible( mockEmails[ 2 ] ) ).toBe( false );
		// Newspack reader-revenue email can be deactivated.
		expect( deactivate.isEligible( mockEmails[ 0 ] ) ).toBe( true );
		// Draft reader-activation email cannot be activated.
		expect( activate.isEligible( mockEmails[ 3 ] ) ).toBe( false );
	} );

	it( 'reset action calls wizardApiFetch with DELETE after confirmation', async () => {
		const { utils } = require( '../../../../../../packages/components/src' );
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		const reset = mockCapturedActions.find( a => a.id === 'reset' );
		reset.callback( [ mockEmails[ 0 ] ] );

		expect( utils.confirmAction ).toHaveBeenCalled();

		expect( mockWizardApiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/newspack/v1/wizard/newspack-audience-donations/emails/1',
				method: 'DELETE',
			} ),
			expect.objectContaining( {
				onSuccess: expect.any( Function ),
			} )
		);
	} );

	it( 'reset is eligible when registry_slug is present', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		const reset = mockCapturedActions.find( a => a.id === 'reset' );
		// Email with registry_slug — eligible.
		expect( reset.isEligible( mockEmails[ 0 ] ) ).toBe( true );
		// Email without registry_slug — not eligible.
		expect( reset.isEligible( { ...mockEmails[ 0 ], registry_slug: '' } ) ).toBe( false );
	} );
} );
