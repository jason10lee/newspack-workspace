/* globals newspackStoryBudget */
import { apiFetch } from '@wordpress/data-controls';

const { apiNamespace } = newspackStoryBudget;

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
	return {
		type: 'VIEW_SET',
		payload: args,
	};
}
