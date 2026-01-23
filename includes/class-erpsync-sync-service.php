<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

class Sync_Service {

    const OPTION_LAST_SYNC = 'erp_sync_last_sync';
    const OPTION_LAST_PRODUCTS_SYNC = 'erp_sync_last_products_sync';
    const OPTION_LAST_STOCK_SYNC = 'erp_sync_last_stock_sync';

    /**
     * Action Scheduler hook for processing stock batch.
     */
    const HOOK_PROCESS_STOCK_BATCH = 'erp_sync_process_stock_batch';

    /**
     * Action Scheduler hook for processing catalog batch.
     */
    const HOOK_PROCESS_CATALOG_BATCH = 'erp_sync_process_catalog_batch';

    /**
     * Action Scheduler hook for cleaning up orphaned products.
     */
    const HOOK_CLEANUP_ORPHANS = 'erp_sync_cleanup_orphans';

    /**
     * Option key for storing the current active sync session ID.
     */
    const OPTION_ACTIVE_SESSION = 'erp_sync_active_session';

    /**
     * Batch size for processing items with Action Scheduler.
     */
    const BATCH_SIZE = 50;

    /**
     * Delay in seconds before running orphan cleanup (30 minutes).
     */
    const ORPHAN_CLEANUP_DELAY = 1800;

    /**
     * Whether hooks have been registered.
     *
     * @var bool
     */
    private static bool $hooks_registered = false;

    private API_Client $api;
    private Product_Service $product_service;

    /**
     * Initialize Action Scheduler hooks.
     *
     * This should be called once during plugin bootstrap to register
     * the hooks that Action Scheduler will fire.
     */
    public static function init(): void {
        if ( self::$hooks_registered ) {
            return;
        }

        add_action( self::HOOK_PROCESS_STOCK_BATCH, [ __CLASS__, 'handle_stock_batch' ], 10, 2 );
        add_action( self::HOOK_PROCESS_CATALOG_BATCH, [ __CLASS__, 'handle_catalog_batch' ], 10, 2 );
        add_action( self::HOOK_CLEANUP_ORPHANS, [ __CLASS__, 'handle_cleanup_orphans' ], 10, 1 );

        self::$hooks_registered = true;
    }

    /**
     * Static handler for stock batch processing (called by Action Scheduler).
     *
     * @param array  $batch      Array of stock rows to process.
     * @param string $session_id Unique session identifier for this sync.
     */
    public static function handle_stock_batch( array $batch, string $session_id ): void {
        $service = new self( new API_Client() );
        $service->process_stock_batch( $batch, $session_id );
    }

    /**
     * Static handler for catalog batch processing (called by Action Scheduler).
     *
     * @param array  $batch      Array of catalog rows to process.
     * @param string $session_id Unique session identifier for this sync.
     */
    public static function handle_catalog_batch( array $batch, string $session_id ): void {
        $service = new self( new API_Client() );
        $service->process_catalog_batch( $batch, $session_id );
    }

    /**
     * Static handler for orphan cleanup (called by Action Scheduler).
     *
     * @param string $session_id Unique session identifier for the sync that completed.
     */
    public static function handle_cleanup_orphans( string $session_id ): void {
        $service = new self( new API_Client() );
        $service->cleanup_orphans( $session_id );
    }

    public function __construct( API_Client $api, ?Product_Service $product_service = null ) {
        $this->api = $api;
        $this->product_service = $product_service ?? new Product_Service();
    }

    /**
     * Import only new codes that don't exist locally
     */
    public function import_new_only(): array {
        $cards = $this->api->fetch_cards_remote();
        $created = 0;
        $total = count( $cards );
        
        $this->set_progress( 0, $total, 'Starting import new only...' );
        
        foreach ( $cards as $index => $card ) {
            if ( empty( $card['CardCode'] ) ) continue;
            
            $formatted = erp_sync_format_code( $card['CardCode'] );
            
            if ( ! $this->coupon_exists( $formatted ) ) {
                $this->create_or_update_coupon( $card, false, false );
                $created++;
            }
            
            $this->set_progress( $index + 1, $total, sprintf( 'Processing %d of %d...', $index + 1, $total ) );
        }
        
        update_option( self::OPTION_LAST_SYNC, current_time( 'mysql' ) );
        $this->clear_progress();
        
        Logger::instance()->log( 'Import new only completed', [ 
            'created' => $created, 
            'remote' => $total,
            'user' => wp_get_current_user()->user_login ?? 'system'
        ] );
        
        return [ 'created' => $created, 'updated' => 0, 'total_remote' => $total ];
    }

