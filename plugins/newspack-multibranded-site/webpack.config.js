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
const admin = path.join( __dirname, 'src' );

const webpackConfig = getBaseWebpackConfig(
	{ WP: true },
	{
		entry: { admin },
		'output-path': path.join( __dirname, 'dist' ),
	}
);

module.exports = webpackConfig;
