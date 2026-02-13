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
     * Transient key for stock sync lock.
     */
    const TRANSIENT_LOCK_STOCK = 'erp_sync_lock_stock';

    /**
     * Transient key for catalog sync lock.
     */
    const TRANSIENT_LOCK_CATALOG = 'erp_sync_lock_catalog';

    /**
     * Lock expiration time in seconds (1 hour).
     */
    const TRANSIENT_LOCK_EXPIRATION = 3600;

    /**
     * Transient key prefix for cached stock data.
     */
    const TRANSIENT_STOCK_DATA_PREFIX = 'erpsync_temp_stock_';

    /**
     * Transient key prefix for cached catalog data.
     */
    const TRANSIENT_CATALOG_DATA_PREFIX = 'erpsync_temp_catalog_';

    /**
     * Cache expiration time in seconds (1 hour).
     */
    const TRANSIENT_CACHE_EXPIRATION = 3600;

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
     * Fetches product catalog data and processes it synchronously in batches.
     * Creates new products or updates existing ones based on VendorCode (SKU).
     * After processing, immediately runs orphan cleanup to set missing products to out-of-stock.
     *
     * @return array{processed: int, total: int, session_id: string, created: int, updated: int, errors: int, orphans_zeroed: int} Sync statistics.
     * @throws \Throwable If API call fails.
     */
    public function import_products_catalog(): array {
        // Check if a catalog sync is already in progress (process locking)
        if ( get_transient( self::TRANSIENT_LOCK_CATALOG ) ) {
            Logger::instance()->log( 'Catalog import aborted: sync already in progress', [
                'user' => wp_get_current_user()->user_login ?? 'system',
            ] );
            throw new \Exception( __( 'Sync already in progress. Please wait.', 'erp-sync' ) );
        }

        // Set the lock before proceeding
        set_transient( self::TRANSIENT_LOCK_CATALOG, true, self::TRANSIENT_LOCK_EXPIRATION );

        // Close PHP session early to allow concurrent AJAX requests (progress polling)
        // This is critical to enable the progress bar to work while this long process executes
        if ( session_status() === PHP_SESSION_ACTIVE ) {
            session_write_close();
        }

        // Set reasonable max time limit for large syncs (30 minutes)
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit( 1800 );
        // Continue processing even if the user closes the browser
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @ignore_user_abort( true );

        Logger::instance()->log( 'Starting products catalog import (synchronous mode)', [
            'user' => wp_get_current_user()->user_login ?? 'system',
        ] );

        // Completion flag for strict cleanup gate
        $is_sync_complete = false;
        $session_id = '';
        $total = 0;
        $processed_batches = 0;

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

            // Branch filtering: only process products from active (non-hidden) branches
            $active_branches = $this->product_service->get_active_branches();
            $hidden_branches = $this->product_service->get_hidden_branches();

            if ( ! empty( $hidden_branches ) ) {
                $pre_filter_count = count( $rows );

                // Filter out products that belong exclusively to hidden branches
                // The 'Branch' field is populated from the SOAP response if available
                $rows = array_values( array_filter( $rows, function ( array $row ) use ( $hidden_branches ): bool {
                    $branch = trim( $row['Branch'] ?? '' );
                    // If no branch data in catalog row, keep the product (cannot filter)
                    if ( empty( $branch ) ) {
                        return true;
                    }
                    // Exclude products that belong to a hidden branch
                    return ! in_array( $branch, $hidden_branches, true );
                } ) );

                $filtered_count = $pre_filter_count - count( $rows );

                Logger::instance()->log( 'Catalog branch filtering applied', [
                    'active_branches'  => $active_branches,
                    'hidden_branches'  => $hidden_branches,
                    'pre_filter_count' => $pre_filter_count,
                    'post_filter_count'=> count( $rows ),
                    'filtered_out'     => $filtered_count,
                    'session_id'       => $session_id,
                ] );
            }

            $total = count( $rows );

            $this->set_progress( 0, $total, 'Processing catalog batches...' );

            // Split data into chunks of BATCH_SIZE items
            $chunks = array_chunk( $rows, self::BATCH_SIZE );
            $total_chunks = count( $chunks );

            // Aggregate statistics across all batches
            $aggregate_stats = [
                'created' => 0,
                'updated' => 0,
                'errors'  => 0,
            ];

            // Process each chunk synchronously
            foreach ( $chunks as $index => $chunk ) {
                $batch_stats = $this->product_service->sync_catalog_batch( $chunk, $session_id );

                // Aggregate stats
                $aggregate_stats['created'] += $batch_stats['created'];
                $aggregate_stats['updated'] += $batch_stats['updated'];
                $aggregate_stats['errors']  += $batch_stats['errors'];

                $processed_batches++;

                $this->set_progress(
                    ( $index + 1 ) * self::BATCH_SIZE,
                    $total,
                    sprintf( 'Processed batch %d of %d', $index + 1, $total_chunks )
                );

                // Memory management: clear caches after each batch to prevent memory exhaustion
                $this->product_service->clear_cache();
                $this->clear_memory_caches();
            }

            // Mark sync as complete ONLY after all batches have been processed
            $is_sync_complete = ( $processed_batches === $total_chunks );

            // Orphan cleanup permanently disabled - products not in feed will not be set to out of stock
            $orphan_count = 0;
            Logger::instance()->log( 'Orphan cleanup disabled by configuration. Skipping.', [ 'session_id' => $session_id, 'operation' => 'catalog_import' ] );

            // Update last sync time
            update_option( self::OPTION_LAST_PRODUCTS_SYNC, current_time( 'mysql' ) );

            Logger::instance()->log( 'Products catalog import completed (synchronous)', [
                'session_id'        => $session_id,
                'total_items'       => $total,
                'batch_size'        => self::BATCH_SIZE,
                'processed_batches' => $processed_batches,
                'created'           => $aggregate_stats['created'],
                'updated'           => $aggregate_stats['updated'],
                'errors'            => $aggregate_stats['errors'],
                'orphans_zeroed'    => $orphan_count,
                'user'              => wp_get_current_user()->user_login ?? 'system',
            ] );

            $this->clear_progress();

            return [
                'processed'      => $processed_batches,
                'total'          => $total,
                'session_id'     => $session_id,
                'created'        => $aggregate_stats['created'],
                'updated'        => $aggregate_stats['updated'],
                'errors'         => $aggregate_stats['errors'],
                'orphans_zeroed' => $orphan_count,
            ];

        } catch ( \Throwable $e ) {
            $this->clear_progress();
            Logger::instance()->log( 'Products catalog import failed', [
                'error' => $e->getMessage(),
                'user'  => wp_get_current_user()->user_login ?? 'system',
            ] );
            throw $e;
        } finally {
            // Always release the lock, even if the script crashes or throws an exception
            delete_transient( self::TRANSIENT_LOCK_CATALOG );
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
     * Fetches stock data and processes it synchronously in batches.
     * Only updates existing products; does not create new ones.
     * After processing, immediately runs orphan cleanup to set missing products to out-of-stock.
     *
     * @param string $vendor_codes Optional comma-separated list of VendorCodes (SKUs). Empty returns all.
     * @return array{processed: int, total: int, session_id: string, updated: int, skipped: int, errors: int, orphans_zeroed: int} Sync statistics.
     * @throws \Throwable If API call fails.
     */
    public function update_products_stock( string $vendor_codes = '' ): array {
        // Check if a stock sync is already in progress (process locking)
        // Only apply lock for full syncs (not partial SKU syncs)
        if ( empty( $vendor_codes ) && get_transient( self::TRANSIENT_LOCK_STOCK ) ) {
            Logger::instance()->log( 'Stock update aborted: sync already in progress', [
                'user' => wp_get_current_user()->user_login ?? 'system',
            ] );
            throw new \Exception( __( 'Sync already in progress. Please wait.', 'erp-sync' ) );
        }

        // Set the lock before proceeding (only for full syncs)
        if ( empty( $vendor_codes ) ) {
            set_transient( self::TRANSIENT_LOCK_STOCK, true, self::TRANSIENT_LOCK_EXPIRATION );
        }

        // Close PHP session early to allow concurrent AJAX requests (progress polling)
        // This is critical to enable the progress bar to work while this long process executes
        if ( session_status() === PHP_SESSION_ACTIVE ) {
            session_write_close();
        }

        // Set reasonable max time limit for large syncs (30 minutes)
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit( 1800 );
        // Continue processing even if the user closes the browser
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @ignore_user_abort( true );

        Logger::instance()->log( 'Starting products stock update (synchronous mode)', [
            'vendor_codes' => $vendor_codes ? substr( $vendor_codes, 0, 100 ) : '(all)',
            'user'         => wp_get_current_user()->user_login ?? 'system',
        ] );

        // Completion flag for strict cleanup gate
        $is_sync_complete = false;
        $session_id = '';
        $total = 0;
        $processed_batches = 0;

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

            $this->set_progress( 0, $total, 'Processing stock update batches...' );

            // Split data into chunks of BATCH_SIZE items
            $chunks = array_chunk( $rows, self::BATCH_SIZE );
            $total_chunks = count( $chunks );

            // Aggregate statistics across all batches
            $aggregate_stats = [
                'updated' => 0,
                'skipped' => 0,
                'errors'  => 0,
            ];

            // Process each chunk synchronously
            foreach ( $chunks as $index => $chunk ) {
                $batch_stats = $this->process_stock_batch( $chunk, $session_id );

                // Aggregate stats
                $aggregate_stats['updated'] += $batch_stats['updated'];
                $aggregate_stats['skipped'] += $batch_stats['skipped'];
                $aggregate_stats['errors']  += $batch_stats['errors'];

                $processed_batches++;

                $this->set_progress(
                    ( $index + 1 ) * self::BATCH_SIZE,
                    $total,
                    sprintf( 'Processed batch %d of %d', $index + 1, $total_chunks )
                );

                // Memory management: clear caches after each batch to prevent memory exhaustion
                $this->product_service->clear_cache();
                $this->clear_memory_caches();
            }

            // Mark sync as complete ONLY after all batches have been processed
            $is_sync_complete = ( $processed_batches === $total_chunks );

            // Orphan cleanup permanently disabled - products not in feed will not be set to out of stock
            $orphan_count = 0;
            Logger::instance()->log( 'Orphan cleanup disabled by configuration. Skipping.', [ 'session_id' => $session_id, 'operation' => 'stock_update' ] );

            // Update last sync time
            update_option( self::OPTION_LAST_STOCK_SYNC, current_time( 'mysql' ) );

            Logger::instance()->log( 'Products stock update completed (synchronous)', [
                'session_id'        => $session_id,
                'total_items'       => $total,
                'batch_size'        => self::BATCH_SIZE,
                'processed_batches' => $processed_batches,
                'updated'           => $aggregate_stats['updated'],
                'skipped'           => $aggregate_stats['skipped'],
                'errors'            => $aggregate_stats['errors'],
                'orphans_zeroed'    => $orphan_count,
                'user'              => wp_get_current_user()->user_login ?? 'system',
            ] );

            $this->clear_progress();

            return [
                'processed'      => $processed_batches,
                'total'          => $total,
                'session_id'     => $session_id,
                'updated'        => $aggregate_stats['updated'],
                'skipped'        => $aggregate_stats['skipped'],
                'errors'         => $aggregate_stats['errors'],
                'orphans_zeroed' => $orphan_count,
            ];

        } catch ( \Throwable $e ) {
            $this->clear_progress();
            Logger::instance()->log( 'Products stock update failed', [
                'error' => $e->getMessage(),
                'user'  => wp_get_current_user()->user_login ?? 'system',
            ] );
            throw $e;
        } finally {
            // Always release the lock, even if the script crashes or throws an exception
            // Only release lock for full syncs (as only full syncs set the lock)
            if ( empty( $vendor_codes ) ) {
                delete_transient( self::TRANSIENT_LOCK_STOCK );
            }
        }
    }

    /**
     * Step-based stock sync for AJAX batch processing.
     *
     * Handles 'init', 'process', and 'cleanup' steps for batch processing.
     *
     * @param string $step       Current step: 'init', 'process', or 'cleanup'.
     * @param int    $offset     Current offset for batch processing.
     * @param int    $batch_size Number of items to process per batch.
     * @param string $session_id Unique session identifier.
     * @return array Response data for the current step.
     * @throws \Exception If step fails.
     */
    public function update_products_stock_step( string $step, int $offset, int $batch_size, string $session_id ): array {
        $transient_key = self::TRANSIENT_STOCK_DATA_PREFIX . $session_id;

        switch ( $step ) {
            case 'init':
                return $this->init_stock_sync( $session_id, $transient_key );

            case 'process':
                return $this->process_stock_batch_from_cache( $session_id, $transient_key, $offset, $batch_size );

            case 'cleanup':
                return $this->cleanup_stock_sync( $session_id, $transient_key );

            default:
                throw new \Exception( __( 'Invalid sync step', 'erp-sync' ) );
        }
    }

    /**
     * Initialize stock sync: fetch data from API and cache it.
     *
     * @param string $session_id    Unique session identifier.
     * @param string $transient_key Transient key for caching.
     * @return array Response with total count.
     * @throws \Exception If API call fails or sync already in progress.
     */
    private function init_stock_sync( string $session_id, string $transient_key ): array {
        // Check if a stock sync is already in progress
        if ( get_transient( self::TRANSIENT_LOCK_STOCK ) ) {
            throw new \Exception( __( 'Sync already in progress. Please wait.', 'erp-sync' ) );
        }

        // Set the lock
        set_transient( self::TRANSIENT_LOCK_STOCK, $session_id, self::TRANSIENT_LOCK_EXPIRATION );

        Logger::instance()->log( 'Stock sync init step started', [
            'session_id' => $session_id,
            'user'       => wp_get_current_user()->user_login ?? 'system',
        ] );

        // Fetch stock data from API
        $rows = $this->api->fetch_products_stock();
        $total = count( $rows );

        // Store data in transient for batch processing
        set_transient( $transient_key, $rows, self::TRANSIENT_CACHE_EXPIRATION );

        // Store session metadata
        update_option( self::OPTION_ACTIVE_SESSION, [
            'session_id' => $session_id,
            'type'       => 'stock',
            'started_at' => current_time( 'mysql' ),
            'total'      => $total,
        ] );

        Logger::instance()->log( 'Stock sync init completed', [
            'session_id' => $session_id,
            'total'      => $total,
        ] );

        return [
            'message' => __( 'Stock data fetched and cached', 'erp-sync' ),
            'total'   => $total,
            'step'    => 'init',
        ];
    }

    /**
     * Process a batch of stock items from cache.
     *
     * @param string $session_id    Unique session identifier.
     * @param string $transient_key Transient key for cached data.
     * @param int    $offset        Current offset.
     * @param int    $batch_size    Number of items to process.
     * @return array Response with batch stats.
     * @throws \Exception If cached data is missing.
     */
    private function process_stock_batch_from_cache( string $session_id, string $transient_key, int $offset, int $batch_size ): array {
        // Retrieve cached data
        $rows = get_transient( $transient_key );

        if ( false === $rows || ! is_array( $rows ) ) {
            // Transient expired or missing - try to re-fetch
            Logger::instance()->log( 'Stock cache missing, re-fetching data', [
                'session_id' => $session_id,
                'offset'     => $offset,
            ] );

            $rows = $this->api->fetch_products_stock();
            set_transient( $transient_key, $rows, self::TRANSIENT_CACHE_EXPIRATION );
        }

        $total = count( $rows );

        // Get batch using array_slice
        $batch = array_slice( $rows, $offset, $batch_size );

        if ( empty( $batch ) ) {
            // No more items to process
            return [
                'message'     => __( 'No more items to process', 'erp-sync' ),
                'total'       => $total,
                'next_offset' => $total,
                'processed'   => 0,
                'updated'     => 0,
                'skipped'     => 0,
                'errors'      => 0,
                'step'        => 'process',
            ];
        }

        // Process this batch
        $stats = $this->process_stock_batch( $batch, $session_id );

        // Memory management
        $this->product_service->clear_cache();
        $this->clear_memory_caches();

        $next_offset = $offset + count( $batch );

        // Update progress
        $this->set_progress( $next_offset, $total, sprintf( 'Processing batch at offset %d', $offset ) );

        Logger::instance()->log( 'Stock batch processed from cache', [
            'session_id'  => $session_id,
            'offset'      => $offset,
            'batch_size'  => count( $batch ),
            'next_offset' => $next_offset,
            'updated'     => $stats['updated'],
            'skipped'     => $stats['skipped'],
            'errors'      => $stats['errors'],
        ] );

        return [
            'message'     => sprintf( __( 'Processed batch at offset %d', 'erp-sync' ), $offset ),
            'total'       => $total,
            'next_offset' => $next_offset,
            'processed'   => count( $batch ),
            'updated'     => $stats['updated'] ?? 0,
            'skipped'     => $stats['skipped'] ?? 0,
            'errors'      => $stats['errors'] ?? 0,
            'step'        => 'process',
        ];
    }

    /**
     * Cleanup stock sync: run orphan cleanup and delete transient.
     *
     * @param string $session_id    Unique session identifier.
     * @param string $transient_key Transient key for cached data.
     * @return array Response with cleanup stats.
     */
    private function cleanup_stock_sync( string $session_id, string $transient_key ): array {
        Logger::instance()->log( 'Stock sync cleanup step started', [
            'session_id' => $session_id,
        ] );

        // Orphan cleanup permanently disabled - products not in feed will not be set to out of stock
        $orphan_count = 0;
        Logger::instance()->log( 'Orphan cleanup disabled by configuration. Skipping.', [
            'session_id' => $session_id,
            'operation'  => 'stock_update_step',
        ] );

        // Delete the cached data transient
        delete_transient( $transient_key );

        // Release the lock
        delete_transient( self::TRANSIENT_LOCK_STOCK );

        // Clear active session
        delete_option( self::OPTION_ACTIVE_SESSION );

        // Update last sync time
        update_option( self::OPTION_LAST_STOCK_SYNC, current_time( 'mysql' ) );

        // Clear progress
        $this->clear_progress();

        Logger::instance()->log( 'Stock sync cleanup completed', [
            'session_id'     => $session_id,
            'orphans_zeroed' => $orphan_count,
        ] );

        return [
            'message'        => __( 'Stock sync completed successfully', 'erp-sync' ),
            'orphans_zeroed' => $orphan_count,
            'step'           => 'cleanup',
        ];
    }

    /**
     * Step-based catalog sync for AJAX batch processing.
     *
     * Handles 'init', 'process', and 'cleanup' steps for batch processing.
     *
     * @param string $step       Current step: 'init', 'process', or 'cleanup'.
     * @param int    $offset     Current offset for batch processing.
     * @param int    $batch_size Number of items to process per batch.
     * @param string $session_id Unique session identifier.
     * @return array Response data for the current step.
     * @throws \Exception If step fails.
     */
    public function import_products_catalog_step( string $step, int $offset, int $batch_size, string $session_id ): array {
        $transient_key = self::TRANSIENT_CATALOG_DATA_PREFIX . $session_id;

        switch ( $step ) {
            case 'init':
                return $this->init_catalog_sync( $session_id, $transient_key );

            case 'process':
                return $this->process_catalog_batch_from_cache( $session_id, $transient_key, $offset, $batch_size );

            case 'cleanup':
                return $this->cleanup_catalog_sync( $session_id, $transient_key );

            default:
                throw new \Exception( __( 'Invalid sync step', 'erp-sync' ) );
        }
    }

    /**
     * Initialize catalog sync: fetch data from API and cache it.
     *
     * @param string $session_id    Unique session identifier.
     * @param string $transient_key Transient key for caching.
     * @return array Response with total count.
     * @throws \Exception If API call fails or sync already in progress.
     */
    private function init_catalog_sync( string $session_id, string $transient_key ): array {
        // Check if a catalog sync is already in progress
        if ( get_transient( self::TRANSIENT_LOCK_CATALOG ) ) {
            throw new \Exception( __( 'Sync already in progress. Please wait.', 'erp-sync' ) );
        }

        // Set the lock
        set_transient( self::TRANSIENT_LOCK_CATALOG, $session_id, self::TRANSIENT_LOCK_EXPIRATION );

        Logger::instance()->log( 'Catalog sync init step started', [
            'session_id' => $session_id,
            'user'       => wp_get_current_user()->user_login ?? 'system',
        ] );

        // Fetch catalog data from API
        $rows = $this->api->fetch_products_catalog();

        // Branch filtering: only process products from active (non-hidden) branches
        $hidden_branches = $this->product_service->get_hidden_branches();

        if ( ! empty( $hidden_branches ) ) {
            $pre_filter_count = count( $rows );

            // Filter out products that belong exclusively to hidden branches
            // The 'Branch' field is populated from the SOAP response if available
            $rows = array_values( array_filter( $rows, function ( array $row ) use ( $hidden_branches ): bool {
                $branch = trim( $row['Branch'] ?? '' );
                // If no branch data in catalog row, keep the product (cannot filter)
                if ( empty( $branch ) ) {
                    return true;
                }
                // Exclude products that belong to a hidden branch
                return ! in_array( $branch, $hidden_branches, true );
            } ) );

            Logger::instance()->log( 'Catalog branch filtering applied (init step)', [
                'hidden_branches'  => $hidden_branches,
                'pre_filter_count' => $pre_filter_count,
                'post_filter_count'=> count( $rows ),
                'filtered_out'     => $pre_filter_count - count( $rows ),
                'session_id'       => $session_id,
            ] );
        }

        $total = count( $rows );

        // Store data in transient for batch processing
        set_transient( $transient_key, $rows, self::TRANSIENT_CACHE_EXPIRATION );

        // Store session metadata
        update_option( self::OPTION_ACTIVE_SESSION, [
            'session_id' => $session_id,
            'type'       => 'catalog',
            'started_at' => current_time( 'mysql' ),
            'total'      => $total,
        ] );

        Logger::instance()->log( 'Catalog sync init completed', [
            'session_id' => $session_id,
            'total'      => $total,
        ] );

        return [
            'message' => __( 'Catalog data fetched and cached', 'erp-sync' ),
            'total'   => $total,
            'step'    => 'init',
        ];
    }

    /**
     * Process a batch of catalog items from cache.
     *
     * @param string $session_id    Unique session identifier.
     * @param string $transient_key Transient key for cached data.
     * @param int    $offset        Current offset.
     * @param int    $batch_size    Number of items to process.
     * @return array Response with batch stats.
     * @throws \Exception If cached data is missing.
     */
    private function process_catalog_batch_from_cache( string $session_id, string $transient_key, int $offset, int $batch_size ): array {
        // Retrieve cached data
        $rows = get_transient( $transient_key );

        if ( false === $rows || ! is_array( $rows ) ) {
            // Transient expired or missing - try to re-fetch
            Logger::instance()->log( 'Catalog cache missing, re-fetching data', [
                'session_id' => $session_id,
                'offset'     => $offset,
            ] );

            $rows = $this->api->fetch_products_catalog();
            set_transient( $transient_key, $rows, self::TRANSIENT_CACHE_EXPIRATION );
        }

        $total = count( $rows );

        // Get batch using array_slice
        $batch = array_slice( $rows, $offset, $batch_size );

        if ( empty( $batch ) ) {
            // No more items to process
            return [
                'message'     => __( 'No more items to process', 'erp-sync' ),
                'total'       => $total,
                'next_offset' => $total,
                'processed'   => 0,
                'created'     => 0,
                'updated'     => 0,
                'errors'      => 0,
                'step'        => 'process',
            ];
        }

        // Process this batch
        $stats = $this->product_service->sync_catalog_batch( $batch, $session_id );

        // Memory management
        $this->product_service->clear_cache();
        $this->clear_memory_caches();

        $next_offset = $offset + count( $batch );

        // Update progress
        $this->set_progress( $next_offset, $total, sprintf( 'Processing batch at offset %d', $offset ) );

        Logger::instance()->log( 'Catalog batch processed from cache', [
            'session_id'  => $session_id,
            'offset'      => $offset,
            'batch_size'  => count( $batch ),
            'next_offset' => $next_offset,
            'created'     => $stats['created'],
            'updated'     => $stats['updated'],
            'errors'      => $stats['errors'],
        ] );

        return [
            'message'     => sprintf( __( 'Processed batch at offset %d', 'erp-sync' ), $offset ),
            'total'       => $total,
            'next_offset' => $next_offset,
            'processed'   => count( $batch ),
            'created'     => $stats['created'] ?? 0,
            'updated'     => $stats['updated'] ?? 0,
            'errors'      => $stats['errors'] ?? 0,
            'step'        => 'process',
        ];
    }

    /**
     * Cleanup catalog sync: run orphan cleanup and delete transient.
     *
     * @param string $session_id    Unique session identifier.
     * @param string $transient_key Transient key for cached data.
     * @return array Response with cleanup stats.
     */
    private function cleanup_catalog_sync( string $session_id, string $transient_key ): array {
        Logger::instance()->log( 'Catalog sync cleanup step started', [
            'session_id' => $session_id,
        ] );

        // Orphan cleanup permanently disabled - products not in feed will not be set to out of stock
        $orphan_count = 0;
        Logger::instance()->log( 'Orphan cleanup disabled by configuration. Skipping.', [
            'session_id' => $session_id,
            'operation'  => 'catalog_import_step',
        ] );

        // Delete the cached data transient
        delete_transient( $transient_key );

        // Release the lock
        delete_transient( self::TRANSIENT_LOCK_CATALOG );

        // Clear active session
        delete_option( self::OPTION_ACTIVE_SESSION );

        // Update last sync time
        update_option( self::OPTION_LAST_PRODUCTS_SYNC, current_time( 'mysql' ) );

        // Clear progress
        $this->clear_progress();

        Logger::instance()->log( 'Catalog sync cleanup completed', [
            'session_id'     => $session_id,
            'orphans_zeroed' => $orphan_count,
        ] );

        return [
            'message'        => __( 'Catalog sync completed successfully', 'erp-sync' ),
            'orphans_zeroed' => $orphan_count,
            'step'           => 'cleanup',
        ];
    }

    /**
     * Clear PHP memory caches to prevent memory exhaustion during large syncs.
     *
     * This method should be called after processing each batch to free up memory.
     */
    private function clear_memory_caches(): void {
        // Clear WordPress object cache
        wp_cache_flush();

        // Clear WooCommerce term caches
        if ( function_exists( 'wc_delete_product_transients' ) ) {
            // We don't call this per-product as it's expensive, but clear global caches
            delete_transient( 'wc_products_onsale' );
            delete_transient( 'wc_featured_products' );
        }

        // Force PHP garbage collection if available
        if ( function_exists( 'gc_collect_cycles' ) ) {
            gc_collect_cycles();
        }
    }

    /**
     * Synchronous orphan cleanup.
     *
     * Sets stock to 0 for products that were not touched during the sync session.
     * Called immediately after batch processing completes.
     *
     * @param string $session_id Unique session identifier for the sync that completed.
     * @return int Number of orphaned products that were zeroed out.
     */
    private function cleanup_orphans_sync( string $session_id ): int {
        Logger::instance()->log( 'Starting synchronous orphan cleanup', [
            'session_id' => $session_id,
        ] );

        try {
            $orphan_count = $this->product_service->zero_out_orphans( $session_id );

            Logger::instance()->log( 'Synchronous orphan cleanup completed', [
                'session_id'    => $session_id,
                'orphan_count'  => $orphan_count,
            ] );

            // Clear active session after cleanup
            delete_option( self::OPTION_ACTIVE_SESSION );

            return $orphan_count;

        } catch ( \Throwable $e ) {
            Logger::instance()->log( 'Synchronous orphan cleanup failed', [
                'session_id' => $session_id,
                'error'      => $e->getMessage(),
            ] );
            return 0;
        }
    }

    /**
     * Process a single stock batch.
     *
     * Called synchronously during stock update. Also kept for backward compatibility
     * with Action Scheduler hooks if needed.
     *
     * @param array  $batch      Array of stock rows to process.
     * @param string $session_id Unique session identifier for this sync.
     * @return array{updated: int, skipped: int, errors: int, total: int} Batch processing statistics.
     */
    public function process_stock_batch( array $batch, string $session_id ): array {
        Logger::instance()->log( 'Processing stock batch', [
            'session_id' => $session_id,
            'batch_size' => count( $batch ),
        ] );

        $default_stats = [
            'updated' => 0,
            'skipped' => 0,
            'errors'  => 0,
            'total'   => count( $batch ),
        ];

        try {
            $stats = $this->product_service->sync_stock_batch( $batch, $session_id );

            Logger::instance()->log( 'Stock batch processed', [
                'session_id' => $session_id,
                'updated'    => $stats['updated'],
                'skipped'    => $stats['skipped'],
                'errors'     => $stats['errors'],
                'total'      => $stats['total'],
            ] );

            return $stats;

        } catch ( \Throwable $e ) {
            Logger::instance()->log( 'Stock batch processing failed', [
                'session_id' => $session_id,
                'error'      => $e->getMessage(),
            ] );

            return $default_stats;
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

            // If API returns empty result, treat it as product being out of stock
            // This is the expected behavior when a product is no longer in the ERP
            if ( empty( $rows ) ) {
                Logger::instance()->log( 'SKU not found in ERP during single update. Stock set to 0.', [
                    'product_id' => $product_id,
                    'sku'        => $sku,
                ] );

                // Set product stock to 0 and mark as out of stock
                $old_stock = $product->get_stock_quantity();
                $product->set_manage_stock( true );
                $product->set_stock_quantity( 0 );
                $product->set_stock_status( 'outofstock' );
                // Mark as ERP-managed so it's tracked in future syncs
                $product->update_meta_data( '_erp_sync_managed', 1 );
                $product->update_meta_data( '_erp_sync_last_update', current_time( 'mysql' ) );
                $product->update_meta_data( '_erp_sync_zeroed_reason', 'not_found_in_erp' );
                $product->save();

                // Log the stock change for audit
                if ( class_exists( '\ERPSync\Audit_Logger' ) ) {
                    Audit_Logger::log_change(
                        $product,
                        'single_sync_not_found',
                        $old_stock,
                        0,
                        sprintf( 'SKU %s not found in ERP during single update. Stock set to 0.', $sku )
                    );
                }

                return true;
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
