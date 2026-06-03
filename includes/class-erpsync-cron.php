<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

class Cron {
    // Existing coupon sync options
    const OPTION_CRON_ENABLED     = 'erp_sync_cron_enabled';
    const OPTION_CRON_INTERVAL    = 'erp_sync_cron_interval';
    const OPTION_CRON_LAST_RESULT = 'erp_sync_cron_last_result';
    const LOCK_TRANSIENT          = 'erp_sync_cron_lock';
    const HOOK_EVENT              = 'erp_sync_cron_sync';

    // New product sync options
    const OPTION_CATALOG_CRON_ENABLED     = 'erp_sync_catalog_cron_enabled';
    const OPTION_CATALOG_CRON_INTERVAL    = 'erp_sync_catalog_cron_interval';
    const OPTION_CATALOG_CRON_LAST_RESULT = 'erp_sync_catalog_cron_last_result';
    const LOCK_CATALOG_TRANSIENT          = 'erp_sync_catalog_cron_lock';
    const HOOK_CATALOG_EVENT              = 'erpsync_cron_catalog_sync';

    const OPTION_STOCK_CRON_ENABLED     = 'erp_sync_stock_cron_enabled';
    const OPTION_STOCK_CRON_INTERVAL    = 'erp_sync_stock_cron_interval';
    const OPTION_STOCK_CRON_LAST_RESULT = 'erp_sync_stock_cron_last_result';
    const LOCK_STOCK_TRANSIENT          = 'erp_sync_stock_cron_lock';
    const HOOK_STOCK_EVENT              = 'erpsync_cron_stock_sync';

    public static function init(): void {
        add_filter( 'cron_schedules', [ __CLASS__, 'register_schedules' ] );
        
        // Coupon sync (existing)
        add_action( self::HOOK_EVENT, [ __CLASS__, 'run' ] );
        
        // Product catalog sync (new)
        add_action( self::HOOK_CATALOG_EVENT, [ __CLASS__, 'run_catalog_sync' ] );
        
        // Stock & prices sync (new)
        add_action( self::HOOK_STOCK_EVENT, [ __CLASS__, 'run_stock_sync' ] );
        
        add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
    }

    public static function activate(): void {
        self::maybe_schedule(true);
    }

    public static function deactivate(): void {
        self::clear_schedule();
        self::clear_catalog_schedule();
        self::clear_stock_schedule();
    }

    public static function register_schedules( array $schedules ): array {
        // Short intervals for stock sync
        $schedules['erp_sync_5min'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 5 minutes (ERP Sync)', 'erp-sync' ),
        ];
        $schedules['erp_sync_10min'] = [
            'interval' => 10 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 10 minutes (ERP Sync)', 'erp-sync' ),
        ];
        $schedules['erp_sync_15min'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 15 minutes (ERP Sync)', 'erp-sync' ),
        ];
        $schedules['erp_sync_30min'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 30 minutes (ERP Sync)', 'erp-sync' ),
        ];
        // Longer intervals for catalog sync
        $schedules['erp_sync_hourly'] = [
            'interval' => HOUR_IN_SECONDS,
            'display'  => __( 'Hourly (ERP Sync)', 'erp-sync' ),
        ];
        $schedules['erp_sync_6h'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => __( 'Every 6 hours (ERP Sync)', 'erp-sync' ),
        ];
        $schedules['erp_sync_twicedaily'] = [
            'interval' => 12 * HOUR_IN_SECONDS,
            'display'  => __( 'Twice Daily (ERP Sync)', 'erp-sync' ),
        ];
        $schedules['erp_sync_daily'] = [
            'interval' => DAY_IN_SECONDS,
            'display'  => __( 'Daily (ERP Sync)', 'erp-sync' ),
        ];
        return $schedules;
    }

    public static function is_enabled(): bool {
        return (bool) get_option( self::OPTION_CRON_ENABLED, false );
    }

    public static function is_catalog_enabled(): bool {
        return (bool) get_option( self::OPTION_CATALOG_CRON_ENABLED, false );
    }

    public static function is_stock_enabled(): bool {
        return (bool) get_option( self::OPTION_STOCK_CRON_ENABLED, false );
    }

    /**
     * Valid intervals for the coupon (discount-card) sync.
     *
     * The coupon sync now pulls the COMPLETE 1C card list (~6 min) into the local
     * cache and pushes only changed cards to WooCommerce. That is far too heavy to
     * run on a sub-hour schedule, so only 1h / 6h / 12h / 24h are allowed. Legacy
     * 5/10/15-min values are migrated up to 6h automatically.
     */
    const COUPON_INTERVALS = [ 'erp_sync_hourly', 'erp_sync_6h', 'erp_sync_twicedaily', 'erp_sync_daily' ];

