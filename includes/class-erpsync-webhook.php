<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

class Webhook {

    const OPTION_WEBHOOK_URL         = 'erp_sync_webhook_url';
    const OPTION_WEBHOOK_ENABLED     = 'erp_sync_webhook_enabled';
    const OPTION_WEBHOOK_SECRET      = 'erp_sync_webhook_secret';
    const OPTION_WEBHOOK_EVENTS      = 'erp_sync_webhook_events';

    public static function init(): void {
        // Hook into coupon usage
        add_action( 'woocommerce_applied_coupon', [ __CLASS__, 'on_coupon_applied' ] );
        add_action( 'woocommerce_removed_coupon', [ __CLASS__, 'on_coupon_removed' ] );
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'on_order_completed' ] );
        
        // Custom trigger endpoint
        add_action( 'rest_api_init', [ __CLASS__, 'register_webhook_endpoint' ] );
    }

    /**
     * Register REST API endpoint for triggering sync
     */
    public static function register_webhook_endpoint(): void {
        register_rest_route( 'erp-sync/v1', '/trigger-sync', [
            'methods'  => 'POST',
            'callback' => [ __CLASS__, 'handle_trigger_sync' ],
            'permission_callback' => [ __CLASS__, 'verify_webhook_secret' ],
        ] );
    }

    /**
     * Handle trigger sync webhook
     */
    public static function handle_trigger_sync( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            Logger::instance()->log( 'Webhook triggered sync', [ 'ip' => Security::get_client_ip() ] );
            
            $svc = new Sync_Service( new API_Client() );
            $result = $svc->import_new_only();
            
            return new \WP_REST_Response( [
                'success' => true,
                'data'    => $result,
                'message' => __( 'Sync completed successfully', 'erp-sync' ),
            ], 200 );
        } catch ( \Throwable $e ) {
            Logger::instance()->log( 'Webhook sync failed', [ 'error' => $e->getMessage() ] );
            
            return new \WP_REST_Response( [
                'success' => false,
                'error'   => $e->getMessage(),
                'message' => __( 'Sync failed', 'erp-sync' ),
            ], 500 );
        }
    }

    /**
     * Verify webhook secret
     */
    public static function verify_webhook_secret( \WP_REST_Request $request ): bool|\WP_Error {
        $secret = (string) get_option( self::OPTION_WEBHOOK_SECRET, '' );
        
        if ( empty( $secret ) ) {
            return true; // No secret configured, allow all
        }

        $provided_secret = $request->get_header( 'X-ERPSync-Secret' );
        
        // Also support old header name for backward compatibility
        if ( empty( $provided_secret ) ) {
            $provided_secret = $request->get_header( 'X-WDCS-Secret' );
        }
        
        if ( empty( $provided_secret ) ) {
            return new \WP_Error( 
                'missing_secret', 
                __( 'Webhook secret is required', 'erp-sync' ), 
                [ 'status' => 401 ] 
            );
        }
        
        if ( ! hash_equals( $secret, $provided_secret ) ) {
            Logger::instance()->log( 'Webhook authentication failed', [ 
                'ip' => Security::get_client_ip() 
            ] );
            
            return new \WP_Error( 
                'invalid_secret', 
                __( 'Invalid webhook secret', 'erp-sync' ), 
                [ 'status' => 403 ] 
            );
        }
        
        return true;
    }

    /**
     * Send webhook notification
     */
    public static function send_webhook( string $event, array $data ): void {
        if ( ! get_option( self::OPTION_WEBHOOK_ENABLED, false ) ) {
            return;
        }

        $url = (string) get_option( self::OPTION_WEBHOOK_URL, '' );
        
        if ( empty( $url ) ) {
            return;
        }

        $enabled_events = (array) get_option( self::OPTION_WEBHOOK_EVENTS, [] );
        
        if ( ! empty( $enabled_events ) && ! in_array( $event, $enabled_events, true ) ) {
            return;
        }

        $payload = [
            'event'     => $event,
            'data'      => $data,
            'timestamp' => current_time( 'mysql' ),
            'site_url'  => get_site_url(),
            'user'      => wp_get_current_user()->user_login ?? 'guest',
        ];

        $secret = (string) get_option( self::OPTION_WEBHOOK_SECRET, '' );
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent'   => 'ERPSync-Webhook/' . ERPSYNC_VERSION,
        ];

        if ( ! empty( $secret ) ) {
            $headers['X-ERPSync-Secret'] = $secret;
            $headers['X-ERPSync-Signature'] = hash_hmac( 'sha256', wp_json_encode( $payload ), $secret );
        }

        $response = wp_remote_post( $url, [
            'body'    => wp_json_encode( $payload ),
            'headers' => $headers,
            'timeout' => 15,
            'blocking' => false, // Don't wait for response
        ] );

        if ( is_wp_error( $response ) ) {
            Logger::instance()->log( 'Webhook failed', [
                'event' => $event,
                'error' => $response->get_error_message(),
                'url'   => $url,
            ] );
        } else {
            Logger::instance()->log( 'Webhook sent', [
                'event'  => $event,
                'url'    => $url,
            ] );
        }
    }

    /**
     * On coupon applied
     */
    public static function on_coupon_applied( string $coupon_code ): void {
        $coupon_id = wc_get_coupon_id_by_code( $coupon_code );
        
        if ( ! $coupon_id || ! get_post_meta( $coupon_id, '_erp_sync_managed', true ) ) {
            return;
        }

        self::send_webhook( 'coupon.applied', [
            'coupon_code'  => $coupon_code,
            'coupon_id'    => $coupon_id,
            'inn'          => get_post_meta( $coupon_id, '_erp_sync_inn', true ),
            'name'         => get_post_meta( $coupon_id, '_erp_sync_name', true ),
            'discount'     => get_post_meta( $coupon_id, 'coupon_amount', true ),
            'user_id'      => get_current_user_id(),
            'user_login'   => wp_get_current_user()->user_login ?? 'guest',
            'ip_address'   => Security::get_client_ip(),
        ] );
    }

    /**
     * On coupon removed
     */
    public static function on_coupon_removed( string $coupon_code ): void {
        $coupon_id = wc_get_coupon_id_by_code( $coupon_code );
        
        if ( ! $coupon_id || ! get_post_meta( $coupon_id, '_erp_sync_managed', true ) ) {
            return;
        }

        self::send_webhook( 'coupon.removed', [
            'coupon_code' => $coupon_code,
            'coupon_id'   => $coupon_id,
            'inn'         => get_post_meta( $coupon_id, '_erp_sync_inn', true ),
            'user_id'     => get_current_user_id(),
            'user_login'  => wp_get_current_user()->user_login ?? 'guest',
        ] );
    }

    /**
     * On order completed with ERPSync coupon
     */
    public static function on_order_completed( int $order_id ): void {
        $order = wc_get_order( $order_id );
        
        if ( ! $order ) {
            return;
        }

        $coupons = $order->get_coupon_codes();
        
        foreach ( $coupons as $coupon_code ) {
            $coupon_id = wc_get_coupon_id_by_code( $coupon_code );
            
            if ( ! $coupon_id || ! get_post_meta( $coupon_id, '_erp_sync_managed', true ) ) {
                continue;
            }

            self::send_webhook( 'order.completed', [
                'order_id'     => $order_id,
                'order_number' => $order->get_order_number(),
                'coupon_code'  => $coupon_code,
                'coupon_id'    => $coupon_id,
                'inn'          => get_post_meta( $coupon_id, '_erp_sync_inn', true ),
                'name'         => get_post_meta( $coupon_id, '_erp_sync_name', true ),
                'order_total'  => $order->get_total(),
                'discount'     => $order->get_discount_total(),
                'currency'     => $order->get_currency(),
                'customer_id'  => $order->get_customer_id(),
                'customer_email' => $order->get_billing_email(),
            ] );
        }
    }
}
