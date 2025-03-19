import { createReduxStore, register } from '@wordpress/data';
import { controls } from '@wordpress/data-controls';

import { NAMESPACE, INITIAL_STATE } from './constants';
import reducer from './reducers';
import * as actions from './actions';
import * as selectors from './selectors';
import * as resolvers from './resolvers';

const store = createReduxStore( NAMESPACE, {
	reducer,
	controls,
	actions,
	selectors,
	resolvers,
	initialState: INITIAL_STATE,
} );

export const registerStore = () => register( store );
