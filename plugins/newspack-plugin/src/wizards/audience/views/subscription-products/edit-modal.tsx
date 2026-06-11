/**
 * Row "Edit" action modal for a subscription product.
 *
 * Modeled on the newsletters list `action.RenderModal` pattern. Editing the full
 * WooCommerce product in a modal is out of scope for this prototype, so the modal
 * surfaces the consolidated product model + the resolved policy stack and deep-links
 * to the canonical WooCommerce product editor for the actual edit.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	Button,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Badge } from '../../../../../packages/components/src';
import { PolicyChips, EffectivePrice } from './policy-cells';

/**
 * A labeled summary row.
 */
function Row( { label, children }: { label: string; children: React.ReactNode } ) {
	return (
		<HStack alignment="topLeft" justify="space-between" spacing={ 4 }>
			<span className="newspack-subscription-products__modal-label">{ label }</span>
			<span className="newspack-subscription-products__modal-value">{ children }</span>
		</HStack>
	);
}

export default function EditProductModal( {
	item,
	currency,
	closeModal,
}: {
	item: SubscriptionProduct;
	currency: SubscriptionProductsCurrency;
	closeModal: () => void;
} ) {
	const hasActiveCount = item.active_subscriptions !== null && item.active_subscriptions !== undefined;
	const availabilityLevels = { free: 'info', private: 'warning', public: 'default' } as const;

	return (
		<VStack spacing={ 4 } className="newspack-subscription-products__modal">
			<Row label={ __( 'Type', 'newspack-plugin' ) }>{ item.type_label }</Row>
			<Row label={ __( 'Status', 'newspack-plugin' ) }>
				<Badge level={ item.status === 'publish' ? 'success' : 'default' } text={ item.status_label } />
			</Row>
			<Row label={ __( 'Price', 'newspack-plugin' ) }>
				{ item.type === 'variable-subscription' && item.price_range_label
					? item.price_range_label
					: item.price_label || <span className="newspack-subscription-products__muted">&mdash;</span> }
			</Row>
			<Row label={ __( 'Category', 'newspack-plugin' ) }>
				{ item.category_label || <span className="newspack-subscription-products__muted">{ __( 'Uncategorized', 'newspack-plugin' ) }</span> }
			</Row>
			<Row label={ __( 'Availability', 'newspack-plugin' ) }>
				<Badge level={ availabilityLevels[ item.availability ] } text={ item.availability_label } />
			</Row>
			<Row label={ __( 'Active subscriptions', 'newspack-plugin' ) }>
				{ hasActiveCount ? item.active_subscriptions : <span className="newspack-subscription-products__muted">&mdash;</span> }
			</Row>
			{ item.is_group_subscription && (
				<Row label={ __( 'Members', 'newspack-plugin' ) }>
					<Badge level="info" text={ item.group_member_label } />
				</Row>
			) }
			{ item.type === 'grouped' && (
				<Row label={ __( 'Bundled plans', 'newspack-plugin' ) }>
					{ item.bundled_products.length ? (
						<div className="newspack-subscription-products__bundled">
							{ item.bundled_products.map( bundled => (
								<Badge key={ bundled.id } level="default" text={ bundled.name } />
							) ) }
						</div>
					) : (
						<span className="newspack-subscription-products__muted">&mdash;</span>
					) }
				</Row>
			) }
			<Row label={ __( 'Unlocks', 'newspack-plugin' ) }>
				{ item.unlocks.length ? (
					<div className="newspack-subscription-products__unlocks">
						{ item.unlocks.map( gate => (
							<Badge key={ gate.id } level="default" text={ gate.title } />
						) ) }
					</div>
				) : (
					<span className="newspack-subscription-products__muted">{ __( 'Nothing gated', 'newspack-plugin' ) }</span>
				) }
			</Row>

			<hr className="newspack-subscription-products__modal-divider" />

			<Row label={ __( 'Applied policies', 'newspack-plugin' ) }>
				<PolicyChips policy={ item.policy } />
			</Row>
			<Row label={ __( 'Effective price', 'newspack-plugin' ) }>
				<EffectivePrice policy={ item.policy } currency={ currency } />
			</Row>

			{ item.type === 'variable-subscription' && item.variations.length > 0 && (
				<>
					<hr className="newspack-subscription-products__modal-divider" />
					<span className="newspack-subscription-products__modal-label">{ __( 'Per-plan policies & pricing', 'newspack-plugin' ) }</span>
					<VStack spacing={ 3 } className="newspack-subscription-products__variations">
						{ item.variations.map( variation => (
							<div key={ variation.id } className="newspack-subscription-products__variation">
								<HStack justify="space-between" spacing={ 4 } alignment="topLeft">
									<strong>{ variation.name }</strong>
									<EffectivePrice policy={ variation.policy } currency={ currency } />
								</HStack>
								<PolicyChips policy={ variation.policy } />
							</div>
						) ) }
					</VStack>
				</>
			) }

			{ item.policy?.is_mock && (
				<p className="newspack-subscription-products__modal-note">
					{ __( 'Policy data shown here is mocked. It will be replaced by the live policy engine with no UI change.', 'newspack-plugin' ) }
				</p>
			) }

			<HStack justify="flex-end" spacing={ 2 }>
				<Button variant="tertiary" onClick={ closeModal }>
					{ __( 'Close', 'newspack-plugin' ) }
				</Button>
				<Button variant="primary" href={ item.edit_url } target="_blank" rel="noopener noreferrer">
					{ __( 'Edit in WooCommerce', 'newspack-plugin' ) }
				</Button>
			</HStack>
		</VStack>
	);
}
