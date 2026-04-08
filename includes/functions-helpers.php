<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Normalize/format a coupon code the same way WooCommerce does for storage/lookup.
 * Falls back to sanitize_title if WooCommerce helper isn't available.
 */
/**
 * SKUs excluded from stock zeroing / out-of-stock marking.
 * These products keep their current stock even when absent from ERP.
 *
 * @return string[]
 */
function erp_sync_excluded_skus(): array {
    return [
        'ART-GIFT-3496',
    ];
}

function erp_sync_format_code( string $code ): string {
    if ( function_exists( 'wc_format_coupon_code' ) ) {
        return wc_format_coupon_code( $code );
    }
    // Fallback – keep it lowercase and dash-safe similar to Woo.
    $code = sanitize_title( $code );
    return strtolower( $code );
}