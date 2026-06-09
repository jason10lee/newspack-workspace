/**
 * DateRangePicker
 *
 * Preset-driven date range selector. Six presets (last-7, last-30 default,
 * last-90, this-month, last-month, custom). Custom mode reveals two date
 * inputs.
 *
 * Component owns no state — caller wires it to useDateRange.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { DATE_RANGE_PRESETS, type DateRange, type DateRangePreset } from '../state/useDateRange';

export interface DateRangePickerProps {
	range: DateRange;
	onPresetChange: ( preset: DateRangePreset ) => void;
	onCustomChange: ( start: string, end: string ) => void;
	className?: string;
}

const DateRangePicker = ( { range, onPresetChange, onCustomChange, className }: DateRangePickerProps ) => {
	const presetId = 'newspack-insights-date-range-preset';
	const startId = 'newspack-insights-date-range-start';
	const endId = 'newspack-insights-date-range-end';
	return (
		<div className={ className ?? 'newspack-insights__date-range-picker' }>
			<label className="newspack-insights__date-range-picker-label" htmlFor={ presetId }>
				<span className="screen-reader-text">{ __( 'Date range', 'newspack-plugin' ) }</span>
				<select
					id={ presetId }
					className="newspack-insights__date-range-picker-select"
					value={ range.preset }
					onChange={ e => onPresetChange( e.target.value as DateRangePreset ) }
				>
					{ DATE_RANGE_PRESETS.map( p => (
						<option key={ p.key } value={ p.key }>
							{ p.label }
						</option>
					) ) }
				</select>
			</label>

			{ range.preset === 'custom' && (
				<div className="newspack-insights__date-range-picker-custom">
					<label htmlFor={ startId }>
						<span className="screen-reader-text">{ __( 'Start date', 'newspack-plugin' ) }</span>
						<input id={ startId } type="date" value={ range.start } onChange={ e => onCustomChange( e.target.value, range.end ) } />
					</label>
					<span className="newspack-insights__date-range-picker-sep" aria-hidden="true">
						{ '→' }
					</span>
					<label htmlFor={ endId }>
						<span className="screen-reader-text">{ __( 'End date', 'newspack-plugin' ) }</span>
						<input id={ endId } type="date" value={ range.end } onChange={ e => onCustomChange( range.start, e.target.value ) } />
					</label>
				</div>
			) }
		</div>
	);
};

export default DateRangePicker;
