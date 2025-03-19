import { createSelector } from '@wordpress/data';

export const isLoading = state => state.meta.loading || state.meta.searching;

export const isLoadingStory = ( state, id ) =>
	state.meta.loadingStory?.[ id ] ?? false;

export const getProgress = state => state.meta.progress;

export const getFields = state => state.fields;

export const getBudgets = state => state.budgets;

export const getStories = createSelector(
	state => {
		const { search, view, fields } = state;

		let stories;

		if ( view.search ) {
			stories = search.map( id =>
				state.stories[ id ] ? state.stories[ id ] : { id }
			);
		} else {
			stories = Object.values( state.stories );
		}

		// Filter
		for ( const filter of view.filters ) {
			const { operator, value, field } = filter;
			const fieldObject = fields.find( f => f.slug === field );
			if ( ! fieldObject?.is_filterable || ! value?.length ) {
				continue;
			}
			stories = stories.filter( story => {
				const fieldValue = story?.[ field ] ?? '';
				switch ( operator ) {
					case 'is':
						return fieldValue === value;
					case 'isNot':
						return fieldValue !== value;
					case 'isAny':
						if ( fieldObject.is_multiple ) {
							return value.some( v => fieldValue.includes( v ) );
						}
						return value.includes( fieldValue );
					case 'isNone':
						if ( fieldObject.is_multiple ) {
							return ! value.some( v =>
								fieldValue.includes( v )
							);
						}
						return ! value.includes( fieldValue );
					case 'isAll':
						if ( fieldObject.is_multiple ) {
							return value.every( v => fieldValue.includes( v ) );
						}
						return value.includes( fieldValue );
					case 'isNotAll':
						if ( fieldObject.is_multiple ) {
							return ! value.every( v =>
								fieldValue.includes( v )
							);
						}
						return ! value.includes( fieldValue );
					default:
						return true;
				}
			} );
		}

		// Sort
		if ( view.sort?.field ) {
			const { field, direction } = view.sort;
			const fieldObject = fields.find( f => f.slug === field );
			if ( fieldObject?.is_sortable ) {
				switch ( fieldObject.type ) {
					case 'number':
						stories = stories.sort( ( a, b ) => {
							const aValue = a?.[ field ] ?? 0;
							const bValue = b?.[ field ] ?? 0;
							return direction === 'asc'
								? aValue - bValue
								: bValue - aValue;
						} );
						break;
					case 'date':
						stories = stories.sort( ( a, b ) => {
							const aValue = new Date( a?.[ field ] ?? 0 );
							const bValue = new Date( b?.[ field ] ?? 0 );
							return direction === 'asc'
								? aValue - bValue
								: bValue - aValue;
						} );
						break;
					default:
						stories = stories.sort( ( a, b ) => {
							const aValue = a?.[ field ] ?? '';
							const bValue = b?.[ field ] ?? '';
							if ( aValue < bValue ) {
								return direction === 'asc' ? -1 : 1;
							}
							if ( aValue > bValue ) {
								return direction === 'asc' ? 1 : -1;
							}
							return 0;
						} );
				}
			}
		}
		return stories;
	},
	state => [
		state.search,
		state.stories,
		state.view.search,
		state.view.filters,
		state.view.sort,
	]
);

export const getStory = ( state, id ) => state.stories[ id ];

export const getView = state => state.view;
