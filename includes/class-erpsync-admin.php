<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

class Admin {

    const MENU_SLUG = 'erp-sync-settings';

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );

        // Settings & core actions
        add_action( 'admin_post_erp_sync_save_settings', [ __CLASS__, 'handle_save_settings' ] );
        add_action( 'admin_post_erp_sync_import_new', [ __CLASS__, 'handle_import_new' ] );
        add_action( 'admin_post_erp_sync_full_sync', [ __CLASS__, 'handle_full_sync' ] );
        add_action( 'admin_post_erp_sync_update_existing', [ __CLASS__, 'handle_update_existing' ] );
        add_action( 'admin_post_erp_sync_force_import_all', [ __CLASS__, 'handle_force_import_all' ] );
        add_action( 'admin_post_erp_sync_test_connection', [ __CLASS__, 'handle_test_connection' ] );
        add_action( 'admin_post_erp_sync_raw_dump', [ __CLASS__, 'handle_raw_dump' ] );
        add_action( 'admin_post_erp_sync_products_test', [ __CLASS__, 'handle_products_test' ] );
        add_action( 'admin_post_erp_sync_generate_mock', [ __CLASS__, 'handle_generate_mock' ] );

        // Cron & diagnostics
        add_action( 'admin_post_erp_sync_run_cron_now', [ __CLASS__, 'handle_run_cron_now' ] );
        add_action( 'admin_post_erp_sync_download_last_xml', [ __CLASS__, 'handle_download_last_xml' ] );
        add_action( 'admin_post_erp_sync_download_last_request', [ __CLASS__, 'handle_download_last_request' ] );
        add_action( 'admin_post_erp_sync_download_last_fault', [ __CLASS__, 'handle_download_last_fault' ] );
        add_action( 'admin_post_erp_sync_download_last_headers', [ __CLASS__, 'handle_download_last_headers' ] );
        add_action( 'admin_post_erp_sync_download_last_meta', [ __CLASS__, 'handle_download_last_meta' ] );

        // AJAX handlers
        add_action( 'wp_ajax_erp_sync_sync_progress', [ __CLASS__, 'ajax_sync_progress' ] );
        add_action( 'wp_ajax_erp_sync_quick_edit_coupon', [ __CLASS__, 'ajax_quick_edit_coupon' ] );

        // Coupon admin columns
        add_filter( 'manage_edit-shop_coupon_columns', [ __CLASS__, 'add_coupon_columns' ] );
        add_action( 'manage_shop_coupon_posts_custom_column', [ __CLASS__, 'render_coupon_columns' ], 10, 2 );
        add_filter( 'manage_edit-shop_coupon_sortable_columns', [ __CLASS__, 'sortable_columns' ] );
    }

    public static function menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'ERP Sync', 'erp-sync' ),
            __( 'ERP Sync', 'erp-sync' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function handle_save_settings(): void {
        check_admin_referer( 'erp_sync_settings' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No permission' );

        // API Settings
        update_option( API_Client::OPTION_WSDL, esc_url_raw( $_POST['wsdl'] ?? '' ) );
        update_option( API_Client::OPTION_USERNAME, sanitize_text_field( $_POST['username'] ?? '' ) );
        
        if ( isset( $_POST['password'] ) && $_POST['password'] !== '' ) {
            $encrypted_password = Security::encrypt( sanitize_text_field( $_POST['password'] ) );
            update_option( API_Client::OPTION_PASSWORD, $encrypted_password );
        }
        
        update_option( API_Client::OPTION_FORCE_LOCATION, esc_url_raw( $_POST['force_location'] ?? '' ) );
        update_option( API_Client::OPTION_TIMEOUT, max( 5, (int) ( $_POST['timeout'] ?? 30 ) ) );
        update_option( API_Client::OPTION_DEBUG, isset( $_POST['debug'] ) ? 1 : 0 );

        $soap_version = (int) ( $_POST['soap_version'] ?? 11 );
        if ( ! in_array( $soap_version, [ 11, 12 ], true ) ) $soap_version = 11;
        update_option( API_Client::OPTION_SOAP_VERSION, $soap_version );

        // Cron Settings
        $cron_enabled  = isset( $_POST['cron_enabled'] ) ? 1 : 0;
        $cron_interval = (string) ( $_POST['cron_interval'] ?? 'erp_sync_10min' );
        if ( ! in_array( $cron_interval, [ 'erp_sync_5min','erp_sync_10min','erp_sync_15min' ], true ) ) {
            $cron_interval = 'erp_sync_10min';
        }
        update_option( Cron::OPTION_CRON_ENABLED, $cron_enabled );
        update_option( Cron::OPTION_CRON_INTERVAL, $cron_interval );

        // Security Settings
        update_option( Security::OPTION_IP_WHITELIST, sanitize_textarea_field( $_POST['ip_whitelist'] ?? '' ) );
        update_option( Security::OPTION_RATE_LIMIT, isset( $_POST['rate_limit_enabled'] ) ? 1 : 0 );
        update_option( Security::OPTION_RATE_LIMIT_MAX, max( 10, (int) ( $_POST['rate_limit_max'] ?? 60 ) ) );

        // Webhook Settings
        update_option( Webhook::OPTION_WEBHOOK_ENABLED, isset( $_POST['webhook_enabled'] ) ? 1 : 0 );
        update_option( Webhook::OPTION_WEBHOOK_URL, esc_url_raw( $_POST['webhook_url'] ?? '' ) );
        update_option( Webhook::OPTION_WEBHOOK_SECRET, sanitize_text_field( $_POST['webhook_secret'] ?? '' ) );
        
        $webhook_events = isset( $_POST['webhook_events'] ) ? array_map( 'sanitize_text_field', (array) $_POST['webhook_events'] ) : [];
        update_option( Webhook::OPTION_WEBHOOK_EVENTS, $webhook_events );

        if ( class_exists( '\ERPSync\Cron' ) ) {
            Cron::reschedule_after_settings_change();
        }

        wp_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'saved' => 1 ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_update_existing(): void {
        check_admin_referer( 'erp_sync_actions' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No permission' );
        
        try {
            $svc = new Sync_Service( new API_Client() );
            $res = $svc->update_existing_only();
            wp_redirect( add_query_arg( [
                'page'    => self::MENU_SLUG,
                'updated' => (int) ( $res['updated'] ?? 0 ),
                'remote'  => (int) ( $res['total_remote'] ?? 0 ),
            ], admin_url( 'admin.php' ) ) );
        } catch ( \Throwable $e ) {
            Logger::instance()->log( 'Update existing failed', [ 'error' => $e->getMessage() ] );
            wp_redirect( add_query_arg( [
                'page'    => self::MENU_SLUG,
                'syncerr' => rawurlencode( $e->getMessage() ),
            ], admin_url( 'admin.php' ) ) );
        }
        exit;
    }

    public static function handle_force_import_all(): void {
        check_admin_referer( 'erp_sync_actions' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No permission' );
        
        try {
            $svc = new Sync_Service( new API_Client() );
            $res = $svc->force_import_all();
            wp_redirect( add_query_arg( [
                'page'    => self::MENU_SLUG,
                'created' => (int) ( $res['created'] ?? 0 ),
                'updated' => (int) ( $res['updated'] ?? 0 ),
                'remote'  => (int) ( $res['total_remote'] ?? 0 ),
                'forced'  => 1,
            ], admin_url( 'admin.php' ) ) );
        } catch ( \Throwable $e ) {
            Logger::instance()->log( 'Force import all failed', [ 'error' => $e->getMessage() ] );
            wp_redirect( add_query_arg( [
                'page'    => self::MENU_SLUG,
                'syncerr' => rawurlencode( $e->getMessage() ),
            ], admin_url( 'admin.php' ) ) );
        }
        exit;
    }

    public static function handle_import_new(): void {
        check_admin_referer( 'erp_sync_actions' );
        try {
            $svc = new Sync_Service( new API_Client() );
            $res = $svc->import_new_only();
            wp_redirect( add_query_arg( [
                'page'     => self::MENU_SLUG,
                'imported' => (int) ( $res['created'] ?? 0 ),
                'remote'   => (int) ( $res['total_remote'] ?? 0 ),
            ], admin_url( 'admin.php' ) ) );
        } catch ( \Throwable $e ) {
            Logger::instance()->log( 'Import new failed', [ 'error' => $e->getMessage() ] );
            wp_redirect( add_query_arg( [
                'page'    => self::MENU_SLUG,
                'syncerr' => rawurlencode( $e->getMessage() ),
            ], admin_url( 'admin.php' ) ) );
        }
        exit;
    }

    public static function handle_full_sync(): void {
        check_admin_referer( 'erp_sync_actions' );
        try {
            $svc = new Sync_Service( new API_Client() );
            $res = $svc->full_sync();
            wp_redirect( add_query_arg( [
                'page'    => self::MENU_SLUG,
                'created' => (int) ( $res['created'] ?? 0 ),
                'updated' => (int) ( $res['updated'] ?? 0 ),
                'remote'  => (int) ( $res['total_remote'] ?? 0 ),
            ], admin_url( 'admin.php' ) ) );
        } catch ( \Throwable $e ) {
            Logger::instance()->log( 'Full sync failed', [ 'error' => $e->getMessage() ] );
            wp_redirect( add_query_arg( [
                'page'    => self::MENU_SLUG,
                'syncerr' => rawurlencode( $e->getMessage() ),
            ], admin_url( 'admin.php' ) ) );
        }
        exit;
    }

    public static function handle_test_connection(): void {
        check_admin_referer( 'erp_sync_actions' );
        $api = new API_Client();
        $res = $api->test_connection();
        $args = [ 'page' => self::MENU_SLUG ];
        if ( $res['success'] ?? false ) {
            $args['test'] = 'ok';
        } else {
            $args['test']  = 'fail';
            $args['error'] = rawurlencode( $res['error'] ?? 'Unknown error' );
        }
        wp_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_raw_dump(): void {
        check_admin_referer( 'erp_sync_actions' );
        $api = new API_Client();
        try {
            $api->fetch_cards();
            wp_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'rawdump' => 'done' ], admin_url( 'admin.php' ) ) );
        } catch ( \Throwable $e ) {
            wp_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'rawdump' => 'fail', 'error' => rawurlencode( $e->getMessage() ) ], admin_url( 'admin.php' ) ) );
        }
        exit;
    }

    public static function handle_products_test(): void {
        check_admin_referer( 'erp_sync_actions' );
        $api = new API_Client();
        try {
            $api->fetch_products_raw();
            wp_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'prodtest' => 'ok' ], admin_url( 'admin.php' ) ) );
        } catch ( \Throwable $e ) {
            wp_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'prodtest' => 'fail', 'error' => rawurlencode( $e->getMessage() ) ], admin_url( 'admin.php' ) ) );
        }
        exit;
    }

    public static function handle_generate_mock(): void {
        check_admin_referer( 'erp_sync_actions' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No permission' );

        $svc = new Sync_Service( new API_Client() );
        $today = current_time( 'timestamp' );
        $birthdayDob = ( date('Y', $today) - 30 ) . date( '-m-d', $today );

        $mockCards = [
            [ 'Inn'=>'123456789','Name'=>'Mock Regular','MobileNumber'=>'5551000','DateOfBirth'=>'1990-05-10','CardCode'=>'MOCKCARD1','DiscountPercentage'=>10,'IsDeleted'=>false ],
            [ 'Inn'=>'999999999','Name'=>'Mock Birthday','MobileNumber'=>'5552000','DateOfBirth'=>$birthdayDob,'CardCode'=>'MOCKBIRTHDAY','DiscountPercentage'=>15,'IsDeleted'=>false ],
            [ 'Inn'=>'777777777','Name'=>'Mock Deleted','MobileNumber'=>'5553000','DateOfBirth'=>'1988-02-02','CardCode'=>'MOCKDEL','DiscountPercentage'=>20,'IsDeleted'=>true ],
            [ 'Inn'=>'555555555','Name'=>'Mock High','MobileNumber'=>'5554000','DateOfBirth'=>'1992-11-11','CardCode'=>'MOCKHIGH','DiscountPercentage'=>35,'IsDeleted'=>false ],
        ];

        $created = 0;
        $updated = 0;
        foreach ( $mockCards as $card ) {
            $exists = wc_get_coupon_id_by_code( sanitize_title( $card['CardCode'] ) );
            $svc->create_or_update_coupon( $card, (bool) $exists );
            if ( $exists ) $updated++; else $created++;
        }

        Logger::instance()->log( 'Mock cards generated', [ 'created'=>$created, 'updated'=>$updated ] );
        wp_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'mockgen' => 'done', 'created' => $created, 'updated' => $updated ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_run_cron_now(): void {
        check_admin_referer( 'erp_sync_actions' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No permission' );
        try {
            Cron::run();
            wp_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'cronrun' => 'ok' ], admin_url( 'admin.php' ) ) );
        } catch ( \Throwable $e ) {
            wp_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'cronrun' => 'fail', 'error' => rawurlencode( $e->getMessage() ) ], admin_url( 'admin.php' ) ) );
        }
        exit;
    }

    public static function handle_download_last_xml(): void {
        check_admin_referer( 'erp_sync_actions' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No permission' );
        $xml = get_option( API_Client::OPTION_LAST_RAW_XML, '' );
        if ( empty( $xml ) ) {
            wp_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'xmldl' => 'empty' ], admin_url( 'admin.php' ) ) );
            exit;
        }
        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="erp-sync-last-soap-response.xml"' );
        echo $xml;
        exit;
    }

    public static function handle_download_last_request(): void {
        check_admin_referer( 'erp_sync_actions' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No permission' );
        $xml = get_option( API_Client::OPTION_LAST_REQUEST_XML, '' );
        if ( empty( $xml ) ) {
            wp_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'reqdl' => 'empty' ], admin_url( 'admin.php' ) ) );
            exit;
        }
        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="erp-sync-last-soap-request.xml"' );
        echo $xml;
        exit;
    }

    public static function handle_download_last_fault(): void {
        check_admin_referer( 'erp_sync_actions' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No permission' );
        $fault = get_option( API_Client::OPTION_LAST_SOAP_FAULT, '' );
        if ( empty( $fault ) ) {
            wp_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'faultdl' => 'empty' ], admin_url( 'admin.php' ) ) );
            exit;
        }
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="erp-sync-last-soap-fault.txt"' );
        echo $fault;
        exit;
    }

    public static function handle_download_last_headers(): void {
        check_admin_referer( 'erp_sync_actions' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No permission' );
        $reqH = get_option( API_Client::OPTION_LAST_REQUEST_HEADERS, '' );
        $resH = get_option( API_Client::OPTION_LAST_RESPONSE_HEADERS, '' );
        if ( empty( $reqH ) && empty( $resH ) ) {
            wp_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'headersdl'=> 'empty' ], admin_url( 'admin.php' ) ) );
            exit;
        }
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="erp-sync-last-soap-headers.txt"' );
        echo "=== Last SOAP Request Headers ===\n".$reqH."\n\n=== Last SOAP Response Headers ===\n".$resH;
        exit;
    }

    public static function handle_download_last_meta(): void {
        check_admin_referer( 'erp_sync_actions' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No permission' );
        $meta = get_option( API_Client::OPTION_LAST_INFOCARDS_META, '' );
        if ( empty( $meta ) ) {
            wp_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'metadl' => 'empty' ], admin_url( 'admin.php' ) ) );
            exit;
        }
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="erp-sync-last-infocards-meta.json"' );
        echo $meta;
        exit;
    }

    public static function ajax_sync_progress(): void {
        check_ajax_referer( 'erp_sync_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }
        $progress = get_transient( 'erp_sync_sync_progress' );
        if ( $progress === false ) {
            wp_send_json_success( [ 'progress' => 0, 'status' => 'idle' ] );
        }
        wp_send_json_success( $progress );
    }

    public static function ajax_quick_edit_coupon(): void {
        check_ajax_referer( 'erp_sync_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }

        $coupon_id = isset( $_POST['coupon_id'] ) ? intval( $_POST['coupon_id'] ) : 0;
        $field = isset( $_POST['field'] ) ? sanitize_text_field( $_POST['field'] ) : '';
        $value = isset( $_POST['value'] ) ? sanitize_text_field( $_POST['value'] ) : '';

        if ( ! $coupon_id || ! $field ) {
            wp_send_json_error( [ 'message' => 'Invalid data' ] );
        }

        switch ( $field ) {
            case 'base_discount':
                // Update both: _erp_sync_base_discount (our tracker) and coupon_amount (WooCommerce display)
                update_post_meta( $coupon_id, '_erp_sync_base_discount', max( 0, intval( $value ) ) );
                update_post_meta( $coupon_id, 'coupon_amount', max( 0, intval( $value ) ) );
                break;
            case 'is_deleted':
                update_post_meta( $coupon_id, '_erp_sync_is_deleted', $value === 'yes' ? 'yes' : 'no' );
                break;
            default:
                wp_send_json_error( [ 'message' => 'Invalid field' ] );
        }

        wp_send_json_success( [ 'message' => 'Updated successfully' ] );
    }

    private static function render_notices(): void {
        $notices = [];
        $notice_keys = ['saved','imported','created','updated','test','rawdump','prodtest','mockgen','syncerr','cronrun','xmldl','reqdl','faultdl','headersdl','metadl','forced'];
        
        foreach ( $notice_keys as $k ) {
            if ( ! isset( $_GET[$k] ) ) continue;
            
            switch ( $k ) {
                case 'saved':
                    $notices[] = ['success', __( 'Settings saved successfully.', 'erp-sync' )];
                    break;
                case 'imported':
                    $notices[] = ['info', sprintf( __('Imported %d new codes (Remote total: %d)', 'erp-sync'), intval($_GET['imported']), intval($_GET['remote'] ?? 0) )];
                    break;
                case 'updated':
                    if ( ! isset($_GET['created']) ) {
                        $notices[] = ['info', sprintf( __('Updated %d existing coupons (Remote total: %d)', 'erp-sync'), intval($_GET['updated']), intval($_GET['remote'] ?? 0) )];
                    }
                    break;
                case 'created':
                    if ( isset($_GET['updated']) ) {
                        $msg = sprintf( __('Full Sync: Created %d, Updated %d (Remote total: %d)', 'erp-sync'), intval($_GET['created']), intval($_GET['updated']), intval($_GET['remote'] ?? 0) );
                        if ( isset($_GET['forced']) ) {
                            $msg = sprintf( __('Force Import All: Created %d, Updated %d (Remote total: %d)', 'erp-sync'), intval($_GET['created']), intval($_GET['updated']), intval($_GET['remote'] ?? 0) );
                        }
                        $notices[] = ['success', $msg];
                    }
                    break;
                case 'test':
                    $notices[] = [ $_GET['test'] === 'ok' ? 'success' : 'error', $_GET['test'] === 'ok' ? __( 'Connection test successful!', 'erp-sync' ) : sprintf( __('Test failed: %s', 'erp-sync'), esc_html( urldecode($_GET['error'] ?? '') ) ) ];
                    break;
                case 'rawdump':
                    $notices[] = [ $_GET['rawdump'] === 'done' ? 'success' : 'error', $_GET['rawdump'] === 'done' ? __('Raw dump executed.', 'erp-sync') : sprintf( __('Raw dump failed: %s','erp-sync'), esc_html( urldecode($_GET['error'] ?? '') ) ) ];
                    break;
                case 'prodtest':
                    $notices[] = [ $_GET['prodtest'] === 'ok' ? 'success' : 'error', $_GET['prodtest'] === 'ok' ? __('Products test executed.', 'erp-sync') : sprintf( __('Products test failed: %s','erp-sync'), esc_html( urldecode($_GET['error'] ?? '') ) ) ];
                    break;
                case 'mockgen':
                    if ( $_GET['mockgen'] === 'done' ) {
                        $notices[] = ['success', sprintf( __('Mock cards generated. Created %d, Updated %d.', 'erp-sync'), intval($_GET['created'] ?? 0), intval($_GET['updated'] ?? 0) ) ];
                    }
                    break;
                case 'syncerr':
                    $notices[] = ['error', sprintf( __('Sync failed: %s', 'erp-sync' ), esc_html( urldecode($_GET['syncerr']) ) )];
                    break;
                case 'cronrun':
                    $notices[] = [ $_GET['cronrun'] === 'ok' ? 'success' : 'error', $_GET['cronrun'] === 'ok' ? __('Scheduled import executed now.', 'erp-sync') : sprintf( __('Manual cron failed: %s', 'erp-sync' ), esc_html( urldecode( $_GET['error'] ?? '' ) ) ) ];
                    break;
                case 'xmldl':
                    if ( $_GET['xmldl'] === 'empty' ) $notices[] = ['error', __( 'No SOAP XML available.', 'erp-sync' )];
                    break;
                case 'reqdl':
                    if ( $_GET['reqdl'] === 'empty' ) $notices[] = ['error', __( 'No SOAP Request available.', 'erp-sync' )];
                    break;
                case 'faultdl':
                    if ( $_GET['faultdl'] === 'empty' ) $notices[] = ['error', __( 'No SOAP Fault captured.', 'erp-sync' )];
                    break;
                case 'headersdl':
                    if ( $_GET['headersdl'] === 'empty' ) $notices[] = ['error', __( 'No SOAP headers captured.', 'erp-sync' )];
                    break;
                case 'metadl':
                    if ( $_GET['metadl'] === 'empty' ) $notices[] = ['error', __( 'No meta captured.', 'erp-sync' )];
                    break;
            }
        }

        foreach ( $notices as $n ) {
            list($type,$msg) = $n;
            $class = $type === 'error' ? 'notice-error' : ( $type === 'success' ? 'notice-success' : 'notice-info' );
            echo '<div class="notice '.$class.' is-dismissible"><p>'.esc_html($msg).'</p></div>';
        }
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;

        $wsdl           = get_option( API_Client::OPTION_WSDL );
        $username       = get_option( API_Client::OPTION_USERNAME );
        $last_sync      = get_option( Sync_Service::OPTION_LAST_SYNC, '—' );
        $force_location = get_option( API_Client::OPTION_FORCE_LOCATION );
        $timeout        = (int) get_option( API_Client::OPTION_TIMEOUT, 30 );
        $debug          = (bool) get_option( API_Client::OPTION_DEBUG, false );
        $soap_version   = (int) get_option( API_Client::OPTION_SOAP_VERSION, 11 );

        // Security
        $ip_whitelist   = get_option( Security::OPTION_IP_WHITELIST, '' );
        $rate_limit     = (bool) get_option( Security::OPTION_RATE_LIMIT, false );
        $rate_limit_max = (int) get_option( Security::OPTION_RATE_LIMIT_MAX, 60 );

        // Webhooks
        $webhook_enabled = (bool) get_option( Webhook::OPTION_WEBHOOK_ENABLED, false );
        $webhook_url     = get_option( Webhook::OPTION_WEBHOOK_URL, '' );
        $webhook_secret  = get_option( Webhook::OPTION_WEBHOOK_SECRET, '' );
        $webhook_events  = get_option( Webhook::OPTION_WEBHOOK_EVENTS, [] );

        // Debug data
        $last_raw_json  = get_option( API_Client::OPTION_LAST_RAW_JSON, '' );
        $last_raw_xml   = get_option( API_Client::OPTION_LAST_RAW_XML, '' );
        $last_prod_xml  = get_option( API_Client::OPTION_LAST_PRODUCTS_XML, '' );
        $last_req_xml   = get_option( API_Client::OPTION_LAST_REQUEST_XML, '' );
        $last_fault     = get_option( API_Client::OPTION_LAST_SOAP_FAULT, '' );
        $last_req_headers = get_option( API_Client::OPTION_LAST_REQUEST_HEADERS, '' );
        $last_res_headers = get_option( API_Client::OPTION_LAST_RESPONSE_HEADERS, '' );
        $last_meta_json   = get_option( API_Client::OPTION_LAST_INFOCARDS_META, '' );

        // Cron
        $cron_enabled   = (bool) get_option( Cron::OPTION_CRON_ENABLED, false );
        $cron_interval  = (string) get_option( Cron::OPTION_CRON_INTERVAL, 'erp_sync_10min' );
        $cron_next      = class_exists('\ERPSync\Cron') ? Cron::next_run_human() : '—';
        $cron_last_res  = get_option( Cron::OPTION_CRON_LAST_RESULT, [] );

        ?>
        <div class="wrap erp-sync-admin-wrap">
            <h1><?php esc_html_e( 'ERP Sync Products and Coupons', 'erp-sync' ); ?> <span class="erp-sync-version">v<?php echo esc_html( ERPSYNC_VERSION ); ?></span></h1>
            
            <?php self::render_notices(); ?>

            <div id="erp-sync-progress-container" style="display:none;" class="erp-sync-progress-wrap">
                <div class="erp-sync-progress-bar">
                    <div class="erp-sync-progress-fill" style="width:0%"></div>
                </div>
                <div class="erp-sync-progress-text">Syncing...</div>
            </div>

            <h2 class="nav-tab-wrapper erp-sync-nav-tabs">
                <a href="#tab-settings" class="nav-tab nav-tab-active"><?php _e('Settings', 'erp-sync'); ?></a>
                <a href="#tab-actions" class="nav-tab"><?php _e('Actions', 'erp-sync'); ?></a>
                <a href="#tab-webhooks" class="nav-tab"><?php _e('Webhooks', 'erp-sync'); ?></a>
                <a href="#tab-security" class="nav-tab"><?php _e('Security', 'erp-sync'); ?></a>
                <a href="#tab-diagnostics" class="nav-tab"><?php _e('Diagnostics', 'erp-sync'); ?></a>
            </h2>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="erp-sync-settings-form">
                <?php wp_nonce_field( 'erp_sync_settings' ); ?>
                <input type="hidden" name="action" value="erp_sync_save_settings" />

                <!-- Settings Tab -->
                <div id="tab-settings" class="erp-sync-tab-content">
                    <h2><?php _e( 'API Settings', 'erp-sync' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="wsdl"><?php _e('WSDL URL','erp-sync'); ?></label></th>
                            <td>
                                <input type="text" class="regular-text" id="wsdl" name="wsdl" value="<?php echo esc_attr( $wsdl ); ?>">
                                <p class="description"><?php _e('Example:', 'erp-sync'); ?> http://92.241.78.182:8080/artsw2022/ws/WebExchange.1cws?wsdl</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="force_location"><?php _e('Force Endpoint','erp-sync'); ?></label></th>
                            <td>
                                <input type="text" class="regular-text" id="force_location" name="force_location" value="<?php echo esc_attr( $force_location ); ?>">
                                <p class="description"><?php _e('Full URL without ?wsdl (leave blank to rely on WSDL).', 'erp-sync'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="username"><?php _e('Username','erp-sync'); ?></label></th>
                            <td><input type="text" class="regular-text" id="username" name="username" value="<?php echo esc_attr( $username ); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="password"><?php _e('Password','erp-sync'); ?></label></th>
                            <td>
                                <input type="password" class="regular-text" id="password" name="password" value="" placeholder="<?php esc_attr_e('(leave blank to keep)','erp-sync'); ?>">
                                <p class="description"><?php _e('Password is encrypted before storage.', 'erp-sync'); ?> 🔒</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="timeout"><?php _e('Timeout (sec)','erp-sync'); ?></label></th>
                            <td><input type="number" min="5" id="timeout" name="timeout" value="<?php echo esc_attr( $timeout ); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="soap_version"><?php _e('SOAP Version','erp-sync'); ?></label></th>
                            <td>
                                <select id="soap_version" name="soap_version">
                                    <option value="11" <?php selected( $soap_version, 11 ); ?>>SOAP 1.1</option>
                                    <option value="12" <?php selected( $soap_version, 12 ); ?>>SOAP 1.2</option>
                                </select>
                                <p class="description"><?php _e('Switch if one binding causes faults or empty responses.', 'erp-sync'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Last Sync','erp-sync'); ?></th>
                            <td><strong><?php echo esc_html( $last_sync ); ?></strong></td>
                        </tr>
                        <tr>
                            <th><label for="debug"><?php _e('Enable Debug Trace','erp-sync'); ?></label></th>
                            <td>
                                <label><input type="checkbox" id="debug" name="debug" value="1" <?php checked( $debug ); ?>> <?php _e('Capture detailed SOAP request/response, headers & performance metrics.', 'erp-sync'); ?></label>
                            </td>
                        </tr>
                    </table>

                    <h2><?php _e( 'Automation', 'erp-sync' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="cron_enabled"><?php _e('Enable Scheduled Import','erp-sync'); ?></label></th>
                            <td>
                                <label><input type="checkbox" id="cron_enabled" name="cron_enabled" value="1" <?php checked( $cron_enabled ); ?>> <?php _e('Run Import New automatically.', 'erp-sync'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cron_interval"><?php _e('Import Frequency','erp-sync'); ?></label></th>
                            <td>
                                <select id="cron_interval" name="cron_interval">
                                    <option value="erp_sync_5min"  <?php selected( $cron_interval, 'erp_sync_5min'  ); ?>><?php _e('Every 5 minutes','erp-sync'); ?></option>
                                    <option value="erp_sync_10min" <?php selected( $cron_interval, 'erp_sync_10min' ); ?>><?php _e('Every 10 minutes','erp-sync'); ?></option>
                                    <option value="erp_sync_15min" <?php selected( $cron_interval, 'erp_sync_15min' ); ?>><?php _e('Every 15 minutes','erp-sync'); ?></option>
                                </select>
                                <p class="description">
                                    <?php printf( __('Next run: %s', 'erp-sync' ), '<strong>' . esc_html( $cron_next ) . '</strong>' ); ?>
                                    <?php
                                    if ( is_array( $cron_last_res ) && ! empty( $cron_last_res ) ) {
                                        echo '<br>'.esc_html( sprintf(
                                            __('Last run: %s, success=%s, created=%d, remote=%d, duration=%sms', 'erp-sync'),
                                            $cron_last_res['time'] ?? '—',
                                            ! empty( $cron_last_res['success'] ) ? 'yes' : 'no',
                                            (int) ( $cron_last_res['created'] ?? 0 ),
                                            (int) ( $cron_last_res['remote'] ?? 0 ),
                                            (int) ( $cron_last_res['duration_ms'] ?? 0 )
                                        ) );
                                        if ( ! empty( $cron_last_res['error'] ) ) {
                                            echo '<br><span class="erp-sync-error">'.esc_html( sprintf( __('Error: %s','erp-sync' ), $cron_last_res['error'] ) ).'</span>';
                                        }
                                    }
                                    ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Actions Tab -->
                <div id="tab-actions" class="erp-sync-tab-content" style="display:none;">
                    <h2><?php _e( 'Manual Actions', 'erp-sync' ); ?></h2>
                    <p class="description"><?php _e('Use these buttons to manually trigger synchronization operations.', 'erp-sync'); ?></p>
                    
                    <div class="erp-sync-action-buttons">
                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;">
                            <?php wp_nonce_field( 'erp_sync_actions' ); ?>
                            <input type="hidden" name="action" value="erp_sync_import_new">
                            <?php submit_button( __('Import New Codes Only','erp-sync'), 'secondary', 'submit', false ); ?>
                            <p class="description"><?php _e('Import only codes that don\'t exist locally.', 'erp-sync'); ?></p>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;">
                            <?php wp_nonce_field( 'erp_sync_actions' ); ?>
                            <input type="hidden" name="action" value="erp_sync_update_existing">
                            <?php submit_button( __('Update Existing Codes Only','erp-sync'), 'secondary', 'submit', false ); ?>
                            <p class="description"><?php _e('Update only codes that already exist locally.', 'erp-sync'); ?></p>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;">
                            <?php wp_nonce_field( 'erp_sync_actions' ); ?>
                            <input type="hidden" name="action" value="erp_sync_full_sync">
                            <?php submit_button( __('Full Sync (Create + Update)','erp-sync'), 'primary', 'submit', false ); ?>
                            <p class="description"><?php _e('Sync all codes: create new and update existing.', 'erp-sync'); ?></p>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;">
                            <?php wp_nonce_field( 'erp_sync_actions' ); ?>
                            <input type="hidden" name="action" value="erp_sync_force_import_all">
                            <?php submit_button( __('Force Import ALL','erp-sync'), 'delete', 'submit', false, ['onclick' => 'return confirm("'.__('This will overwrite ALL existing coupons. Continue?', 'erp-sync').'");'] ); ?>
                            <p class="description"><?php _e('Force re-import everything from 1C, overwriting local data.', 'erp-sync'); ?> ⚠️</p>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;">
                            <?php wp_nonce_field( 'erp_sync_actions' ); ?>
                            <input type="hidden" name="action" value="erp_sync_test_connection">
                            <?php submit_button( __('Test Connection (non-destructive)','erp-sync'), 'secondary', 'submit', false ); ?>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;">
                            <?php wp_nonce_field( 'erp_sync_actions' ); ?>
                            <input type="hidden" name="action" value="erp_sync_raw_dump">
                            <?php submit_button( __('Quick Raw Dump','erp-sync'), 'secondary', 'submit', false ); ?>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;">
                            <?php wp_nonce_field( 'erp_sync_actions' ); ?>
                            <input type="hidden" name="action" value="erp_sync_products_test">
                            <?php submit_button( __('Test Products','erp-sync'), 'secondary', 'submit', false ); ?>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;">
                            <?php wp_nonce_field( 'erp_sync_actions' ); ?>
                            <input type="hidden" name="action" value="erp_sync_generate_mock">
                            <?php submit_button( __('Generate Mock Cards','erp-sync'), 'secondary', 'submit', false ); ?>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;">
                            <?php wp_nonce_field( 'erp_sync_actions' ); ?>
                            <input type="hidden" name="action" value="erp_sync_run_cron_now">
                            <?php submit_button( __('Run Scheduled Import Now','erp-sync'), 'secondary', 'submit', false ); ?>
                        </form>
                    </div>
                </div>

                <!-- Webhooks Tab -->
                <div id="tab-webhooks" class="erp-sync-tab-content" style="display:none;">
                    <h2><?php _e( 'Webhook Configuration', 'erp-sync' ); ?></h2>
                    <p class="description"><?php _e('Send real-time notifications to your 1C server when events occur.', 'erp-sync'); ?></p>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="webhook_enabled"><?php _e('Enable Webhooks','erp-sync'); ?></label></th>
                            <td>
                                <label><input type="checkbox" id="webhook_enabled" name="webhook_enabled" value="1" <?php checked( $webhook_enabled ); ?>> <?php _e('Send webhook notifications to 1C server.', 'erp-sync'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="webhook_url"><?php _e('Webhook URL','erp-sync'); ?></label></th>
                            <td>
                                <input type="url" class="regular-text" id="webhook_url" name="webhook_url" value="<?php echo esc_attr( $webhook_url ); ?>" placeholder="https://your-1c-server.com/webhook">
                                <p class="description"><?php _e('The endpoint URL to receive webhook notifications.', 'erp-sync'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="webhook_secret"><?php _e('Webhook Secret','erp-sync'); ?></label></th>
                            <td>
                                <input type="text" class="regular-text" id="webhook_secret" name="webhook_secret" value="<?php echo esc_attr( $webhook_secret ); ?>" placeholder="<?php esc_attr_e('Optional secret key', 'erp-sync'); ?>">
                                <p class="description"><?php _e('Optional secret key for webhook signature verification (HMAC-SHA256).', 'erp-sync'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Events to Track','erp-sync'); ?></th>
                            <td>
                                <?php
                                $available_events = [
                                    'coupon.applied'   => __( 'Coupon Applied', 'erp-sync' ),
                                    'coupon.removed'   => __( 'Coupon Removed', 'erp-sync' ),
                                    'order.completed'  => __( 'Order Completed', 'erp-sync' ),
                                ];
                                foreach ( $available_events as $event => $label ) {
                                    $checked = in_array( $event, $webhook_events, true ) ? 'checked' : '';
                                    echo '<label style="display:block;margin-bottom:5px;">';
                                    echo '<input type="checkbox" name="webhook_events[]" value="'.esc_attr($event).'" '.$checked.'> ';
                                    echo esc_html($label);
                                    echo '</label>';
                                }
                                ?>
                                <p class="description"><?php _e('Select which events should trigger webhooks.', 'erp-sync'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Trigger Sync Endpoint','erp-sync'); ?></th>
                            <td>
                                <code><?php echo esc_url( rest_url( 'erp-sync/v1/trigger-sync' ) ); ?></code>
                                <p class="description"><?php _e('External systems can POST to this endpoint to trigger a sync.', 'erp-sync'); ?></p>
                                <p class="description"><?php _e('Include header: <code>X-ERPSync-Secret: your_secret</code>', 'erp-sync'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Security Tab -->
                <div id="tab-security" class="erp-sync-tab-content" style="display:none;">
                    <h2><?php _e( 'Security Settings', 'erp-sync' ); ?></h2>
                    <p class="description"><?php _e('Protect your API calls with rate limiting and IP whitelisting.', 'erp-sync'); ?></p>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="rate_limit_enabled"><?php _e('Enable Rate Limiting','erp-sync'); ?></label></th>
                            <td>
                                <label><input type="checkbox" id="rate_limit_enabled" name="rate_limit_enabled" value="1" <?php checked( $rate_limit ); ?>> <?php _e('Limit the number of API calls per minute.', 'erp-sync'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="rate_limit_max"><?php _e('Max Requests per Minute','erp-sync'); ?></label></th>
                            <td>
                                <input type="number" min="10" max="1000" id="rate_limit_max" name="rate_limit_max" value="<?php echo esc_attr( $rate_limit_max ); ?>">
                                <p class="description"><?php _e('Maximum number of requests allowed per IP address per minute.', 'erp-sync'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ip_whitelist"><?php _e('IP Whitelist','erp-sync'); ?></label></th>
                            <td>
                                <textarea id="ip_whitelist" name="ip_whitelist" rows="5" class="large-text"><?php echo esc_textarea( $ip_whitelist ); ?></textarea>
                                <p class="description"><?php _e('One IP address per line. Leave empty to allow all IPs.', 'erp-sync'); ?></p>
                                <p class="description"><?php printf( __('Your current IP: %s', 'erp-sync'), '<code>' . esc_html( Security::get_client_ip() ) . '</code>' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Credential Encryption','erp-sync'); ?></th>
                            <td>
                                <p>✅ <?php _e('Passwords are encrypted using AES-256-CBC before storage.', 'erp-sync'); ?></p>
                                <p class="description"><?php _e('Encryption key is automatically generated and stored securely.', 'erp-sync'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Diagnostics Tab -->
                <div id="tab-diagnostics" class="erp-sync-tab-content" style="display:none;">
                    <h2><?php _e( 'Diagnostics & Debug Data', 'erp-sync' ); ?></h2>
                    <p class="description"><?php _e('Enable Debug in Settings tab, run a sync, then download the artifacts here.', 'erp-sync'); ?></p>
                    
                    <div class="erp-sync-diagnostics-buttons">
                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;">
                            <?php wp_nonce_field( 'erp_sync_actions' ); ?>
                            <input type="hidden" name="action" value="erp_sync_download_last_request" />
                            <?php submit_button( __( 'Download Last SOAP Request', 'erp-sync' ), 'secondary', 'submit', false ); ?>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;">
                            <?php wp_nonce_field( 'erp_sync_actions' ); ?>
                            <input type="hidden" name="action" value="erp_sync_download_last_xml" />
                            <?php submit_button( __( 'Download Last SOAP Response', 'erp-sync' ), 'secondary', 'submit', false ); ?>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;">
                            <?php wp_nonce_field( 'erp_sync_actions' ); ?>
                            <input type="hidden" name="action" value="erp_sync_download_last_fault" />
                            <?php submit_button( __( 'Download Last SOAP Fault', 'erp-sync' ), 'secondary', 'submit', false ); ?>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;">
                            <?php wp_nonce_field( 'erp_sync_actions' ); ?>
                            <input type="hidden" name="action" value="erp_sync_download_last_headers" />
                            <?php submit_button( __( 'Download Last SOAP Headers', 'erp-sync' ), 'secondary', 'submit', false ); ?>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;">
                            <?php wp_nonce_field( 'erp_sync_actions' ); ?>
                            <input type="hidden" name="action" value="erp_sync_download_last_meta" />
                            <?php submit_button( __( 'Download InfoCards Meta (JSON)', 'erp-sync' ), 'secondary', 'submit', false ); ?>
                        </form>
                    </div>

                    <?php if ( $debug ) : ?>
                        <?php if ( $last_req_xml ) : ?>
                            <h3><?php _e('Last SOAP Request (excerpt)','erp-sync'); ?></h3>
                            <textarea readonly rows="8" class="large-text code"><?php echo esc_textarea( $last_req_xml ); ?></textarea>
                        <?php endif; ?>

                        <?php if ( $last_raw_xml ) : ?>
                            <h3><?php _e('Last SOAP Response (excerpt)','erp-sync'); ?></h3>
                            <textarea readonly rows="8" class="large-text code"><?php echo esc_textarea( $last_raw_xml ); ?></textarea>
                        <?php endif; ?>

                        <?php if ( $last_req_headers ) : ?>
                            <h3><?php _e('Last SOAP Request Headers (excerpt)','erp-sync'); ?></h3>
                            <textarea readonly rows="6" class="large-text code"><?php echo esc_textarea( $last_req_headers ); ?></textarea>
                        <?php endif; ?>

                        <?php if ( $last_res_headers ) : ?>
                            <h3><?php _e('Last SOAP Response Headers (excerpt)','erp-sync'); ?></h3>
                            <textarea readonly rows="6" class="large-text code"><?php echo esc_textarea( $last_res_headers ); ?></textarea>
                        <?php endif; ?>

                        <?php if ( $last_meta_json ) : ?>
                            <h3><?php _e('Last InformationCards Meta','erp-sync'); ?></h3>
                            <textarea readonly rows="6" class="large-text code"><?php echo esc_textarea( $last_meta_json ); ?></textarea>
                        <?php endif; ?>

                        <?php if ( $last_fault ) : ?>
                            <h3><?php _e('Last SOAP Fault','erp-sync'); ?></h3>
                            <textarea readonly rows="4" class="large-text code"><?php echo esc_textarea( $last_fault ); ?></textarea>
                        <?php endif; ?>

                        <?php if ( $last_raw_json ) : ?>
                            <h3><?php _e('Last Raw JSON Excerpt','erp-sync'); ?></h3>
                            <textarea readonly rows="4" class="large-text code"><?php echo esc_textarea( $last_raw_json ); ?></textarea>
                        <?php endif; ?>

                        <?php if ( $last_prod_xml ) : ?>
                            <h3><?php _e('Products Raw XML Excerpt','erp-sync'); ?></h3>
                            <textarea readonly rows="8" class="large-text code"><?php echo esc_textarea( $last_prod_xml ); ?></textarea>
                        <?php endif; ?>
                    <?php else : ?>
                        <p class="erp-sync-notice"><?php _e('Enable Debug mode in Settings tab to see detailed information here.', 'erp-sync'); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Save Button (visible on all tabs) -->
                <p class="submit">
                    <?php submit_button( __( 'Save All Settings', 'erp-sync' ), 'primary', 'submit', false ); ?>
                </p>
            </form>
        </div>
        <?php
    }

    public static function add_coupon_columns( array $cols ): array {
        $cols['erp_sync_status']        = __( 'Status', 'erp-sync' );
        $cols['erp_sync_base_discount'] = __( 'Base %', 'erp-sync' );
        $cols['erp_sync_is_deleted']    = __( 'Deleted?', 'erp-sync' );
        $cols['erp_sync_dob']           = __( 'DOB', 'erp-sync' );
        return $cols;
    }

    public static function render_coupon_columns( string $column, int $post_id ): void {
        if ( ! get_post_meta( $post_id, '_erp_sync_managed', true ) ) return;
        
        switch ( $column ) {
            case 'erp_sync_status':
                $is_deleted = get_post_meta( $post_id, '_erp_sync_is_deleted', true ) === 'yes';
                $dob = (string) get_post_meta( $post_id, '_erp_sync_dob', true );
                $is_birthday = self::is_in_birthday_window( $dob );
                
                if ( $is_deleted ) {
                    echo '<span class="erp-sync-status-badge erp-sync-status-deleted">🚫 ' . esc_html__( 'Deleted', 'erp-sync' ) . '</span>';
                } elseif ( $is_birthday ) {
                    echo '<span class="erp-sync-status-badge erp-sync-status-birthday">🎂 ' . esc_html__( 'Birthday', 'erp-sync' ) . '</span>';
                } else {
                    echo '<span class="erp-sync-status-badge erp-sync-status-active">✅ ' . esc_html__( 'Active', 'erp-sync' ) . '</span>';
                }
                break;
                
            case 'erp_sync_base_discount':
                $value = get_post_meta( $post_id, '_erp_sync_base_discount', true );
                echo '<span class="erp-sync-editable" data-coupon-id="' . esc_attr( $post_id ) . '" data-field="base_discount">';
                echo esc_html( $value ) . '%';
                echo '</span>';
                break;
                
            case 'erp_sync_is_deleted':
                $value = get_post_meta( $post_id, '_erp_sync_is_deleted', true );
                $display = $value === 'yes' ? __('Yes', 'erp-sync') : __('No', 'erp-sync');
                echo '<span class="erp-sync-editable" data-coupon-id="' . esc_attr( $post_id ) . '" data-field="is_deleted">';
                echo esc_html( $display );
                echo '</span>';
                break;
                
            case 'erp_sync_dob':
                echo esc_html( get_post_meta( $post_id, '_erp_sync_dob', true ) );
                break;
        }
    }

    public static function sortable_columns( array $columns ): array {
        $columns['erp_sync_base_discount'] = 'erp_sync_base_discount';
        $columns['erp_sync_dob'] = 'erp_sync_dob';
        return $columns;
    }

    private static function is_in_birthday_window( string $dob ): bool {
        if ( empty( $dob ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ) {
            return false;
        }
        
        try {
            list( $y, $m, $d ) = array_map( 'intval', explode( '-', $dob ) );
            $now = current_time( 'timestamp' );
            $year = (int) date( 'Y', $now );

            $candidate_dates = [];
            if ( $m === 2 && $d === 29 && ! self::is_leap_year( $year ) ) {
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

    private static function is_leap_year( int $y ): bool {
        return ( ( $y % 4 === 0 ) && ( $y % 100 !== 0 ) ) || ( $y % 400 === 0 );
    }
}
