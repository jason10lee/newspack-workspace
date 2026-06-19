/**
 * PDF export helpers (NPPD-1661).
 *
 * The Insights "Print / Save as PDF" export is print-based: a `@media print`
 * stylesheet hides the WordPress/wizard chrome and reveals a document
 * header + footer, then we hand off to the browser's own print engine
 * via `window.print()`. The browser renders the live SVG/DOM charts at
 * full vector fidelity — no rasterization, no charting-library round-trip
 * — and paginates long tables natively. See the PR pre-flight notes for
 * why this beats html2canvas/jsPDF for our hand-rolled SVG charts.
 *
 * Because the export is `window.print()`, the suggested filename is taken
 * from `document.title` (there's no API to pass a filename to the print
 * dialog). We set the title to the desired name for the duration of the
 * print, then restore it.
 */

import type { DateRange } from '../state/useDateRange';

/**
 * Build the suggested PDF filename for a tab + date range, e.g.
 * `audience-2026-05-20_to_2026-06-18`. The browser appends `.pdf` when
 * the user picks "Save as PDF", matching the `<tab>-<date_range>` shape.
 */
export const buildPdfFilename = ( tab: string, range: DateRange ): string => `${ tab }-${ range.start }_to_${ range.end }`;

/**
 * In-flight guard. `window.print()` blocks the main thread in most
 * browsers, so a second invocation can't normally land mid-print — but
 * embedded webviews don't always block, and a re-entrant call would
 * capture the already-swapped filename as `originalTitle` and strand it.
 * Ignore invocations until the in-flight print has restored the title.
 */
let printPending = false;

/**
 * Trigger the browser print dialog with a temporary document title so the
 * suggested "Save as PDF" filename matches `name`. The title is restored
 * on the `afterprint` event, with a timeout fallback for browsers that
 * don't fire it reliably.
 */
export const printCurrentTab = ( name: string ): void => {
	if ( typeof window === 'undefined' || typeof document === 'undefined' || printPending ) {
		return;
	}
	printPending = true;

	const originalTitle = document.title;
	let restored = false;
	const restore = () => {
		if ( restored ) {
			return;
		}
		restored = true;
		printPending = false;
		document.title = originalTitle;
		window.removeEventListener( 'afterprint', restore );
	};

	window.addEventListener( 'afterprint', restore );
	document.title = name;
	window.print();
	// Safety net: Safari (and some embedded webviews) don't always emit
	// `afterprint`, which would otherwise strand the temporary title.
	window.setTimeout( restore, 1000 );
};
