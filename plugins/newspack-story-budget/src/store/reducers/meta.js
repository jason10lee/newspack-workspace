import { INITIAL_STATE } from '../constants';

export default ( state = INITIAL_STATE.meta, action ) => {
	switch ( action.type ) {
		case 'FETCH_START':
			return {
				...state,
				loading: true,
			};
		case 'FETCH_END':
			return {
				...state,
				loading: false,
			};
		case 'FETCH_PROGRESS':
			return {
				...state,
				progress: action.payload.progress,
			};
		case 'HYDRATE':
			if ( action.payload.key === 'stories' ) {
				return {
					...state,
					lastRefresh: action.payload.timestamp,
				};
			}
			return state;
		case 'REFRESH_START':
			return {
				...state,
				refreshing: action.payload.silent ? false : true,
				lastRefresh: Date.now(),
			};
		case 'REFRESH_END':
			return {
				...state,
				refreshing: false,
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
		case 'SAVE_STORY_START':
		case 'SAVE_STORY_FIELD_START':
			return {
				...state,
				loadingStory: {
					...state.loadingStory,
					[ action.payload.id ]: true,
				},
			};
		case 'FETCH_STORY_SUCCESS':
		case 'FETCH_STORY_ERROR':
		case 'SAVE_STORY_SUCCESS':
		case 'SAVE_STORY_ERROR':
		case 'SAVE_STORY_FIELD_SUCCESS':
		case 'SAVE_STORY_FIELD_ERROR':
			return {
				...state,
				loadingStory: {
					...state.loadingStory,
					[ action.payload.id ]: false,
				},
			};
		case 'STORIES_META_SET':
			return {
				...state,
				stories: action.payload,
			};
		case 'STORY_META_FETCH_QUEUE':
			return {
				...state,
				storyMetaFetchQueue: {
					...state.storyMetaFetchQueue,
					[ action.payload.id ]: true,
				},
			};
		case 'STORY_META_BATCH_START':
		case 'STORY_META_BATCH_SET':
			return {
				...state,
				storyMetaFetchQueue: {},
			};
		default:
			return state;
	}
};
