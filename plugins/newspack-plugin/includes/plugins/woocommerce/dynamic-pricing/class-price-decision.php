<?php
/**
 * Price Decision value object.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Output of Pricing_Strategy::decide().
 */
final class Price_Decision {
	const DURABLE  = 'durable';
	const ONE_TIME = 'one_time';

	/**
	 * @param float       $amount          Final resolved amount.
	 * @param string      $durability      Self::DURABLE or self::ONE_TIME.
	 * @param string      $reason          Machine-readable label.
	 * @param string      $label           Human-readable label (audit note).
	 * @param string      $strategy_id     Strategy that produced this decision.
	 * @param mixed       $dimension_value Idempotency anchor (e.g., cycle number).
	 * @param string|null $policy_id       Set by the engine after decide() returns.
	 * @param bool        $is_locked       Set by Pricing_Guardrails::compose() when a priority_exclusive
	 *                                     policy wins; runtime-only, never persisted.
	 * @param bool        $publicize       Set by the engine from Policy::$publicize. Runtime-only,
	 *                                     never persisted. Surfaces use it to decide whether to
	 *                                     communicate the policy to the reader (cart strikethrough,
	 *                                     label badge, etc.).
	 */
	public function __construct(
		public float $amount,
		public string $durability,
		public string $reason,
		public string $label,
		public string $strategy_id,
		public mixed $dimension_value,
		public ?string $policy_id = null,
		public bool $is_locked = false,
		public bool $publicize = false
	) {}
}
