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
 * External dependencies
 */
import classnames from 'classnames';

export type TabKey =
	| 'audience'
	| 'engagement'
	| 'conversion'
	| 'gates'
	| 'prompts'
	| 'subscribers'
	| 'donors'
	| 'advertising';

export interface TabDef {
	key: TabKey;
	label: string;
}

export const ALL_TABS: TabDef[] = [
	{ key: 'audience', label: 'Audience' },
	{ key: 'engagement', label: 'Engagement' },
	{ key: 'conversion', label: 'Conversion Journey' },
	{ key: 'gates', label: 'Gates' },
	{ key: 'prompts', label: 'Prompts' },
	{ key: 'subscribers', label: 'Subscribers' },
	{ key: 'donors', label: 'Donors' },
	{ key: 'advertising', label: 'Advertising' },
];

export type TabVisibility = Record< TabKey, boolean >;

export interface TabNavigationProps {
	activeTab: TabKey;
	visibility: TabVisibility;
	onTabChange: ( tab: TabKey ) => void;
	className?: string;
}

const TabNavigation = ( {
	activeTab,
	visibility,
	onTabChange,
	className,
}: TabNavigationProps ) => {
	const visibleTabs = ALL_TABS.filter( t => visibility[ t.key ] );
	return (
		<nav
			className={ classnames( 'newspack-insights__tabs', className ) }
			role="tablist"
			aria-label="Insights sections"
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
						className={ classnames(
							'newspack-insights__tab',
							isActive && 'is-active'
						) }
						onClick={ () => onTabChange( tab.key ) }
					>
						{ tab.label }
					</button>
				);
			} ) }
		</nav>
	);
};

export default TabNavigation;
