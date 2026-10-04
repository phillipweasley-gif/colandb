<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The read-only integration API other plugins use to read public events
 * (added 1.26.0 for the member-planning plugin). Per the project brief,
 * the member plugin stores an event's ID only and reads current event data
 * through here every time, never copying title/date/location.
 *
 * Contract:
 * - Only a published cec_event is ever returned; anything else (draft,
 *   pending, in review, trashed, wrong post type, missing) returns null.
 *   A cancelled event is still published, so it is returned with
 *   event_status 'cancelled'; a past one with is_past true.
 * - Only fields already shown on the public event page are included —
 *   editorial fields (source URL, price-review flag) and RSVP data are not.
 * - Nothing here writes, and nothing in this plugin reads member data.
 *
 * Bump CEC_PUBLIC_API_VERSION if a returned key is removed or changes
 * meaning; adding a key is backward compatible.
 */
define( 'CEC_PUBLIC_API_VERSION', 1 );

class CEC_Public_API {

	const FIELDS = array(
		'id',
		'title',
		'title_plain',
		'permalink',
		'start_raw',
		'start_ts',
		'end_ts',
		'time_mode',
		'timezone',
		'timezone_abbr',
		'start_display',
		'end_display',
		'date_range_display',
		'when_display',
		'display_time_mode',
		'host_org_name',
		'official_website_url',
		'location_mode',
		'venue_name',
		'city_region_country',
		'online_url',
		'admission_status',
		'event_status',
		'is_past',
	);

	public static function get( $event_id ) {
		$event_id = absint( $event_id );
		$post     = $event_id ? get_post( $event_id ) : null;
		if ( ! $post || 'cec_event' !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		$data  = CEC_Event_Helper::data( $event_id );
		$event = array_intersect_key( $data, array_flip( self::FIELDS ) );

		$end_raw          = get_post_meta( $event_id, '_cec_end', true );
		$event['end_raw'] = $end_raw ? $end_raw : '';

		$badge                     = CEC_Event_Helper::admission_badge( $data );
		$event['admission_label']  = $badge['label'];
		$where                     = CEC_Event_Helper::location_display( $data );
		$event['location_label']   = $where['label'];
		$event['grid_item']        = CEC_Month_Grid::item_from_event_data( $data );

		return $event;
	}

	/**
	 * Same as get() for several IDs at once; unpublished/missing IDs are
	 * simply absent from the result, which is keyed by event ID.
	 */
	public static function get_many( $event_ids ) {
		$event_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $event_ids ) ) ) );
		if ( ! $event_ids ) {
			return array();
		}
		_prime_post_caches( $event_ids, true, true );

		$events = array();
		foreach ( $event_ids as $id ) {
			$event = self::get( $id );
			if ( $event ) {
				$events[ $id ] = $event;
			}
		}
		return $events;
	}
}

/**
 * Public, published event data for one event ID, or null. See CEC_Public_API.
 */
function cec_get_public_event( $event_id ) {
	return CEC_Public_API::get( $event_id );
}

/**
 * Public, published event data for several IDs, keyed by ID. See CEC_Public_API.
 */
function cec_get_public_events( $event_ids ) {
	return CEC_Public_API::get_many( $event_ids );
}
