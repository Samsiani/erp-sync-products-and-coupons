<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Branch Cloud
 *
 * Renders the `erp_branch` taxonomy as a tag cloud that doubles as a filter
 * for the shop / archive pages. Clicking a branch appends
 * `?filter_erp_branch=<slug>` to the current URL; clicking the active branch
 * again removes the filter. A `pre_get_posts` hook applies the filter on
 * top of any existing brand / category / attribute / layered-nav filter, so
 * filters stack rather than replace each other.
 *
 * Exposed:
 *  - Shortcode: [erp_branchs_cloud] (alias: [erp_branch_cloud])
 *  - Widget:    "ERP Sync: Branches Cloud" (registered class
 *               ERPSync\Branch_Cloud_Widget)
 *
 * @package ERPSync
 * @since 1.4.7
 */
class Branch_Cloud {

    public const TAXONOMY  = 'erp_branch';
    public const QUERY_VAR = 'filter_erp_branch';

    public static function init(): void {
        add_action( 'init',               [ __CLASS__, 'register_shortcode' ] );
        add_action( 'widgets_init',       [ __CLASS__, 'register_widget' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_styles' ] );
        add_action( 'pre_get_posts',      [ __CLASS__, 'filter_query' ] );
    }

    public static function register_shortcode(): void {
        add_shortcode( 'erp_branchs_cloud', [ __CLASS__, 'shortcode' ] );
        add_shortcode( 'erp_branch_cloud',  [ __CLASS__, 'shortcode' ] );
    }

    public static function register_widget(): void {
        register_widget( __NAMESPACE__ . '\\Branch_Cloud_Widget' );
    }

    public static function enqueue_styles(): void {
        wp_register_style( 'erp-sync-branch-cloud', false, [], ERPSYNC_VERSION );
        wp_enqueue_style( 'erp-sync-branch-cloud' );
        wp_add_inline_style( 'erp-sync-branch-cloud', self::inline_css() );
    }

    private static function inline_css(): string {
        return '
            .erp-branchs-cloud { line-height: 1.9; }
            .erp-branchs-cloud a {
                display: inline-block;
                margin: 2px 4px 2px 0;
                padding: 4px 10px;
                background: #f3f3f3;
                color: #333;
                text-decoration: none;
                border-radius: 3px;
                transition: background .15s, color .15s, border-color .15s;
                border: 1px solid transparent;
            }
            .erp-branchs-cloud a:hover,
            .erp-branchs-cloud a:focus { background: #e8e8e8; color: #000; }
            .erp-branchs-cloud a.is-active {
                background: #222;
                color: #fff;
                border-color: #222;
            }
            .erp-branchs-cloud .erp-branch-count { opacity: .65; margin-left: 4px; font-size: 0.85em; }
            .erp-branchs-cloud .erp-branch-empty { color: #888; font-style: italic; }
        ';
    }

    /**
     * Apply the `filter_erp_branch=<slug>` filter on product archive queries.
     *
     * Stacks with any existing tax_query so brand / category / attribute /
     * layered-nav filters keep working.
     */
    public static function filter_query( \WP_Query $query ): void {
        if ( is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $raw = isset( $_GET[ self::QUERY_VAR ] )
            ? sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) )
            : '';
        if ( $raw === '' ) {
            return;
        }

        // Only operate on product archives and product-related taxonomies.
        $is_product_archive =
            $query->is_post_type_archive( 'product' )
            || ( function_exists( 'is_shop' ) && is_shop() )
            || $query->is_tax( [ 'product_cat', 'product_tag', 'product_brand', 'pa_brendi', 'offers', self::TAXONOMY ] );
        if ( ! $is_product_archive ) {
            return;
        }

        $slugs = array_filter( array_map( 'sanitize_title', array_map( 'trim', explode( ',', $raw ) ) ) );
        if ( empty( $slugs ) ) {
            return;
        }

        $tax_query   = (array) $query->get( 'tax_query' );
        $tax_query[] = [
            'taxonomy' => self::TAXONOMY,
            'field'    => 'slug',
            'terms'    => array_values( $slugs ),
            'operator' => 'IN',
        ];
        $query->set( 'tax_query', $tax_query );
    }

    /**
     * Build the URL for a given branch term, toggling its active state.
     *
     * - If the branch is the only one currently selected, clicking it removes
     *   the filter (toggle off) and returns to the bare archive URL.
     * - Otherwise the branch slug becomes the new filter value (single-select).
     */
    private static function term_toggle_url( \WP_Term $term ): string {
        $base    = self::current_url_without( [ self::QUERY_VAR, 'paged' ] );
        $current = self::current_branch_slugs();
        $slug    = $term->slug;

        if ( in_array( $slug, $current, true ) ) {
            // Toggle off.
            return $base;
        }
        return add_query_arg( self::QUERY_VAR, $slug, $base );
    }

    private static function current_branch_slugs(): array {
        $raw = isset( $_GET[ self::QUERY_VAR ] )
            ? sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) )
            : '';
        if ( $raw === '' ) {
            return [];
        }
        return array_values( array_filter( array_map( 'sanitize_title', array_map( 'trim', explode( ',', $raw ) ) ) ) );
    }

    private static function current_url_without( array $remove_keys ): string {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $url         = home_url( $request_uri );
        foreach ( $remove_keys as $key ) {
            $url = remove_query_arg( $key, $url );
        }
        return $url;
    }

    /**
     * Shortcode: [erp_branchs_cloud]
     *
     * Attributes:
     *  - hide_empty: "1" (default) / "0"
     *  - orderby:    "count" (default) / "name" / "term_id"
     *  - order:      "DESC" (default) / "ASC"
     *  - number:     integer, 0 = all (default)
     *  - show_count: "1" (default) / "0"
     *  - smallest:   smallest font size (default 12)
     *  - largest:    largest font size (default 22)
     *  - unit:       "px" (default) / "em" / "rem"
     *  - title:      optional title (rendered as <h3>); empty = no title
     */
    public static function shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'hide_empty' => '1',
            'orderby'    => 'count',
            'order'      => 'DESC',
            'number'     => 0,
            'show_count' => '1',
            'smallest'   => 12,
            'largest'    => 22,
            'unit'       => 'px',
            'title'      => '',
        ], $atts, 'erp_branchs_cloud' );

        $out = '';
        if ( ! empty( $atts['title'] ) ) {
            $out .= '<h3 class="erp-branchs-cloud-title">' . esc_html( $atts['title'] ) . '</h3>';
        }
        $out .= self::render( $atts );
        return $out;
    }

    public static function render( array $atts ): string {
        if ( ! taxonomy_exists( self::TAXONOMY ) ) {
            return '';
        }

        $terms = get_terms( [
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => (bool) (int) ( $atts['hide_empty'] ?? 1 ),
            'orderby'    => sanitize_key( (string) ( $atts['orderby'] ?? 'count' ) ),
            'order'      => strtoupper( (string) ( $atts['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC',
            'number'     => (int) ( $atts['number'] ?? 0 ),
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return '<div class="erp-branchs-cloud"><span class="erp-branch-empty">' .
                esc_html__( 'No branches found.', 'erp-sync' ) .
                '</span></div>';
        }

        $show_count = (bool) (int) ( $atts['show_count'] ?? 1 );
        $smallest   = (int) ( $atts['smallest'] ?? 12 );
        $largest    = (int) ( $atts['largest']  ?? 22 );
        $unit       = preg_replace( '/[^a-z%]/', '', strtolower( (string) ( $atts['unit'] ?? 'px' ) ) ) ?: 'px';

        $counts = array_map( static function( $t ) { return max( 1, (int) $t->count ); }, $terms );
        $min    = min( $counts );
        $max    = max( $counts );
        $spread = max( 1, $max - $min );

        $active_slugs = self::current_branch_slugs();

        $out = '<div class="erp-branchs-cloud">';
        foreach ( $terms as $term ) {
            $ratio     = ( max( 1, (int) $term->count ) - $min ) / $spread;
            $font      = (int) round( $smallest + ( $largest - $smallest ) * $ratio );
            $is_active = in_array( $term->slug, $active_slugs, true );
            $url       = self::term_toggle_url( $term );

            $out .= sprintf(
                '<a href="%1$s" class="%5$s" style="font-size:%2$d%6$s" rel="nofollow"%7$s>%3$s%4$s</a>',
                esc_url( $url ),
                $font,
                esc_html( $term->name ),
                $show_count ? '<span class="erp-branch-count">(' . (int) $term->count . ')</span>' : '',
                'erp-branch-link' . ( $is_active ? ' is-active' : '' ),
                esc_attr( $unit ),
                $is_active ? ' aria-current="true"' : ''
            );
        }
        $out .= '</div>';

        return $out;
    }
}

/**
 * Sidebar widget — drop into shop sidebar (or anywhere) to render the same cloud.
 */
class Branch_Cloud_Widget extends \WP_Widget {

    public function __construct() {
        parent::__construct(
            'erpsync_branch_cloud',
            __( 'ERP Sync: Branches Cloud', 'erp-sync' ),
            [
                'description'                 => __( 'Cloud of erp_branch terms. Each branch acts as a stackable filter on the shop / archive pages.', 'erp-sync' ),
                'classname'                   => 'widget_erpsync_branch_cloud',
                'customize_selective_refresh' => true,
            ]
        );
    }

    public function widget( $args, $instance ) {
        $title = ! empty( $instance['title'] )
            ? apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base )
            : '';

        $render_args = [
            'hide_empty' => ! empty( $instance['hide_empty'] ) ? '1' : '0',
            'orderby'    => ! empty( $instance['orderby'] )    ? (string) $instance['orderby'] : 'count',
            'order'      => ! empty( $instance['order'] )      ? (string) $instance['order']   : 'DESC',
            'number'     => isset( $instance['number'] )       ? (int) $instance['number']     : 0,
            'show_count' => ! empty( $instance['show_count'] ) ? '1' : '0',
            'smallest'   => isset( $instance['smallest'] )     ? (int) $instance['smallest']   : 12,
            'largest'    => isset( $instance['largest'] )      ? (int) $instance['largest']    : 22,
            'unit'       => ! empty( $instance['unit'] )       ? (string) $instance['unit']    : 'px',
        ];

        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        if ( $title !== '' ) {
            echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore
        }
        echo Branch_Cloud::render( $render_args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped inside render()
        echo $args['after_widget']; // phpcs:ignore
    }

    public function update( $new_instance, $old_instance ) {
        $out = [];
        $out['title']      = sanitize_text_field( (string) ( $new_instance['title'] ?? '' ) );
        $out['hide_empty'] = ! empty( $new_instance['hide_empty'] ) ? 1 : 0;
        $out['orderby']    = in_array( $new_instance['orderby'] ?? '', [ 'count', 'name', 'term_id' ], true ) ? $new_instance['orderby'] : 'count';
        $out['order']      = strtoupper( (string) ( $new_instance['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';
        $out['number']     = max( 0, (int) ( $new_instance['number'] ?? 0 ) );
        $out['show_count'] = ! empty( $new_instance['show_count'] ) ? 1 : 0;
        $out['smallest']   = max( 8, min( 100, (int) ( $new_instance['smallest'] ?? 12 ) ) );
        $out['largest']    = max( 8, min( 100, (int) ( $new_instance['largest']  ?? 22 ) ) );
        $out['unit']       = in_array( $new_instance['unit'] ?? '', [ 'px', 'em', 'rem' ], true ) ? $new_instance['unit'] : 'px';
        return $out;
    }

    public function form( $instance ) {
        $title      = (string) ( $instance['title']    ?? __( 'Branches', 'erp-sync' ) );
        $hide_empty = ! empty( $instance['hide_empty'] );
        $orderby    = (string) ( $instance['orderby']  ?? 'count' );
        $order      = (string) ( $instance['order']    ?? 'DESC' );
        $number     = (int)    ( $instance['number']   ?? 0 );
        $show_count = ! isset( $instance['show_count'] ) || ! empty( $instance['show_count'] );
        $smallest   = (int)    ( $instance['smallest'] ?? 12 );
        $largest    = (int)    ( $instance['largest']  ?? 22 );
        $unit       = (string) ( $instance['unit']     ?? 'px' );
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'erp-sync' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'orderby' ) ); ?>"><?php esc_html_e( 'Order by:', 'erp-sync' ); ?></label>
            <select id="<?php echo esc_attr( $this->get_field_id( 'orderby' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'orderby' ) ); ?>" class="widefat">
                <option value="count"   <?php selected( $orderby, 'count' ); ?>>Count</option>
                <option value="name"    <?php selected( $orderby, 'name' ); ?>>Name</option>
                <option value="term_id" <?php selected( $orderby, 'term_id' ); ?>>Term ID</option>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'order' ) ); ?>"><?php esc_html_e( 'Order:', 'erp-sync' ); ?></label>
            <select id="<?php echo esc_attr( $this->get_field_id( 'order' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'order' ) ); ?>" class="widefat">
                <option value="DESC" <?php selected( $order, 'DESC' ); ?>>DESC</option>
                <option value="ASC"  <?php selected( $order, 'ASC' ); ?>>ASC</option>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>"><?php esc_html_e( 'Number of branches (0 = all):', 'erp-sync' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'number' ) ); ?>" type="number" min="0" value="<?php echo esc_attr( (string) $number ); ?>" />
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'smallest' ) ); ?>"><?php esc_html_e( 'Smallest size:', 'erp-sync' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'smallest' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'smallest' ) ); ?>" type="number" min="8" max="100" value="<?php echo esc_attr( (string) $smallest ); ?>" />
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'largest' ) ); ?>"><?php esc_html_e( 'Largest size:', 'erp-sync' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'largest' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'largest' ) ); ?>" type="number" min="8" max="100" value="<?php echo esc_attr( (string) $largest ); ?>" />
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'unit' ) ); ?>"><?php esc_html_e( 'Size unit:', 'erp-sync' ); ?></label>
            <select id="<?php echo esc_attr( $this->get_field_id( 'unit' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'unit' ) ); ?>" class="widefat">
                <option value="px"  <?php selected( $unit, 'px' );  ?>>px</option>
                <option value="em"  <?php selected( $unit, 'em' );  ?>>em</option>
                <option value="rem" <?php selected( $unit, 'rem' ); ?>>rem</option>
            </select>
        </p>
        <p>
            <input class="checkbox" type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'hide_empty' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'hide_empty' ) ); ?>" <?php checked( $hide_empty ); ?> />
            <label for="<?php echo esc_attr( $this->get_field_id( 'hide_empty' ) ); ?>"><?php esc_html_e( 'Hide branches with no products', 'erp-sync' ); ?></label>
        </p>
        <p>
            <input class="checkbox" type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_count' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_count' ) ); ?>" <?php checked( $show_count ); ?> />
            <label for="<?php echo esc_attr( $this->get_field_id( 'show_count' ) ); ?>"><?php esc_html_e( 'Show product count next to each branch', 'erp-sync' ); ?></label>
        </p>
        <?php
    }
}
