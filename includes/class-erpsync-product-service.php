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
     * Taxonomy slug for branch availability.
     */
    public const TAXONOMY_BRANCH = 'erp_branch';

    /**
     * Initialize the Product_Service hooks.
     */
    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_branch_taxonomy' ], 5 );
    }

    /**
     * Register the erp_branch taxonomy for filtering products by branch.
     *
     * Public taxonomy visible in widgets for shop page filtering.
     */
    public static function register_branch_taxonomy(): void {
        if ( taxonomy_exists( self::TAXONOMY_BRANCH ) ) {
            return;
        }

        $labels = [
            'name'                       => _x( 'Branches', 'taxonomy general name', 'erp-sync' ),
            'singular_name'              => _x( 'Branch', 'taxonomy singular name', 'erp-sync' ),
            'search_items'               => __( 'Search Branches', 'erp-sync' ),
            'all_items'                  => __( 'All Branches', 'erp-sync' ),
            'parent_item'                => null,
            'parent_item_colon'          => null,
            'edit_item'                  => __( 'Edit Branch', 'erp-sync' ),
            'update_item'                => __( 'Update Branch', 'erp-sync' ),
            'add_new_item'               => __( 'Add New Branch', 'erp-sync' ),
            'new_item_name'              => __( 'New Branch Name', 'erp-sync' ),
            'separate_items_with_commas' => __( 'Separate branches with commas', 'erp-sync' ),
            'add_or_remove_items'        => __( 'Add or remove branches', 'erp-sync' ),
            'choose_from_most_used'      => __( 'Choose from the most used branches', 'erp-sync' ),
            'not_found'                  => __( 'No branches found.', 'erp-sync' ),
            'menu_name'                  => __( 'Branch Availability', 'erp-sync' ),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => true,
            'show_tagcloud'      => true,
            'show_in_quick_edit' => true,
            'show_admin_column'  => true,
            'hierarchical'       => false,
            'rewrite'            => [ 'slug' => 'branch', 'with_front' => false ],
            'query_var'          => true,
            'show_in_rest'       => true,
        ];

        register_taxonomy( self::TAXONOMY_BRANCH, [ 'product' ], $args );
    }

    /**
     * Option key for storing attribute mapping in database.
     */
    public const OPTION_ATTRIBUTE_MAPPING = 'erp_sync_attribute_mapping';

    /**
     * Default attribute mapping from IBS data fields to WooCommerce taxonomy slugs.
     * Used as fallback when user hasn't configured custom mappings.
     */
    private const DEFAULT_ATTRIBUTE_MAPPING = [
        'Brand'      => 'pa_brand',
        'Color'      => 'pa_color',
        'Size'       => 'pa_size',
        'Mechanism'  => 'pa_mechanism',
        'Bracelet'   => 'pa_bracelet',
        'gender'     => 'pa_gender',
        'Bijouterie' => 'pa_bijouterie',
    ];

    /**
     * Maximum number of not-found SKUs to include in a single log entry.
     * Used in sync_stock_batch to prevent oversized log messages.
     */
    private const MAX_LOGGED_SKUS = 20;

    /**
     * Batch size for orphan cleanup processing.
     * Controls how often memory caches are cleared during orphan zeroing.
     */
    private const ORPHAN_CLEANUP_BATCH_SIZE = 50;

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
     * Cached attribute mapping (populated once per request).
     *
     * @var array<string, string>|null
     */
    private ?array $attribute_mapping_cache = null;

    /**
     * Retrieve the attribute mapping configuration.
     *
     * Merges default mapping with user-configured database settings.
     * User settings take precedence over defaults.
     *
     * @return array<string, string> Mapping of 1C field names to WooCommerce taxonomy slugs.
     */
    public function get_attribute_mapping(): array {
        // Return cached mapping if available
        if ( $this->attribute_mapping_cache !== null ) {
            return $this->attribute_mapping_cache;
        }

        // Get user-configured mapping from database
        $db_mapping = get_option( self::OPTION_ATTRIBUTE_MAPPING, [] );

        // Ensure it's an array
        if ( ! is_array( $db_mapping ) ) {
            $db_mapping = [];
        }

        // Merge defaults with database settings (database takes precedence)
        $this->attribute_mapping_cache = array_merge( self::DEFAULT_ATTRIBUTE_MAPPING, $db_mapping );

        return $this->attribute_mapping_cache;
    }

    /**
     * Generate a display label from a taxonomy slug.
     *
     * Converts 'pa_some_attribute' to 'Some Attribute'.
     *
     * @param string $taxonomy_slug The taxonomy slug (e.g., 'pa_brand').
     * @param string $field_name    The original field name from 1C data as fallback.
     * @return string Human-readable label.
     */
    private function get_attribute_label( string $taxonomy_slug, string $field_name ): string {
        // Remove 'pa_' prefix and convert to title case
        $label = str_replace( 'pa_', '', $taxonomy_slug );
        $label = str_replace( [ '_', '-' ], ' ', $label );
        $label = ucwords( $label );

        // If the result is empty, use the field name
        if ( empty( trim( $label ) ) ) {
            return ucfirst( $field_name );
        }

        return $label;
    }

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
     * @param array  $rows       Array of product catalog rows from IBS API.
     * @param string $session_id Optional unique session identifier for tracking sync.
     * @return array{created: int, updated: int, errors: int, total: int} Sync statistics.
     */
    public function sync_catalog_batch( array $rows, string $session_id = '' ): array {
        $stats = [
            'created' => 0,
            'updated' => 0,
            'errors'  => 0,
            'total'   => count( $rows ),
        ];

        // Load attribute mapping once at the start of the batch
        $attribute_mapping = $this->get_attribute_mapping();

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
                $this->set_product_catalog_data( $product, $row, $attribute_mapping, $session_id );

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
            'created'    => $stats['created'],
            'updated'    => $stats['updated'],
            'errors'     => $stats['errors'],
            'total'      => $stats['total'],
            'session_id' => $session_id,
        ] );

        return $stats;
    }

    /**
     * Set product catalog data from IBS row.
     *
     * @param \WC_Product $product           Product object.
     * @param array       $row               Product data row from IBS API.
     * @param array       $attribute_mapping Attribute mapping configuration.
     * @param string      $session_id        Optional unique session identifier for tracking sync.
     */
    private function set_product_catalog_data( \WC_Product $product, array $row, array $attribute_mapping, string $session_id = '' ): void {
        // Set basic product data
        $product->set_name( sanitize_text_field( $row['ProductName'] ?? '' ) );
        $product->set_sku( sanitize_text_field( $row['VendorCode'] ?? '' ) );
        $product->set_status( 'publish' );

        // Mark as ERP-managed product
        $product->update_meta_data( '_erp_sync_managed', 1 );
        $product->update_meta_data( '_erp_sync_synced_at', current_time( 'mysql' ) );

        // Update session ID if provided (marks product as "touched" in this sync session)
        if ( ! empty( $session_id ) ) {
            $product->update_meta_data( '_erp_sync_session_id', $session_id );
        }

        // Process and set attributes
        $attributes = $this->build_product_attributes( $row, $attribute_mapping );
        if ( ! empty( $attributes ) ) {
            $product->set_attributes( $attributes );
        }
    }

    /**
     * Build WC_Product_Attribute array from IBS row data.
     *
     * Uses dynamic attribute mapping from database settings.
     *
     * @param array $row               Product data row from IBS API.
     * @param array $attribute_mapping Attribute mapping configuration.
     * @return \WC_Product_Attribute[] Array of product attributes.
     */
    private function build_product_attributes( array $row, array $attribute_mapping ): array {
        $attributes = [];

        foreach ( $attribute_mapping as $field_name => $taxonomy_slug ) {
            $value = $row[ $field_name ] ?? '';

            // Skip empty values (Source of Truth: don't create empty attributes)
            if ( empty( trim( (string) $value ) ) ) {
                continue;
            }

            // Generate label from taxonomy slug dynamically
            $label = $this->get_attribute_label( $taxonomy_slug, $field_name );

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
     * Option key for storing detected branch names.
     */
    public const OPTION_DETECTED_BRANCHES = 'erp_sync_detected_branches';

    /**
     * Option key for storing branch settings (alias, excluded).
     */
    public const OPTION_BRANCH_SETTINGS = 'erp_sync_branch_settings';

    /**
     * Warehouse locations to exclude from stock calculations.
     *
     * Products from these warehouses will be filtered out before
     * calculating the total stock. The global Quantity from the ERP
     * is ignored; instead, stock is recalculated from non-excluded warehouses.
     *
     * This is a system-level exclusion list for warehouses that should never
     * appear on the website (e.g., internal warehouses). For admin-configurable
     * branch exclusions, see OPTION_BRANCH_SETTINGS used in assign_branch_terms().
     *
     * Note: This constant is intentionally hard-coded for simplicity and
     * predictability. The client's requirement is to exclude a specific
     * internal warehouse that should never be shown to end users.
     *
     * @var array<string, bool> Warehouse location names as keys for O(1) lookup.
     */
    private const EXCLUDED_WAREHOUSE_LOCATIONS = [
        'ძირითადი საწყობი' => true,
    ];

    /**
     * Synchronize a batch of product stock data.
     *
     * Updates stock quantities and prices for existing products.
     * Optimized for frequent execution - only updates stock-related fields.
     * Also discovers and records unique branch/warehouse locations.
     *
     * @param array  $rows       Array of stock rows from IBS API.
     * @param string $session_id Optional unique session identifier for tracking sync.
     * @return array{updated: int, skipped: int, errors: int, total: int} Sync statistics.
     */
    public function sync_stock_batch( array $rows, string $session_id = '' ): array {
        $stats = [
            'updated' => 0,
            'skipped' => 0,
            'errors'  => 0,
            'total'   => count( $rows ),
        ];

        // Collect unique branch locations for discovery
        $discovered_locations = [];

        // Track SKUs not found to avoid repeated logging
        $not_found_skus = [];

        foreach ( $rows as $row ) {
            $sku = sanitize_text_field( trim( $row['VendorCode'] ?? '' ) );

            // Skip rows without SKU
            if ( empty( $sku ) ) {
                $stats['errors']++;
                continue;
            }

            // Collect branch locations from this row for discovery
            $warehouses = $row['_warehouses'] ?? [];
            foreach ( $warehouses as $wh ) {
                $location = $wh['Location'] ?? '';
                if ( ! empty( $location ) ) {
                    $discovered_locations[ $location ] = true;
                }
            }

            try {
                // Find product by SKU - uses WooCommerce's optimized lookup table
                $product_id = wc_get_product_id_by_sku( $sku );

                if ( ! $product_id ) {
                    // Collect not found SKUs for batch logging (reduces log spam)
                    $not_found_skus[] = $sku;
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
                // This method also updates _erp_sync_session_id meta
                $this->set_product_stock_data( $product, $row, $session_id );

                // Save product (persists all changes including session_id)
                $product->save();
                $stats['updated']++;

                // Clear individual product from memory to prevent buildup
                // This is safe because we're done with this product
                unset( $product );

            } catch ( \Throwable $e ) {
                Logger::instance()->log( 'Exception during stock update', [
                    'sku'   => $sku,
                    'error' => $e->getMessage(),
                ] );
                $stats['errors']++;
            }
        }

        // Log not found SKUs in a single entry (if any)
        if ( ! empty( $not_found_skus ) ) {
            Logger::instance()->log( 'Stock update: Products not found for SKUs', [
                'count'      => count( $not_found_skus ),
                'skus'       => array_slice( $not_found_skus, 0, self::MAX_LOGGED_SKUS ),
                'session_id' => $session_id,
            ] );
        }

        // Update detected branches if we found any new ones
        if ( ! empty( $discovered_locations ) ) {
            $this->update_detected_branches( array_keys( $discovered_locations ) );
        }

        // Log summary
        Logger::instance()->log( 'Stock batch sync completed', [
            'updated'            => $stats['updated'],
            'skipped'            => $stats['skipped'],
            'errors'             => $stats['errors'],
            'total'              => $stats['total'],
            'branches_discovered'=> count( $discovered_locations ),
            'session_id'         => $session_id,
        ] );

        return $stats;
    }

    /**
     * Update the global list of detected branch locations.
     *
     * Merges new locations into the existing list and saves if there are changes.
     *
     * @param array $new_locations Array of location names discovered in current sync.
     */
    private function update_detected_branches( array $new_locations ): void {
        $existing = get_option( self::OPTION_DETECTED_BRANCHES, [] );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }

        $merged = array_unique( array_merge( $existing, $new_locations ) );
        sort( $merged );

        // Only update if there are changes
        if ( $merged !== $existing ) {
            update_option( self::OPTION_DETECTED_BRANCHES, $merged );
            Logger::instance()->log( 'Detected branches updated', [
                'previous_count' => count( $existing ),
                'new_count'      => count( $merged ),
                'new_branches'   => array_diff( $merged, $existing ),
            ] );
        }
    }

    /**
     * Set product stock and price data from IBS row.
     *
     * Performance-optimized: only updates stock-related meta.
     * Also saves per-warehouse stock data for branch display.
     * Assigns branch taxonomy terms efficiently by comparing target vs current.
     * Logs changes to the audit log when values differ.
     *
     * IMPORTANT: This method implements warehouse exclusion logic.
     * The global Quantity from the ERP API is intentionally IGNORED.
     * Stock is recalculated by summing quantities from non-excluded warehouses only.
     * This ensures stock from internal/excluded warehouses (defined in
     * EXCLUDED_WAREHOUSE_LOCATIONS) does not appear on the website.
     *
     * @param \WC_Product $product    Product object.
     * @param array       $row        Stock data row from IBS API.
     * @param string      $session_id Optional unique session identifier for tracking sync.
     */
    private function set_product_stock_data( \WC_Product $product, array $row, string $session_id = '' ): void {
        // ========== CAPTURE OLD VALUES FOR COMPARISON ==========
        $old_regular_price = $product->get_regular_price();
        $old_sale_price    = $product->get_sale_price();
        $old_stock_qty     = $product->get_stock_quantity();
        $old_warehouses    = $product->get_meta( '_erp_sync_warehouse_data', true );
        
        if ( ! is_array( $old_warehouses ) ) {
            $old_warehouses = [];
        }

        // Parse price (supports European decimal format with comma)
        // Only set price if we have a valid non-empty value from the API
        $raw_price = $row['Price'] ?? null;
        $regular_price = 0.0;
        if ( $raw_price !== null && $raw_price !== '' ) {
            $regular_price = $this->parse_numeric_value( $raw_price );
            // Only set price if it was successfully parsed to a positive value
            // Price of 0 is intentionally not set to avoid overwriting valid prices with invalid data
            if ( $regular_price > 0 ) {
                $product->set_regular_price( (string) $regular_price );
            }
        }

        // Handle sale price from ERP
        $raw_sale_price = $row['SalesPrice'] ?? null;
        $sale_price = $this->parse_numeric_value( $raw_sale_price ?? 0 );

        if ( $sale_price > 0 && $regular_price > 0 && $sale_price < $regular_price ) {
            // Set sale price if valid and strictly lower than regular price
            $product->set_sale_price( (string) $sale_price );
        } else {
            // Remove sale price if empty, zero, or not lower than regular price
            // This ensures no stale discounts remain when ERP sends empty/zero
            $product->set_sale_price( '' );
            $sale_price = 0.0; // For comparison purposes
        }

        // ========== WAREHOUSE FILTERING & STOCK RECALCULATION ==========
        // Step 1: Get all warehouses from API
        $all_warehouses = $row['_warehouses'] ?? [];

        // Step 2: Filter out excluded warehouse locations
        // Only keep warehouses that are NOT in the exclusion list (O(1) lookup)
        $valid_warehouses = array_filter( $all_warehouses, function ( $wh ) {
            $location = $wh['Location'] ?? '';
            return ! isset( self::EXCLUDED_WAREHOUSE_LOCATIONS[ $location ] );
        } );

        // Re-index array to ensure clean numeric keys after filtering
        $valid_warehouses = array_values( $valid_warehouses );

        // Step 3: Recalculate quantity from valid warehouses only
        // The global $row['Quantity'] from the API is intentionally ignored because
        // it includes stock from excluded warehouses (e.g., internal warehouses)
        $quantity = 0;
        foreach ( $valid_warehouses as $wh ) {
            $quantity += (int) $this->parse_numeric_value( $wh['Quantity'] ?? 0 );
        }

        // Enable stock management
        $product->set_manage_stock( true );
        $product->set_stock_quantity( $quantity );

        // Set stock status based on recalculated quantity
        if ( $quantity > 0 ) {
            $product->set_stock_status( 'instock' );
        } else {
            $product->set_stock_status( 'outofstock' );
        }

        // Save per-warehouse stock data (only valid, non-excluded warehouses)
        // This ensures excluded warehouses are hidden from the frontend
        $product->update_meta_data( '_erp_sync_warehouse_data', $valid_warehouses );

        // Mark as ERP-managed product (ensures it's tracked in future syncs)
        $product->update_meta_data( '_erp_sync_managed', 1 );

        // Update sync timestamp
        $product->update_meta_data( '_erp_sync_stock_updated_at', current_time( 'mysql' ) );

        // Update session ID if provided (marks product as "touched" in this sync session)
        if ( ! empty( $session_id ) ) {
            $product->update_meta_data( '_erp_sync_session_id', $session_id );
        }

        // Assign branch taxonomy terms (performance-optimized)
        // Pass only valid warehouses so taxonomy accurately reflects available branches
        $this->assign_branch_terms( $product, $valid_warehouses );

        // ========== LOG CHANGES TO AUDIT LOG ==========
        // Pass valid warehouses and recalculated quantity for accurate audit logging
        $this->log_product_changes(
            $product,
            (float) $old_regular_price,
            $regular_price,
            (float) $old_sale_price,
            $sale_price,
            (int) $old_stock_qty,
            $quantity,
            $old_warehouses,
            $valid_warehouses
        );
    }

    /**
     * Log product changes to the audit log.
     *
     * Compares old and new values and creates a human-readable log entry
     * when differences are detected.
     *
     * @param \WC_Product $product          The product being updated.
     * @param float       $old_regular_price Old regular price.
     * @param float       $new_regular_price New regular price.
     * @param float       $old_sale_price   Old sale price.
     * @param float       $new_sale_price   New sale price.
     * @param int         $old_stock_qty    Old stock quantity.
     * @param int         $new_stock_qty    New stock quantity.
     * @param array       $old_warehouses   Old warehouse data.
     * @param array       $new_warehouses   New warehouse data.
     */
    private function log_product_changes(
        \WC_Product $product,
        float $old_regular_price,
        float $new_regular_price,
        float $old_sale_price,
        float $new_sale_price,
        int $old_stock_qty,
        int $new_stock_qty,
        array $old_warehouses,
        array $new_warehouses
    ): void {
        $changes = [];

        // Compare Regular Price
        // Note: Only log when new_regular_price > 0 because the code only updates
        // prices when they are positive (to avoid overwriting valid prices with zero)
        if ( abs( $old_regular_price - $new_regular_price ) > 0.001 && $new_regular_price > 0 ) {
            $message = sprintf(
                'Price: %s → %s',
                $old_regular_price > 0 ? number_format( $old_regular_price, 2 ) : '0',
                number_format( $new_regular_price, 2 )
            );
            Audit_Logger::log_change(
                $product,
                'price',
                $old_regular_price,
                $new_regular_price,
                $message
            );
            $changes[] = $message;
        }

        // Compare Sale Price
        if ( abs( $old_sale_price - $new_sale_price ) > 0.001 ) {
            $old_display = $old_sale_price > 0 ? number_format( $old_sale_price, 2 ) : 'none';
            $new_display = $new_sale_price > 0 ? number_format( $new_sale_price, 2 ) : 'none';
            $message = sprintf( 'Sale Price: %s → %s', $old_display, $new_display );
            Audit_Logger::log_change(
                $product,
                'sale_price',
                $old_sale_price,
                $new_sale_price,
                $message
            );
            $changes[] = $message;
        }

        // Compare Stock Quantity
        if ( $old_stock_qty !== $new_stock_qty ) {
            // Build branch-level change details
            $branch_changes = $this->calculate_branch_stock_diff( $old_warehouses, $new_warehouses );
            
            $message = sprintf( 'Stock: %d → %d', $old_stock_qty, $new_stock_qty );
            
            if ( ! empty( $branch_changes ) ) {
                $message .= ' (' . implode( ', ', $branch_changes ) . ')';
            }

            Audit_Logger::log_change(
                $product,
                'stock',
                $old_stock_qty,
                $new_stock_qty,
                $message
            );
        }
    }

    /**
     * Calculate per-branch stock differences.
     *
     * Compares old and new warehouse data to generate human-readable
     * branch-level stock change descriptions.
     *
     * @param array $old_warehouses Old warehouse data.
     * @param array $new_warehouses New warehouse data.
     * @return array Array of formatted branch change strings.
     */
    private function calculate_branch_stock_diff( array $old_warehouses, array $new_warehouses ): array {
        $branch_changes = [];

        // Build lookup maps by location
        $old_by_location = [];
        foreach ( $old_warehouses as $wh ) {
            $location = $wh['Location'] ?? '';
            if ( ! empty( $location ) ) {
                $old_by_location[ $location ] = (int) ( $wh['Quantity'] ?? 0 );
            }
        }

        $new_by_location = [];
        foreach ( $new_warehouses as $wh ) {
            $location = $wh['Location'] ?? '';
            if ( ! empty( $location ) ) {
                $new_by_location[ $location ] = (int) ( $wh['Quantity'] ?? 0 );
            }
        }

        // Find all unique locations
        $all_locations = array_unique( array_merge(
            array_keys( $old_by_location ),
            array_keys( $new_by_location )
        ) );

        foreach ( $all_locations as $location ) {
            $old_qty = $old_by_location[ $location ] ?? 0;
            $new_qty = $new_by_location[ $location ] ?? 0;

            if ( $old_qty !== $new_qty ) {
                $diff = $new_qty - $old_qty;
                $sign = $diff > 0 ? '+' : '';
                $branch_changes[] = sprintf( '%s: %d→%d', $location, $old_qty, $new_qty );
            }
        }

        return $branch_changes;
    }

    /**
     * Assign branch taxonomy terms to a product based on warehouse stock data.
     *
     * Performance-optimized: compares target terms vs current terms and only
     * writes to database if they differ. This prevents unnecessary DB writes
     * during frequent sync operations.
     *
     * @param \WC_Product $product    Product object.
     * @param array       $warehouses Warehouse data array from IBS API.
     */
    private function assign_branch_terms( \WC_Product $product, array $warehouses ): void {
        $product_id = $product->get_id();

        // Ensure product ID is valid
        if ( ! $product_id ) {
            return;
        }

        // Step 1: Calculate Target Terms
        // Get branch settings (aliases, exclusions)
        $branch_settings = get_option( self::OPTION_BRANCH_SETTINGS, [] );
        if ( ! is_array( $branch_settings ) ) {
            $branch_settings = [];
        }

        $target_terms = [];

        foreach ( $warehouses as $wh ) {
            $location = $wh['Location'] ?? '';
            $quantity = isset( $wh['Quantity'] ) ? (float) $wh['Quantity'] : 0;

            // Skip if no location name
            if ( empty( $location ) ) {
                continue;
            }

            // Skip if quantity is zero or negative (branch not available for this product)
            if ( $quantity <= 0 ) {
                continue;
            }

            // Check if this branch is excluded in settings
            $settings = $branch_settings[ $location ] ?? [];
            if ( ! empty( $settings['excluded'] ) ) {
                continue;
            }

            // Get display name (alias) or use original name
            // This ensures renamed branches use their alias as the term name
            $display_name = ! empty( $settings['alias'] ) ? $settings['alias'] : $location;
            $display_name = sanitize_text_field( trim( $display_name ) );

            if ( ! empty( $display_name ) ) {
                $target_terms[] = $display_name;
            }
        }

        // Remove duplicates and sort for consistent comparison
        $target_terms = array_unique( $target_terms );
        sort( $target_terms );

        // Step 2: Get Current Terms
        $current_terms = wp_get_object_terms( $product_id, self::TAXONOMY_BRANCH, [ 'fields' => 'names' ] );

        if ( is_wp_error( $current_terms ) ) {
            // Log the error - this indicates a database or configuration issue
            Logger::instance()->log( 'Failed to get branch terms for product', [
                'product_id' => $product_id,
                'error'      => $current_terms->get_error_message(),
            ] );
            $current_terms = [];
        }

        // Sort current terms for consistent comparison
        sort( $current_terms );

        // Step 3: Compare & Optimize
        // If arrays are identical, skip the database write entirely
        if ( $target_terms === $current_terms ) {
            // Zero DB write impact - terms haven't changed
            return;
        }

        // Terms are different - update the database
        // wp_set_object_terms with string names will find or create terms as needed
        // This is the intended behavior for dynamic branch assignment
        $result = wp_set_object_terms( $product_id, $target_terms, self::TAXONOMY_BRANCH );

        if ( is_wp_error( $result ) ) {
            Logger::instance()->log( 'Failed to set branch terms for product', [
                'product_id'   => $product_id,
                'target_terms' => $target_terms,
                'error'        => $result->get_error_message(),
            ] );
        }
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
        $this->attribute_mapping_cache = null;
    }

    /**
     * Zero out stock for orphaned products.
     *
     * Identifies products that were NOT present in the sync data (i.e., their
     * session_id doesn't match the current sync session) and sets their stock to 0.
     *
     * ABSOLUTE SYNC: This method now processes ALL WooCommerce products, not just
     * those previously managed by ERP Sync. Any product missing from the current
     * ERP sync session will be zeroed out. This ensures complete inventory accuracy
     * even for products that were never synced before.
     *
     * Memory optimization: Processes orphans in batches and clears object cache
     * periodically to prevent memory exhaustion on large product catalogs.
     *
     * @param string $session_id The current sync session ID.
     * @return int Number of orphaned products updated.
     */
    public function zero_out_orphans( string $session_id ): int {
        global $wpdb;

        if ( empty( $session_id ) ) {
            Logger::instance()->log( 'Orphan cleanup skipped: No session ID provided', [] );
            return 0;
        }

        Logger::instance()->log( 'Starting orphan cleanup (absolute sync mode)', [
            'session_id' => $session_id,
        ] );

        // Query: Find ALL products where:
        // - Post type = product
        // - Post status is publish, draft, pending, or private
        // - _erp_sync_session_id != $session_id OR does not exist
        //
        // CRITICAL: We no longer filter by _erp_sync_managed = 1.
        // This ensures ABSOLUTE synchronization - any product in WooCommerce
        // that is not present in the current ERP sync will be zeroed out.
        // This prevents inventory inconsistencies from products that were
        // never synced before or were added manually.
        //
        // We use a direct SQL query for performance with large datasets
        $orphan_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm_session 
                    ON p.ID = pm_session.post_id 
                    AND pm_session.meta_key = '_erp_sync_session_id'
                WHERE p.post_type = 'product'
                    AND p.post_status IN ('publish', 'draft', 'pending', 'private')
                    AND (pm_session.meta_value IS NULL OR pm_session.meta_value != %s)",
                $session_id
            )
        );

        if ( empty( $orphan_ids ) ) {
            Logger::instance()->log( 'Orphan cleanup: No orphans found', [
                'session_id' => $session_id,
            ] );
            return 0;
        }

        $orphan_count = 0;
        $processed = 0;

        foreach ( $orphan_ids as $product_id ) {
            $product = wc_get_product( (int) $product_id );

            if ( ! $product ) {
                continue;
            }

            // Get current stock for logging
            $old_stock = $product->get_stock_quantity();

            // Always mark as ERP-managed so it's tracked in future syncs
            // This ensures consistency even for products with stock already at 0
            $needs_save = false;
            $is_erp_managed = $product->get_meta( '_erp_sync_managed', true );
            if ( $is_erp_managed !== '1' && $is_erp_managed !== 1 ) {
                $product->update_meta_data( '_erp_sync_managed', 1 );
                $needs_save = true;
            }

            // Skip if stock is already 0 (but still mark as managed above)
            if ( $old_stock === 0 || $old_stock === null ) {
                // Save if we need to update the managed flag
                if ( $needs_save ) {
                    $product->save();
                }
                unset( $product );
                $processed++;
                
                // Periodically clear caches even for skipped products
                if ( $processed % self::ORPHAN_CLEANUP_BATCH_SIZE === 0 ) {
                    wp_cache_flush();
                    $this->clear_cache();
                    if ( function_exists( 'gc_collect_cycles' ) ) {
                        gc_collect_cycles();
                    }
                }
                continue;
            }

            // Set stock to 0
            $product->set_manage_stock( true );
            $product->set_stock_quantity( 0 );
            $product->set_stock_status( 'outofstock' );
            $product->update_meta_data( '_erp_sync_orphan_zeroed_at', current_time( 'mysql' ) );
            $product->update_meta_data( '_erp_sync_orphan_session_id', $session_id );
            $product->save();

            $orphan_count++;

            // Log the action
            $message = sprintf(
                'Set stock to 0 due to absence in ERP sync (session: %s). Previous stock: %d',
                $session_id,
                $old_stock
            );

            Audit_Logger::log_change(
                $product,
                'orphan_cleanup',
                $old_stock,
                0,
                $message
            );

            Logger::instance()->log( 'Orphan product stock zeroed', [
                'product_id'  => $product_id,
                'sku'         => $product->get_sku(),
                'old_stock'   => $old_stock,
                'session_id'  => $session_id,
            ] );

            // Clear product from memory
            unset( $product );
            $processed++;

            // Periodically clear caches to prevent memory exhaustion
            if ( $processed % self::ORPHAN_CLEANUP_BATCH_SIZE === 0 ) {
                wp_cache_flush();
                $this->clear_cache();
                if ( function_exists( 'gc_collect_cycles' ) ) {
                    gc_collect_cycles();
                }
            }
        }

        Logger::instance()->log( 'Orphan cleanup completed', [
            'session_id'          => $session_id,
            'orphans_found'       => count( $orphan_ids ),
            'orphans_zeroed'      => $orphan_count,
            'orphans_already_zero'=> count( $orphan_ids ) - $orphan_count,
        ] );

        return $orphan_count;
    }
}
