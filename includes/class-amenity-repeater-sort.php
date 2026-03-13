<?php
/**
 * Amenity Repeater Sort Class
 * 
 * Automatically sorts ACF repeater field "ammenities" 
 * alphabetically by sub-field "amenity_type"
 */

if (!defined('ABSPATH')) {
    exit;
}

class SnoPeak_Amenity_Repeater_Sort {

    /**
     * Initialize the sorting functionality
     */
    public static function init() {
        add_filter('acf/load_value/name=ammenities', array(__CLASS__, 'sort_repeater_rows'), 10, 3);
    }

    /**
     * Sort repeater rows alphabetically by amenity_type sub-field
     * 
     * @param mixed $value The repeater field value (array of rows)
     * @param int $post_id The post ID
     * @param array $field The field array
     * @return mixed Sorted array or original value
     */
    public static function sort_repeater_rows($value, $post_id, $field) {
        // Verify this is the correct field
        if (!isset($field['name']) || $field['name'] !== 'ammenities') {
            return $value;
        }

        // Ensure we have a valid array
        if (empty($value) || !is_array($value)) {
            return $value;
        }

        // Build sort order array
        $order = array();
        
        foreach ($value as $index => $row) {
            $order[$index] = isset($row['amenity_type']) ? $row['amenity_type'] : '';
        }

        // Sort both arrays maintaining key association
        array_multisort($order, SORT_ASC, SORT_STRING, $value);

        return $value;
    }
}

// Initialize the sorting functionality
SnoPeak_Amenity_Repeater_Sort::init();