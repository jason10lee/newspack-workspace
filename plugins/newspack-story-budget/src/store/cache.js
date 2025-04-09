/**
 * Helper functions for caching data in session storage.
 */
const STORAGE_KEY_BASE = 'newspack-story-budget-';

// Cache configuration.
export const STORAGE_KEYS = {
	fields: {
		actions: [ 'fetchFields' ],
		ttl: 1000 * 60 * 60 * 24, // 24 hours
	},
	stories: {
		actions: [ 'refreshStories' ],
		ttl: 1000 * 30, // 30 seconds
	},
	view: {}, // No expiration.
};

/**
 * Encode object to be stored.
 *
 * @param {Object} object Object to encode.
 *
 * @return {string} Encoded object.
 */
export function encode( object ) {
	return JSON.stringify( object );
}

/**
 * Decode object to be read.
 *
 * @param {string} str String to decode.
 *
 * @return {Object} Decoded string.
 */
export function decode( str ) {
	if ( ! str || 'string' !== typeof str ) {
		return str;
	}
	return JSON.parse( str );
}

/**
 * Get a cached object.
 *
 * @param {string} key Cache.
 *
 * @return {Object} Cached data.
 */
export function getCache( key ) {
	if ( ! STORAGE_KEYS.hasOwnProperty( key ) ) {
		return null;
	}
	try {
		const cache = decode(
			sessionStorage.getItem( STORAGE_KEY_BASE + key )
		);
		if ( ! cache?.data ) {
			return null;
		}
		return cache;
	} catch ( error ) {
		console.warn( 'Unable to get cache for key:', key, error ); // eslint-disable-line no-console
		return null;
	}
}

/**
 * Set a cached object.
 *
 * @param {string} key  Cache.
 * @param {Object} data Data to set.
 */
export function setCache( key, data ) {
	if ( ! STORAGE_KEYS.hasOwnProperty( key ) ) {
		return;
	}

	try {
		sessionStorage.setItem(
			STORAGE_KEY_BASE + key,
			encode( { data, timestamp: Date.now() } )
		);
	} catch ( error ) {
		console.warn( 'Unable to set cache for key:', key, error ); // eslint-disable-line no-console
	}
}

/**
 * Delete a cached object.
 *
 * @param {string} key Cache.
 */
export function deleteCache( key ) {
	if ( ! STORAGE_KEYS.hasOwnProperty( key ) ) {
		return;
	}
	try {
		sessionStorage.removeItem( STORAGE_KEY_BASE + key );
	} catch ( error ) {
		console.warn( 'Unable to delete cache for key:', key, error ); // eslint-disable-line no-console
	}
}
