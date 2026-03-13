<?php
/**
 * Event Cache Handler Class
 * 
 * Manages cache invalidation for event-related transients
 * Clears cache when events are created, updated, or deleted
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clear event month cache on save
 */
add_action('save_post_event', 'spk_clear_event_cache', 20);

function spk_clear_event_cache($post_id) {
    // Verify this is not an autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Verify this is not a revision
    if (wp_is_post_revision($post_id)) {
        return;
    }

    // Clear all event month transients (includes organiser/location context variants)
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_spk_event_months%'
            OR option_name LIKE '_transient_timeout_spk_event_months%'"
    );

    // Clear conditional filter option caches
    if ( class_exists( 'SPK_Past_Filter_Bar' ) ) {
        SPK_Past_Filter_Bar::clear_option_caches();
    }
    if ( class_exists( 'SPK_Organiser_Filter_Bar' ) ) {
        SPK_Organiser_Filter_Bar::clear_option_caches();
    }

    // Clear organiser count transients for any organiser linked to this event
    if (class_exists('Organiser_Event_Counts')) {
        Organiser_Event_Counts::clear_cache_for_event($post_id);
    }

    // Clear location count transients for any location linked to this event
    if (class_exists('Location_Event_Counts')) {
        Location_Event_Counts::clear_cache_for_event($post_id);
    }
}

/**
 * Clear event month cache on delete
 */
add_action('deleted_post', 'spk_clear_event_cache_on_delete', 10, 1);

function spk_clear_event_cache_on_delete($post_id) {
    if (get_post_type($post_id) === 'event') {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_spk_event_months%'
                OR option_name LIKE '_transient_timeout_spk_event_months%'"
        );
    }
}

/**
 * Clear organiser count transients when an organiser post is saved.
 * Handles the case where the bidirectional organiser_events field is updated
 * directly on the organiser side.
 */
add_action('save_post_event-organiser', 'spk_clear_organiser_cache', 20);

function spk_clear_organiser_cache($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (wp_is_post_revision($post_id)) {
        return;
    }

    if (class_exists('Organiser_Event_Counts')) {
        Organiser_Event_Counts::clear_cache_for_organiser($post_id);
    }
}

/**
 * Clear location count transients when a location post is saved.
 */
add_action( 'save_post_location', 'spk_clear_location_cache', 20 );

function spk_clear_location_cache( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }

    if ( class_exists( 'Location_Event_Counts' ) ) {
        Location_Event_Counts::clear_cache_for_location( $post_id );
    }
}
