require( '@rushstack/eslint-patch/modern-module-resolution' );

module.exports = {
	extends: [ './node_modules/newspack-scripts/config/eslintrc.js' ],
	ignorePatterns: [ 'dist/', 'node_modules/' ],
	globals: {
		newspack_urls: 'readonly',
		newspack_aux_data: 'readonly',
	},
	rules: {
		'no-nested-ternary': 'off',
		'react/display-name': 'off',
	},
};
