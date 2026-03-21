<?php
/**
 * Location Organisers Shortcode Class
 *
 * Displays a list of organisers associated with a location
 * based on events held at that location.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Event_Location_Organisers_Shortcode {

	/**
	 * Register the shortcode
	 */
	public static function register() {
		add_shortcode( 'spk_location_organisers', array( __CLASS__, 'render' ) );
	}

	/**
	 * Render organisers list for current location
	 *
	 * @return string HTML output.
	 */
	public static function render() {
		// Bricks returns the template ID from get_the_ID() — parse from URL instead.
		$path  = trim( wp_unslash( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ), '/' );
		$parts = explode( '/', $path );
		$idx   = array_search( 'location', $parts );

		if ( false === $idx || ! isset( $parts[ $idx + 1 ] ) ) {
			return '';
		}

		$loc_post    = get_page_by_path( sanitize_title( $parts[ $idx + 1 ] ), OBJECT, 'location' );
		$location_id = $loc_post ? (int) $loc_post->ID : 0;

		if ( ! $location_id ) {
			return '';
		}

		$organiser_ids = spk_get_organiser_ids_for_location( $location_id );

		if ( empty( $organiser_ids ) ) {
			return '<p>No organisers found for this location.</p>';
		}

		// Organiser IDs are already ordered by event frequency (most common first).
		$output = '<ul class="spk-location-organisers">';

		foreach ( $organiser_ids as $org_id ) {
			$output .= '<li><a href="' . esc_url( get_permalink( $org_id ) ) . '">'
				. esc_html( get_the_title( $org_id ) )
				. '</a></li>';
		}

		$output .= '</ul>';

		return $output;
	}
}
