/**
 * useConversionData (NPPD-1609, Phase 2).
 *
 * Thin reader over the module insightsCache. The slot key embeds the
 * date range + comparison window so cross-tab/date state stays coherent.
 * Mirrors {@see usePromptsData} exactly: makeSlotKey, useSyncExternalStore
 * against insightsCache, ensureFetched, refresh, useRegisterRefresh.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useSyncExternalStore } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import { fetchConversionData, refreshConversionData, type ConversionResponse } from '../api/conversion';
import { insightsCache, makeSlotKey } from '../state/insightsCache';
import { useRegisterRefresh } from '../state/refreshRegistry';

export type FetchStatus = 'idle' | 'loading' | 'success' | 'error';

export interface UseConversionDataResult {
	status: FetchStatus;
	data: ConversionResponse | null;
	error: string | null;
	refetch: () => void;
	computedAt: string | null;
	source: 'bigquery' | 'external' | 'local' | null;
	cooldownUntil: string | null;
}

const queryFrom = ( range: DateRange, previousRange: DateRange | null ) => ( {
	start: range.start,
	end: range.end,
	compare_start: previousRange?.start,
	compare_end: previousRange?.end,
} );

const useConversionData = ( range: DateRange, previousRange: DateRange | null ): UseConversionDataResult => {
	const key = makeSlotKey( 'conversion', range, previousRange );

	const slot = useSyncExternalStore(
		listener => insightsCache.subscribe( key, listener ),
		() => insightsCache.getSlot< ConversionResponse >( key )
	);

	useEffect( () => {
		insightsCache.ensureFetched( key, () => fetchConversionData( queryFrom( range, previousRange ) ) );
	}, [ key, range.start, range.end, previousRange?.start, previousRange?.end ] );

	const refetch = useCallback( () => {
		insightsCache.refresh( key, () => refreshConversionData( queryFrom( range, previousRange ) ) );
	}, [ key, range.start, range.end, previousRange?.start, previousRange?.end ] );

	useRegisterRefresh( 'conversion', refetch );

	return {
		status: slot.status,
		data: slot.data,
		error: slot.error,
		refetch,
		computedAt: slot.computedAt,
		source: slot.source,
		cooldownUntil: slot.cooldownUntil,
	};
};

export default useConversionData;
