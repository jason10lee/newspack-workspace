/**
 * Newspack > Settings > Emails > Emails section
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useMemo, Fragment } from '@wordpress/element';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import type { Action, Field, View } from '@wordpress/dataviews';

/**
 * Internal dependencies.
 */
import { Badge, DataViews, Notice, utils } from '../../../../../../packages/components/src';
import WizardsPluginCard from '../../../../wizards-plugin-card';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import './emails.scss';

interface EmailItem {
	label: string;
	post_id: number;
	edit_link: string;
	status: string;
	type: string;
	category: string;
	trigger_description: string;
	recipient: 'reader' | 'admin';
	recommended: boolean;
	chip: 'auth-account' | 'reader-revenue';
	source: 'newspack' | 'woocommerce';
}

interface EmailSettings {
	newspack_emails: EmailItem[];
	post_type: string;
	admin_url?: string;
	enable_woocommerce_email_editor?: boolean;
}

const DEFAULT_VIEW: View = {
	type: 'grid',
	page: 1,
	perPage: 50,
	search: '',
	fields: [ 'recipient', 'status' ],
	filters: [],
	layout: {},
	titleField: 'name',
	descriptionField: 'trigger_description',
};

const PageHeading = () => <h1 className="screen-reader-text">{ __( 'Emails', 'newspack-plugin' ) }</h1>;

