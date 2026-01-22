<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

class Logger {
    private static ?Logger $instance = null;
    private ?\WC_Logger $logger = null;

    public static function instance(): Logger {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( class_exists( '\\WC_Logger' ) ) {
            $this->logger = new \WC_Logger();
        }
    }

    public function log( string $message, array $context = [] ): void {
        $line = [
            'msg' => $message,
            'context' => $context,
            'time' => current_time( 'mysql' ),
            'ver' => ERPSYNC_VERSION
        ];
        if ( $this->logger ) {
            $this->logger->add( 'erp-sync', wp_json_encode( $line, JSON_UNESCAPED_UNICODE ) );
        } else {
            error_log( '[ERPSync] ' . wp_json_encode( $line, JSON_UNESCAPED_UNICODE ) );
        }
    }
}
