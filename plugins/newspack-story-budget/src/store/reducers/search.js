import { INITIAL_STATE } from '../constants';

export default ( state = INITIAL_STATE.search, action ) => {
	switch ( action.type ) {
		case 'SEARCH_SUCCESS':
			return action.payload.ids;
		case 'SEARCH_CLEAR':
			return [];
		default:
			return state;
	}
};
