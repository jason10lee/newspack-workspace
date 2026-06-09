/**
 * TabNavigation
 *
 * Horizontal tab bar for the Insights wizard. Visibility per tab is
 * driven by props (computed at boot from feature detection — stubbed
 * all-on for now). Active tab highlighting per design spec.
 *
 * Component owns no state — caller passes activeTab and onTabChange.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * External dependencies
 */
import classnames from 'classnames';

export type TabKey = 'audience' | 'engagement' | 'conversion' | 'gates' | 'prompts' | 'subscribers' | 'donors' | 'advertising';

export interface TabDef {
	key: TabKey;
	label: string;
}

export const ALL_TABS: TabDef[] = [
	{ key: 'audience', label: __( 'Audience', 'newspack-plugin' ) },
	{ key: 'engagement', label: __( 'Engagement', 'newspack-plugin' ) },
	{ key: 'conversion', label: __( 'Conversion Journey', 'newspack-plugin' ) },
	{ key: 'gates', label: __( 'Gates', 'newspack-plugin' ) },
	{ key: 'prompts', label: __( 'Prompts', 'newspack-plugin' ) },
	{ key: 'subscribers', label: __( 'Subscribers', 'newspack-plugin' ) },
	{ key: 'donors', label: __( 'Donors', 'newspack-plugin' ) },
	{ key: 'advertising', label: __( 'Advertising', 'newspack-plugin' ) },
];

export type TabVisibility = Record< TabKey, boolean >;

export interface TabNavigationProps {
	activeTab: TabKey;
	visibility: TabVisibility;
	onTabChange: ( tab: TabKey ) => void;
	className?: string;
}

const TabNavigation = ( { activeTab, visibility, onTabChange, className }: TabNavigationProps ) => {
	const visibleTabs = ALL_TABS.filter( t => visibility[ t.key ] );
	return (
		<div
			className={ classnames( 'newspack-insights__tabs', className ) }
			role="tablist"
			aria-label={ __( 'Insights sections', 'newspack-plugin' ) }
		>
			{ visibleTabs.map( tab => {
				const isActive = tab.key === activeTab;
				return (
					<button
						key={ tab.key }
						type="button"
						role="tab"
						aria-selected={ isActive }
						aria-controls={ `newspack-insights-panel-${ tab.key }` }
						id={ `newspack-insights-tab-${ tab.key }` }
						className={ classnames( 'newspack-insights__tab', isActive && 'is-active' ) }
						onClick={ () => onTabChange( tab.key ) }
					>
						{ tab.label }
					</button>
				);
			} ) }
		</div>
	);
};

export default TabNavigation;
