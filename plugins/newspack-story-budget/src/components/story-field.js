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
	Notice,
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
import utils from '../utils';

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
	const { story, field, canEditStory, isLoadingStory, fieldError } =
		useSelect( select => ( {
			story: select( storeNamespace ).getStory( storyId ),
			field: select( storeNamespace ).getField( fieldId ),
			isLoadingStory: select( storeNamespace ).isLoadingStory( storyId ),
			canEditStory: select( storeNamespace ).canEditStory( storyId ),
			fieldError: select( storeNamespace ).getFieldError(
				storyId,
				fieldId
			),
		} ) );

	const { saveStoryField, clearErrors } = useDispatch( storeNamespace );

	value = value !== undefined ? value : story[ fieldId ];

	const [ editedValue, setEditedValue ] = useState( value );
	const [ isOpen, setIsOpen ] = useState( saveInPlace && !! fieldError );

	if ( ! field ) {
		return null;
	}

	const canEdit = allowEdit && canEditStory && field.is_editable;

	const displayValue = utils.fields.getDisplayValue( field, value );

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
				open={ isOpen && ! isLoadingStory }
				popoverProps={
					popoverProps || {
						placement: 'right-start',
						shift: true,
					}
				}
				contentClassName="newspack-story-budget__field__popover"
				onToggle={ () => setIsOpen( ! isOpen ) }
				renderToggle={ ( { onToggle } ) => (
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
						{ saveInPlace && fieldError && (
							<Notice
								className="newspack-story-budget__error"
								isDismissible={ false }
								status="error"
							>
								{ fieldError }
							</Notice>
						) }
						{ field.description && field.type !== 'boolean' && (
							<p>{ field.description }</p>
						) }
						<form
							onSubmit={ async e => {
								e.preventDefault();
								if ( saveInPlace ) {
									const response = await saveStoryField(
										storyId,
										fieldId,
										editedValue
									);

									// Reopen the popover if there is an error.
									if ( response?.payload?.message ) {
										setIsOpen( true );
									}
								}
							} }
						>
							<VStack spacing={ 4 }>
								<StoryFieldControl
									field={ field }
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
											disabled={ value === editedValue }
											type="submit"
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
					clearErrors( storyId, fieldId );
					onCloseEdit( editedValue );
				} }
			/>
		</div>
	);
};
