<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

class Sync_Service {

    const OPTION_LAST_SYNC = 'erp_sync_last_sync';

    private API_Client $api;

    public function __construct( API_Client $api ) {
        $this->api = $api;
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
            
            $formatted = function_exists('ERPSync\\erp_sync_format_code') ? erp_sync_format_code( $card['CardCode'] ) : sanitize_title( $card['CardCode'] );
            
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
            
            $formatted = function_exists('ERPSync\\erp_sync_format_code') ? erp_sync_format_code( $card['CardCode'] ) : sanitize_title( $card['CardCode'] );
            
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
            
            $formatted = function_exists('ERPSync\\erp_sync_format_code') ? erp_sync_format_code( $card['CardCode'] ) : sanitize_title( $card['CardCode'] );
            
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
            
            $formatted = function_exists('ERPSync\\erp_sync_format_code') ? erp_sync_format_code( $card['CardCode'] ) : sanitize_title( $card['CardCode'] );
            
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
        $code = function_exists('ERPSync\\erp_sync_format_code') ? erp_sync_format_code( $card['CardCode'] ) : sanitize_title( $card['CardCode'] );
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
            'last_sync_user' => get_option( '_erp_sync_last_sync_user', '—' )
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
