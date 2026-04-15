/**
 * WordPress dependencies
 */
import { withSelect } from '@wordpress/data';

const SUBTITLE_ID = 'newspack-post-subtitle-element';
export const META_FIELD_NAME = 'newspack_post_subtitle';

/**
 * Appends subtitle to DOM, below the Title in the Editor.
 *
 * @param {string} subtitle Subtitle text
 */
export const appendSubtitleToTitleDOMElement = subtitle => {
	// In WordPress 7.0+ the editor is always iframed; use the canvas document.
	// TODO: Remove `document` fallback once WordPress 7.0 is released and the non-iframed editor is no longer supported.
	const editorCanvas = document.querySelector( 'iframe[name="editor-canvas"]' );
	const doc = ( editorCanvas && editorCanvas.contentDocument ) || document;
	const titleEl = doc.querySelector( '.edit-post-visual-editor__post-title-wrapper' );

	if ( titleEl && typeof subtitle === 'string' ) {
		let subtitleEl = doc.getElementById( SUBTITLE_ID );
		const titleParent = titleEl.parentNode;
		if ( ! subtitleEl ) {
			subtitleEl = doc.createElement( 'div' );
			subtitleEl.id = SUBTITLE_ID;
			titleParent.insertBefore( subtitleEl, titleEl.nextSibling );
		}
		subtitleEl.innerHTML = subtitle;
	}
};

export const connectWithSelect = withSelect( select => ( {
	subtitle: select( 'core/editor' ).getEditedPostAttribute( 'meta' )[ META_FIELD_NAME ],
} ) );
