<?php
/**
 * Plugin Name: ERP Sync Products and Coupons
 * Description: Synchronize products (catalog, stock, prices) and discount cards (coupons) from 1C/IBS SOAP WebExchange service into WooCommerce.
 * Version: 1.2.0
 * Author: ERPSync
 * Text Domain: erp-sync
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC tested up to: 8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Constants
 */
define( 'ERPSYNC_VERSION', '1.2.0' );
define( 'ERPSYNC_FILE', __FILE__ );
define( 'ERPSYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'ERPSYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'ERPSYNC_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Migrate options from old WDCS plugin to new ERPSync naming.
 * This function copies values from old wdcs_ option keys to new erp_sync_ keys,
 * ensuring users don't lose their settings during the upgrade.
 */
function erp_sync_migrate_options(): void {
    // Check if migration has already been completed
    if ( get_option( 'erp_sync_migration_completed', false ) ) {
        return;
    }

    // Define mapping of old option names to new option names
    $option_map = [
        // API Client options
        'wdcs_api_username'        => 'erp_sync_api_username',
        'wdcs_api_password'        => 'erp_sync_api_password',
        'wdcs_api_wsdl'            => 'erp_sync_api_wsdl',
        'wdcs_api_force_location'  => 'erp_sync_api_force_location',
        'wdcs_api_timeout'         => 'erp_sync_api_timeout',
        'wdcs_api_debug'           => 'erp_sync_api_debug',
        'wdcs_api_soap_version'    => 'erp_sync_api_soap_version',
        
        // Debug artifacts
        'wdcs_last_raw_excerpt'     => 'erp_sync_last_raw_excerpt',
        'wdcs_last_raw_xml'         => 'erp_sync_last_raw_xml',
        'wdcs_last_products_xml'    => 'erp_sync_last_products_xml',
        'wdcs_last_request_xml'     => 'erp_sync_last_request_xml',
        'wdcs_last_soap_fault'      => 'erp_sync_last_soap_fault',
        'wdcs_last_request_headers' => 'erp_sync_last_request_headers',
        'wdcs_last_response_headers'=> 'erp_sync_last_response_headers',
        'wdcs_last_infocards_meta'  => 'erp_sync_last_infocards_meta',
        
        // Sync Service options
        'wdcs_last_sync'           => 'erp_sync_last_sync',
        
        // Cron options
        'wdcs_cron_enabled'        => 'erp_sync_cron_enabled',
        'wdcs_cron_interval'       => 'erp_sync_cron_interval',
        'wdcs_cron_last_result'    => 'erp_sync_cron_last_result',
        
        // Security options
        'wdcs_ip_whitelist'        => 'erp_sync_ip_whitelist',
        'wdcs_rate_limit_enabled'  => 'erp_sync_rate_limit_enabled',
        'wdcs_rate_limit_max'      => 'erp_sync_rate_limit_max',
        'wdcs_encryption_key'      => 'erp_sync_encryption_key',
        
        // Webhook options
        'wdcs_webhook_url'         => 'erp_sync_webhook_url',
        'wdcs_webhook_enabled'     => 'erp_sync_webhook_enabled',
        'wdcs_webhook_secret'      => 'erp_sync_webhook_secret',
        'wdcs_webhook_events'      => 'erp_sync_webhook_events',
    ];

    $migrated_count = 0;

    foreach ( $option_map as $old_key => $new_key ) {
        $old_value = get_option( $old_key, null );
        
        // Only migrate if old option exists and new option doesn't
        if ( $old_value !== null && get_option( $new_key, null ) === null ) {
            update_option( $new_key, $old_value );
            $migrated_count++;
        }
    }

    // Migrate cron interval values if they contain old prefix
    $cron_interval = get_option( 'erp_sync_cron_interval', '' );
    if ( strpos( $cron_interval, 'wdcs_' ) === 0 ) {
        $new_interval = str_replace( 'wdcs_', 'erp_sync_', $cron_interval );
        update_option( 'erp_sync_cron_interval', $new_interval );
    }

    // Mark migration as completed
    update_option( 'erp_sync_migration_completed', true );
    update_option( 'erp_sync_migration_date', current_time( 'mysql' ) );
    update_option( 'erp_sync_migrated_options_count', $migrated_count );

    // Log migration if Logger is available
    if ( class_exists( '\ERPSync\Logger' ) ) {
        \ERPSync\Logger::instance()->log( 'Options migrated from WDCS to ERPSync', [
            'migrated_count' => $migrated_count,
            'timestamp'      => current_time( 'mysql' ),
        ] );
    }
}

/**
 * Basic environment checks and admin notices
 */
function erp_sync_admin_notice_missing_wc(): void {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    echo '<div class="notice notice-error"><p>' . esc_html__( 'ERP Sync Products and Coupons requires WooCommerce to be installed and active.', 'erp-sync' ) . '</p></div>';
}

function erp_sync_admin_notice_missing_soap(): void {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    echo '<div class="notice notice-warning"><p>' . esc_html__( 'ERP Sync Products and Coupons: PHP SOAP extension is not available. Please enable it to use the 1C integration.', 'erp-sync' ) . '</p></div>';
}

/**
 * i18n
 */
function erp_sync_load_textdomain(): void {
    load_plugin_textdomain( 'erp-sync', false, dirname( ERPSYNC_BASENAME ) . '/languages' );
}
add_action( 'plugins_loaded', 'erp_sync_load_textdomain' );

/**
 * Includes
 */
require_once ERPSYNC_DIR . 'includes/class-erpsync-logger.php';
require_once ERPSYNC_DIR . 'includes/class-erpsync-security.php';
require_once ERPSYNC_DIR . 'includes/class-erpsync-api-client.php';
require_once ERPSYNC_DIR . 'includes/class-erpsync-audit-logger.php';
require_once ERPSYNC_DIR . 'includes/class-erpsync-product-service.php';
require_once ERPSYNC_DIR . 'includes/class-erpsync-sync-service.php';
require_once ERPSYNC_DIR . 'includes/class-erpsync-webhook.php';
require_once ERPSYNC_DIR . 'includes/class-erpsync-coupon-dynamic.php';
require_once ERPSYNC_DIR . 'includes/class-erpsync-admin.php';
require_once ERPSYNC_DIR . 'includes/class-erpsync-cron.php';
require_once ERPSYNC_DIR . 'includes/class-erpsync-frontend.php';
require_once ERPSYNC_DIR . 'includes/functions-helpers.php';

/**
 * Bootstrap
 */
function erp_sync_bootstrap(): void {
    // WooCommerce check
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'erp_sync_admin_notice_missing_wc' );
        return;
    }

    // SOAP extension notice
    if ( ! class_exists( 'SoapClient' ) ) {
        add_action( 'admin_notices', 'erp_sync_admin_notice_missing_soap' );
    }

    // Initialize Security first
    if ( class_exists( '\ERPSync\Security' ) ) {
        \ERPSync\Security::init();
    }

    // Initialize Product Service (registers branch taxonomy)
    if ( class_exists( '\ERPSync\Product_Service' ) ) {
        \ERPSync\Product_Service::init();
    }

    // Initialize Sync Service (registers Action Scheduler hooks)
    if ( class_exists( '\ERPSync\Sync_Service' ) ) {
        \ERPSync\Sync_Service::init();
    }

    // Initialize Webhook
    if ( class_exists( '\ERPSync\Webhook' ) ) {
        \ERPSync\Webhook::init();
    }

    // Initialize Coupon Dynamic
    if ( class_exists( '\ERPSync\Coupon_Dynamic' ) ) {
        \ERPSync\Coupon_Dynamic::init();
    }

    // Initialize Frontend (branch stock display)
    if ( class_exists( '\ERPSync\Frontend' ) ) {
        \ERPSync\Frontend::init();
    }

    // Initialize Admin UI and Cron
    if ( class_exists( '\ERPSync\Admin' ) ) {
        \ERPSync\Admin::init();
    }
    if ( class_exists( '\ERPSync\Cron' ) ) {
        \ERPSync\Cron::init();
    }
}
add_action( 'plugins_loaded', 'erp_sync_bootstrap', 20 );

