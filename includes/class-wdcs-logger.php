<?php
namespace WDCS;

if ( ! defined( 'ABSPATH' ) ) exit;

class Logger {
    private static $instance;
    private $logger;

    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( class_exists( '\\WC_Logger' ) ) {
            $this->logger = new \WC_Logger();
        }
    }

    public function log( $message, $context = [] ) {
        $line = [
            'msg' => $message,
            'context' => $context,
            'time' => current_time( 'mysql' ),
            'ver' => WDCS_VERSION
        ];
        if ( $this->logger ) {
            $this->logger->add( 'wdcs', wp_json_encode( $line, JSON_UNESCAPED_UNICODE ) );
        } else {
            error_log( '[WDCS] ' . wp_json_encode( $line, JSON_UNESCAPED_UNICODE ) );
        }
    }
}