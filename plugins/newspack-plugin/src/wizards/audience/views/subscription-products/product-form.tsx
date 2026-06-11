/**
 * Shared create/edit form for subscription products (content only — the caller wraps it
 * in a Modal). Covers simple, variable (plan repeater), and grouped (bundle picker), plus
 * category + availability + group-subscription. POSTs to create or PUTs to update; the
 * list refetches on success. Type is locked when editing.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	TextControl,
	SelectControl,
	CheckboxControl,
	FormTokenField,
	Notice,
	Flex,
	FlexBlock,
	FlexItem,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Badge } from '../../../../../packages/components/src';
import { PolicyChips, EffectivePrice } from './policy-cells';

const BASE_PATH = '/newspack/v1/wizard/newspack-audience-subscription-products/products';
const CONVENTION_SLUGS = [ 'private-subscriptions', 'free-subscriptions' ];

const PERIOD_OPTIONS = [
	{ label: __( 'day', 'newspack-plugin' ), value: 'day' },
	{ label: __( 'week', 'newspack-plugin' ), value: 'week' },
	{ label: __( 'month', 'newspack-plugin' ), value: 'month' },
	{ label: __( 'year', 'newspack-plugin' ), value: 'year' },
];

type ProductType = 'subscription' | 'variable-subscription' | 'grouped' | 'simple';
type PlanDraft = { id?: number; label: string; price: string; period: string; interval: string; existing: boolean };

const newPlan = ( label = '', period = 'month' ): PlanDraft => ( { label, price: '', period, interval: '1', existing: false } );

export default function ProductForm( {
	mode,
	initial,
	categories,
	bundleOptions,
	currency,
	onClose,
	onSaved,
}: {
	mode: 'create' | 'edit';
	initial?: SubscriptionProduct;
	categories: { id: number; label: string }[];
	bundleOptions: { id: number; label: string }[];
	currency: SubscriptionProductsCurrency;
	onClose: () => void;
	onSaved: ( name: string ) => void;
} ) {
	const isEdit = mode === 'edit' && !! initial;

	const [ name, setName ] = useState( initial?.name ?? '' );
	const [ type, setType ] = useState< ProductType >( ( initial?.type as ProductType ) ?? 'subscription' );
	const [ status, setStatus ] = useState( initial?.status === 'draft' ? 'draft' : 'publish' );
	const [ isDonation, setIsDonation ] = useState( initial?.is_donation ?? false );
	const [ availability, setAvailability ] = useState( initial?.availability ?? 'public' );

	// Categories as names (mapped to IDs on submit); convention categories are managed by
	// the availability picker, so they're excluded here.
	const [ categoryNames, setCategoryNames ] = useState< string[] >(
		( initial?.categories ?? [] ).filter( cat => ! CONVENTION_SLUGS.includes( cat.slug ) ).map( cat => cat.name )
	);

	// Simple fields.
	const [ price, setPrice ] = useState(
		initial && ( initial.type === 'subscription' || initial.type === 'simple' ) && initial.base_price !== null ? String( initial.base_price ) : ''
	);
	const [ period, setPeriod ] = useState( initial?.period || 'month' );
	const [ interval, setInterval ] = useState( initial?.interval ? String( initial.interval ) : '1' );

	// Variable plans.
	const [ plans, setPlans ] = useState< PlanDraft[] >(
		initial && initial.type === 'variable-subscription'
			? initial.variations.map( variation => ( {
					id: variation.id,
					label: variation.plan_label || variation.name,
					price: variation.base_price !== null ? String( variation.base_price ) : '',
					period: variation.period || 'month',
					interval: String( variation.interval || 1 ),
					existing: true,
			  } ) )
			: [ newPlan( __( 'Monthly', 'newspack-plugin' ), 'month' ), newPlan( __( 'Annual', 'newspack-plugin' ), 'year' ) ]
	);

	// Group subscription (applies to product / all plans).
	const [ groupEnabled, setGroupEnabled ] = useState( initial?.is_group_subscription ?? false );
	const [ groupLimit, setGroupLimit ] = useState( initial && initial.group_member_limit > 0 ? String( initial.group_member_limit ) : '0' );

	// Grouped bundle.
	const [ bundled, setBundled ] = useState< number[] >( initial?.bundled_products?.map( b => b.id ) ?? [] );

	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	const updatePlan = ( index: number, key: keyof PlanDraft, value: string ) =>
		setPlans( current => current.map( ( plan, i ) => ( i === index ? { ...plan, [ key ]: value } : plan ) ) );
	const addPlan = () => setPlans( current => [ ...current, newPlan() ] );
	const removePlan = ( index: number ) => setPlans( current => current.filter( ( _, i ) => i !== index ) );
	const toggleBundled = ( id: number, checked: boolean ) =>
		setBundled( current => ( checked ? [ ...current, id ] : current.filter( existing => existing !== id ) ) );

	const submit = () => {
		setError( '' );
		if ( ! name.trim() ) {
			setError( __( 'Product name is required.', 'newspack-plugin' ) );
			return;
		}

		const categoryIds = categoryNames
			.map( label => categories.find( cat => cat.label === label )?.id )
			.filter( ( id ): id is number => typeof id === 'number' );

		const groupFields = { is_group_subscription: groupEnabled, group_member_limit: Number( groupLimit ) || 0 };
		const payload: Record< string, unknown > = {
			name: name.trim(),
			type,
			status,
			is_donation: isDonation,
			availability,
			category_ids: categoryIds,
		};

		if ( type === 'grouped' ) {
			if ( ! bundled.length ) {
				setError( __( 'Select at least one subscription to bundle.', 'newspack-plugin' ) );
				return;
			}
			payload.bundled_product_ids = bundled;
		} else if ( type === 'variable-subscription' ) {
			if ( plans.some( plan => ( ! plan.existing && ! plan.label.trim() ) || plan.price === '' ) ) {
				setError( __( 'Each plan needs a label and a price.', 'newspack-plugin' ) );
				return;
			}
			payload.variations = plans.map( plan => ( {
				...( plan.id ? { id: plan.id } : {} ),
				label: plan.label.trim(),
				price: Number( plan.price ),
				period: plan.period,
				interval: Number( plan.interval ) || 1,
				...groupFields,
			} ) );
		} else if ( type === 'simple' ) {
			// One-time product: price only.
			if ( price === '' ) {
				setError( __( 'A price is required.', 'newspack-plugin' ) );
				return;
			}
			payload.price = Number( price );
		} else {
			if ( price === '' ) {
				setError( __( 'A price is required.', 'newspack-plugin' ) );
				return;
			}
			payload.price = Number( price );
			payload.period = period;
			payload.interval = Number( interval ) || 1;
			Object.assign( payload, groupFields );
		}

		setIsSaving( true );
		apiFetch< { id: number; name: string } >( {
			path: isEdit ? `${ BASE_PATH }/${ initial?.id }` : BASE_PATH,
			method: isEdit ? 'PUT' : 'POST',
			data: payload,
		} )
			.then( response => {
				onSaved( response.name || name.trim() );
				onClose();
			} )
			.catch( ( e: { message?: string } ) => {
				setError( e.message || __( 'Failed to save the product.', 'newspack-plugin' ) );
				setIsSaving( false );
			} );
	};

	return (
		<VStack spacing={ 4 } className="newspack-subscription-products__form">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<TextControl label={ __( 'Name', 'newspack-plugin' ) } value={ name } onChange={ setName } __next40pxDefaultSize />

			<HStack alignment="flex-start" spacing={ 4 }>
				<SelectControl
					label={ __( 'Type', 'newspack-plugin' ) }
					value={ type }
					options={
						type === 'simple'
							? [ { label: __( 'One-time', 'newspack-plugin' ), value: 'simple' } ]
							: [
									{ label: __( 'Simple subscription', 'newspack-plugin' ), value: 'subscription' },
									{ label: __( 'Variable subscription', 'newspack-plugin' ), value: 'variable-subscription' },
									{ label: __( 'Plan group (switching)', 'newspack-plugin' ), value: 'grouped' },
							  ]
					}
					onChange={ value => setType( value as ProductType ) }
					disabled={ isEdit }
					help={ isEdit ? __( 'Type can’t be changed after creation.', 'newspack-plugin' ) : undefined }
					__next40pxDefaultSize
				/>
				<SelectControl
					label={ __( 'Status', 'newspack-plugin' ) }
					value={ status }
					options={ [
						{ label: __( 'Published', 'newspack-plugin' ), value: 'publish' },
						{ label: __( 'Draft', 'newspack-plugin' ), value: 'draft' },
					] }
					onChange={ setStatus }
					__next40pxDefaultSize
				/>
			</HStack>

			{ type === 'subscription' && (
				<HStack alignment="flex-start" spacing={ 4 }>
					<TextControl
						label={ __( 'Price', 'newspack-plugin' ) }
						type="number"
						min="0"
						value={ price }
						onChange={ setPrice }
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Bill every', 'newspack-plugin' ) }
						type="number"
						min="1"
						max="6"
						value={ interval }
						onChange={ setInterval }
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Period', 'newspack-plugin' ) }
						value={ period }
						options={ PERIOD_OPTIONS }
						onChange={ setPeriod }
						__next40pxDefaultSize
					/>
				</HStack>
			) }

			{ type === 'simple' && (
				<TextControl
					label={ __( 'Price (one-time)', 'newspack-plugin' ) }
					type="number"
					min="0"
					value={ price }
					onChange={ setPrice }
					__next40pxDefaultSize
				/>
			) }

			{ type === 'variable-subscription' && (
				<VStack spacing={ 3 }>
					<span className="newspack-subscription-products__add-modal-label">{ __( 'Plans', 'newspack-plugin' ) }</span>
					{ plans.map( ( plan, index ) => (
						<Flex key={ plan.id ?? `new-${ index }` } align="flex-end" gap={ 2 }>
							<FlexBlock>
								<TextControl
									label={ __( 'Plan label', 'newspack-plugin' ) }
									value={ plan.label }
									onChange={ value => updatePlan( index, 'label', value ) }
									disabled={ plan.existing }
									help={ plan.existing ? __( 'Existing plan', 'newspack-plugin' ) : undefined }
									__next40pxDefaultSize
								/>
							</FlexBlock>
							<FlexItem>
								<TextControl
									label={ __( 'Price', 'newspack-plugin' ) }
									type="number"
									min="0"
									value={ plan.price }
									onChange={ value => updatePlan( index, 'price', value ) }
									__next40pxDefaultSize
								/>
							</FlexItem>
							<FlexItem>
								<TextControl
									label={ __( 'Every', 'newspack-plugin' ) }
									type="number"
									min="1"
									max="6"
									value={ plan.interval }
									onChange={ value => updatePlan( index, 'interval', value ) }
									__next40pxDefaultSize
								/>
							</FlexItem>
							<FlexItem>
								<SelectControl
									label={ __( 'Period', 'newspack-plugin' ) }
									value={ plan.period }
									options={ PERIOD_OPTIONS }
									onChange={ value => updatePlan( index, 'period', value ) }
									__next40pxDefaultSize
								/>
							</FlexItem>
							<FlexItem>
								<Button variant="tertiary" isDestructive onClick={ () => removePlan( index ) } disabled={ plans.length <= 1 }>
									{ __( 'Remove', 'newspack-plugin' ) }
								</Button>
							</FlexItem>
						</Flex>
					) ) }
					<div>
						<Button variant="secondary" onClick={ addPlan }>
							{ __( 'Add plan', 'newspack-plugin' ) }
						</Button>
					</div>
				</VStack>
			) }

			{ type === 'grouped' && (
				<VStack spacing={ 2 }>
					<span className="newspack-subscription-products__add-modal-label">{ __( 'Bundled subscriptions', 'newspack-plugin' ) }</span>
					<div className="newspack-subscription-products__bundle-picker">
						{ bundleOptions.length ? (
							bundleOptions.map( option => (
								<CheckboxControl
									key={ option.id }
									label={ option.label }
									checked={ bundled.includes( option.id ) }
									onChange={ checked => toggleBundled( option.id, checked ) }
								/>
							) )
						) : (
							<span className="newspack-subscription-products__muted">
								{ __( 'No subscription products to bundle yet.', 'newspack-plugin' ) }
							</span>
						) }
					</div>
				</VStack>
			) }

			<HStack alignment="flex-start" spacing={ 4 }>
				<SelectControl
					label={ __( 'Availability', 'newspack-plugin' ) }
					value={ availability }
					options={ [
						{ label: __( 'Public', 'newspack-plugin' ), value: 'public' },
						{ label: __( 'Private', 'newspack-plugin' ), value: 'private' },
						{ label: __( 'Free', 'newspack-plugin' ), value: 'free' },
					] }
					onChange={ value => setAvailability( value as SubscriptionProduct[ 'availability' ] ) }
					help={ __( 'Private/Free file the product under the matching category.', 'newspack-plugin' ) }
					__next40pxDefaultSize
				/>
				<FlexBlock>
					<FormTokenField
						label={ __( 'Categories', 'newspack-plugin' ) }
						value={ categoryNames }
						suggestions={ categories.map( cat => cat.label ) }
						onChange={ tokens => setCategoryNames( tokens as string[] ) }
						__experimentalExpandOnFocus
						__next40pxDefaultSize
					/>
				</FlexBlock>
			</HStack>

			{ ( type === 'subscription' || type === 'variable-subscription' ) && (
				<VStack spacing={ 2 }>
					<CheckboxControl
						label={ __( 'Group subscription (multi-seat)', 'newspack-plugin' ) }
						help={ __( 'Let one purchase grant access to multiple members.', 'newspack-plugin' ) }
						checked={ groupEnabled }
						onChange={ setGroupEnabled }
					/>
					{ groupEnabled && (
						<TextControl
							label={ __( 'Member limit (0 = unlimited)', 'newspack-plugin' ) }
							type="number"
							min="0"
							value={ groupLimit }
							onChange={ setGroupLimit }
							__next40pxDefaultSize
						/>
					) }
				</VStack>
			) }

			<CheckboxControl label={ __( 'This is a donation product', 'newspack-plugin' ) } checked={ isDonation } onChange={ setIsDonation } />

			{ isEdit && initial && ( initial.unlocks.length > 0 || initial.policy.policies.length > 0 ) && (
				<VStack spacing={ 2 } className="newspack-subscription-products__form-info">
					<hr className="newspack-subscription-products__modal-divider" />
					{ initial.policy.policies.length > 0 && (
						<HStack justify="space-between" alignment="topLeft" spacing={ 4 }>
							<span className="newspack-subscription-products__modal-label">{ __( 'Applied policies', 'newspack-plugin' ) }</span>
							<span>
								<PolicyChips policy={ initial.policy } /> <EffectivePrice policy={ initial.policy } currency={ currency } />
							</span>
						</HStack>
					) }
					{ initial.unlocks.length > 0 && (
						<HStack justify="space-between" alignment="topLeft" spacing={ 4 }>
							<span className="newspack-subscription-products__modal-label">{ __( 'Unlocks', 'newspack-plugin' ) }</span>
							<span className="newspack-subscription-products__unlocks">
								{ initial.unlocks.map( gate => (
									<Badge key={ gate.id } level="default" text={ gate.title } />
								) ) }
							</span>
						</HStack>
					) }
				</VStack>
			) }

			<HStack justify="flex-end" spacing={ 2 }>
				<Button variant="tertiary" onClick={ onClose } disabled={ isSaving }>
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Button>
				<Button variant="primary" onClick={ submit } isBusy={ isSaving } disabled={ isSaving }>
					{ isEdit ? __( 'Save changes', 'newspack-plugin' ) : __( 'Create product', 'newspack-plugin' ) }
				</Button>
			</HStack>
		</VStack>
	);
}