    /**
     * Update only existing codes (NEW FEATURE)
     * ALWAYS overwrites manual changes with 1C data
     */
    public function update_existing_only(): array {
        $cards = $this->api->fetch_cards_remote();
        $updated = 0;
        $total = count( $cards );
        
        $this->set_progress( 0, $total, 'Starting update existing only...' );
        
        foreach ( $cards as $index => $card ) {
            if ( empty( $card['CardCode'] ) ) continue;
            
            $formatted = erp_sync_format_code( $card['CardCode'] );
            
            if ( $this->coupon_exists( $formatted ) ) {
                $this->create_or_update_coupon( $card, true, false );
                $updated++;
            }
            
            $this->set_progress( $index + 1, $total, sprintf( 'Processing %d of %d...', $index + 1, $total ) );
        }
        
        update_option( self::OPTION_LAST_SYNC, current_time( 'mysql' ) );
        $this->clear_progress();
        
        Logger::instance()->log( 'Update existing only completed', [ 
            'updated' => $updated, 
            'remote' => $total,
            'user' => wp_get_current_user()->user_login ?? 'system'
        ] );
        
        return [ 'created' => 0, 'updated' => $updated, 'total_remote' => $total ];
    }

    /**
     * Full sync: create new and update existing
     * ALWAYS overwrites manual changes with 1C data
     */
    public function full_sync(): array {
        $cards = $this->api->fetch_cards_remote();
        $created = 0;
        $updated = 0;
        $total = count( $cards );
        
        $this->set_progress( 0, $total, 'Starting full sync...' );
        
        foreach ( $cards as $index => $card ) {
            if ( empty( $card['CardCode'] ) ) continue;
            
            $formatted = erp_sync_format_code( $card['CardCode'] );
            
            if ( $this->coupon_exists( $formatted ) ) {
                $this->create_or_update_coupon( $card, true, true );
                $updated++;
            } else {
                $this->create_or_update_coupon( $card, false, false );
                $created++;
            }
            
            $this->set_progress( $index + 1, $total, sprintf( 'Processing %d of %d...', $index + 1, $total ) );
        }
        
        update_option( self::OPTION_LAST_SYNC, current_time( 'mysql' ) );
        $this->clear_progress();
        
        Logger::instance()->log( 'Full sync completed', [
            'created' => $created,
            'updated' => $updated,
            'remote'  => $total,
            'user' => wp_get_current_user()->user_login ?? 'system'
        ] );
        
        return [ 'created' => $created, 'updated' => $updated, 'total_remote' => $total ];
    }

    /**
     * Force import all: re-import everything from 1C, overwriting local data (NEW FEATURE)
     * ALWAYS overwrites manual changes with 1C data
     */
    public function force_import_all(): array {
        $cards = $this->api->fetch_cards_remote();
        $created = 0;
        $updated = 0;
        $total = count( $cards );
        
        $this->set_progress( 0, $total, 'Starting force import all...' );
        
        foreach ( $cards as $index => $card ) {
            if ( empty( $card['CardCode'] ) ) continue;
            
            $formatted = erp_sync_format_code( $card['CardCode'] );
            
            $exists = $this->coupon_exists( $formatted );
            
            // Force update/create regardless - ALWAYS overwrite
            $this->create_or_update_coupon( $card, $exists, true );
            
            if ( $exists ) {
                $updated++;
            } else {
                $created++;
            }
            
            $this->set_progress( $index + 1, $total, sprintf( 'Force processing %d of %d...', $index + 1, $total ) );
        }
        
        update_option( self::OPTION_LAST_SYNC, current_time( 'mysql' ) );
        $this->clear_progress();
        
        Logger::instance()->log( 'Force import all completed', [
            'created' => $created,
            'updated' => $updated,
            'remote'  => $total,
            'forced'  => true,
            'user' => wp_get_current_user()->user_login ?? 'system'
        ] );
        
        return [ 'created' => $created, 'updated' => $updated, 'total_remote' => $total ];
    }

