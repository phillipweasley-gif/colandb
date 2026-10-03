<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_Event_Helper {

	/**
	 * Legacy admission data (a plain is_free checkbox + free-text price note)
	 * predates the four-state admission model below. Rather than migrate
	 * storage, _cec_admission_status is the new source of truth going
	 * forward and is_free is now DERIVED from it in data() — every existing
	 * template/display call site that reads $data['is_free'] keeps working
	 * unchanged.
	 */
	const ADMISSION_STATES = array( 'free_confirmed', 'paid', 'price_varies', 'not_posted' );
	const LOCATION_MODES   = array( 'in_person', 'online', 'hybrid', 'not_posted' );
	const TIME_MODES       = array( 'exact', 'start_only', 'all_day', 'varies' );

	public static function data( $post_id ) {
		$start_raw = get_post_meta( $post_id, '_cec_start', true );
		$end_raw   = get_post_meta( $post_id, '_cec_end', true );

		$location_mode = get_post_meta( $post_id, '_cec_location_mode', true );
		$location_mode = in_array( $location_mode, self::LOCATION_MODES, true ) ? $location_mode : 'in_person';

		$venue_terms = get_the_terms( $post_id, 'cec_venue' );
		$venue_name  = '';
		$maps_url    = get_post_meta( $post_id, '_cec_venue_custom_maps_url', true );
		$address     = get_post_meta( $post_id, '_cec_venue_custom_address', true );
		$city        = get_post_meta( $post_id, '_cec_venue_custom_city', true );
		$region      = get_post_meta( $post_id, '_cec_venue_custom_region', true );
		$country     = get_post_meta( $post_id, '_cec_venue_custom_country', true );
		$venue_tz    = '';

		if ( $venue_terms && ! is_wp_error( $venue_terms ) ) {
			$venue_name = $venue_terms[0]->name;
			if ( ! $maps_url ) {
				$maps_url = CEC_Term_Meta::venue_map_url( $venue_terms[0]->term_id );
			}
			if ( ! $address ) {
				$address = get_term_meta( $venue_terms[0]->term_id, 'cec_address', true );
			}
			if ( ! $city ) {
				$city = get_term_meta( $venue_terms[0]->term_id, 'cec_city', true );
			}
			if ( ! $region ) {
				$region = get_term_meta( $venue_terms[0]->term_id, 'cec_region', true );
			}
			if ( ! $country ) {
				$country = get_term_meta( $venue_terms[0]->term_id, 'cec_country', true );
			}
			$venue_tz = get_term_meta( $venue_terms[0]->term_id, 'cec_timezone', true );
		} else {
			$venue_name = $address;
		}

		if ( ! $maps_url && $address ) {
			$maps_url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $address );
		}

		$city_region_country = implode( ', ', array_filter( array( $city, $region, $country ) ) );

		$timezone = get_post_meta( $post_id, '_cec_timezone', true );
		if ( ! $timezone ) {
			$timezone = $venue_tz;
		}
		if ( ! $timezone ) {
			$timezone = wp_timezone_string();
		}

		$time_mode = get_post_meta( $post_id, '_cec_time_mode', true );
		$time_mode = in_array( $time_mode, self::TIME_MODES, true ) ? $time_mode : 'exact';

		$admission_status = self::admission_status( $post_id );

		$partner_terms = get_the_terms( $post_id, 'cec_partner_org' );
		$partners      = array();
		if ( $partner_terms && ! is_wp_error( $partner_terms ) ) {
			foreach ( $partner_terms as $t ) {
				$partners[] = array(
					'name' => $t->name,
					'link' => get_term_link( $t ),
				);
			}
		}

		$type_terms = get_the_terms( $post_id, 'cec_event_type' );
		$types      = array();
		if ( $type_terms && ! is_wp_error( $type_terms ) ) {
			foreach ( $type_terms as $t ) {
				$types[] = $t->name;
			}
		}

		return array(
			'id'               => $post_id,
			'title'            => get_the_title( $post_id ),
			'permalink'        => get_permalink( $post_id ),
			'excerpt'          => get_the_excerpt( $post_id ),
			'content'          => apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) ),
			'thumb_calendar'   => get_the_post_thumbnail_url( $post_id, 'cec_calendar_thumb' ),
			'thumb_card'       => get_the_post_thumbnail_url( $post_id, 'cec_card_thumb' ),
			'start_raw'        => $start_raw,
			'start_ts'         => $start_raw ? strtotime( $start_raw ) : 0,
			'end_ts'           => $end_raw ? strtotime( $end_raw ) : 0,
			'time_mode'        => $time_mode,
			'timezone'         => $timezone,
			'timezone_abbr'    => in_array( $time_mode, array( 'exact', 'start_only' ), true ) ? self::timezone_abbr( $timezone, $start_raw ) : '',
			'start_display'    => self::format_start_display( $start_raw, $time_mode ),
			'end_display'      => ( 'exact' === $time_mode && $end_raw ) ? date_i18n( 'g:i a', strtotime( $end_raw ) ) : '',
			'date_range_display' => self::format_date_range( $start_raw, $end_raw, $time_mode ),
			'host_org_name'    => get_post_meta( $post_id, '_cec_host_org_name', true ),
			'host_org_url'     => get_post_meta( $post_id, '_cec_host_org_url', true ),
			'official_website_url' => get_post_meta( $post_id, '_cec_official_website_url', true ),
			'more_info_url'    => get_post_meta( $post_id, '_cec_more_info_url', true ),
			'organizers'       => get_post_meta( $post_id, '_cec_organizers', true ),
			'location_mode'    => $location_mode,
			'venue_name'       => $venue_name,
			'address'          => $address,
			'city'             => $city,
			'region'           => $region,
			'country'          => $country,
			'city_region_country' => $city_region_country,
			'maps_url'         => $maps_url,
			'online_url'       => get_post_meta( $post_id, '_cec_online_url', true ),
			'admission_status' => $admission_status,
			'is_free'          => 'free_confirmed' === $admission_status,
			'price_amount'     => get_post_meta( $post_id, '_cec_price_amount', true ),
			'price_min'        => get_post_meta( $post_id, '_cec_price_min', true ),
			'price_max'        => get_post_meta( $post_id, '_cec_price_max', true ),
			'price_currency'   => get_post_meta( $post_id, '_cec_price_currency', true ) ? get_post_meta( $post_id, '_cec_price_currency', true ) : 'USD',
			'price_note'       => get_post_meta( $post_id, '_cec_price_note', true ),
			'price_source_url' => get_post_meta( $post_id, '_cec_price_source_url', true ),
			'source_url'       => get_post_meta( $post_id, '_cec_source_url', true ),
			'last_updated_display' => get_post_field( 'post_modified', $post_id ) ? date_i18n( 'M j, Y', strtotime( get_post_field( 'post_modified', $post_id ) ) ) : '',
			'rsvp_mode'        => get_post_meta( $post_id, '_cec_rsvp_mode', true ),
			'rsvp_url'         => get_post_meta( $post_id, '_cec_rsvp_url', true ),
			'rsvp_capacity'    => (int) get_post_meta( $post_id, '_cec_rsvp_capacity', true ),
			'rsvp_taken'       => CEC_RSVP::count_for_event( $post_id ),
			'code_of_conduct'  => get_post_meta( $post_id, '_cec_code_of_conduct', true ),
			'photo_alt'        => get_post_meta( $post_id, '_cec_photo_alt', true ),
			'partners'         => $partners,
			'types'            => $types,
			'event_status'     => self::event_status( $post_id ),
			'event_status_note' => get_post_meta( $post_id, '_cec_event_status_note', true ),
			'is_recurring_root' => ! CEC_Recurrence::is_occurrence( $post_id ) && 'none' !== get_post_meta( $post_id, '_cec_recurrence_rule', true ) && get_post_meta( $post_id, '_cec_recurrence_rule', true ),
			'is_occurrence'    => CEC_Recurrence::is_occurrence( $post_id ),
			'is_past'          => self::is_past( $start_raw ? strtotime( $start_raw ) : 0, $end_raw ? strtotime( $end_raw ) : 0 ),
		);
	}

	/**
	 * "Past" is derived from the effective end moment (end date/time if
	 * known, otherwise the start) rather than stored as its own status —
	 * per the brief: a past event isn't a different kind of record, just
	 * one whose time has already elapsed.
	 */
	public static function is_past( $start_ts, $end_ts ) {
		if ( ! $start_ts ) {
			return false;
		}
		$effective_end = $end_ts ? $end_ts : $start_ts;
		return $effective_end < current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	}

	/**
	 * _cec_admission_status is the new source of truth for Cost (see the
	 * class const comment above); anything not yet migrated or not one of
	 * the four valid states safely falls back to "Price not posted" rather
	 * than guessing Free — matching the brief's explicit migration rule
	 * that a legacy ambiguous value must never be auto-promoted to Free.
	 */
	public static function admission_status( $post_id ) {
		$status = get_post_meta( $post_id, '_cec_admission_status', true );
		return in_array( $status, self::ADMISSION_STATES, true ) ? $status : 'not_posted';
	}

	/**
	 * The time portion is only meaningful when the organizer actually gave
	 * one — showing "12:00 am" for an all-day or schedule-varies event is
	 * exactly the fake-precision the brief calls out, so those modes
	 * deliberately drop the time off the stored datetime value entirely.
	 */
	private static function format_start_display( $start_raw, $time_mode ) {
		if ( ! $start_raw ) {
			return '';
		}
		$start_ts = strtotime( $start_raw );
		if ( 'all_day' === $time_mode ) {
			return date_i18n( 'D, M j, Y', $start_ts );
		}
		if ( 'varies' === $time_mode ) {
			/* translators: %s: event date, e.g. "Fri, Oct 9, 2026" */
			return sprintf( __( '%s (schedule varies)', 'cec' ), date_i18n( 'D, M j, Y', $start_ts ) );
		}
		return date_i18n( 'D, M j, Y g:i a', $start_ts );
	}

	/**
	 * A compact date (range) for contexts with no room for a full
	 * start/end display — the Upcoming Events ticker in particular,
	 * where a multi-day event previously showed only its start date/time
	 * with nothing indicating it ran for several more days.
	 *
	 * Single-day events keep the existing "Fri, Oct 9 6:00 pm" style
	 * (unchanged for time_mode "exact"/"start_only"); a multi-day event
	 * instead shows a date range, e.g. "Oct 9 – 13" (same month), "Oct 9 –
	 * Nov 2" (different months), or "Dec 30, 2026 – Jan 2, 2027" (different
	 * years). All-day/schedule-varies events never show a time, per
	 * format_start_display() above.
	 */
	private static function format_date_range( $start_raw, $end_raw, $time_mode = 'exact' ) {
		if ( ! $start_raw ) {
			return '';
		}
		$start_ts    = strtotime( $start_raw );
		$show_time   = in_array( $time_mode, array( 'exact', 'start_only' ), true );
		$day_format  = $show_time ? 'D, M j, Y g:i a' : 'D, M j, Y';

		if ( ! $end_raw ) {
			return date_i18n( $day_format, $start_ts );
		}
		$end_ts = strtotime( $end_raw );

		if ( date_i18n( 'Y-m-d', $start_ts ) === date_i18n( 'Y-m-d', $end_ts ) ) {
			return date_i18n( $day_format, $start_ts );
		}

		$same_year  = date_i18n( 'Y', $start_ts ) === date_i18n( 'Y', $end_ts );
		$same_month = $same_year && date_i18n( 'n', $start_ts ) === date_i18n( 'n', $end_ts );

		if ( $same_month ) {
			/* translators: 1: start day (e.g. "Oct 9"), 2: end day number (e.g. "13") */
			return sprintf( __( '%1$s–%2$s', 'cec' ), date_i18n( 'M j', $start_ts ), date_i18n( 'j', $end_ts ) );
		}
		if ( $same_year ) {
			/* translators: 1: start date (e.g. "Oct 9"), 2: end date (e.g. "Nov 2") */
			return sprintf( __( '%1$s – %2$s', 'cec' ), date_i18n( 'M j', $start_ts ), date_i18n( 'M j', $end_ts ) );
		}
		/* translators: 1: start date with year (e.g. "Dec 30, 2026"), 2: end date with year (e.g. "Jan 2, 2027") */
		return sprintf( __( '%1$s – %2$s', 'cec' ), date_i18n( 'M j, Y', $start_ts ), date_i18n( 'M j, Y', $end_ts ) );
	}

	/**
	 * The split month/day-range/year-weekday-time pieces behind the
	 * Upcoming list's "date-range-first" card — a big day number needs its
	 * own month/year lines around it, which date_range_display()'s single
	 * compact string isn't built to split back apart. Degrades to that same
	 * compact string (with no separate month/year) for the rare event that
	 * spans a calendar-month boundary, rather than contorting a "big
	 * number" to fit two different months.
	 */
	public static function date_block( $data ) {
		if ( ! $data['start_ts'] ) {
			return array( 'month' => '', 'days' => '', 'year_line' => '' );
		}
		$start_ts   = $data['start_ts'];
		$end_ts     = $data['end_ts'] ? $data['end_ts'] : $start_ts;
		$same_day   = date_i18n( 'Y-m-d', $start_ts ) === date_i18n( 'Y-m-d', $end_ts );
		$same_month = date_i18n( 'Y-m', $start_ts ) === date_i18n( 'Y-m', $end_ts );

		if ( $same_day ) {
			$suffix = '';
			if ( 'varies' === $data['time_mode'] ) {
				$suffix = ' · ' . __( 'Schedule varies', 'cec' );
			} elseif ( in_array( $data['time_mode'], array( 'exact', 'start_only' ), true ) ) {
				$suffix = ' · ' . date_i18n( 'g:i a', $start_ts ) . ( $data['timezone_abbr'] ? ' ' . $data['timezone_abbr'] : '' );
			}
			return array(
				'month'     => date_i18n( 'F', $start_ts ),
				'days'      => date_i18n( 'j', $start_ts ),
				'year_line' => date_i18n( 'Y', $start_ts ) . ' · ' . date_i18n( 'D', $start_ts ) . $suffix,
			);
		}

		if ( $same_month ) {
			return array(
				'month'     => date_i18n( 'F', $start_ts ),
				/* translators: 1: start day number, 2: end day number */
				'days'      => sprintf( __( '%1$s–%2$s', 'cec' ), date_i18n( 'j', $start_ts ), date_i18n( 'j', $end_ts ) ),
				/* translators: 1: year, 2: start weekday abbr, 3: end weekday abbr */
				'year_line' => sprintf( __( '%1$s · %2$s–%3$s', 'cec' ), date_i18n( 'Y', $start_ts ), date_i18n( 'D', $start_ts ), date_i18n( 'D', $end_ts ) ),
			);
		}

		return array( 'month' => '', 'days' => $data['date_range_display'], 'year_line' => '' );
	}

	/**
	 * The short abbreviation (e.g. "CDT", "PST") for an IANA timezone name
	 * at a given moment — shown next to a public start time so a visitor in
	 * a different timezone isn't misled into reading it as their own local
	 * time. Falls back to an empty string for an invalid/unrecognized zone
	 * rather than fatal — a bad stored value should degrade gracefully.
	 */
	private static function timezone_abbr( $timezone, $start_raw ) {
		if ( ! $timezone || ! $start_raw ) {
			return '';
		}
		try {
			$dt = new DateTime( str_replace( 'T', ' ', $start_raw ), new DateTimeZone( $timezone ) );
			return $dt->format( 'T' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	public static function event_status( $post_id ) {
		$status = get_post_meta( $post_id, '_cec_event_status', true );
		return in_array( $status, array( 'postponed', 'cancelled' ), true ) ? $status : 'scheduled';
	}

	/**
	 * The four-state admission model's display precedence (postponed/
	 * cancelled status always wins; otherwise Free confirmed / Paid /
	 * Price varies / Price not posted each get their own label and class)
	 * — centralized here so the calendar day-detail panel, the event card,
	 * and the single-event page all show the identical label/styling
	 * without duplicating the logic.
	 */
	public static function admission_badge( $data ) {
		if ( 'scheduled' !== $data['event_status'] ) {
			return array(
				'label' => ucfirst( $data['event_status'] ),
				'class' => 'cec-badge-' . $data['event_status'],
			);
		}
		switch ( $data['admission_status'] ) {
			case 'free_confirmed':
				return array( 'label' => __( 'Free', 'cec' ), 'class' => 'cec-badge-free' );
			case 'price_varies':
				$label = ( $data['price_min'] || $data['price_max'] )
					? self::format_price_range( $data )
					: __( 'Price Varies', 'cec' );
				return array( 'label' => $label, 'class' => 'cec-badge-varies' );
			case 'paid':
				$label = $data['price_amount'] ? self::format_price( $data['price_amount'], $data['price_currency'] ) : __( 'Paid', 'cec' );
				if ( $data['price_note'] ) {
					$label .= ' — ' . $data['price_note'];
				}
				return array( 'label' => $label, 'class' => 'cec-badge-paid' );
			case 'not_posted':
			default:
				return array( 'label' => __( 'Price Not Posted', 'cec' ), 'class' => 'cec-badge-not-posted' );
		}
	}

	public static function format_price( $amount, $currency = 'USD' ) {
		$float  = (float) $amount;
		// Whole-dollar amounts show with no decimals ("$10"); anything with
		// real cents keeps both digits ("$12.50") rather than the naive
		// trailing-zero-strip that would otherwise turn $12.50 into the
		// confusing "$12.5".
		$amount = ( floor( $float ) === $float ) ? number_format( $float, 0 ) : number_format( $float, 2 );
		return 'USD' === $currency ? '$' . $amount : $amount . ' ' . $currency;
	}

	private static function format_price_range( $data ) {
		if ( $data['price_min'] && $data['price_max'] ) {
			/* translators: 1: low price (e.g. "$5"), 2: high price (e.g. "$20") */
			return sprintf( __( '%1$s–%2$s', 'cec' ), self::format_price( $data['price_min'], $data['price_currency'] ), self::format_price( $data['price_max'], $data['price_currency'] ) );
		}
		$known = $data['price_min'] ? $data['price_min'] : $data['price_max'];
		/* translators: %s: a price, e.g. "$10" */
		return sprintf( __( 'From %s', 'cec' ), self::format_price( $known, $data['price_currency'] ) );
	}

	/**
	 * A short, consistent "Where" string — used by the single-event page,
	 * the calendar day-detail panel, and .ics export so the four location
	 * modes (in person / online / hybrid / not posted) never have to be
	 * re-interpreted ad hoc at each call site.
	 */
	public static function location_display( $data ) {
		$venue = $data['venue_name'] ? $data['venue_name'] : $data['address'];
		if ( $venue && $data['city_region_country'] ) {
			$venue .= ' — ' . $data['city_region_country'];
		}

		switch ( $data['location_mode'] ) {
			case 'online':
				return array(
					'label'      => __( 'Online', 'cec' ),
					'detail'     => '',
					'online_url' => $data['online_url'],
				);
			case 'hybrid':
				return array(
					'label'      => __( 'In Person & Online', 'cec' ),
					'detail'     => $venue,
					'online_url' => $data['online_url'],
				);
			case 'not_posted':
				return array(
					'label'      => __( 'Location Not Posted', 'cec' ),
					'detail'     => '',
					'online_url' => '',
				);
			case 'in_person':
			default:
				return array(
					'label'      => $venue ? $venue : __( 'Location Not Posted', 'cec' ),
					'detail'     => '',
					'online_url' => '',
				);
		}
	}

	/**
	 * Builds a schema.org/JSON-LD Event array from an already-assembled
	 * data() array, for search engines' rich-result eligibility.
	 */
	public static function event_schema( $data ) {
		$status_map = array(
			'scheduled' => 'https://schema.org/EventScheduled',
			'postponed' => 'https://schema.org/EventPostponed',
			'cancelled' => 'https://schema.org/EventCancelled',
		);

		$description = $data['excerpt'] ? $data['excerpt'] : wp_strip_all_tags( $data['content'] );

		$schema = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'Event',
			'name'                => $data['title'],
			'url'                 => $data['permalink'],
			'eventAttendanceMode' => self::schema_attendance_mode( $data['location_mode'] ),
			'eventStatus'         => isset( $status_map[ $data['event_status'] ] ) ? $status_map[ $data['event_status'] ] : $status_map['scheduled'],
		);

		if ( $description ) {
			$schema['description'] = $description;
		}
		if ( $data['start_ts'] ) {
			$schema['startDate'] = date_i18n( 'c', $data['start_ts'] );
		}
		if ( $data['end_ts'] ) {
			$schema['endDate'] = date_i18n( 'c', $data['end_ts'] );
		}
		if ( $data['thumb_card'] ) {
			$schema['image'] = array( $data['thumb_card'] );
		}

		if ( 'online' === $data['location_mode'] && $data['online_url'] ) {
			$schema['location'] = array(
				'@type' => 'VirtualLocation',
				'url'   => $data['online_url'],
			);
		} elseif ( $data['venue_name'] || $data['address'] ) {
			$place = array(
				'@type' => 'Place',
				'name'  => $data['venue_name'] ? $data['venue_name'] : $data['address'],
			);
			if ( $data['address'] ) {
				$place['address'] = $data['address'];
			}
			$schema['location'] = $place;
			if ( 'hybrid' === $data['location_mode'] && $data['online_url'] ) {
				$schema['location'] = array( $place, array( '@type' => 'VirtualLocation', 'url' => $data['online_url'] ) );
			}
		} else {
			// Google's guidelines expect a location on an offline event; fall back
			// to the site itself rather than omit it when no venue/address is set.
			$schema['location'] = array(
				'@type' => 'Place',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			);
		}

		$organizer_name = $data['host_org_name'] ? $data['host_org_name'] : get_bloginfo( 'name' );
		$schema['organizer'] = array(
			'@type' => 'Organization',
			'name'  => $organizer_name,
		);
		if ( $data['host_org_url'] ) {
			$schema['organizer']['url'] = $data['host_org_url'];
		}

		$sold_out = $data['rsvp_capacity'] > 0 && $data['rsvp_taken'] >= $data['rsvp_capacity'];
		if ( 'free_confirmed' === $data['admission_status'] ) {
			$schema['offers'] = array(
				'@type'         => 'Offer',
				'price'         => '0',
				'priceCurrency' => 'USD',
				'availability'  => $sold_out ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
				'url'           => $data['permalink'],
			);
		} elseif ( 'paid' === $data['admission_status'] && $data['price_amount'] ) {
			$schema['offers'] = array(
				'@type'         => 'Offer',
				'price'         => $data['price_amount'],
				'priceCurrency' => $data['price_currency'],
				'availability'  => $sold_out ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
				'url'           => $data['permalink'],
			);
		}

		return $schema;
	}

	private static function schema_attendance_mode( $location_mode ) {
		switch ( $location_mode ) {
			case 'online':
				return 'https://schema.org/OnlineEventAttendanceMode';
			case 'hybrid':
				return 'https://schema.org/MixedEventAttendanceMode';
			default:
				return 'https://schema.org/OfflineEventAttendanceMode';
		}
	}

	public static function type_badge_class( $type_name ) {
		return 'cec-badge-' . sanitize_title( $type_name );
	}

	/**
	 * The Upcoming list's simplified date-range filter is a small set of
	 * presets (not an open date picker) — this maps each one to the end of
	 * its window as a timestamp, or null for "All Upcoming" (no upper
	 * bound at all, today's existing behavior).
	 */
	public static function date_range_preset_end( $preset ) {
		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		switch ( $preset ) {
			case 'this_week':
				// PHP's relative "next Saturday" jumps a full 7 days when
				// today already IS Saturday — computing the day offset
				// directly instead keeps "This Week" meaning "through the
				// end of this week" even when run on a Saturday.
				$days_until_saturday = 6 - (int) date( 'w', $now );
				return strtotime( "+{$days_until_saturday} days 23:59:59", $now );
			case 'this_month':
				return strtotime( 'last day of this month 23:59:59', $now );
			case 'next_3_months':
				return strtotime( '+3 months', $now );
			case 'all_upcoming':
			default:
				return null;
		}
	}

	/**
	 * Phase 1d: "Give editors duplicate detection." A conservative,
	 * title-similarity + date-proximity heuristic — not a hard block, just
	 * a flag for a human to judge, consistent with this project's
	 * safe-by-default-automation standard. Deliberately excludes anything
	 * in the same recurring series as this event (same parent, or this
	 * event's own parent): a weekly series' own occurrences legitimately
	 * share the same title and land close together in time, and are
	 * already an intentional repeat, not an accidental duplicate.
	 *
	 * @return array[] Each: id, title, start_display, edit_link, percent.
	 */
	public static function find_possible_duplicates( $post_id ) {
		$data = self::data( $post_id );
		if ( ! $data['start_ts'] ) {
			return array();
		}

		$own_parent_id = CEC_Recurrence::is_occurrence( $post_id ) ? CEC_Recurrence::get_parent_id( $post_id ) : $post_id;
		$own_title     = self::normalize_title_for_matching( $data['title'] );
		if ( '' === $own_title ) {
			return array();
		}

		$window_start = date( 'Y-m-d\TH:i', $data['start_ts'] - DAY_IN_SECONDS );
		$window_end   = date( 'Y-m-d\TH:i', $data['start_ts'] + DAY_IN_SECONDS );

		$candidates = get_posts(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => array( 'publish', 'pending', 'draft', 'cec_in_review', 'future', 'private' ),
				'posts_per_page' => 50,
				'post__not_in'   => array( $post_id ),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_cec_start',
						'value'   => array( $window_start, $window_end ),
						'compare' => 'BETWEEN',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		$matches = array();
		foreach ( $candidates as $candidate ) {
			$candidate_parent_id = CEC_Recurrence::is_occurrence( $candidate->ID ) ? CEC_Recurrence::get_parent_id( $candidate->ID ) : $candidate->ID;
			if ( $candidate_parent_id === $own_parent_id ) {
				continue;
			}

			$candidate_title = self::normalize_title_for_matching( $candidate->post_title );
			if ( '' === $candidate_title ) {
				continue;
			}

			similar_text( $own_title, $candidate_title, $percent );
			if ( $percent < 70 ) {
				continue;
			}

			$candidate_data = self::data( $candidate->ID );
			// No edit_link here on purpose — a wp-admin link is useless to
			// a front-end-only Calendar Manager, and the dashboard's own
			// edit link needs that page's own permalink to build. Each
			// caller (admin meta box vs. front-end dashboard) builds
			// whichever link makes sense for where it's rendering.
			$matches[] = array(
				'id'            => $candidate->ID,
				'title'         => $candidate->post_title,
				'start_display' => $candidate_data['start_display'],
				'status'        => $candidate->post_status,
				'percent'       => (int) round( $percent ),
			);
		}

		return $matches;
	}

	private static function normalize_title_for_matching( $title ) {
		$title = strtolower( $title );
		$title = preg_replace( '/[^a-z0-9]+/', ' ', $title );
		return trim( $title );
	}

	/**
	 * Phase 1d: "a preview of the list card, detail page, and month view"
	 * before publishing — one shared renderer (not duplicated across the
	 * wp-admin meta box and the front-end dashboard edit form) for the
	 * possible-duplicates warning plus all three previews. $context picks
	 * the right kind of "review this" link for a flagged duplicate, since
	 * a front-end-only Calendar Manager can't use a wp-admin URL and
	 * vice-versa isn't meaningful either; $dashboard_url is only needed
	 * when $context is 'dashboard'.
	 */
	public static function render_editor_preview( $post_id, $context = 'admin', $dashboard_url = '' ) {
		if ( 'cec_event' !== get_post_type( $post_id ) ) {
			return '';
		}
		$data       = self::data( $post_id );
		$duplicates = self::find_possible_duplicates( $post_id );
		$view       = 'grid'; // in scope for templates/parts/event-card.php below.

		ob_start();
		?>
		<div class="cec-editor-preview">
			<?php if ( ! empty( $duplicates ) ) : ?>
				<div class="cec-notice cec-notice-error">
					<p><strong><?php esc_html_e( 'Possible duplicate events found nearby in time:', 'cec' ); ?></strong></p>
					<ul style="margin:0;">
						<?php foreach ( $duplicates as $dup ) : ?>
							<?php
							$review_url = 'admin' === $context
								? get_edit_post_link( $dup['id'] )
								: add_query_arg( array( 'cec_view' => 'edit', 'cec_edit' => $dup['id'] ), $dashboard_url );
							?>
							<li>
								<?php
								printf(
									/* translators: 1: candidate event title, 2: its start date, 3: its status, 4: title-similarity percent */
									esc_html__( '%1$s — %2$s (%3$s, %4$d%% title match)', 'cec' ),
									esc_html( $dup['title'] ),
									esc_html( $dup['start_display'] ),
									esc_html( ucfirst( $dup['status'] ) ),
									(int) $dup['percent']
								);
								?>
								— <a href="<?php echo esc_url( $review_url ); ?>"><?php esc_html_e( 'Review', 'cec' ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<h4><?php esc_html_e( 'List Card Preview', 'cec' ); ?></h4>
			<div class="cec-editor-preview-card">
				<?php include CEC_DIR . 'templates/parts/event-card.php'; ?>
			</div>

			<p>
				<a class="cec-btn cec-btn-small cec-btn-outline" href="<?php echo esc_url( get_preview_post_link( $post_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview Full Event Page', 'cec' ); ?> &rarr;</a>
			</p>

			<?php if ( $data['start_ts'] ) : ?>
				<h4><?php esc_html_e( 'Month View Preview', 'cec' ); ?></h4>
				<p class="description"><?php esc_html_e( 'Dashed outline marks this not-yet-published event.', 'cec' ); ?></p>
				<div class="cec-calendar-wrap cec-editor-preview-calendar" data-month="<?php echo esc_attr( date_i18n( 'n', $data['start_ts'] ) ); ?>" data-year="<?php echo esc_attr( date_i18n( 'Y', $data['start_ts'] ) ); ?>">
					<?php echo CEC_Shortcodes::render_month_html( (int) date_i18n( 'n', $data['start_ts'] ), (int) date_i18n( 'Y', $data['start_ts'] ), $post_id ); // phpcs:ignore ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function is_valid_timezone( $timezone ) {
		return $timezone && in_array( $timezone, timezone_identifiers_list(), true );
	}

	/**
	 * The Phase 1a date/time, admission, and location fields are identical
	 * across all five places an event can be edited (wp-admin meta box,
	 * public submission form, Calendar Manager dashboard, My Events, guest
	 * email-link edit) — centralized here so that logic exists exactly
	 * once instead of five times. Each caller still owns its own simpler
	 * legacy fields (title/excerpt/venue/etc.) exactly as before; this only
	 * covers the new Phase 1a meta.
	 */
	public static function save_phase1a_fields( $post_id ) {
		$time_mode = isset( $_POST['cec_time_mode'] ) ? sanitize_key( wp_unslash( $_POST['cec_time_mode'] ) ) : 'exact';
		update_post_meta( $post_id, '_cec_time_mode', in_array( $time_mode, self::TIME_MODES, true ) ? $time_mode : 'exact' );

		if ( isset( $_POST['cec_timezone'] ) ) {
			$tz = sanitize_text_field( wp_unslash( $_POST['cec_timezone'] ) );
			update_post_meta( $post_id, '_cec_timezone', self::is_valid_timezone( $tz ) ? $tz : '' );
		}

		$admission     = isset( $_POST['cec_admission_status'] ) ? sanitize_key( wp_unslash( $_POST['cec_admission_status'] ) ) : 'not_posted';
		$admission     = in_array( $admission, self::ADMISSION_STATES, true ) ? $admission : 'not_posted';
		$old_admission = self::admission_status( $post_id );
		update_post_meta( $post_id, '_cec_admission_status', $admission );
		update_post_meta( $post_id, '_cec_is_free', 'free_confirmed' === $admission ? '1' : '0' );
		update_post_meta( $post_id, '_cec_price_amount', isset( $_POST['cec_price_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_price_amount'] ) ) : '' );
		update_post_meta( $post_id, '_cec_price_min', isset( $_POST['cec_price_min'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_price_min'] ) ) : '' );
		update_post_meta( $post_id, '_cec_price_max', isset( $_POST['cec_price_max'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_price_max'] ) ) : '' );
		$currency = ! empty( $_POST['cec_price_currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['cec_price_currency'] ) ) ) : 'USD';
		update_post_meta( $post_id, '_cec_price_currency', $currency );
		if ( isset( $_POST['cec_price_source_url'] ) ) {
			update_post_meta( $post_id, '_cec_price_source_url', esc_url_raw( wp_unslash( $_POST['cec_price_source_url'] ) ) );
		}
		delete_post_meta( $post_id, '_cec_price_needs_review' );
		if ( $old_admission !== $admission ) {
			CEC_Audit_Log::log( $post_id, 'admission_changed', $old_admission . ' → ' . $admission );
		}

		$location_mode = isset( $_POST['cec_location_mode'] ) ? sanitize_key( wp_unslash( $_POST['cec_location_mode'] ) ) : 'in_person';
		update_post_meta( $post_id, '_cec_location_mode', in_array( $location_mode, self::LOCATION_MODES, true ) ? $location_mode : 'in_person' );
		if ( isset( $_POST['cec_online_url'] ) ) {
			update_post_meta( $post_id, '_cec_online_url', esc_url_raw( wp_unslash( $_POST['cec_online_url'] ) ) );
		}
		foreach ( array( 'cec_venue_custom_city' => '_cec_venue_custom_city', 'cec_venue_custom_region' => '_cec_venue_custom_region', 'cec_venue_custom_country' => '_cec_venue_custom_country' ) as $post_key => $meta_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
			}
		}

		if ( isset( $_POST['cec_official_website_url'] ) ) {
			update_post_meta( $post_id, '_cec_official_website_url', esc_url_raw( wp_unslash( $_POST['cec_official_website_url'] ) ) );
		}
		if ( isset( $_POST['cec_source_url'] ) ) {
			update_post_meta( $post_id, '_cec_source_url', esc_url_raw( wp_unslash( $_POST['cec_source_url'] ) ) );
		}
		if ( isset( $_POST['cec_photo_alt'] ) ) {
			$alt = sanitize_text_field( wp_unslash( $_POST['cec_photo_alt'] ) );
			update_post_meta( $post_id, '_cec_photo_alt', $alt );
			$thumb_id = get_post_thumbnail_id( $post_id );
			if ( $thumb_id ) {
				update_post_meta( $thumb_id, '_wp_attachment_image_alt', $alt );
			}
		}
	}

	/**
	 * Validates and handles an uploaded event photo from any of the
	 * front-end forms — MIME type and size are checked explicitly (rather
	 * than left entirely to media_handle_upload(), which accepts anything
	 * WordPress itself allows, a broader set than a flyer/photo upload
	 * should) before the attachment is created, so a bad file never
	 * silently becomes the featured image with no feedback to the
	 * submitter. Returns the new attachment ID, null if nothing was
	 * uploaded, or a WP_Error whose code matches photo_error_message()
	 * below.
	 */
	public static function handle_photo_upload( $post_id, $file_key = 'cec_photo' ) {
		if ( empty( $_FILES[ $file_key ]['name'] ) ) {
			return null;
		}
		if ( UPLOAD_ERR_OK !== $_FILES[ $file_key ]['error'] ) {
			return new WP_Error( 'upload_failed', self::photo_error_message( 'upload_failed' ) );
		}
		if ( $_FILES[ $file_key ]['size'] > 5 * MB_IN_BYTES ) {
			return new WP_Error( 'too_large', self::photo_error_message( 'too_large' ) );
		}

		$allowed  = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
		$filetype = wp_check_filetype_and_ext( $_FILES[ $file_key ]['tmp_name'], $_FILES[ $file_key ]['name'] );
		if ( empty( $filetype['type'] ) || ! in_array( $filetype['type'], $allowed, true ) ) {
			return new WP_Error( 'bad_type', self::photo_error_message( 'bad_type' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$attachment_id = media_handle_upload( $file_key, $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error( 'upload_failed', self::photo_error_message( 'upload_failed' ) );
		}
		set_post_thumbnail( $post_id, $attachment_id );

		$alt = isset( $_POST['cec_photo_alt'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_photo_alt'] ) ) : '';
		if ( $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}
		return $attachment_id;
	}

	public static function photo_error_message( $error_code ) {
		$map = array(
			'too_large'     => __( 'That photo is too large — please use a file under 5MB.', 'cec' ),
			'bad_type'      => __( 'Please upload a JPG, PNG, GIF, or WEBP image.', 'cec' ),
			'upload_failed' => __( 'That photo could not be uploaded. Please try again.', 'cec' ),
		);
		return isset( $map[ $error_code ] ) ? $map[ $error_code ] : __( 'That photo could not be uploaded. Please try again.', 'cec' );
	}

	/**
	 * A <select> of every IANA timezone, grouped by continent the way PHP's
	 * own DateTimeZone::listIdentifiers() returns them — used everywhere an
	 * event or venue picks an explicit timezone. "Site Default" (empty
	 * value) is always the first option and always what's selected when no
	 * explicit value has been chosen, so leaving this alone never changes
	 * an event's current (already-correct) behavior.
	 */
	public static function timezone_select_html( $name, $selected ) {
		$groups = array();
		foreach ( DateTimeZone::listIdentifiers() as $tz ) {
			$parts  = explode( '/', $tz, 2 );
			$region = count( $parts ) > 1 ? $parts[0] : __( 'Other', 'cec' );
			$groups[ $region ][] = $tz;
		}

		ob_start();
		?>
		<select name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>">
			<option value="" <?php selected( '', $selected ); ?>>
				<?php
				/* translators: %s: the site's own configured timezone, e.g. "America/Chicago" */
				printf( esc_html__( 'Site Default (%s)', 'cec' ), esc_html( wp_timezone_string() ) );
				?>
			</option>
			<?php foreach ( $groups as $region => $zones ) : ?>
				<optgroup label="<?php echo esc_attr( $region ); ?>">
					<?php foreach ( $zones as $tz ) : ?>
						<option value="<?php echo esc_attr( $tz ); ?>" <?php selected( $tz, $selected ); ?>><?php echo esc_html( $tz ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			<?php endforeach; ?>
		</select>
		<?php
		return ob_get_clean();
	}

	/**
	 * Static fallback share links for platforms with a reliable, universal
	 * web share-intent URL — these work with plain <a> tags, no JS required.
	 * Messenger/Snapchat/SMS deliberately excluded: none has a share URL
	 * that works reliably across both desktop and mobile without an
	 * installed app or a developer account, so those are only offered via
	 * the native OS share sheet (see the .cec-share-btn JS), which lists
	 * whatever's actually installed on the visitor's own device.
	 */
	public static function share_links( $data ) {
		$url   = $data['permalink'];
		$title = $data['title'];
		$text  = $title . ( $data['start_display'] ? ' — ' . $data['start_display'] : '' );

		// iOS and Android expect a different separator before the sms: body
		// param (& vs ?) — decided server-side from the request's own User
		// Agent so the link works with no JS, same as the others here.
		$is_ios  = isset( $_SERVER['HTTP_USER_AGENT'] ) && preg_match( '/iPad|iPhone|iPod/', $_SERVER['HTTP_USER_AGENT'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$sms_sep = $is_ios ? '&' : '?';

		return array(
			'whatsapp' => 'https://wa.me/?text=' . rawurlencode( $text . ' ' . $url ),
			'telegram' => 'https://t.me/share/url?url=' . rawurlencode( $url ) . '&text=' . rawurlencode( $text ),
			'sms'      => 'sms:' . $sms_sep . 'body=' . rawurlencode( $text . ' ' . $url ),
			'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url ),
			'twitter'  => 'https://twitter.com/intent/tweet?text=' . rawurlencode( $text ) . '&url=' . rawurlencode( $url ),
			'email'    => 'mailto:?subject=' . rawurlencode( $title ) . '&body=' . rawurlencode( $text . "\n\n" . $url ),
		);
	}
}
