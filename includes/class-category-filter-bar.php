<?php
/**
 * Category Filter Bar
 *
 * Organiser and location filters for the upcoming events dual loop
 * on event-category taxonomy archive pages (/event-category/{slug}/).
 *
 * Shortcodes:
 *   [spk_cat_filter_organiser]  — organiser <select>  (conditional on loc)
 *   [spk_cat_filter_location]   — location <select>   (conditional on org)
 *   [spk_cat_filter_pills]      — active filter pills
 *   [spk_cat_filter_clear]      — clear filters link (hidden when no filters active)
 *
 * URL params: ?org=ID&loc=ID
 * These are read by bl_get_event_month_data() on category context pages,
 * so the dual loop filters automatically when these params are present.
 *
 * Count shortcodes:
 *   [spk_cat_upcoming]  — upcoming event count for current category
 *   [spk_cat_past]      — past event count for current category
 *   [spk_cat_total]     — total event count for current category
 *
 * Dynamic tag:
 *   {spk_cat_event_count} — upcoming count, use in Bricks conditions
 *
 * Reuses past-filter-bar.css / past-filter-bar.js.
 * BEM block: .spk-filter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPK_Category_Filter_Bar {

    const CACHE_EXPIRY = 43200; // 12h

    private static $state = null;

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function register() {
        add_shortcode( 'spk_cat_filter_organiser', array( __CLASS__, 'sc_organiser' ) );
        add_shortcode( 'spk_cat_filter_location',  array( __CLASS__, 'sc_location'  ) );
        add_shortcode( 'spk_cat_filter_pills',     array( __CLASS__, 'sc_pills'     ) );
        add_shortcode( 'spk_cat_filter_clear',     array( __CLASS__, 'sc_clear'     ) );
        add_shortcode( 'spk_cat_upcoming',         array( __CLASS__, 'sc_upcoming'  ) );
        add_shortcode( 'spk_cat_past',             array( __CLASS__, 'sc_past'      ) );
        add_shortcode( 'spk_cat_total',            array( __CLASS__, 'sc_total'     ) );
    }

    // -------------------------------------------------------------------------
    // Shared state — parsed once per request
    // -------------------------------------------------------------------------

    private static function state() {
        if ( self::$state !== null ) {
            return self::$state;
        }

        $term = self::get_term();
        if ( ! $term ) {
            self::$state = false;
            return false;
        }

        $pool_ids = self::get_upcoming_ids( $term->slug );

        $active_org = isset( $_GET['org'] ) ? absint( $_GET['org'] )        : 0;
        $active_loc = isset( $_GET['loc'] ) ? absint( $_GET['loc'] )        : 0;

        // Validate — silently drop stale params
        if ( $active_org ) {
            $org_opts = self::get_organisers( $pool_ids, $active_loc );
            if ( ! isset( $org_opts[ $active_org ] ) ) $active_org = 0;
        }
        if ( $active_loc ) {
            $loc_opts = self::get_locations( $pool_ids, $active_org );
            if ( ! isset( $loc_opts[ $active_loc ] ) ) $active_loc = 0;
        }

        $base_url = get_term_link( $term );
        if ( is_wp_error( $base_url ) ) $base_url = '';

        self::$state = compact( 'term', 'pool_ids', 'active_org', 'active_loc', 'base_url' );
        return self::$state;
    }

    // ── Term parser ───────────────────────────────────────────────────────────

    private static function get_term() {
        $path      = trim( parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
        $parts     = explode( '/', $path );
        $cat_index = array_search( 'event-category', $parts );

        if ( $cat_index !== false && isset( $parts[ $cat_index + 1 ] ) ) {
            $term = get_term_by( 'slug', sanitize_title( $parts[ $cat_index + 1 ] ), 'event-category' );
            if ( $term && ! is_wp_error( $term ) ) {
                return $term;
            }
        }

        return null;
    }

    // ── Enqueue assets ────────────────────────────────────────────────────────

    private static function enqueue() {
        wp_enqueue_style(
            'spk-past-filter-bar',
            SNOPEAK_EVENTS_URL . 'assets/past-filter-bar.css',
            array(),
            SNOPEAK_EVENTS_VERSION
        );
        wp_enqueue_script(
            'spk-past-filter-bar',
            SNOPEAK_EVENTS_URL . 'assets/past-filter-bar.js',
            array(),
            SNOPEAK_EVENTS_VERSION,
            true
        );
    }

    private static function extra_class( $atts ) {
        $atts = shortcode_atts( array( 'class' => '' ), $atts );
        return $atts['class'] ? ' ' . esc_attr( trim( $atts['class'] ) ) : '';
    }

    // -------------------------------------------------------------------------
    // [spk_cat_filter_organiser]
    // -------------------------------------------------------------------------

    public static function sc_organiser( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        $extra = self::extra_class( $atts );
        $opts  = self::get_organisers( $s['pool_ids'], $s['active_loc'] );
        if ( empty( $opts ) ) return '';

        $active_cls = $s['active_org'] ? ' spk-filter__select--active' : '';
        $none_url   = self::build_url( $s['base_url'], 0, $s['active_loc'] );

        $html  = '<select class="spk-filter__select' . $active_cls . $extra . '" aria-label="' . esc_attr__( 'Filter by organiser', 'snopeak-events' ) . '" data-spk-select>';
        $html .= '<option value="' . esc_url( $none_url ) . '">' . esc_html__( 'All Organisers', 'snopeak-events' ) . '</option>';
        foreach ( $opts as $id => $name ) {
            $url   = self::build_url( $s['base_url'], $id, $s['active_loc'] );
            $html .= '<option value="' . esc_url( $url ) . '"' . selected( $id, $s['active_org'], false ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    // -------------------------------------------------------------------------
    // [spk_cat_filter_location]
    // -------------------------------------------------------------------------

    public static function sc_location( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        $extra = self::extra_class( $atts );
        $opts  = self::get_locations( $s['pool_ids'], $s['active_org'] );
        if ( empty( $opts ) ) return '';

        $active_cls = $s['active_loc'] ? ' spk-filter__select--active' : '';
        $none_url   = self::build_url( $s['base_url'], $s['active_org'], 0 );

        $html  = '<select class="spk-filter__select' . $active_cls . $extra . '" aria-label="' . esc_attr__( 'Filter by location', 'snopeak-events' ) . '" data-spk-select>';
        $html .= '<option value="' . esc_url( $none_url ) . '">' . esc_html__( 'All Locations', 'snopeak-events' ) . '</option>';
        foreach ( $opts as $id => $name ) {
            $url   = self::build_url( $s['base_url'], $s['active_org'], $id );
            $html .= '<option value="' . esc_url( $url ) . '"' . selected( $id, $s['active_loc'], false ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    // -------------------------------------------------------------------------
    // [spk_cat_filter_pills]
    // -------------------------------------------------------------------------

    public static function sc_pills( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        $extra    = self::extra_class( $atts );
        $org_opts = self::get_organisers( $s['pool_ids'], $s['active_loc'] );
        $loc_opts = self::get_locations(  $s['pool_ids'], $s['active_org'] );

        $pills = array();

        if ( $s['active_org'] && isset( $org_opts[ $s['active_org'] ] ) ) {
            $pills[] = array(
                'label'      => $org_opts[ $s['active_org'] ],
                'remove_url' => self::build_url( $s['base_url'], 0, $s['active_loc'] ),
            );
        }
        if ( $s['active_loc'] && isset( $loc_opts[ $s['active_loc'] ] ) ) {
            $pills[] = array(
                'label'      => $loc_opts[ $s['active_loc'] ],
                'remove_url' => self::build_url( $s['base_url'], $s['active_org'], 0 ),
            );
        }

        if ( empty( $pills ) ) return '';

        $html = '';
        foreach ( $pills as $pill ) {
            $html .= '<span class="spk-filter__pill' . $extra . '">'
                . esc_html( $pill['label'] )
                . '<a href="' . esc_url( $pill['remove_url'] ) . '" class="spk-filter__pill-remove" aria-label="'
                . esc_attr( sprintf( __( 'Remove %s filter', 'snopeak-events' ), $pill['label'] ) )
                . '">✕</a>'
                . '</span>';
        }
        return $html;
    }

    // -------------------------------------------------------------------------
    // [spk_cat_filter_clear]
    // -------------------------------------------------------------------------

    public static function sc_clear( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        if ( ! $s['active_org'] && ! $s['active_loc'] ) return '';

        $extra = self::extra_class( $atts );
        $atts  = shortcode_atts( array( 'class' => '', 'label' => __( 'Clear filters', 'snopeak-events' ) ), $atts );

        return '<a href="' . esc_url( $s['base_url'] ) . '" class="spk-filter__clear-all' . $extra . '">'
            . esc_html( $atts['label'] )
            . '</a>';
    }

    // -------------------------------------------------------------------------
    // Count shortcodes
    // -------------------------------------------------------------------------

    public static function sc_upcoming( $atts ) {
        $atts = shortcode_atts( array( 'wrap' => 'span' ), $atts );
        $term = self::get_term();
        if ( ! $term ) return '';
        $count = self::get_count( $term->slug, 'upcoming' );
        return self::wrap( $count, 'cat-upcoming-count', $atts['wrap'] );
    }

    public static function sc_past( $atts ) {
        $atts = shortcode_atts( array( 'wrap' => 'span' ), $atts );
        $term = self::get_term();
        if ( ! $term ) return '';
        $count = self::get_count( $term->slug, 'past' );
        return self::wrap( $count, 'cat-past-count', $atts['wrap'] );
    }

    public static function sc_total( $atts ) {
        $atts = shortcode_atts( array( 'wrap' => 'span' ), $atts );
        $term = self::get_term();
        if ( ! $term ) return '';
        $count = self::get_count( $term->slug, 'total' );
        return self::wrap( $count, 'cat-total-count', $atts['wrap'] );
    }

    private static function get_count( $slug, $type ) {
        $cache_key = 'spk_cat_count_' . $slug . '_' . $type;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return (int) $cached;

        $today = (int) gmdate( 'Ymd', strtotime( current_time( 'Y-m-d' ) ) );

        $args = array(
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'tax_query'      => array(
                array(
                    'taxonomy' => 'event-category',
                    'field'    => 'slug',
                    'terms'    => $slug,
                ),
            ),
        );

        if ( 'total' !== $type ) {
            $args['meta_query'] = array(
                array(
                    'key'     => 'event_end_sort',
                    'value'   => $today,
                    'compare' => ( 'upcoming' === $type ) ? '>=' : '<',
                    'type'    => 'NUMERIC',
                ),
            );
        }

        $query = new WP_Query( $args );
        $count = (int) $query->found_posts;
        set_transient( $cache_key, $count, self::CACHE_EXPIRY );
        return $count;
    }

    private static function wrap( $count, $class, $tag = 'span' ) {
        $tag = tag_escape( $tag );
        return '<' . $tag . ' class="spk-count ' . esc_attr( $class ) . '">'
            . (int) $count
            . '</' . $tag . '>';
    }

    // -------------------------------------------------------------------------
    // URL builder
    // -------------------------------------------------------------------------

    private static function build_url( $base, $org, $loc ) {
        $args = array();
        if ( $org ) $args['org'] = $org;
        if ( $loc ) $args['loc'] = $loc;
        return $args ? add_query_arg( $args, $base ) : $base;
    }

    // -------------------------------------------------------------------------
    // Pool: all upcoming event IDs for this category
    // -------------------------------------------------------------------------

    public static function get_upcoming_ids_public( $slug ) {
        return self::get_upcoming_ids( $slug );
    }

    private static function get_upcoming_ids( $slug ) {
        $cache_key = 'spk_cat_pool_' . $slug . '_' . gmdate( 'Ymd' );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) return $cached;

        $today = (int) gmdate( 'Ymd', strtotime( current_time( 'Y-m-d' ) ) );

        $upcoming = get_posts( array(
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => array(
                array(
                    'taxonomy' => 'event-category',
                    'field'    => 'slug',
                    'terms'    => $slug,
                ),
            ),
            'meta_query'     => array(
                array( 'key' => 'event_end_sort', 'value' => $today, 'compare' => '>=', 'type' => 'NUMERIC' ),
            ),
        ) );

        $upcoming = array_map( 'intval', (array) $upcoming );
        set_transient( $cache_key, $upcoming, self::CACHE_EXPIRY );
        return $upcoming;
    }

    // -------------------------------------------------------------------------
    // Conditional option fetchers
    // -------------------------------------------------------------------------

    private static function get_organisers( $pool_ids, $loc ) {
        if ( empty( $pool_ids ) ) return array();
        $ids = $loc > 0 ? self::filter_ids_by_loc( $pool_ids, $loc ) : $pool_ids;
        return self::pluck_relationship( $ids, 'event_organiser' );
    }

    private static function get_locations( $pool_ids, $org ) {
        if ( empty( $pool_ids ) ) return array();
        $ids = $org > 0 ? self::filter_ids_by_org( $pool_ids, $org ) : $pool_ids;
        return self::pluck_relationship( $ids, 'event_location' );
    }

    // -------------------------------------------------------------------------
    // Pool narrowing helpers
    // -------------------------------------------------------------------------

    private static function filter_ids_by_org( $ids, $org ) {
        if ( empty( $ids ) || ! $org ) return $ids;
        return (array) get_posts( array(
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post__in'       => $ids,
            'meta_query'     => array(
                array( 'key' => 'event_organiser', 'value' => '"' . (int) $org . '"', 'compare' => 'LIKE' ),
            ),
        ) );
    }

    private static function filter_ids_by_loc( $ids, $loc ) {
        if ( empty( $ids ) || ! $loc ) return $ids;
        return (array) get_posts( array(
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post__in'       => $ids,
            'meta_query'     => array(
                array( 'key' => 'event_location', 'value' => '"' . (int) $loc . '"', 'compare' => 'LIKE' ),
            ),
        ) );
    }

    // -------------------------------------------------------------------------
    // Extractors
    // -------------------------------------------------------------------------

    private static function pluck_relationship( $post_ids, $meta_key ) {
        if ( empty( $post_ids ) ) return array();
        $related = array();
        foreach ( $post_ids as $id ) {
            $raw = get_post_meta( (int) $id, $meta_key, true );
            if ( empty( $raw ) ) continue;
            if ( is_string( $raw ) ) $raw = maybe_unserialize( $raw );
            if ( is_array( $raw ) ) {
                foreach ( $raw as $item ) {
                    $related[] = is_object( $item ) ? (int) $item->ID : (int) $item;
                }
            } elseif ( is_object( $raw ) ) {
                $related[] = (int) $raw->ID;
            } elseif ( is_numeric( $raw ) ) {
                $related[] = (int) $raw;
            }
        }
        $related = array_unique( array_filter( $related ) );
        $result  = array();
        foreach ( $related as $rid ) {
            $title = get_the_title( $rid );
            if ( $title ) $result[ $rid ] = $title;
        }
        asort( $result );
        return $result;
    }

    // -------------------------------------------------------------------------
    // Cache invalidation
    // -------------------------------------------------------------------------

    public static function clear_option_caches() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_spk_cat_pool_%'
                OR option_name LIKE '_transient_timeout_spk_cat_pool_%'
                OR option_name LIKE '_transient_spk_cat_count_%'
                OR option_name LIKE '_transient_timeout_spk_cat_count_%'"
        );
    }
}

SPK_Category_Filter_Bar::register();
add_action( 'save_post_event', array( 'SPK_Category_Filter_Bar', 'clear_option_caches' ) );

// -----------------------------------------------------------------------------
// Bricks dynamic data tag: {spk_cat_event_count}
// -----------------------------------------------------------------------------

add_filter( 'bricks/dynamic_tags_list', function( $tags ) {
    $tags[] = array(
        'name'  => '{spk_cat_event_count}',
        'label' => 'Category Upcoming Event Count',
        'group' => 'SnoPeak Events',
    );
    return $tags;
} );

add_filter( 'bricks/dynamic_data/render_tag', function( $tag, $post, $context ) {
    if ( $tag !== 'spk_cat_event_count' ) return $tag;

    $path      = trim( parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
    $parts     = explode( '/', $path );
    $cat_index = array_search( 'event-category', $parts );

    if ( $cat_index === false || ! isset( $parts[ $cat_index + 1 ] ) ) return '0';

    $term = get_term_by( 'slug', sanitize_title( $parts[ $cat_index + 1 ] ), 'event-category' );
    if ( ! $term || is_wp_error( $term ) ) return '0';

    $ids = SPK_Category_Filter_Bar::get_upcoming_ids_public( $term->slug );
    return (string) count( $ids );
}, 10, 3 );

add_filter( 'bricks/dynamic_data/render_content', function( $content, $post, $context ) {
    if ( strpos( $content, '{spk_cat_event_count}' ) === false ) return $content;

    $path      = trim( parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
    $parts     = explode( '/', $path );
    $cat_index = array_search( 'event-category', $parts );
    $count     = 0;

    if ( $cat_index !== false && isset( $parts[ $cat_index + 1 ] ) ) {
        $term = get_term_by( 'slug', sanitize_title( $parts[ $cat_index + 1 ] ), 'event-category' );
        if ( $term && ! is_wp_error( $term ) ) {
            $count = count( SPK_Category_Filter_Bar::get_upcoming_ids_public( $term->slug ) );
        }
    }

    return str_replace( '{spk_cat_event_count}', (string) $count, $content );
}, 10, 3 );
