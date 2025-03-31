import { INITIAL_STATE } from '../constants';

export default ( state = INITIAL_STATE.stories, action ) => {
	switch ( action.type ) {
		case 'STORIES_SET':
			const stories = action.payload.reduce( ( acc, story ) => {
				acc[ story.id ] = story;
				return acc;
			}, {} );
			return stories;
		case 'SAVE_STORY_SUCCESS':
		case 'STORIES_ADD':
			return {
				...state,
				[ action.payload.id ]: action.payload,
			};
		case 'SAVE_STORY_FIELD_SUCCESS':
			return {
				...state,
				[ action.payload.id ]: {
					...state[ action.payload.id ],
					[ action.payload.slug ]: action.payload.value,
				},
			};
		case 'STORY_META_SET':
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
		case 'STORY_META_BATCH_SET':
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
		default:
			return state;
	}
};
