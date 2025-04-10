/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * External dependencies.
 */
import debounce from 'lodash/debounce';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews/wp';
import {
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	Icon,
	Button,
	Modal,
	Notice,
	ProgressBar,
	Spinner,
	ToggleControl,
	Tooltip,
} from '@wordpress/components';
import { edit, error, seen, update } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import utils from '../utils';
import { NAMESPACE as storeNamespace } from '../store/constants';
import StoryField from './story-field';

const TableRowField = ( { story, field, allowEdit = false } ) => {
	const { isLoadingStory, storyError, view } = useSelect( select => ( {
		isLoadingStory: select( storeNamespace ).isLoadingStory( story.id ),
		storyError: select( storeNamespace ).getStoryError( story.id ),
		view: select( storeNamespace ).getView(),
	} ) );

	const fieldIdx = view.fields.findIndex( f => f === field.slug );

	return (
		<div className="newspack-story-budget__table-row-field">
			<StoryField
				fieldId={ field.slug }
				storyId={ story.id }
				allowEdit={ allowEdit }
				saveInPlace
			/>
			{ fieldIdx === 0 && isLoadingStory && (
				<Spinner
					style={ {
						width: '13px',
						height: '13px',
					} }
				/>
			) }
			{ fieldIdx === 0 && ! isLoadingStory && storyError && (
				<Tooltip text={ storyError }>
					<span className="newspack-story-budget__table-row-field-error">
						<Icon icon={ error } />
					</span>
				</Tooltip>
			) }
		</div>
	);
};

export default () => {
	const { view, stories, fields, isLoading, isRefreshing, progress, errors } = useSelect(
		select => ( {
			view: select( storeNamespace ).getView(),
			stories: select( storeNamespace ).getStories(),
			fields: select( storeNamespace ).getFields(),
			isLoading: select( storeNamespace ).isLoading(),
			isRefreshing: select( storeNamespace ).isRefreshing(),
			progress: select( storeNamespace ).getProgress(),
			errors: select( storeNamespace ).getErrors(),
		} )
	);
	const [ editMode, setEditMode ] = useState( false );
	const [ modalOpen, setModalOpen ] = useState( false );
	const currentStories = stories.slice(
		( view.page - 1 ) * view.perPage,
		( view.page - 1 ) * view.perPage + view.perPage
	);

	const {
		clearErrors,
		setView,
		setSearching,
		search,
		fetchFields,
		fetchStory,
		refreshStories,
	} = useDispatch( storeNamespace );

	const doSearch = debounce( search, 300 );

	useEffect( () => {
		if ( view.search ) {
			setSearching();
			doSearch( view.search );
		}
	}, [ view.search ] );

	if ( isLoading && undefined !== progress && progress < 1 ) {
		return (
			<div className="newspack-story-budget__loading">
				<ProgressBar value={ Math.ceil( progress * 100 ) } />
				<p>Fetching Stories...</p>
			</div>
		);
	}

	const getFieldElements = field => {
		if ( ! field.is_filterable ) {
			return undefined;
		}
		if ( field.options?.length ) {
			return field.options;
		}
		if ( field.type === 'boolean' ) {
			return [
				{ value: true, label: __( 'Yes', 'newspack-story-budget' ) },
				{ value: false, label: __( 'No', 'newspack-story-budget' ) },
			];
		}
		// Fallback to unique values.
		const values = utils.fields.getUniqueValues( field );
		if ( ! values.length ) {
			return undefined;
		}
		return values.map( value => ( {
			value,
			label: value,
		} ) );
	};

	const getFilterByOperators = field => {
		if ( field.is_multiple ) {
			return [ 'isAny', 'isNone', 'isAll', 'isNotAll' ];
		}
		if ( field.type === 'boolean' ) {
			return [ 'is' ];
		}
		return [ 'isAny', 'isNone' ];
	};

	const dataViewFields = fields.map( field => ( {
		id: field.slug,
		label: field.name,
		isVisible: () => field.show_in_table || field.always_visible_in_table,
		type: field.type,
		enableHiding: ! field.always_visible_in_table,
		enableSorting: field.is_sortable,
		elements: getFieldElements( field ),
		filterBy: field.is_filterable
			? {
					operators: getFilterByOperators( field ),
					isPrimary: field.slug === 'budgets',
			  }
			: undefined,
		render: value => (
			<TableRowField
				story={ value.item }
				field={ field }
				allowEdit={ editMode && ! isRefreshing }
			/>
		),
	} ) );

	const refresh = () => {
		clearErrors();
		fetchFields();
		refreshStories( false );
	};

	const actions = [
		{
			id: 'view',
			label: 'View',
			isPrimary: true,
			icon: <Icon icon={ seen } />,
			callback: items => {
				fetchStory( items[ 0 ].id );
				window.location.hash = '#/stories/' + items[ 0 ].id;
			},
		},
		{
			id: 'refresh',
			label: 'Refresh',
			isPrimary: false,
			icon: <Icon icon={ update } />,
			callback: items => {
				clearErrors( items[ 0 ].id );
				fetchStory( items[ 0 ].id );
			},
		},
		{
			id: 'edit',
			label: 'Edit Post',
			isEligible: item => !! item.metadata?.edit_url,
			isPrimary: false,
			icon: <Icon icon={ edit } />,
			callback: items => {
				if ( items[ 0 ].metadata?.edit_url ) {
					window.open( items[ 0 ].metadata.edit_url );
				}
			},
		},
	];

	if ( errors?.stories ) {
		return (
			<Modal
				isOpen={ modalOpen }
				onRequestClose={ () => {
					setModalOpen( false );
					clearErrors();
				} }
				size="small"
				title={ __( 'Something went wrong', 'newspack-story-budget' ) }
			>
				<VStack spacing={ 4 }>
					<Notice
						className="newspack-story-budget__error"
						isDismissible={ false }
						status="error"
					>
						{ errors.stories }
					</Notice>
					<HStack
						expanded
						spacing={ 2 }
						justify="end"
						direction="row-reverse"
					>
						<Button
							variant="primary"
							onClick={ refresh }
						>
							{ __( 'Refetch stories', 'newspack-story-budget' ) }
						</Button>
					</HStack>
				</VStack>
			</Modal>
		);
	}

	return (
		<DataViews
			isLoading={ isLoading }
			fields={ dataViewFields }
			view={ view }
			onChangeView={ setView }
			actions={ actions }
			data={ isLoading ? [] : currentStories }
			paginationInfo={ {
				totalItems: stories.length,
				totalPages: Math.ceil( stories.length / view.perPage ),
			} }
			defaultLayouts={ {
				table: {
					showMedia: false,
				},
			} }
			header={
				<HStack spacing={ 4 } style={ { marginLeft: '12px' } }>
					<Button
						className={ isLoading || isRefreshing ? 'newspack-story-budget__refresh-button-is-busy' : 'newspack-story-budget__refresh-button' }
						icon={ <Icon icon={ update } /> }
						disabled={ isLoading || isRefreshing }
						label={ isLoading || isRefreshing ? __( 'Loading stories…', 'newspack-story-budget' ) : __( 'Refresh all stories', 'newspack-story-budget' ) }
						onClick={ refresh }
					/>
					<ToggleControl
						label="Edit mode"
						checked={ editMode }
						onChange={ setEditMode }
						__nextHasNoMarginBottom
					/>
				</HStack>
			}
		/>
	);
};
