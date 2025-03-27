/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import {
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	Dropdown,
	Button,
	Tooltip,
} from '@wordpress/components';
import { __experimentalInspectorPopoverHeader as InspectorPopoverHeader } from '@wordpress/block-editor';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { NAMESPACE as storeNamespace } from '../store/constants';
import StoryFieldControl from './story-field-control';

const getDisplayValue = ( field, value ) => {
	if (
		value === null ||
		value === undefined ||
		( Array.isArray( value ) && ! value.length ) ||
		( [ 'date', 'datetime', 'text', 'longtext' ].includes( field.type ) &&
			! value )
	) {
		return null;
	}
	if ( field.options?.length ) {
		if ( Array.isArray( value ) ) {
			value = value.map(
				v => field.options.find( o => o.value === v )?.label || v
			);
		}
		value = field.options.find( o => o.value === value )?.label || value;
	}
	if ( field.type === 'date' ) {
		return new Date( value * 1000 ).toLocaleDateString( undefined, {
			dateStyle: 'medium',
		} );
	}
	if ( field.type === 'datetime' ) {
		return new Date( value * 1000 ).toLocaleString( undefined, {
			dateStyle: 'medium',
			timeStyle: 'short',
		} );
	}
	if ( field.type === 'boolean' ) {
		return value ? 'Yes' : 'No';
	}
	if ( Array.isArray( value ) ) {
		return value.join( ', ' );
	}
	return value;
};

export default ( {
	fieldId,
	storyId,
	value,
	onChange = () => {},
	onCloseEdit = () => {},
	allowEdit = true,
	saveInPlace = false,
	popoverProps,
} ) => {
	const { story, field, canEditPost, isLoadingStory } = useSelect(
		select => ( {
			story: select( storeNamespace ).getStory( storyId ),
			field: select( storeNamespace ).getField( fieldId ),
			isLoadingStory: select( storeNamespace ).isLoadingStory( storyId ),
			canEditPost: select( 'core' ).canUser( 'update', {
				kind: 'postType',
				name: 'post',
				id: storyId,
			} ),
		} )
	);

	const { saveStoryField } = useDispatch( storeNamespace );

	value = value !== null && value !== undefined ? value : story[ fieldId ];

	const [ editedValue, setEditedValue ] = useState( value );

	if ( ! field ) {
		return null;
	}

	const canEdit = allowEdit && canEditPost && field.is_editable;

	const displayValue = getDisplayValue( field, value );

	const collapsedValue =
		displayValue?.length > 70
			? `${ displayValue.slice( 0, 67 ) }...`
			: null;

	if ( ! canEdit ) {
		return (
			<div className="newspack-story-budget__field">
				{ collapsedValue ? (
					<Tooltip
						text={ displayValue }
						delay={ 300 }
						placement="bottom-start"
						className="newspack-story-budget__field__value-tooltip"
					>
						<span className="newspack-story-budget__field__value">
							{ collapsedValue }
						</span>
					</Tooltip>
				) : (
					<span className="newspack-story-budget__field__value">
						{ displayValue !== null ? displayValue : '--' }
					</span>
				) }
			</div>
		);
	}

	return (
		<div className="newspack-story-budget__field">
			<Dropdown
				popoverProps={
					popoverProps || {
						placement: 'right-start',
						shift: true,
					}
				}
				contentClassName="newspack-story-budget__field__popover"
				renderToggle={ ( { isOpen, onToggle } ) => (
					<Button
						className="newspack-story-budget__field__button"
						variant="tertiary"
						onClick={ onToggle }
						disabled={ isLoadingStory }
						aria-expanded={ isOpen }
						title={
							field.is_editable
								? `Edit ${ field.name }`
								: undefined
						}
					>
						{ displayValue === null ? (
							<span className="newspack-story-budget__field__empty-value">
								Click to set
							</span>
						) : (
							collapsedValue || displayValue
						) }
					</Button>
				) }
				renderContent={ ( { onClose } ) => (
					<>
						<InspectorPopoverHeader
							title={ field.name }
							onClose={ onClose }
						/>
						{ field.description && field.type !== 'boolean' && (
							<p>{ field.description }</p>
						) }
						<form
							onSubmit={ e => {
								e.preventDefault();
								if ( saveInPlace ) {
									saveStoryField(
										storyId,
										fieldId,
										editedValue
									);
								}
								onClose();
							} }
						>
							<VStack spacing={ 4 }>
								<StoryFieldControl
									storyId={ storyId }
									fieldId={ fieldId }
									value={ editedValue }
									onChange={ val => {
										setEditedValue( val );
										onChange( val );
									} }
								/>
								{ saveInPlace && (
									<HStack
										expanded
										spacing={ 2 }
										justify="end"
										direction="row-reverse"
									>
										<Button
											variant="primary"
											onClick={ () => {
												saveStoryField(
													storyId,
													fieldId,
													editedValue
												);
												onClose();
											} }
										>
											{ __(
												'Save',
												'newspack-story-budget'
											) }
										</Button>
										<Button
											variant="secondary"
											onClick={ () => {
												onClose();
												setEditedValue( value );
											} }
										>
											{ __(
												'Cancel',
												'newspack-story-budget'
											) }
										</Button>
									</HStack>
								) }
							</VStack>
						</form>
					</>
				) }
				onClose={ () => {
					onCloseEdit( editedValue );
				} }
			/>
		</div>
	);
};
