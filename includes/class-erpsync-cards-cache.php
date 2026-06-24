<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Local mirror of the 1C "InformationCards" discount-card list.
 *
 * WHY THIS EXISTS
 * ---------------
 * The 1C WebExchange `InformationCards` SOAP operation takes NO parameters and
 * can only ever return the COMPLETE card set (38k+ rows, ~15 MB, ~6 minutes).
 * There is no "get one card by code" call. Doing that fetch inside a web request
 * (e.g. the single-coupon "Sync" button, or a 15-min WP-Cron loopback) ties up a
 * PHP worker for minutes and blows the AJAX timeout ("ajax error").
 *
 * So we fetch the full list ONCE in the background (CLI / system cron, off the
 * web path), store it in this table, and:
 *   - the single-coupon Sync button reads ONE row instantly (no SOAP call);
 *   - the scheduled refresh pushes only NEW/CHANGED cards to WooCommerce
 *     coupons (content-hash diff), so keeping all 38k up to date is cheap.
 *
 * NEVER call refresh() from a normal web request — only from WP-CLI or WP-Cron
 * driven by system cron.
 */
class Cards_Cache {

    const TABLE          = 'erp_sync_cards';
    const LOCK_TRANSIENT = 'erp_sync_cards_refresh_lock';
    const OPTION_LAST    = 'erp_sync_cards_cache_last_result';

    /**
     * Truncation defence — the 1C `InformationCards` SOAP op takes NO parameters
     * (WSDL: <xs:sequence/>), cannot paginate, and intermittently returns a
     * well-formed BUT INCOMPLETE set (e.g. ~19k of ~38k rows: its server-side
     * iteration aborts partway). A short response must NEVER be trusted to prune
     * the cache. We keep a rolling baseline of recent good fetch sizes, RETRY the
     * fetch when it looks truncated, and only prune when the feed is plausibly
     * complete (or a smaller feed has persisted across several runs = real shrink).
     */
    const OPTION_FETCH_BASELINE = 'erp_sync_cards_fetch_baseline'; // rolling last-N good fetch sizes
    const FETCH_BASELINE_WINDOW = 5;
    const FETCH_MIN_RATIO       = 0.85;  // fetch < ratio * expected ⇒ suspected truncation
    const FETCH_MAX_ATTEMPTS    = 3;     // full re-fetches within one refresh when truncated
    const FETCH_RETRY_BACKOFF   = 30;    // seconds to wait between retries (let 1C recover, avoid hammering)
    const OPTION_PRUNE_MISS     = 'erp_sync_cards_prune_miss';     // consecutive sustained-small fetches
    const PRUNE_MISS_THRESHOLD  = 3;     // runs a smaller feed must persist before we accept a real shrink
    const OPTION_FORCE_PRUNE    = 'erp_sync_cards_force_prune_once'; // operator override: prune once regardless

    /** Number of cache rows written per bulk INSERT. */
    const UPSERT_CHUNK = 500;

    /** Flush the WP object cache every N coupon writes to keep memory flat. */
    const WC_FLUSH_EVERY = 500;

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Create / upgrade the cache table. Called on plugin activation.
     */
    public static function create_table(): void {
        global $wpdb;
        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            code_key varchar(191) NOT NULL,
            card_code varchar(191) NOT NULL DEFAULT '',
            inn varchar(64) NOT NULL DEFAULT '',
            name varchar(255) NOT NULL DEFAULT '',
            mobile varchar(64) NOT NULL DEFAULT '',
            dob varchar(32) NOT NULL DEFAULT '',
            discount float NOT NULL DEFAULT 0,
            is_deleted tinyint(1) NOT NULL DEFAULT 0,
            content_hash char(32) NOT NULL DEFAULT '',
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (code_key),
            KEY card_code (card_code),
            KEY is_deleted (is_deleted)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        if ( class_exists( '\ERPSync\Logger' ) ) {
            Logger::instance()->log( 'Cards cache table created/verified', [] );
        }
    }

