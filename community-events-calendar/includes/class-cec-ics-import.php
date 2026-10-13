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
	 * @param array  $owner           1.45.0: who the events belong to (see apply_owner()).
	 * @return array{created:int,updated:int,recurrence_skipped:int,errors:string[],ids:int[]}
	 */
	public static function import( $raw, $author_id, $update_existing = false, $owner = array() ) {
		$result = self::empty_result();

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

			$organizer_name = '';
			if ( isset( $props['ORGANIZER']['params']['CN'] ) ) {
				$organizer_name = trim( $props['ORGANIZER']['params']['CN'], " \t\"" );
			}
			// Many real calendar exports (Google Calendar, Eventbrite,
			// etc.) set a URL property pointing at a "more info" page for
			// the event — this plugin's own .ics export already emits
			// one (pointing at the event's own permalink), so reading it
			// back on import is the natural counterpart.
			self::save_event(
				array(
					'title'       => $title,
					'description' => isset( $props['DESCRIPTION'] ) ? self::unescape_text( $props['DESCRIPTION']['value'] ) : '',
					'location'    => isset( $props['LOCATION'] ) ? self::unescape_text( $props['LOCATION']['value'] ) : '',
					'uid'         => isset( $props['UID'] ) ? trim( $props['UID']['value'] ) : '',
					'organizer'   => $organizer_name,
					'url'         => isset( $props['URL']['value'] ) ? esc_url_raw( trim( $props['URL']['value'] ) ) : '',
					'start'       => $start_dt,
					'end'         => $end_dt,
					'all_day'     => $start_info['all_day'],
					'timezone'    => ! empty( $props['DTSTART']['params']['TZID'] ) ? $props['DTSTART']['params']['TZID'] : '',
					'repeats'     => ! empty( $props['RRULE'] ),
					'price'       => null,
				),
				$author_id,
				$update_existing,
				$owner,
				$result
			);
		}

		return self::finish( $result );
	}

	/**
	 * 1.45.0: who imported events belong to. Empty = the uploader, as before.
	 * - member_id: the member who manages them (post author, so they show
	 *   under My Events); their email is also kept as the submitter email.
	 * - email: someone without an account; they manage the events through
	 *   Manage My Submission (an edit link by email), like a guest submission.
	 * - org_ids: organization / titleholder terms, so the events show on
	 *   that group's page and under its name on the calendar.
	 *
	 * @param array $owner { member_id?: int, email?: string, org_ids?: int[] }
	 */
	private static function apply_owner( $post_id, $owner ) {
		if ( ! empty( $owner['member_id'] ) ) {
			wp_update_post( array( 'ID' => $post_id, 'post_author' => (int) $owner['member_id'] ) );
			$user = get_userdata( (int) $owner['member_id'] );
			if ( $user ) {
				update_post_meta( $post_id, '_cec_submitter_email', strtolower( $user->user_email ) );
			}
		} elseif ( ! empty( $owner['email'] ) ) {
			// Like a guest submission: no account owns it; the email does.
			wp_update_post( array( 'ID' => $post_id, 'post_author' => 0 ) );
			update_post_meta( $post_id, '_cec_submitter_email', strtolower( $owner['email'] ) );
		}
		if ( ! empty( $owner['org_ids'] ) ) {
			wp_set_object_terms( $post_id, array_map( 'intval', $owner['org_ids'] ), 'cec_partner_org', false );
		}
	}

	/**
	 * Saves one normalized event (from an .ics VEVENT or a CSV row).
	 *
	 * @param array $ev  title, description, location, uid, organizer, url,
	 *                   start/end (DateTime, site time), all_day, timezone,
	 *                   repeats, price (null = the source has no price).
	 */
	private static function save_event( $ev, $author_id, $update_existing, $owner, &$result ) {
		$title          = $ev['title'];
		$description    = $ev['description'];
		$location       = $ev['location'];
		$uid            = $ev['uid'];
		$organizer_name = $ev['organizer'];
		$more_info_url  = $ev['url'];
		$start_dt       = $ev['start'];
		$end_dt         = $ev['end'];
		if ( '' === $organizer_name && ! empty( $owner['org_ids'] ) ) {
			$term           = get_term( (int) $owner['org_ids'][0], 'cec_partner_org' );
			$organizer_name = $term && ! is_wp_error( $term ) ? $term->name : '';
		}
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
					// left to review it against — it's published directly
					// rather than queued behind its own uploader. 1.45.0:
					// saved as a draft first and published once its date,
					// place and group are set (below), so the "New event"
					// email to subscribers has the details and reaches
					// the group's followers.
					'post_status'  => 'draft',
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
			return;
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
			if ( null === $ev['price'] ) {
				update_post_meta( $post_id, '_cec_admission_status', 'not_posted' );
				$result['no_price'] = ( $result['no_price'] ?? 0 ) + 1;
			} elseif ( 0.0 === (float) $ev['price'] ) {
				update_post_meta( $post_id, '_cec_admission_status', 'free_confirmed' );
			} else {
				update_post_meta( $post_id, '_cec_admission_status', 'paid' );
				update_post_meta( $post_id, '_cec_price_amount', (string) $ev['price'] );
				update_post_meta( $post_id, '_cec_price_currency', 'USD' );
			}
			update_post_meta( $post_id, '_cec_location_mode', $location ? 'in_person' : 'not_posted' );
			update_post_meta( $post_id, '_cec_time_mode', $ev['all_day'] ? 'all_day' : 'exact' );
			if ( $ev['timezone'] && CEC_Event_Helper::is_valid_timezone( $ev['timezone'] ) ) {
				update_post_meta( $post_id, '_cec_timezone', $ev['timezone'] );
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
		if ( $owner ) {
			self::apply_owner( $post_id, $owner );
		}
		if ( ! $existing_id ) {
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		}
		$result['ids'][] = (int) $post_id;

		if ( $ev['repeats'] ) {
			$result['recurrence_skipped']++;
			/* translators: 1: event title */
			$result['errors'][] = sprintf( __( '"%s" repeats in the source file (a recurrence rule was found) — only its first date was imported. Set recurrence manually on this event if it should repeat here too.', 'cec' ), $title );
		}
	}

	/** The import summary's closing note, once per import rather than per event. */
	private static function finish( $result ) {
		if ( ! empty( $result['no_price'] ) ) {
			// .ics has no price field at all, and a CSV may leave the
			// column empty: "Price not posted" says exactly that instead
			// of guessing Free or inventing a price.
			$result['errors'][] = sprintf(
				/* translators: %d: number of events */
				_n( '%d imported event had no price in the file, so it was marked Price Not Posted rather than guessing Free or a price — review and set the real cost.', '%d imported events had no price in the file, so each was marked Price Not Posted rather than guessing Free or a price — review and set the real cost on each one.', (int) $result['no_price'], 'cec' ),
				(int) $result['no_price']
			);
		}
		unset( $result['no_price'] );
		return $result;
	}

	/**
	 * 1.45.0: CSV import, for organizers whose events live in a spreadsheet
	 * or a tool that exports CSV. One event per row; the first row names the
	 * columns. Column names are matched loosely (case, spaces and
	 * punctuation ignored), including Outlook's export names:
	 *
	 *   Title (or Subject, Name, Event)             required
	 *   Start (date and time), or Start Date + Start Time   required
	 *   End, or End Date + End Time                 optional (defaults to the start)
	 *   All Day (yes/true/1)                        optional; a start with no time is all-day too
	 *   Description, Location (or Venue, Address), URL (or Link), Price (or Cost)
	 *
	 * Price: empty = Price Not Posted; "Free" or 0 = Free; one amount
	 * ("$20", "20.00") = that price; anything else is left Not Posted, noted.
	 * Each row gets a stable ID from its title and start, so re-uploading the
	 * same sheet with "update previously imported" updates instead of duplicating.
	 */
	public static function import_csv( $raw, $author_id, $update_existing = false, $owner = array() ) {
		$result = self::empty_result();
		$raw    = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $raw );
		$fh     = fopen( 'php://temp', 'r+' );
		fwrite( $fh, $raw );
		rewind( $fh );
		$header = fgetcsv( $fh, 0, ',', '"', '\\' );
		$map    = array();
		$alias  = array(
			'title'       => array( 'title', 'subject', 'name', 'event', 'eventname', 'eventtitle', 'summary' ),
			'start'       => array( 'start', 'starts', 'startdatetime', 'startdate', 'date', 'begin' ),
			'start_time'  => array( 'starttime', 'time' ),
			'end'         => array( 'end', 'ends', 'enddatetime', 'enddate', 'finish' ),
			'end_time'    => array( 'endtime' ),
			'all_day'     => array( 'allday', 'alldayevent' ),
			'description' => array( 'description', 'details', 'about', 'notes' ),
			'location'    => array( 'location', 'venue', 'address', 'where', 'place' ),
			'url'         => array( 'url', 'link', 'moreinfo', 'eventurl', 'website', 'eventlink' ),
			'price'       => array( 'price', 'cost', 'admission', 'ticketprice' ),
		);
		foreach ( (array) $header as $i => $name ) {
			$key = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $name ) );
			foreach ( $alias as $field => $names ) {
				if ( ! isset( $map[ $field ] ) && in_array( $key, $names, true ) ) {
					$map[ $field ] = $i;
				}
			}
		}
		if ( ! isset( $map['title'], $map['start'] ) ) {
			fclose( $fh );
			$result['errors'][] = __( 'The first row of the CSV needs column names, including a Title and a Start (or Start Date) column. Download the template from this page to see the layout.', 'cec' );
			return $result;
		}
		$tz  = wp_timezone();
		$row = 1;
		while ( false !== ( $cells = fgetcsv( $fh, 0, ',', '"', '\\' ) ) ) {
			$row++;
			if ( array( null ) === $cells || '' === trim( implode( '', $cells ) ) ) {
				continue;
			}
			$get   = function ( $field ) use ( $map, $cells ) {
				return isset( $map[ $field ], $cells[ $map[ $field ] ] ) ? trim( (string) $cells[ $map[ $field ] ] ) : '';
			};
			$title = $get( 'title' );
			if ( '' === $title ) {
				/* translators: %d: row number in the spreadsheet */
				$result['errors'][] = sprintf( __( 'Row %d has no title and was skipped.', 'cec' ), $row );
				continue;
			}
			$start_str = trim( $get( 'start' ) . ' ' . $get( 'start_time' ) );
			$start     = self::parse_loose_datetime( $start_str, $tz );
			if ( ! $start ) {
				/* translators: 1: row number, 2: event title, 3: what was in the start column */
				$result['errors'][] = sprintf( __( 'Row %1$d ("%2$s"): the start "%3$s" isn\'t a date this importer can read, so it was skipped. Use a format like 2026-10-16 19:00 or 10/16/2026 7:00 PM.', 'cec' ), $row, $title, $start_str );
				continue;
			}
			$all_day = in_array( strtolower( $get( 'all_day' ) ), array( 'yes', 'y', 'true', '1', 'x' ), true ) || ! $start['has_time'];
			$end_str = trim( $get( 'end' ) . ' ' . $get( 'end_time' ) );
			if ( '' === $get( 'end' ) && '' !== $get( 'end_time' ) ) {
				$end_str = trim( $get( 'start' ) . ' ' . $get( 'end_time' ) );
			}
			$end = '' === $end_str ? null : self::parse_loose_datetime( $end_str, $tz );
			if ( '' !== $end_str && ! $end ) {
				/* translators: 1: row number, 2: event title */
				$result['errors'][] = sprintf( __( 'Row %1$d ("%2$s"): the end couldn\'t be read, so the event was given its start time only.', 'cec' ), $row, $title );
			}
			$start_dt = $start['datetime'];
			$end_dt   = $end ? $end['datetime'] : clone $start_dt;
			if ( $all_day ) {
				$start_dt->setTime( 0, 0 );
				$end_dt->setTime( 23, 59 );
			}
			if ( $end_dt < $start_dt ) {
				$end_dt = clone $start_dt;
			}
			$price_raw = strtolower( $get( 'price' ) );
			$price     = null;
			if ( '' !== $price_raw ) {
				if ( in_array( $price_raw, array( 'free', '0', '$0', '0.00', '$0.00', 'no cost' ), true ) ) {
					$price = 0.0;
				} elseif ( 1 === preg_match_all( '/\d+(?:\.\d{1,2})?/', $price_raw, $m ) && preg_match( '/^\s*\$?\s*\d+(?:\.\d{1,2})?\s*(usd)?\s*$/', $price_raw ) ) {
					$price = (float) $m[0][0];
				} else {
					/* translators: 1: row number, 2: event title, 3: what was in the price column */
					$result['errors'][] = sprintf( __( 'Row %1$d ("%2$s"): the price "%3$s" wasn\'t a single amount, so it was left as Price Not Posted. Set it on the event.', 'cec' ), $row, $title, $get( 'price' ) );
				}
			}
			$url = $get( 'url' );
			self::save_event(
				array(
					'title'       => $title,
					'description' => $get( 'description' ),
					'location'    => $get( 'location' ),
					'uid'         => 'csv-' . md5( strtolower( $title ) . '|' . $start_dt->format( 'Y-m-d\TH:i' ) ),
					'organizer'   => '',
					'url'         => $url && wp_http_validate_url( $url ) ? esc_url_raw( $url ) : '',
					'start'       => $start_dt,
					'end'         => $end_dt,
					'all_day'     => $all_day,
					'timezone'    => '',
					'repeats'     => false,
					'price'       => $price,
				),
				$author_id,
				$update_existing,
				$owner,
				$result
			);
		}
		fclose( $fh );
		if ( 1 === $row ) {
			$result['errors'][] = __( 'The CSV has column names but no events below them.', 'cec' );
		}
		return self::finish( $result );
	}

	/**
	 * A spreadsheet date/time in the site's time zone: ISO ("2026-10-16 19:00",
	 * "2026-10-16T19:00"), US ("10/16/2026 7:00 PM", "10/16/26 19:00") or
	 * written out ("Oct 16, 2026 7pm").
	 *
	 * @return array{datetime:DateTime,has_time:bool}|null
	 */
	private static function parse_loose_datetime( $str, $tz ) {
		$str = trim( preg_replace( '/\s+/', ' ', (string) $str ) );
		if ( '' === $str ) {
			return null;
		}
		$has_time = (bool) preg_match( '/\d{1,2}:\d{2}|\d\s*(am|pm)\b/i', $str );
		$formats  = array( 'Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d', 'm/d/Y g:i A', 'm/d/Y g:iA', 'm/d/Y g A', 'm/d/Y H:i', 'm/d/Y', 'm/d/y g:i A', 'm/d/y H:i', 'm/d/y', 'n/j/Y g:i A', 'n/j/Y H:i', 'n/j/Y' );
		foreach ( $formats as $f ) {
			$dt = DateTime::createFromFormat( '!' . $f, strtoupper( $str ), $tz );
			$err = DateTime::getLastErrors();
			if ( $dt && ( ! $err || ( 0 === $err['warning_count'] && 0 === $err['error_count'] ) ) ) {
				return array( 'datetime' => $dt, 'has_time' => $has_time );
			}
		}
		if ( preg_match( '/[a-z]{3}/i', $str ) ) {
			try {
				$dt = new DateTime( $str, $tz );
				return array( 'datetime' => $dt, 'has_time' => $has_time );
			} catch ( Exception $e ) {
				return null;
			}
		}
		return null;
	}

	private static function empty_result() {
		return array(
			'created'            => 0,
			'updated'            => 0,
			'recurrence_skipped' => 0,
			'errors'             => array(),
			'ids'                => array(),
		);
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
		list( , $y, $mo, $d, $h, $mi, $s ) = $m;
		$is_utc = $m[7] ?? ''; // 1.45.0: no trailing Z means no 8th match (was an "Undefined array key" warning).
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
