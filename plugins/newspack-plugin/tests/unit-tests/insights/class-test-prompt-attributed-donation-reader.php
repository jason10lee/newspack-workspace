<?php
/**
 * DB-backed integration tests for the NPPD-1685 prompt-attributed donation
 * reader (get_prompt_attributed_donation_conversions) on BOTH storage backends.
 *
 * The rest of the insights storage suite mocks WooCommerce and cannot reach the
 * storage SQL (see class-test-conversion-journey-storage.php → "What these tests
 * do NOT cover"). This is the DB-backed integration harness that file
 * anticipated: it stands up the real WooCommerce order tables (via
 * {@see Insights_Woo_Order_Fixtures}) so the reader SQL runs against actual rows,
 * on both legacy and HPOS.
 *
 * Anchor case = the NPPD-1685 2x bug: `_newspack_popup_id` is written more than
 * once per order, so a JOIN on that meta double-counted COUNT and SUM. The
 * duplicate-meta fixture asserts the order is counted ONCE — it FAILS against the
 * pre-fix JOIN reader and PASSES against the EXISTS + correlated-MIN reader.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Donors_Storage_Interface;
use Newspack\Insights\HPOS_Donors_Storage;
use Newspack\Insights\Legacy_Donors_Storage;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

require_once __DIR__ . '/trait-insights-woo-order-fixtures.php';

/**
 * Integration tests for the prompt-attributed donation reader.
 *
 * @group insights
 */
class Test_Prompt_Attributed_Donation_Reader extends WP_UnitTestCase {

	use Insights_Woo_Order_Fixtures;

	const DONATION_PRODUCT_ID = 999001;

	/**
	 * Stand up the WC order tables once (InnoDB → per-test inserts roll back).
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::create_woo_order_tables();
	}

	/**
	 * Drop the integration tables after the class.
	 */
	public static function tearDownAfterClass(): void {
		self::drop_woo_order_tables();
		parent::tearDownAfterClass();
	}

	/**
	 * Both storage backends. Every test runs against legacy AND HPOS.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function backends(): array {
		return [
			'legacy' => [ 'legacy' ],
			'hpos'   => [ 'hpos' ],
		];
	}

	/**
	 * The reader under test for a backend.
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @return Donors_Storage_Interface
	 */
	private function reader_for( string $backend ): Donors_Storage_Interface {
		return 'hpos' === $backend
			? new HPOS_Donors_Storage( [ self::DONATION_PRODUCT_ID ] )
			: new Legacy_Donors_Storage( [ self::DONATION_PRODUCT_ID ] );
	}

	/**
	 * Insert an order into the given backend.
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @param array  $args    Order spec (see the fixtures trait).
	 * @return void
	 */
	private function insert_order( string $backend, array $args ): void {
		if ( 'hpos' === $backend ) {
			$this->insert_hpos_order( $args );
		} else {
			$this->insert_legacy_order( $args );
		}
	}

	/**
	 * A window that brackets the fixtures' order dates.
	 *
	 * @return DateTimeImmutable[] [ start, end ]
	 */
	private function window(): array {
		$tz = new DateTimeZone( 'UTC' );
		return [ new DateTimeImmutable( '-15 days', $tz ), new DateTimeImmutable( '+1 day', $tz ) ];
	}

	/**
	 * Run the reader for the window.
	 *
	 * @param string $backend Backend key.
	 * @return array
	 */
	private function run_reader( string $backend ): array {
		[ $start, $end ] = $this->window();
		return $this->reader_for( $backend )->get_prompt_attributed_donation_conversions( $start, $end );
	}

	/**
	 * ANCHOR (NPPD-1685 2x bug): one order, TWO identical `_newspack_popup_id`
	 * rows must be counted ONCE — both conversions and revenue. Fails against the
	 * pre-fix JOIN reader.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_duplicate_popup_meta_counted_once( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 5001,
				'total'     => 100.00,
				'popup_ids' => [ '194959', '194959' ],
			]
		);

		$result = $this->run_reader( $backend );

		$this->assertEquals(
			[
				'194959' => [
					'conversions' => 1,
					'revenue'     => 100.00,
				],
			],
			$result 
		);
		$this->assertSame( 1, $result['194959']['conversions'], 'count must not double' );
		$this->assertSame( 100.00, $result['194959']['revenue'], 'revenue must not double' );
	}

	/**
	 * DEFENSIVENESS: an order carrying two DIFFERENT popup ids resolves to one
	 * defined popup id (the MIN) and is counted once — no duplication, no error.
	 * Pins the reader against a future write-side change emitting divergent ids.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_divergent_popup_meta_resolves_to_single_min( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 5002,
				'total'     => 50.00,
				'popup_ids' => [ '300', '200' ],
			]
		);

		$result = $this->run_reader( $backend );

		// MIN('300','200') === '200'; one order, counted once.
		$this->assertEquals(
			[
				'200' => [
					'conversions' => 1,
					'revenue'     => 50.00,
				],
			],
			$result 
		);
	}

	/**
	 * RENEWAL EXCLUSION: an initial donation (no `_subscription_renewal`) is
	 * counted; a renewal order (carries `_subscription_renewal`, and inherits the
	 * parent's popup meta) is excluded.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_renewal_order_excluded( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 5003,
				'total'     => 40.00,
				'popup_ids' => [ '777' ],
			]
		);
		$this->insert_order(
			$backend,
			[
				'order_id'  => 5004,
				'total'     => 25.00,
				'popup_ids' => [ '777' ],
				'renewal'   => '9001',
			]
		);

		$result = $this->run_reader( $backend );

		// Only the initial order counts; renewal excluded.
		$this->assertEquals(
			[
				'777' => [
					'conversions' => 1,
					'revenue'     => 40.00,
				],
			],
			$result 
		);
	}

	/**
	 * RECONCILIATION: a known set must produce exact per-popup count AND revenue —
	 * revenue is the dimension that hid the 2x, so it is asserted explicitly.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_reconciliation_count_and_revenue( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 5005,
				'total'     => 10.00,
				'popup_ids' => [ '111' ],
			] 
		);
		$this->insert_order(
			$backend,
			[
				'order_id'  => 5006,
				'total'     => 20.00,
				'popup_ids' => [ '111' ],
			] 
		);
		$this->insert_order(
			$backend,
			[
				'order_id'  => 5007,
				'total'     => 30.00,
				'popup_ids' => [ '222' ],
			] 
		);

		$result = $this->run_reader( $backend );

		$this->assertEquals(
			[
				'111' => [
					'conversions' => 2,
					'revenue'     => 30.00,
				],
				'222' => [
					'conversions' => 1,
					'revenue'     => 30.00,
				],
			],
			$result
		);
	}

	/**
	 * An order with no popup meta (organic / direct donation) is NOT counted —
	 * meta-absence is correctly "not prompt-attributed", not a coverage gap.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_order_without_popup_meta_excluded( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 5008,
				'total'     => 99.00,
				'popup_ids' => [],
			] 
		);

		$this->assertSame( [], $this->run_reader( $backend ) );
	}
}
