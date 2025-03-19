import { INITIAL_STATE } from '../constants';

export default ( state = INITIAL_STATE.view, action ) => {
	switch ( action.type ) {
		case 'VIEW_SET':
			return action.payload;
		case 'FIELDS_SET':
			return {
				...state,
				fields: action.payload
					.filter( field => field.show_in_table )
					.map( field => field.slug ),
			};
		default:
			return state;
	}
};
