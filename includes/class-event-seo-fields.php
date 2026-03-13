<?php
/**
 * Event SEO Fields
 *
 * Resolves ACF relationship and date fields into plain meta values
 * that SureRank can use in title and description templates.
 *
 * Writes the following meta fields on every event save:
 *   event_organiser_seo    — organiser post title (relationship → string)
 *   event_location_seo     — location post title (relationship → string)
 *   event_start_date_seo   — formatted start date e.g. Mar 7th 2026
 *   event_date_range_seo   — start date, or "Mar 7th 2026 - Mar 9th 2026" if multi-day
 *   event_category_seo     — comma-separated event-category taxonomy names
 *
 * SureRank templates:
 *   Title: %title% | %custom_field.event_category_seo% | %custom_field.event_organiser_seo% | %custom_field.event_location_seo% | %custom_field.event_date_range_seo%
 *   Desc:  Join %custom_field.event_organiser_seo% at %custom_field.event_location_seo% for %title% – a %custom_field.event_category_seo% on %custom_field.event_date_range_seo%.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'acf/save_post', 'spk_update_event_seo_fields', 20 );

function spk_update_event_seo_fields( $post_id ) {

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( get_post_type( $post_id ) !== 'event' ) return;
    if ( ! function_exists( 'get_field' ) ) return;

    // --- Organiser (relationship → post title) ---
    $org_ids = get_field( 'event_organiser', $post_id );
    if ( ! empty( $org_ids ) ) {
        $id = is_array( $org_ids ) ? $org_ids[0] : $org_ids;
        $org_id = is_object( $id ) ? $id->ID : $id;
        update_post_meta( $post_id, 'event_organiser_seo', get_the_title( $org_id ) );
    }

    // --- Location (relationship → post title) ---
    $loc_ids = get_field( 'event_location', $post_id );
    if ( ! empty( $loc_ids ) ) {
        $id = is_array( $loc_ids ) ? $loc_ids[0] : $loc_ids;
        $loc_id = is_object( $id ) ? $id->ID : $id;
        update_post_meta( $post_id, 'event_location_seo', get_the_title( $loc_id ) );
    }

    // --- Dates (d/m/Y format from ACF date_picker) ---
    $start_raw = get_field( 'event_start_date', $post_id );
    $start_formatted = '';

    if ( $start_raw ) {
        $start_obj = DateTime::createFromFormat( 'd/m/Y', $start_raw );
        if ( $start_obj ) {
            $start_formatted = $start_obj->format( 'M jS Y' );
            update_post_meta( $post_id, 'event_start_date_seo', $start_formatted );
        }
    }

    $end_raw = get_field( 'event_end_date', $post_id );
    if ( $end_raw && $end_raw !== $start_raw ) {
        $end_obj = DateTime::createFromFormat( 'd/m/Y', $end_raw );
        $date_range = $end_obj
            ? $start_formatted . ' - ' . $end_obj->format( 'M jS Y' )
            : $start_formatted;
    } else {
        $date_range = $start_formatted;
    }
    update_post_meta( $post_id, 'event_date_range_seo', $date_range );

    // --- Event category (taxonomy → comma-separated names) ---
    $terms = wp_get_post_terms( $post_id, 'event-category', [ 'fields' => 'names' ] );
    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
        update_post_meta( $post_id, 'event_category_seo', implode( ', ', $terms ) );
    }
}
