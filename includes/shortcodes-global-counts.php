<?php
/**
 * Global Count Shortcodes
 * 
 * Provides counts for events, organisers, locations, and sponsors
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get total published events count
 * Shortcode: [spk_event_count]
 * 
 * @return int Number of published events
 */
// NOTE: spk_event_count, spk_event_organiser_count, and spk_location_count shortcodes
// are registered by Event_Counts_Shortcodes class in class-event-counts-shortcodes.php.
// The functions below are kept as utilities but do NOT register shortcodes to avoid
// duplicate registration warnings.

function spk_event_count() {
    $count = wp_count_posts('event');
    return isset($count->publish) ? (int) $count->publish : 0;
}

/**
 * @return int Number of published organisers
 */
function spk_event_organiser_count() {
    $count = wp_count_posts('event-organiser');
    return isset($count->publish) ? (int) $count->publish : 0;
}

/**
 * @return int Number of published locations
 */
function spk_location_count() {
    $count = wp_count_posts('location');
    return isset($count->publish) ? (int) $count->publish : 0;
}

/**
 * Get total published sponsors count
 * Shortcode: [spk_sponsor_count]
 * 
 * @return int Number of published sponsors
 */
function spk_sponsor_count() {
    $sponsors = get_posts(array(
        'post_type'      => 'event-sponsor',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids'
    ));
    return count($sponsors);
}
add_shortcode('spk_sponsor_count', 'spk_sponsor_count');