<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Audit Logger Class
 * 
 * Handles logging of product changes (stock, price) to a custom database table.
 * 
 * @package ERPSync
 * @since 1.3.0
 */
class Audit_Logger {

    /**
     * Table name without prefix.
     */
    private const TABLE_NAME = 'erp_sync_product_logs';

    /**
     * Get the full table name with WordPress prefix.
     *
     * @return string Full table name.
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Log a product change to the database.
     *
     * @param \WC_Product $product     The product being changed.
     * @param string      $change_type Type of change (e.g., 'stock', 'price', 'sale_price').
     * @param mixed       $old_value   The old value before the change.
     * @param mixed       $new_value   The new value after the change.
     * @param string      $message     Human-readable message describing the change.
     * @return bool True on success, false on failure.
     */
    public static function log_change( \WC_Product $product, string $change_type, $old_value, $new_value, string $message ): bool {
        global $wpdb;

        $table_name = self::get_table_name();

        // Check if table exists - table name is sanitized via esc_sql since it contains user-controllable prefix
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
        if ( ! $table_exists ) {
            Logger::instance()->log( 'Audit log table does not exist', [
                'table' => $table_name,
            ] );
            return false;
        }

        $product_id   = $product->get_id();
        $product_name = $product->get_name();
        $vendor_code  = $product->get_sku();

        // Serialize old/new values if they are arrays
        $old_value_str = is_array( $old_value ) ? wp_json_encode( $old_value, JSON_UNESCAPED_UNICODE ) : (string) $old_value;
        $new_value_str = is_array( $new_value ) ? wp_json_encode( $new_value, JSON_UNESCAPED_UNICODE ) : (string) $new_value;

        $result = $wpdb->insert(
            $table_name,
            [
                'product_id'   => $product_id,
                'vendor_code'  => $vendor_code,
                'product_name' => $product_name,
                'change_type'  => $change_type,
                'old_value'    => $old_value_str,
                'new_value'    => $new_value_str,
                'message'      => $message,
                'created_at'   => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( $result === false ) {
            Logger::instance()->log( 'Failed to insert audit log', [
                'product_id'  => $product_id,
                'change_type' => $change_type,
                'error'       => $wpdb->last_error,
            ] );
            return false;
        }

        return true;
    }

    /**
     * Get logs with optional filters.
     *
     * @param array $args Query arguments (month, year, search, per_page, offset, orderby, order).
     * @return array Array of log records.
     */
    public static function get_logs( array $args = [] ): array {
        global $wpdb;

        $defaults = [
            'month'    => 0,
            'year'     => 0,
            'search'   => '',
            'per_page' => 20,
            'offset'   => 0,
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        ];

        $args = wp_parse_args( $args, $defaults );
        $table_name = self::get_table_name();

        // Build WHERE clauses
        $where_clauses = [];
        $where_values  = [];

        // Date filter
        if ( $args['year'] > 0 && $args['month'] > 0 ) {
            $where_clauses[] = 'YEAR(created_at) = %d AND MONTH(created_at) = %d';
            $where_values[]  = $args['year'];
            $where_values[]  = $args['month'];
        } elseif ( $args['year'] > 0 ) {
            $where_clauses[] = 'YEAR(created_at) = %d';
            $where_values[]  = $args['year'];
        }

        // Search filter (by product name or vendor code)
        if ( ! empty( $args['search'] ) ) {
            $search_term     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where_clauses[] = '(product_name LIKE %s OR vendor_code LIKE %s)';
            $where_values[]  = $search_term;
            $where_values[]  = $search_term;
        }

        // Build SQL
        $sql = "SELECT * FROM $table_name";

        if ( ! empty( $where_clauses ) ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where_clauses );
        }

        // Sanitize orderby and order
        $allowed_orderby = [ 'id', 'product_name', 'vendor_code', 'created_at', 'change_type' ];
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $sql .= " ORDER BY $orderby $order";
        $sql .= ' LIMIT %d OFFSET %d';

        $where_values[] = (int) $args['per_page'];
        $where_values[] = (int) $args['offset'];

        // Execute query
        if ( ! empty( $where_values ) ) {
            $prepared = $wpdb->prepare( $sql, $where_values );
        } else {
            $prepared = $sql;
        }

        $results = $wpdb->get_results( $prepared, ARRAY_A );

        return $results ?: [];
    }

    /**
     * Get total count of logs with optional filters.
     *
     * @param array $args Query arguments (month, year, search).
     * @return int Total count.
     */
    public static function get_logs_count( array $args = [] ): int {
        global $wpdb;

        $defaults = [
            'month'  => 0,
            'year'   => 0,
            'search' => '',
        ];

        $args = wp_parse_args( $args, $defaults );
        $table_name = self::get_table_name();

        // Build WHERE clauses
        $where_clauses = [];
        $where_values  = [];

        // Date filter
        if ( $args['year'] > 0 && $args['month'] > 0 ) {
            $where_clauses[] = 'YEAR(created_at) = %d AND MONTH(created_at) = %d';
            $where_values[]  = $args['year'];
            $where_values[]  = $args['month'];
        } elseif ( $args['year'] > 0 ) {
            $where_clauses[] = 'YEAR(created_at) = %d';
            $where_values[]  = $args['year'];
        }

        // Search filter
        if ( ! empty( $args['search'] ) ) {
            $search_term     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where_clauses[] = '(product_name LIKE %s OR vendor_code LIKE %s)';
            $where_values[]  = $search_term;
            $where_values[]  = $search_term;
        }

        // Build SQL
        $sql = "SELECT COUNT(*) FROM $table_name";

        if ( ! empty( $where_clauses ) ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where_clauses );
        }

        // Execute query
        if ( ! empty( $where_values ) ) {
            $count = $wpdb->get_var( $wpdb->prepare( $sql, $where_values ) );
        } else {
            $count = $wpdb->get_var( $sql );
        }

        return (int) $count;
    }

