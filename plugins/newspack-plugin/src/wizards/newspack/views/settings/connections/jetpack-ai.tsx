/**
 * Settings Wizard: Connections > Jetpack AI Assistant
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import WizardsActionCard from '../../../../wizards-action-card';
import useWizardApiFetchToggle from '../../../../hooks/use-wizard-api-fetch-toggle';

function JetpackAI() {
	const { description, apiData, isFetching, actionText, apiFetchToggle, errorMessage } = useWizardApiFetchToggle< JetpackAiData >( {
		path: '/newspack/v1/wizard/newspack-settings/jetpack-ai',
		apiNamespace: 'newspack-settings/jetpack-ai',
		data: {
			module_enabled_jetpack_ai: false,
		},
		description: __(
			"Enables Jetpack's AI features, such as the AI Assistant block. Off by default; turn on to let editors use Jetpack AI on this site.",
			'newspack-plugin'
		),
	} );

	return (
		<WizardsActionCard
			title={ __( 'Enable Jetpack AI Assistant', 'newspack-plugin' ) }
			description={ description }
			disabled={ isFetching }
			actionText={ actionText }
			error={ errorMessage }
			toggleChecked={ apiData.module_enabled_jetpack_ai }
			toggleOnChange={ ( value: boolean ) => apiFetchToggle( { ...apiData, module_enabled_jetpack_ai: value }, true ) }
		/>
	);
}

export default JetpackAI;
