/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import {
	__experimentalInputControl as InputControl,
	__experimentalVStack as VStack,
	CheckboxControl,
	SelectControl,
	DatePicker,
	DateTimePicker,
	TextareaControl,
} from '@wordpress/components';
import { useId } from '@wordpress/element';

export default ( { field, value, onChange = () => {} } ) => {
	const componentId = useId();

	const getOptionId = option => `${ componentId }-${ option.value }`;

	if ( ! field ) {
		return null;
	}

	const controlProps = {
		label: field.title,
		hideLabelFromVision: true,
		onChange: val => {
			if ( field.type === 'date' || field.type === 'datetime' ) {
				val = parseInt( new Date( val ).getTime() / 1000 );
			}
			if ( field.type === 'number' && val !== '' ) {
				if ( field.is_multiple ) {
					val = val.map( v => v * 1 );
				} else {
					val = val * 1;
				}
			}
			// If the value is an empty string, set it to null so it skips type check and clears the field.
			if ( val === '' ) {
				val = null;
			}
			onChange( val );
		},
	};

	// The budgets field should always render a select control.
	if ( field.slug === 'budgets' ) {
		return (
			<SelectControl
				options={ [
					{
						value: '',
						label: __( 'No budget', 'newspack-story-budget' ),
					},
					...field.options,
				] }
				value={ value || '' }
				multiple={ field.is_multiple }
				{ ...controlProps }
			/>
		);
	}

	if ( field.options?.length ) {
		const options = field.options.map( option => ( {
			...option,
			label: option.label || option.name,
			disabled: ! option.user_can_apply || option.disabled,
		} ) );

		if ( field.is_multiple ) {
			return (
				<VStack spacing={ 2 }>
					{ options.map( option => (
						<CheckboxControl
							key={ option.value }
							label={ option.label }
							disabled={ option.disabled }
							checked={ value.includes( option.value ) }
							value={ value }
							onChange={ checked => {
								onChange(
									checked
										? [ ...value, option.value ]
										: value.filter(
												v => v !== option.value
										  )
								);
							} }
						/>
					) ) }
				</VStack>
			);
		}
		return (
			<VStack spacing={ 2 }>
				{ options.map( option => (
					<div
						key={ getOptionId( option ) }
						className="newspack-story-budget__control__radio-option"
					>
						<input
							id={ getOptionId( option ) }
							type="radio"
							label={ option.label }
							disabled={ option.disabled }
							checked={ value === option.value }
							value={ option.value }
							onChange={ ev => onChange( ev.target.value ) }
						/>
						<label htmlFor={ getOptionId( option ) }>
							{ option.label }
						</label>
					</div>
				) ) }
			</VStack>
		);
	}

	if ( field.type === 'date' ) {
		return (
			<DatePicker
				currentDate={ new Date( value * 1000 ) }
				{ ...controlProps }
			/>
		);
	}

	if ( field.type === 'datetime' ) {
		return (
			<DateTimePicker
				currentDate={ new Date( value * 1000 ) }
				{ ...controlProps }
			/>
		);
	}

	if ( field.type === 'longtext' ) {
		return <TextareaControl value={ value || '' } { ...controlProps } />;
	}

	if ( field.type === 'boolean' ) {
		return (
			<CheckboxControl
				checked={ value }
				label={ field.description || field.name }
				onChange={ onChange }
			/>
		);
	}

	if ( field.type === 'number' ) {
		controlProps.type = 'number';
	}

	return <InputControl value={ value || '' } { ...controlProps } />;
};
