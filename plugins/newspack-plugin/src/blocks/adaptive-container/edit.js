/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { desktop, mobile } from '@wordpress/icons';
import { BlockControls, InnerBlocks, useBlockProps } from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton } from '@wordpress/components';

const ALLOWED_BLOCKS = [ 'newspack/adaptive-container-slot' ];
const BLOCKS_TEMPLATE = [
	[ 'newspack/adaptive-container-slot', { view: 'desktop' } ],
	[ 'newspack/adaptive-container-slot', { view: 'mobile' } ],
];

/**
 * Edit component for the Adaptive Container block.
 *
 * Renders a locked template of exactly two slots (desktop + mobile) and a
 * toolbar toggle that sets `editorView`. The active view is shared with the
 * slots via block context; the inactive slot hides itself in the editor.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 *
 * @return {JSX.Element} The block editor UI.
 */
export default function AdaptiveContainerEdit( { attributes, setAttributes } ) {
	const { editorView } = attributes;

	const blockProps = useBlockProps( {
		className: `newspack-adaptive-container is-editing-${ editorView }`,
	} );

	return (
		<>
			<BlockControls>
				<ToolbarGroup>
					<ToolbarButton
						icon={ desktop }
						label={ __( 'Edit desktop view', 'newspack-plugin' ) }
						isActive={ editorView === 'desktop' }
						onClick={ () => setAttributes( { editorView: 'desktop' } ) }
					/>
					<ToolbarButton
						icon={ mobile }
						label={ __( 'Edit mobile view', 'newspack-plugin' ) }
						isActive={ editorView === 'mobile' }
						onClick={ () => setAttributes( { editorView: 'mobile' } ) }
					/>
				</ToolbarGroup>
			</BlockControls>
			<div { ...blockProps }>
				<InnerBlocks template={ BLOCKS_TEMPLATE } templateLock="all" allowedBlocks={ ALLOWED_BLOCKS } />
			</div>
		</>
	);
}
