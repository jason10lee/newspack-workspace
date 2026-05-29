/**
 * Newspack > Settings > Print
 */

/**
 * WordPress dependencies
 */
import { RadioControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import WizardsTab from '../../../../wizards-tab';
import WizardSection from '../../../../wizards-section';
import WizardsActionCard from '../../../../wizards-action-card';
import useWizardApiFetchToggle from '../../../../hooks/use-wizard-api-fetch-toggle';

function Print() {
	const { description, apiData, isFetching, actionText, apiFetchToggle, errorMessage } = useWizardApiFetchToggle< PrintData >( {
		path: '/newspack/v1/wizard/newspack-settings/print',
		apiNamespace: 'newspack-settings/print',
		data: {
			module_enabled_print: false,
			format: 'tagged-text',
		},
		description: __( 'Allows editors to export article content in Adobe InDesign formats.', 'newspack-plugin' ),
	} );

	return (
		<WizardsTab title={ __( 'Adobe Indesign', 'newspack-plugin' ) }>
			<WizardSection>
				<WizardsActionCard
					title={ __( 'Enable InDesign Export', 'newspack-plugin' ) }
					description={ description }
					disabled={ isFetching }
					actionText={ actionText }
					error={ errorMessage }
					toggleChecked={ apiData.module_enabled_print }
					toggleOnChange={ ( value: boolean ) => apiFetchToggle( { ...apiData, module_enabled_print: value }, true ) }
				/>
			</WizardSection>
			{ apiData.module_enabled_print && (
				<WizardSection title={ __( 'Export Format', 'newspack-plugin' ) }>
					<RadioControl
						label={ __( 'Export format', 'newspack-plugin' ) }
						help={ __(
							'Tagged Text is text-only. XML bundles images in a ZIP and requires an InDesign template mapped to the XML element names.',
							'newspack-plugin'
						) }
						selected={ apiData.format }
						options={ [
							{
								label: __( 'Tagged Text (.txt)', 'newspack-plugin' ),
								value: 'tagged-text',
							},
							{
								label: __( 'XML + Images (.zip)', 'newspack-plugin' ),
								value: 'xml',
							},
						] }
						onChange={ ( value: string ) => apiFetchToggle( { ...apiData, format: value as PrintData[ 'format' ] }, true ) }
					/>
				</WizardSection>
			) }
		</WizardsTab>
	);
}

export default Print;
