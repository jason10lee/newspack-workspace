/**
 * Tests for PerJourneyConversionFunnelsSection (Section 2).
 *
 * Covers section structure, the 2.4 cohort visibility gate, the NPPD-1742
 * configuration matrix (which legs render), and the funnel-shaped per-leg
 * empty states for the subscription and donation legs.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import PerJourneyConversionFunnelsSection from './PerJourneyConversionFunnelsSection';
import { makeConversionWindow, conversionLeg } from './fixtures';

const heading = ( name: string ) => screen.queryByRole( 'heading', { name } );

describe( 'PerJourneyConversionFunnelsSection', () => {
	it( 'renders the heading and all four journey funnel titles', () => {
		render( <PerJourneyConversionFunnelsSection current={ makeConversionWindow() } /> );
		expect( screen.getByRole( 'heading', { name: 'Per-journey conversion funnels' } ) ).toBeInTheDocument();
		expect( heading( 'Anonymous → Registered' ) ).toBeInTheDocument();
		expect( heading( 'Registered → Subscriber' ) ).toBeInTheDocument();
		expect( heading( 'Registered → Donor' ) ).toBeInTheDocument();
		expect( heading( 'Subscriber → Donor (cross-upsell)' ) ).toBeInTheDocument();
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

describe( 'PerJourneyConversionFunnelsSection — configuration matrix (NPPD-1742)', () => {
	it( 'registrations only: hides both the subscription and donation legs', () => {
		render(
			<PerJourneyConversionFunnelsSection
				current={ makeConversionWindow( { subscriptionVisibility: 'hidden', donationVisibility: 'hidden' } ) }
			/>
		);
		expect( heading( 'Anonymous → Registered' ) ).toBeInTheDocument();
		expect( heading( 'Registered → Subscriber' ) ).not.toBeInTheDocument();
		expect( heading( 'Registered → Donor' ) ).not.toBeInTheDocument();
	} );

	it( 'registrations + subscriptions: shows subscription, hides donation', () => {
		render(
			<PerJourneyConversionFunnelsSection
				current={ makeConversionWindow( { subscriptionVisibility: 'visible', donationVisibility: 'hidden' } ) }
			/>
		);
		expect( heading( 'Registered → Subscriber' ) ).toBeInTheDocument();
		expect( heading( 'Registered → Donor' ) ).not.toBeInTheDocument();
	} );

	it( 'registrations + donations: shows donation, hides subscription', () => {
		render(
			<PerJourneyConversionFunnelsSection
				current={ makeConversionWindow( { subscriptionVisibility: 'hidden', donationVisibility: 'visible' } ) }
			/>
		);
		expect( heading( 'Registered → Donor' ) ).toBeInTheDocument();
		expect( heading( 'Registered → Subscriber' ) ).not.toBeInTheDocument();
	} );

	it( 'all three: shows every leg', () => {
		render(
			<PerJourneyConversionFunnelsSection
				current={ makeConversionWindow( { subscriptionVisibility: 'visible', donationVisibility: 'visible' } ) }
			/>
		);
		expect( heading( 'Registered → Subscriber' ) ).toBeInTheDocument();
		expect( heading( 'Registered → Donor' ) ).toBeInTheDocument();
	} );
} );

describe( 'PerJourneyConversionFunnelsSection — per-leg states (NPPD-1742)', () => {
	it( 'subscription no_opportunity: empty leg swaps the funnel for the no-opportunity note', () => {
		render(
			<PerJourneyConversionFunnelsSection
				current={ makeConversionWindow( { registeredToSubscriberFunnel: conversionLeg( { state: 'empty' } ) } ) }
			/>
		);
		// The cell still renders (configured), with the no_opportunity copy.
		expect( heading( 'Registered → Subscriber' ) ).toBeInTheDocument();
		expect( screen.getByText( /No registered readers entered the subscription journey in this timeframe/ ) ).toBeInTheDocument();
	} );

	it( 'subscription no_conversions: keeps the funnel and annotates with {N} = prior-stage base', () => {
		render(
			<PerJourneyConversionFunnelsSection
				current={ makeConversionWindow( {
					registeredToSubscriberFunnel: conversionLeg( { entry: 1000, prior: 400, conversion: 0 } ),
				} ) }
			/>
		);
		expect( screen.getByText( /400 registered readers saw a subscription prompt, but none subscribed/ ) ).toBeInTheDocument();
		// Not the no_opportunity treatment — the funnel is kept.
		expect( screen.queryByText( /No registered readers entered the subscription journey/ ) ).not.toBeInTheDocument();
	} );

	it( 'subscription good-zero / pass-through: renders the normal funnel with no empty-state note', () => {
		render(
			<PerJourneyConversionFunnelsSection
				current={ makeConversionWindow( {
					registeredToSubscriberFunnel: conversionLeg( { entry: 1000, prior: 400, conversion: 80 } ),
				} ) }
			/>
		);
		expect( screen.queryByText( /No registered readers entered the subscription journey/ ) ).not.toBeInTheDocument();
		expect( screen.queryByText( /but none subscribed/ ) ).not.toBeInTheDocument();
	} );

	it( 'donation no_conversions: annotates the donation leg with {N} = prior-stage base', () => {
		render(
			<PerJourneyConversionFunnelsSection
				current={ makeConversionWindow( {
					registeredToDonorFunnel: conversionLeg( { entry: 900, prior: 250, conversion: 0 } ),
				} ) }
			/>
		);
		expect( screen.getByText( /250 registered readers saw a donation prompt, but none donated/ ) ).toBeInTheDocument();
	} );
} );
