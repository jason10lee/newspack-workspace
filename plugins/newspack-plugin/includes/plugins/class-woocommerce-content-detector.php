<?php
/**
 * WooCommerce content detector.
 *
 * Detects whether the current front-end request renders WooCommerce content
 * (blocks or classic shortcodes) so the Perfmatters integration can veto the
 * "Disable WooCommerce Scripts" strip on those requests only. See NPPM-193.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Detects WooCommerce content on the current request.
 */
class WooCommerce_Content_Detector {

	/**
	 * Memoized per-request result. Null = not yet computed.
	 *
	 * @var bool|null
	 */
	private static $memo = null;

	/**
	 * WooCommerce shortcode tags that render storefront content depending on
	 * WooCommerce's frontend stylesheets.
	 *
	 * Maintenance obligation: review on WooCommerce major upgrades (the list can
	 * drift from WooCommerce's actual registrations). See design doc
	 * "Dependencies & fragility".
	 *
	 * @var string[]
	 */
	private static $wc_shortcode_tags = [
		'products',
		'product',
		'product_page',
		'product_category',
		'product_categories',
		'recent_products',
		'featured_products',
		'sale_products',
		'best_selling_products',
		'top_rated_products',
		'related_products',
		'add_to_cart',
		'add_to_cart_url',
		'woocommerce_cart',
		'woocommerce_checkout',
		'woocommerce_my_account',
		'woocommerce_order_tracking',
		'shop_messages',
	];

	/**
	 * Whether the current request renders WooCommerce content.
	 *
	 * Fail-open: on any error, returns true (assume WooCommerce content present)
	 * so the Perfmatters strip is vetoed and assets are kept — never strip on
	 * doubt.
	 *
	 * @return bool
	 */
	public static function current_request_has_woocommerce_content() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		try {
			$visited    = [];
			self::$memo = self::scan_queried_post( $visited )
				|| self::scan_active_block_widgets( $visited )
				|| self::scan_fse_template( $visited );
		} catch ( \Throwable $e ) {
			// Fail open: keep WooCommerce assets. Logged via newspack_log so a
			// *persistent* failure (perf win silently off site-wide) is
			// observable in Newspack Manager, not just local logs.
			Logger::newspack_log(
				'newspack_perfmatters_wc_detection_error',
				'WooCommerce content detection failed; keeping WooCommerce assets (fail-open).',
				[ 'error' => $e->getMessage() ],
				'error'
			);
			self::$memo = true;
		}

		return self::$memo;
	}

	/**
	 * Reset the per-request memo. For tests only.
	 */
	public static function reset_memo() {
		self::$memo = null;
	}

	/**
	 * Recursive markup scanner: matchers first, then indirection expansion.
	 *
	 * @param string $markup  Block markup to scan.
	 * @param array  $visited Reference set of already-resolved refs ("type:id").
	 * @return bool
	 */
	private static function markup_has_woocommerce( $markup, &$visited ) {
		if ( ! is_string( $markup ) || '' === $markup ) {
			return false;
		}
		if ( self::markup_has_wc_block( $markup ) || self::markup_has_wc_shortcode( $markup ) ) {
			return true;
		}
		return self::expand_references( $markup, $visited );
	}

	/**
	 * Whether markup contains any woocommerce/* block (catches any nesting depth
	 * because serialized block markup is inline).
	 *
	 * @param string $markup Block markup.
	 * @return bool
	 */
	private static function markup_has_wc_block( $markup ) {
		return str_contains( $markup, '<!-- wp:woocommerce/' );
	}

	/**
	 * Whether markup contains any known WooCommerce shortcode. Relies on the
	 * shortcode being registered (WooCommerce registers its shortcodes on `init`,
	 * before wp_enqueue_scripts priority 99).
	 *
	 * @param string $markup Markup/content.
	 * @return bool
	 */
	private static function markup_has_wc_shortcode( $markup ) {
		foreach ( self::$wc_shortcode_tags as $tag ) {
			if ( has_shortcode( $markup, $tag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Follow core/template-part and core/block references found in markup.
	 * Stub — implemented in a later task.
	 *
	 * @param string $markup  Block markup.
	 * @param array  $visited Reference set.
	 * @return bool
	 */
	private static function expand_references( $markup, &$visited ) {
		return false;
	}

	/**
	 * Source: the queried post's content (post-type-agnostic).
	 *
	 * @param array $visited Reference set.
	 * @return bool
	 */
	private static function scan_queried_post( &$visited ) {
		$queried = get_queried_object();
		if ( ! $queried instanceof \WP_Post ) {
			return false;
		}
		return self::markup_has_woocommerce( $queried->post_content, $visited );
	}

	/**
	 * Source: active block widgets. Scans only widgets assigned to active
	 * sidebars; wp_inactive_widgets are deliberately skipped so orphaned widgets
	 * cannot veto the Perfmatters strip site-wide.
	 *
	 * @param array $visited Reference set.
	 * @return bool
	 */
	private static function scan_active_block_widgets( &$visited ) {
		$sidebars = wp_get_sidebars_widgets();
		if ( empty( $sidebars ) || ! is_array( $sidebars ) ) {
			return false;
		}
		$instances = get_option( 'widget_block', [] );
		if ( empty( $instances ) || ! is_array( $instances ) ) {
			return false;
		}
		foreach ( $sidebars as $sidebar_id => $widget_ids ) {
			// Skip the inactive store: orphaned widgets must not veto the strip.
			if ( 'wp_inactive_widgets' === $sidebar_id || empty( $widget_ids ) || ! is_array( $widget_ids ) ) {
				continue;
			}
			foreach ( $widget_ids as $widget_id ) {
				if ( ! preg_match( '/^block-(\d+)$/', (string) $widget_id, $matches ) ) {
					continue;
				}
				$index = (int) $matches[1];
				if ( empty( $instances[ $index ]['content'] ) ) {
					continue;
				}
				if ( self::markup_has_woocommerce( $instances[ $index ]['content'], $visited ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Source: the resolved FSE template. Stub — implemented in a later task.
	 *
	 * @param array $visited Reference set.
	 * @return bool
	 */
	private static function scan_fse_template( &$visited ) {
		return false;
	}
}
