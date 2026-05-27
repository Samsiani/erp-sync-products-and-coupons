<?php
declare(strict_types=1);

namespace ERPSync;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Branch Cloud
 *
 * Renders the `erp_branch` taxonomy as a tag cloud that also acts as a
 * stackable filter for the shop / archive pages.
 *
 * - Output matches WP's `wp_tag_cloud()` (uses `wp_generate_tag_cloud()`),
 *   so the theme's existing tag-cloud styles apply automatically and the
 *   markup is indistinguishable from the standard product-tag widget.
 * - Adaptive: on a filtered archive (category, brand, layered-nav, etc.)
 *   the cloud only includes branches that have at least one product in
 *   that filtered set. Empty branches in the current context are hidden.
 * - Clicking a branch appends `?filter_erp_branch=<slug>` to the current
 *   URL; clicking the active branch toggles the filter off.
 * - `pre_get_posts` stacks the branch tax_query on top of any existing
 *   brand / category / attribute / layered-nav filter.
 *
 * Exposed:
 *  - Shortcode: [erp_branchs_cloud] (alias: [erp_branch_cloud])
 *  - Widget:    "ERP Sync: Branches Cloud" (class
 *               ERPSync\Branch_Cloud_Widget)
 *
 * @package ERPSync
 * @since 1.4.7
 */
class Branch_Cloud {

    public const TAXONOMY  = 'erp_branch';
    public const QUERY_VAR = 'filter_erp_branch';

    /** Per-request memoization of `get_available_terms()` */
    private static array $term_cache = [];

    public static function init(): void {
        add_action( 'init',          [ __CLASS__, 'register_shortcode' ] );
        add_action( 'widgets_init',  [ __CLASS__, 'register_widget' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'filter_query' ] );

        // WPML String Translation: register the default title so it appears
        // in WPML → String Translation under the "erp-sync" domain. A no-op
        // when WPML String Translation isn't active.
        add_action( 'init', [ __CLASS__, 'register_wpml_strings' ], 20 );
    }

    public static function register_wpml_strings(): void {
        if ( ! function_exists( 'icl_register_string' ) && ! has_action( 'wpml_register_single_string' ) ) {
            return;
        }
        $strings = [
            'Branch cloud default title' => __( 'Branches', 'erp-sync' ),
        ];
        foreach ( $strings as $name => $value ) {
            if ( has_action( 'wpml_register_single_string' ) ) {
                do_action( 'wpml_register_single_string', 'erp-sync', $name, $value );
            } elseif ( function_exists( 'icl_register_string' ) ) {
                icl_register_string( 'erp-sync', $name, $value );
            }
        }
    }

    /** Translated default title (uses WPML's translation when available). */
    private static function default_title(): string {
        $title = __( 'Branches', 'erp-sync' );
        if ( has_filter( 'wpml_translate_single_string' ) ) {
            $title = (string) apply_filters( 'wpml_translate_single_string', $title, 'erp-sync', 'Branch cloud default title' );
        }
        return $title;
    }

    /** Public accessor so the sidebar widget can fall back to the same default. */
    public static function default_title_public(): string {
        return self::default_title();
    }

    public static function register_shortcode(): void {
        add_shortcode( 'erp_branchs_cloud', [ __CLASS__, 'shortcode' ] );
        add_shortcode( 'erp_branch_cloud',  [ __CLASS__, 'shortcode' ] );
    }

    public static function register_widget(): void {
        register_widget( __NAMESPACE__ . '\\Branch_Cloud_Widget' );
    }

    // ------------------------------------------------------------------
    //  pre_get_posts — apply ?filter_erp_branch=<slug>
    // ------------------------------------------------------------------

