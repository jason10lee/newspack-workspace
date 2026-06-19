/**
 * PrintDocumentMeta (NPPD-1661).
 *
 * Print-only document chrome for the per-tab PDF export. Hidden on screen
 * (`.newspack-insights__print-only { display: none }`) and revealed under
 * `@media print`, so it costs nothing in the normal admin view and only
 * surfaces when the user runs "Print / Save as PDF" from the Insights options
 * kebab.
 *
 * Renders:
 *   - a document header (publisher name + tab title + the active date
 *     range, plus the comparison period when compare mode is on), printed
 *     once at the top of the first page; and
 *   - a footer with the generation date, fixed to the bottom of every
 *     printed page via the print stylesheet.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { dateI18n } from '@wordpress/date';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';

export interface PrintDocumentMetaProps {
	/** Human tab title, e.g. "Audience" (the printed document's heading). */
	tabLabel: string;
	/** Site title from the boot config; omitted from the header when empty. */
	publisherName: string;
	/** Active date range. */
	range: DateRange;
	/** Previous-period range when compare mode is on, else null. */
	previousRange: DateRange | null;
}

/** Format a range as "May 20 – Jun 18, 2026" for the document header. */
const formatRangeLabel = ( range: DateRange ): string =>
	sprintf(
		/* translators: 1: range start date, 2: range end date. */
		__( '%1$s – %2$s', 'newspack-plugin' ),
		dateI18n( 'M j, Y', range.start ),
		dateI18n( 'M j, Y', range.end )
	);

const PrintDocumentMeta = ( { tabLabel, publisherName, range, previousRange }: PrintDocumentMetaProps ) => (
	<>
		<header className="newspack-insights__print-only newspack-insights__print-header">
			{ publisherName && <p className="newspack-insights__print-publisher">{ publisherName }</p> }
			<h1 className="newspack-insights__print-title">{ tabLabel }</h1>
			<p className="newspack-insights__print-range">{ formatRangeLabel( range ) }</p>
			{ previousRange && (
				<p className="newspack-insights__print-compare">
					{ sprintf(
						/* translators: %s is the previous comparison period date range. */
						__( 'Compared to %s', 'newspack-plugin' ),
						formatRangeLabel( previousRange )
					) }
				</p>
			) }
		</header>
		<footer className="newspack-insights__print-only newspack-insights__print-footer">
			{ sprintf(
				/* translators: %s is the date the PDF was generated. */
				__( 'Generated %s', 'newspack-plugin' ),
				dateI18n( 'M j, Y', new Date() )
			) }
		</footer>
	</>
);

export default PrintDocumentMeta;
