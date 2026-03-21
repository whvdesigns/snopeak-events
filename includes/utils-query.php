<?php
/**
 * Query Utilities
 *
 * Shared query helpers used across filter classes and shortcodes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get organiser IDs for a location, ranked by event frequency.
 *
 * Queries all published events at the given location, counts how many events
 * each organiser has there, and returns the top $limit IDs ordered most →
 * least frequent.
 *
 * ACF relationship fields return WP_Post objects by default — IDs are
 * extracted with is_object() check, matching the pattern in
 * class-related-events-query.php.
 *
 * @param int $location_id  Post ID of the location.
 * @param int $limit        Maximum number of organiser IDs to return (default 5).
 * @return int[]            Organiser post IDs ordered by event count desc.
 */
function spk_get_organiser_ids_for_location( $location_id, $limit = 5 ) {
	$location_id = absint( $location_id );
	$limit       = absint( $limit );

	if ( ! $location_id ) {
		return array();
	}

	$events = get_posts( array(
		'post_type'      => 'event',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => array(
			array(
				'key'     => 'event_location',
				'value'   => '"' . $location_id . '"',
				'compare' => 'LIKE',
			),
		),
	) );

	if ( empty( $events ) ) {
		return array();
	}

	// Count events per organiser.
	// get_field() returns WP_Post objects for relationship fields by default.
	$counts = array();

	foreach ( $events as $event_id ) {
		$orgs = get_field( 'event_organiser', $event_id );

		if ( empty( $orgs ) ) {
			continue;
		}

		foreach ( (array) $orgs as $org ) {
			$org_id = is_object( $org ) ? $org->ID : absint( $org );
			if ( ! $org_id ) {
				continue;
			}
			$counts[ $org_id ] = isset( $counts[ $org_id ] ) ? $counts[ $org_id ] + 1 : 1;
		}
	}

	if ( empty( $counts ) ) {
		return array();
	}

	arsort( $counts );

	return array_slice( array_keys( $counts ), 0, $limit );
}
