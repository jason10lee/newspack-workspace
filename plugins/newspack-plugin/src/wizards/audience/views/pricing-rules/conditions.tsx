/**
 * Eligibility conditions editor. Renders each condition matcher from the REST
 * vocab generically by its `field_type` (boolean → toggle, datetime →
 * datetime-local), bound to a { matcher_id: value } map. New matchers the engine
 * registers appear automatically — no change here.
 */

/**
 * WordPress dependencies
 */
import {
	TextControl,
	ToggleControl,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { tsToLocalInput, localInputToTs } from './datetime';

type ConditionsMap = { [ id: string ]: boolean | number | null };

interface ConditionsProps {
	vocab: PricingRuleConditionVocab[];
	value: ConditionsMap;
	onChange: ( next: ConditionsMap ) => void;
}

export default function Conditions( { vocab, value, onChange }: ConditionsProps ) {
	if ( ! vocab?.length ) {
		return null;
	}

	const setOne = ( id: string, v: boolean | number | null ) => onChange( { ...value, [ id ]: v } );

	return (
		<VStack spacing={ 4 }>
			{ vocab.map( matcher => {
				if ( 'datetime' === matcher.field_type ) {
					const ts = typeof value[ matcher.id ] === 'number' ? ( value[ matcher.id ] as number ) : null;
					return (
						<TextControl
							key={ matcher.id }
							label={ matcher.label }
							help={ matcher.help }
							type="datetime-local"
							value={ tsToLocalInput( ts ) }
							onChange={ s => setOne( matcher.id, localInputToTs( s ) ) }
							__next40pxDefaultSize
						/>
					);
				}
				// Boolean toggle for 'boolean' and as the graceful default for any
				// unknown field type.
				return (
					<ToggleControl
						key={ matcher.id }
						label={ matcher.label }
						help={ matcher.help }
						checked={ Boolean( value[ matcher.id ] ) }
						onChange={ v => setOne( matcher.id, v ) }
						__nextHasNoMarginBottom
					/>
				);
			} ) }
		</VStack>
	);
}