    public static function get_interval_key(): string {
        $key = (string) get_option( self::OPTION_CRON_INTERVAL, 'erp_sync_6h' );

        // Support migration from old WDCS interval keys (one-time check)
        if ( strpos( $key, 'wdcs_' ) === 0 ) {
            $key = str_replace( 'wdcs_', 'erp_sync_', $key );
        }

        // Legacy sub-hour intervals are unsafe for the full-card sync — bump to 6h.
        if ( in_array( $key, [ 'erp_sync_5min', 'erp_sync_10min', 'erp_sync_15min', 'erp_sync_30min' ], true ) ) {
            $key = 'erp_sync_6h';
            update_option( self::OPTION_CRON_INTERVAL, $key );
        }

        if ( ! in_array( $key, self::COUPON_INTERVALS, true ) ) {
            $key = 'erp_sync_6h';
        }
        return $key;
    }

    public static function get_catalog_interval_key(): string {
        $key = (string) get_option( self::OPTION_CATALOG_CRON_INTERVAL, 'erp_sync_daily' );
        $valid_keys = [ 'erp_sync_hourly', 'erp_sync_twicedaily', 'erp_sync_daily' ];
        if ( ! in_array( $key, $valid_keys, true ) ) {
            $key = 'erp_sync_daily';
        }
        return $key;
    }

    public static function get_stock_interval_key(): string {
        $key = (string) get_option( self::OPTION_STOCK_CRON_INTERVAL, 'erp_sync_15min' );
        $valid_keys = [ 'erp_sync_5min', 'erp_sync_10min', 'erp_sync_15min', 'erp_sync_30min', 'erp_sync_hourly' ];
        if ( ! in_array( $key, $valid_keys, true ) ) {
            $key = 'erp_sync_15min';
        }
        return $key;
    }

    public static function maybe_schedule( bool $force = false ): void {
        // Schedule coupon sync (existing logic)
        $enabled  = self::is_enabled();
        $interval = self::get_interval_key();
        $scheduled_ts = wp_next_scheduled( self::HOOK_EVENT );

        if ( $enabled ) {
            if ( ! $scheduled_ts || $force ) {
                self::clear_schedule();
                wp_schedule_event( time() + 60, $interval, self::HOOK_EVENT );
                Logger::instance()->log( 'ERP Sync coupon cron scheduled', [ 'interval' => $interval ] );
            }
        } else {
            if ( $scheduled_ts ) {
                self::clear_schedule();
                Logger::instance()->log( 'ERP Sync coupon cron unscheduled', [] );
            }
        }

        // Schedule catalog sync
        $catalog_enabled = self::is_catalog_enabled();
        $catalog_interval = self::get_catalog_interval_key();
        $catalog_scheduled_ts = wp_next_scheduled( self::HOOK_CATALOG_EVENT );

        if ( $catalog_enabled ) {
            if ( ! $catalog_scheduled_ts || $force ) {
                self::clear_catalog_schedule();
                wp_schedule_event( time() + 120, $catalog_interval, self::HOOK_CATALOG_EVENT );
                Logger::instance()->log( 'ERP Sync catalog cron scheduled', [ 'interval' => $catalog_interval ] );
            }
        } else {
            if ( $catalog_scheduled_ts ) {
                self::clear_catalog_schedule();
                Logger::instance()->log( 'ERP Sync catalog cron unscheduled', [] );
            }
        }

        // Schedule stock sync
        $stock_enabled = self::is_stock_enabled();
        $stock_interval = self::get_stock_interval_key();
        $stock_scheduled_ts = wp_next_scheduled( self::HOOK_STOCK_EVENT );

        if ( $stock_enabled ) {
            if ( ! $stock_scheduled_ts || $force ) {
                self::clear_stock_schedule();
                wp_schedule_event( time() + 180, $stock_interval, self::HOOK_STOCK_EVENT );
                Logger::instance()->log( 'ERP Sync stock cron scheduled', [ 'interval' => $stock_interval ] );
            }
        } else {
            if ( $stock_scheduled_ts ) {
                self::clear_stock_schedule();
                Logger::instance()->log( 'ERP Sync stock cron unscheduled', [] );
            }
        }
    }

    public static function reschedule_after_settings_change(): void {
        self::maybe_schedule( true );
    }

