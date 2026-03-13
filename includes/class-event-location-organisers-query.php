<?php
/**
 * Location Organisers Query Class
 *
 * Custom Bricks Builder query type to display organisers
 * for the current location.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Event_Location_Organisers_Query {

	/**
	 * Constructor - register hooks
	 */
	public function __construct() {
		add_filter( 'bricks/query/types', array( $this, 'register_type' ) );
		add_filter( 'bricks/query/run',   array( $this, 'run_query' ), 10, 2 );
	}

	/**
	 * Register custom query type
	 *
	 * @param array $types Existing query types.
	 * @return array Modified query types.
	 */
	public function register_type( $types ) {
		$types['location_organisers'] = array(
			'label'   => 'Location – Organisers',
			'options' => array(),
		);

		return $types;
	}

	/**
	 * Run the custom query
	 *
	 * @param object $results    Query results.
	 * @param object $query_obj  Query object.
	 * @return object Modified results.
	 */
	public function run_query( $results, $query_obj ) {
		if ( ! isset( $query_obj->type ) || $query_obj->type !== 'location_organisers' ) {
			return $results;
		}

		$location_id   = absint( get_the_ID() );
		$organiser_ids = spk_get_organiser_ids_for_location( $location_id );

		if ( empty( $organiser_ids ) ) {
			$results->posts      = array();
			$results->found_posts = 0;
			return $results;
		}

		$organisers = get_posts( array(
			'post_type'      => 'event-organiser',
			'post__in'       => $organiser_ids,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		) );

		$results->posts      = $organisers;
		$results->found_posts = count( $organisers );

		return $results;
	}
}

/**
 * Initialize when Bricks is ready
 */
add_action( 'bricks/setup/control_types', function () {
	new Event_Location_Organisers_Query();
} );
