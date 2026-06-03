/**
 * useDateRange
 *
 * Owns the active date range state for the Insights wizard. Hydrates
 * initial state from URL query (so refresh / direct links preserve range)
 * with fallback to the boot config default; persists changes back to URL
 * via history.replaceState (no history pollution).
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';

export type DateRangePreset =
	| 'last-7'
	| 'last-30'
	| 'last-90'
	| 'this-month'
	| 'last-month'
	| 'custom';

export interface DateRange {
	preset: DateRangePreset;
	start: string; // YYYY-MM-DD
	end: string; // YYYY-MM-DD
}

export interface DateRangePresetDef {
	key: DateRangePreset;
	label: string;
}

export const DATE_RANGE_PRESETS: DateRangePresetDef[] = [
	{ key: 'last-7', label: 'Last 7 days' },
	{ key: 'last-30', label: 'Last 30 days' },
	{ key: 'last-90', label: 'Last 90 days' },
	{ key: 'this-month', label: 'This month' },
	{ key: 'last-month', label: 'Last month' },
	{ key: 'custom', label: 'Custom' },
];

const ISO_DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

const isValidISODate = ( s: unknown ): s is string =>
	typeof s === 'string' && ISO_DATE_RE.test( s );

const isPreset = ( v: unknown ): v is DateRangePreset =>
	typeof v === 'string' &&
	DATE_RANGE_PRESETS.some( p => p.key === v );

const pad2 = ( n: number ) => String( n ).padStart( 2, '0' );

const toISO = ( d: Date ): string =>
	`${ d.getFullYear() }-${ pad2( d.getMonth() + 1 ) }-${ pad2( d.getDate() ) }`;

/**
 * Compute a range from a preset, anchored to today.
 *
 * Returns null for 'custom' — the caller supplies start/end directly.
 */
export const computeRangeForPreset = (
	preset: DateRangePreset,
	today: Date = new Date()
): { start: string; end: string } | null => {
	if ( preset === 'custom' ) {
		return null;
	}
	const end = toISO( today );
	if ( preset === 'last-7' ) {
		const s = new Date( today );
		s.setDate( s.getDate() - 7 );
		return { start: toISO( s ), end };
	}
	if ( preset === 'last-30' ) {
		const s = new Date( today );
		s.setDate( s.getDate() - 30 );
		return { start: toISO( s ), end };
	}
	if ( preset === 'last-90' ) {
		const s = new Date( today );
		s.setDate( s.getDate() - 90 );
		return { start: toISO( s ), end };
	}
	if ( preset === 'this-month' ) {
		const s = new Date( today.getFullYear(), today.getMonth(), 1 );
		return { start: toISO( s ), end };
	}
	if ( preset === 'last-month' ) {
		const s = new Date( today.getFullYear(), today.getMonth() - 1, 1 );
		const e = new Date( today.getFullYear(), today.getMonth(), 0 );
		return { start: toISO( s ), end: toISO( e ) };
	}
	return null;
};

const readUrl = (): Partial< DateRange > => {
	if ( typeof window === 'undefined' ) {
		return {};
	}
	const params = new URLSearchParams( window.location.search );
	const preset = params.get( 'range' );
	const start = params.get( 'start' );
	const end = params.get( 'end' );
	const out: Partial< DateRange > = {};
	if ( isPreset( preset ) ) {
		out.preset = preset;
	}
	if ( isValidISODate( start ) ) {
		out.start = start;
	}
	if ( isValidISODate( end ) ) {
		out.end = end;
	}
	return out;
};

const writeUrl = ( range: DateRange ) => {
	if ( typeof window === 'undefined' ) {
		return;
	}
	const params = new URLSearchParams( window.location.search );
	params.set( 'range', range.preset );
	if ( range.preset === 'custom' ) {
		params.set( 'start', range.start );
		params.set( 'end', range.end );
	} else {
		params.delete( 'start' );
		params.delete( 'end' );
	}
	const next = `${ window.location.pathname }?${ params.toString() }${ window.location.hash }`;
	window.history.replaceState( window.history.state, '', next );
};

export interface UseDateRangeOptions {
	defaultRange: DateRange;
}

export interface UseDateRangeReturn {
	range: DateRange;
	setPreset: ( preset: DateRangePreset ) => void;
	setCustom: ( start: string, end: string ) => void;
}

/**
 * Hydrate from URL first, fall back to defaultRange. Persist on change.
 */
const useDateRange = ( { defaultRange }: UseDateRangeOptions ): UseDateRangeReturn => {
	const [ range, setRange ] = useState< DateRange >( () => {
		const fromUrl = readUrl();
		// Custom preset requires both start and end from URL; otherwise fall
		// back to default.
		if ( fromUrl.preset === 'custom' ) {
			if ( fromUrl.start && fromUrl.end ) {
				return {
					preset: 'custom',
					start: fromUrl.start,
					end: fromUrl.end,
				};
			}
			return defaultRange;
		}
		if ( fromUrl.preset ) {
			const computed = computeRangeForPreset( fromUrl.preset );
			if ( computed ) {
				return { preset: fromUrl.preset, ...computed };
			}
		}
		return defaultRange;
	} );

	useEffect( () => {
		writeUrl( range );
	}, [ range ] );

	const setPreset = useCallback( ( preset: DateRangePreset ) => {
		if ( preset === 'custom' ) {
			// Custom needs explicit start/end; setCustom handles that path.
			// Hitting "Custom" without supplying dates keeps the current
			// range but flags it as custom so the picker opens.
			setRange( prev => ( {
				preset: 'custom',
				start: prev.start,
				end: prev.end,
			} ) );
			return;
		}
		const computed = computeRangeForPreset( preset );
		if ( computed ) {
			setRange( { preset, ...computed } );
		}
	}, [] );

	const setCustom = useCallback( ( start: string, end: string ) => {
		if ( ! isValidISODate( start ) || ! isValidISODate( end ) ) {
			return;
		}
		setRange( { preset: 'custom', start, end } );
	}, [] );

	return { range, setPreset, setCustom };
};

export default useDateRange;
