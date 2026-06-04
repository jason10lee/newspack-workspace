<?php
/**
 * Newspack My Account core shell.
 *
 * Owns the My Account page, endpoints, page detection, URL generation, tab
 * registry, and content dispatch independently of WooCommerce. When
 * WooCommerce is active, every accessor delegates to WooCommerce so behavior
 * is unchanged; when it is absent, the shell runs natively.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * My_Account class.
 */
class My_Account {
	/**
	 * Option that stores the native account page ID (used only when WooCommerce
	 * is not active).
	 */
	const PAGE_ID_OPTION = 'newspack_my_account_page_id';

	/**
	 * Whether WooCommerce owns the My Account shell.
	 *
	 * @return bool
	 */
	public static function woocommerce_owns_shell() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_page_permalink' );
	}

	/**
	 * Initialize hooks. No-op for now; populated in later tasks.
	 */
	public static function init() {
		// Hooks are added in later tasks.
	}

	/**
	 * Get the My Account page ID.
	 *
	 * Resolution order: WooCommerce account page when Woo is active, else the
	 * native Newspack account page.
	 *
	 * @return int Page ID, or 0 if none is set.
	 */
	public static function get_page_id() {
		if ( self::woocommerce_owns_shell() ) {
			return (int) \get_option( 'woocommerce_myaccount_page_id', 0 );
		}
		return (int) \get_option( self::PAGE_ID_OPTION, 0 );
	}

	/**
	 * Whether the current request is the My Account page (or one of its
	 * endpoints).
	 *
	 * @return bool
	 */
	public static function is_account_page() {
		if ( self::woocommerce_owns_shell() && function_exists( 'is_account_page' ) ) {
			return \is_account_page();
		}
		$page_id = self::get_page_id();
		return $page_id && \is_page( $page_id );
	}

	/**
	 * Get the URL for a My Account endpoint.
	 *
	 * @param string $endpoint Endpoint slug. Empty string returns the base
	 *                         account page URL.
	 * @param string $value    Optional endpoint value (e.g. a subscription ID).
	 * @return string URL, or empty string if the page is not set.
	 */
	public static function get_endpoint_url( $endpoint = '', $value = '' ) {
		if ( self::woocommerce_owns_shell() ) {
			if ( '' === $endpoint || 'dashboard' === $endpoint ) {
				return \wc_get_account_endpoint_url( 'dashboard' );
			}
			return \wc_get_endpoint_url( $endpoint, $value, \wc_get_page_permalink( 'myaccount' ) );
		}

		$page_id = self::get_page_id();
		if ( ! $page_id ) {
			return '';
		}
		$permalink = \get_permalink( $page_id );
		if ( ! $permalink || '' === $endpoint ) {
			return $permalink ? $permalink : '';
		}

		if ( \get_option( 'permalink_structure' ) ) {
			$url = \trailingslashit( $permalink ) . $endpoint;
			if ( '' !== $value ) {
				$url .= '/' . $value;
			}
			return \user_trailingslashit( $url );
		}
		return \add_query_arg( $endpoint, $value, $permalink );
	}
}

My_Account::init();
