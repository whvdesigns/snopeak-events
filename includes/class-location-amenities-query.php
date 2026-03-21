<?php
/**
 * Location Amenities Query
 *
 * Registers a custom Bricks query type "Location Amenities" that loops over
 * the ammenities ACF repeater on the location linked to the current event.
 *
 * Usage in Bricks:
 *   1. Add a loop container to your event detail template
 *   2. Set Query → Type → "Location Amenities"
 *   3. Inside the loop, use a Code element:
 *        echo spk_get_current_amenity_type();
 *      Or use the dynamic tag: {spk_amenity_type}
 *
 * Works on:
 *   - Event detail pages  (reads event_location → ammenities)
 *   - Location detail pages (reads ammenities directly from the current post)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPK_Location_Amenities_Query {

    public static function register() {
        add_action( 'after_setup_theme', array( __CLASS__, 'init' ), 11 );
    }

    public static function init() {
        // Register query type in the Bricks dropdown
        add_filter( 'bricks/setup/control_options', array( __CLASS__, 'register_query_type' ) );

        // Supply the loop data
        add_filter( 'bricks/query/run', array( __CLASS__, 'run_query' ), 10, 2 );

        // Expose helper function to Bricks Code elements
        add_filter( 'bricks/code/echo_function_names', array( __CLASS__, 'register_functions' ) );

        // Dynamic tag {spk_amenity_type}
        add_filter( 'bricks/dynamic_tags_list', array( __CLASS__, 'register_tag' ) );
        add_filter( 'bricks/dynamic_data/render_tag', array( __CLASS__, 'render_tag' ), 10, 3 );
        add_filter( 'bricks/dynamic_data/render_content', array( __CLASS__, 'render_content' ), 10, 3 );
    }

    // -------------------------------------------------------------------------
    // Query registration
    // -------------------------------------------------------------------------

    public static function register_query_type( $opts ) {
        if ( ! is_array( $opts ) ) {
            $opts = array();
        }
        if ( ! isset( $opts['queryTypes'] ) || ! is_array( $opts['queryTypes'] ) ) {
            $opts['queryTypes'] = array();
        }

        $opts['queryTypes']['location_amenities'] = 'Location Amenities';

        return $opts;
    }

    public static function run_query( $results, $query_obj ) {
        if ( ! is_object( $query_obj ) || ! isset( $query_obj->object_type ) ) {
            return $results;
        }

        if ( $query_obj->object_type !== 'location_amenities' ) {
            return $results;
        }

        return self::get_amenity_types();
    }

    // -------------------------------------------------------------------------
    // Data helper
    // -------------------------------------------------------------------------

    /**
     * Return an array of amenity_type strings for the relevant location.
     * Works from an event page (reads linked location) or a location page directly.
     *
     * @return string[]
     */
    public static function get_amenity_types() {
        $location_id = self::resolve_location_id();

        if ( ! $location_id ) {
            return array();
        }

        $rows = get_field( 'ammenities', $location_id );

        if ( empty( $rows ) || ! is_array( $rows ) ) {
            return array();
        }

        $types = array();
        foreach ( $rows as $row ) {
            if ( ! empty( $row['amenity_type'] ) ) {
                $types[] = $row['amenity_type'];
            }
        }

        return $types;
    }

    /**
     * Resolve the location post ID from the current context.
     * Handles event detail pages and location detail pages.
     *
     * @return int|0
     */
    private static function resolve_location_id() {
        // --- Try to determine the real post from the URL ---
        // (Bricks templates override get_queried_object_id with the template ID)
        $path  = trim( parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
        $parts = explode( '/', $path );

        // Event detail page: /events/{slug}/
        $event_index = array_search( 'events', $parts );
        if ( $event_index !== false && isset( $parts[ $event_index + 1 ] ) ) {
            $slug  = sanitize_title( $parts[ $event_index + 1 ] );
            $event = get_page_by_path( $slug, OBJECT, 'event' );
            if ( $event ) {
                return self::location_id_from_event( $event->ID );
            }
        }

        // Location detail page: /location/{slug}/
        $loc_index = array_search( 'location', $parts );
        if ( $loc_index !== false && isset( $parts[ $loc_index + 1 ] ) ) {
            $slug = sanitize_title( $parts[ $loc_index + 1 ] );
            $loc  = get_page_by_path( $slug, OBJECT, 'location' );
            if ( $loc ) {
                return $loc->ID;
            }
        }

        // Fallback: use queried object
        $queried_id   = get_queried_object_id();
        $queried_type = get_post_type( $queried_id );

        if ( $queried_type === 'location' ) {
            return $queried_id;
        }

        if ( $queried_type === 'event' ) {
            return self::location_id_from_event( $queried_id );
        }

        // Last resort: global $post
        global $post;
        if ( $post ) {
            if ( get_post_type( $post->ID ) === 'location' ) {
                return $post->ID;
            }
            if ( get_post_type( $post->ID ) === 'event' ) {
                return self::location_id_from_event( $post->ID );
            }
        }

        return 0;
    }

    /**
     * Get the first linked location ID from an event.
     *
     * @param int $event_id
     * @return int|0
     */
    private static function location_id_from_event( $event_id ) {
        $locations = get_field( 'event_location', $event_id );

        if ( empty( $locations ) || ! is_array( $locations ) ) {
            return 0;
        }

        $first = $locations[0];
        return is_object( $first ) ? $first->ID : absint( $first );
    }

    // -------------------------------------------------------------------------
    // Helper function for Code elements
    // -------------------------------------------------------------------------

    public static function register_functions( $functions ) {
        if ( ! is_array( $functions ) ) {
            $functions = array();
        }

        return array_merge( $functions, array( 'spk_get_current_amenity_type' ) );
    }

    // -------------------------------------------------------------------------
    // Dynamic tag {spk_amenity_type}
    // -------------------------------------------------------------------------

    public static function register_tag( $tags ) {
        $tags[] = array(
            'name'  => '{spk_amenity_type}',
            'label' => 'Amenity Type',
            'group' => 'SnoPeak',
        );
        return $tags;
    }

    public static function render_tag( $tag, $post, $context ) {
        if ( $tag !== 'spk_amenity_type' ) {
            return $tag;
        }
        return spk_get_current_amenity_type();
    }

    public static function render_content( $content, $post, $context ) {
        if ( strpos( $content, '{spk_amenity_type}' ) === false ) {
            return $content;
        }
        return str_replace( '{spk_amenity_type}', spk_get_current_amenity_type(), $content );
    }

    /**
     * Public alias for resolve_location_id() — used by dynamic tag callbacks outside the class.
     */
    public static function get_location_id_public() {
        return self::resolve_location_id();
    }
}

