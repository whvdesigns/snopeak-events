<?php
/**
 * Event Timeline Shortcode for Detail Pages
 * 
 * Displays a visual timeline with event status, dates, and progress indicator
 * Optimized for Bricks Builder and Automatic CSS
 * 
 * Usage: [spk_event_timeline]
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Event Timeline Shortcode
 * 
 * @return string HTML output
 */
function spk_event_timeline_shortcode() {
    $post_id = get_the_ID();
    
    if (!$post_id || get_post_type($post_id) !== 'event') {
        return '';
    }

    // Get event dates
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
    $today->setTime(0, 0, 0); // Reset to midnight for date-only comparison
    
    // Also reset start and end to midnight for accurate date comparison
    $start_compare = clone $start;
    $start_compare->setTime(0, 0, 0);
    $end_compare = clone $end;
    $end_compare->setTime(0, 0, 0);

    // Check if event is cancelled
    $status = get_field('event_status', $post_id);
    $is_cancelled = ($status && strtolower(trim($status)) === 'cancelled');

    // Calculate timeline data
    $timeline_data = spk_calculate_timeline_data($start_compare, $end_compare, $today, $is_cancelled);

    // Build output
    ob_start();
    ?>
    <div class="spk-event-timeline" data-state="<?php echo esc_attr($timeline_data['state']); ?>">
        
        <!-- Status Label -->
        <div class="spk-timeline-status">
            <span class="spk-timeline-label">
                <?php echo wp_kses($timeline_data['label'], array('br' => array())); ?>
            </span>
        </div>

        <!-- Visual Progress Bar -->
        <div class="spk-timeline-bar-wrapper">
            <div class="spk-timeline-bar">
                <div class="spk-timeline-progress" style="width: <?php echo esc_attr($timeline_data['progress']); ?>%;"></div>
                <div class="spk-timeline-dot" style="left: <?php echo esc_attr($timeline_data['progress']); ?>%;"></div>
            </div>
        </div>

    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('spk_event_timeline', 'spk_event_timeline_shortcode');


/**
 * Calculate timeline data for display
 * 
 * @param DateTime $start Event start date
 * @param DateTime $end Event end date
 * @param DateTime $today Current date
 * @param bool $is_cancelled Whether event is cancelled
 * @return array Timeline data
 */
function spk_calculate_timeline_data($start, $end, $today, $is_cancelled) {
    
    // Default data
    $data = array(
        'state' => 'upcoming',
        'label' => '',
        'progress' => 0
    );

    // Cancelled events - show full bar in red
    if ($is_cancelled) {
        $data['state'] = 'cancelled';
        $data['label'] = 'Event Cancelled';
        $data['progress'] = 100;
        return $data;
    }

    // Past events
    if ($end < $today) {
        $data['state'] = 'past';
        $data['label'] = 'Event Completed';
        $data['progress'] = 100;
        return $data;
    }

    // Ongoing events
    if ($today >= $start && $today <= $end) {
        // Calculate inclusive day counts
        $total_days = (int) $start->diff($end)->days + 1;
        $elapsed_days = (int) $start->diff($today)->days + 1;
        
        if ($total_days > 1) {
            // Multi-day event: show percentage based on days elapsed
            // Example: Day 2 of 3-day event = 67%
            $data['progress'] = round(($elapsed_days / $total_days) * 100);
            $data['label'] = "Happening Now<br>Day {$elapsed_days} of {$total_days}";
        } else {
            // Single day event: show as 50% (middle of the day)
            $data['progress'] = 50;
            $data['label'] = 'Happening Now';
        }
        
        $data['state'] = 'ongoing';
        return $data;
    }

    // Upcoming events - show visual proximity
    $days_until = (int) $today->diff($start)->days;
    $weekday = (int) $start->format('N'); // 1=Monday, 7=Sunday
    
    if ($days_until === 0) {
        $data['label'] = 'Starts Today';
        $data['progress'] = 90;
        $data['state'] = 'starting-soon';
    } elseif ($days_until === 1) {
        $data['label'] = 'Starts Tomorrow';
        $data['progress'] = 80;
        $data['state'] = 'starting-soon';
    } elseif ($days_until === 2) {
        $data['label'] = 'Starts in 2 days';
        $data['progress'] = 70;
        $data['state'] = 'starting-soon';
    } elseif ($days_until === 3) {
        $data['label'] = 'Starts in 3 days';
        $data['progress'] = 60;
        $data['state'] = 'starting-soon';
    } elseif ($days_until === 4) {
        $data['label'] = 'Starts in 4 days';
        $data['progress'] = 55;
        $data['state'] = 'starting-soon';
    } elseif ($days_until === 5) {
        $data['label'] = 'Starts in 5 days';
        $data['progress'] = 50;
        $data['state'] = 'starting-soon';
    } elseif ($days_until === 6) {
        $data['label'] = 'Starts in 6 days';
        $data['progress'] = 45;
        $data['state'] = 'starting-soon';
    } elseif ($days_until === 7) {
        $data['label'] = 'Starts in 7 days';
        $data['progress'] = 40;
        $data['state'] = 'starting-soon';
    } elseif ($days_until <= 14) {
        // Check if it's next weekend (the FOLLOWING weekend)
        if ($weekday >= 5 && $weekday <= 7) {
            $data['label'] = 'Next Weekend';
            // Calculate position for 8-14 days (35% to 25%)
            $data['progress'] = 35 - (($days_until - 8) * 1.5);
            $data['state'] = 'next-weekend';
        } else {
            $data['label'] = 'Starts in ' . $days_until . ' days';
            $data['progress'] = 35 - (($days_until - 8) * 1.5);
        }
    } elseif ($days_until <= 21) {
        $data['label'] = 'Starts in ' . ceil($days_until / 7) . ' weeks';
        $data['progress'] = 20;
    } elseif ($days_until <= 30) {
        $data['label'] = 'Starts in ' . ceil($days_until / 7) . ' weeks';
        $data['progress'] = 15;
    } elseif ($days_until <= 60) {
        $weeks = ceil($days_until / 7);
        $data['label'] = 'Starts in ' . $weeks . ' weeks';
        $data['progress'] = 10;
    } else {
        $months = ceil($days_until / 30);
        $data['label'] = 'Starts in ' . $months . ' ' . ($months === 1 ? 'month' : 'months');
        $data['progress'] = 5;
    }

    return $data;
}


/**
 * Enqueue timeline styles
 */
function spk_event_timeline_styles() {
    if (!is_singular('event')) {
        return;
    }
    ?>
    <style id="spk-event-timeline-styles">
    .spk-event-timeline {
        --timeline-bg: var(--gray-100, #f3f4f6);
        --timeline-progress-bg: var(--secondary, #6366f1);
        --timeline-dot: var(--secondary, #6366f1);
        --timeline-ongoing: var(--success, #10b981);
        --timeline-warning: var(--warning, #f59e0b);
        --timeline-info: var(--info, #eab308);
        --timeline-cancelled: var(--danger, #ef4444);
        --timeline-past: var(--neutral-semi-light);
        background: var(--white, #ffffff);
        border-radius: var(--radius);
        padding: var(--space-l, 1.5rem);
        border: 4px solid var(--gray-200, #e5e7eb);
        margin-bottom: var(--space-l, 1.5rem);
    }
    .spk-timeline-status { margin-bottom: var(--space-m, 1rem); text-align: center; }
    .spk-timeline-label { font-size: var(--text-l, 1.125rem); font-weight: var(--font-semibold, 600); color: var(--gray-900, #111827); }
    .spk-timeline-bar-wrapper { margin-bottom: 0; }
    .spk-timeline-bar { position: relative; height: 8px; background: var(--timeline-bg); border-radius: var(--radius-full, 9999px); overflow: visible; }
    .spk-timeline-progress { position: absolute; top: 0; left: 0; height: 100%; background: var(--timeline-progress-bg); border-radius: var(--radius-full, 9999px); transition: width 0.3s ease; }
    .spk-timeline-dot { position: absolute; top: 50%; transform: translate(-50%, -50%); width: 16px; height: 16px; background: var(--timeline-dot); border: 3px solid var(--white, #ffffff); border-radius: var(--radius-full, 9999px); box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: left 0.3s ease; }
    .spk-event-timeline[data-state="upcoming"] { border-color: var(--secondary, #6366f1); }
    .spk-event-timeline[data-state="upcoming"] .spk-timeline-bar { background: var(--secondary-light, rgba(99,102,241,0.15)); }
    .spk-event-timeline[data-state="upcoming"] .spk-timeline-progress,
    .spk-event-timeline[data-state="upcoming"] .spk-timeline-dot { background: var(--secondary, #6366f1); }
    .spk-event-timeline[data-state="upcoming"] .spk-timeline-label { color: var(--secondary, #6366f1); }
    .spk-event-timeline[data-state="ongoing"] { border-color: var(--timeline-ongoing); box-shadow: 0 0 0 4px rgba(16,185,129,0.15), 0 8px 16px rgba(16,185,129,0.2); background: var(--success-ultra-light); }
    .spk-event-timeline[data-state="ongoing"] .spk-timeline-bar { background: var(--success-light, rgba(16,185,129,0.15)); }
    .spk-event-timeline[data-state="ongoing"] .spk-timeline-progress,
    .spk-event-timeline[data-state="ongoing"] .spk-timeline-dot { background: var(--timeline-ongoing); }
    .spk-event-timeline[data-state="ongoing"] .spk-timeline-label { color: var(--timeline-ongoing); }
    .spk-event-timeline[data-state="starting-soon"] { border-color: var(--warning, #f59e0b); box-shadow: 0 0 0 4px rgba(245,158,11,0.15), 0 8px 16px rgba(245,158,11,0.2); background: var(--warning-ultra-light); }
    .spk-event-timeline[data-state="starting-soon"] .spk-timeline-bar { background: var(--warning-light, rgba(245,158,11,0.15)); }
    .spk-event-timeline[data-state="starting-soon"] .spk-timeline-progress,
    .spk-event-timeline[data-state="starting-soon"] .spk-timeline-dot { background: var(--warning, #f59e0b); }
    .spk-event-timeline[data-state="starting-soon"] .spk-timeline-label { color: var(--warning, #f59e0b); }
    .spk-event-timeline[data-state="next-weekend"] { border-color: var(--info, #eab308); box-shadow: 0 0 0 4px rgba(234,179,8,0.15), 0 8px 16px rgba(234,179,8,0.2); background: var(--info-ultra-light); }
    .spk-event-timeline[data-state="next-weekend"] .spk-timeline-bar { background: var(--info-light, rgba(234,179,8,0.15)); }
    .spk-event-timeline[data-state="next-weekend"] .spk-timeline-progress,
    .spk-event-timeline[data-state="next-weekend"] .spk-timeline-dot { background: var(--info, #eab308); }
    .spk-event-timeline[data-state="next-weekend"] .spk-timeline-label { color: var(--info, #eab308); }
    .spk-event-timeline[data-state="cancelled"] { border-color: var(--timeline-cancelled); background: var(--danger-ultra-light); }
    .spk-event-timeline[data-state="cancelled"] .spk-timeline-bar { background: var(--danger-light, rgba(239,68,68,0.15)); }
    .spk-event-timeline[data-state="cancelled"] .spk-timeline-progress,
    .spk-event-timeline[data-state="cancelled"] .spk-timeline-dot { background: var(--timeline-cancelled); }
    .spk-event-timeline[data-state="cancelled"] .spk-timeline-label { color: var(--timeline-cancelled); }
    .spk-event-timeline[data-state="past"] { border-color: var(--timeline-past); }
    .spk-event-timeline[data-state="past"] .spk-timeline-bar { background: var(--gray-200, #e5e7eb); }
    .spk-event-timeline[data-state="past"] .spk-timeline-progress,
    .spk-event-timeline[data-state="past"] .spk-timeline-dot { background: var(--timeline-past); }
    .spk-event-timeline[data-state="past"] .spk-timeline-label { color: var(--timeline-past); }
    @media (max-width: 768px) {
        .spk-event-timeline { padding: var(--space-m, 1rem); }
        .spk-timeline-dates { flex-direction: column; gap: var(--space-2xs, 0.25rem); }
        .spk-timeline-separator { display: none; }
    }
    @keyframes pulse-upcoming {
        0%, 100% { transform: translate(-50%, -50%) scale(1); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        50% { transform: translate(-50%, -50%) scale(1.1); box-shadow: 0 0 0 8px color-mix(in srgb, var(--secondary, #6366f1) 40%, transparent), 0 0 0 12px color-mix(in srgb, var(--secondary, #6366f1) 20%, transparent); }
    }
    @keyframes pulse-success {
        0%, 100% { transform: translate(-50%, -50%) scale(1); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        50% { transform: translate(-50%, -50%) scale(1.1); box-shadow: 0 0 0 8px rgba(16,185,129,0.4), 0 0 0 12px rgba(16,185,129,0.2); }
    }
    @keyframes pulse-warning {
        0%, 100% { transform: translate(-50%, -50%) scale(1); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        50% { transform: translate(-50%, -50%) scale(1.1); box-shadow: 0 0 0 8px rgba(245,158,11,0.5), 0 0 0 12px rgba(245,158,11,0.25); }
    }
    @keyframes pulse-info {
        0%, 100% { transform: translate(-50%, -50%) scale(1); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        50% { transform: translate(-50%, -50%) scale(1.1); box-shadow: 0 0 0 8px rgba(234,179,8,0.4), 0 0 0 12px rgba(234,179,8,0.2); }
    }
    .spk-event-timeline[data-state="upcoming"] .spk-timeline-dot { animation: pulse-upcoming 2.5s ease-in-out infinite; }
    .spk-event-timeline[data-state="ongoing"] .spk-timeline-dot { animation: pulse-success 2s ease-in-out infinite; }
    .spk-event-timeline[data-state="starting-soon"] .spk-timeline-dot { animation: pulse-warning 2.5s ease-in-out infinite; }
    .spk-event-timeline[data-state="next-weekend"] .spk-timeline-dot { animation: pulse-info 2.5s ease-in-out infinite; }
    </style>
    <?php
}
add_action('wp_head', 'spk_event_timeline_styles', 100);