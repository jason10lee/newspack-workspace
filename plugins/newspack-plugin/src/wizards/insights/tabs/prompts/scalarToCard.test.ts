/**
 * Tests for the Prompts tab-local scalarToCard adapter, focused on the
 * per-intent capability routing (NPPD-1720). Precedence under test:
 * error > not-capable > normal value.
 */

/**
 * Internal dependencies
 */
import { scalarToMetricCardProps } from './scalarToCard';
import type { PromptsScalarMetric } from '../../api/prompts';

const scalar = ( overrides: Partial< PromptsScalarMetric > = {} ): PromptsScalarMetric => ( {
	state: 'populated',
	value: 0.42,
	computable: true,
	denominator: null,
	placeholder_type: 'rate',
	...overrides,
} );

describe( 'prompts scalarToMetricCardProps — capability routing (NPPD-1720)', () => {
	it( 'routes has_capability:false to the supplied not-capable message', () => {
		const props = scalarToMetricCardProps( {
			label: 'Donation Conversion (Direct)',
			description: 'd',
			current: scalar( { has_capability: false } ),
			notCapableMessage: 'Add a Donate block.',
		} );
		expect( props.notCapableMessage ).toBe( 'Add a Donate block.' );
		// The value path is skipped entirely.
		expect( props ).not.toHaveProperty( 'value' );
	} );

	it( 'falls back to a generic message when the section omits copy', () => {
		const props = scalarToMetricCardProps( {
			label: 'x',
			description: 'd',
			current: scalar( { has_capability: false } ),
		} );
		expect( props.notCapableMessage ).toBe( 'Not measurable for your active prompts' );
	} );

	it( 'lets the error treatment win over not-capable', () => {
		const props = scalarToMetricCardProps( {
			label: 'x',
			description: 'd',
			current: scalar( { state: 'error', has_capability: false, error_message: 'boom' } ),
			notCapableMessage: 'Add a Donate block.',
		} );
		expect( props.error ).toBe( 'boom' );
		expect( props ).not.toHaveProperty( 'notCapableMessage' );
	} );

	it( 'renders the normal value when capable, ignoring any message', () => {
		const props = scalarToMetricCardProps( {
			label: 'x',
			description: 'd',
			current: scalar( { has_capability: true } ),
			notCapableMessage: 'unused',
		} );
		expect( props.value ).toBe( 0.42 );
		expect( props ).not.toHaveProperty( 'notCapableMessage' );
	} );

	it( 'treats absent has_capability as capable (matches server fail-open)', () => {
		const props = scalarToMetricCardProps( {
			label: 'x',
			description: 'd',
			current: scalar(),
		} );
		expect( props.value ).toBe( 0.42 );
		expect( props ).not.toHaveProperty( 'notCapableMessage' );
	} );
} );
