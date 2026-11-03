<?php
/**
 * Turns extractor output into Community Events Calendar fields, without
 * guessing:
 *
 * - Only the calendar's own values: admission free_confirmed / paid /
 *   price_varies / not_posted; location in_person / online / hybrid /
 *   not_posted; existing Event Type terms only; IANA timezones only.
 * - "Free" needs words like free / no cover in the evidence. A missing or
 *   zero price is "Price not posted", never free.
 * - A link must appear in the post's text or links (or be read from the
 *   flyer, which is flagged), so a made-up link can't slip through.
 * - No time → the time is marked "varies" and flagged; no timezone → blank
 *   (the calendar then uses the venue's or the site's timezone).
 * - Multi-day events stay one event with inclusive start and end dates.
 *   The calendar has no session records, so session details become a note.
 * - Anything below the confidence threshold is listed for the editor;
 *   a missing or uncertain title or start date keeps the entry out of the
 *   calendar (intake queue, "needs info").
 */
defined( 'ABSPATH' ) || exit;

final class CTI_Normalizer {

	const LABELS = array(
		'title'             => 'Title',
		'short_description' => 'Description',
		'event_type'        => 'Event type',
		'start_date'        => 'Start date',
		'end_date'          => 'End date',
		'start_time'        => 'Start time',
		'end_time'          => 'End time',
		'timezone'          => 'Timezone',
		'location_mode'     => 'Location type',
		'venue_name'        => 'Venue',
		'address'           => 'Address',
		'city'              => 'City',
		'region'            => 'State/region',
		'country'           => 'Country',
		'online_url'        => 'Online link',
		'organizer'         => 'Organizer',
		'official_url'      => 'Event website',
		'ticket_url'        => 'Tickets/registration',
		'admission'         => 'Admission',
		'price_amount'      => 'Price',
		'price_min'         => 'Lowest price',
		'price_max'         => 'Highest price',
		'currency'          => 'Currency',
		'price_note'        => 'Price note',
		'year_stated'       => 'Year written in post',
		'schedule_notes'    => 'Sessions',
	);

	/**
	 * @param array $ev   One event from CTI_Extractors::clean().
	 * @param array $post The cti_posts row (post_url, channel, body, links, post_date).
	 */
	public static function entry( $ev, $post ) {
		$min     = (float) CTI_Config::get( 'min_confidence' );
		$notes   = array();
		$missing = array();
		$display = array();
		$v       = static function ( $key ) use ( $ev ) {
			return isset( $ev[ $key ]['value'] ) ? trim( (string) $ev[ $key ]['value'] ) : '';
		};
		$c       = static function ( $key ) use ( $ev ) {
			return isset( $ev[ $key ]['confidence'] ) ? (float) $ev[ $key ]['confidence'] : 0.0;
		};
		$haystack = self::haystack( $post );

		foreach ( self::LABELS as $key => $label ) {
			$f = isset( $ev[ $key ] ) ? $ev[ $key ] : array( 'value' => '', 'confidence' => 0, 'evidence' => '', 'source' => 'none' );
			$display[ $key ] = array(
				'label'      => $label,
				'value'      => (string) $f['value'],
				'confidence' => (float) $f['confidence'],
				'evidence'   => (string) $f['evidence'],
				'source'     => (string) $f['source'],
				'flag'       => '' !== (string) $f['value'] && (float) $f['confidence'] < $min ? 'low' : '',
			);
		}
		$flag = static function ( $key, $why ) use ( &$display, &$notes ) {
			$display[ $key ]['flag'] = 'check';
			$notes[]                 = $why;
		};

		$fields = array();

		// Title.
		$title = mb_substr( sanitize_text_field( $v( 'title' ) ), 0, 200 );
		if ( '' === $title ) {
			$missing[] = 'No event title found.';
		} elseif ( $c( 'title' ) < $min ) {
			$missing[] = 'The title is uncertain.';
		}
		$fields['cec_title'] = $title;

		// Dates and times.
		$start_date = self::date( $v( 'start_date' ) );
		$end_date   = self::date( $v( 'end_date' ) );
		$start_time = self::time( $v( 'start_time' ) );
		$end_time   = self::time( $v( 'end_time' ) );
		if ( '' === $start_date ) {
			$missing[] = '' === $v( 'start_date' ) ? 'No event date found.' : 'The date could not be read as a real date.';
		} elseif ( $c( 'start_date' ) < $min ) {
			$missing[] = 'The date is uncertain.';
		}
		if ( $end_date && $start_date && $end_date < $start_date ) {
			$flag( 'end_date', 'The end date was before the start date and was left out.' );
			$end_date = '';
		}
		if ( $start_date && 'no' === strtolower( $v( 'year_stated' ) ) ) {
			$flag( 'start_date', 'The post does not give the year; ' . substr( $start_date, 0, 4 ) . ' was assumed from the post date.' );
		}
		if ( $start_date && $start_date < gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) && ( ! $end_date || $end_date < gmdate( 'Y-m-d' ) ) ) {
			$missing[] = 'The event date has already passed.';
		}
		if ( $start_date ) {
			$multi = $end_date && $end_date !== $start_date;
			if ( $start_time && $end_time ) {
				$fields['cec_time_mode'] = 'exact';
			} elseif ( $start_time ) {
				$fields['cec_time_mode'] = 'start_only';
			} else {
				$fields['cec_time_mode'] = 'varies';
				$flag( 'start_time', 'No start time is stated; the time shows as "varies" until an editor sets it.' );
			}
			$fields['cec_start'] = $start_date . 'T' . ( $start_time ? $start_time : '00:00' );
			if ( $end_time ) {
				$fields['cec_end'] = ( $end_date ? $end_date : $start_date ) . 'T' . $end_time;
			} elseif ( $multi ) {
				$fields['cec_end'] = $end_date . 'T23:59';
			} else {
				$fields['cec_end'] = '';
			}
		}

