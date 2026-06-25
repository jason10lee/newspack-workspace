/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useMergeRefs, useRefEffect } from '@wordpress/compose';
import { BlockPreview } from '@wordpress/block-editor';
import { Placeholder, Spinner } from '@wordpress/components';
import { forwardRef } from '@wordpress/element';
import { pages } from '@wordpress/icons';
import { useCustomFontsInIframe } from '../../../newsletter-editor/styling';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Post-item typography and spacing for the preview, matching the values the WC
 * email renderer produces: title 24px / subtitle 16px / body 16px, an 8px gap
 * between the title, subtitle, date, excerpt and continue-reading link. The
 * preview is an iframed `BlockPreview`, so this can't live in the block's editor
 * stylesheet (the outer document) — it's injected straight into the iframe. The
 * inserted blocks carry authored inline font sizes (e.g. the date's 20px) that
 * the email normalises away, so `!important` is needed to beat them.
 */
const POST_ITEM_PREVIEW_STYLES = `
	h3.wp-block-heading {
		font-size: 24px !important;
		line-height: 1.2 !important;
		font-weight: 700 !important;
	}
	h4.wp-block-heading {
		font-size: 16px !important;
		line-height: 1.2 !important;
		font-weight: 700 !important;
	}
	.wp-block-column p {
		font-size: 16px !important;
		line-height: 1.5 !important;
		font-weight: 400 !important;
	}
	.wp-block-column > * {
		margin-top: 0 !important;
		margin-bottom: 0 !important;
	}
	.wp-block-column > * + * {
		margin-top: 8px !important;
	}
`;

/**
 * Posts Preview component.
 */
const PostsPreview = ( { isReady, blocks, className, viewportWidth }, ref ) => {
	// Iframe styles are not properly applied when nesting iframed editors.
	// This fix ensures the iframe is properly styled.
	const useIframeBorderFix = useRefEffect( node => {
		const observerCallback = () => {
			const iframe = node.querySelector( 'iframe[title="Editor canvas"]' );
			if ( iframe ) {
				const updateIframeStyle = () => {
					iframe.style.border = 0;
					observer.disconnect();
				};
				updateIframeStyle();
				iframe.addEventListener( 'load', updateIframeStyle );
			}
		};
		const observer = new MutationObserver( observerCallback );
		observer.observe( node, { childList: true } );
		return () => {
			observer.disconnect();
		};
	}, [] );

	// Append layout style if viewing layout preview.
	const useLayoutStyle = useRefEffect( node => {
		const style = document.getElementById( 'newspack-newsletters__layout-css' );
		if ( ! style ) {
			return;
		}
		const clonedStyle = style.cloneNode( true );
		const observerCallback = () => {
			const iframe = node.querySelector( 'iframe[title="Editor canvas"]' );
			if ( iframe ) {
				const doc = iframe.contentDocument;
				const appendStyle = () => {
					doc.body.id = style.dataset.previewid;
					if ( ! doc.contains( clonedStyle ) ) {
						doc.head.appendChild( clonedStyle );
					}
					observer.disconnect();
				};
				appendStyle();
				iframe.addEventListener( 'load', appendStyle );
			}
		};
		const observer = new MutationObserver( observerCallback );
		observer.observe( node, { childList: true } );
		return () => {
			observer.disconnect();
		};
	}, [] );

	// Inject the email-matching post-item typography into the preview iframe.
	const usePostItemStyles = useRefEffect( node => {
		const applyStyle = () => {
			const iframe = node.querySelector( 'iframe[title="Editor canvas"]' );
			if ( ! iframe ) {
				return;
			}
			const injectStyle = () => {
				const doc = iframe.contentDocument;
				if ( ! doc || doc.getElementById( 'newspack-posts-inserter-preview-typography' ) ) {
					return;
				}
				const styleEl = doc.createElement( 'style' );
				styleEl.id = 'newspack-posts-inserter-preview-typography';
				styleEl.textContent = POST_ITEM_PREVIEW_STYLES;
				doc.head.appendChild( styleEl );
			};
			injectStyle();
			iframe.addEventListener( 'load', injectStyle );
		};
		const observer = new MutationObserver( applyStyle );
		observer.observe( node, { childList: true } );
		applyStyle();
		return () => {
			observer.disconnect();
		};
	}, [] );

	return (
		<div
			className={ classnames( 'newspack-posts-inserter__preview', className ) }
			ref={ useMergeRefs( [ ref, useIframeBorderFix, useLayoutStyle, usePostItemStyles, useCustomFontsInIframe() ] ) }
		>
			{ ! isReady && <Spinner /> }
			{ isReady && blocks?.length > 0 && <BlockPreview blocks={ blocks } viewportWidth={ viewportWidth } /> }
			{ isReady && ! blocks?.length && (
				<Placeholder
					icon={ pages }
					label={ __( 'No posts found', 'newspack-newsletters' ) }
					instructions={ __( 'Verify filter settings.', 'newspack-newsletters' ) }
				/>
			) }
		</div>
	);
};

export default forwardRef( PostsPreview );
