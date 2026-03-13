<?php
/**
 * Related Events – Bricks Posts Query Filter
 *
 * Hooks into bricks/posts/query_vars to modify a standard Bricks Posts element.
 *
 * Setup in Bricks:
 *   1. Add a Posts loop element to your event detail template
 *   2. Set Query → Post Type → event
 *   3. In Style tab → CSS ID → set to: spk-related-events
 *   4. Set any other display options (limit etc.) as normal in the Bricks query
 *
 * The filter detects that CSS ID and replaces the post__in with upcoming events
 * from the same organiser(s) as the current event, excluding the current event.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'bricks/posts/query_vars', function( $query_vars, $settings, $element_id ) {

    // Only run for our specific element (matched by CSS ID set in Bricks)
    $css_id = isset( $settings['_cssId'] ) ? $settings['_cssId'] : '';
    if ( $css_id !== 'spk-related-events' ) {
        return $query_vars;
    }

    $current_id = get_queried_object_id();
    if ( ! $current_id || get_post_type( $current_id ) !== 'event' ) {
        $query_vars['post__in'] = array( 0 ); // force no results
        return $query_vars;
    }

    $today         = (int) gmdate( 'Ymd', strtotime( current_time( 'Y-m-d' ) ) );
    $candidate_ids = spk_get_organiser_event_ids( $current_id );
    $candidate_ids = array_diff( $candidate_ids, array( $current_id ) );

    if ( empty( $candidate_ids ) ) {
        $query_vars['post__in'] = array( 0 );
        return $query_vars;
    }

    $query_vars['post__in']   = array_values( $candidate_ids );
    $query_vars['meta_query'] = array(
        array(
            'key'     => 'event_end_sort',
            'value'   => $today,
            'compare' => '>=',
            'type'    => 'NUMERIC',
        ),
    );
    $query_vars['meta_key']   = 'event_start_sort';
    $query_vars['orderby']    = 'meta_value_num';
    $query_vars['order']      = 'ASC';

    return $query_vars;

}, 10, 3 );

/**
 * Get all event IDs associated with the same organiser(s) as the given event.
 * Uses ACF bidirectional field organiser_events on the event-organiser post.
 *
 * @param int $post_id Event post ID.
 * @return int[]
 */
function spk_get_organiser_event_ids( $post_id ) {
    $ids        = array();
    $organisers = get_field( 'event_organiser', $post_id );

    if ( empty( $organisers ) ) {
        return $ids;
    }

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

    return array_values( array_unique( array_filter( $ids ) ) );
}
