<?php
/**
 * Location Event Count Shortcodes
 *
 * Provides per-location upcoming, past, and total event counts.
 *
 * Shortcodes:
 *   [spk_loc_upcoming id="123"]  — upcoming events at location
 *   [spk_loc_past     id="123"]  — past events at location
 *   [spk_loc_total    id="123"]  — total events at location
 *
 * `id` defaults to get_queried_object_id() so shortcodes work on
 * location single pages without an explicit attribute.
 *
 * Same query pattern as class-organiser-event-counts.php:
 *   Organiser filter: LIKE '"ID"' on event_location meta key.
 *   Date filter:      event_end_sort NUMERIC (Ymd integer).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Location_Event_Counts {

    const CACHE_EXPIRY = 43200; // 12 hours

    public static function register() {
        add_action( 'init', array( __CLASS__, 'init_shortcodes' ), 20 );
    }

    public static function init_shortcodes() {
        add_shortcode( 'spk_loc_upcoming', array( __CLASS__, 'upcoming' ) );
        add_shortcode( 'spk_loc_past',     array( __CLASS__, 'past'     ) );
        add_shortcode( 'spk_loc_total',    array( __CLASS__, 'total'    ) );
    }

    // -------------------------------------------------------------------------
    // Shortcode callbacks
    // -------------------------------------------------------------------------

    public static function upcoming( $atts ) {
        $atts  = self::parse_atts( $atts );
        $count = self::get_count( $atts['id'], 'upcoming' );
        return self::wrap( $count, 'loc-upcoming-count', $atts['wrap'] );
    }

    public static function past( $atts ) {
        $atts  = self::parse_atts( $atts );
        $count = self::get_count( $atts['id'], 'past' );
        return self::wrap( $count, 'loc-past-count', $atts['wrap'] );
    }

    public static function total( $atts ) {
        $atts  = self::parse_atts( $atts );
        $count = self::get_count( $atts['id'], 'total' );
        return self::wrap( $count, 'loc-total-count', $atts['wrap'] );
    }

    // -------------------------------------------------------------------------
    // Core counting logic
    // -------------------------------------------------------------------------

    private static function get_count( $location_id, $type ) {
        $location_id = absint( $location_id );

        if ( ! $location_id ) {
            return 0;
        }

        $cache_key = 'spk_loc_count_' . $location_id . '_' . $type;
        $cached    = get_transient( $cache_key );

        if ( $cached !== false ) {
            return (int) $cached;
        }

        $count = self::query_count( $location_id, $type );

        set_transient( $cache_key, $count, self::CACHE_EXPIRY );

        return $count;
    }

    private static function query_count( $location_id, $type ) {
        $meta_query = array(
            array(
                'key'     => 'event_location',
                'value'   => '"' . (int) $location_id . '"',
                'compare' => 'LIKE',
            ),
        );

        if ( 'total' !== $type ) {
            $today = (int) gmdate( 'Ymd', strtotime( current_time( 'Y-m-d' ) ) );

            $meta_query[] = array(
                'key'     => 'event_end_sort',
                'value'   => $today,
                'compare' => ( 'upcoming' === $type ) ? '>=' : '<',
                'type'    => 'NUMERIC',
            );
        }

        $query = new WP_Query( array(
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'meta_query'     => $meta_query,
        ) );

        return (int) $query->found_posts;
    }

    // -------------------------------------------------------------------------
    // Cache invalidation
    // -------------------------------------------------------------------------

    public static function clear_cache_for_event( $post_id ) {
        if ( ! function_exists( 'get_field' ) ) {
            return;
        }

        $locations = get_field( 'event_location', $post_id );

        if ( empty( $locations ) || ! is_array( $locations ) ) {
            return;
        }

        foreach ( $locations as $loc ) {
            $loc_id = is_object( $loc ) ? $loc->ID : absint( $loc );
            self::clear_cache_for_location( $loc_id );
        }
    }

    public static function clear_cache_for_location( $location_id ) {
        $location_id = absint( $location_id );
        if ( ! $location_id ) {
            return;
        }

        delete_transient( 'spk_loc_count_' . $location_id . '_upcoming' );
        delete_transient( 'spk_loc_count_' . $location_id . '_past'     );
        delete_transient( 'spk_loc_count_' . $location_id . '_total'    );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function parse_atts( $atts ) {
        return shortcode_atts(
            array(
                'id'   => self::get_location_id(),
                'wrap' => 'span',
            ),
            $atts
        );
    }

    /**
     * Parse location ID from the request URI.
     * Bricks returns the template post ID from get_queried_object_id(),
     * so we fall back to URL slug parsing.
     */
    private static function get_location_id() {
        $path      = trim( parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
        $parts     = explode( '/', $path );
        $loc_index = array_search( 'location', $parts );

        if ( $loc_index !== false && isset( $parts[ $loc_index + 1 ] ) ) {
            $post = get_page_by_path( sanitize_title( $parts[ $loc_index + 1 ] ), OBJECT, 'location' );
            if ( $post ) {
                return $post->ID;
            }
        }

        // Fallback for non-Bricks or direct use with id="" attribute
        return get_queried_object_id();
    }

    private static function wrap( $count, $class, $tag = 'span' ) {
        $tag = tag_escape( $tag );
        return '<' . $tag . ' class="spk-count ' . esc_attr( $class ) . '">'
            . (int) $count
            . '</' . $tag . '>';
    }
}

Location_Event_Counts::register();
