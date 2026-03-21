<?php
/**
 * Location Organisers – Bricks Posts Query Filter
 *
 * Hooks into bricks/posts/query_vars to modify a standard Bricks Posts element.
 *
 * Setup in Bricks:
 *   1. Add a Posts loop element to your location template
 *   2. Set Query → Post Type → event-organiser
 *   3. In Style tab → CSS ID → set to: spk-location-organisers
 *   4. Set Posts Per Page to 5 (or leave unlimited — query caps at 5 internally)
 *
 * The filter detects that CSS ID, parses the location from the URL, and
 * replaces post__in with the top 5 organisers ranked by event frequency.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'bricks/posts/query_vars', function ( $query_vars, $settings, $element_id ) {

	$css_id = isset( $settings['_cssId'] ) ? $settings['_cssId'] : '';
	if ( $css_id !== 'spk-location-organisers' ) {
		return $query_vars;
	}

	// Parse location from URL — get_queried_object_id() returns template ID in Bricks.
	$path  = trim( wp_unslash( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ), '/' );
	$parts = explode( '/', $path );
	$idx   = array_search( 'location', $parts );

	if ( false === $idx || ! isset( $parts[ $idx + 1 ] ) ) {
		$query_vars['post__in'] = array( 0 );
		return $query_vars;
	}

	$loc_post    = get_page_by_path( sanitize_title( $parts[ $idx + 1 ] ), OBJECT, 'location' );
	$location_id = $loc_post ? (int) $loc_post->ID : 0;

	if ( ! $location_id ) {
		$query_vars['post__in'] = array( 0 );
		return $query_vars;
	}

	$organiser_ids = spk_get_organiser_ids_for_location( $location_id );

	if ( empty( $organiser_ids ) ) {
		$query_vars['post__in'] = array( 0 );
		return $query_vars;
	}

	$query_vars['post_type']      = 'event-organiser';
	$query_vars['post__in']       = $organiser_ids;
	$query_vars['orderby']        = 'post__in';
	$query_vars['posts_per_page'] = -1;

	// Remove any meta_key ordering that may conflict with post__in order.
	unset( $query_vars['meta_key'], $query_vars['meta_query'], $query_vars['order'] );

	return $query_vars;

}, 10, 3 );
