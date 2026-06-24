<?php
/**
 * Tests for the two-phase idempotent send helper (NPPD-1768).
 *
 * Covers the claim → send → confirm state machine and its reconciliation
 * of interrupted claims:
 *   - SENT marker blocks without sending.
 *   - a recent PENDING claim is skipped (concurrency lock).
 *   - a stale PENDING claim re-sends (over-send policy) and is logged.
 *   - a clean send claims durably BEFORE sending, then promotes to SENT.
 *   - a failed send releases the claim; an un-persistable claim is not sent.
 *   - the grace window is filterable; value-match invalidates stale marks.
 *
 * @package Newspack\Tests
 */

use Newspack\Idempotent_Send;

/**
 * Idempotent_Send test case.
 */
class Newspack_Test_Idempotent_Send extends WP_UnitTestCase {

	const SENT_KEY    = '_test_sent_42';
	const PENDING_KEY = '_test_pending_42';
	const SEEDED_KEY  = '_test_seeded_42';
	const IDEM        = '42:12/2026';

	/**
	 * Build a minimal in-memory WC_Data-like entity. Duck-typed on the
	 * methods Idempotent_Send uses. `save()` throws while `throw_saves` is
	 * true, so tests can simulate a transient/persistent persistence failure
	 * (including failing only the confirm save by flipping the flag from
	 * inside the send callback).
	 *
	 * @param array $initial Initial meta as key => value.
	 * @return object
	 */
	private function make_entity( array $initial = [] ) {
		return new class( $initial ) {
			/**
			 * Meta storage.
			 *
			 * @var array
			 */
			public $meta = [];
			/**
			 * Count of save() calls.
			 *
			 * @var int
			 */
			public $save_calls = 0;
			/**
			 * When true, save() throws.
			 *
			 * @var bool
			 */
			public $throw_saves = false;
			/**
			 * Constructor.
			 *
			 * @param array $initial Initial meta.
			 */
			public function __construct( array $initial ) {
				$this->meta = $initial;
			}
			/**
			 * Get a single meta value.
			 *
			 * @param string $key    Meta key.
			 * @param bool   $single Single flag (ignored).
			 * @return mixed Stored value or '' if absent.
			 */
			public function get_meta( $key, $single = true ) {
				return $this->meta[ $key ] ?? '';
			}
			/**
			 * Set a meta value.
			 *
			 * @param string $key   Meta key.
			 * @param mixed  $value Value.
			 */
			public function update_meta_data( $key, $value ) {
				$this->meta[ $key ] = $value;
			}
			/**
			 * Delete a meta key.
			 *
			 * @param string $key Meta key.
			 */
			public function delete_meta_data( $key ) {
				unset( $this->meta[ $key ] );
			}
			/**
			 * Persist; throws while throw_saves is set.
			 *
			 * @return bool
			 * @throws \RuntimeException When throw_saves is true.
			 */
			public function save() {
				++$this->save_calls;
				if ( $this->throw_saves ) {
					throw new \RuntimeException( 'transient save failure' );
				}
				return true;
			}
		};
	}

	/**
	 * Base args for a send, with a counting send callback that returns
	 * $return. The callback records how many times it ran via $calls (passed
	 * by reference).
	 *
	 * @param int|null $calls  By-ref counter of send invocations.
	 * @param bool     $return What the send callback returns.
	 * @return array
	 */
	private function args( &$calls = null, bool $return = true ): array {
		$calls = 0;
		return [
			'sent_key'      => self::SENT_KEY,
			'pending_key'   => self::PENDING_KEY,
			'idem_value'    => self::IDEM,
			'send'          => function () use ( &$calls, $return ) {
				++$calls;
				return $return;
			},
			'logger_header' => 'NEWSPACK-TEST',
			'clear_on_send' => [ self::SEEDED_KEY ],
		];
	}

	/**
	 * Missing a required arg (or an unusable entity) is a no-op returning
	 * false — never a fatal.
	 */
	public function test_missing_args_or_bad_entity_returns_false() {
		$entity = $this->make_entity();
		$calls  = 0;
		$args   = $this->args( $calls );
		unset( $args['send'] );
		$this->assertFalse( Idempotent_Send::send( $entity, $args ), 'Missing send callback must return false.' );

		$this->assertFalse(
			Idempotent_Send::send( new \stdClass(), $this->args( $calls ) ),
			'An entity missing the WC_Data meta API must return false.'
		);
		$this->assertSame( 0, $calls );
	}

