import { INITIAL_STATE } from '../constants';

export default ( state = INITIAL_STATE.stories, action ) => {
	switch ( action.type ) {
		case 'STORIES_SET':
			const stories = action.payload.reduce( ( acc, story ) => {
				acc[ story.id ] = story;
				return acc;
			}, {} );
			return stories;
		case 'STORIES_ADD':
			return {
				...state,
				[ action.payload.id ]: action.payload,
			};
		default:
			return state;
	}
};
