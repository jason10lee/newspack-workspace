<?php
/**
 * Pricing Strategy contract.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

interface Pricing_Strategy {
	public function id(): string;
	public function config_schema(): array;
	public function applies_to( Pricing_Context $ctx, array $params ): bool;
	public function decide( Pricing_Context $ctx, array $params ): ?Price_Decision;
}