    public static function filter_query( \WP_Query $query ): void {
        if ( is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $slugs = self::current_branch_slugs();
        if ( empty( $slugs ) ) {
            return;
        }

        $is_product_archive =
            $query->is_post_type_archive( 'product' )
            || ( function_exists( 'is_shop' ) && is_shop() )
            || $query->is_tax( [ 'product_cat', 'product_tag', 'product_brand', 'pa_brendi', 'offers', self::TAXONOMY ] );
        if ( ! $is_product_archive ) {
            return;
        }

        $tax_query   = (array) $query->get( 'tax_query' );
        $tax_query[] = [
            'taxonomy' => self::TAXONOMY,
            'field'    => 'slug',
            'terms'    => $slugs,
            'operator' => 'IN',
        ];
        $query->set( 'tax_query', $tax_query );
    }

    // ------------------------------------------------------------------
    //  URL helpers
    // ------------------------------------------------------------------

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
     * Toggle URL for one branch term: removes the filter if this slug is
     * currently the active one, otherwise sets it.
     */
    private static function term_toggle_url( \WP_Term $term ): string {
        $base    = self::current_url_without( [ self::QUERY_VAR, 'paged' ] );
        $current = self::current_branch_slugs();
        if ( in_array( $term->slug, $current, true ) ) {
            return $base;
        }
        return add_query_arg( self::QUERY_VAR, $term->slug, $base );
    }

    // ------------------------------------------------------------------
    //  Adaptive term lookup
    // ------------------------------------------------------------------

    /**
     * True when the current archive has any filter applied that should
     * narrow the visible branch set. Plain `/shop/` with no GET params and
     * non-archive pages return false; in that case the global term counts
     * (cached on wp_term_taxonomy.count) are used.
     */
    private static function is_filtered_archive(): bool {
        if ( ! ( ( function_exists( 'is_shop' ) && is_shop() )
                 || is_post_type_archive( 'product' )
                 || is_tax( [ 'product_cat', 'product_tag', 'product_brand', 'pa_brendi', 'offers', self::TAXONOMY ] ) ) ) {
            return false;
        }

        // Any taxonomy archive narrows the product set.
        if ( is_tax( [ 'product_cat', 'product_tag', 'product_brand', 'pa_brendi', 'offers', self::TAXONOMY ] ) ) {
            return true;
        }

        // Layered-nav / WC filter GET params imply a narrowed view.
        foreach ( array_keys( $_GET ) as $key ) {
            $k = (string) $key;
            if ( $k === 'filter_erp_branch' ) {
                // Don't let the cloud's own filter make the term set empty —
                // we still want every available branch to be clickable.
                continue;
            }
            if ( strpos( $k, 'filter_' )    === 0 ) return true;
            if ( strpos( $k, 'query_type_' ) === 0 ) return true;
            if ( in_array( $k, [ 'min_price', 'max_price', 'rating_filter', 'product_stock_status', 'on_sale', 's' ], true ) ) return true;
        }
        return false;
    }

    /**
     * Return the term set that should appear in the cloud right now.
     *
     * On a filtered archive: only terms that have ≥1 product matching the
     * current archive constraints (excluding the cloud's own filter, so the
     * user can switch branches). Counts on the returned terms reflect the
     * filtered set, so font-size weighting matches what the visitor sees.
     *
     * On a plain (unfiltered) context: standard `get_terms()` with
     * `hide_empty => true`.
     */
    private static function get_available_terms( array $atts ): array {
        global $wp_query, $wpdb;

        $orderby = sanitize_key( (string) ( $atts['orderby'] ?? 'count' ) );
        $order   = strtoupper( (string) ( $atts['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';
        $number  = max( 0, (int) ( $atts['number'] ?? 0 ) );

        $filtered = self::is_filtered_archive();

        $cache_key = md5( serialize( [
            'filtered' => $filtered,
            'orderby'  => $orderby,
            'order'    => $order,
            'number'   => $number,
            'qv'       => $filtered && $wp_query ? $wp_query->query_vars : null,
            'get'      => $filtered ? $_GET : null,
        ] ) );

        if ( isset( self::$term_cache[ $cache_key ] ) ) {
            return self::$term_cache[ $cache_key ];
        }

        if ( ! $filtered ) {
            $terms = get_terms( [
                'taxonomy'   => self::TAXONOMY,
                'hide_empty' => true,
                'orderby'    => $orderby,
                'order'      => $order,
                'number'     => $number,
            ] );
            $terms = is_wp_error( $terms ) ? [] : $terms;
            self::$term_cache[ $cache_key ] = $terms;
            return $terms;
        }

        // Filtered archive — compute branch counts within the visible set.
        $ids = self::get_filtered_product_ids();
        if ( empty( $ids ) ) {
            self::$term_cache[ $cache_key ] = [];
            return [];
        }

        $ids_in = implode( ',', array_map( 'intval', $ids ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — $ids_in is a verified int list.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.term_id, t.name, t.slug, COUNT(DISTINCT tr.object_id) AS count
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt
                 ON tt.term_id = t.term_id AND tt.taxonomy = %s
             INNER JOIN {$wpdb->term_relationships} tr
                 ON tr.term_taxonomy_id = tt.term_taxonomy_id
             WHERE tr.object_id IN ({$ids_in})
             GROUP BY t.term_id, t.name, t.slug
             HAVING COUNT(DISTINCT tr.object_id) > 0",
            self::TAXONOMY
        ) );

        $terms = [];
        foreach ( (array) $rows as $r ) {
            $terms[] = (object) [
                'term_id'  => (int) $r->term_id,
                'id'       => (int) $r->term_id,
                'name'     => (string) $r->name,
                'slug'     => (string) $r->slug,
                'count'    => (int) $r->count,
                'taxonomy' => self::TAXONOMY,
            ];
        }

        // Sort + limit in PHP (term_id list is small).
        usort( $terms, static function( $a, $b ) use ( $orderby, $order ) {
            $cmp = match ( $orderby ) {
                'name'    => strnatcasecmp( $a->name, $b->name ),
                'term_id' => $a->term_id <=> $b->term_id,
                default   => $a->count   <=> $b->count, // 'count'
            };
            return $order === 'ASC' ? $cmp : -$cmp;
        } );
        if ( $number > 0 ) {
            $terms = array_slice( $terms, 0, $number );
        }

        self::$term_cache[ $cache_key ] = $terms;
        return $terms;
    }

    /**
     * Product IDs matching the current archive (without erp_branch, so the
     * cloud doesn't filter itself out). Uses the resolved query vars from
     * the global $wp_query — every other filter (layered nav, price, WC
     * visibility, brand/category) has already populated them.
     */
    private static function get_filtered_product_ids(): array {
        global $wp_query;
        if ( ! $wp_query ) {
            return [];
        }

        $args = (array) $wp_query->query_vars;
        $args['post_type']              = 'product';
        $args['post_status']            = 'publish';
        $args['posts_per_page']         = -1;
        $args['paged']                  = 1;
        $args['fields']                 = 'ids';
        $args['no_found_rows']          = true;
        $args['ignore_sticky_posts']    = true;
        $args['orderby']                = 'ID';
        $args['order']                  = 'ASC';
        $args['update_post_meta_cache'] = false;
        $args['update_post_term_cache'] = false;
        unset( $args['offset'], $args['page'], $args['nopaging'] );

        // Drop erp_branch from tax_query so every available branch can still appear.
        if ( ! empty( $args['tax_query'] ) ) {
            $args['tax_query'] = array_values( array_filter( (array) $args['tax_query'], static function( $c ) {
                return ! is_array( $c ) || ( $c['taxonomy'] ?? '' ) !== self::TAXONOMY;
            } ) );
        }

        $sub = new \WP_Query( $args );
        return $sub->posts ?: [];
    }

    // ------------------------------------------------------------------
    //  Render
    // ------------------------------------------------------------------

    /**
     * Shortcode: [erp_branchs_cloud]
     *
     * Attributes (all optional):
     *  - smallest   smallest font size (default 8)
     *  - largest    largest font size  (default 22)
     *  - unit       size unit          (default pt; px / em / rem also accepted)
     *  - number     max branches       (0 = all, default 0)
     *  - orderby    "count" (default) / "name" / "term_id"
     *  - order      "DESC" (default) / "ASC"
     *  - title      optional heading rendered above the cloud
     */
    public static function shortcode( $atts ): string {
        // Accept user-supplied attrs first; fall back to translated default
        // for title so visitors see the localized version.
        $atts = shortcode_atts( [
            'smallest' => 8,
            'largest'  => 22,
            'unit'     => 'pt',
            'number'   => 0,
            'orderby'  => 'count',
            'order'    => 'DESC',
            'title'    => '__default__',
            'show_title' => '1',
        ], $atts, 'erp_branchs_cloud' );

        if ( $atts['title'] === '__default__' ) {
            $atts['title'] = self::default_title();
        }
        $show_title = (bool) (int) $atts['show_title'];

        $out = '';
        if ( $show_title && $atts['title'] !== '' ) {
            $out .= '<h3 class="erp-branchs-cloud-title widget-title widgettitle">' . esc_html( $atts['title'] ) . '</h3>';
        }
        $out .= self::render( $atts );
        return $out;
    }

    public static function render( array $atts ): string {
        if ( ! taxonomy_exists( self::TAXONOMY ) ) {
            return '';
        }

        $terms = self::get_available_terms( $atts );
        if ( empty( $terms ) ) {
            return '';
        }

        // Override each term's link to point at our toggle URL.
        foreach ( $terms as $term ) {
            // wp_generate_tag_cloud reads $tag->link
            $term->link = self::term_toggle_url( $term );
            if ( ! isset( $term->id ) ) {
                $term->id = $term->term_id;
            }
        }

        $smallest = max( 1, (int) ( $atts['smallest'] ?? 8 ) );
        $largest  = max( $smallest, (int) ( $atts['largest'] ?? 22 ) );
        $unit     = (string) ( $atts['unit'] ?? 'pt' );
        $orderby  = sanitize_key( (string) ( $atts['orderby'] ?? 'count' ) );
        $order    = strtoupper( (string) ( $atts['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';

        $cloud = wp_generate_tag_cloud( $terms, [
            'smallest'   => $smallest,
            'largest'    => $largest,
            'unit'       => $unit,
            'number'     => 0, // already limited in get_available_terms
            'format'     => 'flat',
            'separator'  => "\n",
            'orderby'    => $orderby,
            'order'      => $order,
            'show_count' => 0, // no visible (N)
            'topic_count_text_callback' => static function ( $count ) {
                // Use WooCommerce's text domain so the aria-label inherits
                // WC's existing "%s product"/"%s products" translations
                // (e.g. ka_GE → "%s პროდუქტი") without us shipping a .po.
                return sprintf( _n( '%s product', '%s products', $count, 'woocommerce' ), number_format_i18n( $count ) );
            },
            'echo' => false,
        ] );

        if ( empty( $cloud ) ) {
            return '';
        }

        return '<div class="tagcloud erp-branchs-cloud">' . $cloud . '</div>';
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
                'description'                 => __( 'Tag cloud of erp_branch terms; doubles as a stackable shop / archive filter. Only branches present in the current view are shown.', 'erp-sync' ),
                'classname'                   => 'widget_erpsync_branch_cloud widget_tag_cloud',
                'customize_selective_refresh' => true,
            ]
        );
    }

    public function widget( $args, $instance ) {
        $stored_title = isset( $instance['title'] ) ? (string) $instance['title'] : '';
        if ( $stored_title === '' ) {
            $stored_title = Branch_Cloud::default_title_public();
        }
        $title = apply_filters( 'widget_title', $stored_title, $instance, $this->id_base );

        $render_args = [
            'smallest' => isset( $instance['smallest'] ) ? (int) $instance['smallest'] : 8,
            'largest'  => isset( $instance['largest'] )  ? (int) $instance['largest']  : 22,
            'unit'     => ! empty( $instance['unit'] )   ? (string) $instance['unit']  : 'pt',
            'number'   => isset( $instance['number'] )   ? (int) $instance['number']   : 0,
            'orderby'  => ! empty( $instance['orderby'] )? (string) $instance['orderby']: 'count',
            'order'    => ! empty( $instance['order'] )  ? (string) $instance['order']  : 'DESC',
        ];

        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        if ( $title !== '' ) {
            echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore
        }
        echo Branch_Cloud::render( $render_args ); // phpcs:ignore — escaped inside render()
        echo $args['after_widget']; // phpcs:ignore
    }

    public function update( $new_instance, $old_instance ) {
        $out = [];
        $out['title']    = sanitize_text_field( (string) ( $new_instance['title'] ?? '' ) );
        $out['orderby']  = in_array( $new_instance['orderby'] ?? '', [ 'count', 'name', 'term_id' ], true ) ? $new_instance['orderby'] : 'count';
        $out['order']    = strtoupper( (string) ( $new_instance['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';
        $out['number']   = max( 0, (int) ( $new_instance['number'] ?? 0 ) );
        $out['smallest'] = max( 1, min( 100, (int) ( $new_instance['smallest'] ?? 8 ) ) );
        $out['largest']  = max( 1, min( 100, (int) ( $new_instance['largest']  ?? 22 ) ) );
        $out['unit']     = in_array( $new_instance['unit'] ?? '', [ 'pt', 'px', 'em', 'rem' ], true ) ? $new_instance['unit'] : 'pt';
        return $out;
    }

    public function form( $instance ) {
        $title    = (string) ( $instance['title']    ?? __( 'Branches', 'erp-sync' ) );
        $orderby  = (string) ( $instance['orderby']  ?? 'count' );
        $order    = (string) ( $instance['order']    ?? 'DESC' );
        $number   = (int)    ( $instance['number']   ?? 0 );
        $smallest = (int)    ( $instance['smallest'] ?? 8 );
        $largest  = (int)    ( $instance['largest']  ?? 22 );
        $unit     = (string) ( $instance['unit']     ?? 'pt' );
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
            <label for="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>"><?php esc_html_e( 'Max branches (0 = all):', 'erp-sync' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'number' ) ); ?>" type="number" min="0" value="<?php echo esc_attr( (string) $number ); ?>" />
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'smallest' ) ); ?>"><?php esc_html_e( 'Smallest size:', 'erp-sync' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'smallest' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'smallest' ) ); ?>" type="number" min="1" max="100" value="<?php echo esc_attr( (string) $smallest ); ?>" />
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'largest' ) ); ?>"><?php esc_html_e( 'Largest size:', 'erp-sync' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'largest' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'largest' ) ); ?>" type="number" min="1" max="100" value="<?php echo esc_attr( (string) $largest ); ?>" />
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'unit' ) ); ?>"><?php esc_html_e( 'Size unit:', 'erp-sync' ); ?></label>
            <select id="<?php echo esc_attr( $this->get_field_id( 'unit' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'unit' ) ); ?>" class="widefat">
                <option value="pt"  <?php selected( $unit, 'pt' );  ?>>pt</option>
                <option value="px"  <?php selected( $unit, 'px' );  ?>>px</option>
                <option value="em"  <?php selected( $unit, 'em' );  ?>>em</option>
                <option value="rem" <?php selected( $unit, 'rem' ); ?>>rem</option>
            </select>
        </p>
        <?php
    }
}
