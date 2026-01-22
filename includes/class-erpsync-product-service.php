<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Product Service Class
 * 
 * Handles the mapping, creation, and updating of WooCommerce products
 * and their attributes based on the IBS/1C data structure.
 * 
 * @package ERPSync
 * @since 1.3.0
 */
class Product_Service {

    /**
     * Attribute mapping from IBS data fields to WooCommerce taxonomy slugs.
     */
    private const ATTRIBUTE_MAPPING = [
        'Brand'      => 'pa_brand',
        'Color'      => 'pa_color',
        'Size'       => 'pa_size',
        'Mechanism'  => 'pa_mechanism',
        'Bracelet'   => 'pa_bracelet',
        'gender'     => 'pa_gender',
        'Bijouterie' => 'pa_bijouterie',
    ];

    /**
     * Attribute labels for display purposes.
     */
    private const ATTRIBUTE_LABELS = [
        'pa_brand'      => 'Brand',
        'pa_color'      => 'Color',
        'pa_size'       => 'Size',
        'pa_mechanism'  => 'Mechanism',
        'pa_bracelet'   => 'Bracelet',
        'pa_gender'     => 'Gender',
        'pa_bijouterie' => 'Bijouterie',
    ];

    /**
     * Cache for attribute taxonomy existence checks.
     *
     * @var array<string, bool>
     */
    private array $attribute_taxonomy_cache = [];

    /**
     * Cache for term existence checks.
     *
     * @var array<string, int>
     */
    private array $term_cache = [];

    /**
     * Ensure a product attribute exists and return the term data for assignment.
     *
     * Uses Global Attributes (Taxonomies) for filtering capability.
     * Creates the term if it doesn't exist in the taxonomy.
     *
     * @param string $slug  Attribute taxonomy slug (e.g., 'pa_brand').
     * @param string $label Attribute label for display (e.g., 'Brand').
     * @param string $value The attribute value/term name (e.g., 'Rolex').
     * @return array{term_id: int, name: string}|null Term data or null if failed.
     */
    public function ensure_attribute( string $slug, string $label, string $value ): ?array {
        // Sanitize inputs
        $slug  = sanitize_key( $slug );
        $label = sanitize_text_field( $label );
        $value = sanitize_text_field( trim( $value ) );

        // Skip empty values
        if ( empty( $value ) ) {
            return null;
        }

        // Check if taxonomy exists (with caching)
        if ( ! $this->taxonomy_exists( $slug ) ) {
            // Register the attribute taxonomy if it doesn't exist
            if ( ! $this->register_attribute_taxonomy( $slug, $label ) ) {
                Logger::instance()->log( 'Failed to register attribute taxonomy', [
                    'slug'  => $slug,
                    'label' => $label,
                ] );
                return null;
            }
        }

        // Check if term exists (with caching)
        $cache_key = $slug . ':' . $value;
        if ( isset( $this->term_cache[ $cache_key ] ) ) {
            return [
                'term_id' => $this->term_cache[ $cache_key ],
                'name'    => $value,
            ];
        }

        // Try to get existing term
        $term = get_term_by( 'name', $value, $slug );

        if ( $term instanceof \WP_Term ) {
            $this->term_cache[ $cache_key ] = $term->term_id;
            return [
                'term_id' => $term->term_id,
                'name'    => $term->name,
            ];
        }

        // Create the term if it doesn't exist
        $result = wp_insert_term( $value, $slug );

        if ( is_wp_error( $result ) ) {
            // Check if term already exists (race condition)
            if ( $result->get_error_code() === 'term_exists' ) {
                $existing_term_id = $result->get_error_data( 'term_exists' );
                if ( is_numeric( $existing_term_id ) ) {
                    $this->term_cache[ $cache_key ] = (int) $existing_term_id;
                    return [
                        'term_id' => (int) $existing_term_id,
                        'name'    => $value,
                    ];
                }
            }

            Logger::instance()->log( 'Failed to create attribute term', [
                'slug'  => $slug,
                'value' => $value,
                'error' => $result->get_error_message(),
            ] );
            return null;
        }

        $this->term_cache[ $cache_key ] = (int) $result['term_id'];

        return [
            'term_id' => (int) $result['term_id'],
            'name'    => $value,
        ];
    }

