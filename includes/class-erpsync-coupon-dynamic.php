<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Applies runtime discount logic:
 * - If _erp_sync_is_deleted=yes => 0
 * - If birthday window (-1, 0, +1 day of DOB) => 20
 * - Else base discount
 *
 * Note: as of v1.4.6 the coupon can be used by guests and is not restricted
 * by the "Allowed Phone Numbers" field. The field is still stored on the
 * coupon (informational only) but no longer affects validity.
 */
class Coupon_Dynamic {

    const BIRTHDAY_OVERRIDE = 20;

    /** Days on EACH side of the birthday that also get the override (2 = a 5-day window). */
    const BIRTHDAY_WINDOW_DAYS = 2;

    public static function init(): void {
        add_filter( 'woocommerce_coupon_get_amount', [ __CLASS__, 'filter_amount' ], 25, 2 );
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
        $md = self::parse_birthday_month_day( $dob );
        if ( null === $md ) return false;
        list( $m, $d ) = $md;

        try {
            $now   = current_time( 'timestamp' );
            $today = strtotime( date( 'Y-m-d', $now ) );
            if ( false === $today ) return false;
            $year  = (int) date( 'Y', $now );

            // Check the birthday in the previous, current AND next year so the
            // window works across the Dec/Jan boundary (e.g. birthday Jan 1 while
            // today is Dec 31).
            foreach ( [ $year - 1, $year, $year + 1 ] as $y ) {
                $candidateDates = [];
                if ( $m === 2 && $d === 29 && ! self::is_leap_year( $y ) ) {
                    // Non-leap year: treat a Feb 29 birthday as Feb 28 + Mar 1.
                    $candidateDates[] = strtotime( sprintf( '%04d-02-28', $y ) );
                    $candidateDates[] = strtotime( sprintf( '%04d-03-01', $y ) );
                } else {
                    $candidateDates[] = strtotime( sprintf( '%04d-%02d-%02d', $y, $m, $d ) );
                }

                foreach ( $candidateDates as $c ) {
                    if ( false === $c ) continue;
                    // Round the day gap so a fixed-offset TZ can't cause off-by-one.
                    $days = (int) round( abs( $today - $c ) / DAY_IN_SECONDS );
                    if ( $days <= self::BIRTHDAY_WINDOW_DAYS ) {
                        return true;
                    }
                }
            }
            return false;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * Extract [month, day] from a stored DOB. Accepts the 1C export format
     * "dd.MM.yyyy [H:mm:ss]" as well as "YYYY-MM-DD", "YYYYMMDD" and "dd/MM/yyyy".
     * Only month + day matter for the birthday window, so the year is ignored.
     *
     * @return array{0:int,1:int}|null  [month, day] or null when unparseable.
     */
    private static function parse_birthday_month_day( string $dob ): ?array {
        $dob = trim( $dob );
        if ( '' === $dob ) return null;

        // Drop any trailing time portion ("... 0:00:00" or "...T00:00:00").
        $parts = preg_split( '/[ T]/', $dob );
        $date  = $parts[0] ?? '';

        $m = 0;
        $d = 0;
        if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $x ) ) {            // YYYY-MM-DD
            $m = (int) $x[2];
            $d = (int) $x[3];
        } elseif ( preg_match( '#^(\d{1,2})[./](\d{1,2})[./](\d{4})$#', $date, $x ) ) { // dd.MM.yyyy / dd/MM/yyyy (1C)
            $d = (int) $x[1];
            $m = (int) $x[2];
        } elseif ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $date, $x ) ) {            // YYYYMMDD
            $m = (int) $x[2];
            $d = (int) $x[3];
        } else {
            return null;
        }

        if ( $m < 1 || $m > 12 || $d < 1 || $d > 31 ) return null;
        return [ $m, $d ];
    }

    private static function is_leap_year( int $y ): bool {
        return ( ($y % 4 === 0) && ($y % 100 !== 0) ) || ($y % 400 === 0);
    }
}
