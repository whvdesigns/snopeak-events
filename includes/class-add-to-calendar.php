<?php
/**
 * Add to Calendar
 *
 * Shortcode: [spk_add_to_calendar]
 * ICS endpoint: ?spk_ical=POST_ID
 *
 * Outputs Google Calendar, Apple Calendar (ICS download), and Outlook.com links
 * for the current event post. All events are treated as all-day (no times stored).
 *
 * @since 1.3.79
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPK_Add_To_Calendar {

	public function __construct() {
		add_shortcode( 'spk_add_to_calendar', [ $this, 'render_shortcode' ] );
		add_action( 'template_redirect', [ $this, 'handle_ics_download' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets() {
		if ( ! is_singular( 'event' ) ) {
			return;
		}
		wp_enqueue_style(
			'spk-add-to-calendar',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/add-to-calendar.css',
			[],
			'1.3.79'
		);
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	public function render_shortcode( $atts ) {
		$post_id = get_the_ID();
		if ( ! $post_id || get_post_type( $post_id ) !== 'event' ) {
			return '';
		}

		$data = $this->get_event_data( $post_id );
		if ( ! $data ) {
			return '';
		}

		$google_url  = $this->google_url( $data );
		$outlook_url = $this->outlook_url( $data );
		$ics_url     = add_query_arg( 'spk_ical', $post_id, home_url( '/' ) );

		ob_start();
		?>
		<div class="spk-add-to-calendar">
			<a class="spk-atc-btn spk-atc-btn--google"
			   href="<?php echo esc_url( $google_url ); ?>"
			   target="_blank"
			   rel="noopener noreferrer"
			   title="Add to Google Calendar"
			   aria-label="Add to Google Calendar">
				<i class="fab fa-google" aria-hidden="true"></i>
			</a>
			<a class="spk-atc-btn spk-atc-btn--apple"
			   href="<?php echo esc_url( $ics_url ); ?>"
			   title="Add to Apple Calendar"
			   aria-label="Add to Apple Calendar">
				<i class="fab fa-apple" aria-hidden="true"></i>
			</a>
			<a class="spk-atc-btn spk-atc-btn--outlook"
			   href="<?php echo esc_url( $outlook_url ); ?>"
			   target="_blank"
			   rel="noopener noreferrer"
			   title="Add to Outlook"
			   aria-label="Add to Outlook">
				<i class="fab fa-microsoft" aria-hidden="true"></i>
			</a>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// ICS download endpoint
	// -------------------------------------------------------------------------

	public function handle_ics_download() {
		if ( empty( $_GET['spk_ical'] ) ) {
			return;
		}

		$post_id = absint( $_GET['spk_ical'] );
		if ( ! $post_id || get_post_type( $post_id ) !== 'event' ) {
			wp_die( 'Invalid event.', 404 );
		}

		$data = $this->get_event_data( $post_id );
		if ( ! $data ) {
			wp_die( 'Event data unavailable.', 404 );
		}

		$filename = sanitize_title( $data['title'] ) . '.ics';

		// ICS end date is exclusive (day after last day).
		$ics_end = clone $data['end_dt'];
		$ics_end->modify( '+1 day' );

		$uid     = $post_id . '@' . parse_url( home_url(), PHP_URL_HOST );
		$now     = gmdate( 'Ymd\THis\Z' );
		$summary = $this->ics_escape( $data['title'] );
		$loc     = $this->ics_escape( $data['location'] );

		// Description: category only — URL goes in the dedicated URL: property.
		$desc_parts = array_filter( [ $data['category'] ] );
		$desc       = $this->ics_escape( implode( "

", $desc_parts ) );

		$ics  = "BEGIN:VCALENDAR\r\n";
		$ics .= "VERSION:2.0\r\n";
		$ics .= "PRODID:-//SnoPeak Events//EN\r\n";
		$ics .= "CALSCALE:GREGORIAN\r\n";
		$ics .= "METHOD:PUBLISH\r\n";
		$ics .= "BEGIN:VEVENT\r\n";
		$ics .= "UID:{$uid}\r\n";
		$ics .= "DTSTAMP:{$now}\r\n";
		$ics .= "DTSTART;VALUE=DATE:" . $data['start_dt']->format( 'Ymd' ) . "\r\n";
		$ics .= "DTEND;VALUE=DATE:" . $ics_end->format( 'Ymd' ) . "\r\n";
		$ics .= "SUMMARY:{$summary}\r\n";
		if ( $loc ) {
			$ics .= "LOCATION:{$loc}\r\n";
		}
		if ( $desc ) {
			$ics .= "DESCRIPTION:{$desc}\r\n";
		}
		if ( $data['url'] ) {
			$ics .= "URL:" . $data['url'] . "\r\n";
		}
		$ics .= "END:VEVENT\r\n";
		$ics .= "END:VCALENDAR\r\n";

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	// -------------------------------------------------------------------------
	// URL builders
	// -------------------------------------------------------------------------

	private function google_url( array $data ) {
		// Google all-day end date is exclusive.
		$end_google = clone $data['end_dt'];
		$end_google->modify( '+1 day' );

		$desc_parts = array_filter( [ $data['category'] ] );
		$details    = implode( "\n\n", $desc_parts );

		return add_query_arg(
			[
				'action'   => 'TEMPLATE',
				'text'     => $data['title'],
				'dates'    => $data['start_dt']->format( 'Ymd' ) . '/' . $end_google->format( 'Ymd' ),
				'location' => $data['location'],
				'details'  => $details,
			],
			'https://calendar.google.com/calendar/render'
		);
	}

	private function outlook_url( array $data ) {
		$body_parts = array_filter( [ $data['category'], $data['url'] ] );
		$body       = implode( "\n\n", $body_parts );

		return add_query_arg(
			[
				'rru'      => 'addevent',
				'startdt'  => $data['start_dt']->format( 'Y-m-d' ),
				'enddt'    => $data['end_dt']->format( 'Y-m-d' ),
				'subject'  => $data['title'],
				'location' => $data['location'],
				'body'     => $body,
			],
			'https://outlook.live.com/calendar/0/action/compose'
		);
	}

	// -------------------------------------------------------------------------
	// Data helper
	// -------------------------------------------------------------------------

	/**
	 * Returns normalised event data array, or false on failure.
	 *
	 * @param int $post_id
	 * @return array|false
	 */
	private function get_event_data( int $post_id ) {
		$start_raw = get_field( 'event_start_date', $post_id ); // "d/m/Y"
		if ( ! $start_raw ) {
			return false;
		}

		$start_dt = DateTime::createFromFormat( 'd/m/Y', $start_raw );
		if ( ! $start_dt ) {
			return false;
		}

		$end_raw = get_field( 'event_end_date', $post_id );
		if ( $end_raw ) {
			$end_dt = DateTime::createFromFormat( 'd/m/Y', $end_raw );
		}
		if ( empty( $end_dt ) || ! $end_dt ) {
			$end_dt = clone $start_dt;
		}

		// Location: first linked location post title.
		$location_name = '';
		$location_rel  = get_field( 'event_location', $post_id );
		if ( ! empty( $location_rel ) && is_array( $location_rel ) ) {
			$loc_post = is_object( $location_rel[0] ) ? $location_rel[0] : get_post( $location_rel[0] );
			if ( $loc_post ) {
				$location_name = $loc_post->post_title;
			}
		}

		// Category: first term name.
		$category_name = '';
		$terms         = get_the_terms( $post_id, 'event-category' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$category_name = $terms[0]->name;
		}

		return [
			'title'    => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
			'start_dt' => $start_dt,
			'end_dt'   => $end_dt,
			'location' => $location_name,
			'category' => $category_name,
			'url'      => get_permalink( $post_id ),
		];
	}

	// -------------------------------------------------------------------------
	// Utility
	// -------------------------------------------------------------------------

	private function ics_escape( string $str ): string {
		return addcslashes( $str, "\\;,\n" );
	}
}

new SPK_Add_To_Calendar();
