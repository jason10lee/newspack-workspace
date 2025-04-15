import { actions as fields } from '../reducers/fields';
import { actions as stories } from '../reducers/stories';
import { actions as view } from '../reducers/view';

export default {
	fields,
	stories: {
		// Only cache actions that modify the stories field data. Metadata are
		// fetched asynchronously.
		STORIES_SET: stories.STORIES_SET,
		STORIES_APPEND: stories.STORIES_APPEND,
	},
	view,
};
