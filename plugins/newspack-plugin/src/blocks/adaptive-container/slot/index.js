/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { mobile as icon } from '@wordpress/icons';
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import colors from '../../../../packages/colors/colors.module.scss';

export const title = __( 'Adaptive Slot', 'newspack-plugin' );

const { name } = metadata;

export { metadata, name };

export const settings = {
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	edit: Edit,
	save: ( { attributes } ) => {
		const blockProps = useBlockProps.save( {
			className: `newspack-adaptive-container-slot--${ attributes.view }`,
		} );
		return (
			<div { ...blockProps }>
				<InnerBlocks.Content />
			</div>
		);
	},
};
