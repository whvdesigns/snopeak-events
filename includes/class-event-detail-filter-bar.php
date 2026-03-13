<?php
/**
 * Event Detail Filter Bar
 *
 * Location and category filters for the related upcoming events section
 * on event detail pages.
 *
 * Only shows options that exist within the current event's organiser's
 * upcoming events — conditional on the other active filter.
 *
 * Shortcodes:
 *   [spk_detail_filter_location]   — location <select>  (conditional on cat)
 *   [spk_detail_filter_category]   — category <select>  (conditional on loc)
 *   [spk_detail_filter_pills]      — active filter pills
 *   [spk_detail_filter_clear]      — clear filters link (hidden when no filters active)
 *
 * URL params: ?loc=ID&cat=slug
 * Reuses past-filter-bar.css / past-filter-bar.js.
 * BEM block: .spk-filter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPK_Event_Detail_Filter_Bar {

    const CACHE_EXPIRY = 43200; // 12h

    private static $state = null;

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function register() {
        add_shortcode( 'spk_detail_filter_location', array( __CLASS__, 'sc_location'   ) );
        add_shortcode( 'spk_detail_filter_category', array( __CLASS__, 'sc_category'   ) );
        add_shortcode( 'spk_detail_filter_pills',    array( __CLASS__, 'sc_pills'      ) );
        add_shortcode( 'spk_detail_filter_clear',    array( __CLASS__, 'sc_clear'      ) );
    }

    // -------------------------------------------------------------------------
    // Shared state — parsed once per request
    // -------------------------------------------------------------------------

    private static function state() {
        if ( self::$state !== null ) {
            return self::$state;
        }

        // Parse current event ID from URL (Bricks returns template ID from get_queried_object_id)
        $path        = trim( parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
        $parts       = explode( '/', $path );
        $current_id  = 0;
        $event_index = array_search( 'events', $parts );

        if ( $event_index !== false && isset( $parts[ $event_index + 1 ] ) ) {
            $post = get_page_by_path( sanitize_title( $parts[ $event_index + 1 ] ), OBJECT, 'event' );
            if ( $post ) {
                $current_id = $post->ID;
            }
        }

        if ( ! $current_id ) {
            $current_id = get_queried_object_id();
        }

        if ( ! $current_id || get_post_type( $current_id ) !== 'event' ) {
            self::$state = false;
            return false;
        }

        // Collect all upcoming event IDs for this event's organiser(s)
        $org_event_ids = self::get_organiser_upcoming_ids( $current_id );

        $active_loc = isset( $_GET['loc'] ) ? absint( $_GET['loc'] )         : 0;
        $active_cat = isset( $_GET['cat'] ) ? sanitize_key( $_GET['cat'] ) : '';

        // Validate — silently drop stale params
        if ( $active_loc ) {
            $loc_opts = self::get_locations( $org_event_ids, $active_cat );
            if ( ! isset( $loc_opts[ $active_loc ] ) ) $active_loc = 0;
        }
        if ( $active_cat ) {
            $cat_opts = self::get_categories( $org_event_ids, $active_loc );
            if ( ! isset( $cat_opts[ $active_cat ] ) ) $active_cat = '';
        }

        $base_url = get_permalink( $current_id );

        self::$state = compact( 'current_id', 'org_event_ids', 'active_loc', 'active_cat', 'base_url' );
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
    // [spk_detail_filter_location]
    // -------------------------------------------------------------------------

    public static function sc_location( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        $extra = self::extra_class( $atts );
        $opts  = self::get_locations( $s['org_event_ids'], $s['active_cat'] );

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
    // [spk_detail_filter_category]
    // -------------------------------------------------------------------------

    public static function sc_category( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        $extra = self::extra_class( $atts );
        $opts  = self::get_categories( $s['org_event_ids'], $s['active_loc'] );

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
    // [spk_detail_filter_pills]
    // -------------------------------------------------------------------------

    public static function sc_pills( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        $extra    = self::extra_class( $atts );
        $loc_opts = self::get_locations(  $s['org_event_ids'], $s['active_cat'] );
        $cat_opts = self::get_categories( $s['org_event_ids'], $s['active_loc'] );

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
    // [spk_detail_filter_clear]
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
    // Pool: all upcoming event IDs for the current event's organiser(s),
    // excluding the current event itself.
    // -------------------------------------------------------------------------

    public static function get_organiser_upcoming_ids_public( $post_id ) {
        return self::get_organiser_upcoming_ids( $post_id ?: ( self::state() ? self::state()['current_id'] : 0 ) );
    }

    private static function get_organiser_upcoming_ids( $current_id ) {
        $cache_key = 'spk_detail_pool_' . $current_id;
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) return $cached;

        $ids        = array();
        $organisers = get_field( 'event_organiser', $current_id );

        if ( ! empty( $organisers ) ) {
            foreach ( (array) $organisers as $org ) {
                $org_id = is_object( $org ) ? $org->ID : absint( $org );
                if ( ! $org_id ) continue;
                $org_events = get_field( 'organiser_events', $org_id );
                if ( empty( $org_events ) ) continue;
                foreach ( (array) $org_events as $ev ) {
                    $ids[] = is_object( $ev ) ? $ev->ID : absint( $ev );
                }
            }
        }

        $ids = array_values( array_diff( array_unique( array_filter( $ids ) ), array( $current_id ) ) );

        if ( empty( $ids ) ) {
            set_transient( $cache_key, array(), self::CACHE_EXPIRY );
            return array();
        }

        $today    = (int) gmdate( 'Ymd', strtotime( current_time( 'Y-m-d' ) ) );
        $upcoming = get_posts( array(
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post__in'       => $ids,
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

    private static function get_locations( $pool_ids, $cat ) {
        if ( empty( $pool_ids ) ) return array();

        $ids = $pool_ids;

        // Narrow pool by active category
        if ( $cat !== '' ) {
            $ids = self::filter_ids_by_cat( $ids, $cat );
        }

        return self::pluck_relationship( $ids, 'event_location' );
    }

    private static function get_categories( $pool_ids, $loc ) {
        if ( empty( $pool_ids ) ) return array();

        $ids = $pool_ids;

        // Narrow pool by active location
        if ( $loc > 0 ) {
            $ids = self::filter_ids_by_loc( $ids, $loc );
        }

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
    // Extractors — identical to other filter bars
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
             WHERE option_name LIKE '_transient_spk_detail_%'
                OR option_name LIKE '_transient_timeout_spk_detail_%'"
        );
    }
}

SPK_Event_Detail_Filter_Bar::register();
add_action( 'save_post_event', array( 'SPK_Event_Detail_Filter_Bar', 'clear_option_caches' ) );

// -----------------------------------------------------------------------------
// Bricks dynamic data tag: {spk_related_event_count}
//
// Returns the number of other upcoming events from the same organiser.
// Use in Bricks element conditions to hide the related events section when
// the current event is the organiser's only upcoming event.
//
// Example condition: Hide element if {spk_related_event_count} = 0
// -----------------------------------------------------------------------------

add_filter( 'bricks/dynamic_tags_list', function( $tags ) {
    $tags[] = array(
        'name'  => '{spk_related_event_count}',
        'label' => 'Related Event Count',
        'group' => 'SnoPeak Events',
    );
    return $tags;
} );

add_filter( 'bricks/dynamic_data/render_tag', function( $tag, $post, $context ) {
    if ( $tag !== 'spk_related_event_count' ) {
        return $tag;
    }

    $ids = SPK_Event_Detail_Filter_Bar::get_organiser_upcoming_ids_public(
        $post ? $post->ID : 0
    );

    return (string) count( $ids );
}, 10, 3 );

add_filter( 'bricks/dynamic_data/render_content', function( $content, $post, $context ) {
    if ( strpos( $content, '{spk_related_event_count}' ) === false ) {
        return $content;
    }
    $ids   = SPK_Event_Detail_Filter_Bar::get_organiser_upcoming_ids_public(
        $post ? $post->ID : 0
    );
    return str_replace( '{spk_related_event_count}', (string) count( $ids ), $content );
}, 10, 3 );
