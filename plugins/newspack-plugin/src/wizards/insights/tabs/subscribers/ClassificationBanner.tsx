/**
 * ClassificationBanner (NPPD-1616).
 *
 * Surfaces the backend + donation classification metadata so publishers
 * can verify Insights is reading their data correctly. Renders at the
 * top of the Subscribers tab.
 *
 * Shows:
 *   - Which order storage backend is in use (HPOS vs legacy CPT).
 *   - How many distinct products were classified as donations and
 *     excluded from non-donation subscription metrics.
 *   - A muted warning when no donation family is configured (Tab 6
 *     filters become identity ops, which may surprise sites that DO
 *     have donations but have not yet configured the canonical Newspack
 *     donation product).
 */

/**
 * WordPress dependencies
 */
import { __, sprintf, _n } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { SubscribersClassification } from '../../api/subscribers';

export interface ClassificationBannerProps {
	classification: SubscribersClassification;
}

const ClassificationBanner = ( { classification }: ClassificationBannerProps ) => {
	const { backend, donation_product_count, has_donation_family } = classification;

	const backendLabel = backend === 'hpos'
		? __( 'WooCommerce HPOS', 'newspack-plugin' )
		: __( 'WooCommerce legacy CPT', 'newspack-plugin' );

	const donationLabel = has_donation_family
		? sprintf(
			/* translators: %d: number of donation products excluded from Subscribers metrics */
			_n(
				'%d donation product excluded from Subscribers metrics',
				'%d donation products excluded from Subscribers metrics',
				donation_product_count,
				'newspack-plugin'
			),
			donation_product_count
		)
		: __( 'No donation products configured — donation/subscription separation is a no-op.', 'newspack-plugin' );

	return (
		<aside
			className="newspack-insights__classification-banner"
			role="note"
			aria-label={ __( 'Subscribers data classification', 'newspack-plugin' ) }
		>
			<div className="newspack-insights__classification-banner-row">
				<strong className="newspack-insights__classification-banner-label">
					{ __( 'Order storage:', 'newspack-plugin' ) }
				</strong>
				<span className="newspack-insights__classification-banner-value">
					{ backendLabel }
				</span>
			</div>
			<div className="newspack-insights__classification-banner-row">
				<strong className="newspack-insights__classification-banner-label">
					{ __( 'Donation classification:', 'newspack-plugin' ) }
				</strong>
				<span className="newspack-insights__classification-banner-value">
					{ donationLabel }
				</span>
			</div>
		</aside>
	);
};

export default ClassificationBanner;
