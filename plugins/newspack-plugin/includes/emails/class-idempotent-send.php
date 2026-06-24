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
 *          skip to avoid a concurrent double-send (the claim acts as a lock).
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
 * The entity is any WC_Data-backed object (WC_Subscription, WC_Order, ...);
 * it is duck-typed on get_meta / update_meta_data / delete_meta_data / save,
 * so this helper carries no hard WooCommerce dependency.
 *
 * Value-match (`=== $idem_value`) on both markers is intentional: when the
 * idempotency value encodes mutable state (e.g. a card's expiry tuple), a
 * change in that state yields a new value which the stored marker no longer
 * matches — so the next cycle is correctly not blocked by a stale mark.
 */
class Idempotent_Send {

	/**
	 * Default bounded save attempts (a transient save() is retried).
	 */
	const DEFAULT_SAVE_ATTEMPTS = 3;

	/**
	 * Log header used when the caller does not supply one.
	 */
	const LOGGER_HEADER = 'NEWSPACK-IDEMPOTENT-SEND';

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

		$sent_key      = $args['sent_key'];
		$pending_key   = $args['pending_key'];
		$idem          = $args['idem_value'];
		$header        = $args['logger_header'] ?? self::LOGGER_HEADER;
		$save_attempts = max( 1, (int) ( $args['save_attempts'] ?? self::DEFAULT_SAVE_ATTEMPTS ) );
		$clear_on_send = (array) ( $args['clear_on_send'] ?? [] );

		/**
		 * Filters how long (in seconds) a PENDING claim is treated as
		 * in-progress before a later pass considers it stale and re-sends.
		 *
		 * @param int    $grace_seconds Grace window in seconds.
		 * @param string $sent_key      The SENT meta key (identifies the flow).
		 */
		$grace = (int) apply_filters(
			'newspack_idempotent_send_grace_seconds',
			(int) ( $args['grace_seconds'] ?? HOUR_IN_SECONDS ),
			$sent_key
		);

		// 1. SENT always blocks.
		if ( $entity->get_meta( $sent_key, true ) === $idem ) {
			return false;
		}

		// 2. Reconcile an existing matching PENDING claim.
		$pending = $entity->get_meta( $pending_key, true );
		if ( is_array( $pending ) && isset( $pending['value'], $pending['ts'] ) && $pending['value'] === $idem ) {
			$age = time() - (int) $pending['ts'];
			if ( $age < $grace ) {
				// Recent claim: a concurrent pass holds it. Skip (lock).
				return false;
			}
			// Stale claim: the claiming pass died without confirming.
			// Re-send per the over-send policy and surface it in the log.
			Logger::log(
				sprintf(
					'Stale pending send claim (age %ds, grace %ds) for "%s"; re-sending per over-send policy.',
					$age,
					$grace,
					$idem
				),
				$header,
				'warning'
			);
		}

		// 3. Claim PENDING durably. Never send without a durable claim.
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

		// 4. Send.
		$sent = (bool) call_user_func( $args['send'] );
		if ( ! $sent ) {
			// Release the claim so a later pass retries cleanly.
			$entity->delete_meta_data( $pending_key );
			self::save_with_retry( $entity, $save_attempts );
			return false;
		}

		// 5. Confirm: promote PENDING → SENT (and clear companion keys).
		$entity->delete_meta_data( $pending_key );
		$entity->update_meta_data( $sent_key, $idem );
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
