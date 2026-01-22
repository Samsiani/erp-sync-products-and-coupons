<?php
namespace WDCS;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Normalize/format a coupon code the same way WooCommerce does for storage/lookup.
 * Falls back to sanitize_title if WooCommerce helper isn't available.
 */
function wdcs_format_code( $code ) {
    $code = (string) $code;
    if ( function_exists( 'wc_format_coupon_code' ) ) {
        return wc_format_coupon_code( $code );
    }
    // Fallback – keep it lowercase and dash-safe similar to Woo.
    $code = sanitize_title( $code );
    return strtolower( $code );
}