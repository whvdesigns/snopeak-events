<?php
/**
 * Event Duration Shortcode Class
 * 
 * Calculates event duration from ACF date fields
 * Supports d/m/Y format
 */

if (!defined('ABSPATH')) {
    exit;
}

class Event_Duration_Shortcode {

    /**
     * Register the shortcode
     */
    public static function register() {
        add_shortcode('spk_event_duration', array(__CLASS__, 'render'));
    }

    /**
     * Render event duration
     * 
     * @param array $atts Shortcode attributes
     * @return string Duration text or error message
     */
    public static function render($atts = array()) {
        // Get post ID
        $post_id = get_the_ID();
        
        if (!$post_id) {
            return '';
        }

        // Get date fields
        $start_date = function_exists('get_field') ? get_field('event_start_date', $post_id) : '';
        $end_date = function_exists('get_field') ? get_field('event_end_date', $post_id) : '';

        // Validate start date
        if (empty($start_date)) {
            return 'Start date is missing.';
        }

        // Parse start date
        $start = DateTime::createFromFormat('d/m/Y', $start_date);
        
        if ($start === false) {
            return '1 day';
        }

        // If no end date, assume single day event
        if (empty($end_date)) {
            return '1 day';
        }

        // Parse end date
        $end = DateTime::createFromFormat('d/m/Y', $end_date);
        
        if ($end === false) {
            return '1 day';
        }

        // Calculate difference
        $interval = $start->diff($end);
        $days = (int) $interval->days + 1; // Include both start and end days

        return $days === 1 ? '1 day' : $days . ' days';
    }
}