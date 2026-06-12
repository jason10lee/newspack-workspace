<?php
/**
 * Subscription Pin — owns the pinned-rule snapshots on a subscription line item.
 *
 * A pin is the materialized DEAL a reader acquired under (docs
 * 03-rule-pinning-design, 08-composed-deal-pinning): self-contained copies of
 * EVERY locked-class rule that matched at acquisition, stored as hidden line
 * item meta. Renewals resolve from the set — composed under the rules' own
 * modes, exactly as the checkout schedule was — and it survives rule edits
 * and deletion.
 *
 * Storage: a LIST of snapshot arrays under one meta key. Legacy single-
 * snapshot meta (pre-multi-pin) reads as a one-entry list; new writes are
 * always lists.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

final class Subscription_Pin {
	const LOCKED_RULE_META_KEY = '_newspack_dp_locked_rule';

	/**
	 * All valid rule snapshots on a line item, as a list. Accepts both the
	 * current list shape and the legacy single-snapshot shape.
	 *
	 * @param \WC_Order_Item_Product $line Recurring line item.
	 * @return array<int, array>
	 */
	public static function snapshots( $line ): array {
		$meta = $line->get_meta( self::LOCKED_RULE_META_KEY );
		if ( ! is_array( $meta ) || empty( $meta ) ) {
			return [];
		}
		// Legacy single snapshot is an assoc array carrying schema_version at
		// the top level; the list shape is numerically indexed.
		$entries = isset( $meta['schema_version'] ) ? [ $meta ] : array_values( $meta );

		$valid = [];
		foreach ( $entries as $entry ) {
			if (
				is_array( $entry )
				&& 1 === (int) ( $entry['schema_version'] ?? 0 )
				&& '' !== (string) ( $entry['strategy_id'] ?? '' )
			) {
				$valid[] = $entry;
			}
		}
		return $valid;
	}

	/**
	 * Write the full snapshot set (replaces whatever was pinned). An empty set
	 * removes the pin entirely.
	 *
	 * @param \WC_Order_Item_Product $line      Recurring line item.
	 * @param array<int, array>      $snapshots Snapshot payloads (Pricing_Rule::to_snapshot shape).
	 */
	public static function pin_set( $line, array $snapshots ): void {
		if ( empty( $snapshots ) ) {
			self::unpin_all( $line );
			return;
		}
		$line->update_meta_data( self::LOCKED_RULE_META_KEY, array_values( $snapshots ) );
		$line->save();
	}

	/**
	 * Insert or replace ONE rule's snapshot within the set, keyed by rule id —
	 * the migrate CLI's re-lock operation. Other rules' pins are untouched.
	 *
	 * @param \WC_Order_Item_Product $line Recurring line item.
	 * @param Pricing_Rule           $rule Rule whose current config to snapshot.
	 */
	public static function upsert( $line, Pricing_Rule $rule ): void {
		$set      = self::snapshots( $line );
		$replaced = false;
		foreach ( $set as $i => $entry ) {
			if ( (string) ( $entry['rule_id'] ?? '' ) === (string) $rule->id ) {
				$set[ $i ] = $rule->to_snapshot();
				$replaced  = true;
			}
		}
		if ( ! $replaced ) {
			$set[] = $rule->to_snapshot();
		}
		self::pin_set( $line, $set );
	}

	/**
	 * Remove ONE rule's snapshot from the set — the migrate CLI's detach
	 * operation. Removing the last entry removes the pin meta entirely.
	 *
	 * @param \WC_Order_Item_Product $line    Recurring line item.
	 * @param string                 $rule_id Rule id whose snapshot to remove.
	 * @return array<int, array> The remaining set.
	 */
	public static function remove( $line, string $rule_id ): array {
		$remaining = array_values(
			array_filter(
				self::snapshots( $line ),
				fn( array $entry ): bool => (string) ( $entry['rule_id'] ?? '' ) !== (string) $rule_id
			)
		);
		self::pin_set( $line, $remaining );
		return $remaining;
	}

	/**
	 * Remove every pin from a line item.
	 *
	 * @param \WC_Order_Item_Product $line Recurring line item.
	 */
	public static function unpin_all( $line ): void {
		$line->delete_meta_data( self::LOCKED_RULE_META_KEY );
		$line->save();
	}
}
