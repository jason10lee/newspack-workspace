/**
 * Subscribers API client (NPPD-1616).
 *
 * Thin wrapper around `@wordpress/api-fetch` for the single Tab 6
 * endpoint: `GET /newspack-insights/v1/subscribers`. Type definitions
 * here are the source of truth for the React layer and mirror the PHP
 * response shape assembled by `Subscribers_REST_Controller`.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

export type StorageBackend = 'hpos' | 'legacy';

export interface SubscribersClassification {
	backend: StorageBackend;
	donation_product_count: number;
	has_donation_family: boolean;
}

export interface TenureDistributionRow {
	product_name: string;
	tenure_days: number;
}

export interface UpcomingRenewals {
	count: number;
	total_value: number;
}

export interface SubscribersSnapshot {
	active_subscribers: number;
	mrr: number;
	arr: number;
	tenure_distribution: TenureDistributionRow[];
	upcoming_renewals_30d: UpcomingRenewals;
}

export interface PerformanceVariationRow {
	variation_id: number;
	label: string;
	active_subs: number;
	churned_subs: number;
	active_value: number;
	lifetime_revenue: number;
}

export interface PerformanceRow {
	product_id: number;
	name: string;
	is_parent: boolean;
	active_subs: number;
	churned_subs: number;
	active_value: number;
	lifetime_revenue: number;
	/** Present only when `is_parent` is true. Sorted by active_subs descending. */
	variations?: PerformanceVariationRow[];
}

export interface CancellationReasonRow {
	cancellation_reason: string;
	count: number;
}

export interface SubscribersWindow {
	window: { start: string; end: string };
	new_subscribers: number;
	churned_subscribers: number;
	revenue_gross: number;
	revenue_net: number;
	refund_rate: number;
	failed_payment_retry_rate: number;
	performance_by_product: PerformanceRow[];
	cancellation_reasons: CancellationReasonRow[];
}

export interface SubscribersResponse {
	classification: SubscribersClassification;
	snapshot: SubscribersSnapshot;
	current: SubscribersWindow;
	previous: SubscribersWindow | null;
}

export interface SubscribersQuery {
	start: string;
	end: string;
	compare_start?: string;
	compare_end?: string;
}

const ENDPOINT = '/newspack-insights/v1/subscribers';

/**
 * Fetch Tab 6 data for the given window pair. Returns the full
 * classification + snapshot + current + previous payload.
 *
 * Throws on REST error (caught by the calling hook into an `error`
 * state).
 */
export const fetchSubscribersData = async ( query: SubscribersQuery ): Promise< SubscribersResponse > => {
	const params = new URLSearchParams();
	params.set( 'start', query.start );
	params.set( 'end', query.end );
	if ( query.compare_start && query.compare_end ) {
		params.set( 'compare_start', query.compare_start );
		params.set( 'compare_end', query.compare_end );
	}
	return apiFetch< SubscribersResponse >( {
		path: `${ ENDPOINT }?${ params.toString() }`,
		method: 'GET',
	} );
};
