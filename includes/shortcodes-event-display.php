<?php
/**
 * Event Display Shortcodes
 * 
 * Shortcodes for displaying event information:
 * - Event duration (days)
 * - Timing bar (happening now, starting soon, etc.)
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calculate event duration in days
 * Shortcode: [spk_event_days]
 * 
 * @param array $atts Shortcode attributes
 * @return string Duration text (e.g., "3 days")
 */
function spk_event_days_shortcode($atts) {
    $atts = shortcode_atts(array('id' => get_the_ID()), $atts);
    $post_id = (int) $atts['id'];

    if (!$post_id || get_post_type($post_id) !== 'event') {
        return '';
    }

    $start = spk_parse_date(spk_get_field_safe('event_start_date', $post_id));
    $end = spk_parse_date(spk_get_field_safe('event_end_date', $post_id));

    if (!$start) {
        return '';
    }

    if (!$end) {
        return '1 day';
    }

    $days = $start->diff($end)->days + 1;
    return $days === 1 ? '1 day' : $days . ' days';
}
add_shortcode('spk_event_days', 'spk_event_days_shortcode');

/**
 * Get event timing state
 * 
 * @param int|null $post_id Post ID
 * @return string Timing state: 'today', 'startingsoon', 'nextweekend', or empty
 */
function spk_get_timing_state($post_id = null) {
    $post_id = $post_id ?: get_the_ID();
    
    if (!$post_id) {
        return '';
    }

    // Never show timing for cancelled events
    $status = get_field('event_status', $post_id);
    if ($status && strtolower(trim($status)) === 'cancelled') {
        return '';
    }

    $start_raw = spk_get_field_safe('event_start_date', $post_id);
    $end_raw = spk_get_field_safe('event_end_date', $post_id);

    if (empty($start_raw)) {
        return '';
    }

    $start = spk_parse_date($start_raw);
    
    if (!$start) {
        return '';
    }
    
    $end = $end_raw ? spk_parse_date($end_raw) : clone $start;

    // Use WordPress timezone for accurate "today" calculation
    $today = new DateTime('now', wp_timezone());
    $today->setTime(0, 0, 0);
    
    // Reset start and end to midnight for date-only comparison
    $start_compare = clone $start;
    $start_compare->setTime(0, 0, 0);
    $end_compare = clone $end;
    $end_compare->setTime(0, 0, 0);
    
    $diff_start = (int) $today->diff($start_compare)->format('%r%a');
    $weekday = (int) $start_compare->format('N');

    // Happening now (multi-day aware)
    if ($today >= $start_compare && $today <= $end_compare) {
        return 'today';
    }

    // Starting soon (1-7 days) - includes this weekend
    if ($diff_start >= 1 && $diff_start <= 7) {
        return 'startingsoon';
    }

    // Next weekend (Friday-Sunday, 8-14 days away) - the FOLLOWING weekend
    if ($weekday >= 5 && $weekday <= 7 && $diff_start >= 8 && $diff_start <= 14) {
        return 'nextweekend';
    }

    return '';
}

/**
 * Display timing bar for events
 * Shortcode: [spk_timing_bar]
 * 
 * @return string HTML output
 */
function spk_timing_bar_shortcode() {
    $post_id = get_the_ID();
    
    if (!$post_id) {
        return '';
    }

    $state = spk_get_timing_state($post_id);
    
    if (!$state) {
        return '';
    }

    // Get days until event for "starting soon" state
    $label = '';
    
    if ($state === 'today') {
        $label = 'Happening Now!';
    } elseif ($state === 'startingsoon') {
        // Calculate actual days
        $start_raw = spk_get_field_safe('event_start_date', $post_id);
        $start = spk_parse_date($start_raw);
        
        if ($start) {
            // Use WordPress timezone
            $today = new DateTime('now', wp_timezone());
            $today->setTime(0, 0, 0);
            
            $start_compare = clone $start;
            $start_compare->setTime(0, 0, 0);
            
            $days = (int) $today->diff($start_compare)->format('%r%a');
            
            if ($days === 1) {
                $label = 'Starts Tomorrow';
            } else {
                $label = 'Starts in ' . $days . ' days';
            }
        } else {
            $label = 'Starting Soon';
        }
    } elseif ($state === 'nextweekend') {
        $label = 'Next Weekend';
    }

    // Inject styles once, only on pages where the shortcode actually renders.
    static $styles_added = false;
    $style = '';
    if ( ! $styles_added ) {
        $styles_added = true;
        $style = '<style id="spk-timing-bar-styles">
    .event-timing-bar {
        --timing-success: var(--success, #10b981);
        --timing-warning: var(--warning, #f59e0b);
        --timing-info: var(--info, #eab308);
        display: inline-block;
        padding: var(--space-xs, 0.5rem) var(--space-m, 1rem);
        border-radius: var(--radius-s) var(--radius-s) 0 0;
    }
    .event-timing-bar[data-timing="today"]       { background: var(--timing-success); }
    .event-timing-bar[data-timing="startingsoon"]{ background: var(--timing-info); }
    .event-timing-bar[data-timing="nextweekend"] { background: var(--timing-warning); }
    </style>';
    }

    // Tell LiteSpeed Cache not to cache pages where the timing bar renders
    do_action( 'litespeed_tag_add_private', 'spk_timing' );

    return $style . '<div class="event-timing-bar" data-timing="' . esc_attr($state) . '">'
           . esc_html($label)
           . '</div>';
}
add_shortcode('spk_timing_bar', 'spk_timing_bar_shortcode');