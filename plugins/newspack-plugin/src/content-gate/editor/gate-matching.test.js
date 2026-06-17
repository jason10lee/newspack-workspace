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

	it( 'ANY: exclusion rule carves a post out even when an inclusion rule matches', () => {
		const r = [
			{ slug: 'post_types', value: [ 'post' ] },
			{ slug: 'category', value: [ 5 ], exclusion: true },
		];
		// Post is in the excluded category 5: matches the post_types inclusion but is carved out.
		expect( gateMatchesPost( r, 'post', { categories: [ 5 ] }, 1, 'any', taxonomyMap ) ).toBe( false );
		// Post not in the excluded category: gated via the inclusion rule.
		expect( gateMatchesPost( r, 'post', { categories: [] }, 1, 'any', taxonomyMap ) ).toBe( true );
	} );
} );