		// Timezone: only a stated one.
		$tz = $v( 'timezone' );
		if ( '' !== $tz ) {
			if ( in_array( $tz, timezone_identifiers_list(), true ) && $c( 'timezone' ) >= $min ) {
				$fields['cec_timezone'] = $tz;
			} else {
				$flag( 'timezone', 'Timezone "' . $tz . '" was not used (not a recognised IANA name, or uncertain).' );
			}
		}

		// Location.
		$modes = array( 'in_person' => 'in_person', 'online' => 'online', 'hybrid' => 'hybrid' );
		$mode  = strtolower( str_replace( array( '-', ' ' ), '_', $v( 'location_mode' ) ) );
		$fields['cec_location_mode'] = isset( $modes[ $mode ] ) && $c( 'location_mode' ) >= $min ? $modes[ $mode ] : 'not_posted';
		$venue   = sanitize_text_field( $v( 'venue_name' ) );
		$address = sanitize_text_field( $v( 'address' ) );
		$fields['cec_venue_custom_address'] = trim( $venue . ( $venue && $address ? ', ' : '' ) . $address );
		$fields['cec_venue_custom_city']    = sanitize_text_field( $v( 'city' ) );
		$fields['cec_venue_custom_region']  = sanitize_text_field( $v( 'region' ) );
		$fields['cec_venue_custom_country'] = sanitize_text_field( $v( 'country' ) );
		if ( 'in_person' === $fields['cec_location_mode'] && '' === $fields['cec_venue_custom_address'] && '' === $fields['cec_venue_custom_city'] ) {
			$flag( 'venue_name', 'In person, but no venue or city is stated.' );
		}
		if ( 'not_posted' === $fields['cec_location_mode'] ) {
			$notes[] = 'Location type is not stated.';
		}

		// Links.
		$urls = array();
		foreach ( array( 'online_url' => 'cec_online_url', 'official_url' => 'cec_official_website_url', 'ticket_url' => 'cec_rsvp_url' ) as $key => $field ) {
			$url = self::url( $v( $key ) );
			if ( '' === $v( $key ) ) {
				continue;
			}
			if ( '' === $url ) {
				$flag( $key, self::LABELS[ $key ] . ' "' . $v( $key ) . '" is not a valid web address and was left out.' );
				continue;
			}
			if ( ! self::url_in( $url, $haystack ) ) {
				if ( in_array( $ev[ $key ]['source'], array( 'image', 'both' ), true ) ) {
					$flag( $key, self::LABELS[ $key ] . ' was read from the flyer image; check it is typed correctly.' );
				} else {
					$flag( $key, self::LABELS[ $key ] . ' "' . $url . '" does not appear in the post and was left out.' );
					continue;
				}
			}
			if ( 'cec_online_url' === $field && ! in_array( $fields['cec_location_mode'], array( 'online', 'hybrid' ), true ) ) {
				$flag( $key, 'An online link was found but the event is not marked online or hybrid.' );
			}
			$fields[ $field ] = $url;
			$urls[]           = $url;
		}

