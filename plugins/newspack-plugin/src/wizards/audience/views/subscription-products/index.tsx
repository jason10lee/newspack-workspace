/**
 * Subscription Products management screen.
 *
 * Exploratory RSM prototype: a DataViews list of WooCommerce Subscriptions products
 * with the consolidated product model (Layer 1) and the applied-policy stack +
 * effective price (Layer 2, behind the Subscription_Policy_Resolver seam).
 */

import '../../../../shared/js/public-path';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { forwardRef } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { Wizard, withWizard } from '../../../../../packages/components/src';
import SubscriptionProductsList from './list';
import './style.scss';

const AudienceSubscriptionProducts = ( props: object, ref: React.Ref< HTMLDivElement > ) => {
	return (
		<Wizard
			title={ __( 'Subscription products', 'newspack-plugin' ) }
			headerText={ __( 'Audience Management / Subscription products', 'newspack-plugin' ) }
			ref={ ref }
			fixedHeader
			sections={ [
				{
					path: '/',
					render: SubscriptionProductsList,
					exact: true,
					fullWidth: true,
				},
			] }
		/>
	);
};

export default withWizard( forwardRef( AudienceSubscriptionProducts ) );
