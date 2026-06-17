/**
 * Pricing-rule common-fields editor. Full-page form; Save/Back live in the wizard
 * header. POST creates (simple-only), PUT updates. Advanced bits (multi-step
 * schedule, conditions) live in the classic editor — surfaced read-only on edit.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	TextControl,
	SelectControl,
	ToggleControl,
	FlexBlock,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Grid, SectionHeader, Divider } from '../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';

const API_PATH = '/wc-dynamic-pricing/v1/rules';

interface RuleFormProps {
	isNew: boolean;
	rule: PricingRuleRow | null;
	vocab: PricingRulesResponse;
	onDone: () => void;
}

interface StepRowState {
	at: string;
	calc_type: string;
	value: string;
	label: string;
}

export default function RuleForm( { isNew, rule, vocab, onDone }: RuleFormProps ) {
	const { setHeaderData, addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

	const [ title, setTitle ] = useState( rule?.title ?? '' );
	const [ status, setStatus ] = useState( rule?.status === 'publish' ? 'publish' : 'draft' );
	const [ calcType, setCalcType ] = useState( rule?.simple?.calc_type ?? vocab.calc_types[ 0 ]?.value ?? 'fixed_price' );
	const [ value, setValue ] = useState( String( rule?.simple?.value ?? '' ) );
	const [ cyclesLimit, setCyclesLimit ] = useState( String( rule?.simple?.cycles_limit ?? 0 ) );
	const [ strategyId, setStrategyId ] = useState( rule?.strategy_id ?? vocab.strategies[ 0 ]?.id ?? 'simple_price' );
	const defaultCalc = vocab.calc_types[ 0 ]?.value ?? 'fixed_price';
	const [ steps, setSteps ] = useState< StepRowState[] >(
		rule?.steps?.length
			? rule.steps.map( s => ( { at: String( s.at ), calc_type: s.calc_type, value: String( s.value ), label: s.label } ) )
			: [ { at: '1', calc_type: defaultCalc, value: '', label: '' } ]
	);
	const isSchedule = strategyId === 'stepped_by_cycle';
	const updateStep = ( i: number, key: keyof StepRowState, val: string ) =>
		setSteps( prev => prev.map( ( s, idx ) => ( idx === i ? { ...s, [ key ]: val } : s ) ) );
	const addStep = () =>
		setSteps( prev => [
			...prev,
			{ at: String( ( Number( prev[ prev.length - 1 ]?.at ) || prev.length ) + 1 ), calc_type: defaultCalc, value: '', label: '' },
		] );
	const removeStep = ( i: number ) => setSteps( prev => prev.filter( ( _, idx ) => idx !== i ) );
	const [ scopeType, setScopeType ] = useState( rule?.scope_type ?? vocab.scopes[ 0 ]?.id ?? 'all_products' );
	const [ priority, setPriority ] = useState( String( rule?.priority ?? 100 ) );
	const [ composeMode, setComposeMode ] = useState( rule?.compose_mode ?? 'min' );
	const [ publicize, setPublicize ] = useState( Boolean( rule?.publicize ) );
	const [ target, setTarget ] = useState( rule?.target_conversion_pct !== null && rule ? String( rule.target_conversion_pct ) : '' );
	const [ maxCancel, setMaxCancel ] = useState( rule?.max_cancellation_pct !== null && rule ? String( rule.max_cancellation_pct ) : '' );
	const [ isSaving, setIsSaving ] = useState( false );

	const submit = useCallback( () => {
		if ( ! title.trim() ) {
			addNotice( { message: __( 'A name is required.', 'newspack-plugin' ), type: 'error', id: 'pricing-rule-name' } );
			return;
		}
		setIsSaving( true );
		const body: Record< string, unknown > = {
			title,
			status,
			scope_type: scopeType,
			priority: Number( priority ) || 0,
			compose_mode: composeMode,
			publicize,
			target_conversion_pct: target === '' ? null : Number( target ),
			max_cancellation_pct: maxCancel === '' ? null : Number( maxCancel ),
		};
		if ( isSchedule ) {
			const cleanSteps = steps
				.filter( s => String( s.value ).trim() !== '' )
				.map( s => ( { at: Number( s.at ) || 1, calc_type: s.calc_type, value: Number( s.value ) || 0, label: s.label } ) );
			if ( ! cleanSteps.length ) {
				addNotice( {
					message: __( 'Add at least one schedule step with a value.', 'newspack-plugin' ),
					type: 'error',
					id: 'pricing-rule-steps',
				} );
				return;
			}
			body.strategy_id = 'stepped_by_cycle';
			body.steps = cleanSteps;
		} else {
			body.strategy_id = 'simple_price';
			body.simple = {
				calc_type: calcType,
				value: Number( value ) || 0,
				cycles_limit: Number( cyclesLimit ) || 0,
				label: rule?.simple?.label ?? '',
			};
		}
		const path = isNew ? API_PATH : `${ API_PATH }/${ rule!.id }`;
		apiFetch( { path, method: isNew ? 'POST' : 'PUT', data: body } )
			.then( () => {
				addNotice( {
					message: isNew ? __( 'Rule created.', 'newspack-plugin' ) : __( 'Rule saved.', 'newspack-plugin' ),
					type: 'success',
					id: 'pricing-rule-saved',
				} );
				onDone();
			} )
			.catch( ( e: { message?: string } ) =>
				addNotice( {
					message: e?.message || __( 'Failed to save the rule.', 'newspack-plugin' ),
					type: 'error',
					id: 'pricing-rule-save-error',
				} )
			)
			.finally( () => setIsSaving( false ) );
	}, [
		title,
		status,
		scopeType,
		priority,
		composeMode,
		publicize,
		target,
		maxCancel,
		isSchedule,
		steps,
		calcType,
		value,
		cyclesLimit,
		isNew,
		rule,
		addNotice,
		onDone,
	] );

	useEffect( () => {
		setHeaderData( {
			backNav: '#/',
			actions: [
				{
					type: 'primary',
					label: isNew ? __( 'Create rule', 'newspack-plugin' ) : __( 'Save changes', 'newspack-plugin' ),
					action: submit,
					disabled: isSaving,
				},
			],
		} );
	}, [ setHeaderData, submit, isNew, isSaving ] );

	return (
		<div className="newspack-pricing-rules__form">
			<Grid columns={ 2 } gutter={ 32 }>
				<SectionHeader title={ __( 'Rule details', 'newspack-plugin' ) } description={ __( 'Name and status.', 'newspack-plugin' ) } />
				<VStack spacing={ 4 }>
					<TextControl label={ __( 'Name', 'newspack-plugin' ) } value={ title } onChange={ setTitle } __next40pxDefaultSize />
					{ ! isNew && rule && (
						<p className="description">
							{ __( 'Deal ID:', 'newspack-plugin' ) } <code>{ rule.deal_key }</code>
						</p>
					) }
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
				</VStack>
			</Grid>

			<Divider alignment="full-width" variant="tertiary" />

			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					title={ __( 'Pricing model', 'newspack-plugin' ) }
					description={ __( 'How matching products are priced.', 'newspack-plugin' ) }
				/>
				<VStack spacing={ 4 }>
					{ isNew ? (
						<SelectControl
							label={ __( 'Pricing model', 'newspack-plugin' ) }
							value={ strategyId }
							options={ vocab.strategies.map( s => ( { label: s.label, value: s.id } ) ) }
							onChange={ setStrategyId }
							__next40pxDefaultSize
						/>
					) : (
						<p className="description">
							{ __( 'Pricing model:', 'newspack-plugin' ) }{ ' ' }
							<strong>{ vocab.strategies.find( s => s.id === strategyId )?.label ?? strategyId }</strong>
						</p>
					) }

					{ isSchedule ? (
						<VStack spacing={ 3 }>
							<p className="description">
								{ __(
									'Each row sets the price from a cycle onward, until a later row takes over. Cycle 1 is the purchase; cycle 2 is the first renewal.',
									'newspack-plugin'
								) }
							</p>
							{ steps.map( ( step, i ) => (
								<HStack key={ i } alignment="flex-end" spacing={ 2 }>
									<FlexBlock>
										<TextControl
											label={ __( 'From cycle #', 'newspack-plugin' ) }
											type="number"
											min={ 1 }
											value={ step.at }
											onChange={ v => updateStep( i, 'at', v ) }
											__next40pxDefaultSize
										/>
									</FlexBlock>
									<FlexBlock>
										<SelectControl
											label={ __( 'Pricing', 'newspack-plugin' ) }
											value={ step.calc_type }
											options={ vocab.calc_types.map( c => ( { label: c.label, value: c.value } ) ) }
											onChange={ v => updateStep( i, 'calc_type', v ) }
											__next40pxDefaultSize
										/>
									</FlexBlock>
									<FlexBlock>
										<TextControl
											label={ __( 'Value', 'newspack-plugin' ) }
											type="number"
											value={ step.value }
											onChange={ v => updateStep( i, 'value', v ) }
											__next40pxDefaultSize
										/>
									</FlexBlock>
									<FlexBlock>
										<TextControl
											label={ __( 'Name shown to reader', 'newspack-plugin' ) }
											value={ step.label }
											onChange={ v => updateStep( i, 'label', v ) }
											__next40pxDefaultSize
										/>
									</FlexBlock>
									<Button isDestructive variant="tertiary" disabled={ steps.length <= 1 } onClick={ () => removeStep( i ) }>
										{ __( 'Remove', 'newspack-plugin' ) }
									</Button>
								</HStack>
							) ) }
							<div>
								<Button variant="secondary" onClick={ addStep }>
									{ __( '+ Add row', 'newspack-plugin' ) }
								</Button>
							</div>
						</VStack>
					) : (
						<>
							<SelectControl
								label={ __( 'Adjustment', 'newspack-plugin' ) }
								value={ calcType }
								options={ vocab.calc_types.map( c => ( { label: c.label, value: c.value } ) ) }
								onChange={ setCalcType }
								__next40pxDefaultSize
							/>
							<TextControl
								label={ __( 'Value', 'newspack-plugin' ) }
								type="number"
								value={ value }
								onChange={ setValue }
								__next40pxDefaultSize
							/>
							<TextControl
								label={ __( 'Apply for first N cycles (0 = unlimited)', 'newspack-plugin' ) }
								type="number"
								value={ cyclesLimit }
								onChange={ setCyclesLimit }
								__next40pxDefaultSize
							/>
						</>
					) }
				</VStack>
			</Grid>

			<Divider alignment="full-width" variant="tertiary" />

			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					title={ __( 'Applies to', 'newspack-plugin' ) }
					description={ __( 'Which products this rule targets.', 'newspack-plugin' ) }
				/>
				<VStack spacing={ 4 }>
					<SelectControl
						label={ __( 'Scope', 'newspack-plugin' ) }
						value={ scopeType }
						options={ vocab.scopes.map( s => ( { label: s.label, value: s.id } ) ) }
						onChange={ setScopeType }
						__next40pxDefaultSize
					/>
					{ rule && rule.scope_ids.length > 0 && (
						<p className="description">
							{ __( 'Targets:', 'newspack-plugin' ) } { rule.scope_ids.join( ', ' ) }
						</p>
					) }
				</VStack>
			</Grid>

			<Divider alignment="full-width" variant="tertiary" />

			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					title={ __( 'Scheduling & behavior', 'newspack-plugin' ) }
					description={ __( 'Priority and how it composes with other rules.', 'newspack-plugin' ) }
				/>
				<VStack spacing={ 4 }>
					<TextControl
						label={ __( 'Priority', 'newspack-plugin' ) }
						type="number"
						value={ priority }
						onChange={ setPriority }
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'When multiple rules match', 'newspack-plugin' ) }
						value={ composeMode }
						options={ [
							{ label: __( 'Best price wins', 'newspack-plugin' ), value: 'min' },
							{ label: __( 'This rule only', 'newspack-plugin' ), value: 'priority_exclusive' },
						] }
						onChange={ setComposeMode }
						__next40pxDefaultSize
					/>
					<ToggleControl
						label={ __( 'Show pricing details to readers', 'newspack-plugin' ) }
						checked={ publicize }
						onChange={ setPublicize }
						__nextHasNoMarginBottom
					/>
				</VStack>
			</Grid>

			<Divider alignment="full-width" variant="tertiary" />

			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					title={ __( 'Success criterion', 'newspack-plugin' ) }
					description={ __( 'Declare at least one so you can measure the deal.', 'newspack-plugin' ) }
				/>
				<VStack spacing={ 4 }>
					<TextControl
						label={ __( 'Target conversion (%)', 'newspack-plugin' ) }
						type="number"
						value={ target }
						onChange={ setTarget }
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Acceptable cancellation at deal end (%)', 'newspack-plugin' ) }
						type="number"
						value={ maxCancel }
						onChange={ setMaxCancel }
						__next40pxDefaultSize
					/>
				</VStack>
			</Grid>
		</div>
	);
}
