<?php
/**
 * Policy Repository contract.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

interface Policy_Repository {
	public function for_context( Pricing_Context $ctx ): array;
	public function save( Policy $p ): void;
	public function all(): array;
}
