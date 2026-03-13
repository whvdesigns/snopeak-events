<?php
/**
 * Event Month Query Class
 * 
 * Custom Bricks Builder query for grouping events by month/year
 * Includes helper functions for displaying month data
 * Caches results for performance
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get event month data with caching
 * 
 * @return array Array containing [month_keys, month_post_ids]
 */
/**
 * Return a LIKE meta clause for ACF relationship fields.
 * Both event_location and event_organiser store serialised arrays.
 */
function spk_meta_clause( $key, $id ) {
    return array( 'key' => $key, 'value' => '"' . (int) $id . '"', 'compare' => 'LIKE' );
}

function bl_get_event_month_data() {
    $months = array();
    $month_post_ids = array();
    $today = (int) gmdate('Ymd', strtotime(current_time('Y-m-d')));

    // Determine if we're in "past" mode.
    // Main past page: detected by URL path.
    // Org/location detail pages: detected by ?tab=past param.
    global $wp;
    $current_url = home_url($wp->request);
    $is_past = ( strpos($current_url, '/events/past') !== false )
            || ( isset( $_GET['tab'] ) && sanitize_key( $_GET['tab'] ) === 'past' );

    // Detect organiser or location context from URL slug
    // Bricks overrides get_queried_object() with the template, so parse from URL
    $path   = trim( parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
    $parts  = explode( '/', $path );
    $context_meta_clause = null;
    $context_key = '';

    $org_index = array_search( 'organisers', $parts );
    $loc_index  = array_search( 'location', $parts );

    if ( $org_index !== false && isset( $parts[ $org_index + 1 ] ) ) {
        $post = get_page_by_path( sanitize_title( $parts[ $org_index + 1 ] ), OBJECT, 'event-organiser' );
        if ( $post ) {
            $context_meta_clause = array(
                'key'     => 'event_organiser',
                'value'   => $post->ID,
                'compare' => 'LIKE',
            );
            $context_key = 'org_' . $post->ID;
        }
    } elseif ( $loc_index !== false && isset( $parts[ $loc_index + 1 ] ) ) {
        $post = get_page_by_path( sanitize_title( $parts[ $loc_index + 1 ] ), OBJECT, 'location' );
        if ( $post ) {
            $context_meta_clause = array(
                'key'     => 'event_location',
                'value'   => '"' . $post->ID . '"',
                'compare' => 'LIKE',
            );
            $context_key = 'loc_' . $post->ID;
        }
    }

    // Category taxonomy context — /event-category/{slug}/
    // Handled separately: uses tax_query (not meta), and has two cross-dim meta filters (org + loc).
    $context_cat_slug = '';
    $cat_index = array_search( 'event-category', $parts );
    if ( ! $context_key && $cat_index !== false && isset( $parts[ $cat_index + 1 ] ) ) {
        $term = get_term_by( 'slug', sanitize_title( $parts[ $cat_index + 1 ] ), 'event-category' );
        if ( $term && ! is_wp_error( $term ) ) {
            $context_cat_slug = $term->slug;
            $context_key      = 'cat_' . $term->slug;
        }
    }


    // Cross-dimension param for context pages: org page uses ?loc=, loc page uses ?org=
    // Category context uses both ?org= and ?loc= (handled separately below).
    $ctx_cross_param = '';
    $ctx_cross_meta  = '';
    if ( $context_key && ! $context_cat_slug ) {
        if ( strpos( $context_key, 'org_' ) === 0 ) {
            $ctx_cross_param = 'loc';
            $ctx_cross_meta  = 'event_location';
        } else {
            $ctx_cross_param = 'org';
            $ctx_cross_meta  = 'event_organiser';
        }
    }

    // Get active year filter (past page only)
    $year_filter     = 0;
    $org_filter      = 0;  // post ID
    $loc_filter      = 0;  // post ID
    $cat_filter      = ''; // taxonomy slug

    if ($is_past) {
        $requested = isset($_GET['year']) ? absint( $_GET['year'] ) : 0;
        if ($requested > 0) {
            $year_filter = $requested;
        } elseif ( ! $context_key ) {
            // Only default to most recent year on the main /events/past/ page.
            // Context pages (org/loc detail) show all past years when none is selected.
            $years = bl_get_past_event_years();
            $year_filter = !empty($years) ? (int) $years[0] : (int) gmdate('Y');
        }

        $org_filter = isset( $_GET['org'] ) ? absint( $_GET['org'] ) : 0;
        $loc_filter = isset( $_GET['loc'] )  ? absint( $_GET['loc'] )  : 0;
        $cat_filter = isset( $_GET['cat'] )  ? sanitize_key( $_GET['cat'] ) : '';
    }

    // Apply org/loc/cat filters on the upcoming page too.
    // Only when there is no organiser/location context (those pages handle their
    // own filtering via $context_meta_clause).
    $upcoming_org_filter = 0;
    $upcoming_loc_filter = 0;
    $upcoming_cat_filter = '';
    if ( ! $is_past && ( ! $context_meta_clause || $context_cat_slug ) ) {
        $upcoming_org_filter = isset( $_GET['org'] ) ? absint( $_GET['org'] ) : 0;
        $upcoming_loc_filter = isset( $_GET['loc'] ) ? absint( $_GET['loc'] ) : 0;
        // On cat context page ?cat= is the context itself, not a filter
        if ( ! $context_cat_slug ) {
            $upcoming_cat_filter = isset( $_GET['cat'] ) ? sanitize_key( $_GET['cat'] ) : '';
        }

        // On category context pages validate org/loc against the pool so that a
        // stale or leaked ?org= / ?loc= param (e.g. carried over from an organiser
        // or event detail page) doesn't silently filter the loop while the filter
        // bar's own validation has already dropped the param from the dropdown.
        if ( $context_cat_slug && ( $upcoming_org_filter || $upcoming_loc_filter ) ) {
            $pool_ids = SPK_Category_Filter_Bar::get_upcoming_ids_public( $context_cat_slug );
            if ( $upcoming_org_filter ) {
                $org_opts = array();
                foreach ( $pool_ids as $pid ) {
                    $raw = get_post_meta( $pid, 'event_organiser', true );
                    if ( empty( $raw ) ) continue;
                    if ( is_string( $raw ) ) $raw = maybe_unserialize( $raw );
                    if ( is_array( $raw ) ) {
                        foreach ( $raw as $item ) {
                            $org_opts[] = is_object( $item ) ? (int) $item->ID : (int) $item;
                        }
                    }
                }
                if ( ! in_array( $upcoming_org_filter, $org_opts, true ) ) {
                    $upcoming_org_filter = 0;
                }
            }
            if ( $upcoming_loc_filter ) {
                $loc_opts = array();
                foreach ( $pool_ids as $pid ) {
                    $raw = get_post_meta( $pid, 'event_location', true );
                    if ( empty( $raw ) ) continue;
                    if ( is_string( $raw ) ) $raw = maybe_unserialize( $raw );
                    if ( is_array( $raw ) ) {
                        foreach ( $raw as $item ) {
                            $loc_opts[] = is_object( $item ) ? (int) $item->ID : (int) $item;
                        }
                    }
                }
                if ( ! in_array( $upcoming_loc_filter, $loc_opts, true ) ) {
                    $upcoming_loc_filter = 0;
                }
            }
        }
    }

    // Cache key includes context so organiser/location/category pages cache separately
    if ( $context_key ) {
        if ( $context_cat_slug ) {
            // Category context: cross-dim filters are org + loc
            $ctx_org_key = isset( $_GET['org'] ) ? absint( $_GET['org'] ) : 0;
            $ctx_loc_key = isset( $_GET['loc'] ) ? absint( $_GET['loc'] ) : 0;
            $cache_key = 'spk_event_months_' . $context_key
                . ( $is_past ? '_past' . ( $year_filter ? '_y' . $year_filter : '' ) : '_future_' . gmdate( 'Ymd' ) )
                . '_o' . $ctx_org_key
                . '_l' . $ctx_loc_key;
        } else {
            $ctx_cross_id_key = $ctx_cross_param ? ( isset( $_GET[ $ctx_cross_param ] ) ? absint( $_GET[ $ctx_cross_param ] ) : 0 ) : 0;
            $ctx_cat_key      = isset( $_GET['cat'] ) ? sanitize_key( $_GET['cat'] ) : '';
            $cache_key = 'spk_event_months_' . $context_key
                . ( $is_past ? '_past' . ( $year_filter ? '_y' . $year_filter : '' ) : '_future_' . gmdate( 'Ymd' ) )
                . '_x' . $ctx_cross_id_key
                . '_c' . $ctx_cat_key;
        }
    } else {
        $filter_suffix = $is_past
            ? '_past_' . $year_filter . '_o' . $org_filter . '_l' . $loc_filter . '_c' . $cat_filter
            : '_future_' . gmdate( 'Ymd' ) . '_o' . $upcoming_org_filter . '_l' . $upcoming_loc_filter . '_c' . $upcoming_cat_filter;
        $cache_key = 'spk_event_months' . $filter_suffix;
    }

    $cached = get_transient($cache_key);
    if ($cached && is_array($cached) && isset($cached[0], $cached[1])) {
        return $cached;
    }

    // Build meta query
    $meta_query = array(
        array('key' => 'event_year', 'compare' => 'EXISTS'),
        array('key' => 'event_month', 'compare' => 'EXISTS')
    );

    // Add organiser/location filter if in context
    if ( $context_meta_clause ) {
        $meta_query[] = $context_meta_clause;
    }

    if ($is_past) {
        $meta_query[] = array(
            'key'     => 'event_end_sort',
            'value'   => $today,
            'compare' => '<',
            'type'    => 'NUMERIC',
        );
        if ($year_filter > 0) {
            $meta_query[] = array(
                'key'     => 'event_year',
                'value'   => $year_filter,
                'compare' => '=',
                'type'    => 'NUMERIC',
            );
        }
        // Organiser filter — ACF relationship fields store serialised arrays, use LIKE
        if ($org_filter > 0) {
            $meta_query[] = array(
                'key'     => 'event_organiser',
                'value'   => '"' . $org_filter . '"',
                'compare' => 'LIKE',
            );
        }
        // Location filter
        if ($loc_filter > 0) {
            $meta_query[] = array(
                'key'     => 'event_location',
                'value'   => '"' . $loc_filter . '"',
                'compare' => 'LIKE',
            );
        }
        $order = 'DESC';
    } else {
        $meta_query[] = array(
            'key'     => 'event_end_sort',
            'value'   => $today,
            'compare' => '>=',
            'type'    => 'NUMERIC',
        );
        $order = 'ASC';
        if ( $context_key && $ctx_cross_param && ! $context_cat_slug ) {
            // Org/location context page upcoming loop — apply single cross-dim filter if present
            $ctx_cross_id_upcoming = isset( $_GET[ $ctx_cross_param ] ) ? absint( $_GET[ $ctx_cross_param ] ) : 0;
            if ( $ctx_cross_id_upcoming > 0 ) {
                $meta_query[] = array(
                    'key'     => $ctx_cross_meta,
                    'value'   => '"' . $ctx_cross_id_upcoming . '"',
                    'compare' => 'LIKE',
                );
            }
        } else {
            // Main upcoming page OR category context page (both use ?org= and ?loc=)
            if ( $upcoming_org_filter > 0 ) {
                $meta_query[] = array(
                    'key'     => 'event_organiser',
                    'value'   => '"' . $upcoming_org_filter . '"',
                    'compare' => 'LIKE',
                );
            }
            if ( $upcoming_loc_filter > 0 ) {
                $meta_query[] = array(
                    'key'     => 'event_location',
                    'value'   => '"' . $upcoming_loc_filter . '"',
                    'compare' => 'LIKE',
                );
            }
        }
    }

    $tax_query = array();

    // Category context — the category itself is always applied as a tax_query
    if ( $context_cat_slug ) {
        $tax_query[] = array(
            'taxonomy' => 'event-category',
            'field'    => 'slug',
            'terms'    => $context_cat_slug,
        );
    } elseif ( $is_past && $cat_filter !== '' ) {
        // Past section (main page or context past loop — $cat_filter read from $_GET above)
        $tax_query[] = array(
            'taxonomy' => 'event-category',
            'field'    => 'slug',
            'terms'    => $cat_filter,
        );
    } elseif ( ! $is_past && $context_key && ! $context_cat_slug ) {
        // Org/location context page upcoming loop — read cat directly from $_GET
        $ctx_upcoming_cat = isset( $_GET['cat'] ) ? sanitize_key( $_GET['cat'] ) : '';
        if ( $ctx_upcoming_cat !== '' ) {
            $tax_query[] = array(
                'taxonomy' => 'event-category',
                'field'    => 'slug',
                'terms'    => $ctx_upcoming_cat,
            );
        }
    } elseif ( ! $is_past && $upcoming_cat_filter !== '' ) {
        // Main upcoming page
        $tax_query[] = array(
            'taxonomy' => 'event-category',
            'field'    => 'slug',
            'terms'    => $upcoming_cat_filter,
        );
    }

    // Query events
    $query_args = array(
        'post_type'      => 'event',
        'posts_per_page' => -1,
        'meta_key'       => 'event_start_sort',
        'orderby'        => 'meta_value_num',
        'order'          => $order,
        'meta_query'     => $meta_query,
    );

    if ( ! empty( $tax_query ) ) {
        $query_args['tax_query'] = $tax_query;
    }

    $posts = get_posts( $query_args );

    // Group by month
    if (!empty($posts)) {
        foreach ($posts as $post) {
            $year = get_post_meta($post->ID, 'event_year', true);
            $month = get_post_meta($post->ID, 'event_month', true);

            if (empty($year) || empty($month)) {
                continue;
            }

            // Create month key (YYYY-MM format)
            $key = sprintf('%04d-%02d', (int) $year, (int) $month);
            $months[] = $key;

            if (!isset($month_post_ids[$key])) {
                $month_post_ids[$key] = array();
            }
            
            $month_post_ids[$key][] = (int) $post->ID;
        }
    }

    // Unique and sort months
    $months = array_values(array_unique($months));
    if ($is_past) {
        rsort($months, SORT_STRING);
    } else {
        sort($months, SORT_STRING);
    }

    $data = array($months, $month_post_ids);
    
    // Only cache non-empty results — empty arrays from bad queries shouldn't be persisted
    if ( ! empty( $months ) ) {
        set_transient($cache_key, $data, 6 * HOUR_IN_SECONDS);
    }

    return $data;
}

/**
 * Get current looping month key (e.g., "2025-11")
 * 
 * @return string Month key in YYYY-MM format
 */
function bl_get_event_month() {
    if ( class_exists( '\Bricks\Query' ) ) {
        $id   = \Bricks\Query::is_any_looping();
        $type = $id ? \Bricks\Query::get_query_object_type( $id ) : '';
        if ( $id && ( $type === 'event_months' || $type === 'related_event_months' ) ) {
            return \Bricks\Query::get_loop_object( $id );
        }
    }

    return gmdate('Y-m');
}

/**
 * Get formatted month title (e.g., "November 2025")
 * 
 * @return string Formatted month and year
 */
function bl_get_event_month_title() {
    $key = bl_get_event_month();
    
    if (empty($key)) {
        return '';
    }

    $parts = explode('-', $key);
    $year = isset($parts[0]) ? (int) $parts[0] : (int) gmdate('Y');
    $month = isset($parts[1]) ? (int) $parts[1] : 1;

    return esc_html(date_i18n('F Y', mktime(0, 0, 0, $month, 10, $year)));
}

/**
 * Register Bricks integration
 */
class Event_Month_Query {

    /**
     * Register hooks
     */
    public static function register() {
        add_action('after_setup_theme', array(__CLASS__, 'init'), 11);
    }

    /**
     * Initialize Bricks integration
     */
    public static function init() {
        // Register query type
        add_filter('bricks/setup/control_options', array(__CLASS__, 'register_query_type'));

        // Run query
        add_filter('bricks/query/run', array(__CLASS__, 'run_query'), 10, 2);

        // Expose helper functions
        add_filter('bricks/code/echo_function_names', array(__CLASS__, 'register_functions'));
    }

    /**
     * Register custom query type
     * 
     * @param array $opts Options array
     * @return array Modified options
     */
    public static function register_query_type($opts) {
        if (!is_array($opts)) {
            $opts = array();
        }

        if (!isset($opts['queryTypes']) || !is_array($opts['queryTypes'])) {
            $opts['queryTypes'] = array();
        }

        $opts['queryTypes']['event_months'] = 'Event Months';
        $opts['queryTypes']['related_event_months'] = 'Related Event Months';

        return $opts;
    }

    /**
     * Run the custom query
     * 
     * @param mixed $results Query results
     * @param object $query_obj Query object
     * @return mixed Modified results
     */
    public static function run_query($results, $query_obj) {
        if (!is_object($query_obj) || !isset($query_obj->object_type)) {
            return $results;
        }

        if ( $query_obj->object_type === 'related_event_months' ) {
            $data = bl_get_related_event_month_data();
            return is_array($data) ? $data[0] : $results;
        }

        if ( $query_obj->object_type !== 'event_months' ) {
            return $results;
        }

        $data = bl_get_event_month_data();
        return is_array($data) ? $data[0] : $results;
    }

    /**
     * Register helper functions for Bricks
     * 
     * @param array $functions Existing functions
     * @return array Modified functions
     */
    public static function register_functions($functions) {
        if (!is_array($functions)) {
            $functions = array();
        }

        return array_merge(
            $functions,
            array(
                'bl_get_event_month',
                'bl_get_event_month_data',
                'bl_get_event_month_title',
                'bl_get_past_event_years',
                'bl_get_context_past_event_years',
                'bl_get_related_event_month_data',
            )
        );
    }
}

/**
 * Get distinct years that have past events, ordered DESC
 * Uses a direct DB query for efficiency — avoids loading all 1700+ event IDs.
 *
 * @return array e.g. [2026, 2025, 1990]
 */
function bl_get_past_event_years() {
    $cached = get_transient('spk_past_event_years');
    if (is_array($cached)) {
        return $cached;
    }

    global $wpdb;

    $today = (int) gmdate('Ymd', strtotime(current_time('Y-m-d')));

    $years = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT CAST(pm_year.meta_value AS UNSIGNED) as yr
         FROM {$wpdb->postmeta} pm_end
         INNER JOIN {$wpdb->postmeta} pm_year
             ON pm_year.post_id = pm_end.post_id
            AND pm_year.meta_key = 'event_year'
         INNER JOIN {$wpdb->posts} p
             ON p.ID = pm_end.post_id
            AND p.post_status = 'publish'
            AND p.post_type = 'event'
         WHERE pm_end.meta_key = 'event_end_sort'
           AND CAST(pm_end.meta_value AS UNSIGNED) < %d
           AND pm_year.meta_value != ''
         ORDER BY yr DESC",
        $today
    ) );

    $years = array_map('intval', $years);

    set_transient('spk_past_event_years', $years, 6 * HOUR_IN_SECONDS);

    return $years;
}

