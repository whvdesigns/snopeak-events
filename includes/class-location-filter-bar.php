<?php
/**
 * Location Filter Bar
 *
 * Organiser and category filters for the upcoming events dual loop
 * on event-location detail pages.
 *
 * Shortcodes:
 *   [spk_loc_filter_organiser]  — organiser <select>  (conditional on cat)
 *   [spk_loc_filter_category]   — category <select>   (conditional on org)
 *   [spk_loc_filter_pills]      — active filter pills
 *   [spk_loc_filter_clear]      — clear filters link (hidden when no filters active)
 *
 * URL params: ?org=ID&cat=slug
 * These are already read by bl_get_event_month_data() on location context pages,
 * so the dual loop filters automatically when these params are present.
 *
 * Reuses past-filter-bar.css / past-filter-bar.js.
 * BEM block: .spk-filter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPK_Location_Filter_Bar {

    const CACHE_EXPIRY = 43200; // 12h

    private static $state = null;

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function register() {
        add_shortcode( 'spk_loc_filter_organiser', array( __CLASS__, 'sc_organiser' ) );
        add_shortcode( 'spk_loc_filter_category',  array( __CLASS__, 'sc_category'  ) );
        add_shortcode( 'spk_loc_filter_pills',     array( __CLASS__, 'sc_pills'     ) );
        add_shortcode( 'spk_loc_filter_clear',     array( __CLASS__, 'sc_clear'     ) );
    }

    // -------------------------------------------------------------------------
    // Shared state — parsed once per request
    // -------------------------------------------------------------------------

    private static function state() {
        if ( self::$state !== null ) {
            return self::$state;
        }

        // Parse location ID from URL — Bricks returns template ID from get_queried_object_id()
        $path      = trim( parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
        $parts     = explode( '/', $path );
        $loc_id    = 0;
        $loc_index = array_search( 'location', $parts );

        if ( $loc_index !== false && isset( $parts[ $loc_index + 1 ] ) ) {
            $post = get_page_by_path( sanitize_title( $parts[ $loc_index + 1 ] ), OBJECT, 'location' );
            if ( $post ) {
                $loc_id = $post->ID;
            }
        }

        if ( ! $loc_id ) {
            self::$state = false;
            return false;
        }

        // Pool: all upcoming event IDs for this location
        $pool_ids = self::get_upcoming_ids( $loc_id );

        $active_org = isset( $_GET['org'] ) ? absint( $_GET['org'] )        : 0;
        $active_cat = isset( $_GET['cat'] ) ? sanitize_key( $_GET['cat'] ) : '';

        // Validate — silently drop stale params
        if ( $active_org ) {
            $org_opts = self::get_organisers( $pool_ids, $active_cat );
            if ( ! isset( $org_opts[ $active_org ] ) ) {
                $active_org = 0;
            }
        }
        if ( $active_cat ) {
            $cat_opts = self::get_categories( $pool_ids, $active_org );
            if ( ! isset( $cat_opts[ $active_cat ] ) ) {
                $active_cat = '';
            }
        }

        // Base URL is the location page permalink
        $base_url = get_permalink( $loc_id );

        self::$state = compact( 'loc_id', 'pool_ids', 'active_org', 'active_cat', 'base_url' );
        return self::$state;
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
    // [spk_loc_filter_organiser]
    // -------------------------------------------------------------------------

    public static function sc_organiser( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        $extra = self::extra_class( $atts );
        $opts  = self::get_organisers( $s['pool_ids'], $s['active_cat'] );

        if ( empty( $opts ) ) return '';

        $active_cls = $s['active_org'] ? ' spk-filter__select--active' : '';
        $none_url   = self::build_url( $s['base_url'], 0, $s['active_cat'] );

        $html  = '<select class="spk-filter__select' . $active_cls . $extra . '" aria-label="' . esc_attr__( 'Filter by organiser', 'snopeak-events' ) . '" data-spk-select>';
        $html .= '<option value="' . esc_url( $none_url ) . '">' . esc_html__( 'All Organisers', 'snopeak-events' ) . '</option>';
        foreach ( $opts as $id => $name ) {
            $url   = self::build_url( $s['base_url'], $id, $s['active_cat'] );
            $html .= '<option value="' . esc_url( $url ) . '"' . selected( $id, $s['active_org'], false ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    // -------------------------------------------------------------------------
    // [spk_loc_filter_category]
    // -------------------------------------------------------------------------

    public static function sc_category( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        $extra = self::extra_class( $atts );
        $opts  = self::get_categories( $s['pool_ids'], $s['active_org'] );

        if ( empty( $opts ) ) return '';

        $active_cls = $s['active_cat'] ? ' spk-filter__select--active' : '';
        $none_url   = self::build_url( $s['base_url'], $s['active_org'], '' );

        $html  = '<select class="spk-filter__select' . $active_cls . $extra . '" aria-label="' . esc_attr__( 'Filter by category', 'snopeak-events' ) . '" data-spk-select>';
        $html .= '<option value="' . esc_url( $none_url ) . '">' . esc_html__( 'All Categories', 'snopeak-events' ) . '</option>';
        foreach ( $opts as $slug => $name ) {
            $url   = self::build_url( $s['base_url'], $s['active_org'], $slug );
            $html .= '<option value="' . esc_url( $url ) . '"' . selected( $slug, $s['active_cat'], false ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    // -------------------------------------------------------------------------
    // [spk_loc_filter_pills]
    // -------------------------------------------------------------------------

    public static function sc_pills( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        $extra    = self::extra_class( $atts );
        $org_opts = self::get_organisers( $s['pool_ids'], $s['active_cat'] );
        $cat_opts = self::get_categories( $s['pool_ids'], $s['active_org'] );

        $pills = array();

        if ( $s['active_org'] && isset( $org_opts[ $s['active_org'] ] ) ) {
            $pills[] = array(
                'label'      => $org_opts[ $s['active_org'] ],
                'remove_url' => self::build_url( $s['base_url'], 0, $s['active_cat'] ),
            );
        }
        if ( $s['active_cat'] && isset( $cat_opts[ $s['active_cat'] ] ) ) {
            $pills[] = array(
                'label'      => $cat_opts[ $s['active_cat'] ],
                'remove_url' => self::build_url( $s['base_url'], $s['active_org'], '' ),
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
    // [spk_loc_filter_clear]
    // -------------------------------------------------------------------------

    public static function sc_clear( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        if ( ! $s['active_org'] && ! $s['active_cat'] ) {
            return '';
        }

        $extra = self::extra_class( $atts );
        $atts  = shortcode_atts( array( 'class' => '', 'label' => __( 'Clear filters', 'snopeak-events' ) ), $atts );

        return '<a href="' . esc_url( $s['base_url'] ) . '" class="spk-filter__clear-all' . $extra . '">'
            . esc_html( $atts['label'] )
            . '</a>';
    }

    // -------------------------------------------------------------------------
    // URL builder
    // -------------------------------------------------------------------------

    private static function build_url( $base, $org, $cat ) {
        $args = array();
        if ( $org ) $args['org'] = $org;
        if ( $cat ) $args['cat'] = $cat;
        return $args ? add_query_arg( $args, $base ) : $base;
    }

    // -------------------------------------------------------------------------
    // Pool: all upcoming event IDs for this location
    // -------------------------------------------------------------------------

    public static function get_upcoming_ids_public( $loc_id ) {
        return self::get_upcoming_ids( $loc_id );
    }

    private static function get_upcoming_ids( $loc_id ) {
        $cache_key = 'spk_loc_pool_' . $loc_id . '_' . gmdate( 'Ymd' );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) return $cached;

        $today = (int) gmdate( 'Ymd', strtotime( current_time( 'Y-m-d' ) ) );

        $upcoming = get_posts( array(
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array( 'key' => 'event_location', 'value' => '"' . (int) $loc_id . '"', 'compare' => 'LIKE' ),
                array( 'key' => 'event_end_sort',  'value' => $today, 'compare' => '>=', 'type' => 'NUMERIC' ),
            ),
        ) );

        $upcoming = array_map( 'intval', (array) $upcoming );
        set_transient( $cache_key, $upcoming, self::CACHE_EXPIRY );
        return $upcoming;
    }

    // -------------------------------------------------------------------------
    // Conditional option fetchers
    // -------------------------------------------------------------------------

    private static function get_organisers( $pool_ids, $cat ) {
        if ( empty( $pool_ids ) ) return array();

        $ids = $cat !== '' ? self::filter_ids_by_cat( $pool_ids, $cat ) : $pool_ids;
        return self::pluck_relationship( $ids, 'event_organiser' );
    }

    private static function get_categories( $pool_ids, $org ) {
        if ( empty( $pool_ids ) ) return array();

        $ids = $org > 0 ? self::filter_ids_by_org( $pool_ids, $org ) : $pool_ids;
        return self::pluck_taxonomy( $ids, 'event-category' );
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

    private static function filter_ids_by_cat( $ids, $cat ) {
        if ( empty( $ids ) || $cat === '' ) return $ids;

        return (array) get_posts( array(
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post__in'       => $ids,
            'tax_query'      => array(
                array( 'taxonomy' => 'event-category', 'field' => 'slug', 'terms' => $cat ),
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

    private static function pluck_taxonomy( $post_ids, $taxonomy ) {
        if ( empty( $post_ids ) ) return array();
        $result = array();
        foreach ( $post_ids as $id ) {
            $terms = wp_get_object_terms( (int) $id, $taxonomy, array( 'fields' => 'all' ) );
            if ( is_wp_error( $terms ) ) continue;
            foreach ( $terms as $term ) {
                $result[ $term->slug ] = $term->name;
            }
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
             WHERE option_name LIKE '_transient_spk_loc_pool_%'
                OR option_name LIKE '_transient_timeout_spk_loc_pool_%'"
        );
    }
}

SPK_Location_Filter_Bar::register();
add_action( 'save_post_event', array( 'SPK_Location_Filter_Bar', 'clear_option_caches' ) );

// -----------------------------------------------------------------------------
// Bricks dynamic data tag: {spk_loc_event_count}
//
// Returns the number of upcoming events for the current location.
// Use in Bricks element conditions to hide the events section when
// the location has no upcoming events.
//
// Example condition: Hide element if {spk_loc_event_count} = 0
// -----------------------------------------------------------------------------

add_filter( 'bricks/dynamic_tags_list', function( $tags ) {
    $tags[] = array(
        'name'  => '{spk_loc_event_count}',
        'label' => 'Location Upcoming Event Count',
        'group' => 'SnoPeak Events',
    );
    return $tags;
} );

add_filter( 'bricks/dynamic_data/render_tag', function( $tag, $post, $context ) {
    if ( $tag !== 'spk_loc_event_count' ) {
        return $tag;
    }

    // Parse location ID from URL (Bricks returns template ID from get_queried_object_id)
    $path      = trim( parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
    $parts     = explode( '/', $path );
    $loc_id    = 0;
    $loc_index = array_search( 'location', $parts );

    if ( $loc_index !== false && isset( $parts[ $loc_index + 1 ] ) ) {
        $p = get_page_by_path( sanitize_title( $parts[ $loc_index + 1 ] ), OBJECT, 'location' );
        if ( $p ) $loc_id = $p->ID;
    }

    if ( ! $loc_id ) return '0';

    $ids = SPK_Location_Filter_Bar::get_upcoming_ids_public( $loc_id );
    return (string) count( $ids );
}, 10, 3 );

add_filter( 'bricks/dynamic_data/render_content', function( $content, $post, $context ) {
    if ( strpos( $content, '{spk_loc_event_count}' ) === false ) {
        return $content;
    }

    $path      = trim( parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
    $parts     = explode( '/', $path );
    $loc_id    = 0;
    $loc_index = array_search( 'location', $parts );

    if ( $loc_index !== false && isset( $parts[ $loc_index + 1 ] ) ) {
        $p = get_page_by_path( sanitize_title( $parts[ $loc_index + 1 ] ), OBJECT, 'location' );
        if ( $p ) $loc_id = $p->ID;
    }

    $ids = $loc_id ? SPK_Location_Filter_Bar::get_upcoming_ids_public( $loc_id ) : array();
    return str_replace( '{spk_loc_event_count}', (string) count( $ids ), $content );
}, 10, 3 );