		// Organizer: text only. Linking to a Partner Organization is an editor's call.
		$org = sanitize_text_field( $v( 'organizer' ) );
		if ( '' !== $org ) {
			$fields['cec_organizers']    = $org;
			$fields['cec_host_org_name'] = $org;
		}

		// Admission.
		$adm     = strtolower( $v( 'admission' ) );
		$amount  = self::money( $v( 'price_amount' ) );
		$pmin    = self::money( $v( 'price_min' ) );
		$pmax    = self::money( $v( 'price_max' ) );
		$fields['cec_admission_status'] = 'not_posted';
		if ( 'free' === $adm ) {
			if ( preg_match( '/\b(free|no cover|no charge|complimentary|no cost|gratis)\b/i', $ev['admission']['evidence'] ) && $c( 'admission' ) >= $min ) {
				$fields['cec_admission_status'] = 'free_confirmed';
			} else {
				$flag( 'admission', 'Marked free without clear wording such as "free" or "no cover"; left as "Price not posted".' );
			}
		} elseif ( 'paid' === $adm ) {
			if ( '' !== $amount && (float) $amount > 0 && $c( 'price_amount' ) >= $min ) {
				$fields['cec_admission_status'] = 'paid';
				$fields['cec_price_amount']     = $amount;
			} else {
				$flag( 'price_amount', 'Paid, but no clear price amount; left as "Price not posted".' );
			}
		} elseif ( 'price_varies' === $adm ) {
			if ( ( '' !== $pmin || '' !== $pmax ) && $c( 'admission' ) >= $min ) {
				$fields['cec_admission_status'] = 'price_varies';
				$fields['cec_price_min']        = $pmin;
				$fields['cec_price_max']        = $pmax;
			} else {
				$flag( 'admission', 'Prices vary, but no clear range; left as "Price not posted".' );
			}
		}
		$currency = strtoupper( $v( 'currency' ) );
		if ( preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$fields['cec_price_currency'] = $currency;
		}
		if ( '' !== $v( 'price_note' ) ) {
			$fields['cec_price_note'] = mb_substr( sanitize_text_field( $v( 'price_note' ) ), 0, 200 );
		}

		// Event type: an existing term or nothing.
		$type = $v( 'event_type' );
		if ( '' !== $type ) {
			$term = get_term_by( 'name', $type, 'cec_event_type' );
			$term = $term ? $term : get_term_by( 'slug', sanitize_title( $type ), 'cec_event_type' );
			if ( $term && $c( 'event_type' ) >= $min ) {
				$fields['event_type_ids'] = array( (int) $term->term_id );
			} else {
				$flag( 'event_type', 'Event type "' . $type . '" was not set (not one of the site\'s types, or uncertain).' );
			}
		} else {
			$notes[] = 'No event type chosen.';
		}

		if ( '' !== $v( 'schedule_notes' ) ) {
			$notes[] = 'Sessions mentioned (the calendar keeps one event; add details by hand if needed): ' . mb_substr( $v( 'schedule_notes' ), 0, 300 );
		}

		// Description and attribution.
		$desc                  = mb_substr( sanitize_textarea_field( $v( 'short_description' ) ), 0, 1000 );
		$fields['cec_excerpt'] = $desc;
		$source_label          = $post['channel'] ? 'Telegram: ' . $post['channel'] : 'Telegram';
		$fields['cec_description'] = ( $desc ? '<p>' . esc_html( $desc ) . '</p>' : '' )
			. ( $post['post_url'] ? '<p>Source: <a href="' . esc_url( $post['post_url'] ) . '">' . esc_html( $source_label ) . '</a></p>' : '' );
		$fields['source_url'] = $post['post_url'];

		foreach ( $display as $key => $d ) {
			if ( 'low' === $d['flag'] && ! in_array( $key, array( 'title', 'start_date', 'year_stated' ), true ) ) {
				$notes[] = 'Low confidence: ' . $d['label'] . ' "' . mb_substr( $d['value'], 0, 80 ) . '"' . ( $d['evidence'] ? ' (from: "' . mb_substr( $d['evidence'], 0, 80 ) . '")' : '' ) . '.';
			}
		}
		$notes[] = 'Imported from ' . ( $post['post_url'] ? $post['post_url'] : $source_label ) . '.';

		// 0.4.0: the venue's parts, for linking to (or creating) a saved venue.
		$venue_parts = array(
			'name'      => $venue,
			'address'   => $address,
			'city'      => $fields['cec_venue_custom_city'],
			'region'    => $fields['cec_venue_custom_region'],
			'country'   => $fields['cec_venue_custom_country'],
			'confident' => '' !== $venue && $c( 'venue_name' ) >= $min,
		);

