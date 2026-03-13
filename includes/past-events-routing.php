<?php
/**
 * Past Events Routing
 *
 * Alias route: /events/past/{event-slug}/ loads the event single template.
 *
 * Assumes:
 * - A real WordPress page exists at /events/ (slug: events)
 * - A real child page exists at /events/past/ (slug: past, parent: events page)
 * - The event CPT uses rewrite slug 'events'
 */

if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Rewrite rules
// ---------------------------------------------------------------------------

add_action('init', function () {
    // Give the real /events/past/ page priority over the CPT rewrite.
    $past_page = get_page_by_path('events/past');
    if ($past_page) {
        add_rewrite_rule(
            '^events/past/?$',
            'index.php?page_id=' . absint($past_page->ID),
            'top'
        );
    }

    // Alias route: /events/past/{event-slug}/ → load the event single
    add_rewrite_rule(
        '^events/past/([^/]+)/?$',
        'index.php?post_type=event&name=$matches[1]&event_alias=past',
        'top'
    );
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'event_alias';
    return $vars;
});

// ---------------------------------------------------------------------------
// Canonical URL — prevents duplicate-content indexing
// ---------------------------------------------------------------------------

add_filter('get_canonical_url', function ($canonical, $post) {
    if (!is_singular('event')) {
        return $canonical;
    }
    if (get_query_var('event_alias') === 'past') {
        return get_permalink($post);
    }
    return $canonical;
}, 10, 2);

// ---------------------------------------------------------------------------
// Bricks breadcrumbs
//
// bricks/breadcrumbs/items is an array of pre-rendered HTML strings.
// Bricks simply implodes them with the separator — do NOT return associative
// arrays or they will render as "Array".
//
// Formats used by Bricks:
//   Link:    <a class="item" href="%s">%s</a>
//   Current: <span class="item" aria-current="page">%s</span>
// ---------------------------------------------------------------------------

add_filter('bricks/breadcrumbs/items', function ($items) {
    if (is_tax('event-category')) {
        $link_format    = '<a class="item" href="%s">%s</a>';
        $current_format = '<span class="item" aria-current="page">%s</span>';

        $events_page = get_page_by_path('events');
        $term        = get_queried_object();

        if (!$events_page || !$term) {
            return $items;
        }

        return [
            sprintf($link_format, esc_url(home_url('/')), esc_html__('Home', 'snopeak-events')),
            sprintf($link_format, esc_url(get_permalink($events_page)), esc_html(get_the_title($events_page))),
            sprintf($current_format, esc_html($term->name)),
        ];
    }

    if (is_singular('event-organiser')) {
        $link_format    = '<a class="item" href="%s">%s</a>';
        $current_format = '<span class="item" aria-current="page">%s</span>';

        $archive_url = get_post_type_archive_link('event-organiser');

        return [
            sprintf($link_format, esc_url(home_url('/')), esc_html__('Home', 'snopeak-events')),
            sprintf($link_format, esc_url($archive_url), esc_html__('Event Organisers', 'snopeak-events')),
            sprintf($current_format, esc_html(get_the_title())),
        ];
    }

    if (is_singular('location')) {
        $link_format    = '<a class="item" href="%s">%s</a>';
        $current_format = '<span class="item" aria-current="page">%s</span>';

        $events_page = get_page_by_path('events');

        return [
            sprintf($link_format, esc_url(home_url('/')), esc_html__('Home', 'snopeak-events')),
            sprintf($link_format, esc_url($events_page ? get_permalink($events_page) : home_url('/events/')), esc_html__('Events', 'snopeak-events')),
            sprintf($current_format, esc_html(get_the_title())),
        ];
    }

    if (!is_singular('event')) {
        return $items;
    }

    $link_format    = '<a class="item" href="%s">%s</a>';
    $current_format = '<span class="item" aria-current="page">%s</span>';

    $events_page = get_page_by_path('events');
    $past_page   = get_page_by_path('events/past');

    // An event is past only after its end day has fully passed in the site timezone (UK).
    // Compare against today — an event ending today is not past until tomorrow.
    $today    = (int) gmdate('Ymd', strtotime(current_time('Y-m-d')));
    $end_sort = (int) get_post_meta(get_the_ID(), 'event_end_sort', true);
    $is_past  = $end_sort > 0 && $end_sort < $today;

    // Also treat it as past if accessed via the /events/past/ alias URL
    if (get_query_var('event_alias') === 'past') {
        $is_past = true;
    }

    if ($is_past && $past_page) {
        return [
            sprintf($link_format, esc_url(home_url('/')), esc_html__('Home', 'snopeak-events')),
            sprintf($link_format, esc_url(get_permalink($past_page)), esc_html(get_the_title($past_page))),
            sprintf($current_format, esc_html(get_the_title())),
        ];
    }

    // Current/future event — fix the Events crumb URL if Bricks got it wrong
    if ($events_page) {
        $correct_url = get_permalink($events_page);
        $items = array_map(function ($item) use ($correct_url, $events_page) {
            // Replace any href that isn't the events page URL on the Events link
            $label = esc_html(get_the_title($events_page));
            if (strpos($item, '>' . $label . '<') !== false) {
                $item = preg_replace('/href="[^"]*"/', 'href="' . esc_url($correct_url) . '"', $item);
            }
            return $item;
        }, $items);
    }

    return $items;
});