    /**
     * Get available months/years for filtering.
     *
     * @return array Array of available date options.
     */
    public static function get_available_dates(): array {
        global $wpdb;

        $table_name = self::get_table_name();

        $results = $wpdb->get_results(
            "SELECT DISTINCT YEAR(created_at) as year, MONTH(created_at) as month 
             FROM $table_name 
             ORDER BY year DESC, month DESC",
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Clear all logs from the database.
     *
     * @return bool True on success, false on failure.
     */
    public static function clear_all_logs(): bool {
        global $wpdb;

        $table_name = self::get_table_name();

        // Check if table exists - use prepared statement for safety
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
        if ( ! $table_exists ) {
            return false;
        }

        // Note: Table name cannot be parameterized in prepared statements,
        // but it's safely constructed from $wpdb->prefix which is trusted
        $result = $wpdb->query( "TRUNCATE TABLE `$table_name`" );

        if ( $result === false ) {
            Logger::instance()->log( 'Failed to clear all product audit logs', [
                'error' => $wpdb->last_error,
            ] );
            return false;
        }

        Logger::instance()->log( 'All product audit logs cleared' );
        return true;
    }

    /**
     * Clean old logs (keep last N days).
     *
     * @param int $days Number of days to keep. Default 90.
     * @return int Number of deleted records.
     */
    public static function cleanup_old_logs( int $days = 90 ): int {
        global $wpdb;

        $table_name = self::get_table_name();

        // Check if table exists - use prepared statement for safety
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
        if ( ! $table_exists ) {
            return 0;
        }

        // Note: Table name cannot be parameterized in prepared statements,
        // but it's safely constructed from $wpdb->prefix which is trusted
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM `$table_name` WHERE created_at < %s",
            gmdate( 'Y-m-d H:i:s', strtotime( "-$days days" ) )
        ) );

        if ( $deleted ) {
            Logger::instance()->log( 'Old product audit logs cleaned', [ 'deleted' => $deleted ] );
        }

        return (int) $deleted;
    }
}
