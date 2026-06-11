<?php
/**
 * Subscription Pin — owns the pinned-rule snapshot on a subscription line item.
 *
 * A pin is the materialized DEAL a reader acquired under (docs
 * 03-rule-pinning-design): a self-contained copy of the winning locked-class
 * rule's config, stored as hidden line item meta. Renewals resolve from it;
 * it survives rule edits and deletion.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

final class Subscription_Pin {
	const LOCKED_RULE_META_KEY = '_newspack_dp_locked_rule';

	/**
	 * Read a valid rule snapshot off a line item, or null.
	 *
	 * @param \WC_Order_Item_Product $line Recurring line item.
	 */
	public static function snapshot( $line ): ?array {
		$snapshot = $line->get_meta( self::LOCKED_RULE_META_KEY );
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
	 * Pin a rule's current config onto a line item (write or replace).
	 *
	 * @param \WC_Order_Item_Product $line   Recurring line item.
	 * @param Pricing_Rule                  Pricing_Rule whose config to snapshot.
	 */
	public static function pin( $line, Pricing_Rule $rule ): void {
		$line->update_meta_data( self::LOCKED_RULE_META_KEY, $rule->to_snapshot() );
		$line->save();
	}

	/**
	 * Remove a pin from a line item.
	 *
	 * @param \WC_Order_Item_Product $line Recurring line item.
	 */
	public static function unpin( $line ): void {
		$line->delete_meta_data( self::LOCKED_RULE_META_KEY );
		$line->save();
	}
}
