/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { comment as icon } from '@wordpress/icons';
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import colors from '../../../packages/colors/colors.module.scss';
import './style.scss';

export const title = __( 'Comments Panel', 'newspack-plugin' );

const { name } = metadata;

export { metadata, name };

export const settings = {
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	keywords: [
		__( 'comments', 'newspack-plugin' ),
		__( 'panel', 'newspack-plugin' ),
		__( 'overlay', 'newspack-plugin' ),
		__( 'drawer', 'newspack-plugin' ),
		__( 'discussion', 'newspack-plugin' ),
	],
	description: __( "A button that opens a panel containing the post's comments.", 'newspack-plugin' ),
	edit: Edit,
	save: () => (
		<div { ...useBlockProps.save() }>
			<InnerBlocks.Content />
		</div>
	),
};
