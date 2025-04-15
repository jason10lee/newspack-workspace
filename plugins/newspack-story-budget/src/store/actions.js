/* globals newspackStoryBudget */
import { __ } from '@wordpress/i18n';
import { apiFetch } from '@wordpress/data-controls';
import { resolveSelect, select, dispatch } from '@wordpress/data';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { STORAGE_KEYS, getCache } from './cache';
import { NAMESPACE } from './constants';

const { apiNamespace } = newspackStoryBudget;

export function* initializeEntitiesConfig() {
	// Hydrate state from cache if available.
	for ( const key in STORAGE_KEYS ) {
		const stored = getCache( key );
		if ( stored?.data ) {
			yield {
				type: 'HYDRATE',
				payload: {
					key,
					timestamp: stored.timestamp,
					data: stored.data,
				},
			};
		}
	}

	yield resolveSelect( NAMESPACE ).getFields();
	yield resolveSelect( NAMESPACE ).getBudgets();
	yield resolveSelect( NAMESPACE ).getStoriesMeta();

	// Periodically refresh cacheable state.
	for ( const key in STORAGE_KEYS ) {
		const cache = STORAGE_KEYS[ key ];
		if ( cache?.actions?.length && cache?.ttl ) {
			setInterval(
				() =>
					cache.actions.forEach( action =>
						dispatch( NAMESPACE )[ action ]()
					),
				cache.ttl
			);
		}
	}
}

export function setSearching() {
	return {
		type: 'SEARCH_START',
	};
}

function refreshAbortController( controller ) {
	if ( controller ) {
		controller.abort();
	}
	return typeof AbortController === 'undefined'
		? undefined
		: new AbortController();
}

let searchAbortController = refreshAbortController();

export function* search( str ) {
	yield { type: 'SEARCH_START' };
	searchAbortController = refreshAbortController( searchAbortController );
	try {
		const result = yield apiFetch( {
			path: `${ apiNamespace }/stories/search`,
			data: { s: str },
			method: 'POST',
			signal: searchAbortController?.signal,
		} );
		return {
			type: 'SEARCH_SUCCESS',
			payload: {
				ids: result.story_ids,
			},
		};
	} catch ( error ) {
		if ( error.name === 'AbortError' ) {
			return;
		}
		return {
			type: 'SEARCH_ERROR',
			payload: error,
		};
	}
}

export function setView( args ) {
	const view = select( NAMESPACE ).getView();
	if ( args.fields && view.fields ) {
		const fields = select( NAMESPACE ).getFields();
		args.fields = args.fields.sort( ( a, b ) => {
			// Allow visible columns to be sorted.
			if ( -1 < view.fields.indexOf( a ) ) {
				return 0;
			}
			// When displaying hidden columns, sort by default order.
			return (
				fields.find( f => f.slug === a )?.default_order -
				fields.find( f => f.slug === b )?.default_order
			);
		} );
	}
	return {
		type: 'VIEW_SET',
		payload: args,
	};
}

export function* fetchFields() {
	try {
		const result = yield apiFetch( { path: `${ apiNamespace }/fields` } );
		return {
			type: 'FIELDS_SET',
			payload: result,
		};
	} catch ( error ) {
		return {
			type: 'FIELDS_ERROR',
			payload: error,
		};
	}
}

export function* fetchBudgets() {
	try {
		const result = yield apiFetch( { path: `${ apiNamespace }/budgets` } );
		const { budgets, total } = result;
		while ( budgets.length < total ) {
			const next = yield apiFetch( {
				path: `${ apiNamespace }/budgets?offset=${ budgets.length }`,
			} );
			budgets.push( ...next.budgets );
		}
		return {
			type: 'BUDGETS_SET',
			payload: budgets,
		};
	} catch ( error ) {
		return {
			type: 'BUDGETS_ERROR',
			payload: error,
		};
	}
}

/**
 * Fetch all stories from the API.
 *
 * @return {Object} Action object.
 */
export function* fetchStories() {
	yield {
		type: 'FETCH_PROGRESS',
		payload: { progress: 0 }, // Start progress bar.
	};
	yield { type: 'FETCH_START' };
	try {
		const result = yield apiFetch( { path: `${ apiNamespace }/stories` } );
		const { stories, total } = result;
		yield {
			type: 'FETCH_PROGRESS',
			payload: { result, progress: stories.length / total },
		};
		while ( stories.length < total ) {
			const next = yield apiFetch( {
				path: addQueryArgs( `${ apiNamespace }/stories`, {
					offset: stories.length,
				} ),
			} );
			stories.push( ...next.stories );
			yield {
				type: 'FETCH_PROGRESS',
				payload: { result: next, progress: stories.length / total },
			};
		}
		return {
			type: 'STORIES_SET',
			payload: stories.reduce( ( acc, story ) => {
				acc[ story.id ] = story;
				return acc;
			}, {} ),
		};
	} catch ( error ) {
		const message =
			error?.message ||
			__(
				'Error fetching stories. Please try again.',
				'newspack-story-budget'
			);
		return {
			type: 'STORIES_ERROR',
			payload: { message },
		};
	} finally {
		yield { type: 'FETCH_END' };
	}
}

/**
 * Refresh stories modified since a certain timestamp from the API.
 *
 * @param {boolean} silent Whether to suppress errors and loading state.
 *
 * @return {Object} Action object.
 */