    /**
     * Total cards currently cached.
     */
    public static function count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name() );
    }

    /**
     * Median of the last N good (non-truncated) fetch sizes, used to recognise a
     * truncated 1C response. 0 when no baseline has been recorded yet.
     */
    private static function expected_fetch_size(): int {
        $b = array_values( array_filter(
            array_map( 'intval', (array) get_option( self::OPTION_FETCH_BASELINE, [] ) ),
            static function ( $v ) { return $v > 0; }
        ) );
        if ( empty( $b ) ) {
            return 0;
        }
        sort( $b );
        return (int) $b[ (int) floor( count( $b ) / 2 ) ];
    }

    /**
     * Append a fetch size to the rolling baseline window. Call ONLY for fetches
     * that passed the completeness check, so a truncated fetch never lowers the bar.
     */
    private static function record_fetch_baseline( int $size ): void {
        if ( $size <= 0 ) {
            return;
        }
        $b = array_values( array_filter(
            array_map( 'intval', (array) get_option( self::OPTION_FETCH_BASELINE, [] ) ),
            static function ( $v ) { return $v > 0; }
        ) );
        $b[] = $size;
        if ( count( $b ) > self::FETCH_BASELINE_WINDOW ) {
            $b = array_slice( $b, -self::FETCH_BASELINE_WINDOW );
        }
        update_option( self::OPTION_FETCH_BASELINE, $b );
    }

    /**
     * Stats from the most recent refresh (for the admin panel).
     *
     * @return array<string,mixed>
     */
    public static function last_result(): array {
        $r = get_option( self::OPTION_LAST, [] );
        return is_array( $r ) ? $r : [];
    }

    /**
     * Server-wide MySQL advisory lock name for the refresh. Uses a MySQL-level
     * lock (GET_LOCK) rather than a transient because transients are stored in
     * the persistent object cache here (LiteSpeed), which is NOT reliably shared
     * across separate CLI/cron processes — so a transient lock failed to prevent
     * concurrent refreshes. GET_LOCK is per-connection, cross-process, and
     * auto-releases if a process dies. Prefixed by table prefix to stay unique
     * on shared DB servers.
     */
    private static function db_lock_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'erp_sync_cards_refresh';
    }

    /**
     * True when a refresh is currently in progress in ANY process.
     */
    public static function is_refreshing(): bool {
        global $wpdb;
        // IS_FREE_LOCK returns 1 if free, 0 if held, NULL on error.
        $free = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', self::db_lock_name() ) );
        return '0' === (string) $free;
    }

    /**
     * Look up ONE cached card by its (un-normalized) coupon code.
     * Returns the card in the exact array shape Sync_Service::create_or_update_coupon()
     * expects, or null when the code is not in the cache yet.
     *
     * @return array<string,mixed>|null
     */
    public static function get_card( string $code ): ?array {
        global $wpdb;
        $key = erp_sync_format_code( trim( $code ) );
        if ( '' === $key ) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table_name() . ' WHERE code_key = %s LIMIT 1',
                $key
            ),
            ARRAY_A
        );
        if ( ! $row ) {
            return null;
        }
        return self::row_to_card( $row );
    }

    /**
     * Map a DB row back to the canonical card array shape.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function row_to_card( array $row ): array {
        return [
            'Inn'                => (string) ( $row['inn'] ?? '' ),
            'Name'               => (string) ( $row['name'] ?? '' ),
            'MobileNumber'       => (string) ( $row['mobile'] ?? '' ),
            'DateOfBirth'        => (string) ( $row['dob'] ?? '' ),
            'CardCode'           => (string) ( $row['card_code'] ?? '' ),
            'DiscountPercentage' => (float) ( $row['discount'] ?? 0 ),
            'IsDeleted'          => ! empty( $row['is_deleted'] ),
        ];
    }

    /**
     * Stable fingerprint of a card's syncable fields, used to detect changes
     * between refreshes so we only write coupons that actually changed.
     *
     * @param array<string,mixed> $card
     */
    private static function content_hash( array $card ): string {
        return md5( implode( '|', [
            (string) ( $card['Inn'] ?? '' ),
            (string) ( $card['Name'] ?? '' ),
            (string) ( $card['MobileNumber'] ?? '' ),
            (string) ( $card['DateOfBirth'] ?? '' ),
            (string) round( (float) ( $card['DiscountPercentage'] ?? 0 ), 4 ),
            ! empty( $card['IsDeleted'] ) ? '1' : '0',
        ] ) );
    }

    /**
     * Fetch the full card list from 1C, update the local cache, and push only
     * NEW or CHANGED cards into WooCommerce coupons.
     *
     * Heavy + slow (~6 min just for the fetch). Run ONLY from CLI / WP-Cron.
     *
     * @param bool $write_coupons When true, create/update WC coupons for new+changed cards.
     * @return array<string,mixed> Stats (also stored in OPTION_LAST and logged).
     */
    public static function refresh( bool $write_coupons = true ): array {
        global $wpdb;

        // Acquire a cross-process MySQL lock (timeout 0 = fail fast if held).
        $got_lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::db_lock_name() ) );
        if ( '1' !== (string) $got_lock ) {
            Logger::instance()->log( 'Cards cache refresh skipped (already running)', [] );
            return [ 'success' => false, 'skipped' => true, 'error' => 'locked' ];
        }
        // Belt-and-suspenders transient (drives the admin "is running" hint).
        set_transient( self::LOCK_TRANSIENT, 1, 30 * MINUTE_IN_SECONDS );

        // We may run for many minutes in CLI/cron — lift any time limit.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $start = microtime( true );
        $stats = [
            'time'        => current_time( 'mysql' ),
            'success'     => false,
            'fetched'     => 0,
            'new'         => 0,
            'changed'     => 0,
            'unchanged'   => 0,
            'removed'     => 0,
            'wc_created'  => 0,
            'wc_updated'  => 0,
            'wc_errors'   => 0,
            'fetch_ms'    => 0,
            'duration_ms' => 0,
            'mem_peak_kb' => 0,
            'error'       => '',
        ];

        Logger::instance()->log( 'Cards cache refresh start', [
            'write_coupons' => $write_coupons ? 1 : 0,
            'is_cli'        => ( defined( 'WP_CLI' ) && WP_CLI ) ? 1 : 0,
            'php_mem_kb'    => (int) ( memory_get_usage( true ) / 1024 ),
        ] );

        try {
            $api = new API_Client();

            // Expected feed size = the larger of (a) the rolling median of recent
            // good fetches and (b) the rows already in the cache. Using the current
            // cache count as a floor means even with no baseline yet, a fetch that
            // returns far fewer rows than we already hold is recognised as truncated.
            $expected = max( self::expected_fetch_size(), self::count() );

            $fetch_start = microtime( true );
            $cards       = [];
            $complete    = true; // until proven truncated against a known baseline
            $attempt     = 0;
            for ( $attempt = 1; $attempt <= self::FETCH_MAX_ATTEMPTS; $attempt++ ) {
                $try = $api->fetch_cards_remote();
                if ( count( $try ) > count( $cards ) ) {
                    $cards = $try; // keep the largest attempt
                }
                $min_ok = ( $expected <= 0 ) ? 0 : (int) floor( $expected * self::FETCH_MIN_RATIO );
                if ( count( $cards ) >= $min_ok ) {
                    $complete = true;
                    break; // plausibly complete (or no baseline to judge against) — accept
                }
                // Looks truncated. 1C usually returns the full list on a later try.
                $complete = false;
                Logger::instance()->log( 'Cards fetch looks truncated — retrying', [
                    'attempt'  => $attempt,
                    'got'      => count( $try ),
                    'best'     => count( $cards ),
                    'expected' => $expected,
                    'min_ok'   => $min_ok,
                ] );
                // Gentle backoff between retries so we never hammer 1C back-to-back
                // (this whole loop only ever runs in CLI/cron, off the web path).
                if ( $attempt < self::FETCH_MAX_ATTEMPTS && self::FETCH_RETRY_BACKOFF > 0 ) {
                    sleep( self::FETCH_RETRY_BACKOFF );
                }
            }
            $stats['fetch_ms']       = (int) round( ( microtime( true ) - $fetch_start ) * 1000 );
            $stats['fetched']        = count( $cards );
            $stats['expected']       = $expected;
            $stats['fetch_complete'] = $complete ? 1 : 0;
            $stats['fetch_attempts'] = min( $attempt, self::FETCH_MAX_ATTEMPTS );

            // Snapshot of what we already have: code_key => content_hash.
            $existing = [];
            $rows     = $wpdb->get_results( 'SELECT code_key, content_hash FROM ' . self::table_name(), ARRAY_A );
            foreach ( (array) $rows as $r ) {
                $existing[ $r['code_key'] ] = $r['content_hash'];
            }
            unset( $rows );

            $seen          = [];
            $changed_cards = []; // new or changed → need a WC coupon write
            $now           = current_time( 'mysql' );
            $buffer        = [];

            foreach ( $cards as $card ) {
                $raw = trim( (string) ( $card['CardCode'] ?? '' ) );
                if ( '' === $raw ) {
                    continue;
                }
                $key = erp_sync_format_code( $raw );
                if ( '' === $key || isset( $seen[ $key ] ) ) {
                    continue;
                }
                $seen[ $key ] = true;

                $hash = self::content_hash( $card );
                if ( ! isset( $existing[ $key ] ) ) {
                    $stats['new']++;
                    $changed_cards[] = $card;
                } elseif ( $existing[ $key ] !== $hash ) {
                    $stats['changed']++;
                    $changed_cards[] = $card;
                } else {
                    $stats['unchanged']++;
                }

                $buffer[] = [
                    $key,
                    $raw,
                    (string) ( $card['Inn'] ?? '' ),
                    (string) ( $card['Name'] ?? '' ),
                    (string) ( $card['MobileNumber'] ?? '' ),
                    (string) ( $card['DateOfBirth'] ?? '' ),
                    (float) ( $card['DiscountPercentage'] ?? 0 ),
                    ! empty( $card['IsDeleted'] ) ? 1 : 0,
                    $hash,
                    $now,
                ];

                if ( count( $buffer ) >= self::UPSERT_CHUNK ) {
                    self::flush_upserts( $buffer );
                    $buffer = [];
                }
            }
            self::flush_upserts( $buffer );
            $buffer = [];
            unset( $cards );

            // Drop cache rows that 1C no longer returns (cards truly removed).
            //
            // CRITICAL: a truncated 1C response (see fetch retry above) omits real
            // cards that still exist — pruning on it would delete thousands of valid
            // coupons from the mirror. So prune ONLY when the fetch looked complete,
            // OR a smaller feed has persisted for PRUNE_MISS_THRESHOLD consecutive
            // runs (a genuine 1C deletion, not a transient truncation). An operator
            // can force a one-off prune via the OPTION_FORCE_PRUNE override.
            $force_once = (bool) get_option( self::OPTION_FORCE_PRUNE, false );
            if ( $force_once ) {
                delete_option( self::OPTION_FORCE_PRUNE );
            }

            $prune_ok = false;
            if ( $stats['fetched'] > 0 && ! empty( $existing ) ) {
                if ( $complete || $force_once ) {
                    $prune_ok = true;
                    delete_option( self::OPTION_PRUNE_MISS );
                    if ( $force_once ) {
                        $stats['prune_forced'] = 'manual_override';
                    }
                } else {
                    $miss = (int) get_option( self::OPTION_PRUNE_MISS, 0 ) + 1;
                    if ( $miss >= self::PRUNE_MISS_THRESHOLD ) {
                        // Sustained smaller feed = accept as the new normal: prune AND
                        // re-baseline so the truncation detector stops firing.
                        $prune_ok = true;
                        $stats['prune_forced'] = 'sustained_shrink';
                        delete_option( self::OPTION_PRUNE_MISS );
                        self::record_fetch_baseline( $stats['fetched'] );
                    } else {
                        update_option( self::OPTION_PRUNE_MISS, $miss );
                        $stats['skipped_prune'] = sprintf(
                            'fetch_truncated (fetched=%d, expected=%d, miss=%d/%d)',
                            $stats['fetched'], $expected, $miss, self::PRUNE_MISS_THRESHOLD
                        );
                        Logger::instance()->log( 'Cards cache prune SKIPPED — suspected truncated fetch', [
                            'fetched'  => $stats['fetched'],
                            'expected' => $expected,
                            'cache'    => count( $existing ),
                            'miss'     => $miss . '/' . self::PRUNE_MISS_THRESHOLD,
                        ] );
                    }
                }
            }

            if ( $prune_ok ) {
                $stale = array_diff( array_keys( $existing ), array_keys( $seen ) );
                if ( ! empty( $stale ) ) {
                    foreach ( array_chunk( $stale, self::UPSERT_CHUNK ) as $chunk ) {
                        $ph = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
                        $wpdb->query(
                            $wpdb->prepare(
                                'DELETE FROM ' . self::table_name() . " WHERE code_key IN ($ph)",
                                $chunk
                            )
                        );
                    }
                    $stats['removed'] = count( $stale );
                }
            }

            // Record this fetch size in the rolling baseline ONLY when it looked
            // complete — never let a truncated fetch poison the truncation detector.
            if ( $complete && $stats['fetched'] > 0 ) {
                self::record_fetch_baseline( $stats['fetched'] );
            }
            unset( $existing, $seen );

            // Push new/changed cards into WooCommerce coupons.
            if ( $write_coupons && ! empty( $changed_cards ) ) {
                $svc       = new Sync_Service( $api );
                $processed = 0;
                foreach ( $changed_cards as $card ) {
                    try {
                        $code   = erp_sync_format_code( (string) ( $card['CardCode'] ?? '' ) );
                        $exists = (bool) wc_get_coupon_id_by_code( $code );
                        $svc->create_or_update_coupon( $card, $exists, true );
                        if ( $exists ) {
                            $stats['wc_updated']++;
                        } else {
                            $stats['wc_created']++;
                        }
                    } catch ( \Throwable $e ) {
                        $stats['wc_errors']++;
                        Logger::instance()->log( 'Cards cache coupon write failed', [
                            'code'  => $card['CardCode'] ?? '',
                            'error' => $e->getMessage(),
                        ] );
                    }

                    // Keep memory flat across a large first-run write of all cards.
                    if ( ( ++$processed % self::WC_FLUSH_EVERY ) === 0 ) {
                        wp_cache_flush();
                        if ( function_exists( 'gc_collect_cycles' ) ) {
                            gc_collect_cycles();
                        }
                    }
                }
            }
            unset( $changed_cards );

            $stats['success'] = true;
        } catch ( \Throwable $e ) {
            $stats['error'] = $e->getMessage();
            Logger::instance()->log( 'Cards cache refresh failed', [ 'error' => $e->getMessage() ] );
        } finally {
            $stats['duration_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );
            $stats['mem_peak_kb'] = (int) ( memory_get_peak_usage( true ) / 1024 );
            update_option( self::OPTION_LAST, $stats );
            delete_transient( self::LOCK_TRANSIENT );
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::db_lock_name() ) );

            Logger::instance()->log( 'Cards cache refresh end', $stats );
        }

        return $stats;
    }

    /**
     * Bulk INSERT ... ON DUPLICATE KEY UPDATE for a buffer of card rows.
     *
     * @param array<int,array<int,mixed>> $buffer
     */
    private static function flush_upserts( array $buffer ): void {
        if ( empty( $buffer ) ) {
            return;
        }
        global $wpdb;
        $table  = self::table_name();
        $place  = [];
        $values = [];
        foreach ( $buffer as $b ) {
            $place[] = '(%s,%s,%s,%s,%s,%s,%f,%d,%s,%s)';
            foreach ( $b as $v ) {
                $values[] = $v;
            }
        }
        $sql = "INSERT INTO $table
                (code_key, card_code, inn, name, mobile, dob, discount, is_deleted, content_hash, updated_at)
                VALUES " . implode( ',', $place ) . "
                ON DUPLICATE KEY UPDATE
                    card_code = VALUES(card_code),
                    inn = VALUES(inn),
                    name = VALUES(name),
                    mobile = VALUES(mobile),
                    dob = VALUES(dob),
                    discount = VALUES(discount),
                    is_deleted = VALUES(is_deleted),
                    content_hash = VALUES(content_hash),
                    updated_at = VALUES(updated_at)";

        $wpdb->query( $wpdb->prepare( $sql, $values ) );
    }
}
