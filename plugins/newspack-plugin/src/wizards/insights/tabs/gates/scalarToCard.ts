/**
 * Small helper that maps a `GatesScalarMetric` payload + section
 * copy into the MetricCard props used by every Tab 4 scorecard.
 *
 * Centralises the `placeholder_type` → `MetricFormat` mapping so
 * the section components stay declarative.
 */

import { __ } from '@wordpress/i18n';

import type { GatesScalarMetric } from '../../api/gates';
import type { MetricFormat, MetricCardZeroFallback } from '../components/MetricCard';

const formatFor = ( m: GatesScalarMetric ): MetricFormat => {
	switch ( m.placeholder_type ) {
		case 'rate':
			return 'percent';
		case 'currency':
			return 'currency';
		case 'decimal':
			return 'decimal';
		case 'count':
		default:
			return 'number';
	}
};

export interface ScalarCardProps {
	label: string;
	description: string;
	current: GatesScalarMetric;
	previous?: GatesScalarMetric | null;
	/**
	 * Count-fallback for a zero card (NPPD-1694). The section builds it because
	 * it owns the impressions/conversion copy and (for currency cards) the
	 * section-level impressions count. Forwarded as-is; ignored in the error branch.
	 */
	zeroFallback?: MetricCardZeroFallback;
}

export const scalarToMetricCardProps = ( props: ScalarCardProps ) => {
	const { label, description, current, previous, zeroFallback } = props;
	// A failed query renders MetricCard's shared error treatment rather than a
	// misleading zero. The raw message stays server-side; the card shows generic
	// copy keyed off the `error` prop.
	if ( current.state === 'error' ) {
		return { label, description, error: current.error_message ?? __( 'Data temporarily unavailable.', 'newspack-plugin' ) };
	}
	// Schema drift (a hub row present but missing required column(s)) surfaces the
	// shared "some data could not be loaded" note instead of a misleading zero,
	// mirroring the Conversion tab.
	if ( current.state === 'populated' && current.data_missing ) {
		return { label, description, dataMissing: true };
	}
	return {
		label,
		description,
		value: current.value,
		format: formatFor( current ),
		// Suppress the period-over-period delta unless BOTH windows are real
		// computed values. A non-computable current (e.g. an empty window's zero)
		// must not show a delta against a real prior value (that would read as a
		// misleading "↓ 100%").
		previousValue: current.computable && previous && previous.state !== 'error' && previous.computable ? previous.value : null,
		...( zeroFallback ? { zeroFallback } : {} ),
	};
};
