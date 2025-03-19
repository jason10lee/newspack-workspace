import { INITIAL_STATE } from '../constants';

export default ( state = INITIAL_STATE.meta, action ) => {
	switch ( action.type ) {
		case 'FETCH_START':
			return {
				...state,
				loading: true,
			};
		case 'FETCH_PROGRESS':
			return {
				...state,
				progress: action.payload.progress,
			};
		case 'STORIES_SET':
		case 'STORIES_ERROR':
			return {
				...state,
				loading: false,
			};
		case 'SEARCH_START':
			return {
				...state,
				searching: true,
			};
		case 'SEARCH_SUCCESS':
		case 'SEARCH_ERROR':
			return {
				...state,
				searching: false,
			};
		case 'FETCH_STORY_START':
			return {
				...state,
				loadingStory: {
					...state.loadingStory,
					[ action.payload ]: true,
				},
			};
		case 'FETCH_STORY_SUCCESS':
		case 'FETCH_STORY_ERROR':
			return {
				...state,
				loadingStory: {
					...state.loadingStory,
					[ action.payload ]: false,
				},
			};
		default:
			return state;
	}
};
