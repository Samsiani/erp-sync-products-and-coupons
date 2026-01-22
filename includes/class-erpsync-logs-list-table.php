<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

// Ensure WP_List_Table is available
if ( ! class_exists( '\\WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Logs List Table Class
 * 
 * Extends WP_List_Table to display product audit logs with filtering and search.
 * 
 * @package ERPSync
 * @since 1.3.0
 */
class Logs_List_Table extends \WP_List_Table {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct( [
            'singular' => __( 'Product Log', 'erp-sync' ),
            'plural'   => __( 'Product Logs', 'erp-sync' ),
            'ajax'     => false,
        ] );
    }

    /**
     * Define table columns.
     *
     * @return array Column definitions.
     */
    public function get_columns(): array {
        return [
            'product_name' => __( 'Product Name', 'erp-sync' ),
            'vendor_code'  => __( 'VendorCode', 'erp-sync' ),
            'message'      => __( 'Change Details', 'erp-sync' ),
            'created_at'   => __( 'Date', 'erp-sync' ),
        ];
    }

    /**
     * Define sortable columns.
     *
     * @return array Sortable column definitions.
     */
    public function get_sortable_columns(): array {
        return [
            'product_name' => [ 'product_name', false ],
            'vendor_code'  => [ 'vendor_code', false ],
            'created_at'   => [ 'created_at', true ], // Default sort
        ];
    }

    /**
     * Default column rendering.
     *
     * @param array  $item        Row data.
     * @param string $column_name Column name.
     * @return string Column content.
     */
    protected function column_default( $item, $column_name ): string {
        switch ( $column_name ) {
            case 'product_name':
                $product_id = (int) ( $item['product_id'] ?? 0 );
                $name       = esc_html( $item['product_name'] ?? '' );
                
                if ( $product_id > 0 ) {
                    $edit_url = get_edit_post_link( $product_id );
                    if ( $edit_url ) {
                        return sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), $name );
                    }
                }
                return $name;

            case 'vendor_code':
                return esc_html( $item['vendor_code'] ?? '' );

            case 'message':
                return esc_html( $item['message'] ?? '' );

            case 'created_at':
                $created_at = $item['created_at'] ?? '';
                if ( ! empty( $created_at ) ) {
                    $timestamp = strtotime( $created_at );
                    return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) );
                }
                return '';

            default:
                return print_r( $item, true );
        }
    }

    /**
     * Message to show when no items found.
     */
    public function no_items(): void {
        esc_html_e( 'No product logs found.', 'erp-sync' );
    }

    /**
     * Prepare items for display.
     */
    public function prepare_items(): void {
        $per_page = 20;
        $current_page = $this->get_pagenum();

        // Get filter values
        $search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        $filter_date = isset( $_REQUEST['filter_date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['filter_date'] ) ) : '';

        // Parse date filter
        $year  = 0;
        $month = 0;
        if ( ! empty( $filter_date ) && strpos( $filter_date, '-' ) !== false ) {
            list( $year, $month ) = explode( '-', $filter_date );
            $year  = (int) $year;
            $month = (int) $month;
        }

        // Get sorting
        $orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'created_at';
        $order   = isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'DESC';

        // Build query args
        $args = [
            'year'     => $year,
            'month'    => $month,
            'search'   => $search,
            'per_page' => $per_page,
            'offset'   => ( $current_page - 1 ) * $per_page,
            'orderby'  => $orderby,
            'order'    => $order,
        ];

        // Get data
        $this->items = Audit_Logger::get_logs( $args );
        $total_items = Audit_Logger::get_logs_count( $args );

        // Set column headers
        $this->_column_headers = [
            $this->get_columns(),
            [], // Hidden columns
            $this->get_sortable_columns(),
        ];

        // Set pagination
        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ] );
    }

    /**
     * Display search box and filters.
     *
     * @param string $text     Button text.
     * @param string $input_id Input ID.
     */
    public function search_box( $text, $input_id ): void {
        // Get available dates for filter
        $available_dates = Audit_Logger::get_available_dates();
        $current_filter  = isset( $_REQUEST['filter_date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['filter_date'] ) ) : '';
        $search_value    = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

        ?>
        <p class="search-box">
            <!-- Date Filter -->
            <label for="filter_date" class="screen-reader-text"><?php esc_html_e( 'Filter by date', 'erp-sync' ); ?></label>
            <select name="filter_date" id="filter_date">
                <option value=""><?php esc_html_e( 'All Dates', 'erp-sync' ); ?></option>
                <?php
                foreach ( $available_dates as $date ) {
                    $value = sprintf( '%04d-%02d', $date['year'], $date['month'] );
                    $label = wp_date( 'F Y', mktime( 0, 0, 0, (int) $date['month'], 1, (int) $date['year'] ) );
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr( $value ),
                        selected( $current_filter, $value, false ),
                        esc_html( $label )
                    );
                }
                ?>
            </select>

            <!-- Search Box -->
            <label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $text ); ?>:</label>
            <input type="search" id="<?php echo esc_attr( $input_id ); ?>" name="s" value="<?php echo esc_attr( $search_value ); ?>" placeholder="<?php esc_attr_e( 'Search by Name or VendorCode', 'erp-sync' ); ?>" />
            <?php submit_button( $text, '', '', false, [ 'id' => 'search-submit' ] ); ?>
        </p>
        <?php
    }

    /**
     * Extra table navigation (filters).
     *
     * @param string $which Position (top or bottom).
     */
    protected function extra_tablenav( $which ): void {
        if ( $which !== 'top' ) {
            return;
        }
        // Additional filters can be added here if needed in the future
    }

    /**
     * Render the table.
     */
    public function display(): void {
        $this->search_box( __( 'Search', 'erp-sync' ), 'erp-sync-logs-search' );
        parent::display();
    }
}
