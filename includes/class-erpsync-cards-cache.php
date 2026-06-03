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
     * Stats from the most recent refresh (for the admin panel).
     *
     * @return array<string,mixed>
     */
    public static function last_result(): array {
        $r = get_option( self::OPTION_LAST, [] );
        return is_array( $r ) ? $r : [];
    }

    public static function is_refreshing(): bool {
        return (bool) get_transient( self::LOCK_TRANSIENT );
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

        if ( get_transient( self::LOCK_TRANSIENT ) ) {
            Logger::instance()->log( 'Cards cache refresh skipped (already running)', [] );
            return [ 'success' => false, 'skipped' => true, 'error' => 'locked' ];
        }
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

            $fetch_start    = microtime( true );
            $cards          = $api->fetch_cards_remote();
            $stats['fetch_ms'] = (int) round( ( microtime( true ) - $fetch_start ) * 1000 );
            $stats['fetched']  = count( $cards );

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
            // Guarded by fetched>0 so a momentary empty fetch can't wipe the cache.
            if ( $stats['fetched'] > 0 && ! empty( $existing ) ) {
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
