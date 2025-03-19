export const NAMESPACE = 'newspack-story-budget';

export const INITIAL_STATE = {
	budgets: [],
	stories: {},
	search: [],
	fields: [],
	meta: {
		loading: false,
		progress: 0,
		searching: false,
	},
	view: {
		type: 'table',
		search: '',
		page: 1,
		perPage: 10,
		fields: [],
		filters: [],
		layout: {
			density: 'compact',
		},
	},
};
