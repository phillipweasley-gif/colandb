<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Importing events FROM an uploaded .ics file — the reverse of
 * CEC_Ical's export. Deliberately scoped to what can be mapped onto this
 * plugin's real fields without guessing:
 *
 * - LOCATION (free text) goes into the same "custom venue address" field
 *   used when someone types a venue by hand, never auto-matched against
 *   an existing Venue term (a text match could easily be a different,
 *   coincidentally-similar place).
 * - ORGANIZER's display name (when the source file actually sets one)
 *   goes into the host-org *name* text field, never auto-linked to an
 *   existing Partner Organization term, for the same reason.
 * - A VEVENT with an RRULE is imported as a single event using only its
 *   DTSTART/DTEND — this plugin's own recurrence model (ordinal/weekday
 *   child-post occurrences) doesn't cover arbitrary RRULE syntax, and
 *   guessing a mapping risks silently generating a wrong pattern. The
 *   rule itself is reported back, never silently applied.
 * - Every imported event is a completely normal cec_event post afterward
 *   — fully editable via the same dashboard edit form as any other event.
 */
class CEC_Ics_Import {

	/**
	 * @param string $raw             Raw .ics file contents.
	 * @param int    $author_id       Post author for newly-created events.
	 * @param bool   $update_existing When true, a VEVENT whose UID matches a
	 *                                previously-imported event (by its stored
	 *                                _cec_ics_import_uid) updates that event's
	 *                                source-provided fields in place instead of
	 *                                creating a duplicate — for re-uploading a
	 *                                subscribed calendar that's changed since the
	 *                                last import. A VEVENT with no UID, or one
	 *                                that doesn't match anything, is still
	 *                                created fresh either way.
	 * @return array{created:int,updated:int,recurrence_skipped:int,errors:string[]}
	 */
	public static function import( $raw, $author_id, $update_existing = false ) {
		$result = array(
			'created'            => 0,
			'updated'            => 0,
			'recurrence_skipped' => 0,
			'errors'             => array(),
		);

		$lines = self::unfold( $raw );
		$events = self::split_vevents( $lines );

		if ( ! $events ) {
			$result['errors'][] = __( "No events (VEVENT blocks) were found in that file — check it's a real .ics calendar export.", 'cec' );
			return $result;
		}

		foreach ( $events as $index => $raw_lines ) {
			$props = self::parse_properties( $raw_lines );

			$title = isset( $props['SUMMARY'] ) ? self::unescape_text( $props['SUMMARY']['value'] ) : '';
			if ( '' === trim( $title ) ) {
				/* translators: %d: position of the event within the uploaded file */
				$result['errors'][] = sprintf( __( 'Event #%d in the file has no title (SUMMARY) and was skipped.', 'cec' ), $index + 1 );
				continue;
			}

			if ( empty( $props['DTSTART'] ) ) {
				/* translators: 1: event title */
				$result['errors'][] = sprintf( __( '"%s" has no start date/time and was skipped.', 'cec' ), $title );
				continue;
			}

			$start_info = self::parse_datetime( $props['DTSTART'] );
			if ( ! $start_info ) {
				/* translators: 1: event title */
				$result['errors'][] = sprintf( __( '"%s" has a start date/time in a format this importer could not read and was skipped.', 'cec' ), $title );
				continue;
			}

			$end_info = ! empty( $props['DTEND'] ) ? self::parse_datetime( $props['DTEND'] ) : null;

			if ( $start_info['all_day'] ) {
				// RFC 5545: an all-day DTEND is the day AFTER the last day
				// actually included, so a single-day all-day event has no
				// DTEND at all or DTEND == DTSTART + 1 day. Back it up one
				// day to get the real last day, then span midnight to
				// 23:59 so a multi-day all-day event (a 3-day festival,
				// for instance) still renders as a real multi-day span —
				// this plugin has no separate "all day" flag of its own.
				$start_dt = clone $start_info['datetime'];
				$start_dt->setTime( 0, 0 );

				$end_dt = ( $end_info && $end_info['all_day'] ) ? clone $end_info['datetime'] : clone $start_info['datetime'];
				$end_dt->setTime( 0, 0 );
				$end_dt->modify( '-1 day' );
				if ( $end_dt < $start_dt ) {
					$end_dt = clone $start_dt;
				}
				$end_dt->setTime( 23, 59 );
			} else {
				$start_dt = $start_info['datetime'];
				$end_dt   = ( $end_info && ! $end_info['all_day'] ) ? $end_info['datetime'] : clone $start_dt;
			}

			$description = isset( $props['DESCRIPTION'] ) ? self::unescape_text( $props['DESCRIPTION']['value'] ) : '';
			$location    = isset( $props['LOCATION'] ) ? self::unescape_text( $props['LOCATION']['value'] ) : '';
			$uid         = isset( $props['UID'] ) ? trim( $props['UID']['value'] ) : '';

			$organizer_name = '';
			if ( isset( $props['ORGANIZER']['params']['CN'] ) ) {
				$organizer_name = trim( $props['ORGANIZER']['params']['CN'], " \t\"" );
			}

			// Many real calendar exports (Google Calendar, Eventbrite,
			// etc.) set a URL property pointing at a "more info" page for
			// the event — this plugin's own .ics export already emits
			// one (pointing at the event's own permalink), so reading it
			// back on import is the natural counterpart.
			$more_info_url = isset( $props['URL']['value'] ) ? esc_url_raw( trim( $props['URL']['value'] ) ) : '';

			$existing_id = 0;
			if ( $update_existing && $uid ) {
				$matches = get_posts(
					array(
						'post_type'      => 'cec_event',
						'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private', 'cec_in_review' ),
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
							array(
								'key'   => '_cec_ics_import_uid',
								'value' => $uid,
							),
						),
					)
				);
				$existing_id = $matches ? (int) $matches[0] : 0;
			}

