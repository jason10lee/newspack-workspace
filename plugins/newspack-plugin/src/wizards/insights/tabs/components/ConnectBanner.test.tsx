/**
 * Tests for ConnectBanner (NPPD-1649, NPPD-1731): the full-tab "connect
 * Google Analytics" state shown on Audience and Engagement when GA4 isn't
 * connected. Verifies the Site Kit copy and that the CTA links to the Site
 * Kit URL from the boot config, falling back to a relative admin path when
 * the global is absent.
 *
 * siteKitUrl is read at render time, so each case sets window.newspackInsights
 * before rendering — no module reload needed.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ConnectBanner from './ConnectBanner';

describe( 'ConnectBanner', () => {
	afterEach( () => {
		delete ( window as { newspackInsights?: unknown } ).newspackInsights;
	} );

	it( 'renders the provided banner text and links the CTA to the Site Kit URL', () => {
		( window as { newspackInsights?: unknown } ).newspackInsights = {
			siteKitUrl: 'https://example.test/wp-admin/admin.php?page=googlesitekit-splash',
		};

		render(
			<ConnectBanner text="Audience metrics come from a GA4 property connected through Site Kit. Set up Site Kit to start seeing data here." />
		);

		expect( screen.getByText( /Audience metrics come from a GA4 property connected through Site Kit/ ) ).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'Set up Site Kit →' } ) ).toHaveAttribute(
			'href',
			'https://example.test/wp-admin/admin.php?page=googlesitekit-splash'
		);
	} );

	it( 'falls back to default Site Kit copy and a relative admin path when the global is absent', () => {
		render( <ConnectBanner /> );

		expect( screen.getByText( 'Connect Google Analytics through Site Kit to see this tab.' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'Set up Site Kit →' } ) ).toHaveAttribute( 'href', 'admin.php?page=newspack-settings' );
	} );
} );
