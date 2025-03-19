import { INITIAL_STATE } from '../constants';

export default ( state = INITIAL_STATE.budgets, action ) => {
	switch ( action.type ) {
		case 'BUDGETS_SET':
			return action.payload;
		case 'BUDGETS_ADD':
			if ( state.find( budget => budget.id === action.payload.id ) ) {
				return state.map( budget =>
					budget.id === action.payload.id ? action.payload : budget
				);
			}
			return [ ...state, action.payload ];
		default:
			return state;
	}
};
