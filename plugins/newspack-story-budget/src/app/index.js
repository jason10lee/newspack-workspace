/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * External dependencies.
 */
import { createRoot } from 'react-dom/client';
import {
	HashRouter,
	useLocation,
	useParams,
	Switch,
	Route,
	Redirect,
} from 'react-router-dom';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import {
	Modal,
	Button,
	SlotFillProvider,
	DropdownMenu,
	Icon,
	Snackbar,
} from '@wordpress/components';
import { plus } from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies.
 */
import AppHeader, { AppHeaderActions } from '../components/app-header';
import { TabbedNavigation } from 'newspack-components';
import Stories from '../components/stories';
import Story from '../components/story';
import Budgets from '../components/budgets';
import CreateNewStory from '../components/create-new-story';
import CreateBudgetModal from '../components/create-budget-modal';
import '../style.scss';

const ModalPage = ( { children, name, closeHref, ...props } ) => {
	const className = name
		? `newspack-story-budget__modal-page__${ name }`
		: '';
	return (
		<Modal
			onRequestClose={ () =>
				closeHref
					? ( window.location.href = closeHref )
					: window.history.back()
			}
			size="large"
			className={ `newspack-story-budget__modal-page ${ className }` }
			{ ...props }
		>
			{ children }
		</Modal>
	);
};

const StoryPage = () => {
	const { id } = useParams();
	return (
		<ModalPage name="story" size="fill" __experimentalHideHeader>
			<Story
				storyId={ id }
				onCancel={ () => ( window.location.hash = '' ) }
			/>
		</ModalPage>
	);
};

const StoryBudget = () => {
	const location = useLocation();

	const notices = useSelect( ( select ) => select( noticesStore ).getNotices( 'newspack-story-budget' ) );

	const navigationItems = [
		{ label: __( 'Stories', 'newspack-story-budget' ), path: '/stories' },
		{ label: __( 'Budgets', 'newspack-story-budget' ), path: '/budgets' },
	];

	const currentNavItem = navigationItems.find(
		item => location.pathname.indexOf( item.path ) === 0
	);

	const headerText = `${ __( 'Story Budget', 'newspack-story-budget' ) } / ${
		currentNavItem?.label
	}`;

	return (
		<SlotFillProvider>
			<div className="wrap">
				<AppHeader headerText={ headerText } />
				<TabbedNavigation items={ navigationItems } />
				<div className="newspack-story-budget__content">
					<Switch>
						<Route path="/stories">
							<AppHeaderActions>
								<DropdownMenu
									label={ __(
										'Add New Story',
										'newspack-story-budget'
									) }
									toggleProps={ {
										variant: 'primary',
										children: __(
											'Add New Story',
											'newspack-story-budget'
										),
									} }
									controls={ [
										{
											title: __(
												'Create New Story',
												'newspack-story-budget'
											),
											onClick: () =>
												( window.location.href =
													'#/stories/new' ),
										},
										{
											title: __(
												'Add Existing Story(ies)',
												'newspack-story-budget'
											),
											onClick: () =>
												( window.location.href =
													'#/stories/existing' ),
										},
									] }
									icon={ <Icon icon={ plus } /> }
									iconPosition="right"
								/>
							</AppHeaderActions>
							<Stories />
							<Switch>
								<Route path="/stories/new" exact>
									<ModalPage
										title={ __(
											'Add New Story / Create New Story',
											'newspack-story-budget'
										) }
										closeHref="#/stories"
										name={ 'create-new-story' }
									>
										<CreateNewStory
											onClose={ () =>
												( window.location.href =
													'#/stories' )
											}
										/>
									</ModalPage>
								</Route>
								<Route path="/stories/existing" exact>
									<ModalPage
										title={ __(
											'Add Existing Story(ies)',
											'newspack-story-budget'
										) }
										closeHref="#/stories"
									/>
								</Route>
								<Route path="/stories/:id">
									<StoryPage />
								</Route>
							</Switch>
						</Route>
						<Route path="/budgets">
							<AppHeaderActions>
								<Button variant="primary" href="#/budgets/new">
									{ __(
										'Add New Budget',
										'newspack-story-budget'
									) }
								</Button>
							</AppHeaderActions>
							<Budgets />
							<Switch>
								<Route path="/budgets/new">
									<ModalPage
										title={ __(
											'Add New Budget',
											'newspack-story-budget'
										) }
										closeHref="#/budgets"
										name={ 'create-budget' }
									>
										<CreateBudgetModal
											onClose={ () => ( window.location.href = "#/budgets" ) }
										/>
									</ModalPage>
								</Route>
							</Switch>
						</Route>
						<Redirect to="/stories" />
					</Switch>
				</div>
				<div className="newspack-story-budget__notices">
					{ notices.map( ( notice ) => (
						<Snackbar
							key={ notice.id }
							actions={ notice.actions }
						>
							{ notice.content }
						</Snackbar>
					) ) }
				</div>
			</div>
		</SlotFillProvider>
	);
};

createRoot( document.getElementById( 'newspack-story-budget-app' ) ).render(
	<HashRouter>
		<StoryBudget />
	</HashRouter>
);