    /**
     * Check if a taxonomy exists (with caching).
     *
     * @param string $slug Taxonomy slug.
     * @return bool True if taxonomy exists.
     */
    private function taxonomy_exists( string $slug ): bool {
        if ( isset( $this->attribute_taxonomy_cache[ $slug ] ) ) {
            return $this->attribute_taxonomy_cache[ $slug ];
        }

        $exists = taxonomy_exists( $slug );
        $this->attribute_taxonomy_cache[ $slug ] = $exists;

        return $exists;
    }

    /**
     * Register a WooCommerce product attribute taxonomy.
     *
     * @param string $slug  Attribute taxonomy slug (e.g., 'pa_brand').
     * @param string $label Attribute label for display.
     * @return bool True on success, false on failure.
     */
    private function register_attribute_taxonomy( string $slug, string $label ): bool {
        global $wpdb;

        // Extract attribute name from slug (remove 'pa_' prefix)
        $attribute_name = str_replace( 'pa_', '', $slug );

        // Check if attribute already exists in WooCommerce attributes table
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s",
                $attribute_name
            )
        );

        if ( $existing ) {
            // Attribute exists in DB, just register the taxonomy
            $this->register_taxonomy_for_attribute( $slug, $label );
            $this->attribute_taxonomy_cache[ $slug ] = true;
            return true;
        }

        // Insert new attribute into WooCommerce attributes table
        $result = $wpdb->insert(
            $wpdb->prefix . 'woocommerce_attribute_taxonomies',
            [
                'attribute_name'    => $attribute_name,
                'attribute_label'   => $label,
                'attribute_type'    => 'select',
                'attribute_orderby' => 'menu_order',
                'attribute_public'  => 0,
            ],
            [ '%s', '%s', '%s', '%s', '%d' ]
        );

        if ( $result === false ) {
            Logger::instance()->log( 'Failed to insert attribute into WooCommerce table', [
                'slug'  => $slug,
                'label' => $label,
                'error' => $wpdb->last_error,
            ] );
            return false;
        }

        // Clear WooCommerce transient cache for attributes
        delete_transient( 'wc_attribute_taxonomies' );

        // Flush rewrite rules on next load
        update_option( 'woocommerce_queue_flush_rewrite_rules', 'yes' );

        // Register the taxonomy immediately for current request
        $this->register_taxonomy_for_attribute( $slug, $label );
        $this->attribute_taxonomy_cache[ $slug ] = true;

        Logger::instance()->log( 'Registered new attribute taxonomy', [
            'slug'  => $slug,
            'label' => $label,
        ] );

        return true;
    }

    /**
     * Register the taxonomy for a WooCommerce attribute.
     *
     * @param string $slug  Taxonomy slug.
     * @param string $label Taxonomy label.
     */
    private function register_taxonomy_for_attribute( string $slug, string $label ): void {
        if ( taxonomy_exists( $slug ) ) {
            return;
        }

        register_taxonomy(
            $slug,
            [ 'product' ],
            [
                'hierarchical' => false,
                'labels'       => [
                    'name' => $label,
                ],
                'show_ui'      => false,
                'query_var'    => true,
                'rewrite'      => false,
            ]
        );
    }

    /**
     * Synchronize a batch of product catalog data.
     *
     * Creates new products or updates existing ones based on VendorCode (SKU).
     * Uses Source of Truth strategy: API data overwrites existing data.
     *
     * @param array $rows Array of product catalog rows from IBS API.
     * @return array{created: int, updated: int, errors: int, total: int} Sync statistics.
     */
    public function sync_catalog_batch( array $rows ): array {
        $stats = [
            'created' => 0,
            'updated' => 0,
            'errors'  => 0,
            'total'   => count( $rows ),
        ];

        foreach ( $rows as $row ) {
            $sku = sanitize_text_field( trim( $row['VendorCode'] ?? '' ) );

            // Skip rows without SKU
            if ( empty( $sku ) ) {
                Logger::instance()->log( 'Skipping product row without VendorCode', [
                    'row' => $row,
                ] );
                $stats['errors']++;
                continue;
            }

            try {
                // Check if product exists by SKU
                $product_id = wc_get_product_id_by_sku( $sku );

                if ( $product_id ) {
                    // Update existing product
                    $product = wc_get_product( $product_id );
                    if ( ! $product ) {
                        Logger::instance()->log( 'Failed to load product for update', [
                            'sku'        => $sku,
                            'product_id' => $product_id,
                        ] );
                        $stats['errors']++;
                        continue;
                    }
                    $is_new = false;
                } else {
                    // Create new product
                    $product = new \WC_Product_Simple();
                    $is_new  = true;
                }

                // Set product data
                $this->set_product_catalog_data( $product, $row );

                // Save product
                $saved_id = $product->save();

                if ( ! $saved_id ) {
                    Logger::instance()->log( 'Failed to save product', [
                        'sku'    => $sku,
                        'is_new' => $is_new,
                    ] );
                    $stats['errors']++;
                    continue;
                }

                // Update stats
                if ( $is_new ) {
                    $stats['created']++;
                } else {
                    $stats['updated']++;
                }

            } catch ( \Throwable $e ) {
                Logger::instance()->log( 'Exception during product sync', [
                    'sku'   => $sku,
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                ] );
                $stats['errors']++;
            }
        }

        // Log summary
        Logger::instance()->log( 'Catalog batch sync completed', [
            'created' => $stats['created'],
            'updated' => $stats['updated'],
            'errors'  => $stats['errors'],
            'total'   => $stats['total'],
        ] );

        return $stats;
    }

    /**
     * Set product catalog data from IBS row.
     *
     * @param \WC_Product $product Product object.
     * @param array       $row     Product data row from IBS API.
     */
    private function set_product_catalog_data( \WC_Product $product, array $row ): void {
        // Set basic product data
        $product->set_name( sanitize_text_field( $row['ProductName'] ?? '' ) );
        $product->set_sku( sanitize_text_field( $row['VendorCode'] ?? '' ) );
        $product->set_status( 'publish' );

        // Mark as ERP-managed product
        $product->update_meta_data( '_erp_sync_managed', 1 );
        $product->update_meta_data( '_erp_sync_synced_at', current_time( 'mysql' ) );

        // Process and set attributes
        $attributes = $this->build_product_attributes( $row );
        if ( ! empty( $attributes ) ) {
            $product->set_attributes( $attributes );
        }
    }

    /**
     * Build WC_Product_Attribute array from IBS row data.
     *
     * @param array $row Product data row from IBS API.
     * @return \WC_Product_Attribute[] Array of product attributes.
     */
    private function build_product_attributes( array $row ): array {
        $attributes = [];

        foreach ( self::ATTRIBUTE_MAPPING as $field_name => $taxonomy_slug ) {
            $value = $row[ $field_name ] ?? '';

            // Skip empty values (Source of Truth: don't create empty attributes)
            if ( empty( trim( (string) $value ) ) ) {
                continue;
            }

            // Get the attribute label
            $label = self::ATTRIBUTE_LABELS[ $taxonomy_slug ] ?? $field_name;

            // Ensure the attribute term exists
            $term_data = $this->ensure_attribute( $taxonomy_slug, $label, (string) $value );

            if ( $term_data === null ) {
                continue;
            }

            // Create WC_Product_Attribute object
            $attribute = new \WC_Product_Attribute();
            
            // Get attribute taxonomy ID - may return 0 for newly created attributes
            $attribute_id = wc_attribute_taxonomy_id_by_name( $taxonomy_slug );
            
            // If attribute ID is 0, try to get it from the database directly
            if ( ! $attribute_id ) {
                $attribute_name = str_replace( 'pa_', '', $taxonomy_slug );
                global $wpdb;
                $attribute_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s",
                        $attribute_name
                    )
                );
            }
            
            $attribute->set_id( $attribute_id );
            $attribute->set_name( $taxonomy_slug );
            $attribute->set_options( [ $term_data['term_id'] ] );
            $attribute->set_visible( true );
            $attribute->set_variation( false );

            $attributes[ $taxonomy_slug ] = $attribute;
        }

        return $attributes;
    }

    /**
     * Synchronize a batch of product stock data.
     *
     * Updates stock quantities and prices for existing products.
     * Optimized for frequent execution - only updates stock-related fields.
     *
     * @param array $rows Array of stock rows from IBS API.
     * @return array{updated: int, skipped: int, errors: int, total: int} Sync statistics.
     */
    public function sync_stock_batch( array $rows ): array {
        $stats = [
            'updated' => 0,
            'skipped' => 0,
            'errors'  => 0,
            'total'   => count( $rows ),
        ];

        foreach ( $rows as $row ) {
            $sku = sanitize_text_field( trim( $row['VendorCode'] ?? '' ) );

            // Skip rows without SKU
            if ( empty( $sku ) ) {
                $stats['errors']++;
                continue;
            }

            try {
                // Find product by SKU
                $product_id = wc_get_product_id_by_sku( $sku );

                if ( ! $product_id ) {
                    Logger::instance()->log( 'Stock update: Product not found for SKU', [
                        'sku' => $sku,
                    ] );
                    $stats['skipped']++;
                    continue;
                }

                $product = wc_get_product( $product_id );
                if ( ! $product ) {
                    Logger::instance()->log( 'Stock update: Failed to load product', [
                        'sku'        => $sku,
                        'product_id' => $product_id,
                    ] );
                    $stats['errors']++;
                    continue;
                }

                // Update stock and price data only
                $this->set_product_stock_data( $product, $row );

                // Save product
                $product->save();
                $stats['updated']++;

            } catch ( \Throwable $e ) {
                Logger::instance()->log( 'Exception during stock update', [
                    'sku'   => $sku,
                    'error' => $e->getMessage(),
                ] );
                $stats['errors']++;
            }
        }

        // Log summary
        Logger::instance()->log( 'Stock batch sync completed', [
            'updated' => $stats['updated'],
            'skipped' => $stats['skipped'],
            'errors'  => $stats['errors'],
            'total'   => $stats['total'],
        ] );

        return $stats;
    }

    /**
     * Set product stock and price data from IBS row.
     *
     * Performance-optimized: only updates stock-related meta.
     *
     * @param \WC_Product $product Product object.
     * @param array       $row     Stock data row from IBS API.
     */
    private function set_product_stock_data( \WC_Product $product, array $row ): void {
        // Parse price (supports European decimal format with comma)
        // Only set price if we have a valid non-empty value from the API
        $raw_price = $row['Price'] ?? null;
        if ( $raw_price !== null && $raw_price !== '' ) {
            $price = $this->parse_numeric_value( $raw_price );
            // Only set price if it was successfully parsed to a positive value
            // Price of 0 is intentionally not set to avoid overwriting valid prices with invalid data
            if ( $price > 0 ) {
                $product->set_regular_price( (string) $price );
            }
        }

        // Parse quantity
        $quantity = (int) $this->parse_numeric_value( $row['Quantity'] ?? 0 );

        // Enable stock management
        $product->set_manage_stock( true );
        $product->set_stock_quantity( $quantity );

        // Set stock status based on quantity
        if ( $quantity > 0 ) {
            $product->set_stock_status( 'instock' );
        } else {
            $product->set_stock_status( 'outofstock' );
        }

        // Update sync timestamp
        $product->update_meta_data( '_erp_sync_stock_updated_at', current_time( 'mysql' ) );
    }

    /**
     * Parse a numeric value, handling European decimal format.
     *
     * @param mixed $value Input value.
     * @return float Parsed numeric value.
     */
    private function parse_numeric_value( mixed $value ): float {
        if ( is_numeric( $value ) ) {
            return (float) $value;
        }

        if ( is_string( $value ) ) {
            // Handle European format (comma as decimal separator)
            $value = str_replace( ',', '.', trim( $value ) );
            if ( is_numeric( $value ) ) {
                return (float) $value;
            }
        }

        return 0.0;
    }

    /**
     * Clear internal caches.
     *
     * Call this after processing large batches to free memory.
     */
    public function clear_cache(): void {
        $this->attribute_taxonomy_cache = [];
        $this->term_cache = [];
    }
}
