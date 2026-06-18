<?php
/**
 * Tests for Newspack\Insights\Source_Matcher.
 *
 * @package Newspack
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Source_Matcher;
use WP_UnitTestCase;

/**
 * Tests for Source_Matcher.
 *
 * @group insights
 */
class Test_Source_Matcher extends WP_UnitTestCase {

	/** Attribution is deterministic regardless of caller / BigQuery row order. */
	public function test_matching_is_order_independent() {
		$records  = [
			[
				'key' => 'a',
				'ts'  => 1000,
			],
			[
				'key' => 'b',
				'ts'  => 2000,
			],
		];
		$sorted   = [
			[
				'ts'     => 1005,
				'source' => 'gate',
			],
			[
				'ts'     => 2005,
				'source' => 'prompt',
			],
		];
		$shuffled = [
			[
				'ts'     => 2005,
				'source' => 'prompt',
			],
			[
				'ts'     => 1005,
				'source' => 'gate',
			],
		];
		$expected = [
			'a' => 'gate',
			'b' => 'prompt',
		];
		$this->assertSame( $expected, Source_Matcher::attach_sources( $records, $sorted, 120, 120 ) );
		$this->assertSame( $expected, Source_Matcher::attach_sources( $records, $shuffled, 120, 120 ) );
	}

	/** A record at ts matches an event within the symmetric window and takes its source. */
	public function test_attaches_source_within_symmetric_window() {
		$records = [
			[
				'key' => 11,
				'ts'  => 1000,
			],
		];
		$events  = [
			[
				'ts'     => 1050,
				'source' => 'gate',
			],
		]; // +50s, inside ±120s.
		$result  = Source_Matcher::attach_sources( $records, $events, 120, 120 );
		$this->assertSame( [ 11 => 'gate' ], $result );
	}

	/** An event outside the window leaves the record 'direct'. */
	public function test_unmatched_record_is_direct() {
		$records = [
			[
				'key' => 11,
				'ts'  => 1000,
			],
		];
		$events  = [
			[
				'ts'     => 5000,
				'source' => 'prompt',
			],
		]; // far outside.
		$result  = Source_Matcher::attach_sources( $records, $events, 120, 120 );
		$this->assertSame( [ 11 => Source_Matcher::SOURCE_DIRECT ], $result );
	}

	/** Asymmetric (order) window: event must precede the record (attempt before order). */
	public function test_order_window_requires_event_before_record() {
		$records = [
			[
				'key' => 7,
				'ts'  => 2000,
			],
		];
		$before  = [
			[
				'ts'     => 1500,
				'source' => 'gate',
			],
		];   // 500s before, inside [ts-1800, ts].
		$after   = [
			[
				'ts'     => 2500,
				'source' => 'gate',
			],
		];   // 500s after, OUTSIDE (after=0).
		$this->assertSame( [ 7 => 'gate' ], Source_Matcher::attach_sources( $records, $before, 1800, 0 ) );
		$this->assertSame( [ 7 => Source_Matcher::SOURCE_DIRECT ], Source_Matcher::attach_sources( $records, $after, 1800, 0 ) );
	}

	/** Each event is consumed once: two records, one event → only the nearest matches. */
	public function test_event_consumed_once_nearest_wins() {
		$records = [
			[
				'key' => 'a',
				'ts'  => 1000,
			],
			[
				'key' => 'b',
				'ts'  => 1010,
			],
		];
		$events  = [
			[
				'ts'     => 1012,
				'source' => 'prompt',
			],
		]; // nearest to 'b'.
		$result  = Source_Matcher::attach_sources( $records, $events, 120, 120 );
		$this->assertSame( 'prompt', $result['b'] );
		$this->assertSame( Source_Matcher::SOURCE_DIRECT, $result['a'] );
	}

	/** Counts by source, zero-filling all three buckets. */
	public function test_count_by_source_zero_fills() {
		$map = [
			1 => 'gate',
			2 => 'gate',
			3 => 'direct',
		];
		$this->assertSame(
			[
				'gate'   => 2,
				'prompt' => 0,
				'direct' => 1,
			],
			Source_Matcher::count_by_source( $map )
		);
	}

	/** Empty inputs are safe. */
	public function test_empty_inputs() {
		$this->assertSame( [], Source_Matcher::attach_sources( [], [], 120, 120 ) );
		$this->assertSame(
			[
				'gate'   => 0,
				'prompt' => 0,
				'direct' => 0,
			],
			Source_Matcher::count_by_source( [] )
		);
	}
}
