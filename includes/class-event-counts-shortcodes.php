<?php
/**
 * Event Count Shortcodes Class
 * 
 * Provides wrapped count outputs for various post types
 * and organiser-specific event counts
 */

if (!defined('ABSPATH')) {
    exit;
}

class Event_Counts_Shortcodes {

    /**
     * Register all shortcodes
     */
    public static function register() {
        add_action('init', array(__CLASS__, 'init_shortcodes'), 20);
    }

    /**
     * Initialize shortcodes
     */
    public static function init_shortcodes() {
        // Post type counts
        add_shortcode('spk_team_count', array(__CLASS__, 'team_count'));
        add_shortcode('spk_event_count', array(__CLASS__, 'event_count_wrapped'));
        add_shortcode('spk_event_organiser_count', array(__CLASS__, 'organiser_count_wrapped'));
        add_shortcode('spk_location_count', array(__CLASS__, 'location_count_wrapped'));

        // Organiser event counts

        // Years since shortcode
        add_shortcode('years_since_1997', array(__CLASS__, 'years_since_1997'));
    }

    /**
     * Get post count for any post type
     * 
     * @param string $post_type Post type name
     * @return int Post count
     */
    private static function get_post_count($post_type = '') {
        if (empty($post_type) || !post_type_exists($post_type)) {
            return 0;
        }

        $count = wp_count_posts($post_type);
        return isset($count->publish) ? (int) $count->publish : 0;
    }

    /**
     * Team count shortcode
     * 
     * @return string HTML wrapped count
     */
    public static function team_count() {
        $count = self::get_post_count('team');
        return '<span class="spk-count team-count">' . $count . '</span>';
    }

    /**
     * Event count shortcode (wrapped)
     * 
     * @return string HTML wrapped count
     */
    public static function event_count_wrapped() {
        $count = self::get_post_count('event');
        return '<span class="spk-count event-count">' . $count . '</span>';
    }

    /**
     * Organiser count shortcode (wrapped)
     * 
     * @return string HTML wrapped count
     */
    public static function organiser_count_wrapped() {
        $count = self::get_post_count('event-organiser');
        return '<span class="spk-count organiser-count">' . $count . '</span>';
    }

    /**
     * Location count shortcode (wrapped)
     * 
     * @return string HTML wrapped count
     */
    public static function location_count_wrapped() {
        $count = self::get_post_count('location');
        return '<span class="spk-count location-count">' . $count . '</span>';
    }


    /**
     * Years since 1997 shortcode
     * 
     * @return int Number of years
     */
    public static function years_since_1997() {
        return (int) gmdate('Y') - 1997;
    }
}
