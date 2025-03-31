import { NAMESPACE } from './constants';

export const getFields =
	() =>
	async ( { dispatch } ) => {
		await dispatch.fetchFields();
	};

export const getField =
	() =>
	async ( { dispatch, registry } ) => {
		const { hasStartedResolution, hasFinishedResolution } =
			registry.select( NAMESPACE );
		if (
			hasStartedResolution( 'getFields' ) ||
			hasFinishedResolution( 'getFields' )
		) {
			return;
		}
		await dispatch.fetchFields();
	};

export const getBudgets =
	() =>
	async ( { dispatch } ) => {
		await dispatch.fetchBudgets();
	};

export const getStories =
	() =>
	async ( { dispatch } ) => {
		await dispatch.fetchStories();
	};

export const getStoriesMeta =
	() =>
	async ( { dispatch } ) => {
		await dispatch.fetchStoriesMeta();
	};

export const getStory =
	id =>
	async ( { resolveSelect, dispatch, select, registry } ) => {
		// Fetch the entire story if it's not already fetched.
		if ( ! select.getStory( id ) ) {
			await dispatch.fetchStory( id );
			return;
		}
		// Bail if the story meta is already fetched.
		if ( select.getStoryMeta( id ) ) {
			return;
		}
		// Bail if the story meta is being fetched.
		const { hasStartedResolution } = registry.select( NAMESPACE );
		if ( hasStartedResolution( 'getStoryMeta', id ) ) {
			return;
		}
		// Fetch the story meta.
		await resolveSelect.getStoryMeta( id );
	};

export const getStoryMeta =
	( id, key ) =>
	async ( { dispatch, select, registry } ) => {
		// Bail if the metadata is already fetched.
		if ( select.getStoryMeta( id, key ) ) {
			return;
		}
		// Bail if the story is being fetched.
		const { hasStartedResolution } = registry.select( NAMESPACE );
		if ( hasStartedResolution( 'getStory', id ) ) {
			return;
		}
		// Fetch story and bail if it's not fetched.
		if ( ! select.getStory( id ) ) {
			await dispatch.fetchStory( id );
			return;
		}
		// Fetch the story meta.
		await dispatch.queueStoryMetaFetch( id );
	};

export const canEditStory =
	id =>
	async ( { resolveSelect, select, registry } ) => {
		// If the user can edit stories, they can edit any story.
		if ( select.getStoriesMeta()?.can_edit ) {
			return;
		}
		// Bail if the story `can_edit` metadata is fetched.
		if ( select.getStoryMeta( id, 'can_edit' ) !== undefined ) {
			return;
		}
		// Bail if the story or story meta is being fetched.
		const { hasStartedResolution } = registry.select( NAMESPACE );
		if (
			hasStartedResolution( 'getStory', id ) ||
			hasStartedResolution( 'getStoryMeta', id )
		) {
			return;
		}
		// Fetch the story meta.
		await resolveSelect.getStoryMeta( id, 'can_edit' );
	};
