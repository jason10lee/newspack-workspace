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
					[ action.payload.id ]: true,
				},
			};

		case 'FETCH_STORY_SUCCESS':
		case 'FETCH_STORY_ERROR':
			return {
				...state,
				loadingStory: {
					...state.loadingStory,
					[ action.payload.id ]: false,
				},
			};
		case 'SAVE_STORY_START':
		case 'SAVE_STORY_FIELD_START':
			return {
				...state,
				loadingStory: {
					...state.loadingStory,
					[ action.payload.id ]: true,
				},
			};
		case 'SAVE_STORY_SUCCESS':
		case 'SAVE_STORY_ERROR':
			return {
				...state,
				loadingStory: {
					...state.loadingStory,
					[ action.payload.id ]: false,
				},
			};
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
