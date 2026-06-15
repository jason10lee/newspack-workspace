/**
 * Internal dependencies
 */
import { gateMatchesPost } from './gate-matching';

const taxonomyMap = { category: 'categories' };

describe( 'gateMatchesPost', () => {
	const rules = [
		{ slug: 'category', value: [ 5 ] },
		{ slug: 'newsletters', value: [ 999 ] },
	];
	const termsByTax = { categories: [ 5 ] };

	it( 'AND: does not match when only one rule matches', () => {
		expect( gateMatchesPost( rules, 'post', termsByTax, 1, 'all', taxonomyMap ) ).toBe( false );
	} );

	it( 'ANY: matches when any one rule matches', () => {
		expect( gateMatchesPost( rules, 'post', termsByTax, 1, 'any', taxonomyMap ) ).toBe( true );
	} );

	it( 'specific_posts override matches regardless of mode', () => {
		const r = [
			{ slug: 'specific_posts', value: [ 1 ] },
			{ slug: 'category', value: [ 5 ] },
		];
		expect( gateMatchesPost( r, 'post', {}, 1, 'all', taxonomyMap ) ).toBe( true );
	} );

	it( 'defaults to AND when mode omitted', () => {
		expect( gateMatchesPost( rules, 'post', termsByTax, 1, undefined, taxonomyMap ) ).toBe( false );
	} );
} );
