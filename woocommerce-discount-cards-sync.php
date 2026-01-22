<?php
/**
 * Plugin Name: WooCommerce Discount Cards Sync
 * Description: Synchronize discount cards (coupons) from a 1C SOAP WebExchange service into WooCommerce. Includes manual sync, non-destructive connectivity test, diagnostics, webhooks, security features, and scheduled (WP-Cron) imports.
 * Version: 1.2.0
 * Author: WDCS
 * Text Domain: wdcs
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC tested up to: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Constants
 */
define( 'WDCS_VERSION', '1.2.0' );
define( 'WDCS_FILE', __FILE__ );
define( 'WDCS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WDCS_URL', plugin_dir_url( __FILE__ ) );
define( 'WDCS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Basic environment checks and admin notices
 */
function wdcs_admin_notice_missing_wc() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    echo '<div class="notice notice-error"><p>' . esc_html__( 'WooCommerce Discount Cards Sync requires WooCommerce to be installed and active.', 'wdcs' ) . '</p></div>';
}

function wdcs_admin_notice_missing_soap() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    echo '<div class="notice notice-warning"><p>' . esc_html__( 'WooCommerce Discount Cards Sync: PHP SOAP extension is not available. Please enable it to use the 1C integration.', 'wdcs' ) . '</p></div>';
}

/**
 * i18n
 */
function wdcs_load_textdomain() {
    load_plugin_textdomain( 'wdcs', false, dirname( WDCS_BASENAME ) . '/languages' );
}
add_action( 'plugins_loaded', 'wdcs_load_textdomain' );

/**
 * Includes
 */
require_once WDCS_DIR . 'includes/class-wdcs-logger.php';
require_once WDCS_DIR . 'includes/class-wdcs-security.php';
require_once WDCS_DIR . 'includes/class-wdcs-api-client.php';
require_once WDCS_DIR . 'includes/class-wdcs-sync-service.php';
require_once WDCS_DIR . 'includes/class-wdcs-webhook.php';
require_once WDCS_DIR . 'includes/class-wdcs-coupon-dynamic.php';
require_once WDCS_DIR . 'includes/class-wdcs-admin.php';
require_once WDCS_DIR . 'includes/class-wdcs-cron.php';
require_once WDCS_DIR . 'includes/functions-helpers.php';

/**
 * Bootstrap
 */
function wdcs_bootstrap() {
    // WooCommerce check
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'wdcs_admin_notice_missing_wc' );
        return;
    }

    // SOAP extension notice
    if ( ! class_exists( 'SoapClient' ) ) {
        add_action( 'admin_notices', 'wdcs_admin_notice_missing_soap' );
    }

    // Initialize Security first
    if ( class_exists( '\WDCS\Security' ) ) {
        \WDCS\Security::init();
    }

    // Initialize Webhook
    if ( class_exists( '\WDCS\Webhook' ) ) {
        \WDCS\Webhook::init();
    }

    // Initialize Coupon Dynamic
    if ( class_exists( '\WDCS\Coupon_Dynamic' ) ) {
        \WDCS\Coupon_Dynamic::init();
    }

    // Initialize Admin UI and Cron
    if ( class_exists( '\WDCS\Admin' ) ) {
        \WDCS\Admin::init();
    }
    if ( class_exists( '\WDCS\Cron' ) ) {
        \WDCS\Cron::init();
    }
}
add_action( 'plugins_loaded', 'wdcs_bootstrap', 20 );

/**
 * Activation/Deactivation
 */
function wdcs_activate() {
    if ( class_exists( '\WDCS\Cron' ) ) {
        \WDCS\Cron::activate();
    }
    if ( class_exists( '\WDCS\Security' ) ) {
        \WDCS\Security::create_tables();
    }
}
register_activation_hook( __FILE__, 'wdcs_activate' );

function wdcs_deactivate() {
    if ( class_exists( '\WDCS\Cron' ) ) {
        \WDCS\Cron::deactivate();
    }
}
register_deactivation_hook( __FILE__, 'wdcs_deactivate' );

/**
 * Plugin row meta
 */
function wdcs_plugin_row_meta( $links, $file ) {
    if ( WDCS_BASENAME === $file ) {
        $links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=wdcs-discount-cards' ) ) . '">' . esc_html__( 'Settings', 'wdcs' ) . '</a>';
    }
    return $links;
}
add_filter( 'plugin_action_links_' . WDCS_BASENAME, 'wdcs_plugin_row_meta', 10, 2 );

/**
 * Enqueue admin assets
 */
function wdcs_enqueue_admin_assets( $hook ) {
    if ( strpos( $hook, 'wdcs-discount-cards' ) === false && $hook !== 'edit.php' ) {
        return;
    }
    wp_enqueue_style( 'wdcs-admin', WDCS_URL . 'assets/admin.css', [], WDCS_VERSION );
    wp_enqueue_script( 'wdcs-admin', WDCS_URL . 'assets/admin.js', [ 'jquery' ], WDCS_VERSION, true );
    wp_localize_script( 'wdcs-admin', 'wdcsAdmin', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'wdcs_ajax' ),
    ] );
}
add_action( 'admin_enqueue_scripts', 'wdcs_enqueue_admin_assets' );