const Emails = () => {
	const emailSections = window.newspackSettings.emails.sections;
	const [ pluginsReady, setPluginsReady ] = useState( Boolean( emailSections.emails.dependencies.newspackNewsletters ) );

	// Seed from the SSR bootstrap (class-newspack-settings.php passes the same
	// shape as api_get_email_settings()) so DataViews renders on first paint
	// instead of waiting for the mount-time XHR.
	const initial = emailSections.emails.initial;
	const [ data, setData ] = useState< EmailItem[] >( initial?.newspack_emails ?? [] );
	const postType = initial?.post_type ?? emailSections.emails.postType;
	const [ view, setView ] = useState< View >( DEFAULT_VIEW );

	const { wizardApiFetch, isFetching, errorMessage, resetError } = useWizardApiFetch( 'newspack-settings/emails' );

	const fetchData = useCallback( () => {
		resetError();
		wizardApiFetch< EmailSettings >(
			{
				path: '/newspack/v1/wizard/newspack-settings/emails',
				isCached: false,
			},
			{
				onSuccess( result: EmailSettings ) {
					setData( result.newspack_emails || [] );
				},
				onError() {
					// useWizardApiFetch surfaces the message via errorMessage;
					// this handler is here so a rejection doesn't become an
					// unhandled promise rejection if the hook's plumbing changes.
				},
			}
		);
	}, [ wizardApiFetch, resetError ] );

	useEffect( () => {
		// Only refetch on mount if we didn't get the SSR seed. The SSR data
		// is built from the same api_get_email_settings() shape, so it's
		// authoritative for first paint.
		if ( ! initial?.newspack_emails ) {
			fetchData();
		}
		// Empty cleanup return; the hook's promise resolves to nothing
		// observable post-unmount, but explicit cleanup signals intent.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const updateStatus = ( postId: number, nextStatus: string ) => {
		resetError();
		// Optimistic update — the server response only confirms what we
		// already wrote, so patch the row in place and skip the full
		// refetch (which would otherwise re-pay the N+1 query in
		// Emails::get_emails on every toggle).
		const prev = data;
		setData( data.map( email => ( email.post_id === postId ? { ...email, status: nextStatus } : email ) ) );
		wizardApiFetch(
			{
				path: `/wp/v2/${ postType }/${ postId }`,
				method: 'POST',
				data: { status: nextStatus },
			},
			{
				onError() {
					// Roll back optimistic update on failure.
					setData( prev );
				},
			}
		);
	};

	const resetEmail = ( postId: number ) => {
		resetError();
		// @todo NPPD-1532 Move reset handler to class-emails-section.php so it
		// lives under wizard/newspack-settings/emails/{id} instead of reaching
		// into the donations wizard namespace.
		wizardApiFetch(
			{
				path: `/newspack/v1/wizard/newspack-audience-donations/emails/${ postId }`,
				method: 'DELETE',
			},
			{
				onSuccess: () => fetchData(),
			}
		);
	};

	const fields: Field< EmailItem >[] = useMemo(
		() => [
			{
				id: 'name',
				label: __( 'Email', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item }: { item: EmailItem } ) => item.label,
				render: ( { item }: { item: EmailItem } ) => (
					<a href={ item.edit_link } className="newspack-emails__name-link">
						<strong>{ item.label }</strong>
					</a>
				),
			},
			{
				id: 'trigger_description',
				label: __( 'Description', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item }: { item: EmailItem } ) => item.trigger_description,
				render: ( { item }: { item: EmailItem } ) => (
					<span className="newspack-emails__trigger-description">{ item.trigger_description }</span>
				),
				enableHiding: false,
				enableSorting: false,
			},
			{
				id: 'recipient',
				label: __( 'Recipient', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item }: { item: EmailItem } ) =>
					item.recipient === 'admin' ? __( 'Admin', 'newspack-plugin' ) : __( 'Reader', 'newspack-plugin' ),
				render: ( { item }: { item: EmailItem } ) => (
					<span>{ item.recipient === 'admin' ? __( 'Admin', 'newspack-plugin' ) : __( 'Reader', 'newspack-plugin' ) }</span>
				),
			},
			{
				id: 'status',
				label: __( 'Status', 'newspack-plugin' ),
				getValue: ( { item }: { item: EmailItem } ) => item.status,
				render: ( { item }: { item: EmailItem } ) => {
					const isEnabled = item.status === 'publish';
					return (
						<Badge
							level={ isEnabled ? 'success' : 'default' }
							text={ isEnabled ? __( 'Enabled', 'newspack-plugin' ) : __( 'Disabled', 'newspack-plugin' ) }
						/>
					);
				},
				elements: [
					{ value: 'publish', label: __( 'Enabled', 'newspack-plugin' ) },
					{ value: 'draft', label: __( 'Disabled', 'newspack-plugin' ) },
				],
				filterBy: { isPrimary: false, operators: [ 'is' ] },
			},
		],
		[]
	);

	const actions: Action< EmailItem >[] = [
		{
			id: 'edit',
			label: __( 'Edit', 'newspack-plugin' ),
			isPrimary: true,
			callback: ( items: EmailItem[] ) => {
				window.location.href = items[ 0 ].edit_link;
			},
		},
		{
			id: 'deactivate',
			label: __( 'Deactivate', 'newspack-plugin' ),
			isEligible: ( item: EmailItem ) => item.category !== 'reader-activation' && item.status === 'publish',
			callback: ( items: EmailItem[] ) => {
				updateStatus( items[ 0 ].post_id, 'draft' );
			},
		},
		{
			id: 'activate',
			label: __( 'Activate', 'newspack-plugin' ),
			isEligible: ( item: EmailItem ) => item.category !== 'reader-activation' && item.status !== 'publish',
			callback: ( items: EmailItem[] ) => {
				updateStatus( items[ 0 ].post_id, 'publish' );
			},
		},
		{
			id: 'reset',
			label: __( 'Reset', 'newspack-plugin' ),
			isDestructive: true,
			isEligible: ( item: EmailItem ) => item.source === 'newspack',
			callback: ( items: EmailItem[] ) => {
				if ( utils.confirmAction( __( 'Are you sure you want to reset the contents of this email?', 'newspack-plugin' ) ) ) {
					resetEmail( items[ 0 ].post_id );
				}
			},
		},
	];

	const { data: processedData, paginationInfo } = useMemo( () => filterSortAndPaginate( data, view, fields ), [ data, view, fields ] );

	if ( false === pluginsReady ) {
		return (
			<Fragment>
				<PageHeading />
				<Notice
					isError
					noticeText={ __(
						'Newspack uses Newspack Newsletters to handle editing email-type content. Please activate this plugin to proceed. Until this feature is configured, default receipts will be used.',
						'newspack-plugin'
					) }
				/>
				<WizardsPluginCard
					slug="newspack-newsletters"
					title={ __( 'Newspack Newsletters', 'newspack-plugin' ) }
					description={ __( 'Newspack Newsletters is the plugin that powers Newspack email receipts.', 'newspack-plugin' ) }
					onStatusChange={ ( statuses: Record< string, boolean > ) => {
						if ( ! statuses.isLoading ) {
							setPluginsReady( statuses.isSetup );
						}
					} }
				/>
			</Fragment>
		);
	}

	return (
		<Fragment>
			<PageHeading />
			{ errorMessage && <Notice isError noticeText={ errorMessage } /> }
			<DataViews
				className="newspack-emails"
				data={ processedData }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				defaultLayouts={ { table: {}, grid: {} } }
				isLoading={ isFetching }
				getItemId={ ( item: EmailItem ) => String( item.post_id ) }
				search
			/>
		</Fragment>
	);
};

export default Emails;