/**
 * Year dropdown shortcode for past events page
 * Shortcode: [spk_year_tabs]
 *
 * Outputs a <select> that navigates to /events/past/?year=YYYY on change.
 */
function spk_year_tabs_shortcode() {
    $years = bl_get_past_event_years();

    if (empty($years)) {
        return '';
    }

    $requested   = isset($_GET['year']) ? absint( $_GET['year'] ) : 0;
    $active_year = ($requested > 0 && in_array($requested, $years)) ? $requested : $years[0];

    $past_page = get_page_by_path('events/past');
    $base_url  = $past_page ? get_permalink($past_page) : home_url('/events/past/');

    $html  = '<select class="spk-year-select" onchange="sessionStorage.setItem(\'spk_scroll\', window.scrollY); window.location.href=this.value">';
    foreach ($years as $year) {
        $url      = esc_url( add_query_arg('year', $year, $base_url) );
        $selected = selected($year, $active_year, false);
        $html    .= sprintf('<option value="%s"%s>%s</option>', $url, $selected, esc_html($year));
    }
    $html .= '</select>';

    if ($requested > 0) {
        $html .= '<script>
        var pos = sessionStorage.getItem("spk_scroll");
        if (pos) { sessionStorage.removeItem("spk_scroll"); window.scrollTo(0, parseInt(pos)); }
        </script>';
    }

    return $html;
}
add_shortcode('spk_year_tabs', 'spk_year_tabs_shortcode');

