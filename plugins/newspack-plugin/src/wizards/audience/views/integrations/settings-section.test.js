/**
 * External dependencies
 */
import { render } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { SettingsSection } from './settings-section';

const mockCardFeatureProps = [];

jest.mock( '../../../../../packages/components/src', () => ( {
	Card: ( { children } ) => children,
	Grid: ( { children } ) => children,
	CardFeature: props => {
		mockCardFeatureProps.push( props );
		return null;
	},
} ) );
jest.mock(
	'../../../wizards-tab',
	() =>
		( { children } ) =>
			children
);
jest.mock(
	'../../../wizards-section',
	() =>
		( { children } ) =>
			children
);

const SETUP_URL = 'https://example.com/wp-admin/admin.php?page=newspack-newsletters';

const baseIntegration = {
	id: 'esp',
	name: 'Newsletter ESP',
	description: 'Syncs reader data with your ESP.',
	enabled: false,
	is_set_up: false,
	is_connected: false,
	setup_url: SETUP_URL,
	settings: [],
	required_plugins: [ { slug: 'newspack-newsletters', name: 'Newspack Newsletters', is_active: true, is_installed: true } ],
};

const renderSection = ( integrationOverrides = {} ) => {
	const history = { push: jest.fn() };
	const onToggleEnabled = jest.fn();
	render(
		<SettingsSection
			integrations={ { esp: { ...baseIntegration, ...integrationOverrides } } }
			loading={ false }
			onToggleEnabled={ onToggleEnabled }
			onActivatePlugin={ jest.fn() }
			history={ history }
		/>
	);
	return { history, onToggleEnabled, cardProps: mockCardFeatureProps[ 0 ] };
};

describe( 'Audience Integrations settings section card action', () => {
	beforeEach( () => {
		mockCardFeatureProps.length = 0;
		delete window.location;
		window.location = { href: '' };
	} );

	it( 'offers "Connect" linking to the setup URL when the provider is not connected', () => {
		const { history, cardProps } = renderSection( { is_connected: false, is_set_up: false } );
		expect( cardProps.enableLabel ).toBe( 'Connect' );
		cardProps.onEnable();
		expect( window.location.href ).toBe( SETUP_URL );
		expect( history.push ).not.toHaveBeenCalled();
	} );

	it( 'offers "Finish setup" routing to the configure view when connected but not fully set up', () => {
		const { history, cardProps } = renderSection( { is_connected: true, is_set_up: false } );
		expect( cardProps.enableLabel ).toBe( 'Finish setup' );
		cardProps.onEnable();
		expect( history.push ).toHaveBeenCalledWith( '/settings/esp' );
	} );

	it( 'offers "Enable" toggling the integration when fully set up but not enabled', () => {
		const { onToggleEnabled, cardProps } = renderSection( { is_connected: true, is_set_up: true } );
		expect( cardProps.enableLabel ).toBe( 'Enable' );
		cardProps.onEnable();
		expect( onToggleEnabled ).toHaveBeenCalledWith( 'esp', true );
	} );

	it( 'routes the configure action to the configure view when connected', () => {
		const { history, cardProps } = renderSection( { is_connected: true, is_set_up: true, enabled: true } );
		cardProps.onConfigure();
		expect( history.push ).toHaveBeenCalledWith( '/settings/esp' );
	} );

	it( 'routes the configure action to the setup URL while the provider is not connected', () => {
		const { history, cardProps } = renderSection( { is_connected: false, is_set_up: false, enabled: true } );
		cardProps.onConfigure();
		expect( window.location.href ).toBe( SETUP_URL );
		expect( history.push ).not.toHaveBeenCalled();
	} );
} );
