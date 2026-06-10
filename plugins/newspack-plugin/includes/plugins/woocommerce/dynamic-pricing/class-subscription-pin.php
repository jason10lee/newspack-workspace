<?php
/**
 * Subscription Pin — owns the pinned-deal snapshot on a subscription line item.
 *
 * A pin is the materialized DEAL a reader acquired under (docs
 * 03-policy-pinning-design): a self-contained copy of the winning deal-class
 * policy's config, stored as hidden line item meta. Renewals resolve from it;
 * it survives policy edits and deletion.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

final class Subscription_Pin {
	const DEAL_META_KEY = '_newspack_dp_deal';

	/**
	 * Read a valid deal snapshot off a line item, or null.
	 *
	 * @param \WC_Order_Item_Product $line Recurring line item.
	 */
	public static function snapshot( $line ): ?array {
		$snapshot = $line->get_meta( self::DEAL_META_KEY );
		if (
			is_array( $snapshot )
			&& 1 === (int) ( $snapshot['schema_version'] ?? 0 )
			&& '' !== (string) ( $snapshot['strategy_id'] ?? '' )
		) {
			return $snapshot;
		}
		return null;
	}

	/**
	 * Pin a policy's current config onto a line item (write or replace).
	 *
	 * @param \WC_Order_Item_Product $line   Recurring line item.
	 * @param Policy                 $policy Policy whose config to snapshot.
	 */
	public static function pin( $line, Policy $policy ): void {
		$line->update_meta_data( self::DEAL_META_KEY, $policy->to_snapshot() );
		$line->save();
	}

	/**
	 * Remove a pin from a line item.
	 *
	 * @param \WC_Order_Item_Product $line Recurring line item.
	 */
	public static function unpin( $line ): void {
		$line->delete_meta_data( self::DEAL_META_KEY );
		$line->save();
	}
}