/**
 * Get distinct past-event years for a specific context entity (org or location),
 * optionally filtered by cross-dimension and/or category.
 *
 * Used by the context filter bar year dropdown.
 *
 * @param string $context_meta  Meta key for the context entity (e.g. 'event_organiser')
 * @param int    $context_id    Post ID of the context entity
 * @param string $cross_meta    Meta key for the cross-dim filter (e.g. 'event_location'), or ''
 * @param int    $cross_id      Post ID of the cross-dim filter, or 0
 * @param string $cat           Event-category taxonomy slug, or ''
 * @return int[]  Distinct years DESC, e.g. [2026, 2025, 2024]
 */
function bl_get_context_past_event_years( $context_meta, $context_id, $cross_meta = '', $cross_id = 0, $cat = '' ) {
    $cache_key = 'spk_ctx_years_' . md5( $context_meta . '_' . $context_id . '_' . $cross_meta . '_' . $cross_id . '_' . $cat );
    $cached    = get_transient( $cache_key );
    if ( is_array( $cached ) ) return $cached;

    $today      = (int) gmdate( 'Ymd', strtotime( current_time( 'Y-m-d' ) ) );
    $meta_query = array(
        array( 'key' => 'event_end_sort', 'value' => $today, 'compare' => '<', 'type' => 'NUMERIC' ),
        spk_meta_clause( $context_meta, $context_id ),
    );
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

    $ids   = (array) get_posts( $args );
    $years = array();
    foreach ( $ids as $id ) {
        $y = (int) get_post_meta( $id, 'event_year', true );
        if ( $y > 0 ) $years[] = $y;
    }
    $years = array_values( array_unique( $years ) );
    rsort( $years );

    set_transient( $cache_key, $years, 6 * HOUR_IN_SECONDS );
    return $years;
}