			if ( $existing_id ) {
				$post_id = wp_update_post(
					array(
						'ID'           => $existing_id,
						'post_title'   => sanitize_text_field( $title ),
						'post_content' => wp_kses_post( $description ),
					),
					true
				);
			} else {
				$post_id = wp_insert_post(
					array(
						'post_type'    => 'cec_event',
						// Imports are restricted to Administrators/Calendar
						// Managers (the same people who'd otherwise approve a
						// pending submission), so there's no second person
						// left to review it against — publish directly rather
						// than queuing it behind its own uploader.
						'post_status'  => 'publish',
						'post_title'   => sanitize_text_field( $title ),
						'post_content' => wp_kses_post( $description ),
						'post_author'  => $author_id,
					),
					true
				);
			}

			if ( is_wp_error( $post_id ) ) {
				/* translators: 1: event title, 2: the underlying WordPress error message */
				$result['errors'][] = sprintf( __( '"%1$s" could not be saved: %2$s', 'cec' ), $title, $post_id->get_error_message() );
				continue;
			}

			// These fields are what the source file actually provides,
			// so a re-sync (matched update) refreshes them just like a
			// fresh import would — the same way a calendar subscription
			// is expected to behave when it's re-pulled.
			update_post_meta( $post_id, '_cec_start', $start_dt->format( 'Y-m-d\TH:i' ) );
			update_post_meta( $post_id, '_cec_end', $end_dt->format( 'Y-m-d\TH:i' ) );
			update_post_meta( $post_id, '_cec_venue_custom_address', sanitize_text_field( $location ) );
			if ( $organizer_name ) {
				update_post_meta( $post_id, '_cec_host_org_name', sanitize_text_field( $organizer_name ) );
			}
			if ( $more_info_url ) {
				update_post_meta( $post_id, '_cec_more_info_url', $more_info_url );
			}