		return array(
			'ok'      => ! $missing,
			'venue'   => $venue_parts,
			'missing' => $missing,
			'fields'  => $fields,
			'notes'   => array_values( array_unique( $notes ) ),
			'display' => $display,
			'title'   => $title,
			'start_date' => $start_date,
			'match'   => array(
				'title'      => $title,
				'start_date' => $start_date,
				'end_date'   => $end_date ? $end_date : $start_date,
				'venue'      => $fields['cec_venue_custom_address'] . ' ' . $fields['cec_venue_custom_city'],
				'organizer'  => $org,
				'urls'       => $urls,
				'text'       => (string) $post['body'],
				'image_hash' => isset( $post['image_hash'] ) ? (string) $post['image_hash'] : '',
				'post_url'   => (string) $post['post_url'],
			),
		);
	}

	/** Fields that an edit compares (what an editor would see change). */
	public static function compared_keys() {
		return array( 'cec_title', 'cec_excerpt', 'cec_start', 'cec_end', 'cec_time_mode', 'cec_timezone', 'cec_location_mode', 'cec_venue_custom_address', 'cec_venue_custom_city', 'cec_venue_custom_region', 'cec_venue_custom_country', 'cec_online_url', 'cec_official_website_url', 'cec_rsvp_url', 'cec_organizers', 'cec_host_org_name', 'cec_admission_status', 'cec_price_amount', 'cec_price_min', 'cec_price_max', 'cec_price_currency', 'cec_price_note', 'event_type_ids' );
	}

	public static function field_label( $key ) {
		$map = array(
			'cec_title' => 'title', 'cec_excerpt' => 'description', 'cec_start' => 'start', 'cec_end' => 'end', 'cec_time_mode' => 'time display',
			'cec_timezone' => 'timezone', 'cec_location_mode' => 'location type', 'cec_venue_custom_address' => 'venue/address', 'cec_venue_custom_city' => 'city',
			'cec_venue_custom_region' => 'state/region', 'cec_venue_custom_country' => 'country', 'cec_online_url' => 'online link', 'cec_official_website_url' => 'event website',
			'cec_rsvp_url' => 'tickets link', 'cec_organizers' => 'organizer', 'cec_host_org_name' => 'host', 'cec_admission_status' => 'admission',
			'cec_price_amount' => 'price', 'cec_price_min' => 'lowest price', 'cec_price_max' => 'highest price', 'cec_price_currency' => 'currency',
			'cec_price_note' => 'price note', 'event_type_ids' => 'event type',
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : $key;
	}

	public static function value_text( $key, $value ) {
		if ( 'event_type_ids' === $key ) {
			$names = array();
			foreach ( (array) $value as $id ) {
				$t = get_term( (int) $id, 'cec_event_type' );
				if ( $t && ! is_wp_error( $t ) ) {
					$names[] = $t->name;
				}
			}
			return implode( ', ', $names );
		}
		return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}

	private static function date( $value ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $value;
	}

	private static function time( $value ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $value, $m ) || (int) $m[1] > 23 || (int) $m[2] > 59 ) {
			return '';
		}
		return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
	}

	private static function money( $value ) {
		$value = str_replace( array( '$', ',', ' ' ), '', (string) $value );
		if ( ! preg_match( '/^\d+(\.\d{1,2})?$/', $value ) ) {
			return '';
		}
		return $value;
	}

	private static function url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $value ) ) {
			$value = 'https://' . $value;
		}
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		return $url && ( class_exists( 'CEC_Intake_API' ) ? CEC_Intake_API::valid_url( $url ) : (bool) filter_var( $url, FILTER_VALIDATE_URL ) ) ? $url : '';
	}

	private static function haystack( $post ) {
		$links = is_array( $post['links'] ) ? $post['links'] : (array) json_decode( (string) $post['links'], true );
		return strtolower( (string) $post['body'] . ' ' . implode( ' ', $links ) );
	}

	/** A link counts as "in the post" when its host and path appear there. */
	private static function url_in( $url, $haystack ) {
		$p = wp_parse_url( $url );
		if ( empty( $p['host'] ) ) {
			return false;
		}
		$needle = strtolower( preg_replace( '/^www\./', '', $p['host'] ) . ( isset( $p['path'] ) ? rtrim( $p['path'], '/' ) : '' ) );
		return false !== strpos( $haystack, $needle );
	}
}