/**
 * Clear cache when event is saved
 */
add_action('save_post_event', function() {
    delete_transient('spk_event_months_future');
    delete_transient('spk_past_event_years');

    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_spk_event_months_%'
            OR option_name LIKE '_transient_timeout_spk_event_months_%'
            OR option_name LIKE '_transient_spk_ctx_years_%'
            OR option_name LIKE '_transient_timeout_spk_ctx_years_%'
            OR option_name LIKE '_transient_spk_ctxf_%'
            OR option_name LIKE '_transient_timeout_spk_ctxf_%'
            OR option_name LIKE '_transient_spk_detail_%'
            OR option_name LIKE '_transient_timeout_spk_detail_%'"
    );
});

/**
 * Get event month data for related events (same organiser, upcoming only).
 *
 * Same return format as bl_get_event_month_data() — [$months, $month_post_ids]
 * so it can be dropped straight into the existing Event Months loop.
 *
 * Usage in Bricks PHP query editor:
 *   return bl_get_related_event_month_data();
 *
 * @return array [ string[] $months, array<string, int[]> $month_post_ids ]
 */
function bl_get_related_event_month_data( $limit = 5 ) {
    // Static cache — both outer and inner Bricks loops share identical data
    static $cache = array();

    // Parse event ID from URL — get_queried_object_id() returns template ID in Bricks
    $path  = trim( parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
    $parts = explode( '/', $path );

    $current_id  = 0;
    $event_index = array_search( 'events', $parts );
    if ( $event_index !== false && isset( $parts[ $event_index + 1 ] ) ) {
        $slug = sanitize_title( $parts[ $event_index + 1 ] );
        $post = get_page_by_path( $slug, OBJECT, 'event' );
        if ( $post ) {
            $current_id = $post->ID;
        }
    }

    // Fallback to queried object
    if ( ! $current_id ) {
        $current_id = get_queried_object_id();
    }

    if ( ! $current_id || get_post_type( $current_id ) !== 'event' ) {
        return array( array(), array() );
    }

    $loc_filter = isset( $_GET['loc'] ) ? absint( $_GET['loc'] ) : 0;
    $cat_filter = isset( $_GET['cat'] ) ? sanitize_key( $_GET['cat'] ) : '';

    $cache_key = $current_id . '_' . $limit . '_l' . $loc_filter . '_c' . $cat_filter;
    if ( isset( $cache[ $cache_key ] ) ) {
        return $cache[ $cache_key ];
    }

    // Collect event IDs from the same organiser(s) via bidirectional ACF field
    $ids        = array();
    $organisers = get_field( 'event_organiser', $current_id );

    if ( ! empty( $organisers ) ) {
        foreach ( (array) $organisers as $org ) {
            $org_id = is_object( $org ) ? $org->ID : absint( $org );
            if ( ! $org_id ) {
                continue;
            }
            $org_events = get_field( 'organiser_events', $org_id );
            if ( empty( $org_events ) ) {
                continue;
            }
            foreach ( (array) $org_events as $ev ) {
                $ids[] = is_object( $ev ) ? $ev->ID : absint( $ev );
            }
        }
    }

    $ids = array_values( array_diff( array_unique( array_filter( $ids ) ), array( $current_id ) ) );

    if ( empty( $ids ) ) {
        return array( array(), array() );
    }

    $today = (int) gmdate( 'Ymd', strtotime( current_time( 'Y-m-d' ) ) );

    $meta_query = array(
        array( 'key' => 'event_year',  'compare' => 'EXISTS' ),
        array( 'key' => 'event_month', 'compare' => 'EXISTS' ),
        array(
            'key'     => 'event_end_sort',
            'value'   => $today,
            'compare' => '>=',
            'type'    => 'NUMERIC',
        ),
    );

    if ( $loc_filter > 0 ) {
        $meta_query[] = array(
            'key'     => 'event_location',
            'value'   => '"' . $loc_filter . '"',
            'compare' => 'LIKE',
        );
    }

    $query_args = array(
        'post_type'      => 'event',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'post__in'       => $ids,
        'meta_query'     => $meta_query,
        'meta_key'       => 'event_start_sort',
        'orderby'        => 'meta_value_num',
        'order'          => 'ASC',
    );

    if ( $cat_filter !== '' ) {
        $query_args['tax_query'] = array(
            array(
                'taxonomy' => 'event-category',
                'field'    => 'slug',
                'terms'    => $cat_filter,
            ),
        );
    }

    $posts = get_posts( $query_args );

    $months         = array();
    $month_post_ids = array();

    foreach ( $posts as $post ) {
        $year  = get_post_meta( $post->ID, 'event_year',  true );
        $month = get_post_meta( $post->ID, 'event_month', true );

        if ( empty( $year ) || empty( $month ) ) {
            continue;
        }

        $key = sprintf( '%04d-%02d', (int) $year, (int) $month );

        if ( ! isset( $month_post_ids[$key] ) ) {
            $months[]             = $key;
            $month_post_ids[$key] = array();
        }

        $month_post_ids[$key][] = (int) $post->ID;
    }

    $months = array_values( array_unique( $months ) );
    sort( $months, SORT_STRING );

    // Remove months that have no events (safety check)
    $months = array_values( array_filter( $months, function( $m ) use ( $month_post_ids ) {
        return ! empty( $month_post_ids[ $m ] );
    } ) );

    $result = array( $months, $month_post_ids );
    $cache[ $cache_key ] = $result;

    return $result;
}
