<?php
namespace WDCS;

if ( ! defined( 'ABSPATH' ) ) exit;

class Cron {
    const OPTION_CRON_ENABLED     = 'wdcs_cron_enabled';
    const OPTION_CRON_INTERVAL    = 'wdcs_cron_interval';
    const OPTION_CRON_LAST_RESULT = 'wdcs_cron_last_result';
    const LOCK_TRANSIENT          = 'wdcs_cron_lock';
    const HOOK_EVENT              = 'wdcs_cron_sync';

    public static function init() {
        add_filter( 'cron_schedules', [ __CLASS__, 'register_schedules' ] );
        add_action( self::HOOK_EVENT, [ __CLASS__, 'run' ] );
        add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
    }

    public static function activate() {
        self::maybe_schedule(true);
    }

    public static function deactivate() {
        self::clear_schedule();
    }

    public static function register_schedules( $schedules ) {
        $schedules['wdcs_5min'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 5 minutes (WDCS)', 'wdcs' ),
        ];
        $schedules['wdcs_10min'] = [
            'interval' => 10 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 10 minutes (WDCS)', 'wdcs' ),
        ];
        $schedules['wdcs_15min'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 15 minutes (WDCS)', 'wdcs' ),
        ];
        return $schedules;
    }

    public static function is_enabled() {
        return (bool) get_option( self::OPTION_CRON_ENABLED, false );
    }

    public static function get_interval_key() {
        $key = get_option( self::OPTION_CRON_INTERVAL, 'wdcs_10min' );
        if ( ! in_array( $key, [ 'wdcs_5min','wdcs_10min','wdcs_15min' ], true ) ) {
            $key = 'wdcs_10min';
        }
        return $key;
    }

    public static function maybe_schedule( $force = false ) {
        $enabled  = self::is_enabled();
        $interval = self::get_interval_key();
        $scheduled_ts = wp_next_scheduled( self::HOOK_EVENT );

        if ( $enabled ) {
            if ( ! $scheduled_ts || $force ) {
                self::clear_schedule();
                wp_schedule_event( time() + 60, $interval, self::HOOK_EVENT );
                Logger::instance()->log( 'WDCS cron scheduled', [ 'interval' => $interval ] );
            }
        } else {
            if ( $scheduled_ts ) {
                self::clear_schedule();
                Logger::instance()->log( 'WDCS cron unscheduled', [] );
            }
        }
    }

    public static function reschedule_after_settings_change() {
        self::maybe_schedule( true );
    }

    public static function clear_schedule() {
        $ts = wp_next_scheduled( self::HOOK_EVENT );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::HOOK_EVENT );
        }
    }

    public static function run() {
        if ( get_transient( self::LOCK_TRANSIENT ) ) {
            Logger::instance()->log( 'WDCS cron skipped (locked)', [] );
            return;
        }
        set_transient( self::LOCK_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );

        $start = microtime(true);
        $result = [ 'time' => current_time('mysql'), 'success' => false, 'created' => 0, 'remote' => 0, 'error' => '' ];

        Logger::instance()->log( 'WDCS cron run start', [
            'php_mem_usage_kb' => (int) ( memory_get_usage(true) / 1024 ),
            'php_mem_peak_kb'  => (int) ( memory_get_peak_usage(true) / 1024 ),
            'interval'         => self::get_interval_key(),
        ] );

        try {
            $svc = new Sync_Service( new API_Client() );
            $res = $svc->import_new_only();
            $result['success'] = true;
            $result['created'] = (int) ( $res['created'] ?? 0 );
            $result['remote']  = (int) ( $res['total_remote'] ?? 0 );
        } catch ( \Throwable $e ) {
            $result['error'] = $e->getMessage();
            Logger::instance()->log( 'WDCS cron import failed', [ 'error' => $e->getMessage() ] );
        } finally {
            $duration_ms = (int) round( ( microtime(true) - $start ) * 1000 );
            $result['duration_ms'] = $duration_ms;
            $result['mem_peak_kb'] = (int) ( memory_get_peak_usage(true) / 1024 );
            update_option( self::OPTION_CRON_LAST_RESULT, $result );

            Logger::instance()->log( 'WDCS cron run end', [
                'success'        => $result['success'] ? 1 : 0,
                'created'        => $result['created'],
                'remote'         => $result['remote'],
                'error'          => $result['error'],
                'duration_ms'    => $duration_ms,
                'mem_peak_kb'    => $result['mem_peak_kb'],
            ] );

            delete_transient( self::LOCK_TRANSIENT );
        }
    }

    public static function next_run_human() {
        $ts = wp_next_scheduled( self::HOOK_EVENT );
        if ( ! $ts ) return '—';
        return date_i18n( 'Y-m-d H:i:s', $ts + ( get_option('gmt_offset') * HOUR_IN_SECONDS ) );
    }
}