	/**
	 * A matching SENT marker blocks: no send, nothing written.
	 */
	public function test_sent_marker_blocks_without_sending() {
		$entity = $this->make_entity( [ self::SENT_KEY => self::IDEM ] );
		$result = Idempotent_Send::send( $entity, $this->args( $calls ) );

		$this->assertFalse( $result, 'A matching SENT marker must short-circuit to false.' );
		$this->assertSame( 0, $calls, 'The send callback must not run when already sent.' );
		$this->assertSame( 0, $entity->save_calls, 'No save should occur when already sent.' );
		$this->assertArrayNotHasKey( self::PENDING_KEY, $entity->meta, 'No pending claim should be written.' );
	}

	/**
	 * A recent matching PENDING claim is treated as a concurrent in-progress
	 * send and skipped — the claim is the lock.
	 */
	public function test_recent_pending_claim_skips_as_lock() {
		$entity = $this->make_entity(
			[
				self::PENDING_KEY => [
					'value' => self::IDEM,
					'ts'    => time() - 10,
				],
			]
		);
		$result = Idempotent_Send::send( $entity, $this->args( $calls ) );

		$this->assertFalse( $result, 'A recent claim must skip (concurrency lock).' );
		$this->assertSame( 0, $calls, 'No send while a recent claim is held.' );
		$this->assertSame( 0, $entity->save_calls );
	}

	/**
	 * A stale matching PENDING claim (older than the grace window) re-sends:
	 * the claiming pass died without confirming, and over-send beats a miss.
	 * The send completes and the claim is promoted to SENT.
	 */
	public function test_stale_pending_claim_resends() {
		$entity = $this->make_entity(
			[
				self::PENDING_KEY => [
					'value' => self::IDEM,
					'ts'    => time() - ( 2 * HOUR_IN_SECONDS ),
				],
			]
		);
		$result = Idempotent_Send::send( $entity, $this->args( $calls ) );

		$this->assertTrue( $result, 'A stale claim must re-send.' );
		$this->assertSame( 1, $calls, 'The send callback must run exactly once.' );
		$this->assertSame( self::IDEM, $entity->meta[ self::SENT_KEY ], 'SENT must be written on confirm.' );
		$this->assertArrayNotHasKey( self::PENDING_KEY, $entity->meta, 'The stale claim must be cleared.' );
	}

	/**
	 * A clean send claims PENDING durably BEFORE sending, then promotes the
	 * claim to SENT and clears the companion (seeded) key. The send callback
	 * asserts the claim is already visible when it runs.
	 */
	public function test_clean_send_claims_before_send_then_confirms() {
		$entity      = $this->make_entity( [ self::SEEDED_KEY => self::IDEM ] );
		$claim_seen  = null;
		$saves_at_send = null;
		$args        = $this->args( $calls );
		$args['send'] = function () use ( $entity, &$claim_seen, &$saves_at_send, &$calls ) {
			++$calls;
			$claim_seen    = $entity->get_meta( self::PENDING_KEY, true );
			$saves_at_send = $entity->save_calls;
			return true;
		};

		$result = Idempotent_Send::send( $entity, $args );

		$this->assertTrue( $result );
		$this->assertSame( 1, $calls );
		$this->assertIsArray( $claim_seen, 'The PENDING claim must be visible at send time (claimed before send).' );
		$this->assertSame( self::IDEM, $claim_seen['value'], 'The claim must carry the idempotency value.' );
		$this->assertSame( 1, $saves_at_send, 'The claim must be persisted (one save) before the send runs.' );
		$this->assertSame( self::IDEM, $entity->meta[ self::SENT_KEY ], 'SENT must be written on confirm.' );
		$this->assertArrayNotHasKey( self::PENDING_KEY, $entity->meta, 'The claim must be cleared on confirm.' );
		$this->assertArrayNotHasKey( self::SEEDED_KEY, $entity->meta, 'clear_on_send keys must be removed on confirm.' );
	}

	/**
	 * When the send returns false, the claim is released so a later pass can
	 * retry cleanly, and no SENT marker is written.
	 */
	public function test_send_failure_releases_claim() {
		$entity = $this->make_entity();
		$result = Idempotent_Send::send( $entity, $this->args( $calls, false ) );

		$this->assertFalse( $result, 'A failed send returns false.' );
		$this->assertSame( 1, $calls );
		$this->assertArrayNotHasKey( self::PENDING_KEY, $entity->meta, 'A failed send must release the claim.' );
		$this->assertArrayNotHasKey( self::SENT_KEY, $entity->meta, 'A failed send must not mark SENT.' );
	}

	/**
	 * If the PENDING claim cannot be persisted, the helper does NOT send —
	 * it never sends without a durable claim.
	 */
	public function test_send_skipped_when_claim_cannot_persist() {
		$entity              = $this->make_entity();
		$entity->throw_saves = true; // Every save() fails, including the claim.
		$result              = Idempotent_Send::send( $entity, $this->args( $calls ) );

		$this->assertFalse( $result, 'No send when the claim cannot be persisted.' );
		$this->assertSame( 0, $calls, 'The send callback must not run without a durable claim.' );
		$this->assertSame( Idempotent_Send::DEFAULT_SAVE_ATTEMPTS, $entity->save_calls, 'The claim save is retried to the budget.' );
	}

