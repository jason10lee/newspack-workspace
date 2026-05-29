/**
 * Newspack > Settings > Print
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import WizardsTab from '../../../../wizards-tab';
import WizardSection from '../../../../wizards-section';
import WizardsToggleHeaderCard from '../../../../wizards-toggle-header-card';
import { RadioControl } from '../../../../../../packages/components/src';

function Print() {
	return (
		<WizardsTab title={ __( 'Adobe Indesign', 'newspack-plugin' ) }>
			<WizardSection>
				<WizardsToggleHeaderCard< PrintData >
					title={ __( 'Enable InDesign Export', 'newspack-plugin' ) }
					namespace="newspack-settings/print"
					description={ __( 'Allows editors to export article content in Adobe InDesign formats.', 'newspack-plugin' ) }
					path="/newspack/v1/wizard/newspack-settings/print"
					defaultValue={ {
						module_enabled_print: false,
						format: 'tagged-text',
					} }
					fieldValidationMap={ [] }
					onChecked={ ( data: PrintData ) => data.module_enabled_print }
					onToggle={ ( active: boolean, data: PrintData ) => ( { ...data, module_enabled_print: active } ) }
					renderProp={ ( { settingsUpdates, setSettingsUpdates, isFetching } ) => (
						<RadioControl
							label={ __( 'Export format', 'newspack-plugin' ) }
							help={ __(
								'Tagged Text exports article copy only. XML bundles article copy plus images in a ZIP — InDesign places the images inline when the XML is imported.',
								'newspack-plugin'
							) }
							selected={ settingsUpdates?.format ?? 'tagged-text' }
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
							onChange={ ( value: string ) =>
								setSettingsUpdates( { ...settingsUpdates, format: value as PrintData[ 'format' ] } )
							}
							disabled={ isFetching }
						/>
					) }
				/>
			</WizardSection>
		</WizardsTab>
	);
}

export default Print;
