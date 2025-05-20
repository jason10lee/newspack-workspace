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
	Notice
} from '@wordpress/components';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies.
 */
import { NAMESPACE as storeNamespace } from '../store/constants';

const CreateBudgetModal = ( { onClose } ) => {
	const [ budgetName, setBudgetName ] = useState( '' );

	const {
		budgetError,
		isCreatingBudget,
	} = useSelect( select => ( {
		budgetError: select( storeNamespace ).getErrors()?.budgetError || null,
		isCreatingBudget: select( storeNamespace ).isCreatingBudget(),
	} ) );

	const { createBudget, clearErrors } = useDispatch( storeNamespace );
	const { createNotice, removeNotice } = useDispatch( noticesStore );

	const handleSubmit = async e => {
		e.preventDefault();
		clearErrors();

		if ( ! budgetName.trim() ) {
			return;
		}

		const result = await createBudget( { name: budgetName.trim() } );
		if ( result?.id ) {
			createNotice(
				'success',
				`"${budgetName}" budget saved. `,
				{
					id: result.id,
					type: 'snackbar',
					context: 'newspack-story-budget',
					actions: [
						{
							url: `#/stories/new/${result.id}`,
							label: __( 'Create a new story', 'newspack-story-budget' ),
							onClick: () => { removeNotice( result.id, 'newspack-story-budget' ); },
						},
						/**
						 * TODO: Figure out how to display multiple actions in snackbar.
						 * ref: https://github.com/WordPress/gutenberg/blob/7b3850b6a39ce45948f09efe750451c6323a4613/packages/components/src/snackbar/index.tsx#L120-L127
						 */
					],
					onDismiss: () => { removeNotice( result.id, 'newspack-story-budget' ); }
				}
			);
			onClose();
		}
	};

	return (
		<form onSubmit={ handleSubmit }>
			<VStack spacing={ 4 }>
				<div>
					<TextControl
						label={ __( 'Budget Name', 'newspack-story-budget' ) }
						value={ budgetName }
						onChange={ setBudgetName }
						disabled={ isCreatingBudget }
						required
					/>
				</div>

				{ budgetError && (
					<Notice
						status="error"
						onRemove={ clearErrors }
					>
						{ budgetError.message }
					</Notice>
				) }

				<HStack justify="end">
					<Button
						variant="secondary"
						onClick={ onClose }
						disabled={ isCreatingBudget }
					>
						{ __( 'Cancel', 'newspack-story-budget' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						disabled={ ! budgetName.trim() || isCreatingBudget }
						isBusy={ isCreatingBudget }
					>
						{ __( 'Save', 'newspack-story-budget' ) }
					</Button>
				</HStack>
			</VStack>
		</form>
	);
};

export default CreateBudgetModal;
