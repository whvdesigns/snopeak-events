<?php
/**
 * Plugin Name: SnoPeak Events
 * Description: Custom events guide system for SnoPeak – integrates with ACF and Bricks Builder, compatible with UiPress for admin UI.
 * Version: 1.3.78
 * Author: We Have Victory
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SNOPEAK_EVENTS_VERSION', '1.3.56');
define('SNOPEAK_EVENTS_PATH', plugin_dir_path(__FILE__));
define('SNOPEAK_EVENTS_URL', plugin_dir_url(__FILE__));

// -----------------------------------------------------------------------------
// Core Utilities (load first - required by other files)
// -----------------------------------------------------------------------------
require_once SNOPEAK_EVENTS_PATH . 'includes/utils-date.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/utils-query.php';

// -----------------------------------------------------------------------------
// Routing
// -----------------------------------------------------------------------------
require_once SNOPEAK_EVENTS_PATH . 'includes/past-events-routing.php';

// -----------------------------------------------------------------------------
// Shortcode Files
// -----------------------------------------------------------------------------
require_once SNOPEAK_EVENTS_PATH . 'includes/shortcodes-global-counts.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/shortcodes-event-display.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/shortcode-event-timeline.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-related-events-query.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-event-detail-filter-bar.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-organiser-filter-bar.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-location-filter-bar.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-category-filter-bar.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-org-list-filter.php';

// -----------------------------------------------------------------------------
// Class Files
// -----------------------------------------------------------------------------
require_once SNOPEAK_EVENTS_PATH . 'includes/class-acf-auto-fields.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-event-seo-fields.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-event-cache-handler.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-event-counts-shortcodes.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-organiser-event-counts.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-location-event-counts.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-event-duration-shortcode.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-event-month-query.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-past-filter-bar.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-upcoming-filter-bar.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-context-filter-bar.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-event-location-organisers-shortcode.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-event-location-organisers-query.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-amenity-repeater-sort.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-location-amenities-query.php';
require_once SNOPEAK_EVENTS_PATH . 'includes/class-dashboard-widgets.php';

// -----------------------------------------------------------------------------
// Initialize Plugin
// -----------------------------------------------------------------------------
add_action('plugins_loaded', 'snopeak_events_init');

function snopeak_events_init() {
    // Check for ACF dependency
    if (!function_exists('get_field')) {
        add_action('admin_notices', 'snopeak_events_acf_notice');
        return;
    }

    // Register class-based shortcodes
    if (class_exists('Event_Duration_Shortcode')) {
        Event_Duration_Shortcode::register();
    }

    if (class_exists('Event_Counts_Shortcodes')) {
        Event_Counts_Shortcodes::register();
    }


    if (class_exists('Event_Month_Query')) {
        Event_Month_Query::register();
    }

    if (class_exists('Event_Location_Organisers_Shortcode')) {
        Event_Location_Organisers_Shortcode::register();
    }

    if ( class_exists( 'SPK_Location_Amenities_Query' ) ) {
        SPK_Location_Amenities_Query::register();
    }
}

/**
 * Display admin notice if ACF is not active
 */
function snopeak_events_acf_notice() {
    ?>
    <div class="notice notice-error">
        <p><strong>SnoPeak Events:</strong> This plugin requires Advanced Custom Fields (ACF) to be installed and activated.</p>
    </div>
    <?php
}