    /**
     * Import products catalog from IBS/1C API.
     *
     * Fetches product catalog data and schedules batch processing via Action Scheduler.
     * Creates new products or updates existing ones based on VendorCode (SKU).
     *
     * @return array{scheduled: int, total: int, session_id: string} Schedule statistics.
     * @throws \Throwable If API call fails.
     */
    public function import_products_catalog(): array {
        Logger::instance()->log( 'Starting products catalog import (batch mode)', [
            'user' => wp_get_current_user()->user_login ?? 'system',
        ] );

        try {
            // Generate unique session ID for this sync
            $session_id = uniqid( 'catalog_', true );

            // Store session ID for tracking
            update_option( self::OPTION_ACTIVE_SESSION, [
                'session_id' => $session_id,
                'type'       => 'catalog',
                'started_at' => current_time( 'mysql' ),
            ] );

            // Fetch catalog data from API
            $rows = $this->api->fetch_products_catalog();
            $total = count( $rows );

            $this->set_progress( 0, $total, 'Scheduling catalog batches...' );

            // Split data into chunks of BATCH_SIZE items
            $chunks = array_chunk( $rows, self::BATCH_SIZE );
            $scheduled_batches = 0;

            // Schedule each chunk as a background action
            foreach ( $chunks as $index => $chunk ) {
                if ( function_exists( 'as_schedule_single_action' ) ) {
                    as_schedule_single_action(
                        time() + ( $index * 5 ), // Stagger batches by 5 seconds
                        self::HOOK_PROCESS_CATALOG_BATCH,
                        [ $chunk, $session_id ],
                        'erp-sync'
                    );
                    $scheduled_batches++;
                } else {
                    // Fallback: process synchronously if Action Scheduler not available
                    $this->product_service->sync_catalog_batch( $chunk, $session_id );
                }

                $this->set_progress( $index + 1, count( $chunks ), sprintf( 'Scheduled batch %d of %d', $index + 1, count( $chunks ) ) );
            }

            // Schedule orphan cleanup after delay
            if ( function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action(
                    time() + self::ORPHAN_CLEANUP_DELAY,
                    self::HOOK_CLEANUP_ORPHANS,
                    [ $session_id ],
                    'erp-sync'
                );
            }

            // Update last sync time
            update_option( self::OPTION_LAST_PRODUCTS_SYNC, current_time( 'mysql' ) );

            Logger::instance()->log( 'Products catalog import batches scheduled', [
                'session_id'        => $session_id,
                'total_items'       => $total,
                'batch_size'        => self::BATCH_SIZE,
                'scheduled_batches' => $scheduled_batches,
                'cleanup_delay'     => self::ORPHAN_CLEANUP_DELAY,
                'user'              => wp_get_current_user()->user_login ?? 'system',
            ] );

            $this->clear_progress();

            return [
                'scheduled'  => $scheduled_batches,
                'total'      => $total,
                'session_id' => $session_id,
            ];

        } catch ( \Throwable $e ) {
            $this->clear_progress();
            Logger::instance()->log( 'Products catalog import failed', [
                'error' => $e->getMessage(),
                'user'  => wp_get_current_user()->user_login ?? 'system',
            ] );
            throw $e;
        }
    }

    /**
     * Process a single catalog batch (called by Action Scheduler).
     *
     * @param array  $batch      Array of product rows to process.
     * @param string $session_id Unique session identifier for this sync.
     */
    public function process_catalog_batch( array $batch, string $session_id ): void {
        Logger::instance()->log( 'Processing catalog batch', [
            'session_id' => $session_id,
            'batch_size' => count( $batch ),
        ] );

        try {
            $stats = $this->product_service->sync_catalog_batch( $batch, $session_id );

            Logger::instance()->log( 'Catalog batch processed', [
                'session_id' => $session_id,
                'created'    => $stats['created'],
                'updated'    => $stats['updated'],
                'errors'     => $stats['errors'],
                'total'      => $stats['total'],
            ] );

            // Clear cache after batch to free memory
            $this->product_service->clear_cache();

        } catch ( \Throwable $e ) {
            Logger::instance()->log( 'Catalog batch processing failed', [
                'session_id' => $session_id,
                'error'      => $e->getMessage(),
            ] );
        }
    }

