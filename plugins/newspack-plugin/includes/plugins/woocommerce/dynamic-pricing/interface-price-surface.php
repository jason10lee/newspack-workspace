<?php
/**
 * Price Surface contract.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

interface Price_Surface {
	public function id(): string;
	public function is_stateful(): bool;
	public function triggers(): array;
	public function context( mixed $target, string $trigger ): Pricing_Context;
	public function apply( Pricing_Context $ctx, Price_Decision $d ): void;
}
