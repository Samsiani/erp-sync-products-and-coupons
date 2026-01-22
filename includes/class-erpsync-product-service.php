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
                $this->set_product_catalog_data( $product, $row, $attribute_mapping );

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
     * @param \WC_Product $product           Product object.
     * @param array       $row               Product data row from IBS API.
     * @param array       $attribute_mapping Attribute mapping configuration.
     */
    private function set_product_catalog_data( \WC_Product $product, array $row, array $attribute_mapping ): void {
        // Set basic product data
        $product->set_name( sanitize_text_field( $row['ProductName'] ?? '' ) );
        $product->set_sku( sanitize_text_field( $row['VendorCode'] ?? '' ) );
        $product->set_status( 'publish' );

        // Mark as ERP-managed product
        $product->update_meta_data( '_erp_sync_managed', 1 );
        $product->update_meta_data( '_erp_sync_synced_at', current_time( 'mysql' ) );

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
     * Synchronize a batch of product stock data.
     *
     * Updates stock quantities and prices for existing products.
     * Optimized for frequent execution - only updates stock-related fields.
     * Also discovers and records unique branch/warehouse locations.
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

        // Collect unique branch locations for discovery
        $discovered_locations = [];

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
     *
     * @param \WC_Product $product Product object.
     * @param array       $row     Stock data row from IBS API.
     */
    private function set_product_stock_data( \WC_Product $product, array $row ): void {
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

        // Save per-warehouse stock data
        $warehouses = $row['_warehouses'] ?? [];
        $product->update_meta_data( '_erp_sync_warehouse_data', $warehouses );

        // Update sync timestamp
        $product->update_meta_data( '_erp_sync_stock_updated_at', current_time( 'mysql' ) );

        // Assign branch taxonomy terms (performance-optimized)
        $this->assign_branch_terms( $product, $warehouses );
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
}
