<?php
/**
 * Query Utilities
 *
 * Shared query helpers used across Bricks query classes and shortcodes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get unique organiser IDs linked to a location via its events.
 *
 * Uses LIKE comparison on the event_location meta key so that ACF
 * relationship values (stored as serialised arrays) are matched correctly.
 *
 * @param int $location_id  Post ID of the location.
 * @return int[]            Array of unique organiser post IDs, or empty array.
 */
function spk_get_organiser_ids_for_location( $location_id ) {
	$location_id = absint( $location_id );

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

	$organiser_ids = array();

	foreach ( $events as $event_id ) {
		$orgs = get_field( 'event_organiser', $event_id );

		if ( is_array( $orgs ) ) {
			foreach ( $orgs as $org ) {
				$organiser_ids[] = (int) $org;
			}
		} elseif ( ! empty( $orgs ) ) {
			$organiser_ids[] = (int) $orgs;
		}
	}

	return array_values( array_unique( $organiser_ids ) );
}