    /**
     * Update products stock and prices from IBS/1C API.
     *
     * Fetches stock data and schedules batch processing via Action Scheduler.
     * Only updates existing products; does not create new ones.
     *
     * @param string $vendor_codes Optional comma-separated list of VendorCodes (SKUs). Empty returns all.
     * @return array{scheduled: int, total: int, session_id: string} Schedule statistics.
     * @throws \Throwable If API call fails.
     */
    public function update_products_stock( string $vendor_codes = '' ): array {
        Logger::instance()->log( 'Starting products stock update (batch mode)', [
            'vendor_codes' => $vendor_codes ? substr( $vendor_codes, 0, 100 ) : '(all)',
            'user'         => wp_get_current_user()->user_login ?? 'system',
        ] );

        try {
            // Generate unique session ID for this sync
            $session_id = uniqid( 'stock_', true );

            // Store session ID for tracking
            update_option( self::OPTION_ACTIVE_SESSION, [
                'session_id' => $session_id,
                'type'       => 'stock',
                'started_at' => current_time( 'mysql' ),
            ] );

            // Fetch stock data from API
            $rows = $this->api->fetch_products_stock( $vendor_codes );
            $total = count( $rows );

            $this->set_progress( 0, $total, 'Scheduling stock update batches...' );

            // Split data into chunks of BATCH_SIZE items
            $chunks = array_chunk( $rows, self::BATCH_SIZE );
            $scheduled_batches = 0;

            // Schedule each chunk as a background action
            foreach ( $chunks as $index => $chunk ) {
                if ( function_exists( 'as_schedule_single_action' ) ) {
                    as_schedule_single_action(
                        time() + ( $index * 5 ), // Stagger batches by 5 seconds
                        self::HOOK_PROCESS_STOCK_BATCH,
                        [ $chunk, $session_id ],
                        'erp-sync'
                    );
                    $scheduled_batches++;
                } else {
                    // Fallback: process synchronously if Action Scheduler not available
                    $this->product_service->sync_stock_batch( $chunk, $session_id );
                }

                $this->set_progress( $index + 1, count( $chunks ), sprintf( 'Scheduled batch %d of %d', $index + 1, count( $chunks ) ) );
            }

            // Schedule orphan cleanup after delay
            if ( function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action(
                    time() + self::ORPHAN_CLEANUP_DELAY,
                    self::HOOK_CLEANUP_ORPHANS,
                    [ $session_id ],
                    'erp-sync'
                );
            }

            // Update last sync time
            update_option( self::OPTION_LAST_STOCK_SYNC, current_time( 'mysql' ) );

            Logger::instance()->log( 'Products stock update batches scheduled', [
                'session_id'        => $session_id,
                'total_items'       => $total,
                'batch_size'        => self::BATCH_SIZE,
                'scheduled_batches' => $scheduled_batches,
                'cleanup_delay'     => self::ORPHAN_CLEANUP_DELAY,
                'user'              => wp_get_current_user()->user_login ?? 'system',
            ] );

            $this->clear_progress();

            return [
                'scheduled'  => $scheduled_batches,
                'total'      => $total,
                'session_id' => $session_id,
            ];

        } catch ( \Throwable $e ) {
            $this->clear_progress();
            Logger::instance()->log( 'Products stock update failed', [
                'error' => $e->getMessage(),
                'user'  => wp_get_current_user()->user_login ?? 'system',
            ] );
            throw $e;
        }
    }

    /**
     * Process a single stock batch (called by Action Scheduler).
     *
     * @param array  $batch      Array of stock rows to process.
     * @param string $session_id Unique session identifier for this sync.
     */
    public function process_stock_batch( array $batch, string $session_id ): void {
        Logger::instance()->log( 'Processing stock batch', [
            'session_id' => $session_id,
            'batch_size' => count( $batch ),
        ] );

        try {
            $stats = $this->product_service->sync_stock_batch( $batch, $session_id );

            Logger::instance()->log( 'Stock batch processed', [
                'session_id' => $session_id,
                'updated'    => $stats['updated'],
                'skipped'    => $stats['skipped'],
                'errors'     => $stats['errors'],
                'total'      => $stats['total'],
            ] );

        } catch ( \Throwable $e ) {
            Logger::instance()->log( 'Stock batch processing failed', [
                'session_id' => $session_id,
                'error'      => $e->getMessage(),
            ] );
        }
    }

