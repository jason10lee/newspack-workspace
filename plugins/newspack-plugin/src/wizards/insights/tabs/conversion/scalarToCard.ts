/**
 * Maps a `ConversionScalarMetric` payload + section copy into the
 * MetricCard props used by every Tab 3 scorecard (Sections 7 and
 * 8.1–8.3). Centralises the `placeholder_type` → `MetricFormat` mapping
 * so the section components stay declarative. Mirrors the prompts tab's
 * `scalarToCard.ts`.
 */

import { __ } from '@wordpress/i18n';

import type { ConversionScalarMetric } from '../../api/conversion';
import type { MetricFormat } from '../components/MetricCard';

const formatFor = ( m: ConversionScalarMetric ): MetricFormat => {
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
	current: ConversionScalarMetric;
	previous?: ConversionScalarMetric | null;
}

export const scalarToMetricCardProps = ( props: ScalarCardProps ) => {
	const { label, description, current, previous } = props;
	// A failed query renders MetricCard's shared error treatment rather than a
	// misleading zero. The raw message stays server-side; the card shows generic
	// copy keyed off the `error` prop.
	if ( current.state === 'error' ) {
		return { label, description, error: current.error_message ?? __( 'Data temporarily unavailable.', 'newspack-plugin' ) };
	}
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
		pending: current.state === 'coming_soon',
	};
};
