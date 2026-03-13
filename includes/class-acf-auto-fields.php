<?php
/**
 * ACF Auto Fields Class
 * 
 * Automatically updates meta fields when event dates are saved
 * - event_year: Year (e.g., 2025)
 * - event_month: Numeric month (e.g., 11)
 * - event_start_sort: Sortable date format (e.g., 20251115)
 * - event_end_sort: Sortable end date format (e.g., 20251116); falls back to event_start_sort if no end date
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Auto-update date-based meta fields on event save
 */
add_action('acf/save_post', 'spk_auto_update_event_date_fields', 20);

function spk_auto_update_event_date_fields($post_id) {
    // Verify this is not an autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Verify this is not a revision
    if (wp_is_post_revision($post_id)) {
        return;
    }

    // Only process event post type
    if (get_post_type($post_id) !== 'event') {
        return;
    }

    // Check if ACF is available
    if (!function_exists('get_field')) {
        return;
    }

    // Get start date
    $start_date = get_field('event_start_date', $post_id);

    // If no start date, clear meta fields
    if (empty($start_date)) {
        update_post_meta($post_id, 'event_year', '');
        update_post_meta($post_id, 'event_month', '');
        update_post_meta($post_id, 'event_start_sort', '');
        update_post_meta($post_id, 'event_end_sort', '');
        
        // Clear month cache
        delete_transient('spk_event_months_future');
        delete_transient('spk_event_months_past');
        
        return;
    }

    // Parse date (expecting d/m/Y format from ACF)
    $date_obj = DateTime::createFromFormat('d/m/Y', $start_date);

    if ($date_obj === false) {
        return;
    }

    // Update start-based meta fields
    update_post_meta($post_id, 'event_year', $date_obj->format('Y'));
    update_post_meta($post_id, 'event_month', $date_obj->format('n')); // 1-12 without leading zeros
    update_post_meta($post_id, 'event_start_sort', $date_obj->format('Ymd')); // YYYYMMDD for sorting

    // Derive event_end_sort — used for efficient past/upcoming DB queries.
    // Falls back to event_start_sort when there is no end date (single-day event).
    $end_date = get_field('event_end_date', $post_id);
    if (!empty($end_date)) {
        $end_obj = DateTime::createFromFormat('d/m/Y', $end_date);
        update_post_meta($post_id, 'event_end_sort', $end_obj ? $end_obj->format('Ymd') : $date_obj->format('Ymd'));
    } else {
        update_post_meta($post_id, 'event_end_sort', $date_obj->format('Ymd'));
    }

    // Clear month cache to reflect changes
    delete_transient('spk_event_months_future');
    delete_transient('spk_event_months_past');
}

/**
 * One-time backfill: generate event_end_sort (and sibling sort fields) for any
 * event that is missing them. Fires on admin_init, runs once, then sets an
 * option flag so it never runs again. Re-triggers automatically if the plugin
 * version changes (catches future schema additions).
 *
 * Can also be triggered manually by a manage_options user via a nonce-protected URL.
 * Generate the URL with: spk_backfill_url() or via the Events admin page link.
 */
add_action( 'admin_init', 'spk_maybe_backfill_sort_fields' );

function spk_maybe_backfill_sort_fields() {
    $flag = 'spk_sort_backfill_' . SNOPEAK_EVENTS_VERSION;

    // Manual force-trigger: requires both capability and a valid nonce to prevent CSRF.
    $force = isset( $_GET['spk_backfill'] )
        && current_user_can( 'manage_options' )
        && isset( $_GET['_wpnonce'] )
        && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'spk_backfill' );

    if ( ! $force && get_option( $flag ) ) {
        return;
    }

    spk_backfill_sort_fields();

    update_option( $flag, time(), false );
}

/**
 * Return a nonce-protected URL to manually trigger the backfill.
 * Use this wherever you need to link to the backfill action.
 *
 * @return string Admin URL with spk_backfill=1 and a valid nonce.
 */
function spk_backfill_url() {
    return wp_nonce_url(
        add_query_arg( 'spk_backfill', '1', admin_url() ),
        'spk_backfill'
    );
}

function spk_backfill_sort_fields() {
    // Find all published events missing event_end_sort
    $events = get_posts( array(
        'post_type'      => 'event',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => 'event_end_sort',
                'compare' => 'NOT EXISTS',
            ),
        ),
    ) );

    if ( empty( $events ) ) {
        return;
    }

    foreach ( $events as $post_id ) {
        $start_raw = get_post_meta( $post_id, 'event_start_date', true );
        $end_raw   = get_post_meta( $post_id, 'event_end_date',   true );

        if ( empty( $start_raw ) ) {
            continue;
        }

        $start_obj = DateTime::createFromFormat( 'd/m/Y', $start_raw );

        if ( ! $start_obj ) {
            continue;
        }

        // Write start sort fields if missing
        if ( ! get_post_meta( $post_id, 'event_start_sort', true ) ) {
            update_post_meta( $post_id, 'event_year',       $start_obj->format( 'Y'   ) );
            update_post_meta( $post_id, 'event_month',      $start_obj->format( 'n'   ) );
            update_post_meta( $post_id, 'event_start_sort', $start_obj->format( 'Ymd' ) );
        }

        // Write event_end_sort — fall back to start if no end date
        if ( ! empty( $end_raw ) ) {
            $end_obj = DateTime::createFromFormat( 'd/m/Y', $end_raw );
            update_post_meta( $post_id, 'event_end_sort', $end_obj ? $end_obj->format( 'Ymd' ) : $start_obj->format( 'Ymd' ) );
        } else {
            update_post_meta( $post_id, 'event_end_sort', $start_obj->format( 'Ymd' ) );
        }
    }

    delete_transient( 'spk_event_months_future' );
    delete_transient( 'spk_event_months_past' );
}
