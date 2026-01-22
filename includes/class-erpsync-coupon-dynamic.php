<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Applies runtime discount logic:
 * - If _erp_sync_is_deleted=yes => 0
 * - If birthday window (-1, 0, +1 day of DOB) => 20
 * - Else base discount
 * Restricts usage to logged-in users.
 */
class Coupon_Dynamic {

    const BIRTHDAY_OVERRIDE = 20;

    public static function init(): void {
        add_filter( 'woocommerce_coupon_get_amount', [ __CLASS__, 'filter_amount' ], 25, 2 );
        add_filter( 'woocommerce_coupon_is_valid', [ __CLASS__, 'validate_user_login' ], 25, 2 );
        add_filter( 'woocommerce_coupon_get_description', [ __CLASS__, 'append_dynamic_info' ], 25, 2 );
    }

    public static function filter_amount( mixed $amount, \WC_Coupon $coupon ): mixed {
        $id = $coupon->get_id();
        if ( ! get_post_meta( $id, '_erp_sync_managed', true ) ) return $amount;

        $is_deleted = get_post_meta( $id, '_erp_sync_is_deleted', true ) === 'yes';
        if ( $is_deleted ) return 0;

        $dob = (string) get_post_meta( $id, '_erp_sync_dob', true );
        if ( self::is_in_birthday_window( $dob ) ) {
            return self::BIRTHDAY_OVERRIDE;
        }

        $base = get_post_meta( $id, '_erp_sync_base_discount', true );
        if ( $base === '' ) return $amount;
        return (int) $base;
    }

    public static function validate_user_login( bool $valid, \WC_Coupon $coupon ): bool {
        $id = $coupon->get_id();
        if ( get_post_meta( $id, '_erp_sync_managed', true ) && ! is_user_logged_in() ) {
            wc_add_notice( __( 'You must be logged in to use this discount card coupon.', 'erp-sync' ), 'error' );
            return false;
        }
        return $valid;
    }

    public static function append_dynamic_info( string $description, \WC_Coupon $coupon ): string {
        $id = $coupon->get_id();
        if ( ! get_post_meta( $id, '_erp_sync_managed', true ) ) return $description;

        $extra = [];
        if ( get_post_meta( $id, '_erp_sync_is_deleted', true ) === 'yes' ) {
            $extra[] = __( 'Marked deleted (0%).', 'erp-sync' );
        } else {
            $dob = (string) get_post_meta( $id, '_erp_sync_dob', true );
            if ( self::is_in_birthday_window( $dob ) ) {
                $extra[] = sprintf( __( '🎂 Birthday window (%d%%).', 'erp-sync' ), self::BIRTHDAY_OVERRIDE );
            } else {
                $extra[] = sprintf( __( 'Base: %s%%.', 'erp-sync' ), esc_html( get_post_meta( $id, '_erp_sync_base_discount', true ) ) );
            }
        }
        return trim( $description . ' ' . implode( ' ', $extra ) );
    }

    private static function is_in_birthday_window( string $dob ): bool {
        if ( empty( $dob ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ) return false;
        try {
            list( $y, $m, $d ) = array_map( 'intval', explode( '-', $dob ) );
            $now   = current_time( 'timestamp' );
            $year  = (int) date( 'Y', $now );

            $candidateDates = [];
            if ( $m === 2 && $d === 29 && ! self::is_leap_year( $year ) ) {
                $candidateDates[] = strtotime( "$year-02-28" );
                $candidateDates[] = strtotime( "$year-03-01" );
            } else {
                $candidateDates[] = strtotime( sprintf('%04d-%02d-%02d', $year, $m, $d ) );
            }

            $today = strtotime( date( 'Y-m-d', $now ) );
            foreach ( $candidateDates as $c ) {
                if ( $today === $c || $today === $c - DAY_IN_SECONDS || $today === $c + DAY_IN_SECONDS ) {
                    return true;
                }
            }
            return false;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    private static function is_leap_year( int $y ): bool {
        return ( ($y % 4 === 0) && ($y % 100 !== 0) ) || ($y % 400 === 0);
    }
}
