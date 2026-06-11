/**
 * "Add product" modal — create a subscription product without leaving the page.
 *
 * Productized create form covering all three shapes:
 *   - simple subscription (price + billing period)
 *   - variable subscription (a plan per billing period)
 *   - grouped "Plan group" (bundle existing subscriptions for plan switching)
 * Plus the group-subscription (multi-seat) settings. The POST endpoint builds a
 * well-formed WooCommerce product; the list refetches on success (WC's object cache
 * can't reliably surface the new product in the create request).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Modal,
	Button,
	TextControl,
	SelectControl,
	CheckboxControl,
	Notice,
	Flex,
	FlexBlock,
	FlexItem,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

const API_PATH = '/newspack/v1/wizard/newspack-audience-subscription-products/products';

const PERIOD_OPTIONS = [
	{ label: __( 'day', 'newspack-plugin' ), value: 'day' },
	{ label: __( 'week', 'newspack-plugin' ), value: 'week' },
	{ label: __( 'month', 'newspack-plugin' ), value: 'month' },
	{ label: __( 'year', 'newspack-plugin' ), value: 'year' },
];

type ProductType = 'subscription' | 'variable-subscription' | 'grouped';
type PlanDraft = { label: string; price: string; period: string; interval: string };

const newPlan = ( label = '', period = 'month' ): PlanDraft => ( { label, price: '', period, interval: '1' } );

export default function AddProductModal( {
	onClose,
	onCreated,
	bundleOptions,
}: {
	onClose: () => void;
	onCreated: ( name: string ) => void;
	bundleOptions: { id: number; label: string }[];
} ) {
	const [ name, setName ] = useState( '' );
	const [ type, setType ] = useState< ProductType >( 'subscription' );
	const [ status, setStatus ] = useState( 'publish' );
	const [ isDonation, setIsDonation ] = useState( false );

	// Simple-subscription fields.
	const [ price, setPrice ] = useState( '' );
	const [ period, setPeriod ] = useState( 'month' );
	const [ interval, setInterval ] = useState( '1' );

	// Variable-subscription plans.
	const [ plans, setPlans ] = useState< PlanDraft[] >( [
		newPlan( __( 'Monthly', 'newspack-plugin' ), 'month' ),
		newPlan( __( 'Annual', 'newspack-plugin' ), 'year' ),
	] );

	// Group subscription (multi-seat) — applies to the product / all its plans.
	const [ groupEnabled, setGroupEnabled ] = useState( false );
	const [ groupLimit, setGroupLimit ] = useState( '0' );

	// Grouped "Plan group" — which existing subscriptions to bundle.
	const [ bundled, setBundled ] = useState< number[] >( [] );

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

		const payload: Record< string, unknown > = { name: name.trim(), type, status, is_donation: isDonation };
		const groupFields = { is_group_subscription: groupEnabled, group_member_limit: Number( groupLimit ) || 0 };

		if ( type === 'grouped' ) {
			if ( ! bundled.length ) {
				setError( __( 'Select at least one subscription to bundle.', 'newspack-plugin' ) );
				return;
			}
			payload.bundled_product_ids = bundled;
		} else if ( type === 'variable-subscription' ) {
			if ( plans.some( plan => ! plan.label.trim() || plan.price === '' ) ) {
				setError( __( 'Each plan needs a label and a price.', 'newspack-plugin' ) );
				return;
			}
			payload.variations = plans.map( plan => ( {
				label: plan.label.trim(),
				price: Number( plan.price ),
				period: plan.period,
				interval: Number( plan.interval ) || 1,
				...groupFields,
			} ) );
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
		apiFetch< { id: number; name: string } >( { path: API_PATH, method: 'POST', data: payload } )
			.then( response => {
				onCreated( response.name || name.trim() );
				onClose();
			} )
			.catch( ( e: { message?: string } ) => {
				setError( e.message || __( 'Failed to create the product.', 'newspack-plugin' ) );
				setIsSaving( false );
			} );
	};

	return (
		<Modal
			title={ __( 'Add subscription product', 'newspack-plugin' ) }
			onRequestClose={ onClose }
			className="newspack-subscription-products__add-modal"
		>
			<VStack spacing={ 4 }>
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
						options={ [
							{ label: __( 'Simple subscription', 'newspack-plugin' ), value: 'subscription' },
							{ label: __( 'Variable subscription', 'newspack-plugin' ), value: 'variable-subscription' },
							{ label: __( 'Plan group (switching)', 'newspack-plugin' ), value: 'grouped' },
						] }
						onChange={ value => setType( value as ProductType ) }
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

				{ type === 'variable-subscription' && (
					<VStack spacing={ 3 }>
						<span className="newspack-subscription-products__add-modal-label">{ __( 'Plans', 'newspack-plugin' ) }</span>
						{ plans.map( ( plan, index ) => (
							<Flex key={ index } align="flex-end" gap={ 2 }>
								<FlexBlock>
									<TextControl
										label={ __( 'Plan label', 'newspack-plugin' ) }
										value={ plan.label }
										onChange={ value => updatePlan( index, 'label', value ) }
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
						<p className="newspack-subscription-products__add-modal-help">
							{ __( 'Choose the subscriptions readers can switch between in this plan group.', 'newspack-plugin' ) }
						</p>
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

				{ type !== 'grouped' && (
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

				<HStack justify="flex-end" spacing={ 2 }>
					<Button variant="tertiary" onClick={ onClose } disabled={ isSaving }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" onClick={ submit } isBusy={ isSaving } disabled={ isSaving }>
						{ __( 'Create product', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
