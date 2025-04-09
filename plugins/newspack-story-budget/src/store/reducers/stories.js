import { INITIAL_STATE } from '../constants';

export const actions = {
	STORIES_SET: 'STORIES_SET',
	STORIES_APPEND: 'STORIES_APPEND',
	STORY_META_SET: 'STORY_META_SET',
	STORY_META_BATCH_SET: 'STORY_META_BATCH_SET',
};

export default ( state = INITIAL_STATE.stories, action ) => {
	switch ( action.type ) {
		case actions.STORIES_SET:
			return {
				...state,
				...action.payload,
			};
		case actions.STORIES_APPEND: {
			const newState = { ...state };
			for ( const [ id, story ] of Object.entries( action.payload ) ) {
				newState[ id ] = story;
			}
			return newState;
		}
		case actions.STORY_META_SET:
			return {
				...state,
				[ action.payload.id ]: {
					...state[ action.payload.id ],
					metadata: {
						...state[ action.payload.id ].metadata,
						...action.payload.result,
					},
				},
			};
		case actions.STORY_META_BATCH_SET: {
			const newState = { ...state };
			for ( const [ id, result ] of Object.entries( action.payload ) ) {
				newState[ id ] = {
					...state[ id ],
					metadata: {
						...state[ id ].metadata,
						...result,
					},
				};
			}
			return newState;
		}
		default:
			return state;
	}
};