export function* refreshStories( silent = true ) {
	// If no last refresh timestamp is found, resort to 30 minutes ago.
	const lastRefresh =
		select( NAMESPACE ).getLastRefresh() ||
		Date.now() - 30 * 60 * 1000;

	yield { type: 'REFRESH_START', payload: { silent } };
	try {
		const params = { metadata: true };
		if ( lastRefresh ) {
			params.since = Math.floor( lastRefresh / 1000 ); // UNIX timestamp in seconds.
		}
		const result = yield apiFetch( {
			path: addQueryArgs( `${ apiNamespace }/stories`, params ),
		} );
		const { stories, total } = result;
		while ( stories.length < total ) {
			params.offset = stories.length;
			const next = yield apiFetch( {
				path: addQueryArgs( `${ apiNamespace }/stories`, params ),
			} );
			stories.push( ...next.stories );
		}
		if ( ! stories.length ) {
			return;
		}
		return {
			type: 'STORIES_APPEND',
			payload: stories.reduce( ( acc, story ) => {
				acc[ story.id ] = story;
				return acc;
			}, {} ),
		};
	} catch ( error ) {
		if ( silent ) {
			return;
		}
		const message =
			error?.message ||
			__(
				'Error refreshing stories. Please try again.',
				'newspack-story-budget'
			);
		return {
			type: 'STORIES_ERROR',
			payload: { message },
		};
	} finally {
		yield { type: 'REFRESH_END' };
	}
}

export function* fetchStoriesMeta() {
	const result = yield apiFetch( {
		path: `${ apiNamespace }/stories/meta`,
		method: 'GET',
	} );
	return {
		type: 'STORIES_META_SET',
		payload: result,
	};
}

export function* fetchStory( id ) {
	yield { type: 'FETCH_STORY_START', payload: { id } };
	try {
		const result = yield apiFetch( {
			path: `${ apiNamespace }/stories/${ id }`,
		} );
		yield { type: 'FETCH_STORY_SUCCESS', payload: { id } };
		return {
			type: 'STORIES_APPEND',
			payload: { [ id ]: result },
		};
	} catch ( error ) {
		const message =
			error?.message ||
			__(
				'Error fetching story. Please try again.',
				'newspack-story-budget'
			);
		return {
			type: 'FETCH_STORY_ERROR',
			payload: { id, message },
		};
	}
}

export function* fetchStoryMeta( id ) {
	const result = yield apiFetch( {
		path: `${ apiNamespace }/stories/${ id }/meta`,
		method: 'GET',
	} );
	return {
		type: 'STORY_META_SET',
		payload: { id, result },
	};
}

export function* fetchStoryMetaBatch( storyIds ) {
	yield { type: 'STORY_META_BATCH_START' };
	const result = yield apiFetch( {
		path: `${ apiNamespace }/stories/meta/batch`,
		method: 'POST',
		data: { ids: storyIds },
	} );
	return {
		type: 'STORY_META_BATCH_SET',
		payload: result,
	};
}

let storyMetaFetchTimeout;

const debouncedFetchStoryMetaBatch = () => {
	clearTimeout( storyMetaFetchTimeout );
	storyMetaFetchTimeout = setTimeout( () => {
		const storyIds = Object.keys(
			select( NAMESPACE ).getStoryMetaFetchQueue()
		);
		if ( storyIds.length > 0 ) {
			dispatch( NAMESPACE ).fetchStoryMetaBatch( storyIds );
		}
	}, 300 );
};

export function queueStoryMetaFetch( id ) {
	debouncedFetchStoryMetaBatch();
	return {
		type: 'STORY_META_FETCH_QUEUE',
		payload: { id },
	};
}

export function* saveStory( id, story ) {
	yield { type: 'SAVE_STORY_START', payload: { id, story } };
	try {
		const result = yield apiFetch( {
			path: `${ apiNamespace }/stories/${ id }`,
			method: 'POST',
			data: story,
		} );
		yield { type: 'STORIES_APPEND', payload: { [ id ]: result } };
		return {
			type: 'SAVE_STORY_SUCCESS',
			payload: result,
		};
	} catch ( error ) {
		const message =
			error?.message ||
			__( 'Error saving story.', 'newspack-story-budget' );
		return { type: 'SAVE_STORY_ERROR', payload: { id, story, message } };
	}
}

export function* saveStoryField( id, slug, value ) {
	yield { type: 'SAVE_STORY_FIELD_START', payload: { id, slug, value } };
	try {
		const result = yield apiFetch( {
			path: `${ apiNamespace }/stories/${ id }/${ slug }`,
			method: 'POST',
			data: { value },
		} );
		yield { type: 'STORIES_APPEND', payload: { [ id ]: result } };
		return {
			type: 'SAVE_STORY_FIELD_SUCCESS',
			payload: { id, slug, value: result[ slug ] },
		};
	} catch ( error ) {
		const message =
			error?.message ||
			__( 'Error saving field.', 'newspack-story-budget' );
		return {
			type: 'SAVE_STORY_FIELD_ERROR',
			payload: { id, slug, value, message },
		};
	}
}

export function clearErrors( storyId = null, fieldId = null ) {
	if ( ! storyId ) {
		return {
			type: 'CLEAR_ALL_ERRORS',
		};
	}
	return {
		type: fieldId ? 'CLEAR_FIELD_ERROR' : 'CLEAR_STORY_ERROR',
		payload: { id: storyId, slug: fieldId },
	};
}
