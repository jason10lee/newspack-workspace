/**
 **** WARNING: No ES6 modules here. Not transpiled! ****
 */
/* eslint-disable import/no-nodejs-modules */

/**
 * External dependencies
 */
const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );
const path = require( 'path' );

/**
 * Internal variables
 */
const entry = {
	admin: path.join( __dirname, 'src/admin' ),
	postPrimaryBrand: path.join( __dirname, 'src/post-primary-brand' ),
	promptBrands: path.join( __dirname, 'src/prompt-brands' ),
};

Object.keys( entry ).forEach( key => {
	entry[ key ] = [ 'regenerator-runtime/runtime', entry[ key ] ];
} );

const webpackConfig = getBaseWebpackConfig(
	{ WP: true },
	{
		entry,
		'output-path': path.join( __dirname, 'dist' ),
	}
);

module.exports = webpackConfig;
