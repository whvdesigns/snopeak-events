<?php
/**
 * Organiser Filter Bar
 *
 * Location and category filters for the upcoming events dual loop
 * on event-organiser detail pages.
 *
 * Shortcodes:
 *   [spk_org_filter_location]   — location <select>  (conditional on cat)
 *   [spk_org_filter_category]   — category <select>  (conditional on loc)
 *   [spk_org_filter_pills]      — active filter pills
 *   [spk_org_filter_clear]      — clear filters link (hidden when no filters active)
 *
 * URL params: ?loc=ID&cat=slug
 * These are already read by bl_get_event_month_data() on organiser context pages,
 * so the dual loop filters automatically when these params are present.
 *
 * Reuses past-filter-bar.css / past-filter-bar.js.
 * BEM block: .spk-filter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPK_Organiser_Filter_Bar {

    const CACHE_EXPIRY = 43200; // 12h

    private static $state = null;

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function register() {
        add_shortcode( 'spk_org_filter_location', array( __CLASS__, 'sc_location' ) );
        add_shortcode( 'spk_org_filter_category', array( __CLASS__, 'sc_category' ) );
        add_shortcode( 'spk_org_filter_pills',    array( __CLASS__, 'sc_pills'    ) );
        add_shortcode( 'spk_org_filter_clear',    array( __CLASS__, 'sc_clear'    ) );
    }

    // -------------------------------------------------------------------------
    // Shared state — parsed once per request
    // -------------------------------------------------------------------------

    private static function state() {
        if ( self::$state !== null ) {
            return self::$state;
        }

        // Parse organiser ID from URL — Bricks returns template ID from get_queried_object_id()
        $path      = trim( parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
        $parts     = explode( '/', $path );
        $org_id    = 0;
        $org_index = array_search( 'organisers', $parts );

        if ( $org_index !== false && isset( $parts[ $org_index + 1 ] ) ) {
            $post = get_page_by_path( sanitize_title( $parts[ $org_index + 1 ] ), OBJECT, 'event-organiser' );
            if ( $post ) {
                $org_id = $post->ID;
            }
        }

        if ( ! $org_id ) {
            self::$state = false;
            return false;
        }

        // Pool: all upcoming event IDs for this organiser
        $pool_ids = self::get_upcoming_ids( $org_id );

        $active_loc = isset( $_GET['loc'] ) ? absint( $_GET['loc'] )        : 0;
        $active_cat = isset( $_GET['cat'] ) ? sanitize_key( $_GET['cat'] ) : '';

        // Validate — silently drop stale params
        if ( $active_loc ) {
            $loc_opts = self::get_locations( $pool_ids, $active_cat );
            if ( ! isset( $loc_opts[ $active_loc ] ) ) {
                $active_loc = 0;
            }
        }
        if ( $active_cat ) {
            $cat_opts = self::get_categories( $pool_ids, $active_loc );
            if ( ! isset( $cat_opts[ $active_cat ] ) ) {
                $active_cat = '';
            }
        }

        // Base URL is the organiser page permalink
        $base_url = get_permalink( $org_id );

        self::$state = compact( 'org_id', 'pool_ids', 'active_loc', 'active_cat', 'base_url' );
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
    // [spk_org_filter_location]
    // -------------------------------------------------------------------------

    public static function sc_location( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        $extra = self::extra_class( $atts );
        $opts  = self::get_locations( $s['pool_ids'], $s['active_cat'] );

        if ( empty( $opts ) ) return '';

        $active_cls = $s['active_loc'] ? ' spk-filter__select--active' : '';
        $none_url   = self::build_url( $s['base_url'], 0, $s['active_cat'] );

        $html  = '<select class="spk-filter__select' . $active_cls . $extra . '" aria-label="' . esc_attr__( 'Filter by location', 'snopeak-events' ) . '" data-spk-select>';
        $html .= '<option value="' . esc_url( $none_url ) . '">' . esc_html__( 'All Locations', 'snopeak-events' ) . '</option>';
        foreach ( $opts as $id => $name ) {
            $url   = self::build_url( $s['base_url'], $id, $s['active_cat'] );
            $html .= '<option value="' . esc_url( $url ) . '"' . selected( $id, $s['active_loc'], false ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    // -------------------------------------------------------------------------
    // [spk_org_filter_category]
    // -------------------------------------------------------------------------

    public static function sc_category( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        $extra = self::extra_class( $atts );
        $opts  = self::get_categories( $s['pool_ids'], $s['active_loc'] );

        if ( empty( $opts ) ) return '';

        $active_cls = $s['active_cat'] ? ' spk-filter__select--active' : '';
        $none_url   = self::build_url( $s['base_url'], $s['active_loc'], '' );

        $html  = '<select class="spk-filter__select' . $active_cls . $extra . '" aria-label="' . esc_attr__( 'Filter by category', 'snopeak-events' ) . '" data-spk-select>';
        $html .= '<option value="' . esc_url( $none_url ) . '">' . esc_html__( 'All Categories', 'snopeak-events' ) . '</option>';
        foreach ( $opts as $slug => $name ) {
            $url   = self::build_url( $s['base_url'], $s['active_loc'], $slug );
            $html .= '<option value="' . esc_url( $url ) . '"' . selected( $slug, $s['active_cat'], false ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    // -------------------------------------------------------------------------
    // [spk_org_filter_pills]
    // -------------------------------------------------------------------------

    public static function sc_pills( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        $extra    = self::extra_class( $atts );
        $loc_opts = self::get_locations(  $s['pool_ids'], $s['active_cat'] );
        $cat_opts = self::get_categories( $s['pool_ids'], $s['active_loc'] );

        $pills = array();

        if ( $s['active_loc'] && isset( $loc_opts[ $s['active_loc'] ] ) ) {
            $pills[] = array(
                'label'      => $loc_opts[ $s['active_loc'] ],
                'remove_url' => self::build_url( $s['base_url'], 0, $s['active_cat'] ),
            );
        }
        if ( $s['active_cat'] && isset( $cat_opts[ $s['active_cat'] ] ) ) {
            $pills[] = array(
                'label'      => $cat_opts[ $s['active_cat'] ],
                'remove_url' => self::build_url( $s['base_url'], $s['active_loc'], '' ),
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
    // [spk_org_filter_clear]
    // -------------------------------------------------------------------------

    public static function sc_clear( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        if ( ! $s['active_loc'] && ! $s['active_cat'] ) {
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

    private static function build_url( $base, $loc, $cat ) {
        $args = array();
        if ( $loc ) $args['loc'] = $loc;
        if ( $cat ) $args['cat'] = $cat;
        return $args ? add_query_arg( $args, $base ) : $base;
    }

    // -------------------------------------------------------------------------
    // Pool: all upcoming event IDs for this organiser
    // -------------------------------------------------------------------------

    public static function get_upcoming_ids_public( $org_id ) {
        return self::get_upcoming_ids( $org_id );
    }

    private static function get_upcoming_ids( $org_id ) {
        $cache_key = 'spk_org_pool_' . $org_id . '_' . gmdate( 'Ymd' );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) return $cached;

        $today    = (int) gmdate( 'Ymd', strtotime( current_time( 'Y-m-d' ) ) );

        // Query events directly by meta — same approach as bl_get_event_month_data()
        // Avoids dependency on bidirectional ACF field organiser_events
        $upcoming = get_posts( array(
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array( 'key' => 'event_organiser', 'value' => '"' . $org_id . '"', 'compare' => 'LIKE' ),
                array( 'key' => 'event_end_sort',  'value' => $today,  'compare' => '>=', 'type' => 'NUMERIC' ),
            ),
        ) );

        $upcoming = array_map( 'intval', (array) $upcoming );
        set_transient( $cache_key, $upcoming, self::CACHE_EXPIRY );
        return $upcoming;
    }

    // -------------------------------------------------------------------------
    // Conditional option fetchers
    // -------------------------------------------------------------------------

    private static function get_locations( $pool_ids, $cat ) {
        if ( empty( $pool_ids ) ) return array();

        $ids = $cat !== '' ? self::filter_ids_by_cat( $pool_ids, $cat ) : $pool_ids;
        return self::pluck_relationship( $ids, 'event_location' );
    }

    private static function get_categories( $pool_ids, $loc ) {
        if ( empty( $pool_ids ) ) return array();

        $ids = $loc > 0 ? self::filter_ids_by_loc( $pool_ids, $loc ) : $pool_ids;
        return self::pluck_taxonomy( $ids, 'event-category' );
    }

    // -------------------------------------------------------------------------
    // Pool narrowing helpers
    // -------------------------------------------------------------------------

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
             WHERE option_name LIKE '_transient_spk_org_pool_%'
                OR option_name LIKE '_transient_timeout_spk_org_pool_%'"
        );
    }
}

SPK_Organiser_Filter_Bar::register();
add_action( 'save_post_event', array( 'SPK_Organiser_Filter_Bar', 'clear_option_caches' ) );

// -----------------------------------------------------------------------------
// Bricks dynamic data tag: {spk_org_event_count}
//
// Returns the number of upcoming events for the current organiser.
// Use in Bricks element conditions to hide the events section when
// the organiser has no upcoming events.
//
// Example condition: Hide element if {spk_org_event_count} = 0
// -----------------------------------------------------------------------------

add_filter( 'bricks/dynamic_tags_list', function( $tags ) {
    $tags[] = array(
        'name'  => '{spk_org_event_count}',
        'label' => 'Organiser Upcoming Event Count',
        'group' => 'SnoPeak Events',
    );
    return $tags;
} );

add_filter( 'bricks/dynamic_data/render_tag', function( $tag, $post, $context ) {
    if ( $tag !== 'spk_org_event_count' ) {
        return $tag;
    }

    // Parse organiser ID from URL (Bricks returns template ID from get_queried_object_id)
    $path      = trim( parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
    $parts     = explode( '/', $path );
    $org_id    = 0;
    $org_index = array_search( 'organisers', $parts );

    if ( $org_index !== false && isset( $parts[ $org_index + 1 ] ) ) {
        $p = get_page_by_path( sanitize_title( $parts[ $org_index + 1 ] ), OBJECT, 'event-organiser' );
        if ( $p ) $org_id = $p->ID;
    }

    if ( ! $org_id ) return '0';

    $ids = SPK_Organiser_Filter_Bar::get_upcoming_ids_public( $org_id );
    return (string) count( $ids );
}, 10, 3 );

add_filter( 'bricks/dynamic_data/render_content', function( $content, $post, $context ) {
    if ( strpos( $content, '{spk_org_event_count}' ) === false ) {
        return $content;
    }

    $path      = trim( parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
    $parts     = explode( '/', $path );
    $org_id    = 0;
    $org_index = array_search( 'organisers', $parts );

    if ( $org_index !== false && isset( $parts[ $org_index + 1 ] ) ) {
        $p = get_page_by_path( sanitize_title( $parts[ $org_index + 1 ] ), OBJECT, 'event-organiser' );
        if ( $p ) $org_id = $p->ID;
    }

    $ids = $org_id ? SPK_Organiser_Filter_Bar::get_upcoming_ids_public( $org_id ) : array();
    return str_replace( '{spk_org_event_count}', (string) count( $ids ), $content );
}, 10, 3 );


