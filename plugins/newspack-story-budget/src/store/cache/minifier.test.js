import { isMinifiable, minify, restore } from './minifier';

describe( 'minifier', () => {
	describe( 'isMinifiable', () => {
		it( 'should return false for non-object data', () => {
			expect( isMinifiable( 'string' ) ).toBe( false );
			expect( isMinifiable( 123 ) ).toBe( false );
			expect( isMinifiable( null ) ).toBe( false );
		} );

		it( 'should return true for array of objects', () => {
			const arr = [ { a: 1 }, { b: 2 } ];
			expect( isMinifiable( arr ) ).toBe( true );
		} );

		it( 'should return true for object of objects', () => {
			const obj = { a: { x: 1 }, b: { y: 2 } };
			expect( isMinifiable( obj ) ).toBe( true );
		} );

		it( 'should return false for object with non-object values', () => {
			const objectWithNonObjectValues = { a: 1, b: 'string' };
			expect( isMinifiable( objectWithNonObjectValues ) ).toBe( false );
		} );
	} );

	describe( 'minify and restore', () => {
		it( 'should minify and restore a large array of objects', () => {
			const largeArray = Array.from( { length: 10000 }, ( _, i ) => ( {
				id: i,
				title: `Post ${ i }`,
				content: { text: `Content ${ i }`, meta: { author: 'Author' } },
			} ) );

			const minified = minify( largeArray );
			expect( minified.data ).not.toBe( largeArray );
			expect( minified.keyMap ).toBeDefined();

			expect( JSON.stringify( minified.data ).length ).toBeLessThan(
				JSON.stringify( largeArray ).length
			);

			const restored = restore( minified.data, minified.keyMap );
			expect( restored ).toEqual( largeArray );
		} );

		it( 'should minify and restore a large object of objects', () => {
			const largeObject = {};
			for ( let i = 0; i < 10000; i++ ) {
				largeObject[ `post-${ i }` ] = {
					id: i,
					title: `Post ${ i }`,
					content: {
						text: `Content ${ i }`,
						meta: { author: 'Author' },
					},
				};
			}

			const minified = minify( largeObject );
			expect( minified.data ).not.toBe( largeObject );
			expect( minified.keyMap ).toBeDefined();

			expect( JSON.stringify( minified.data ).length ).toBeLessThan(
				JSON.stringify( largeObject ).length
			);

			const restored = restore( minified.data, minified.keyMap );
			expect( restored ).toEqual( largeObject );
		} );

		it( 'should handle nested objects correctly', () => {
			const nestedObject = {
				a: {
					b: {
						c: {
							d: 1,
							e: 2,
						},
					},
				},
			};

			const minified = minify( nestedObject );
			const restored = restore( minified.data, minified.keyMap );
			expect( restored ).toEqual( nestedObject );
		} );

		it( 'should handle arrays with nested objects', () => {
			const arrayWithNested = [
				{ a: { b: { c: 1 } } },
				{ a: { b: { c: 2 } } },
			];

			const minified = minify( arrayWithNested );
			const restored = restore( minified.data, minified.keyMap );
			expect( restored ).toEqual( arrayWithNested );
		} );
	} );

	describe( 'edge cases', () => {
		it( 'should handle empty objects and arrays', () => {
			expect( minify( {} ).data ).toEqual( {} );
			expect( minify( [] ).data ).toEqual( [] );
		} );

		it( 'should handle null and undefined values', () => {
			const data = { a: null, b: undefined };
			const minified = minify( data );
			const restored = restore( minified.data, minified.keyMap );
			expect( restored ).toEqual( data );
		} );

		it( 'should handle restoring without keyMap', () => {
			const data = { a: 1, b: 2 };
			const minified = minify( data );
			expect( restore( minified.data ) ).toEqual( minified.data );
		} );
	} );
} );
