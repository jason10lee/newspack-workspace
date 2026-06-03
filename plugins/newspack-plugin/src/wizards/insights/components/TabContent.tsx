/**
 * TabContent
 *
 * Lazy-loads the appropriate tab component based on activeTab and renders
 * it inside a Suspense boundary with a skeleton fallback. Each tab is
 * code-split via React.lazy.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { lazy, Suspense } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { TabKey } from './TabNavigation';
import type { DateRange } from '../state/useDateRange';

const AudienceTab = lazy( () => import( '../tabs/AudienceTab' ) );
const EngagementTab = lazy( () => import( '../tabs/EngagementTab' ) );
const ConversionTab = lazy( () => import( '../tabs/ConversionTab' ) );
const GatesTab = lazy( () => import( '../tabs/GatesTab' ) );
const PromptsTab = lazy( () => import( '../tabs/PromptsTab' ) );
const SubscribersTab = lazy( () => import( '../tabs/SubscribersTab' ) );
const DonorsTab = lazy( () => import( '../tabs/DonorsTab' ) );
const AdvertisingTab = lazy( () => import( '../tabs/AdvertisingTab' ) );

export interface TabContentProps {
	activeTab: TabKey;
	range: DateRange;
	previousRange: DateRange | null;
}

const Fallback = () => (
	<div
		className="newspack-insights__tab-fallback"
		role="status"
		aria-live="polite"
	>
		{ __( 'Loading…', 'newspack-plugin' ) }
	</div>
);

const TabContent = ( props: TabContentProps ) => {
	const { activeTab } = props;
	const renderTab = () => {
		switch ( activeTab ) {
			case 'audience':
				return <AudienceTab { ...props } />;
			case 'engagement':
				return <EngagementTab { ...props } />;
			case 'conversion':
				return <ConversionTab { ...props } />;
			case 'gates':
				return <GatesTab { ...props } />;
			case 'prompts':
				return <PromptsTab { ...props } />;
			case 'subscribers':
				return <SubscribersTab { ...props } />;
			case 'donors':
				return <DonorsTab { ...props } />;
			case 'advertising':
				return <AdvertisingTab { ...props } />;
			default:
				return null;
		}
	};
	return (
		<div
			className="newspack-insights__tab-content"
			role="tabpanel"
			id={ `newspack-insights-panel-${ activeTab }` }
			aria-labelledby={ `newspack-insights-tab-${ activeTab }` }
		>
			<Suspense fallback={ <Fallback /> }>{ renderTab() }</Suspense>
		</div>
	);
};

export default TabContent;
