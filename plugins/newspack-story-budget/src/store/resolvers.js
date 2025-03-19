/* globals newspackStoryBudget */
import { apiFetch } from '@wordpress/data-controls';

const { apiNamespace } = newspackStoryBudget;

export function* getFields() {
	try {
		// @TODO API endpoint for fields.
		// const result = yield apiFetch( { path: `${ apiNamespace }/fields` } );
		const result = [
			{
				slug: 'title',
				name: 'Title',
				type: 'text',
				show_in_table: true,
				is_filterable: true,
				is_sortable: true,
			},
			{
				slug: 'slug',
				name: 'Slug',
				type: 'text',
				show_in_table: false,
				is_filterable: false,
				is_sortable: false,
			},
			{
				slug: 'budgets',
				name: 'Budgets',
				type: 'text',
				show_in_table: true,
				is_multiple: true,
				is_filterable: true,
				is_sortable: false,
				options: [
					{ value: 76, label: '03-02-2025' },
					{ value: 77, label: '03-03-2025' },
					{ value: 78, label: '03-04-2025' },
					{ value: 79, label: '03-05-2025' },
					{ value: 80, label: '03-06-2025' },
					{ value: 81, label: '03-07-2025' },
					{ value: 82, label: '03-08-2025' },
					{ value: 83, label: '03-09-2025' },
					{ value: 84, label: '03-10-2025' },
					{ value: 85, label: '03-11-2025' },
					{ value: 86, label: '03-12-2025' },
					{ value: 87, label: '03-13-2025' },
					{ value: 88, label: '03-14-2025' },
					{ value: 89, label: '03-15-2025' },
				],
			},
			{
				slug: 'authors',
				name: 'Authors',
				type: 'text',
				show_in_table: true,
				is_filterable: false,
				is_sortable: false,
			},
			{
				slug: 'status',
				name: 'Status',
				type: 'text',
				options: [
					{ value: 'writing', label: 'Writing' },
					{ value: 'editing', label: 'Editing' },
					{ value: 'pitch', label: 'Pitch' },
					{ value: 'ready', label: 'Ready' },
				],
				show_in_table: true,
				is_filterable: true,
				is_sortable: false,
				editable: true,
			},
			{
				slug: 'image_count',
				name: 'Image Count',
				type: 'integer',
				show_in_table: true,
				is_filterable: false,
				is_sortable: true,
			},
			{
				slug: 'word_count',
				name: 'Word Count',
				type: 'integer',
				show_in_table: true,
				is_filterable: false,
				is_sortable: true,
			},
			{
				slug: 'length_in',
				name: 'Length (in)',
				type: 'integer',
				show_in_table: true,
				is_filterable: false,
				is_sortable: true,
			},
			{
				slug: 'last_modified',
				name: 'Last Modified',
				type: 'datetime',
				show_in_table: true,
				is_filterable: false,
				is_sortable: true,
			},
			{
				slug: 'published_online',
				name: 'Published Online',
				type: 'datetime',
				show_in_table: true,
				is_filterable: false,
				is_sortable: true,
			},
			{
				slug: 'locked',
				name: 'Locked',
				type: 'boolean',
				show_in_table: true,
				is_filterable: false,
				is_sortable: false,
			},
			{
				slug: 'description',
				name: 'Description',
				type: 'text',
				show_in_table: false,
				is_filterable: false,
				is_sortable: false,
			},
			{
				slug: 'print_rank',
				name: 'Print Rank',
				type: 'text',
				show_in_table: true,
				is_filterable: true,
				is_sortable: true,
			},
			{
				slug: 'publication',
				name: 'Publication',
				type: 'select',
				show_in_table: true,
				is_filterable: true,
				is_sortable: true,
			},
			{
				slug: 'print_page',
				name: 'Print Page',
				type: 'text',
				show_in_table: true,
				is_filterable: true,
				is_sortable: true,
			},
		];
		return {
			type: 'FIELDS_SET',
			payload: result,
		};
	} catch ( error ) {
		return {
			type: 'FIELDS_ERROR',
			payload: error,
		};
	}
}

export function* getBudgets() {
	try {
		const result = yield apiFetch( { path: `${ apiNamespace }/budgets` } );
		const { budgets, total } = result;
		while ( budgets.length < total ) {
			const next = yield apiFetch( {
				path: `${ apiNamespace }/budgets?offset=${ budgets.length }`,
			} );
			budgets.push( ...next.budgets );
		}
		return {
			type: 'BUDGETS_SET',
			payload: budgets,
		};
	} catch ( error ) {
		return {
			type: 'BUDGETS_ERROR',
			payload: error,
		};
	}
}

export function* getStories() {
	yield { type: 'FETCH_START' };
	try {
		const result = yield apiFetch( { path: `${ apiNamespace }/stories` } );
		const { stories, total } = result;
		yield {
			type: 'FETCH_PROGRESS',
			payload: { result, progress: stories.length / total },
		};
		while ( stories.length < total ) {
			const next = yield apiFetch( {
				path: `${ apiNamespace }/stories?offset=${ stories.length }`,
			} );
			stories.push( ...next.stories );
			yield {
				type: 'FETCH_PROGRESS',
				payload: { result: next, progress: stories.length / total },
			};
		}
		return {
			type: 'STORIES_SET',
			payload: stories,
		};
	} catch ( error ) {
		return {
			type: 'STORIES_ERROR',
			payload: error,
		};
	}
}

export function* getStory( id ) {
	yield { type: 'FETCH_STORY_START', payload: id };
	try {
		const result = yield apiFetch( {
			path: `${ apiNamespace }/stories/${ id }`,
		} );
		yield { type: 'FETCH_STORY_SUCCESS', payload: id };
		return {
			type: 'STORIES_ADD',
			payload: result,
		};
	} catch ( error ) {
		yield { type: 'FETCH_STORY_ERROR', payload: id };
		return {
			type: 'STORIES_ERROR',
			payload: error,
		};
	}
}
