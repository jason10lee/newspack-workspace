require( '@rushstack/eslint-patch/modern-module-resolution' );

module.exports = {
	extends: [ './node_modules/newspack-scripts/config/eslintrc.js' ],
	ignorePatterns: [ 'dist/', 'node_modules/' ],
	rules: {
		'no-nested-ternary': 'off',
		'react/display-name': 'off',
	},
};