/**
 * Activation/Deactivation
 */
function erp_sync_activate(): void {
    // Run migration first to preserve settings
    erp_sync_migrate_options();
    
    if ( class_exists( '\ERPSync\Cron' ) ) {
        \ERPSync\Cron::activate();
    }
    if ( class_exists( '\ERPSync\Security' ) ) {
        \ERPSync\Security::create_tables();
    }
    
    // Create product logs table
    erp_sync_create_log_table();
}
register_activation_hook( __FILE__, 'erp_sync_activate' );

/**
 * Create the product logs table for audit logging.
 * 
 * Table stores product change history (stock, price changes) from ERP sync.
 */
function erp_sync_create_log_table(): void {
    global $wpdb;
    
    $table_name      = $wpdb->prefix . 'erp_sync_product_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id bigint(20) UNSIGNED NOT NULL,
        vendor_code varchar(100) NOT NULL DEFAULT '',
        product_name varchar(255) NOT NULL DEFAULT '',
        change_type varchar(50) NOT NULL DEFAULT '',
        old_value text,
        new_value text,
        message text,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY product_id (product_id),
        KEY vendor_code (vendor_code),
        KEY created_at (created_at)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    
    if ( class_exists( '\ERPSync\Logger' ) ) {
        \ERPSync\Logger::instance()->log( 'Product logs table created/verified', [] );
    }
}

