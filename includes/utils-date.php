<?php
/**
 * Date Utility Functions
 * 
 * Handles date parsing and formatting for events
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parse date string into DateTime object
 * Supports multiple formats: d/m/Y, Ymd, Y-m-d
 * 
 * @param string|null $raw Raw date string
 * @return DateTime|null DateTime object or null on failure
 */
function spk_parse_date($raw) {
    if (empty($raw) || !is_string($raw)) {
        return null;
    }

    // Try common formats
    $formats = array('d/m/Y', 'Ymd', 'Y-m-d');
    
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $raw);
        if ($dt !== false) {
            return $dt;
        }
    }
    
    // Fallback to strtotime
    try {
        return new DateTime($raw);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Safely get ACF field value, ensuring no null returns
 * Prevents deprecated stripslashes() warnings
 * 
 * @param string $field_name Field name
 * @param int|false $post_id Post ID
 * @return string Field value or empty string
 */
function spk_get_field_safe($field_name, $post_id = false) {
    if (!function_exists('get_field')) {
        return '';
    }
    
    $value = get_field($field_name, $post_id);
    return ($value === null || $value === '') ? '' : $value;
}