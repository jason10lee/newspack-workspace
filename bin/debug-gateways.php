<?php
/**
 * Debug script to inspect WooCommerce payment gateway state.
 *
 * Usage (from the appropriate site directory):
 *   n wp eval-file /var/scripts/debug-gateways.php
 */

if ( ! function_exists( 'WC' ) ) {
	WP_CLI::error( 'WooCommerce is not active.' );
}

$all_gateways       = WC()->payment_gateways()->payment_gateways();
$available_gateways = WC()->payment_gateways->get_available_payment_gateways();

// Supported gateways from Modal_Checkout, if available.
$supported_gateways = [];
if ( class_exists( 'Newspack_Blocks\Modal_Checkout' ) ) {
	$supported_gateways = Newspack_Blocks\Modal_Checkout::get_supported_payment_gateways();
}

WP_CLI::log( '' );
WP_CLI::log( '=== All Registered Gateways (payment_gateways()) ===' );
foreach ( $all_gateways as $id => $gateway ) {
	$settings   = get_option( 'woocommerce_' . $id . '_settings', [] );
	$enabled    = is_array( $settings ) && isset( $settings['enabled'] ) && 'yes' === $settings['enabled'];
	$available  = isset( $available_gateways[ $id ] );
	$supported  = in_array( $id, $supported_gateways, true );
	$flags      = implode( ', ', array_filter( [
		$enabled   ? 'enabled-in-settings' : null,
		$available ? 'available-at-runtime' : null,
		$supported ? 'modal-supported' : null,
	] ) );
	WP_CLI::log( sprintf( '  %-35s %s', $id, $flags ?: '(none)' ) );
}

WP_CLI::log( '' );
WP_CLI::log( '=== Available at Runtime (get_available_payment_gateways()) ===' );
if ( empty( $available_gateways ) ) {
	WP_CLI::log( '  (none)' );
} else {
	foreach ( array_keys( $available_gateways ) as $id ) {
		WP_CLI::log( '  ' . $id );
	}
}

WP_CLI::log( '' );
WP_CLI::log( '=== Enabled in Settings but NOT Available at Runtime ===' );
$gap_found = false;
foreach ( array_keys( $all_gateways ) as $id ) {
	if ( isset( $available_gateways[ $id ] ) ) {
		continue;
	}
	$settings = get_option( 'woocommerce_' . $id . '_settings', [] );
	if ( is_array( $settings ) && isset( $settings['enabled'] ) && 'yes' === $settings['enabled'] ) {
		$supported = in_array( $id, $supported_gateways, true ) ? ' (modal-supported)' : ' ** NOT modal-supported **';
		WP_CLI::log( '  ' . $id . $supported );
		$gap_found = true;
	}
}
if ( ! $gap_found ) {
	WP_CLI::log( '  (none)' );
}

if ( ! empty( $supported_gateways ) ) {
	WP_CLI::log( '' );
	WP_CLI::log( '=== Modal Checkout Supported Gateways (via filter) ===' );
	foreach ( $supported_gateways as $id ) {
		WP_CLI::log( '  ' . $id );
	}
}

// Final verdict.
WP_CLI::log( '' );
if ( class_exists( 'Newspack_Blocks\Modal_Checkout' ) ) {
	$has_unsupported = Newspack_Blocks\Modal_Checkout::has_unsupported_payment_gateway();
	if ( $has_unsupported ) {
		WP_CLI::warning( 'has_unsupported_payment_gateway() returns TRUE — modal checkout will be bypassed.' );
	} else {
		WP_CLI::success( 'has_unsupported_payment_gateway() returns FALSE — modal checkout is available.' );
	}
} else {
	WP_CLI::warning( 'Newspack_Blocks\Modal_Checkout class not found — cannot evaluate has_unsupported_payment_gateway().' );
}

WP_CLI::log( '' );
