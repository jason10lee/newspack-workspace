/**
 * RefreshMenu — the "Insights options" header kebab dropdown. Holds
 * "Refresh now" and "Download PDF" (NPPD-1661).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { DropdownMenu } from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';

export interface RefreshMenuProps {
	onRefresh: () => void;
	disabled: boolean;
	/** Trigger the per-tab PDF export (browser print). */
	onDownloadPdf: () => void;
	/** Disable the export while the tab is still loading its data. */
	downloadDisabled: boolean;
}

const RefreshMenu = ( { onRefresh, disabled, onDownloadPdf, downloadDisabled }: RefreshMenuProps ) => (
	<DropdownMenu
		icon={ moreVertical }
		label={ __( 'Insights options', 'newspack-plugin' ) }
		className="newspack-insights__refresh-menu"
		controls={ [
			{
				title: __( 'Refresh now', 'newspack-plugin' ),
				onClick: onRefresh,
				isDisabled: disabled,
			},
			{
				title: __( 'Download PDF', 'newspack-plugin' ),
				onClick: onDownloadPdf,
				isDisabled: downloadDisabled,
			},
		] }
	/>
);

export default RefreshMenu;
