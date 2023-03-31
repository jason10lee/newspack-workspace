import React from 'react';
import { HashRouter as Router, Routes, Route } from 'react-router-dom';
import { withWizard } from 'newspack-components';

import { render, createElement, Component } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import { __ } from '@wordpress/i18n';

import BrandsList from './views/BrandsList';

import './index.scss';

class App extends Component {
	constructor( props ) {
		super( props );
		this.state = {
			brands: [],
			primaryBrand: null,
		};
	}

	onWizardReady = () => {
		this.fetch();
	};

	/**
	 * Fetching brands data.
	 */
	fetch() {
		const { setError, wizardApiFetch } = this.props;
		wizardApiFetch( {
			path: '/wp/v2/brand',
		} )
			.then( response => {
				this.setState( { brands: response } );
			} )
			.catch( error => setError( error ) );
	}

	/**
	 * Render
	 */
	render() {
		const headerText = __( 'Brands', 'newspack' );
		const subHeaderText = __( 'Configure brands settings', 'newspack' );
		const wizardScreenProps = {
			data: this.state,
			headerText,
			subHeaderText,
		};
		return (
			<React.StrictMode>
				<Router>
					<Routes>
						<Route
							path="/"
							element={ <BrandsList { ...wizardScreenProps } brands={ this.state.brands } /> }
						/>
					</Routes>
				</Router>
			</React.StrictMode>
		);
	}
}

domReady( () => {
	render( createElement( withWizard( App ) ), document.getElementById( 'root' ) );
} );
