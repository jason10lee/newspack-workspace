/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * External dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * WordPress dependencies.
 */
import {
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	Spinner,
	Button,
	Notice,
} from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { NAMESPACE as storeNamespace } from '../store/constants';
import StoryFieldPanel from './story-field-panel';

export default ( { storyId, onCancel } ) => {
	const { fields, story, isLoadingStory, canEditStory, storyError } = useSelect( select => ( {
		fields: select( storeNamespace ).getFields(),
		story: select( storeNamespace ).getStory( storyId ),
		isLoadingStory: select( storeNamespace ).isLoadingStory( storyId ),
		canEditStory: select( storeNamespace ).canEditStory( storyId ),
		storyError: select( storeNamespace ).getStoryError( storyId ),
	} ) );
	const { saveStory, clearErrors } = useDispatch( storeNamespace );
	const [ editedStory, setEditedStory ] = useState( story );
	const [ isIframeLoading, setIsIframeLoading ] = useState( true );

	useEffect( () => {
		clearErrors( storyId );
	}, [ storyId ] );

	if ( ! story ) {
		if ( isLoadingStory ) {
			return (
				<VStack
					expanded
					style={ { height: '100%' } }
					alignment="center"
					justify="center"
				>
					<HStack expanded alignment="center" justify="center">
						<Spinner />
					</HStack>
				</VStack>
			);
		}
		return null;
	}

	const handleSave = async () => {
		clearErrors( storyId );
		await saveStory( storyId, editedStory );
	};

	const handleCancel = () => {
		clearErrors( storyId );
		onCancel?.();
	};

	const handleFieldChange = ( newStory ) => {
		clearErrors( storyId );
		setEditedStory( newStory );
	};

	return (
		<HStack
			alignment="stretch"
			spacing="0"
			className="newspack-story-budget__story"
		>
			<VStack style={ { flexGrow: 1, position: 'relative' } }>
				{ isIframeLoading && (
					<VStack
						style={ {
							position: 'absolute',
							top: 0,
							left: 0,
							right: 0,
							bottom: 0,
							background: '#fff',
							zIndex: 2,
						} }
						alignment="center"
						justify="center"
					>
						<Spinner />
					</VStack>
				) }
				{ story.metadata.preview_url && (
					<iframe
						title={ story.title }
						src={ story.metadata.preview_url }
						style={ {
							width: '100%',
							height: '100%',
							minHeight: '500px',
						} }
						onLoad={ () => setIsIframeLoading( false ) }
					/>
				) }
			</VStack>
			<VStack justify="top" className="newspack-story-budget__sidebar">
				{ ! isIframeLoading && storyError && (
					<Notice
						className="newspack-story-budget__error"
						isDismissible={ false }
						status="error"
					>
						{ storyError }
					</Notice>
				) }
				<div
					style={ {
						flexGrow: 1,
						justifyContent: 'flex-start',
						overflow: 'auto',
						padding: '16px',
					} }
				>
					<StoryFieldPanel
						fields={ fields }
						story={ story }
						onChange={ handleFieldChange }
					/>
				</div>
				{ ( canEditStory || onCancel ) && (
					<HStack
						expanded
						direction="row-reverse"
						justify="end"
						style={ { padding: '16px', boxSizing: 'border-box' } }
					>
						{ canEditStory && (
							<Button
								variant="primary"
								disabled={ isLoadingStory }
								onClick={ handleSave }
							>
								{ __( 'Save', 'newspack-story-budget' ) }
							</Button>
						) }
						<Button
							variant="secondary"
							disabled={ isLoadingStory }
							onClick={ handleCancel }
						>
							{ canEditStory
								? __( 'Cancel', 'newspack-story-budget' )
								: __( 'Close', 'newspack-story-budget' ) }
						</Button>
						{ isLoadingStory && <Spinner /> }
					</HStack>
				) }
			</VStack>
		</HStack>
	);
};
