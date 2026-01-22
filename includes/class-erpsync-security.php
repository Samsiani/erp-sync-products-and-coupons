<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

class Security {

    const OPTION_IP_WHITELIST    = 'erp_sync_ip_whitelist';
    const OPTION_RATE_LIMIT      = 'erp_sync_rate_limit_enabled';
    const OPTION_RATE_LIMIT_MAX  = 'erp_sync_rate_limit_max';
    const OPTION_ENCRYPTION_KEY  = 'erp_sync_encryption_key';
    const TRANSIENT_PREFIX       = 'erp_sync_rate_limit_';

    public static function init(): void {
        // Ensure encryption key exists
        self::ensure_encryption_key();
        
        // Hook into API calls for rate limiting and IP whitelisting
        add_action( 'erp_sync_before_api_call', [ __CLASS__, 'check_rate_limit' ] );
        add_action( 'erp_sync_before_api_call', [ __CLASS__, 'check_ip_whitelist' ] );
        
        // Schedule cleanup of old logs
        if ( ! wp_next_scheduled( 'erp_sync_cleanup_logs' ) ) {
            wp_schedule_event( time(), 'daily', 'erp_sync_cleanup_logs' );
        }
        add_action( 'erp_sync_cleanup_logs', [ __CLASS__, 'cleanup_old_logs' ] );
    }

    public static function create_tables(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'erp_sync_api_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL,
            endpoint varchar(100) NOT NULL,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY ip_timestamp (ip_address, timestamp)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
        
        Logger::instance()->log( 'Security tables created', [] );
    }

    /**
     * Ensure encryption key exists
     */
    public static function ensure_encryption_key(): void {
        if ( ! get_option( self::OPTION_ENCRYPTION_KEY ) ) {
            $key = base64_encode( random_bytes( 32 ) );
            update_option( self::OPTION_ENCRYPTION_KEY, $key );
            Logger::instance()->log( 'Encryption key generated', [] );
        }
    }

    /**
     * Encrypt sensitive data using AES-256-CBC
     * Handles single characters and numbers correctly
     */
    public static function encrypt( string $data ): string {
        if ( $data === '' ) {
            return '';
        }
        
        try {
            $key = base64_decode( (string) get_option( self::OPTION_ENCRYPTION_KEY ) );
            
            if ( empty( $key ) || strlen( $key ) < 32 ) {
                Logger::instance()->log( 'Encryption key invalid', [] );
                return '';
            }
            
            $iv = openssl_random_pseudo_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
            $encrypted = openssl_encrypt( $data, 'aes-256-cbc', $key, 0, $iv );
            
            if ( $encrypted === false ) {
                Logger::instance()->log( 'Encryption failed', [] );
                return '';
            }
            
            // Add a marker to identify encrypted data and make backward compatibility easier
            return 'ERPSYNC_ENC::' . base64_encode( $encrypted . '::' . base64_encode( $iv ) );
            
        } catch ( \Throwable $e ) {
            Logger::instance()->log( 'Encryption exception', [ 'error' => $e->getMessage() ] );
            return '';
        }
    }

    /**
     * Decrypt sensitive data
     * Falls back to plain text for backward compatibility
     */
    public static function decrypt( string $data ): string {
        if ( $data === '' ) {
            return '';
        }
        
        // Check if it's encrypted by our system (new format)
        if ( strpos( $data, 'ERPSYNC_ENC::' ) === 0 ) {
            return self::decrypt_new_format( $data );
        }
        
        // Check for old WDCS format for backward compatibility
        if ( strpos( $data, 'WDCS_ENC::' ) === 0 ) {
            return self::decrypt_old_format( $data );
        }
        
        // Not encrypted with our marker, return as-is (backward compatibility with plain text)
        return $data;
    }

