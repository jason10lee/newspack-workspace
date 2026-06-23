/**
 * Unit tests for the pricing-model sentence builder.
 */
import { pricingModelSentence } from './model-sentence';

const currency = { code: 'USD', symbol: '$', decimals: 2 };

const flat = simple => ( { is_stepped: false, simple, steps: null, strategy_label: 'Flat adjustment' } );

describe( 'pricingModelSentence', () => {
	it( 'flat fixed price reads as "Set price to $X"', () => {
		const item = flat( { calc_type: 'fixed_price', value: 80, cycles_limit: 0 } );
		expect( pricingModelSentence( item, currency ) ).toBe( 'Set price to $80.00' );
	} );

	it( 'flat percentage reads as "N% of regular price"', () => {
		const item = flat( { calc_type: 'percent_of_base', value: 80, cycles_limit: 0 } );
		expect( pricingModelSentence( item, currency ) ).toBe( '80% of regular price' );
	} );

	it( 'flat fixed discount reads as "$X off regular price"', () => {
		const item = flat( { calc_type: 'discount_fixed', value: 5, cycles_limit: 0 } );
		expect( pricingModelSentence( item, currency ) ).toBe( '$5.00 off regular price' );
	} );

	it( 'a cycles limit adds a "first N cycles" suffix', () => {
		const item = flat( { calc_type: 'discount_fixed', value: 5, cycles_limit: 3 } );
		expect( pricingModelSentence( item, currency ) ).toBe( '$5.00 off regular price · first 3 cycles' );
	} );

	it( 'stepped reads as an arrow progression by cycle', () => {
		const item = {
			is_stepped: true,
			simple: null,
			strategy_label: 'Stepped by cycle',
			steps: [
				{ at: 1, calc_type: 'fixed_price', value: 80 },
				{ at: 2, calc_type: 'fixed_price', value: 90 },
				{ at: 3, calc_type: 'fixed_price', value: 100 },
			],
		};
		expect( pricingModelSentence( item, currency ) ).toBe( '$80.00 → $90.00 → $100.00 by cycle' );
	} );

	it( 'falls back to the strategy label for an unknown calc type', () => {
		const item = flat( { calc_type: 'mystery', value: 1, cycles_limit: 0 } );
		expect( pricingModelSentence( item, currency ) ).toBe( 'Flat adjustment' );
	} );
} );
