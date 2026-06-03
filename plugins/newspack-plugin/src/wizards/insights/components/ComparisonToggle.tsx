/**
 * ComparisonToggle
 *
 * Checkbox to enable "compare to previous period". Component owns no
 * state — caller wires it to useComparisonMode.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

export interface ComparisonToggleProps {
	enabled: boolean;
	onChange: ( v: boolean ) => void;
	className?: string;
}

const ComparisonToggle = ( { enabled, onChange, className }: ComparisonToggleProps ) => {
	const inputId = 'newspack-insights-comparison-toggle';
	return (
		<label className={ className ?? 'newspack-insights__comparison-toggle' } htmlFor={ inputId }>
			<input id={ inputId } type="checkbox" checked={ enabled } onChange={ e => onChange( e.target.checked ) } />
			<span className="newspack-insights__comparison-toggle-label">{ __( 'Compare to previous period', 'newspack-plugin' ) }</span>
		</label>
	);
};

export default ComparisonToggle;
