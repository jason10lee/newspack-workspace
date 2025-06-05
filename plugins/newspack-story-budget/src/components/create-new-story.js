/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	Button,
	TextControl,
	SelectControl,
} from '@wordpress/components';
import StoryFieldControl from './story-field-control';

/**
 * Internal dependencies.
 */
import { NAMESPACE as storeNamespace } from '../store/constants';

const CreateStoryModal = ( { onClose } ) => {
	const [ storyName, setStoryName ] = useState( '' );
	const [ selectedBudget, setSelectedBudget ] = useState( '' );
	const [ newBudgetName, setNewBudgetName ] = useState( '' );
	const [ customFieldValues, setCustomFieldValues ] = useState( {} );

	const {
		budgetsField,
		storyError,
		budgetError,
		isCreatingStory,
		isCreatingBudget,
		allfields,
	} = useSelect( select => ( {
		budgetsField: select( storeNamespace ).getField( 'budgets' ),
		storyError: select( storeNamespace ).getErrors()?.storyError || null,
		budgetError: select( storeNamespace ).getErrors()?.budgetError || null,
		isCreatingStory: select( storeNamespace ).isCreatingStory(),
		isCreatingBudget: select( storeNamespace ).isCreatingBudget(),
		allfields: select( storeNamespace ).getFields() || {},
	} ) );

	const { createStory, fetchFields, clearErrors } =
		useDispatch( storeNamespace );

	const isSubmitting = isCreatingStory || isCreatingBudget;
	const error = storyError || budgetError;

	const budgetOptions = [
		{ value: '', label: __( 'Select a budget', 'newspack-story-budget' ) },
		{
			value: 'new',
			label: __( 'Add new budget', 'newspack-story-budget' ),
		},
		...( budgetsField?.options || [] ),
	];

	const handleFieldChange = ( fieldSlug, newValue ) => {
		setCustomFieldValues( prev => ( {
			...prev,
			[ fieldSlug ]: newValue,
		} ) );
	};

	const handleSubmit = async e => {
		e.preventDefault();

		clearErrors();

		const createStoryArgs = {
			name: storyName.trim(),
			budgets: [],
			... customFieldValues,
		};

		if ( selectedBudget === 'new' ) {
			createStoryArgs.newBudgetName = newBudgetName.trim();
		} else if ( selectedBudget ) {
			createStoryArgs.budgets = [ selectedBudget ];
		}
		const result = await createStory( createStoryArgs );
		if ( result?.id ) {
			if ( selectedBudget === 'new' ) {
				await fetchFields();
			}
			onClose();
		}
	};
	return (
		<form onSubmit={ handleSubmit }>
			<VStack spacing={ 4 }>
				<div>
					<TextControl
						label={ __( 'Story Name', 'newspack-story-budget' ) }
						id="story-name"
						value={ storyName }
						onChange={ setStoryName }
						disabled={ isSubmitting }
						required
					/>
				</div>

				<div>
					<SelectControl
						label={ __( 'Budget', 'newspack-story-budget' ) }
						id="story-budget"
						value={ selectedBudget }
						options={ budgetOptions }
						onChange={ setSelectedBudget }
						disabled={ isSubmitting }
						required
					/>
				</div>

				{ selectedBudget === 'new' && (
					<div>
						<TextControl
							label={ __(
								'New Budget Name',
								'newspack-story-budget'
							) }
							id="new-budget-name"
							value={ newBudgetName }
							onChange={ setNewBudgetName }
							disabled={ isSubmitting }
							required={ selectedBudget === 'new' }
						/>
					</div>
				) }

				{ Object.entries( allfields )
					.filter( ( [ , field ] ) => field?.show_in_add_new_story )
					.map( ( [ , field ] ) => (
						<div key={ field.slug }>
							<div className="newspack-story-budget__field-label">
								{ field.name }
							</div>
							<StoryFieldControl
								field={ field }
								value={ customFieldValues[ field.slug ] }
								onChange={ newValue => handleFieldChange( field.slug, newValue ) }
							/>
						</div>
					) )
				}
				{ error && (
					<div className="newspack-story-budget__error-message">
						{ error.message }
					</div>
				) }

				<HStack justify="end">
					<Button
						variant="secondary"
						onClick={ onClose }
						disabled={ isSubmitting }
					>
						{ __( 'Cancel', 'newspack-story-budget' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						disabled={
							! storyName.trim() ||
							! selectedBudget ||
							isSubmitting ||
							( selectedBudget === 'new' &&
								! newBudgetName.trim() )
						}
						isBusy={ isSubmitting }
					>
						{ __( 'Save', 'newspack-story-budget' ) }
					</Button>
				</HStack>
			</VStack>
		</form>
	);
};

export default CreateStoryModal;
