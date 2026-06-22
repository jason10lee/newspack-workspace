<?php
/**
 * DB-backed integration tests for the NPPD-1746 two-key attributed-subscription
 * reader (get_attributed_subscription_orders) on BOTH storage backends.
 *
 * The subscription counterpart to {@see Test_Prompt_Attributed_Donation_Reader}.
 * Where the donation reader is single-key (`_newspack_popup_id`), a subscription
 * order can carry `_gate_post_id` (paywall gate) OR `_newspack_popup_id` (popup)
 * OR neither (organic). The reader returns ONE gate id and ONE popup id per order
 * (correlated MIN, never a multiplying JOIN), scoped to INITIAL completed orders
 * containing a non-donation subscription product, renewals excluded.
 *
 * Anchor cases:
 *   - the NPPD-1685 2x defect, now on BOTH keys: `_gate_post_id` and
 *     `_newspack_popup_id` are each written through different code paths, so each
 *     duplicate-meta case is exercised independently to prove neither double-counts.
 *   - the dual-key order: an order carrying BOTH keys must surface BOTH ids on its
 *     single row, so the orchestrator's gate-precedence fold has something to apply
 *     precedence to (the precedence rule itself — gate wins, counted once — is pinned
 *     in isolation by {@see Test_Subscribers_Metric}, since zero dual-key orders exist
 *     in live data and this is the only thing exercising it).
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\HPOS_Storage;
use Newspack\Insights\Legacy_Storage;
use Newspack\Insights\Storage_Interface;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

require_once __DIR__ . '/trait-insights-woo-order-fixtures.php';

/**
 * Integration tests for the two-key attributed-subscription reader.
 *
 * @group insights
 */
class Test_Attributed_Subscription_Reader extends WP_UnitTestCase {

	use Insights_Woo_Order_Fixtures;

	/**
	 * Donation product id (excluded from the subscription set even though it is
	 * itself subscription-typed — recurring donations are subscriptions).
	 */
	const DONATION_PRODUCT_ID = 999001;

