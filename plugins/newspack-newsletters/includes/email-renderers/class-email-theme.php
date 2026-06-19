<?php
/**
 * Canonical email theme fragment shared by the newsletter editor and the WC renderer.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Email_Renderers;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for block styling that must match between the editor
 * canvas and the rendered email. Currently carries only the button; grows into
 * the full canonical email theme as more blocks are reconciled.
 */
class Email_Theme {
	/**
	 * Canonical theme.json fragment.
	 *
	 * @param \WP_Post $post Newsletter post (reserved for future per-newsletter styling).
	 * @return array Theme.json-shaped array.
	 */
	public static function canonical( \WP_Post $post ): array {
		unset( $post ); // Reserved; the button is not yet per-newsletter.
		return [
			'version' => 3,
			'styles'  => [
				'elements' => [
					'button' => self::button_styles(),
				],
			],
		];
	}

	/**
	 * Branded button styling applied to both the editor and the render.
	 *
	 * @return array Theme.json `elements.button` styles.
	 */
	private static function button_styles(): array {
		$primary = '#36f';
		if ( method_exists( '\Newspack\Lite_Site', 'get_primary_color' ) ) {
			$primary = \Newspack\Lite_Site::get_primary_color();
		}
		return [
			'color'   => [
				'background' => $primary,
				'text'       => '#fff',
			],
			'border'  => [
				'radius' => '5px',
			],
			'spacing' => [
				'padding' => [
					'top'    => '12px',
					'bottom' => '12px',
					'left'   => '24px',
					'right'  => '24px',
				],
			],
		];
	}
}
