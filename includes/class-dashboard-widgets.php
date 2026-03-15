<?php
/**
 * Admin Dashboard Widgets
 *
 * Registers five custom WordPress dashboard widgets:
 * - Events Summary       — counts at a glance
 * - Events I'm Attending — upcoming events with attending role
 * - TBC / Tentative      — upcoming events with TBC location or Tentative status
 * - Recently Modified    — last 8 modified events
 * - Recently Added       — last 8 events by publish date
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Enqueue stylesheet — dashboard only
// ---------------------------------------------------------------------------

add_action( 'admin_enqueue_scripts', 'spk_enqueue_dashboard_styles' );

function spk_enqueue_dashboard_styles( $hook ) {
    if ( $hook !== 'index.php' ) {
        return;
    }
    wp_enqueue_style(
        'spk-dashboard-widgets',
        SNOPEAK_EVENTS_URL . 'assets/dashboard-widgets.css',
        [],
        SNOPEAK_EVENTS_VERSION
    );
}

// ---------------------------------------------------------------------------
// Register widgets
// ---------------------------------------------------------------------------

add_action( 'wp_dashboard_setup', 'spk_register_dashboard_widgets' );

function spk_register_dashboard_widgets() {
    wp_add_dashboard_widget(
        'spk_widget_summary',
        '📅 Events Summary',
        'spk_widget_summary_cb'
    );
    wp_add_dashboard_widget(
        'spk_widget_quick_actions',
        '⚡ Quick Actions',
        'spk_widget_quick_actions_cb'
    );
    wp_add_dashboard_widget(
        'spk_widget_attending',
        '🎽 Events I\'m Attending',
        'spk_widget_attending_cb'
    );
    wp_add_dashboard_widget(
        'spk_widget_tbc',
        '❓ TBC / Tentative Events',
        'spk_widget_tbc_cb'
    );
    wp_add_dashboard_widget(
        'spk_widget_featured',
        '⭐ Featured Events',
        'spk_widget_featured_cb'
    );
    wp_add_dashboard_widget(
        'spk_widget_recent_modified',
        '🕐 Recently Updated Events',
        'spk_widget_recent_modified_cb'
    );
    wp_add_dashboard_widget(
        'spk_widget_recent_added',
        '✨ Recently Added Events',
        'spk_widget_recent_added_cb'
    );
}

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function spk_widget_today() {
    return (int) gmdate( 'Ymd', strtotime( current_time( 'Y-m-d' ) ) );
}

function spk_widget_event_row( $post_id, $meta = '', $meta_label = '', $badge = '', $badge_mod = '' ) {
    $edit_url  = get_edit_post_link( $post_id );
    $start     = get_field( 'event_start_date', $post_id );
    $date_str  = $start ? esc_html( $start ) : '—';

    echo '<li>';
    echo '<span class="spk-event-info">';
    if ( $badge ) {
        $mod_class = $badge_mod ? ' spk-event-badge--' . sanitize_html_class( $badge_mod ) : '';
        echo '<span class="spk-event-badge' . $mod_class . '">' . esc_html( $badge ) . '</span>';
    }
    echo '<span class="spk-event-title">' . esc_html( get_the_title( $post_id ) ) . '</span>';
    echo '<span class="spk-event-meta"><strong>Event Date:</strong> ' . $date_str . '</span>';
    if ( $meta && $meta_label ) {
        echo '<span class="spk-event-meta"><strong>' . esc_html( $meta_label ) . ':</strong> ' . esc_html( $meta ) . '</span>';
    }
    echo '</span>';
    echo '<a href="' . esc_url( $edit_url ) . '" class="spk-edit-btn">Edit Event</a>';
    echo '</li>';
}

// ---------------------------------------------------------------------------
// 1. Events Summary
// ---------------------------------------------------------------------------

function spk_widget_summary_cb() {
    $today = spk_widget_today();

    $upcoming = ( new WP_Query( [
        'post_type'      => 'event',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [ [ 'key' => 'event_end_sort', 'value' => $today, 'compare' => '>=', 'type' => 'NUMERIC' ] ],
    ] ) )->found_posts;

    $past = ( new WP_Query( [
        'post_type'      => 'event',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [ [ 'key' => 'event_end_sort', 'value' => $today, 'compare' => '<', 'type' => 'NUMERIC' ] ],
    ] ) )->found_posts;

    $total      = (int) wp_count_posts( 'event' )->publish;
    $organisers = (int) wp_count_posts( 'event-organiser' )->publish;
    $locations  = (int) wp_count_posts( 'location' )->publish;
    $sponsors   = (int) wp_count_posts( 'event-sponsor' )->publish;
    $categories = (int) wp_count_terms( [ 'taxonomy' => 'event-category', 'hide_empty' => false ] );
    $years      = (int) gmdate( 'Y' ) - 1997;

    $rows = [
        [ 'Upcoming Events', $upcoming   ],
        [ 'Past Events',     $past       ],
        [ 'Total Events',    $total      ],
        [ 'Organisers',      $organisers ],
        [ 'Locations',       $locations  ],
        [ 'Event Types',     $categories ],
        [ 'Sponsors',        $sponsors   ],
        [ 'Years Online',    $years      ],
    ];

    echo '<div class="spk-summary-grid">';
    foreach ( $rows as [ $label, $count ] ) {
        echo '<div class="spk-summary-card">';
        echo '<span class="spk-summary-card-count">' . (int) $count . '</span>';
        echo '<span class="spk-summary-card-label">' . esc_html( $label ) . '</span>';
        echo '</div>';
    }
    echo '</div>';
}

// ---------------------------------------------------------------------------
// 2. Quick Actions
// ---------------------------------------------------------------------------

function spk_widget_quick_actions_cb() {
    $actions = [
        [ 'Add Event',      admin_url( 'post-new.php?post_type=event' )           ],
        [ 'Add Organiser',  admin_url( 'post-new.php?post_type=event-organiser' ) ],
        [ 'Add Location',   admin_url( 'post-new.php?post_type=location' )        ],
        [ 'Add Sponsor',    admin_url( 'post-new.php?post_type=event-sponsor' )   ],
        [ 'Add Event Type', admin_url( 'edit-tags.php?taxonomy=event-category' )  ],
    ];

    echo '<div class="spk-quick-actions">';
    foreach ( $actions as [ $label, $url ] ) {
        echo '<a href="' . esc_url( $url ) . '" class="spk-action-btn">' . esc_html( $label ) . '</a>';
    }
    echo '</div>';
}

// ---------------------------------------------------------------------------
// 3. Events I'm Attending
// ---------------------------------------------------------------------------

function spk_widget_attending_cb() {
    $today = spk_widget_today();

    $q = new WP_Query( [
        'post_type'      => 'event',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_key'       => 'event_start_sort',
        'orderby'        => 'meta_value_num',
        'order'          => 'ASC',
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'     => 'event_end_sort',
                'value'   => $today,
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ],
            [
                'key'     => 'event_attending',
                'value'   => 'Maybe',
                'compare' => 'NOT LIKE',
            ],
            [
                'key'     => 'event_attending',
                'value'   => '"',
                'compare' => 'LIKE',
            ],
        ],
    ] );

    if ( ! $q->have_posts() ) {
        echo '<p class="spk-widget-empty">No upcoming attended events.</p>';
        wp_reset_postdata();
        return;
    }

    echo '<ul class="spk-widget-list">';
    while ( $q->have_posts() ) {
        $q->the_post();
        $role_raw = get_field( 'event_attending', get_the_ID() );
        $role     = is_array( $role_raw ) ? implode( ', ', $role_raw ) : (string) $role_raw;
        spk_widget_event_row( get_the_ID(), '', '', $role );
    }
    echo '</ul>';
    wp_reset_postdata();
}

// ---------------------------------------------------------------------------
// 3. TBC / Tentative Events
// ---------------------------------------------------------------------------

function spk_widget_tbc_cb() {
    $today = spk_widget_today();

    $tbc_post = get_page_by_path( 'tbc', OBJECT, 'location' );
    $tbc_id   = $tbc_post ? (int) $tbc_post->ID : 0;

    // Single query: events that are Tentative OR have TBC location
    $or_clauses = [
        'relation' => 'OR',
        [ 'key' => 'event_status', 'value' => 'Tentative', 'compare' => '=' ],
    ];
    if ( $tbc_id ) {
        $or_clauses[] = [ 'key' => 'event_location', 'value' => '"' . $tbc_id . '"', 'compare' => 'LIKE' ];
    }

    $q = new WP_Query( [
        'post_type'      => 'event',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_key'       => 'event_start_sort',
        'orderby'        => 'meta_value_num',
        'order'          => 'ASC',
        'meta_query'     => [
            'relation' => 'AND',
            [ 'key' => 'event_end_sort', 'value' => $today, 'compare' => '>=', 'type' => 'NUMERIC' ],
            $or_clauses,
        ],
    ] );

    if ( ! $q->have_posts() ) {
        echo '<p class="spk-widget-empty">No TBC or tentative upcoming events.</p>';
        wp_reset_postdata();
        return;
    }

    echo '<ul class="spk-widget-list">';
    while ( $q->have_posts() ) {
        $q->the_post();
        $id      = get_the_ID();
        $status  = get_field( 'event_status', $id );
        $locs    = get_field( 'event_location', $id );
        $loc_ids = array_map( 'intval', wp_list_pluck( (array) $locs, 'ID' ) );

        $is_tentative = ( $status === 'Tentative' );
        $is_tbc       = ( $tbc_id && in_array( $tbc_id, $loc_ids, true ) );

        $edit_url = get_edit_post_link( $id );
        $start    = get_field( 'event_start_date', $id );
        $date_str = $start ? esc_html( $start ) : '—';

        echo '<li>';
        echo '<span class="spk-event-info">';
        echo '<span class="spk-event-badges">';
        if ( $is_tentative ) {
            echo '<span class="spk-event-badge spk-event-badge--tentative">Tentative</span>';
        }
        if ( $is_tbc ) {
            echo '<span class="spk-event-badge spk-event-badge--tbc">TBC</span>';
        }
        echo '</span>';
        echo '<span class="spk-event-title">' . esc_html( get_the_title( $id ) ) . '</span>';
        echo '<span class="spk-event-meta"><strong>Event Date:</strong> ' . $date_str . '</span>';
        echo '</span>';
        echo '<a href="' . esc_url( $edit_url ) . '" class="spk-edit-btn">Edit Event</a>';
        echo '</li>';
    }
    echo '</ul>';
    wp_reset_postdata();
}

// ---------------------------------------------------------------------------
// 4. Featured Events
// ---------------------------------------------------------------------------

function spk_widget_featured_cb() {
    $today = spk_widget_today();

    $q = new WP_Query( [
        'post_type'      => 'event',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_key'       => 'event_start_sort',
        'orderby'        => 'meta_value_num',
        'order'          => 'ASC',
        'meta_query'     => [
            'relation' => 'AND',
            [ 'key' => 'event_end_sort',  'value' => $today, 'compare' => '>=', 'type' => 'NUMERIC' ],
            [ 'key' => 'event_featured',  'value' => '1',    'compare' => '='                       ],
        ],
    ] );

    if ( ! $q->have_posts() ) {
        echo '<p class="spk-widget-empty">No upcoming featured events.</p>';
        wp_reset_postdata();
        return;
    }

    echo '<ul class="spk-widget-list">';
    while ( $q->have_posts() ) {
        $q->the_post();
        spk_widget_event_row( get_the_ID(), '', '', 'Featured', 'featured' );
    }
    echo '</ul>';
    wp_reset_postdata();
}

// ---------------------------------------------------------------------------
// 5. Recently Updated Events
// ---------------------------------------------------------------------------

function spk_widget_recent_modified_cb() {
    $q = new WP_Query( [
        'post_type'      => 'event',
        'post_status'    => 'publish',
        'posts_per_page' => 8,
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ] );

    if ( ! $q->have_posts() ) {
        echo '<p class="spk-widget-empty">No events found.</p>';
        wp_reset_postdata();
        return;
    }

    echo '<ul class="spk-widget-list">';
    while ( $q->have_posts() ) {
        $q->the_post();
        spk_widget_event_row( get_the_ID(), get_the_modified_date( 'j M Y' ), 'Updated' );
    }
    echo '</ul>';
    wp_reset_postdata();
}

// ---------------------------------------------------------------------------
// 5. Recently Added Events
// ---------------------------------------------------------------------------

function spk_widget_recent_added_cb() {
    $q = new WP_Query( [
        'post_type'      => 'event',
        'post_status'    => 'publish',
        'posts_per_page' => 8,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );

    if ( ! $q->have_posts() ) {
        echo '<p class="spk-widget-empty">No events found.</p>';
        wp_reset_postdata();
        return;
    }

    echo '<ul class="spk-widget-list">';
    while ( $q->have_posts() ) {
        $q->the_post();
        spk_widget_event_row( get_the_ID(), get_the_date( 'j M Y' ), 'Added' );
    }
    echo '</ul>';
    wp_reset_postdata();
}
