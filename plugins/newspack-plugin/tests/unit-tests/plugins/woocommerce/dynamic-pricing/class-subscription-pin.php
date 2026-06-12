<?php
/**
 * Tests for Newspack\Dynamic_Pricing\Subscription_Pin — the multi-pin
 * container with legacy single-snapshot read compatibility.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Subscription_Pin;
use Newspack\Dynamic_Pricing\Pricing_Rule;

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Subscription_Pin extends WP_UnitTestCase {
	private function mock_line(): object {
		// Duck-typed line: the pin class only touches meta accessors + save().
		return new class() {
			public array $meta = [];
			public function get_meta( $key ) {
				return $this->meta[ $key ] ?? '';
			}
			public function update_meta_data( $key, $value ) {
				$this->meta[ $key ] = $value;
			}
			public function delete_meta_data( $key ) {
				unset( $this->meta[ $key ] );
			}
			public function save() {}
		};
	}

	private function snapshot( string $rule_id ): array {
		return [
			'schema_version' => 1,
			'rule_id'        => $rule_id,
			'pinned_at'      => '2026-06-12 00:00:00',
			'title'          => "Rule {$rule_id}",
			'strategy_id'    => 'simple_price',
			'params'         => [ 'calc_type' => 'fixed_price', 'value' => 5, 'cycles_limit' => 0, 'label' => '' ],
			'priority'       => 100,
			'compose_mode'   => 'min',
			'publicize'      => false,
		];
	}

	public function test_legacy_single_snapshot_reads_as_one_entry_list() {
		$line = $this->mock_line();
		$line->meta[ Subscription_Pin::LOCKED_RULE_META_KEY ] = $this->snapshot( '18' ); // Pre-multi-pin shape.

		$set = Subscription_Pin::snapshots( $line );
		$this->assertCount( 1, $set );
		$this->assertSame( '18', $set[0]['rule_id'] );
	}

	public function test_pin_set_round_trips_a_list() {
		$line = $this->mock_line();
		Subscription_Pin::pin_set( $line, [ $this->snapshot( '18' ), $this->snapshot( '186' ) ] );

		$set = Subscription_Pin::snapshots( $line );
		$this->assertCount( 2, $set );
		$this->assertSame( [ '18', '186' ], array_column( $set, 'rule_id' ) );
	}

	public function test_upsert_replaces_by_rule_id_and_appends_new() {
		$line = $this->mock_line();
		Subscription_Pin::pin_set( $line, [ $this->snapshot( '18' ) ] );

		$rule              = Pricing_Rule::from_snapshot( $this->snapshot( '18' ) );
		$rule->params      = [ 'calc_type' => 'fixed_price', 'value' => 7, 'cycles_limit' => 0, 'label' => '' ];
		Subscription_Pin::upsert( $line, $rule );

		$set = Subscription_Pin::snapshots( $line );
		$this->assertCount( 1, $set, 'Upsert replaces in place.' );
		$this->assertSame( 7, $set[0]['params']['value'] );

		$other = Pricing_Rule::from_snapshot( $this->snapshot( '186' ) );
		Subscription_Pin::upsert( $line, $other );
		$this->assertCount( 2, Subscription_Pin::snapshots( $line ), 'Unknown rule id appends.' );
	}

	public function test_remove_drops_one_entry_and_empties_cleanly() {
		$line = $this->mock_line();
		Subscription_Pin::pin_set( $line, [ $this->snapshot( '18' ), $this->snapshot( '186' ) ] );

		$remaining = Subscription_Pin::remove( $line, '18' );
		$this->assertCount( 1, $remaining );
		$this->assertSame( '186', $remaining[0]['rule_id'] );

		$remaining = Subscription_Pin::remove( $line, '186' );
		$this->assertSame( [], $remaining );
		$this->assertArrayNotHasKey( Subscription_Pin::LOCKED_RULE_META_KEY, $line->meta, 'Last removal deletes the meta entirely.' );
	}

	public function test_invalid_entries_are_filtered_from_lists() {
		$line = $this->mock_line();
		$line->meta[ Subscription_Pin::LOCKED_RULE_META_KEY ] = [
			$this->snapshot( '18' ),
			[ 'schema_version' => 99, 'strategy_id' => 'x' ], // Unknown schema.
			'garbage',
		];
		$set = Subscription_Pin::snapshots( $line );
		$this->assertCount( 1, $set );
		$this->assertSame( '18', $set[0]['rule_id'] );
	}
}
