<?php
/**
 * Two-phase idempotent transactional-email send.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent_Send.
 *
 * Sends a transactional email at most once per (entity, idempotency value)
 * using a durable two-phase claim stored on the entity's own metadata. This
 * closes the window the single-phase "send then mark" approach leaves open:
 * if a process dies after the mail is accepted but before the SENT marker is
 * persisted, a later pass would re-send.
 *
 * Flow:
 *
 *   1. SENT marker present and matching → already delivered, skip.
 *   2. PENDING claim present and matching:
 *        - recent (within the grace window) → another pass is mid-send;
 *          skip (best-effort guard — see the concurrency note below).
 *        - stale (older than the grace window) → the claiming pass died
 *          between send() and confirm. We cannot know whether the mail went
 *          out, so we RE-SEND and log it. This is a deliberate over-send
 *          policy: for these warnings a rare duplicate is far less harmful
 *          than a silent miss. Callers that prefer the opposite trade-off
 *          should not use this helper as-is.
 *   3. Otherwise: write the PENDING claim durably (never send without a
 *      durable claim), send, then promote the claim to SENT durably. A death
 *      between send and promote leaves a PENDING claim that the next
 *      post-grace pass reconciles per step 2.
 *
 * Concurrency: the PENDING claim is a *best-effort* guard, NOT a
 * mutual-exclusion lock. It is a non-atomic read-then-write, so two passes
 * that each read "no claim" before either persists one can still both send.
 * It reliably catches *overlapping* passes (e.g. a daily cron still running
 * when an operator starts a CLI backfill) but not perfectly *simultaneous*
 * ones, and per-process WC meta caches widen that window further. A hard
 * guarantee would need an atomic primitive (a DB unique index, MySQL
 * GET_LOCK, or wp_cache_add). For the card-expiry use case — a daily cron
 * plus occasional manual backfill — best-effort is sufficient: the residual
 * race is a rare duplicate, the accepted failure direction.
 *
 * The entity is any WC_Data-backed object (WC_Subscription, WC_Order, ...);
 * it is duck-typed on get_meta / update_meta_data / delete_meta_data / save,
 * so this helper carries no hard WooCommerce dependency.
 *
 * Value-match (`=== $idem_value`) on both markers is intentional: when the
 * idempotency value encodes mutable state (e.g. a card's expiry tuple), a
 * change in that state yields a new value which the stored marker no longer
 * matches — so the next cycle is correctly not blocked by a stale mark.
 * Callers should pass a string idem value; the markers are compared strictly.
 */
final class Idempotent_Send {

	/**
	 * Default bounded save attempts (a transient save() is retried).
	 */
	const DEFAULT_SAVE_ATTEMPTS = 3;

	/**
	 * Log header used when the caller does not supply one.
	 */
	const LOGGER_HEADER = 'NEWSPACK-IDEMPOTENT-SEND';

	/**
	 * Whether a send should be SKIPPED right now for this (entity, value):
	 * the SENT marker already matches, or a matching PENDING claim is still
	 * recent (within the grace window). A *stale* PENDING claim returns
	 * false — it does not block, it re-sends (see `send()`).
	 *
	 * This is the public predicate callers use to pre-filter or count work
	 * without sending (e.g. a CLI backfill estimate), so their view stays
	 * consistent with what `send()` will actually do. The PENDING marker's
	 * shape and grace logic live here, not in callers.
	 *
	 * @param object $entity The WC_Data-backed entity carrying the markers.
	 * @param array  $args {
	 *     Predicate arguments.
	 *
	 *     @type string $sent_key      Meta key for the SENT marker. Required.
	 *     @type string $pending_key   Meta key for the PENDING claim. Required.
	 *     @type string $idem_value    Idempotency value to match. Required.
	 *     @type int    $grace_seconds Seconds a claim is "in progress". Optional.
	 * }
	 * @return bool True if a send should be skipped right now.
	 */
	public static function is_claimed( $entity, array $args ): bool {
		if ( ! isset( $args['sent_key'], $args['pending_key'], $args['idem_value'] ) ) {
			return false;
		}
		if ( ! is_object( $entity ) || ! method_exists( $entity, 'get_meta' ) ) {
			return false;
		}
		$idem = $args['idem_value'];

		// SENT always blocks. Checked first and without computing grace, so
		// the common already-delivered case stays cheap.
		if ( $entity->get_meta( $args['sent_key'], true ) === $idem ) {
			return true;
		}

		// A matching PENDING claim blocks only while it is recent.
		$pending = $entity->get_meta( $args['pending_key'], true );
		if ( is_array( $pending ) && isset( $pending['value'], $pending['ts'] ) && $pending['value'] === $idem ) {
			return ( time() - (int) $pending['ts'] ) < self::grace_seconds( $args );
		}
		return false;
	}