// -------------------------------------------------------------------------
// Global helper — use inside the loop via Code element or dynamic tag
// -------------------------------------------------------------------------

/**
 * Returns the amenity type string for the current loop iteration.
 *
 * @return string e.g. "Parking", "Toilets"
 */
function spk_get_current_amenity_type() {
    if ( class_exists( '\\Bricks\\Query' ) ) {
        $loop_id = \Bricks\Query::is_any_looping();
        if ( $loop_id ) {
            $type = \Bricks\Query::get_query_object_type( $loop_id );
            if ( $type === 'location_amenities' ) {
                return esc_html( \Bricks\Query::get_loop_object( $loop_id ) );
            }
        }
    }
    return '';
}

// -----------------------------------------------------------------------------
// Bricks dynamic data tag: {spk_loc_amenity_count}
//
// Returns the number of amenity rows for the current location.
// Use in Bricks element conditions to hide the amenities section when
// the location has no amenities data.
//
// Example condition: Hide element if {spk_loc_amenity_count} = 0
// -----------------------------------------------------------------------------

add_filter( 'bricks/dynamic_tags_list', function ( $tags ) {
    $tags[] = array(
        'name'  => '{spk_loc_amenity_count}',
        'label' => 'Location Amenity Count',
        'group' => 'SnoPeak Events',
    );
    return $tags;
} );

add_filter( 'bricks/dynamic_data/render_tag', function ( $tag, $post, $context ) {
    if ( $tag !== 'spk_loc_amenity_count' ) {
        return $tag;
    }

    $location_id = SPK_Location_Amenities_Query::get_location_id_public();

    if ( ! $location_id ) {
        return '0';
    }

    $rows = get_field( 'ammenities', $location_id );
    return (string) ( is_array( $rows ) ? count( $rows ) : 0 );
}, 10, 3 );

add_filter( 'bricks/dynamic_data/render_content', function ( $content, $post, $context ) {
    if ( strpos( $content, '{spk_loc_amenity_count}' ) === false ) {
        return $content;
    }

    $location_id = SPK_Location_Amenities_Query::get_location_id_public();
    $rows        = $location_id ? get_field( 'ammenities', $location_id ) : array();

    return str_replace( '{spk_loc_amenity_count}', (string) ( is_array( $rows ) ? count( $rows ) : 0 ), $content );
}, 10, 3 );
