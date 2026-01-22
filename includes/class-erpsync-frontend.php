<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Frontend Class
 *
 * Handles display of per-branch stock information on product pages
 * and provides a shortcode for flexible placement.
 *
 * @package ERPSync
 * @since 1.3.0
 */
class Frontend {

    /**
     * Initialize frontend hooks.
     */
    public static function init(): void {
        // Display branch stock on single product pages
        add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'display_branch_stock' ], 25 );

        // Register shortcode
        add_shortcode( 'erp_branch_stock', [ __CLASS__, 'shortcode_branch_stock' ] );

        // Enqueue frontend styles
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_styles' ] );
    }

    /**
     * Enqueue frontend styles.
     */
    public static function enqueue_styles(): void {
        if ( ! is_product() ) {
            return;
        }

        // Inline minimal CSS for branch stock display
        wp_register_style( 'erp-sync-frontend', false );
        wp_enqueue_style( 'erp-sync-frontend' );
        wp_add_inline_style( 'erp-sync-frontend', self::get_inline_css() );
    }

    /**
     * Get inline CSS for branch stock display.
     *
     * @return string CSS styles.
     */
    private static function get_inline_css(): string {
        return '
            .erp-sync-branch-accordion {
                margin: 15px 0;
                padding: 10px 0;
            }
            .erp-sync-branch-summary {
                cursor: pointer;
                font-weight: bold;
                margin-bottom: 10px;
                outline: none;
                list-style: revert;
            }
            .erp-sync-branch-summary::-webkit-details-marker {
                display: initial;
            }
            .erp-sync-branch-stock-list {
                list-style: none;
                margin: 0;
                padding: 0;
            }
            .erp-sync-branch-stock-item {
                display: flex;
                justify-content: space-between;
                padding: 5px 0;
                border-bottom: 1px solid #eee;
            }
            .erp-sync-branch-stock-item:last-child {
                border-bottom: none;
            }
            .erp-sync-branch-name {
                font-weight: 500;
            }
            .erp-sync-branch-qty {
                color: #666;
            }
            .erp-sync-branch-qty-value {
                font-weight: 600;
            }
        ';
    }

    /**
     * Display branch stock on single product page.
     *
     * Hooked to woocommerce_single_product_summary at priority 25
     * (after price, before add to cart button).
     */
    public static function display_branch_stock(): void {
        global $product;

        if ( ! $product instanceof \WC_Product ) {
            return;
        }

        $html = self::get_branch_stock_html( $product->get_id() );
        
        if ( ! empty( $html ) ) {
            echo $html;
        }
    }

    /**
     * Shortcode handler for [erp_branch_stock].
     *
     * Usage: [erp_branch_stock] - displays stock for current product
     * Usage: [erp_branch_stock id="123"] - displays stock for product ID 123
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public static function shortcode_branch_stock( $atts ): string {
        $atts = shortcode_atts( [
            'id' => 0,
        ], $atts, 'erp_branch_stock' );

        $product_id = absint( $atts['id'] );

        // If no ID specified, try to get current product
        if ( ! $product_id ) {
            global $product;
            if ( $product instanceof \WC_Product ) {
                $product_id = $product->get_id();
            }
        }

        if ( ! $product_id ) {
            return '';
        }

        return self::get_branch_stock_html( $product_id );
    }

    /**
     * Get branch stock HTML for a product.
     *
     * @param int $product_id Product ID.
     * @return string HTML output.
     */
    public static function get_branch_stock_html( int $product_id ): string {
        // Get warehouse data from product meta
        $warehouses = get_post_meta( $product_id, '_erp_sync_warehouse_data', true );

        if ( empty( $warehouses ) || ! is_array( $warehouses ) ) {
            return '';
        }

        // Get branch settings (aliases, exclusions)
        $branch_settings = get_option( Product_Service::OPTION_BRANCH_SETTINGS, [] );
        if ( ! is_array( $branch_settings ) ) {
            $branch_settings = [];
        }

        // Build list of branches to display
        $display_items = [];

        foreach ( $warehouses as $wh ) {
            $location = $wh['Location'] ?? '';
            $quantity = isset( $wh['Quantity'] ) ? (float) $wh['Quantity'] : 0;

            // Skip if no location name
            if ( empty( $location ) ) {
                continue;
            }

            // Skip if quantity is zero or negative
            if ( $quantity <= 0 ) {
                continue;
            }

            // Check if this branch is excluded
            $settings = $branch_settings[ $location ] ?? [];
            if ( ! empty( $settings['excluded'] ) ) {
                continue;
            }

            // Get display name (alias) or use original name
            $display_name = ! empty( $settings['alias'] ) ? $settings['alias'] : $location;

            $display_items[] = [
                'name'     => esc_html( $display_name ),
                'quantity' => (int) $quantity,
            ];
        }

        // Return empty if no items to display
        if ( empty( $display_items ) ) {
            return '';
        }

        // Build HTML
        ob_start();
        ?>
        <details class="erp-sync-branch-accordion">
            <summary class="erp-sync-branch-summary"><?php esc_html_e( 'Availability by Branch', 'erp-sync' ); ?></summary>
            <ul class="erp-sync-branch-stock-list">
                <?php foreach ( $display_items as $item ) : ?>
                    <li class="erp-sync-branch-stock-item">
                        <span class="erp-sync-branch-name"><?php echo esc_html( $item['name'] ); ?></span>
                        <span class="erp-sync-branch-qty">
                            <span class="erp-sync-branch-qty-value"><?php echo esc_html( $item['quantity'] ); ?></span>
                            <?php esc_html_e( 'in stock', 'erp-sync' ); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php
        return ob_get_clean();
    }
}
