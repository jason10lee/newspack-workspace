<?php
/**
 * Pricing_Rule Repository contract.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

interface Pricing_Rule_Repository {
	public function for_context( Pricing_Context $ctx ): array;
	public function save( Pricing_Rule $p ): void;
	public function all(): array;
}