function erp_sync_deactivate(): void {
    if ( class_exists( '\ERPSync\Cron' ) ) {
        \ERPSync\Cron::deactivate();
    }
}
register_deactivation_hook( __FILE__, 'erp_sync_deactivate' );

/**
 * Plugin row meta
 */
function erp_sync_plugin_row_meta( array $links, string $file ): array {
    if ( ERPSYNC_BASENAME === $file ) {
        $links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=erp-sync-settings' ) ) . '">' . esc_html__( 'Settings', 'erp-sync' ) . '</a>';
    }
    return $links;
}
add_filter( 'plugin_action_links_' . ERPSYNC_BASENAME, 'erp_sync_plugin_row_meta', 10, 2 );

/**
 * Enqueue admin assets
 */
function erp_sync_enqueue_admin_assets( string $hook ): void {
    // Determine if we should load assets on plugin pages
    $is_erp_sync_page = strpos( $hook, 'erp-sync-settings' ) !== false || strpos( $hook, 'erp-sync-logs' ) !== false;
    
    // Use get_current_screen() for robust detection of product/coupon list pages
    $screen = get_current_screen();
    $is_coupon_list  = false;
    $is_product_list = false;
    
    if ( $screen ) {
        $is_coupon_list  = $screen->id === 'edit-shop_coupon' || $screen->post_type === 'shop_coupon';
        $is_product_list = $screen->id === 'edit-product' || $screen->post_type === 'product';
    }
    
    if ( ! $is_erp_sync_page && ! $is_coupon_list && ! $is_product_list ) {
        return;
    }
    
    wp_enqueue_style( 'erp-sync-admin', ERPSYNC_URL . 'assets/admin.css', [], ERPSYNC_VERSION );
    wp_enqueue_script( 'erp-sync-admin', ERPSYNC_URL . 'assets/admin.js', [ 'jquery' ], ERPSYNC_VERSION, true );
    wp_localize_script( 'erp-sync-admin', 'erpSyncAdmin', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'erp_sync_ajax' ),
    ] );
}
add_action( 'admin_enqueue_scripts', 'erp_sync_enqueue_admin_assets' );