    /**
     * Decrypt data encrypted with new ERPSYNC format
     */
    private static function decrypt_new_format( string $data ): string {
        try {
            // Remove marker prefix
            $encrypted_payload = substr( $data, 13 ); // Remove "ERPSYNC_ENC::"
            
            $key = base64_decode( (string) get_option( self::OPTION_ENCRYPTION_KEY ) );
            
            if ( empty( $key ) || strlen( $key ) < 32 ) {
                Logger::instance()->log( 'Decryption key invalid', [] );
                return '';
            }
            
            $decoded = base64_decode( $encrypted_payload );
            $parts = explode( '::', $decoded, 2 );
            
            if ( count( $parts ) !== 2 ) {
                Logger::instance()->log( 'Decryption format invalid', [] );
                return '';
            }
            
            list( $encrypted_data, $iv_encoded ) = $parts;
            $iv = base64_decode( $iv_encoded );
            
            if ( empty( $iv ) ) {
                Logger::instance()->log( 'Decryption IV invalid', [] );
                return '';
            }
            
            $decrypted = openssl_decrypt( $encrypted_data, 'aes-256-cbc', $key, 0, $iv );
            
            if ( $decrypted === false ) {
                Logger::instance()->log( 'Decryption failed', [] );
                return '';
            }
            
            return $decrypted;
            
        } catch ( \Throwable $e ) {
            Logger::instance()->log( 'Decryption exception', [ 'error' => $e->getMessage() ] );
            return '';
        }
    }

    /**
     * Decrypt data encrypted with old WDCS format (backward compatibility)
     */
    private static function decrypt_old_format( string $data ): string {
        try {
            // Remove marker prefix
            $encrypted_payload = substr( $data, 10 ); // Remove "WDCS_ENC::"
            
            $key = base64_decode( (string) get_option( self::OPTION_ENCRYPTION_KEY ) );
            
            if ( empty( $key ) || strlen( $key ) < 32 ) {
                Logger::instance()->log( 'Decryption key invalid (old format)', [] );
                return '';
            }
            
            $decoded = base64_decode( $encrypted_payload );
            $parts = explode( '::', $decoded, 2 );
            
            if ( count( $parts ) !== 2 ) {
                Logger::instance()->log( 'Decryption format invalid (old format)', [] );
                return '';
            }
            
            list( $encrypted_data, $iv_encoded ) = $parts;
            $iv = base64_decode( $iv_encoded );
            
            if ( empty( $iv ) ) {
                Logger::instance()->log( 'Decryption IV invalid (old format)', [] );
                return '';
            }
            
            $decrypted = openssl_decrypt( $encrypted_data, 'aes-256-cbc', $key, 0, $iv );
            
            if ( $decrypted === false ) {
                Logger::instance()->log( 'Decryption failed (old format)', [] );
                return '';
            }
            
            return $decrypted;
            
        } catch ( \Throwable $e ) {
            Logger::instance()->log( 'Decryption exception (old format)', [ 'error' => $e->getMessage() ] );
            return '';
        }
    }

    /**
     * Check rate limiting
     */
    public static function check_rate_limit(): void {
        if ( ! get_option( self::OPTION_RATE_LIMIT, false ) ) {
            return;
        }

        $max_requests = (int) get_option( self::OPTION_RATE_LIMIT_MAX, 60 );
        $ip = self::get_client_ip();
        $transient_key = self::TRANSIENT_PREFIX . md5( $ip );
        
        $requests = get_transient( $transient_key );
        
        if ( $requests === false ) {
            set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
        } else {
            if ( $requests >= $max_requests ) {
                Logger::instance()->log( 'Rate limit exceeded', [ 
                    'ip' => $ip, 
                    'requests' => $requests,
                    'max' => $max_requests 
                ] );
                
                wp_die( 
                    esc_html__( 'Rate limit exceeded. Please try again later.', 'erp-sync' ),
                    esc_html__( 'Too Many Requests', 'erp-sync' ),
                    [ 'response' => 429 ]
                );
            }
            set_transient( $transient_key, $requests + 1, MINUTE_IN_SECONDS );
        }

        self::log_api_call( $ip, 'SOAP_API' );
    }

