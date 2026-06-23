/**
 * Unit tests for the pricing-path recipe map.
 */
import {
	RECIPES,
	LIFECYCLE_CONDITIONS,
	applyRecipeConditions,
	isConditionVisible,
	segmentSatisfied,
	intentLabel,
} from './recipes';

describe( 'recipes', () => {
	it( 'save presets pending_cancellation + locked', () => {
		expect( RECIPES.save.lifecycleCondition ).toBe( 'pending_cancellation' );
		expect( RECIPES.save.application ).toBe( 'locked' );
	} );

	it( 'retention has no lifecycle matcher, current application, requires a segment', () => {
		expect( RECIPES.retention.lifecycleCondition ).toBeNull();
		expect( RECIPES.retention.application ).toBe( 'current' );
		expect( RECIPES.retention.requiresSegment ).toBe( true );
	} );

	it( 'custom presets nothing', () => {
		expect( RECIPES.custom.lifecycleCondition ).toBeNull();
		expect( RECIPES.custom.application ).toBeNull();
		expect( RECIPES.custom.isCustom ).toBe( true );
	} );

	it( 'applyRecipeConditions clears all lifecycle matchers and sets the path one', () => {
		const start = { lapsed_subscriber: true, reader_segment: [ 5 ] };
		const next = applyRecipeConditions( 'save', start );
		expect( next.pending_cancellation ).toBe( true );
		expect( next.lapsed_subscriber ).toBeUndefined();
		expect( next.reader_segment ).toEqual( [ 5 ] );
	} );

	it( 'applyRecipeConditions for retention clears all lifecycle matchers and sets none', () => {
		const next = applyRecipeConditions( 'retention', { first_time_only: true } );
		LIFECYCLE_CONDITIONS.forEach( id => expect( next[ id ] ).toBeUndefined() );
	} );

	it( 'named paths show only select conditions; custom shows all', () => {
		expect( isConditionVisible( 'save', 'select' ) ).toBe( true );
		expect( isConditionVisible( 'save', 'boolean' ) ).toBe( false );
		expect( isConditionVisible( 'save', 'datetime' ) ).toBe( false );
		expect( isConditionVisible( 'custom', 'boolean' ) ).toBe( true );
	} );

	it( 'segmentSatisfied requires a non-empty reader_segment only for retention', () => {
		expect( segmentSatisfied( 'retention', {} ) ).toBe( false );
		expect( segmentSatisfied( 'retention', { reader_segment: [] } ) ).toBe( false );
		expect( segmentSatisfied( 'retention', { reader_segment: [ 1 ] } ) ).toBe( true );
		expect( segmentSatisfied( 'save', {} ) ).toBe( true );
	} );

	it( 'intentLabel maps known values and passes through unknown', () => {
		expect( intentLabel( 'winback' ) ).toBe( 'Win-back' );
		expect( intentLabel( 'mystery' ) ).toBe( 'mystery' );
	} );
} );
