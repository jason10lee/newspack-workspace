import { INITIAL_STATE } from '../constants';

export default ( state = INITIAL_STATE.fields, action ) => {
	switch ( action.type ) {
		case 'FIELDS_SET':
			return action.payload;
		default:
			return state;
	}
};
