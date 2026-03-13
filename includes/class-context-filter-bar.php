<?php
/**
 * Context Events Filter Bar
 *
 * Filter bar for event-organiser and location detail pages.
 * Tabs switch via ?tab=past / no param (upcoming default), so only one
 * event_months loop runs per page load. bl_get_event_month_data() reads
 * ?tab=past automatically — no separate Bricks query type needed.
 *
 * Shortcodes:
 *   [spk_ctx_tabs]             — upcoming / past tab links
 *   [spk_ctx_filter_year]      — year select (past tab only)
 *   [spk_ctx_filter_category]  — category select (both tabs)
 *   [spk_ctx_filter_cross]     — location select on org pages / organiser on loc pages
 *   [spk_ctx_filter_pills]     — active filter pills
 *   [spk_ctx_filter_clear]     — clear all (hidden when no filters active)
 *
 * All filter URLs preserve the active tab so navigation never flips the tab.
 * Reuses past-filter-bar.css / past-filter-bar.js.
 * BEM block: .spk-filter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPK_Context_Filter_Bar {

    const CACHE_EXPIRY = 43200; // 12h

    private static $state = null;

    public static function register() {
        add_shortcode( 'spk_ctx_tabs',            array( __CLASS__, 'sc_tabs'     ) );
        add_shortcode( 'spk_ctx_filter_year',     array( __CLASS__, 'sc_year'     ) );
        add_shortcode( 'spk_ctx_filter_category', array( __CLASS__, 'sc_category' ) );
        add_shortcode( 'spk_ctx_filter_cross',    array( __CLASS__, 'sc_cross'    ) );
        add_shortcode( 'spk_ctx_filter_pills',    array( __CLASS__, 'sc_pills'    ) );
        add_shortcode( 'spk_ctx_filter_clear',    array( __CLASS__, 'sc_clear'    ) );
    }

    // -------------------------------------------------------------------------
    // Shared state
    // -------------------------------------------------------------------------

    private static function state() {
        if ( self::$state !== null ) {
            return self::$state;
        }

        $path  = trim( parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
        $parts = explode( '/', $path );

        $context_type = '';
        $context_id   = 0;
        $context_meta = '';
        $cross_meta   = '';
        $cross_param  = '';
        $base_url     = '';

        $org_index = array_search( 'organisers', $parts );
        $loc_index = array_search( 'location', $parts );

        if ( $org_index !== false && isset( $parts[ $org_index + 1 ] ) ) {
            $post = get_page_by_path( sanitize_title( $parts[ $org_index + 1 ] ), OBJECT, 'event-organiser' );
            if ( $post ) {
                $context_type = 'org';
                $context_id   = $post->ID;
                $context_meta = 'event_organiser';
                $cross_meta   = 'event_location';
                $cross_param  = 'loc';
                $base_url     = get_permalink( $post->ID );
            }
        } elseif ( $loc_index !== false && isset( $parts[ $loc_index + 1 ] ) ) {
            $post = get_page_by_path( sanitize_title( $parts[ $loc_index + 1 ] ), OBJECT, 'location' );
            if ( $post ) {
                $context_type = 'loc';
                $context_id   = $post->ID;
                $context_meta = 'event_location';
                $cross_meta   = 'event_organiser';
                $cross_param  = 'org';
                $base_url     = get_permalink( $post->ID );
            }
        }

        if ( ! $context_type ) {
            self::$state = false;
            return false;
        }

        $is_past      = isset( $_GET['tab'] ) && sanitize_key( $_GET['tab'] ) === 'past';
        $active_year  = $is_past && isset( $_GET['year'] ) ? absint( $_GET['year'] ) : 0;
        $active_cat   = isset( $_GET['cat'] )              ? sanitize_key( $_GET['cat'] )  : '';
        $active_cross = isset( $_GET[ $cross_param ] )     ? absint( $_GET[ $cross_param ] )   : 0;

        // Validate — silently drop stale params
        if ( $active_cross ) {
            $cross_opts = self::get_cross_opts( $context_meta, $context_id, $cross_meta, $active_cat );
            if ( ! isset( $cross_opts[ $active_cross ] ) ) $active_cross = 0;
        }
        if ( $active_cat ) {
            $cat_opts = self::get_cat_opts( $context_meta, $context_id, $cross_meta, $active_cross );
            if ( ! isset( $cat_opts[ $active_cat ] ) ) $active_cat = '';
        }
        if ( $active_year ) {
            $years = bl_get_context_past_event_years( $context_meta, $context_id, $cross_meta, $active_cross, $active_cat );
            if ( ! in_array( $active_year, $years, true ) ) $active_year = 0;
        }

        self::$state = compact(
            'context_type', 'context_id', 'context_meta',
            'cross_meta', 'cross_param', 'base_url', 'is_past',
            'active_year', 'active_cat', 'active_cross'
        );
        return self::$state;
    }

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
    // [spk_ctx_tabs]
    // Preserves active cross/cat when switching tabs. Drops year on upcoming.
    // -------------------------------------------------------------------------

    public static function sc_tabs( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        $atts = shortcode_atts( array(
            'class'          => '',
            'upcoming_label' => __( 'Current Events', 'snopeak-events' ),
            'past_label'     => __( 'Past Events',    'snopeak-events' ),
        ), $atts );

        $extra = $atts['class'] ? ' ' . esc_attr( trim( $atts['class'] ) ) : '';

        $upcoming_url = self::build_url( $s, false, 0,              $s['active_cross'], $s['active_cat'] );
        $past_url     = self::build_url( $s, true,  $s['active_year'], $s['active_cross'], $s['active_cat'] );

        $upcoming_cls = ! $s['is_past'] ? ' spk-ctx-tabs__tab--active' : '';
        $past_cls     =   $s['is_past'] ? ' spk-ctx-tabs__tab--active' : '';

        return '<div class="spk-ctx-tabs' . $extra . '">'
            . '<a href="' . esc_url( $upcoming_url ) . '" class="spk-ctx-tabs__tab' . $upcoming_cls . '">' . esc_html( $atts['upcoming_label'] ) . '</a>'
            . '<a href="' . esc_url( $past_url )     . '" class="spk-ctx-tabs__tab' . $past_cls     . '">' . esc_html( $atts['past_label']     ) . '</a>'
            . '</div>';
    }

    // -------------------------------------------------------------------------
    // [spk_ctx_filter_year] — past tab only
    // -------------------------------------------------------------------------

    public static function sc_year( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s || ! $s['is_past'] ) return '';

        $extra = self::extra_class( $atts );
        $years = bl_get_context_past_event_years(
            $s['context_meta'], $s['context_id'],
            $s['cross_meta'],   $s['active_cross'],
            $s['active_cat']
        );

        if ( empty( $years ) ) return '';

        $active_cls = $s['active_year'] ? ' spk-filter__select--active' : '';
        $none_url   = self::build_url( $s, true, 0, $s['active_cross'], $s['active_cat'] );

        $html  = '<select class="spk-filter__select' . $active_cls . $extra . '" aria-label="' . esc_attr__( 'Filter by year', 'snopeak-events' ) . '" data-spk-select>';
        $html .= '<option value="' . esc_url( $none_url ) . '">' . esc_html__( 'All Years', 'snopeak-events' ) . '</option>';
        foreach ( $years as $year ) {
            $url   = self::build_url( $s, true, $year, $s['active_cross'], $s['active_cat'] );
            $html .= '<option value="' . esc_url( $url ) . '"' . selected( $year, $s['active_year'], false ) . '>' . esc_html( $year ) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    // -------------------------------------------------------------------------
    // [spk_ctx_filter_category]
    // -------------------------------------------------------------------------

    public static function sc_category( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        $extra = self::extra_class( $atts );
        $opts  = self::get_cat_opts( $s['context_meta'], $s['context_id'], $s['cross_meta'], $s['active_cross'] );

        $active_cls = $s['active_cat'] ? ' spk-filter__select--active' : '';
        $none_url   = self::build_url( $s, $s['is_past'], $s['active_year'], $s['active_cross'], '' );

        $html  = '<select class="spk-filter__select' . $active_cls . $extra . '" aria-label="' . esc_attr__( 'Filter by category', 'snopeak-events' ) . '" data-spk-select>';
        $html .= '<option value="' . esc_url( $none_url ) . '">' . esc_html__( 'All Categories', 'snopeak-events' ) . '</option>';
        foreach ( $opts as $slug => $name ) {
            $url   = self::build_url( $s, $s['is_past'], $s['active_year'], $s['active_cross'], $slug );
            $html .= '<option value="' . esc_url( $url ) . '"' . selected( $slug, $s['active_cat'], false ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    // -------------------------------------------------------------------------
    // [spk_ctx_filter_cross]
    // -------------------------------------------------------------------------

    public static function sc_cross( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        $extra = self::extra_class( $atts );
        $opts  = self::get_cross_opts( $s['context_meta'], $s['context_id'], $s['cross_meta'], $s['active_cat'] );

        $label      = ( $s['context_type'] === 'org' )
            ? esc_attr__( 'Filter by location', 'snopeak-events' )
            : esc_attr__( 'Filter by organiser', 'snopeak-events' );
        $none_label = ( $s['context_type'] === 'org' )
            ? esc_html__( 'All Locations', 'snopeak-events' )
            : esc_html__( 'All Organisers', 'snopeak-events' );

        $active_cls = $s['active_cross'] ? ' spk-filter__select--active' : '';
        $none_url   = self::build_url( $s, $s['is_past'], $s['active_year'], 0, $s['active_cat'] );

        $html  = '<select class="spk-filter__select' . $active_cls . $extra . '" aria-label="' . $label . '" data-spk-select>';
        $html .= '<option value="' . esc_url( $none_url ) . '">' . $none_label . '</option>';
        foreach ( $opts as $id => $name ) {
            $url   = self::build_url( $s, $s['is_past'], $s['active_year'], $id, $s['active_cat'] );
            $html .= '<option value="' . esc_url( $url ) . '"' . selected( $id, $s['active_cross'], false ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    // -------------------------------------------------------------------------
    // [spk_ctx_filter_pills]
    // -------------------------------------------------------------------------

    public static function sc_pills( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        $extra      = self::extra_class( $atts );
        $cross_opts = self::get_cross_opts( $s['context_meta'], $s['context_id'], $s['cross_meta'], $s['active_cat'] );
        $cat_opts   = self::get_cat_opts(   $s['context_meta'], $s['context_id'], $s['cross_meta'], $s['active_cross'] );

        $pills = array();

        if ( $s['is_past'] && $s['active_year'] ) {
            $pills[] = array(
                'label'      => (string) $s['active_year'],
                'remove_url' => self::build_url( $s, true, 0, $s['active_cross'], $s['active_cat'] ),
            );
        }
        if ( $s['active_cross'] && isset( $cross_opts[ $s['active_cross'] ] ) ) {
            $pills[] = array(
                'label'      => $cross_opts[ $s['active_cross'] ],
                'remove_url' => self::build_url( $s, $s['is_past'], $s['active_year'], 0, $s['active_cat'] ),
            );
        }
        if ( $s['active_cat'] && isset( $cat_opts[ $s['active_cat'] ] ) ) {
            $pills[] = array(
                'label'      => $cat_opts[ $s['active_cat'] ],
                'remove_url' => self::build_url( $s, $s['is_past'], $s['active_year'], $s['active_cross'], '' ),
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
    // [spk_ctx_filter_clear]
    // -------------------------------------------------------------------------

    public static function sc_clear( $atts ) {
        self::enqueue();
        $s = self::state();
        if ( ! $s ) return '';

        if ( ! $s['active_year'] && ! $s['active_cross'] && ! $s['active_cat'] ) {
            return '';
        }

        $extra = self::extra_class( $atts );
        $atts  = shortcode_atts( array( 'class' => '', 'label' => __( 'Clear filters', 'snopeak-events' ) ), $atts );

        // Keep the active tab, drop all filters
        $clear_url = $s['is_past']
            ? add_query_arg( 'tab', 'past', $s['base_url'] )
            : $s['base_url'];

        return '<a href="' . esc_url( $clear_url ) . '" class="spk-filter__clear-all' . $extra . '">'
            . esc_html( $atts['label'] )
            . '</a>';
    }

    // -------------------------------------------------------------------------
    // URL builder — always preserves the tab param
    // -------------------------------------------------------------------------

    private static function build_url( $s, $is_past, $year, $cross_id, $cat ) {
        $args = array();
        if ( $is_past )              $args['tab']               = 'past';
        if ( $year > 0 )             $args['year']              = $year;
        if ( $cross_id > 0 )         $args[ $s['cross_param'] ] = $cross_id;
        if ( $cat !== '' )           $args['cat']               = $cat;
        return $args ? add_query_arg( $args, $s['base_url'] ) : $s['base_url'];
    }

    // -------------------------------------------------------------------------
    // Option fetchers
    // -------------------------------------------------------------------------

    private static function get_cross_opts( $context_meta, $context_id, $cross_meta, $cat ) {
        $key    = 'spk_ctxf_cross_' . $context_meta . '_' . $context_id . '_c' . $cat . '_' . gmdate( 'Ymd' );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) return $cached;
        $ids    = self::query_event_ids( $context_meta, $context_id, 0, '', 0, $cat );
        $result = self::pluck_relationship( $ids, $cross_meta );
        set_transient( $key, $result, self::CACHE_EXPIRY );
        return $result;
    }

    private static function get_cat_opts( $context_meta, $context_id, $cross_meta, $cross_id ) {
        $key    = 'spk_ctxf_cats_' . $context_meta . '_' . $context_id . '_x' . $cross_id . '_' . gmdate( 'Ymd' );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) return $cached;
        $ids    = self::query_event_ids( $context_meta, $context_id, 0, $cross_meta, $cross_id, '' );
        $result = self::pluck_taxonomy( $ids, 'event-category' );
        set_transient( $key, $result, self::CACHE_EXPIRY );
        return $result;
    }

    // -------------------------------------------------------------------------
    // Core event ID query
    // -------------------------------------------------------------------------

    private static function query_event_ids( $context_meta, $context_id, $year, $cross_meta, $cross_id, $cat ) {
        $meta_query = array(
            spk_meta_clause( $context_meta, $context_id ),
        );
        if ( $year > 0 ) {
            $meta_query[] = array( 'key' => 'event_year', 'value' => $year, 'compare' => '=', 'type' => 'NUMERIC' );
        }
        if ( $cross_id > 0 && $cross_meta ) {
            $meta_query[] = spk_meta_clause( $cross_meta, $cross_id );
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
             WHERE option_name LIKE '_transient_spk_ctxf_%'
                OR option_name LIKE '_transient_timeout_spk_ctxf_%'
                OR option_name LIKE '_transient_spk_ctx_years_%'
                OR option_name LIKE '_transient_timeout_spk_ctx_years_%'"
        );
    }
}

SPK_Context_Filter_Bar::register();
add_action( 'save_post_event', array( 'SPK_Context_Filter_Bar', 'clear_option_caches' ) );
