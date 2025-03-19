/**
 * External dependencies.
 */
import debounce from 'lodash/debounce';

/**
 * WordPress dependencies.
 */
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews/wp';
import { Icon, ProgressBar } from '@wordpress/components';
import { external, update } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { NAMESPACE as storeNamespace } from '../store/constants';
import Field from './field';

export default () => {
	const { view, stories, fields, isLoading, progress } = useSelect(
		select => ( {
			view: select( storeNamespace ).getView(),
			stories: select( storeNamespace ).getStories(),
			fields: select( storeNamespace ).getFields(),
			isLoading: select( storeNamespace ).isLoading(),
			progress: select( storeNamespace ).getProgress(),
		} )
	);

	const { setView, setSearching, search } = useDispatch( storeNamespace );

	const doSearch = debounce( search, 300 );

	useEffect( () => {
		if ( view.search ) {
			setSearching();
			doSearch( view.search );
		}
	}, [ view.search ] );

	if ( isLoading && progress < 1 ) {
		return (
			<div className="newspack-story-budget-loading">
				<ProgressBar value={ Math.ceil( progress * 100 ) } />
				<p>Fetching Stories...</p>
			</div>
		);
	}

	return (
		<DataViews
			isLoading={ isLoading }
			data={
				isLoading
					? []
					: stories.slice(
							( view.page - 1 ) * view.perPage,
							( view.page - 1 ) * view.perPage + view.perPage
					  )
			}
			fields={ fields.map( field => ( {
				id: field.slug,
				label: field.name,
				isVisible: field.show_in_table,
				type: field.type,
				enableSorting: field.is_sortable,
				elements: field.options?.length ? field.options : undefined,
				filterBy: field.is_filterable
					? {
							operators: field.is_multiple
								? [ 'isAny', 'isNone', 'isAll', 'isNotAll' ]
								: [ 'isAny', 'isNone' ],
							isPrimary: field.slug === 'budgets',
					  }
					: undefined,
				render: value => <Field item={ value.item } field={ field } />,
			} ) ) }
			view={ view }
			paginationInfo={ {
				totalItems: stories.length,
				totalPages: Math.ceil( stories.length / view.perPage ),
			} }
			onChangeView={ setView }
			defaultLayouts={ {
				table: {
					showMedia: false,
				},
			} }
			actions={ [
				{
					id: 'view',
					label: 'View',
					isPrimary: true,
					icon: <Icon icon={ external } />,
					callback: items => {
						console.log( items[ 0 ] ); // eslint-disable-line
					},
				},
				{
					id: 'refresh',
					label: 'Refresh',
					isPrimary: false,
					icon: <Icon icon={ update } />,
					callback: () => {
						// @TODO Refresh stories.
					},
				},
			] }
		/>
	);
};
