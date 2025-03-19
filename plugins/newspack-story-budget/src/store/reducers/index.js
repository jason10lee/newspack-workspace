import { combineReducers } from 'redux';

import budgets from './budgets';
import stories from './stories';
import fields from './fields';
import search from './search';
import meta from './meta';
import view from './view';

export default combineReducers( {
	budgets,
	stories,
	fields,
	search,
	meta,
	view,
} );