    /**
     * Sync a single product's stock and price from the ERP.
     *
     * This performs a synchronous, immediate update for a single product
     * to give instant feedback to the admin.
     *
     * @param int $product_id The WooCommerce product ID.
     * @return bool True on success, false on failure.
     * @throws \Exception If the product has no SKU or API call fails.
     */
    public function sync_single_product( int $product_id ): bool {
        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            throw new \Exception( __( 'Product not found', 'erp-sync' ) );
        }

        $sku = $product->get_sku();

        if ( empty( $sku ) ) {
            throw new \Exception( __( 'No SKU found for this product', 'erp-sync' ) );
        }

        Logger::instance()->log( 'Starting single product sync', [
            'product_id' => $product_id,
            'sku'        => $sku,
            'user'       => wp_get_current_user()->user_login ?? 'system',
        ] );

        try {
            // Fetch stock data for this specific SKU
            $rows = $this->api->fetch_products_stock( $sku );

            if ( empty( $rows ) ) {
                Logger::instance()->log( 'Single product sync: No data returned from API', [
                    'product_id' => $product_id,
                    'sku'        => $sku,
                ] );
                throw new \Exception( __( 'No data returned from ERP for this SKU', 'erp-sync' ) );
            }

            // Generate a unique session ID for this single sync
            $session_id = uniqid( 'single_', true );

            // Process the stock data using the existing batch method
            // This handles warehouse exclusion logic correctly
            $stats = $this->product_service->sync_stock_batch( $rows, $session_id );

            Logger::instance()->log( 'Single product sync completed', [
                'product_id' => $product_id,
                'sku'        => $sku,
                'updated'    => $stats['updated'],
                'skipped'    => $stats['skipped'],
                'errors'     => $stats['errors'],
                'user'       => wp_get_current_user()->user_login ?? 'system',
            ] );

            // Return true only if no errors occurred (product may have been skipped if not found in API data)
            return $stats['errors'] === 0;

        } catch ( \Throwable $e ) {
            Logger::instance()->log( 'Single product sync failed', [
                'product_id' => $product_id,
                'sku'        => $sku,
                'error'      => $e->getMessage(),
                'user'       => wp_get_current_user()->user_login ?? 'system',
            ] );
            throw $e;
        }
    }

    /**
     * Cleanup orphaned products (called by Action Scheduler).
     *
     * Sets stock to 0 for products that were not touched during the sync session.
     * Only affects products managed by ERP Sync (_erp_sync_managed = 1).
     *
     * @param string $session_id Unique session identifier for the sync that completed.
     */
    public function cleanup_orphans( string $session_id ): void {
        Logger::instance()->log( 'Starting orphan cleanup', [
            'session_id' => $session_id,
        ] );

        try {
            $orphan_count = $this->product_service->zero_out_orphans( $session_id );

            Logger::instance()->log( 'Orphan cleanup completed', [
                'session_id'    => $session_id,
                'orphan_count'  => $orphan_count,
            ] );

            // Clear active session after cleanup
            delete_option( self::OPTION_ACTIVE_SESSION );

        } catch ( \Throwable $e ) {
            Logger::instance()->log( 'Orphan cleanup failed', [
                'session_id' => $session_id,
                'error'      => $e->getMessage(),
            ] );
        }
    }

    /**
     * Check if coupon exists
     */
    private function coupon_exists( string $code ): bool {
        return (bool) wc_get_coupon_id_by_code( $code );
    }

    /**
     * Create or update coupon
     * ALWAYS updates coupon amount from 1C data (1C is source of truth)
     * 
     * @param array $card Card data from 1C
     * @param bool $is_update Whether this is an update operation
     * @param bool $force Force overwrite all data (for force_import_all and full_sync)
     */
    public function create_or_update_coupon( array $card, bool $is_update, bool $force = false ): void {
        $code = erp_sync_format_code( $card['CardCode'] );
        $coupon_id = wc_get_coupon_id_by_code( $code );

        // Create new coupon if doesn't exist
        if ( ! $coupon_id ) {
            $coupon_id = wp_insert_post( [
                'post_title'   => $code,
                'post_name'    => $code,
                'post_status'  => 'publish',
                'post_type'    => 'shop_coupon',
                'post_author'  => get_current_user_id() ?: 1,
                'post_excerpt' => sprintf( 
                    __( 'Synchronized discount card - %s', 'erp-sync' ),
                    sanitize_text_field( $card['Name'] ?? '' )
                ),
            ] );
            
            if ( is_wp_error( $coupon_id ) ) {
                Logger::instance()->log( 'Create coupon failed', [
                    'code'  => $code,
                    'error' => $coupon_id->get_error_message(),
                    'user'  => wp_get_current_user()->user_login ?? 'system'
                ] );
                return;
            }
            
            // Set initial coupon settings
            update_post_meta( $coupon_id, 'discount_type', 'percent' );
            update_post_meta( $coupon_id, 'usage_limit', '' );
            update_post_meta( $coupon_id, 'usage_limit_per_user', '' );
            update_post_meta( $coupon_id, 'limit_usage_to_x_items', '' );
            update_post_meta( $coupon_id, 'individual_use', 'no' );
            update_post_meta( $coupon_id, 'product_ids', '' );
            update_post_meta( $coupon_id, 'exclude_product_ids', '' );
            update_post_meta( $coupon_id, 'product_categories', [] );
            update_post_meta( $coupon_id, 'exclude_product_categories', [] );
            update_post_meta( $coupon_id, 'exclude_sale_items', 'no' );
            update_post_meta( $coupon_id, 'minimum_amount', '' );
            update_post_meta( $coupon_id, 'maximum_amount', '' );
            update_post_meta( $coupon_id, 'free_shipping', 'no' );
            update_post_meta( $coupon_id, '_erp_sync_managed', 1 );
            
            Logger::instance()->log( 'Coupon created', [
                'code'      => $code,
                'coupon_id' => $coupon_id,
                'name'      => $card['Name'] ?? '',
                'discount'  => $card['DiscountPercentage'] ?? 0,
                'user'      => wp_get_current_user()->user_login ?? 'system',
                'timestamp' => current_time( 'mysql' )
            ] );
        }

        // Get current values for comparison
        $old_amount = get_post_meta( $coupon_id, 'coupon_amount', true );
        $new_amount = max( 0, (int) ( $card['DiscountPercentage'] ?? 0 ) );

        // ✅ ALWAYS UPDATE COUPON AMOUNT FROM 1C DATA
        // This ensures 1C is always the source of truth
        // Manual changes will be overwritten on ANY sync
        update_post_meta( $coupon_id, 'coupon_amount', $new_amount );
        update_post_meta( $coupon_id, '_erp_sync_base_discount', $new_amount );
        
        // Log if amount changed
        if ( $is_update && $old_amount != $new_amount ) {
            Logger::instance()->log( 'Coupon amount updated', [
                'code'       => $code,
                'coupon_id'  => $coupon_id,
                'old_amount' => $old_amount,
                'new_amount' => $new_amount,
                'forced'     => $force,
                'user'       => wp_get_current_user()->user_login ?? 'system',
                'timestamp'  => current_time( 'mysql' )
            ] );
        }

        // Always update ERPSync meta data
        update_post_meta( $coupon_id, '_erp_sync_inn', sanitize_text_field( $card['Inn'] ?? '' ) );
        update_post_meta( $coupon_id, '_erp_sync_name', sanitize_text_field( $card['Name'] ?? '' ) );
        update_post_meta( $coupon_id, '_erp_sync_mobile', sanitize_text_field( $card['MobileNumber'] ?? '' ) );
        update_post_meta( $coupon_id, '_erp_sync_dob', sanitize_text_field( $card['DateOfBirth'] ?? '' ) );
        update_post_meta( $coupon_id, '_erp_sync_is_deleted', ! empty( $card['IsDeleted'] ) ? 'yes' : 'no' );
        update_post_meta( $coupon_id, '_erp_sync_synced_at', current_time( 'mysql' ) );
        update_post_meta( $coupon_id, '_erp_sync_last_sync_user', wp_get_current_user()->user_login ?? 'system' );
        
        if ( $force ) {
            update_post_meta( $coupon_id, '_erp_sync_forced_update', current_time( 'mysql' ) );
        }
        
        // Update post excerpt if force mode or new coupon
        if ( $force || ! $is_update ) {
            wp_update_post( [
                'ID'           => $coupon_id,
                'post_excerpt' => sprintf( 
                    __( 'Synchronized discount card - %s (Last sync: %s by %s)', 'erp-sync' ),
                    sanitize_text_field( $card['Name'] ?? '' ),
                    current_time( 'Y-m-d H:i:s' ),
                    wp_get_current_user()->user_login ?? 'system'
                ),
            ] );
        }
        
        // Clear any WooCommerce coupon cache
        if ( function_exists( 'wc_delete_coupon_transients' ) ) {
            wc_delete_coupon_transients( $coupon_id );
        }
        
        // Clear object cache
        wp_cache_delete( $coupon_id, 'posts' );
        wp_cache_delete( 'coupon-' . $coupon_id, 'coupons' );
        clean_post_cache( $coupon_id );
        
        // Trigger WooCommerce coupon update hook
        do_action( 'woocommerce_update_coupon', $coupon_id );
    }

    /**
     * Set sync progress for UI feedback
     */
    private function set_progress( int $current, int $total, string $status = '' ): void {
        $progress = [
            'progress'    => $total > 0 ? round( ( $current / $total ) * 100 ) : 0,
            'current'     => $current,
            'total'       => $total,
            'status'      => $status,
            'timestamp'   => time(),
            'user'        => wp_get_current_user()->user_login ?? 'system'
        ];
        
        set_transient( 'erp_sync_sync_progress', $progress, 300 ); // 5 minutes
    }

    /**
     * Clear progress transient
     */
    private function clear_progress(): void {
        delete_transient( 'erp_sync_sync_progress' );
    }

    /**
     * Get list of all ERPSync-managed coupons
     */
    public function get_managed_coupons(): array {
        $args = [
            'post_type'      => 'shop_coupon',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_query'     => [
                [
                    'key'     => '_erp_sync_managed',
                    'value'   => '1',
                    'compare' => '='
                ]
            ]
        ];
        
        return get_posts( $args );
    }

    /**
     * Get sync statistics
     */
    public function get_sync_stats(): array {
        $managed_coupons = $this->get_managed_coupons();
        $total = count( $managed_coupons );
        $active = 0;
        $deleted = 0;
        $birthday = 0;
        
        foreach ( $managed_coupons as $coupon ) {
            $is_deleted = get_post_meta( $coupon->ID, '_erp_sync_is_deleted', true ) === 'yes';
            
            if ( $is_deleted ) {
                $deleted++;
            } else {
                $active++;
                $dob = get_post_meta( $coupon->ID, '_erp_sync_dob', true );
                if ( $this->is_in_birthday_window( $dob ) ) {
                    $birthday++;
                }
            }
        }
        
        return [
            'total'         => $total,
            'active'        => $active,
            'deleted'       => $deleted,
            'birthday'      => $birthday,
            'last_sync'     => get_option( self::OPTION_LAST_SYNC, '—' ),
            'last_sync_user' => get_option( 'erp_sync_last_sync_user', '—' )
        ];
    }

    /**
     * Check if date is in birthday window
     */
    private function is_in_birthday_window( string $dob ): bool {
        if ( empty( $dob ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ) {
            return false;
        }
        
        try {
            list( $y, $m, $d ) = array_map( 'intval', explode( '-', $dob ) );
            $now = current_time( 'timestamp' );
            $year = (int) date( 'Y', $now );

            $candidate_dates = [];
            if ( $m === 2 && $d === 29 && ! $this->is_leap_year( $year ) ) {
                $candidate_dates[] = strtotime( "$year-02-28" );
                $candidate_dates[] = strtotime( "$year-03-01" );
            } else {
                $candidate_dates[] = strtotime( sprintf( '%04d-%02d-%02d', $year, $m, $d ) );
            }

            $today = strtotime( date( 'Y-m-d', $now ) );
            foreach ( $candidate_dates as $c ) {
                if ( $today === $c || $today === $c - DAY_IN_SECONDS || $today === $c + DAY_IN_SECONDS ) {
                    return true;
                }
            }
            return false;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * Check if year is leap year
     */
    private function is_leap_year( int $y ): bool {
        return ( ( $y % 4 === 0 ) && ( $y % 100 !== 0 ) ) || ( $y % 400 === 0 );
    }
}
