/* global newspackPostPrimaryBrand */

import { __, _n, _x } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, CheckboxControl, Flex, FlexItem, SelectControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { decodeEntities } from '@wordpress/html-entities';

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

const MIN_TERMS_COUNT_FOR_FILTER = 8;

const EMPTY_ARRAY = [];

const ADMIN_URL = newspackPostPrimaryBrand.adminUrl;

const TAXONOMY_SLUG = newspackPostPrimaryBrand.taxonomySlug;

const HAS_YOAST = newspackPostPrimaryBrand.hasYoast;

const META_KEY = newspackPostPrimaryBrand.metaKey;

const NewspackPostPrimaryBrand = props => {
	const slug = props.slug;

	const { editPost } = useDispatch( 'core/editor' );

	const [ currentPrimaryBrand, setCurrentPrimaryBrand ] = useState( null );

	const { terms, loading, post, availableTerms, taxonomy } = useSelect(
		select => {
			const { getCurrentPost, getEditedPostAttribute } = select( 'core/editor' );
			const { getTaxonomy, getEntityRecords, isResolving } = select( coreStore );
			const _taxonomy = getTaxonomy( slug );
			const post = getCurrentPost();

			console.log( 'selecting' );
			setCurrentPrimaryBrand( post.meta[ META_KEY ] );

			return {
				terms: _taxonomy ? getEditedPostAttribute( _taxonomy.rest_base ) : EMPTY_ARRAY,
				loading: isResolving( 'getEntityRecords', [ 'taxonomy', slug, DEFAULT_QUERY ] ),
				availableTerms: getEntityRecords( 'taxonomy', slug, DEFAULT_QUERY ) || EMPTY_ARRAY,
				taxonomy: _taxonomy,
				post,
			};
		},
		[ slug ]
	);

	const getTermSelectOptionFromId = id => {
		const term = availableTerms.find( term => term.id === id );
		return term ? { value: term.id, label: term.name } : null;
	};

	/**
	 * Update terms for post.
	 *
	 * @param {number[]} termIds Term ids.
	 */
	const onUpdateTerms = termIds => {
		editPost( { [ taxonomy.rest_base ]: termIds } );
	};

	/**
	 * Handler for checking term.
	 *
	 * @param {number} termId
	 */
	const onChange = termId => {
		const hasTerm = terms.includes( termId );
		const newTerms = hasTerm ? terms.filter( id => id !== termId ) : [ ...terms, termId ];
		onUpdateTerms( newTerms );
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
				{ availableTerms.map( term => {
					return (
						<div key={ term.id } className="editor-post-taxonomies__hierarchical-terms-choice">
							<CheckboxControl
								__nextHasNoMarginBottom
								checked={ terms.indexOf( term.id ) !== -1 }
								onChange={ () => {
									const termId = parseInt( term.id, 10 );
									onChange( termId );
								} }
								label={ decodeEntities( term.name ) }
							/>
						</div>
					);
				} ) }

				{ terms.length > 1 && (
					<SelectControl
						label={ __( 'Primary brand (for multi-branded site)', 'newspack-multibranded-site' ) }
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
				<Button
					href={ ADMIN_URL }
					className="editor-post-taxonomies__hierarchical-terms-add"
					variant="link"
					target="blank"
				>
					{ __( 'Manage Brands', 'newspack-multibranded-site' ) }
				</Button>
			</FlexItem>

			{ HAS_YOAST && (
				<FlexItem>
					<b>{ __( 'Yoast Options:', 'newspack-multibranded-site' ) }</b>
				</FlexItem>
			) }
		</Flex>
	);
};

function customizeSelector( OriginalComponent ) {
	return function ( props ) {
		if ( props.slug === TAXONOMY_SLUG ) {
			return <NewspackPostPrimaryBrand { ...props } />;
		} else {
			return <OriginalComponent { ...props } />;
		}
	};
}
wp.hooks.addFilter(
	'editor.PostTaxonomyType',
	'newspack/set-custom-term-selector',
	customizeSelector
);