	/**
	 * Perform a two-phase idempotent send.
	 *
	 * @param object $entity The WC_Data-backed entity carrying the markers.
	 * @param array  $args {
	 *     Send arguments.
	 *
	 *     @type string   $sent_key      Meta key for the SENT marker. Required.
	 *     @type string   $pending_key   Meta key for the PENDING claim. Required.
	 *     @type string   $idem_value    Idempotency value to match. Required.
	 *     @type callable $send          () => bool performing the actual send. Required.
	 *     @type string   $logger_header Log header. Default self::LOGGER_HEADER.
	 *     @type int      $grace_seconds Seconds a claim is "in progress". Default filterable HOUR_IN_SECONDS.
	 *     @type int      $save_attempts Bounded save retries. Default self::DEFAULT_SAVE_ATTEMPTS.
	 *     @type string[] $clear_on_send Extra meta keys to delete in the confirm save. Default [].
	 * }
	 * @return bool True if a send was performed in this call, false otherwise.
	 */
	public static function send( $entity, array $args ): bool {
		foreach ( [ 'sent_key', 'pending_key', 'idem_value', 'send' ] as $required ) {
			if ( ! isset( $args[ $required ] ) ) {
				return false;
			}
		}
		if (
			! is_object( $entity ) ||
			! method_exists( $entity, 'get_meta' ) ||
			! method_exists( $entity, 'update_meta_data' ) ||
			! method_exists( $entity, 'delete_meta_data' ) ||
			! method_exists( $entity, 'save' ) ||
			! is_callable( $args['send'] )
		) {
			return false;
		}

		// Already delivered, or a recent claim holds it → skip. Computing
		// grace lazily inside is_claimed keeps the already-sent path cheap.
		if ( self::is_claimed( $entity, $args ) ) {
			return false;
		}

		$pending_key   = $args['pending_key'];
		$idem          = $args['idem_value'];
		$header        = $args['logger_header'] ?? self::LOGGER_HEADER;
		$save_attempts = max( 1, (int) ( $args['save_attempts'] ?? self::DEFAULT_SAVE_ATTEMPTS ) );
		$clear_on_send = (array) ( $args['clear_on_send'] ?? [] );
		$grace         = self::grace_seconds( $args );

		// We are past is_claimed(), so any matching PENDING here is STALE:
		// the claiming pass died without confirming. Re-send per the
		// over-send policy and surface it in the log.
		$pending = $entity->get_meta( $pending_key, true );
		if ( is_array( $pending ) && isset( $pending['value'], $pending['ts'] ) && $pending['value'] === $idem ) {
			Logger::log(
				sprintf(
					'Stale pending send claim (age %ds, grace %ds) for "%s"; re-sending per over-send policy.',
					time() - (int) $pending['ts'],
					$grace,
					$idem
				),
				$header,
				'warning'
			);
		}

		// 1. Claim PENDING durably. Never send without a durable claim.
		$entity->update_meta_data(
			$pending_key,
			[
				'value' => $idem,
				'ts'    => time(),
			]
		);
		if ( ! self::save_with_retry( $entity, $save_attempts )['saved'] ) {
			Logger::log(
				sprintf( 'Could not persist the pending claim for "%s"; skipping the send this pass.', $idem ),
				$header,
				'error'
			);
			return false;
		}

		// 2. Send.
		$sent = (bool) call_user_func( $args['send'] );
		if ( ! $sent ) {
			// Release the claim so a later pass retries cleanly. If the
			// release save ALSO fails, the (now-recent) claim persists and
			// can suppress the retry for up to the grace window — the wrong
			// failure direction for these warnings — so log it: this rare
			// double-failure is otherwise invisible.
			$entity->delete_meta_data( $pending_key );
			if ( ! self::save_with_retry( $entity, $save_attempts )['saved'] ) {
				Logger::log(
					sprintf(
						'Send failed for "%s" and releasing the pending claim also failed; the claim may delay a retry for up to %ds.',
						$idem,
						$grace
					),
					$header,
					'error'
				);
			}
			return false;
		}

		// 3. Confirm: promote PENDING → SENT (and clear companion keys).
		$entity->delete_meta_data( $pending_key );
		$entity->update_meta_data( $args['sent_key'], $idem );
		foreach ( $clear_on_send as $key ) {
			$entity->delete_meta_data( $key );
		}
		if ( ! self::save_with_retry( $entity, $save_attempts )['saved'] ) {
			Logger::log(
				sprintf(
					'Sent "%s" but failed to persist the SENT marker; the in-progress claim remains and a single re-send is possible after the %ds grace window.',
					$idem,
					$grace
				),
				$header,
				'error'
			);
		}
		return true;
	}

	/**
	 * Resolve the grace window (seconds) a PENDING claim is treated as
	 * in-progress, applying the filter. Kept private so the marker shape and
	 * the filter name live in one place.
	 *
	 * @param array $args Send/predicate args (reads `grace_seconds`, `sent_key`).
	 * @return int Grace window in seconds.
	 */
	private static function grace_seconds( array $args ): int {
		/**
		 * Filters how long (in seconds) a PENDING claim is treated as
		 * in-progress before a later pass considers it stale and re-sends.
		 *
		 * @param int    $grace_seconds Grace window in seconds.
		 * @param string $sent_key      The SENT meta key (identifies the flow).
		 */
		return (int) apply_filters(
			'newspack_idempotent_send_grace_seconds',
			(int) ( $args['grace_seconds'] ?? HOUR_IN_SECONDS ),
			$args['sent_key'] ?? ''
		);
	}

	/**
	 * Persist a WC_Data entity with a bounded immediate retry, swallowing
	 * throwables. Most save() failures are transient (a momentary lock, a
	 * DB blip), so an immediate retry narrows the window where a marker is
	 * missing. Bounded; never throws.
	 *
	 * @param object $entity   Entity to save (duck-typed on save()).
	 * @param int    $attempts Max attempts (>= 1).
	 * @return array{saved: bool, last_error: string} Whether the save landed,
	 *               and the last error message if it did not.
	 */
	public static function save_with_retry( $entity, int $attempts = self::DEFAULT_SAVE_ATTEMPTS ): array {
		$attempts   = max( 1, $attempts );
		$last_error = '';
		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			try {
				$entity->save();
				return [
					'saved'      => true,
					'last_error' => '',
				];
			} catch ( \Throwable $e ) {
				$last_error = $e->getMessage();
			}
		}
		return [
			'saved'      => false,
			'last_error' => $last_error,
		];
	}
}
