<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Normalize/format a coupon code the same way WooCommerce does for storage/lookup.
 * Falls back to sanitize_title if WooCommerce helper isn't available.
 */
/**
 * Option keys backing the admin-configurable exclusion lists.
 * Excluded products/categories are skipped during EVERY sync path (auto cron,
 * manual button, orphan cleanup, cart re-check) — they keep their stock/price
 * even when their SKU is absent from the ERP feed.
 */
const ERP_SYNC_OPTION_EXCLUDED_SKUS    = 'erp_sync_excluded_sku_list';
const ERP_SYNC_OPTION_EXCLUDED_CAT_IDS = 'erp_sync_excluded_cat_ids';

/**
 * SKUs excluded from stock zeroing / out-of-stock marking.
 * These products keep their current stock even when absent from ERP.
 *
 * Admin-configurable via Settings → Exclusions. When the option has never been
 * saved we fall back to the legacy hardcoded gift-card SKU so existing
 * protection is preserved on upgrade.
 *
 * @return string[]
 */
function erp_sync_excluded_skus(): array {
    $opt = get_option( ERP_SYNC_OPTION_EXCLUDED_SKUS, null );

    // Never configured → preserve the legacy hardcoded default (gift card).
    if ( null === $opt ) {
        return [ 'ART-GIFT-3496' ];
    }

    if ( ! is_array( $opt ) ) {
        $opt = [];
    }

    $opt = array_filter(
        array_map( 'strval', $opt ),
        static function ( $sku ) {
            return '' !== trim( $sku );
        }
    );

    return array_values( array_unique( array_map( 'trim', $opt ) ) );
}

/**
 * Product category term IDs excluded from stock zeroing / sync.
 * Excluding a parent category also excludes all of its descendants.
 *
 * @return int[]
 */
function erp_sync_excluded_category_ids(): array {
    $opt = get_option( ERP_SYNC_OPTION_EXCLUDED_CAT_IDS, [] );

    if ( ! is_array( $opt ) ) {
        $opt = [];
    }

    return array_values( array_unique( array_filter( array_map( 'intval', $opt ) ) ) );
}

/**
 * Whether a product is excluded from ERP sync entirely.
 *
 * True when the product's SKU is in the excluded-SKU list, OR the product
 * belongs to (or descends from) an excluded product category. For variations,
 * the parent product's SKU and categories are used.
 */
function erp_sync_is_product_excluded( \WC_Product $product ): bool {
    // ----- SKU match -----
    $sku = (string) $product->get_sku();
    if ( '' !== $sku && in_array( $sku, erp_sync_excluded_skus(), true ) ) {
        return true;
    }

    // ----- Category match -----
    $excluded_cats = erp_sync_excluded_category_ids();
    if ( empty( $excluded_cats ) ) {
        return false;
    }

    // Variations carry no own categories — resolve to the parent product.
    $parent_id = $product->get_parent_id();
    $check_id  = $parent_id ? $parent_id : $product->get_id();

    $terms = get_the_terms( $check_id, 'product_cat' );
    if ( ! is_array( $terms ) || empty( $terms ) ) {
        return false;
    }

    $excluded = array_flip( $excluded_cats );
    foreach ( $terms as $term ) {
        $tid = (int) $term->term_id;
        if ( isset( $excluded[ $tid ] ) ) {
            return true;
        }
        // Excluding a parent category cascades to its descendants.
        foreach ( get_ancestors( $tid, 'product_cat' ) as $ancestor_id ) {
            if ( isset( $excluded[ (int) $ancestor_id ] ) ) {
                return true;
            }
        }
    }

    return false;
}

function erp_sync_format_code( string $code ): string {
    if ( function_exists( 'wc_format_coupon_code' ) ) {
        return wc_format_coupon_code( $code );
    }
    // Fallback – keep it lowercase and dash-safe similar to Woo.
    $code = sanitize_title( $code );
    return strtolower( $code );
}