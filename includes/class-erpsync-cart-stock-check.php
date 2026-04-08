<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cart Stock Check
 *
 * Re-verifies product stock against the ERP (1C) when a customer
 * adds a product to the cart, ensuring stale WooCommerce stock
 * data doesn't allow out-of-stock items to be purchased.
 *
 * @package ERPSync
 * @since 1.3.0
 */
class Cart_Stock_Check {

    /**
     * Minimum seconds between ERP checks for the same product.
     * Prevents hammering the SOAP endpoint on rapid add-to-cart clicks.
     */
    private const THROTTLE_SECONDS = 30;

    /**
     * Initialize hooks.
     */
    public static function init(): void {
        add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate_stock_from_erp' ], 10, 3 );
    }

    /**
     * Validate product stock against ERP before allowing add-to-cart.
     *
     * @param bool $passed   Whether validation passed so far.
     * @param int  $product_id Product ID being added.
     * @param int  $quantity   Quantity being added.
     * @return bool False to block add-to-cart with a notice.
     */
    public static function validate_stock_from_erp( bool $passed, int $product_id, int $quantity ): bool {
        if ( ! $passed ) {
            return false;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return $passed;
        }

        // Only check ERP-managed products
        $is_erp_managed = $product->get_meta( '_erp_sync_managed', true );
        if ( ! $is_erp_managed ) {
            return $passed;
        }

        $sku = $product->get_sku();
        if ( empty( $sku ) ) {
            return $passed;
        }

        // Skip excluded SKUs — never mark these out of stock
        if ( in_array( $sku, erp_sync_excluded_skus(), true ) ) {
            return $passed;
        }

        // Throttle: skip if we checked this product recently
        $transient_key = 'erp_cart_check_' . $product_id;
        $last_check = get_transient( $transient_key );
        if ( $last_check !== false ) {
            // Use the cached stock status from the last check
            if ( $last_check === 'outofstock' ) {
                wc_add_notice(
                    __( 'Sorry, this product is currently out of stock.', 'erp-sync' ),
                    'error'
                );
                return false;
            }
            return $passed;
        }

        try {
            $api  = new API_Client();
            $rows = $api->fetch_products_stock( $sku );

            if ( empty( $rows ) ) {
                // Product not found in ERP — out of stock
                self::mark_out_of_stock( $product );
                set_transient( $transient_key, 'outofstock', self::THROTTLE_SECONDS );

                wc_add_notice(
                    __( 'Sorry, this product is currently out of stock.', 'erp-sync' ),
                    'error'
                );
                return false;
            }

            // Process the stock data through the normal sync pipeline
            $sync    = new Sync_Service( $api );
            $session = uniqid( 'cart_check_', true );
            $sync->get_product_service()->sync_stock_batch( $rows, $session );

            // Re-read the product after sync updated it
            $product = wc_get_product( $product_id );
            $stock   = (int) $product->get_stock_quantity();

            if ( $stock <= 0 ) {
                set_transient( $transient_key, 'outofstock', self::THROTTLE_SECONDS );
                wc_add_notice(
                    __( 'Sorry, this product is currently out of stock.', 'erp-sync' ),
                    'error'
                );
                return false;
            }

            // Product is in stock
            set_transient( $transient_key, 'instock', self::THROTTLE_SECONDS );
            return $passed;

        } catch ( \Throwable $e ) {
            // On ERP failure, allow the add-to-cart (don't block sales due to API issues)
            Logger::instance()->log( 'Cart stock check failed, allowing add-to-cart', [
                'product_id' => $product_id,
                'sku'        => $sku,
                'error'      => $e->getMessage(),
            ] );
            return $passed;
        }
    }

    /**
     * Mark a product as out of stock and clear warehouse data.
     *
     * @param \WC_Product $product
     */
    private static function mark_out_of_stock( \WC_Product $product ): void {
        $product->set_manage_stock( true );
        $product->set_stock_quantity( 0 );
        $product->set_stock_status( 'outofstock' );
        $product->update_meta_data( '_erp_sync_warehouse_data', [] );
        $product->update_meta_data( '_erp_sync_last_update', current_time( 'mysql' ) );
        $product->update_meta_data( '_erp_sync_zeroed_reason', 'not_found_in_erp' );
        $product->save();

        wp_set_object_terms( $product->get_id(), [], Product_Service::TAXONOMY_BRANCH );
    }
}