    public static function clear_schedule(): void {
        $ts = wp_next_scheduled( self::HOOK_EVENT );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::HOOK_EVENT );
        }
    }

    public static function clear_catalog_schedule(): void {
        $ts = wp_next_scheduled( self::HOOK_CATALOG_EVENT );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::HOOK_CATALOG_EVENT );
        }
    }

    public static function clear_stock_schedule(): void {
        $ts = wp_next_scheduled( self::HOOK_STOCK_EVENT );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::HOOK_STOCK_EVENT );
        }
    }

    /**
     * Run the coupon (discount-card) sync.
     *
     * Refreshes the local card cache from 1C and pushes only NEW/CHANGED cards
     * into WooCommerce coupons (see Cards_Cache::refresh). This replaces the old
     * "fetch all cards + rewrite every coupon on every run" behaviour, which
     * pulled ~15 MB / 38k rows over a ~6-minute SOAP call every cycle and tied up
     * a PHP worker — unsafe on a short schedule.
     *
     * Intended to run from system cron / WP-CLI, never a web request.
     */
    public static function run(): void {
        if ( get_transient( self::LOCK_TRANSIENT ) ) {
            Logger::instance()->log( 'ERP Sync coupon cron skipped (locked)', [] );
            return;
        }
        set_transient( self::LOCK_TRANSIENT, 1, 30 * MINUTE_IN_SECONDS );

        $start  = microtime( true );
        $result = [ 'time' => current_time( 'mysql' ), 'success' => false, 'created' => 0, 'updated' => 0, 'remote' => 0, 'error' => '' ];

        Logger::instance()->log( 'ERP Sync coupon cron run start', [
            'php_mem_usage_kb' => (int) ( memory_get_usage( true ) / 1024 ),
            'php_mem_peak_kb'  => (int) ( memory_get_peak_usage( true ) / 1024 ),
            'interval'         => self::get_interval_key(),
            'mode'             => 'card-cache',
        ] );

        try {
            $stats = Cards_Cache::refresh( true );

            if ( ! empty( $stats['skipped'] ) ) {
                // A refresh was already running (manual trigger / overlap) — not an error.
                Logger::instance()->log( 'ERP Sync coupon cron: cache refresh already running, skipped', [] );
                delete_transient( self::LOCK_TRANSIENT );
                return;
            }

            $result['success']   = ! empty( $stats['success'] );
            $result['created']   = (int) ( $stats['wc_created'] ?? 0 );
            $result['updated']   = (int) ( $stats['wc_updated'] ?? 0 );
            $result['remote']    = (int) ( $stats['fetched'] ?? 0 );
            $result['new']       = (int) ( $stats['new'] ?? 0 );
            $result['changed']   = (int) ( $stats['changed'] ?? 0 );
            $result['unchanged'] = (int) ( $stats['unchanged'] ?? 0 );
            $result['removed']   = (int) ( $stats['removed'] ?? 0 );
            $result['wc_errors'] = (int) ( $stats['wc_errors'] ?? 0 );
            if ( ! empty( $stats['error'] ) ) {
                $result['error'] = (string) $stats['error'];
            }
        } catch ( \Throwable $e ) {
            $result['error'] = $e->getMessage();
            Logger::instance()->log( 'ERP Sync coupon cron import failed', [ 'error' => $e->getMessage() ] );
        } finally {
            $duration_ms           = (int) round( ( microtime( true ) - $start ) * 1000 );
            $result['duration_ms'] = $duration_ms;
            $result['mem_peak_kb'] = (int) ( memory_get_peak_usage( true ) / 1024 );
            update_option( self::OPTION_CRON_LAST_RESULT, $result );

            Logger::instance()->log( 'ERP Sync coupon cron run end', [
                'success'     => $result['success'] ? 1 : 0,
                'remote'      => $result['remote'],
                'new'         => $result['new'] ?? 0,
                'changed'     => $result['changed'] ?? 0,
                'unchanged'   => $result['unchanged'] ?? 0,
                'removed'     => $result['removed'] ?? 0,
                'wc_created'  => $result['created'],
                'wc_updated'  => $result['updated'],
                'wc_errors'   => $result['wc_errors'] ?? 0,
                'error'       => $result['error'],
                'duration_ms' => $duration_ms,
                'mem_peak_kb' => $result['mem_peak_kb'],
            ] );

            delete_transient( self::LOCK_TRANSIENT );
        }
    }

    /**
     * Run catalog sync (products catalog)
     */
    public static function run_catalog_sync(): void {
        if ( get_transient( self::LOCK_CATALOG_TRANSIENT ) ) {
            Logger::instance()->log( 'ERP Sync catalog cron skipped (locked)', [] );
            return;
        }
        set_transient( self::LOCK_CATALOG_TRANSIENT, 1, 30 * MINUTE_IN_SECONDS );

        $start = microtime(true);
        $result = [
            'time'    => current_time('mysql'),
            'success' => false,
            'created' => 0,
            'updated' => 0,
            'errors'  => 0,
            'total'   => 0,
            'error'   => '',
        ];

        Logger::instance()->log( 'ERP Sync catalog cron run start', [
            'php_mem_usage_kb' => (int) ( memory_get_usage(true) / 1024 ),
            'php_mem_peak_kb'  => (int) ( memory_get_peak_usage(true) / 1024 ),
            'interval'         => self::get_catalog_interval_key(),
        ] );

        try {
            $svc = new Sync_Service( new API_Client() );
            $res = $svc->import_products_catalog();
            $result['success'] = true;
            $result['created'] = (int) ( $res['created'] ?? 0 );
            $result['updated'] = (int) ( $res['updated'] ?? 0 );
            $result['errors']  = (int) ( $res['errors'] ?? 0 );
            $result['total']   = (int) ( $res['total'] ?? 0 );
        } catch ( \Throwable $e ) {
            $result['error'] = $e->getMessage();
            Logger::instance()->log( 'ERP Sync catalog cron import failed', [ 'error' => $e->getMessage() ] );
        } finally {
            $duration_ms = (int) round( ( microtime(true) - $start ) * 1000 );
            $result['duration_ms'] = $duration_ms;
            $result['mem_peak_kb'] = (int) ( memory_get_peak_usage(true) / 1024 );
            update_option( self::OPTION_CATALOG_CRON_LAST_RESULT, $result );

            Logger::instance()->log( 'ERP Sync catalog cron run end', [
                'success'        => $result['success'] ? 1 : 0,
                'created'        => $result['created'],
                'updated'        => $result['updated'],
                'errors'         => $result['errors'],
                'total'          => $result['total'],
                'error'          => $result['error'],
                'duration_ms'    => $duration_ms,
                'mem_peak_kb'    => $result['mem_peak_kb'],
            ] );

            delete_transient( self::LOCK_CATALOG_TRANSIENT );
        }
    }

    /**
     * Run stock sync (stock & prices)
     */
    public static function run_stock_sync(): void {
        if ( get_transient( self::LOCK_STOCK_TRANSIENT ) ) {
            Logger::instance()->log( 'ERP Sync stock cron skipped (locked)', [] );
            return;
        }
        set_transient( self::LOCK_STOCK_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );

        $start = microtime(true);
        $result = [
            'time'    => current_time('mysql'),
            'success' => false,
            'updated' => 0,
            'skipped' => 0,
            'errors'  => 0,
            'total'   => 0,
            'error'   => '',
        ];

        Logger::instance()->log( 'ERP Sync stock cron run start', [
            'php_mem_usage_kb' => (int) ( memory_get_usage(true) / 1024 ),
            'php_mem_peak_kb'  => (int) ( memory_get_peak_usage(true) / 1024 ),
            'interval'         => self::get_stock_interval_key(),
        ] );

        try {
            $svc = new Sync_Service( new API_Client() );
            $res = $svc->update_products_stock();
            $result['success'] = true;
            $result['updated'] = (int) ( $res['updated'] ?? 0 );
            $result['skipped'] = (int) ( $res['skipped'] ?? 0 );
            $result['errors']  = (int) ( $res['errors'] ?? 0 );
            $result['total']   = (int) ( $res['total'] ?? 0 );
        } catch ( \Throwable $e ) {
            $result['error'] = $e->getMessage();
            Logger::instance()->log( 'ERP Sync stock cron update failed', [ 'error' => $e->getMessage() ] );
        } finally {
            $duration_ms = (int) round( ( microtime(true) - $start ) * 1000 );
            $result['duration_ms'] = $duration_ms;
            $result['mem_peak_kb'] = (int) ( memory_get_peak_usage(true) / 1024 );
            update_option( self::OPTION_STOCK_CRON_LAST_RESULT, $result );

            Logger::instance()->log( 'ERP Sync stock cron run end', [
                'success'        => $result['success'] ? 1 : 0,
                'updated'        => $result['updated'],
                'skipped'        => $result['skipped'],
                'errors'         => $result['errors'],
                'total'          => $result['total'],
                'error'          => $result['error'],
                'duration_ms'    => $duration_ms,
                'mem_peak_kb'    => $result['mem_peak_kb'],
            ] );

            delete_transient( self::LOCK_STOCK_TRANSIENT );
        }
    }

    public static function next_run_human(): string {
        $ts = wp_next_scheduled( self::HOOK_EVENT );
        if ( ! $ts ) return '—';
        return date_i18n( 'Y-m-d H:i:s', $ts + ( (int) get_option('gmt_offset') * HOUR_IN_SECONDS ) );
    }

    public static function next_catalog_run_human(): string {
        $ts = wp_next_scheduled( self::HOOK_CATALOG_EVENT );
        if ( ! $ts ) return '—';
        return date_i18n( 'Y-m-d H:i:s', $ts + ( (int) get_option('gmt_offset') * HOUR_IN_SECONDS ) );
    }

    public static function next_stock_run_human(): string {
        $ts = wp_next_scheduled( self::HOOK_STOCK_EVENT );
        if ( ! $ts ) return '—';
        return date_i18n( 'Y-m-d H:i:s', $ts + ( (int) get_option('gmt_offset') * HOUR_IN_SECONDS ) );
    }
}
