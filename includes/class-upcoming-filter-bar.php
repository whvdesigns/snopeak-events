<?php
/**
 * Upcoming Events Filter Bar
 *
 * Mirrors the past events filter bar but targets upcoming/current events.
 * No year filter — upcoming events don't require year partitioning.
 *
 * Shortcodes:
 *   [spk_upcoming_filter_organiser]  — organiser <select> (conditional on loc/cat)
 *   [spk_upcoming_filter_location]   — location <select>  (conditional on org/cat)
 *   [spk_upcoming_filter_category]   — category <select>  (conditional on org/loc)
 *   [spk_upcoming_filter_pills]      — active filter pills
 *   [spk_upcoming_filter_clear]      — clear filters link (hidden when no filters active)
 *
 * Reuses the same CSS/JS as the past filter bar (past-filter-bar.css/js).
 * BEM block: .spk-filter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPK_Upcoming_Filter_Bar {

    const CACHE_EXPIRY = 43200; // 12h

    private static $state = null;

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function register() {
        add_shortcode( 'spk_upcoming_filter_organiser', array( __CLASS__, 'sc_organiser' ) );
        add_shortcode( 'spk_upcoming_filter_location',  array( __CLASS__, 'sc_location' ) );
        add_shortcode( 'spk_upcoming_filter_category',  array( __CLASS__, 'sc_category' ) );
        add_shortcode( 'spk_upcoming_filter_pills',     array( __CLASS__, 'sc_pills' ) );
        add_shortcode( 'spk_upcoming_filter_clear',     array( __CLASS__, 'sc_clear' ) );
    }

    // -------------------------------------------------------------------------
    // Shared state — parsed once per request
    // -------------------------------------------------------------------------

    private static function state() {
        if ( self::$state !== null ) {
            return self::$state;
        }

        $active_org = isset( $_GET['org'] ) ? absint( $_GET['org'] ) : 0;
        $active_loc = isset( $_GET['loc'] ) ? absint( $_GET['loc'] ) : 0;
        $active_cat = isset( $_GET['cat'] ) ? sanitize_key( $_GET['cat'] )               : '';

        $events_page = get_page_by_path( 'events' );
        $base_url    = $events_page ? get_permalink( $events_page ) : home_url( '/events/' );

        // Validate active filters — silently drop any that don't exist in the
        // conditional options for the current upcoming event pool.
        if ( $active_org ) {
            $org_opts = self::get_organisers( $active_loc, $active_cat );
            if ( ! isset( $org_opts[ $active_org ] ) ) $active_org = 0;
        }
        if ( $active_loc ) {
            $loc_opts = self::get_locations( $active_org, $active_cat );
            if ( ! isset( $loc_opts[ $active_loc ] ) ) $active_loc = 0;
        }
        if ( $active_cat ) {
            $cat_opts = self::get_categories( $active_org, $active_loc );
            if ( ! isset( $cat_opts[ $active_cat ] ) ) $active_cat = '';
        }

        self::$state = compact( 'active_org', 'active_loc', 'active_cat', 'base_url' );
        return self::$state;
    }

    // ── Enqueue assets — reuse past filter bar CSS/JS ─────────────────────────
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
    // [spk_upcoming_filter_organiser]
    // -------------------------------------------------------------------------

    public static function sc_organiser( $atts ) {
        self::enqueue();
        $s     = self::state();
        $extra = self::extra_class( $atts );
        $opts  = self::get_organisers( $s['active_loc'], $s['active_cat'] );

        $active_cls = $s['active_org'] ? ' spk-filter__select--active' : '';
        $none_url   = self::build_url( $s['base_url'], 0, $s['active_loc'], $s['active_cat'], '' );

        $html  = '<select class="spk-filter__select' . $active_cls . $extra . '" aria-label="' . esc_attr__( 'Filter by organiser', 'snopeak-events' ) . '" data-spk-select>';
        $html .= '<option value="' . esc_url( $none_url ) . '">' . esc_html__( 'All Organisers', 'snopeak-events' ) . '</option>';
        foreach ( $opts as $id => $name ) {
            $url   = self::build_url( $s['base_url'], $id, $s['active_loc'], $s['active_cat'], '' );
            $html .= '<option value="' . esc_url( $url ) . '"' . selected( $id, $s['active_org'], false ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    // -------------------------------------------------------------------------
    // [spk_upcoming_filter_location]
    // -------------------------------------------------------------------------

    public static function sc_location( $atts ) {
        self::enqueue();
        $s     = self::state();
        $extra = self::extra_class( $atts );
        $opts  = self::get_locations( $s['active_org'], $s['active_cat'] );

        $active_cls = $s['active_loc'] ? ' spk-filter__select--active' : '';
        $none_url   = self::build_url( $s['base_url'], $s['active_org'], 0, $s['active_cat'], '' );

        $html  = '<select class="spk-filter__select' . $active_cls . $extra . '" aria-label="' . esc_attr__( 'Filter by location', 'snopeak-events' ) . '" data-spk-select>';
        $html .= '<option value="' . esc_url( $none_url ) . '">' . esc_html__( 'All Locations', 'snopeak-events' ) . '</option>';
        foreach ( $opts as $id => $name ) {
            $url   = self::build_url( $s['base_url'], $s['active_org'], $id, $s['active_cat'], '' );
            $html .= '<option value="' . esc_url( $url ) . '"' . selected( $id, $s['active_loc'], false ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    // -------------------------------------------------------------------------
    // [spk_upcoming_filter_category]
    // -------------------------------------------------------------------------

    public static function sc_category( $atts ) {
        self::enqueue();
        $s     = self::state();
        $extra = self::extra_class( $atts );
        $opts  = self::get_categories( $s['active_org'], $s['active_loc'] );

        $active_cls = $s['active_cat'] ? ' spk-filter__select--active' : '';
        $none_url   = self::build_url( $s['base_url'], $s['active_org'], $s['active_loc'], '', '' );

        $html  = '<select class="spk-filter__select' . $active_cls . $extra . '" aria-label="' . esc_attr__( 'Filter by category', 'snopeak-events' ) . '" data-spk-select>';
        $html .= '<option value="' . esc_url( $none_url ) . '">' . esc_html__( 'All Categories', 'snopeak-events' ) . '</option>';
        foreach ( $opts as $slug => $name ) {
            $url   = self::build_url( $s['base_url'], $s['active_org'], $s['active_loc'], $slug, '' );
            $html .= '<option value="' . esc_url( $url ) . '"' . selected( $slug, $s['active_cat'], false ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    // -------------------------------------------------------------------------
    // [spk_upcoming_filter_pills]
    // -------------------------------------------------------------------------

    public static function sc_pills( $atts ) {
        self::enqueue();
        $s     = self::state();
        $extra = self::extra_class( $atts );

        $org_opts = self::get_organisers( $s['active_loc'], $s['active_cat'] );
        $loc_opts = self::get_locations(  $s['active_org'], $s['active_cat'] );
        $cat_opts = self::get_categories( $s['active_org'], $s['active_loc'] );

        $pills = array();

        if ( $s['active_org'] && isset( $org_opts[ $s['active_org'] ] ) ) {
            $pills[] = array(
                'label'      => $org_opts[ $s['active_org'] ],
                'remove_url' => self::build_url( $s['base_url'], 0, $s['active_loc'], $s['active_cat'], 'org' ),
            );
        }
        if ( $s['active_loc'] && isset( $loc_opts[ $s['active_loc'] ] ) ) {
            $pills[] = array(
                'label'      => $loc_opts[ $s['active_loc'] ],
                'remove_url' => self::build_url( $s['base_url'], $s['active_org'], 0, $s['active_cat'], 'loc' ),
            );
        }
        if ( $s['active_cat'] && isset( $cat_opts[ $s['active_cat'] ] ) ) {
            $pills[] = array(
                'label'      => $cat_opts[ $s['active_cat'] ],
                'remove_url' => self::build_url( $s['base_url'], $s['active_org'], $s['active_loc'], '', 'cat' ),
            );
        }

        if ( empty( $pills ) ) return '';

        $html = '';
        foreach ( $pills as $pill ) {
            $html .= '<span class="spk-filter__pill' . $extra . '">'
                . esc_html( $pill['label'] )
                . '<a href="' . esc_url( $pill['remove_url'] ) . '" class="spk-filter__pill-remove" aria-label="' . esc_attr( sprintf( __( 'Remove %s filter', 'snopeak-events' ), $pill['label'] ) ) . '">✕</a>'
                . '</span>';
        }
        return $html;
    }

    // -------------------------------------------------------------------------
    // [spk_upcoming_filter_clear]
    // Renders nothing if no filters are active.
    // -------------------------------------------------------------------------

    public static function sc_clear( $atts ) {
        self::enqueue();
        $s     = self::state();
        $extra = self::extra_class( $atts );

        if ( ! $s['active_org'] && ! $s['active_loc'] && ! $s['active_cat'] ) {
            return '';
        }

        $atts      = shortcode_atts( array( 'class' => '', 'label' => __( 'Clear filters', 'snopeak-events' ) ), $atts );
        $clear_url = $s['base_url']; // No year to preserve — bare base URL clears all

        return '<a href="' . esc_url( $clear_url ) . '" class="spk-filter__clear-all' . $extra . '">' . esc_html( $atts['label'] ) . '</a>';
    }

    // -------------------------------------------------------------------------
    // URL builder (no year param)
    // -------------------------------------------------------------------------

    private static function build_url( $base, $org, $loc, $cat, $remove ) {
        $args = array();
        if ( $org && $remove !== 'org' ) $args['org'] = $org;
        if ( $loc && $remove !== 'loc' ) $args['loc'] = $loc;
        if ( $cat && $remove !== 'cat' ) $args['cat'] = $cat;
        return $args ? add_query_arg( $args, $base ) : $base;
    }

    // -------------------------------------------------------------------------
    // Conditional option fetchers — upcoming events only
    // -------------------------------------------------------------------------

    private static function get_organisers( $loc, $cat ) {
        $key    = 'spk_ufilter_orgs_l' . $loc . '_c' . sanitize_key( $cat ) . '_' . gmdate( 'Ymd' );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) return $cached;
        $result = self::pluck_relationship( self::query_event_ids( 0, $loc, $cat ), 'event_organiser' );
        set_transient( $key, $result, self::CACHE_EXPIRY );
        return $result;
    }

    private static function get_locations( $org, $cat ) {
        $key    = 'spk_ufilter_locs_o' . $org . '_c' . sanitize_key( $cat ) . '_' . gmdate( 'Ymd' );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) return $cached;
        $result = self::pluck_relationship( self::query_event_ids( $org, 0, $cat ), 'event_location' );
        set_transient( $key, $result, self::CACHE_EXPIRY );
        return $result;
    }

    private static function get_categories( $org, $loc ) {
        $key    = 'spk_ufilter_cats_o' . $org . '_l' . $loc . '_' . gmdate( 'Ymd' );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) return $cached;
        $result = self::pluck_taxonomy( self::query_event_ids( $org, $loc, '' ), 'event-category' );
        set_transient( $key, $result, self::CACHE_EXPIRY );
        return $result;
    }

    // -------------------------------------------------------------------------
    // Core event ID query — upcoming/current events only (end_sort >= today)
    // -------------------------------------------------------------------------

    private static function query_event_ids( $org, $loc, $cat ) {
        $today      = (int) gmdate( 'Ymd', strtotime( current_time( 'Y-m-d' ) ) );
        $meta_query = array(
            array( 'key' => 'event_end_sort', 'value' => $today, 'compare' => '>=', 'type' => 'NUMERIC' ),
        );

        if ( $org > 0 ) {
            $meta_query[] = array( 'key' => 'event_organiser', 'value' => '"' . (int) $org . '"', 'compare' => 'LIKE' );
        }
        if ( $loc > 0 ) {
            $meta_query[] = array( 'key' => 'event_location', 'value' => '"' . (int) $loc . '"', 'compare' => 'LIKE' );
        }

        $args = array(
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => $meta_query,
        );

        if ( $cat !== '' ) {
            $args['tax_query'] = array(
                array( 'taxonomy' => 'event-category', 'field' => 'slug', 'terms' => $cat ),
            );
        }

        return (array) get_posts( $args );
    }

    // -------------------------------------------------------------------------
    // Extractors — identical to past filter bar
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
             WHERE option_name LIKE '_transient_spk_ufilter_%'
                OR option_name LIKE '_transient_timeout_spk_ufilter_%'"
        );
    }
}

SPK_Upcoming_Filter_Bar::register();

// Hook into save_post to bust upcoming filter caches
add_action( 'save_post_event', array( 'SPK_Upcoming_Filter_Bar', 'clear_option_caches' ) );