			if ( $existing_id ) {
				// Local enrichments an admin may have already added since
				// the last import — the real Cost, a linked Venue/Partner
				// Org term, a manually-set postponed/cancelled status —
				// are deliberately left untouched on a matched update.
				// None of these ever came from the source file in the
				// first place, so re-syncing the file's own fields has no
				// business overwriting them back to their import default.
				CEC_Audit_Log::log( $post_id, 'event_ics_resynced' );
				$result['updated']++;
			} else {
				// ICS has no price field at all to read from — "Price not
				// posted" (the fourth admission state, added precisely for
				// cases like this) says exactly that, honestly, instead of
				// guessing Free or inventing a "Paid" claim the source file
				// never actually made.
				update_post_meta( $post_id, '_cec_admission_status', 'not_posted' );
				update_post_meta( $post_id, '_cec_location_mode', $location ? 'in_person' : 'not_posted' );
				update_post_meta( $post_id, '_cec_time_mode', $start_info['all_day'] ? 'all_day' : 'exact' );
				if ( ! empty( $props['DTSTART']['params']['TZID'] ) && CEC_Event_Helper::is_valid_timezone( $props['DTSTART']['params']['TZID'] ) ) {
					update_post_meta( $post_id, '_cec_timezone', $props['DTSTART']['params']['TZID'] );
				}
				update_post_meta( $post_id, '_cec_event_status', 'scheduled' );
				update_post_meta( $post_id, '_cec_recurrence_rule', 'none' );
				if ( $uid ) {
					update_post_meta( $post_id, '_cec_ics_import_uid', sanitize_text_field( $uid ) );
				}
				CEC_Audit_Log::log( $post_id, 'event_imported_ics' );
				$result['created']++;
			}
			update_post_meta( $post_id, '_cec_ics_imported_at', current_time( 'mysql' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp

			if ( ! empty( $props['RRULE'] ) ) {
				$result['recurrence_skipped']++;
				/* translators: 1: event title */
				$result['errors'][] = sprintf( __( '"%s" repeats in the source file (a recurrence rule was found) — only its first date was imported. Set recurrence manually on this event if it should repeat here too.', 'cec' ), $title );
			}
		}

		if ( $result['created'] > 0 ) {
			// One summary note rather than one per event — .ics has no
			// price field at all, so this applies to every single import,
			// not a subset worth calling out individually.
			$result['errors'][] = __( "None of the imported events had pricing info in the source file, so each was marked Price Not Posted rather than guessing Free or a price — review and set the real cost on each one.", 'cec' );
		}

		return $result;
	}

	/**
	 * RFC 5545 line unfolding: a line that starts with a space or tab is
	 * a continuation of the previous line, not a new property — real
	 * calendar exports routinely wrap long DESCRIPTION/SUMMARY values
	 * this way.
	 */
	private static function unfold( $raw ) {
		$raw   = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$lines = explode( "\n", $raw );
		$out   = array();
		foreach ( $lines as $line ) {
			if ( '' !== $line && ( ' ' === $line[0] || "\t" === $line[0] ) && $out ) {
				$out[ count( $out ) - 1 ] .= substr( $line, 1 );
			} else {
				$out[] = $line;
			}
		}
		return $out;
	}

