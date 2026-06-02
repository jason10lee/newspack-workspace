/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';

/**
 * Edit component for an Adaptive Container slot.
 *
 * Reads the parent's `editorView` from block context and hides itself in the
 * editor when its own `view` is not the active one. The content stays in the
 * DOM so it is preserved and becomes editable as soon as the parent toggles
 * to this view.
 *
 * @param {Object} props            Block props.
 * @param {Object} props.attributes Block attributes.
 * @param {Object} props.context    Inherited block context.
 *
 * @return {JSX.Element} The block editor UI.
 */
export default function AdaptiveContainerSlotEdit( { attributes, context } ) {
	const { view } = attributes;
	const editorView = context[ 'newspack-adaptive-container/editorView' ];
	const isActive = editorView === view;

	const className = 'newspack-adaptive-container-slot' + ` newspack-adaptive-container-slot--${ view }` + ( isActive ? '' : ' is-inactive-view' );

	const blockProps = useBlockProps( { className } );

	const label = view === 'desktop' ? __( 'Desktop', 'newspack-plugin' ) : __( 'Mobile', 'newspack-plugin' );

	return (
		<div { ...blockProps }>
			<span className="newspack-adaptive-container-slot__label">{ label }</span>
			<InnerBlocks templateLock={ false } />
		</div>
	);
}
