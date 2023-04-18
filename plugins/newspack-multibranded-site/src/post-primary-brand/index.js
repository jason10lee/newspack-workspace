/* global newspackPostPrimaryBrandVars */

import { __, _n, _x } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Flex, FlexItem, SelectControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

import './index.scss';

/**
 * Module Constants
 */
const DEFAULT_QUERY = {
	per_page: -1,
	orderby: 'name',
	order: 'asc',
	_fields: 'id,name,parent',
	context: 'view',
};

const EMPTY_ARRAY = [];

const ADMIN_URL = newspackPostPrimaryBrandVars.adminURL;

const TAXONOMY_SLUG = newspackPostPrimaryBrandVars.taxonomySlug;

const META_KEY = newspackPostPrimaryBrandVars.metaKey;

/**
 * Adds a primary brand selector to the post editor.
 */
const NewspackPostPrimaryBrand = ( { slug } ) => {
	const { editPost } = useDispatch( 'core/editor' );

	const [ currentPrimaryBrand, setCurrentPrimaryBrand ] = useState( null );

	const { terms, availableTerms, taxonomy } = useSelect(
		select => {
			const { getCurrentPost, getEditedPostAttribute } = select( 'core/editor' );
			const { getTaxonomy, getEntityRecords } = select( coreStore );
			const _taxonomy = getTaxonomy( slug );
			const post = getCurrentPost();

			setCurrentPrimaryBrand( post.meta[ META_KEY ] );

			return {
				terms: _taxonomy ? getEditedPostAttribute( _taxonomy.rest_base ) : EMPTY_ARRAY,
				availableTerms: getEntityRecords( 'taxonomy', slug, DEFAULT_QUERY ) || EMPTY_ARRAY,
				taxonomy: _taxonomy,
			};
		},
		[ slug ]
	);

	const getTermSelectOptionFromId = id => {
		const term = availableTerms.find( term => term.id === id );
		return term ? { value: term.id, label: term.name } : null;
	};

	const onChangePrimaryBrand = termId => {
		editPost( { meta: { [ META_KEY ]: termId } } );
		setCurrentPrimaryBrand( termId );
	};

	return (
		<Flex direction="column" gap="4">
			<div
				className="editor-post-taxonomies__hierarchical-terms-list"
				tabIndex="0"
				role="group"
				aria-label={ __( 'Brands', 'newspack-multibranded-site' ) }
			>
				{ terms.length > 1 && (
					<SelectControl
						label={ __( 'Primary brand', 'newspack-multibranded-site' ) }
						value={ currentPrimaryBrand || 0 }
						options={ [
							{
								label: __( 'None', 'newspack-multibranded-site' ),
								value: 0,
							},
							...terms.map( term => getTermSelectOptionFromId( term ) ).filter( term => term ),
						] }
						onChange={ onChangePrimaryBrand }
					/>
				) }
			</div>

			<FlexItem>
				<Button href={ ADMIN_URL } variant="link" target="blank">
					{ __( 'Manage Brands', 'newspack-multibranded-site' ) }
				</Button>
			</FlexItem>
		</Flex>
	);
};

function customizeSelector( OriginalComponent ) {
	return function ( props ) {
		if ( props.slug === TAXONOMY_SLUG ) {
			return (
				<div className="newspack-multibranded-site-brand-control">
					<OriginalComponent { ...props } />
					<NewspackPostPrimaryBrand { ...props } />
				</div>
			);
		}
		return <OriginalComponent { ...props } />;
wp.hooks.addFilter(
	'editor.PostTaxonomyType',
	'newspack/multibranded-site/brand-selector-filter',
	customizeSelector
);