	/**
	 * The mail is accepted but the confirm save fails: the helper still
	 * returns true (a send happened) and retried the confirm save. The
	 * remaining claim bounds the over-send to a single re-send after grace.
	 */
	public function test_returns_true_when_confirm_save_fails() {
		$entity       = $this->make_entity();
		$args         = $this->args( $calls );
		$args['send'] = function () use ( $entity, &$calls ) {
			++$calls;
			$entity->throw_saves = true; // Fail only the confirm save (claim already saved).
			return true;
		};

		$result = Idempotent_Send::send( $entity, $args );

		$this->assertTrue( $result, 'A confirmed send returns true even if the SENT marker save fails.' );
		$this->assertSame( 1, $calls );
		$this->assertSame(
			1 + Idempotent_Send::DEFAULT_SAVE_ATTEMPTS,
			$entity->save_calls,
			'One successful claim save, then the confirm save retried to the budget.'
		);
	}

	/**
	 * The grace window is filterable: a 30-minute-old claim is "recent"
	 * under the default 1-hour grace (skip), but "stale" once the filter
	 * tightens the window to 10 minutes (re-send).
	 */
	public function test_grace_window_is_filterable() {
		$make = function () {
			return $this->make_entity(
				[
					self::PENDING_KEY => [
						'value' => self::IDEM,
						'ts'    => time() - ( 30 * MINUTE_IN_SECONDS ),
					],
				]
			);
		};

		$default = $make();
		$this->assertFalse(
			Idempotent_Send::send( $default, $this->args( $calls ) ),
			'Under the default 1h grace, a 30m-old claim is recent → skip.'
		);
		$this->assertSame( 0, $calls );

		add_filter( 'newspack_idempotent_send_grace_seconds', fn() => 10 * MINUTE_IN_SECONDS );
		$tightened = $make();
		$this->assertTrue(
			Idempotent_Send::send( $tightened, $this->args( $calls ) ),
			'With a 10m grace, the 30m-old claim is stale → re-send.'
		);
		$this->assertSame( 1, $calls );
		remove_all_filters( 'newspack_idempotent_send_grace_seconds' );
	}

	/**
	 * Value-match: a SENT marker carrying a DIFFERENT value (e.g. the card's
	 * prior expiry tuple) does not block a new idempotency value — the next
	 * cycle correctly sends.
	 */
	public function test_value_change_is_not_blocked_by_stale_marker() {
		$entity = $this->make_entity( [ self::SENT_KEY => '42:01/2025' ] ); // Old expiry.
		$result = Idempotent_Send::send( $entity, $this->args( $calls ) ); // New IDEM = 42:12/2026.

		$this->assertTrue( $result, 'A SENT marker for a different value must not block the new value.' );
		$this->assertSame( 1, $calls );
		$this->assertSame( self::IDEM, $entity->meta[ self::SENT_KEY ], 'SENT is updated to the new value.' );
	}

	/**
	 * The bounded save retry rides out transient failures within the budget.
	 */
	public function test_save_with_retry_succeeds_after_transient_failures() {
		$entity = new class() {
			/**
			 * Remaining throws.
			 *
			 * @var int
			 */
			public $remaining = 2;
			/**
			 * Save calls.
			 *
			 * @var int
			 */
			public $save_calls = 0;
			/**
			 * Save, throwing twice then succeeding.
			 *
			 * @return bool
			 * @throws \RuntimeException While throws remain.
			 */
			public function save() {
				++$this->save_calls;
				if ( $this->remaining > 0 ) {
					--$this->remaining;
					throw new \RuntimeException( 'transient' );
				}
				return true;
			}
		};

		$result = Idempotent_Send::save_with_retry( $entity, 3 );
		$this->assertTrue( $result['saved'] );
		$this->assertSame( 3, $entity->save_calls, '2 throws + 1 success.' );
	}

	/**
	 * The bounded save retry gives up after the budget and surfaces the error.
	 */
	public function test_save_with_retry_gives_up_after_budget() {
		$entity = new class() {
			/**
			 * Save calls.
			 *
			 * @var int
			 */
			public $save_calls = 0;
			/**
			 * Always throws.
			 *
			 * @throws \RuntimeException Always.
			 */
			public function save() {
				++$this->save_calls;
				throw new \RuntimeException( 'persistent failure' );
			}
		};

		$result = Idempotent_Send::save_with_retry( $entity, 3 );
		$this->assertFalse( $result['saved'] );
		$this->assertSame( 3, $entity->save_calls );
		$this->assertSame( 'persistent failure', $result['last_error'] );
	}
}
