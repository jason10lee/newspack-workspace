import { createSelector } from '@wordpress/data';

import utils from '../utils';

export const isLoading = state => state.meta.loading || state.meta.searching;

export const isLoadingStory = ( state, id ) =>
	state.meta.loadingStory?.[ id ] ?? false;

export const getProgress = state => state.meta.progress;

export const getFields = state => state.fields;

export const getField = ( state, slug ) =>
	state.fields.find( f => f.slug === slug );

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

		if ( view.filters?.length ) {
			stories = utils.stories.filter( stories, fields, view );
		}

		if ( view.sort?.field ) {
			stories = utils.stories.sort( stories, fields, view );
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

export const canEditStory = ( state, id ) =>
	state.meta.stories.can_edit || state.stories[ id ]?.metadata?.can_edit;

export const getStoriesMeta = state => state.meta.stories;

export const getStoryMeta = ( state, id, key ) =>
	key
		? state.stories[ id ]?.metadata?.[ key ]
		: state.stories[ id ]?.metadata;

export const getErrors = state => state.errors;

export const getFieldError = ( state, storyId, fieldId ) =>
	state.errors[ `${ storyId }-${ fieldId }` ];

export const getStoryError = ( state, storyId ) =>
	state.errors[ `story-${ storyId }` ];

export const getStoryMetaFetchQueue = state => state.meta.storyMetaFetchQueue;
