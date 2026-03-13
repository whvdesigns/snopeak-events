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
		if ( ! is_singular( 'location' ) ) {
			return '';
		}

		$location_id   = absint( get_the_ID() );
		$organiser_ids = spk_get_organiser_ids_for_location( $location_id );

		if ( empty( $organiser_ids ) ) {
			return '<p>No organisers found for this location.</p>';
		}

		// Sort alphabetically by title
		usort( $organiser_ids, function ( $a, $b ) {
			return strcasecmp( get_the_title( $a ), get_the_title( $b ) );
		} );

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