	/**
	 * Non-donation subscription product id (membership/paywall product), created
	 * fresh per test and tagged `product_type=subscription`.
	 *
	 * @var int
	 */
	private $sub_product_id;

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
	 * Per test: ensure the `product_type` taxonomy exists, then create the
	 * non-donation subscription product and the (excluded) donation product, both
	 * tagged subscription-typed so the reader's `subscription_product_ids_sql()`
	 * taxonomy query returns them and the donation NOT IN filter is exercised.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! taxonomy_exists( 'product_type' ) ) {
			register_taxonomy( 'product_type', 'product' );
		}
		$this->sub_product_id = (int) wp_insert_post(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Membership',
			]
		);
		wp_set_object_terms( $this->sub_product_id, 'subscription', 'product_type' );

		// The donation product is ALSO subscription-typed (recurring donation), so it
		// only stays out of the subscription set via the donation NOT IN filter.
		wp_insert_post(
			[
				'import_id'   => self::DONATION_PRODUCT_ID,
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Recurring donation',
			]
		);
		wp_set_object_terms( self::DONATION_PRODUCT_ID, 'subscription', 'product_type' );
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
	 * The reader under test for a backend, scoped to exclude the donation product.
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @return Storage_Interface
	 */
	private function reader_for( string $backend ): Storage_Interface {
		return 'hpos' === $backend
			? new HPOS_Storage( [ self::DONATION_PRODUCT_ID ] )
			: new Legacy_Storage( [ self::DONATION_PRODUCT_ID ] );
	}

	/**
	 * Insert an order into the given backend (defaults product to the subscription
	 * product so callers only specify attribution meta).
	 *
	 * @param string $backend 'legacy' | 'hpos'.
	 * @param array  $args    Order spec (see the fixtures trait).
	 * @return void
	 */
	private function insert_order( string $backend, array $args ): void {
		$args['product_id'] = $args['product_id'] ?? $this->sub_product_id;
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
	 * Run the reader and index its per-order rows by order_id for assertions.
	 *
	 * @param string $backend Backend key.
	 * @return array<int, array{order_id:int, gate_id:?string, popup_id:?string, order_total:float}>
	 */
	private function run_reader_indexed( string $backend ): array {
		[ $start, $end ] = $this->window();
		$rows            = $this->reader_for( $backend )->get_attributed_subscription_orders( $start, $end );
		$by_id           = [];
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['order_id'] ] = $row;
		}
		return $by_id;
	}

	/**
	 * POPUP-only order: surfaces a popup id, no gate id.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_popup_only_order( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 6001,
				'total'     => 60.00,
				'popup_ids' => [ '4242' ],
			] 
		);

		$rows = $this->run_reader_indexed( $backend );

		$this->assertArrayHasKey( 6001, $rows );
		$this->assertNull( $rows[6001]['gate_id'] );
		$this->assertSame( '4242', $rows[6001]['popup_id'] );
		$this->assertSame( 60.00, $rows[6001]['order_total'] );
	}

	/**
	 * GATE-only order: surfaces a gate id, no popup id.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_gate_only_order( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id' => 6002,
				'total'    => 75.00,
				'gate_ids' => [ '888' ],
			] 
		);

		$rows = $this->run_reader_indexed( $backend );

		$this->assertArrayHasKey( 6002, $rows );
		$this->assertSame( '888', $rows[6002]['gate_id'] );
		$this->assertNull( $rows[6002]['popup_id'] );
		$this->assertSame( 75.00, $rows[6002]['order_total'] );
	}

	/**
	 * DUAL-KEY order: an order carrying BOTH keys surfaces BOTH ids on its single
	 * row (one row, not two) — the input the gate-precedence fold acts on. The
	 * precedence DECISION (gate wins, counted once) is asserted in isolation by
	 * Test_Subscribers_Metric::test_bucket_dual_key_order_is_gate_only().
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_dual_key_order_surfaces_both_ids_on_one_row( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 6003,
				'total'     => 90.00,
				'gate_ids'  => [ '888' ],
				'popup_ids' => [ '4242' ],
			]
		);

		$rows = $this->run_reader_indexed( $backend );

		$this->assertArrayHasKey( 6003, $rows );
		$this->assertCount( 1, $rows, 'a dual-key order must produce exactly one row, not one per key' );
		$this->assertSame( '888', $rows[6003]['gate_id'] );
		$this->assertSame( '4242', $rows[6003]['popup_id'] );
	}

	/**
	 * ORGANIC order (neither key) is NOT returned — meta-absence is "not
	 * prompt/gate-driven", correctly excluded, not a coverage gap.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_organic_order_excluded( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id' => 6004,
				'total'    => 50.00,
			] 
		);

		$this->assertSame( [], $this->run_reader_indexed( $backend ) );
	}

	/**
	 * ANCHOR (2x defect, POPUP key): two identical `_newspack_popup_id` rows on one
	 * order resolve to ONE row with one popup id — not two, not doubled.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_duplicate_popup_meta_counted_once( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 6005,
				'total'     => 30.00,
				'popup_ids' => [ '4242', '4242' ],
			] 
		);

		$rows = $this->run_reader_indexed( $backend );

		$this->assertCount( 1, $rows );
		$this->assertSame( '4242', $rows[6005]['popup_id'] );
		$this->assertSame( 30.00, $rows[6005]['order_total'] );
	}

	/**
	 * ANCHOR (2x defect, GATE key): two identical `_gate_post_id` rows on one order
	 * resolve to ONE row with one gate id. The gate key is written through a
	 * different code path (class-memberships.php) than the popup key, so it gets its
	 * own duplicate-meta proof rather than relying on the popup case.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_duplicate_gate_meta_counted_once( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id' => 6006,
				'total'    => 45.00,
				'gate_ids' => [ '888', '888' ],
			] 
		);

		$rows = $this->run_reader_indexed( $backend );

		$this->assertCount( 1, $rows );
		$this->assertSame( '888', $rows[6006]['gate_id'] );
		$this->assertSame( 45.00, $rows[6006]['order_total'] );
	}

	/**
	 * DIVERGENT meta resolves to the MIN id (deterministic), counted once — pins the
	 * reader against a future write-side change emitting divergent ids per key.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_divergent_meta_resolves_to_min( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'  => 6007,
				'total'     => 20.00,
				'gate_ids'  => [ '900', '300' ],
				'popup_ids' => [ '700', '500' ],
			]
		);

		$rows = $this->run_reader_indexed( $backend );

		$this->assertCount( 1, $rows );
		$this->assertSame( '300', $rows[6007]['gate_id'], 'MIN gate id' );
		$this->assertSame( '500', $rows[6007]['popup_id'], 'MIN popup id' );
	}

	/**
	 * RENEWAL EXCLUSION: a renewal order (carries `_subscription_renewal`, inherits
	 * the parent's attribution meta) is excluded; the initial order is kept.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_renewal_order_excluded( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id' => 6008,
				'total'    => 40.00,
				'gate_ids' => [ '888' ],
			] 
		);
		$this->insert_order(
			$backend,
			[
				'order_id' => 6009,
				'total'    => 40.00,
				'gate_ids' => [ '888' ],
				'renewal'  => '6008',
			]
		);

		$rows = $this->run_reader_indexed( $backend );

		$this->assertArrayHasKey( 6008, $rows );
		$this->assertArrayNotHasKey( 6009, $rows, 'renewal order must be excluded' );
	}

	/**
	 * DONATION-PRODUCT EXCLUSION: an attributed order whose only product is the
	 * (subscription-typed) donation product is excluded from the subscription
	 * reader via the donation NOT IN filter — it belongs to the donation reader.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend key.
	 */
	public function test_donation_product_order_excluded( string $backend ): void {
		$this->insert_order(
			$backend,
			[
				'order_id'   => 6010,
				'total'      => 25.00,
				'product_id' => self::DONATION_PRODUCT_ID,
				'popup_ids'  => [ '4242' ],
			]
		);

		$this->assertSame( [], $this->run_reader_indexed( $backend ) );
	}
}