    /**
     * Check IP whitelist
     */
    public static function check_ip_whitelist(): void {
        $whitelist = get_option( self::OPTION_IP_WHITELIST, '' );
        
        if ( empty( $whitelist ) ) {
            return; // No whitelist configured
        }

        $ip = self::get_client_ip();
        $allowed_ips = array_map( 'trim', explode( "\n", $whitelist ) );
        $allowed_ips = array_filter( $allowed_ips ); // Remove empty lines
        
        // Normalize IPs
        $allowed_ips = array_map( function( $allowed_ip ) {
            return trim( $allowed_ip );
        }, $allowed_ips );
        
        if ( ! in_array( $ip, $allowed_ips, true ) ) {
            Logger::instance()->log( 'IP blocked by whitelist', [ 
                'ip' => $ip, 
                'allowed_ips' => implode( ', ', $allowed_ips ) 
            ] );
            
            wp_die( 
                sprintf( 
                    esc_html__( 'Access denied. Your IP address (%s) is not whitelisted.', 'erp-sync' ),
                    esc_html( $ip )
                ),
                esc_html__( 'Forbidden', 'erp-sync' ),
                [ 'response' => 403 ]
            );
        }
    }

    /**
     * Get client IP address
     * Supports various proxy configurations
     */
    public static function get_client_ip(): string {
        $ip = '';
        
        // Check for proxy headers first
        if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            // Get first IP if multiple (comma-separated)
            $forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
            $ips = explode( ',', $forwarded );
            $ip = trim( $ips[0] );
        } elseif ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        
        // Validate IP address
        if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return $ip;
        }
        
        return '0.0.0.0';
    }

    /**
     * Log API call to database
     */
    private static function log_api_call( string $ip, string $endpoint ): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'erp_sync_api_logs';
        
        // Check if table exists first
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name;
        
        if ( ! $table_exists ) {
            return;
        }
        
        $wpdb->insert(
            $table_name,
            [
                'ip_address' => $ip,
                'endpoint'   => $endpoint,
                'timestamp'  => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s' ]
        );
    }

    /**
     * Clean old logs (keep last 30 days)
     */
    public static function cleanup_old_logs(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'erp_sync_api_logs';
        
        // Check if table exists
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name;
        
        if ( ! $table_exists ) {
            return;
        }
        
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table_name WHERE timestamp < %s",
            date( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
        ) );
        
        if ( $deleted ) {
            Logger::instance()->log( 'Old API logs cleaned', [ 'deleted' => $deleted ] );
        }
    }

    /**
     * Get API call statistics
     */
    public static function get_stats( int $days = 7 ): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'erp_sync_api_logs';
        
        // Check if table exists
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name;
        
        if ( ! $table_exists ) {
            return [];
        }
        
        $stats = $wpdb->get_results( $wpdb->prepare(
            "SELECT 
                DATE(timestamp) as date,
                COUNT(*) as total_calls,
                COUNT(DISTINCT ip_address) as unique_ips
            FROM $table_name 
            WHERE timestamp >= %s
            GROUP BY DATE(timestamp)
            ORDER BY date DESC",
            date( 'Y-m-d H:i:s', strtotime( "-$days days" ) )
        ) );
        
        return $stats ?: [];
    }

    /**
     * Get top IP addresses by call count
     */
    public static function get_top_ips( int $limit = 10, int $days = 7 ): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'erp_sync_api_logs';
        
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name;
        
        if ( ! $table_exists ) {
            return [];
        }
        
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT 
                ip_address,
                COUNT(*) as call_count,
                MAX(timestamp) as last_call
            FROM $table_name 
            WHERE timestamp >= %s
            GROUP BY ip_address
            ORDER BY call_count DESC
            LIMIT %d",
            date( 'Y-m-d H:i:s', strtotime( "-$days days" ) ),
            $limit
        ) );
        
        return $results ?: [];
    }
}