	private static function split_vevents( $lines ) {
		$events = array();
		$current = null;
		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			if ( 'BEGIN:VEVENT' === strtoupper( $trimmed ) ) {
				$current = array();
				continue;
			}
			if ( 'END:VEVENT' === strtoupper( $trimmed ) ) {
				if ( null !== $current ) {
					$events[] = $current;
				}
				$current = null;
				continue;
			}
			if ( null !== $current && '' !== $trimmed ) {
				$current[] = $line;
			}
		}
		return $events;
	}

	/**
	 * Parses one VEVENT's lines into NAME => {value, params}. Only the
	 * last occurrence of a repeated property wins, matching how most
	 * real-world parsers behave for the handful of properties this
	 * importer actually reads.
	 */
	private static function parse_properties( $lines ) {
		$props = array();
		foreach ( $lines as $line ) {
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$name_and_params = substr( $line, 0, $colon );
			$value            = substr( $line, $colon + 1 );
			$parts            = explode( ';', $name_and_params );
			$name             = strtoupper( array_shift( $parts ) );
			$params           = array();
			foreach ( $parts as $param ) {
				$eq = strpos( $param, '=' );
				if ( false !== $eq ) {
					$params[ strtoupper( substr( $param, 0, $eq ) ) ] = substr( $param, $eq + 1 );
				}
			}
			$props[ $name ] = array(
				'value'  => $value,
				'params' => $params,
			);
		}
		return $props;
	}

	/**
	 * Returns a DateTime already converted to the site's own configured
	 * timezone (wp_timezone()) — deliberately never a raw Unix timestamp.
	 * Formatting a timestamp back to a string later would go through
	 * PHP's global date()/strtotime(), which use the *server's* default
	 * timezone, not WordPress's site setting; those two commonly differ
	 * (many hosts run PHP itself in UTC regardless of the site's real
	 * timezone), which would silently shift every imported time. Keeping
	 * everything as a DateTime object tied to wp_timezone() and only ever
	 * calling ->format() on it avoids that trap entirely.
	 *
	 * @param array{value:string,params:array} $prop DTSTART or DTEND property.
	 * @return array{datetime:DateTime,all_day:bool}|null
	 */
	private static function parse_datetime( $prop ) {
		$value   = trim( $prop['value'] );
		$site_tz = wp_timezone();

		// VALUE=DATE (or an 8-digit value with no time part): an all-day
		// marker, not a specific moment.
		if ( ( isset( $prop['params']['VALUE'] ) && 'DATE' === strtoupper( $prop['params']['VALUE'] ) ) || preg_match( '/^\d{8}$/', $value ) ) {
			if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
				return null;
			}
			$date = $m[1] . '-' . $m[2] . '-' . $m[3];
			try {
				$dt = new DateTime( $date . ' 00:00:00', $site_tz );
			} catch ( Exception $e ) {
				return null;
			}
			return array(
				'datetime' => $dt,
				'all_day'  => true,
			);
		}

		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z)?$/', $value, $m ) ) {
			return null;
		}
		list( , $y, $mo, $d, $h, $mi, $s, $is_utc ) = $m;
		$date_str = "$y-$mo-$d $h:$mi:$s";

		try {
			if ( $is_utc ) {
				$dt = new DateTime( $date_str, new DateTimeZone( 'UTC' ) );
				$dt->setTimezone( $site_tz );
			} elseif ( ! empty( $prop['params']['TZID'] ) ) {
				try {
					$source_tz = new DateTimeZone( $prop['params']['TZID'] );
					$dt        = new DateTime( $date_str, $source_tz );
					$dt->setTimezone( $site_tz );
				} catch ( Exception $e ) {
					// Unrecognized zone name (some exporters use non-IANA
					// aliases) — treat as already-local rather than failing
					// the whole event over a timezone label.
					$dt = new DateTime( $date_str, $site_tz );
				}
			} else {
				// A "floating" time with no zone info at all — RFC 5545 says
				// to interpret this in whatever zone the consumer considers
				// local, which here is the site's own configured timezone.
				$dt = new DateTime( $date_str, $site_tz );
			}
		} catch ( Exception $e ) {
			return null;
		}

		return array(
			'datetime' => $dt,
			'all_day'  => false,
		);
	}

	/**
	 * RFC 5545 TEXT escaping: literal backslash-n is a line break,
	 * backslash-comma/semicolon/backslash are the escaped forms of
	 * characters that are otherwise structural in this format.
	 */
	private static function unescape_text( $value ) {
		$value = str_replace( array( '\\n', '\\N' ), "\n", $value );
		$value = str_replace( array( '\\,', '\\;' ), array( ',', ';' ), $value );
		$value = str_replace( '\\\\', '\\', $value );
		return $value;
	}
}
