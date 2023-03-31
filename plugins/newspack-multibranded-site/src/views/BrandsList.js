import { NavLink } from 'react-router-dom';

import { Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Card, ActionCard, Button, withWizardScreen } from 'newspack-components';

const AddNewBrandLink = () => (
	<NavLink to="brands/new">
		<Button variant="primary">{ __( 'Add New Brand', 'newspack' ) }</Button>
	</NavLink>
);

const BrandActionCard = ( { brand } ) => {
	return <ActionCard isSmall title={ brand.name } />;
};

const BrandsList = ( { brands } ) => {
	return brands.length ? (
		<Fragment>
			<Card headerActions noBorder>
				<h2>{ __( 'Site brands', 'newspack' ) }</h2>
				<AddNewBrandLink />
			</Card>
			{ brands.map( brand => (
				<BrandActionCard key={ brand.id } brand={ brand } />
			) ) }
		</Fragment>
	) : (
		<Fragment>
			<Card headerActions noBorder>
				<h2>{ __( 'You have no saved brands.', 'newspack' ) }</h2>
				<AddNewBrandLink />
			</Card>
			<p>{ __( 'Create brands to enhance your readers experience.', 'newspack' ) }</p>
		</Fragment>
	);
};

export default withWizardScreen( BrandsList );
