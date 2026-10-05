<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Add to Calendar" links and .ics export. Reachable as a plain query var
 * (?cec_ical=1 on a single event, ?cec_ical=all site-wide) rather than a
 * registered WP feed, so it works without a rewrite-rules flush.
 */
class CEC_Ical {

	public static function add_query_vars( $vars ) {
		$vars[] = 'cec_ical';
		return $vars;
	}

	/**
	 * The old front-end addresses (?cec_ical=all, an event's ?cec_ical=1)
	 * now redirect to the admin-post.php ones (1.32.1). The host's CDN cached
	 * the front-end ones for up to 7 days, so subscribers saw new and changed
	 * events late. Calendar apps follow the redirect, so existing
	 * subscriptions keep working.
	 */
	public static function maybe_output() {
		$target = get_query_var( 'cec_ical' );
		if ( '' === $target || false === $target ) {
			return;
		}
		if ( 'all' === $target ) {
			wp_safe_redirect( self::feed_url(), 301 );
			exit;
		}
		if ( is_singular( 'cec_event' ) ) {
			wp_safe_redirect( self::event_ics_url( get_queried_object_id() ), 301 );
			exit;
		}
	}

	/** admin-post.php?action=cec_ical&feed=all, or &event=<id> (1.32.1). */
	public static function serve() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only downloads.
		$event = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0;
		$feed  = isset( $_GET['feed'] ) ? sanitize_key( wp_unslash( $_GET['feed'] ) ) : '';
		// phpcs:enable
		if ( $event ) {
			self::output_single( $event );
		} elseif ( 'all' === $feed ) {
			self::output_feed();
		}
		status_header( 404 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Not found';
		exit;
	}

	private static function output_single( $post_id ) {
		if ( ! $post_id || 'cec_event' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return;
		}

		$data = CEC_Event_Helper::data( $post_id );

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $data['title'] ) . '.ics"' );
		echo self::build_ics( array( $data ), CEC_Event_Helper::site_name() . ' - ' . $data['title'] ); // phpcs:ignore
		exit;
	}

	private static function output_feed() {
		$query = new WP_Query(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'meta_key'       => '_cec_start',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => '_cec_start',
						'value'   => date( 'Y-m-d\TH:i', current_time( 'timestamp' ) - DAY_IN_SECONDS ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions
						'compare' => '>=',
					),
				),
			)
		);

		$events = array();
		foreach ( $query->posts as $p ) {
			$events[] = CEC_Event_Helper::data( $p->ID );
		}

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="events.ics"' );
		echo self::build_ics( $events, CEC_Event_Helper::site_name() . ' Events' ); // phpcs:ignore
		exit;
	}

	/**
	 * An .ics calendar for these events (1.32.0, public for Community
	 * Member Planning's private calendar feed). $tentative: event ids to mark
	 * TENTATIVE (a member who's only "Interested"). A subscribed calendar is
	 * asked to refresh every few hours.
	 *
	 * @param array  $events    CEC_Event_Helper::data() arrays.
	 * @param string $calname   Calendar name shown in calendar apps.
	 * @param int[]  $tentative Event ids to mark tentative.
	 */
	public static function calendar( $events, $calname, $tentative = array() ) {
		return self::build_ics( $events, $calname, array_map( 'intval', (array) $tentative ), true );
	}

	private static function build_ics( $events, $calname, $tentative = array(), $subscribed = false ) {
		$lines   = array();
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//' . self::escape( CEC_Event_Helper::site_name() ) . '//Community Events Calendar//EN';
		$lines[] = 'CALSCALE:GREGORIAN';
		$lines[] = 'X-WR-CALNAME:' . self::escape( $calname );
		if ( $subscribed ) {
			$lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT4H';
			$lines[] = 'X-PUBLISHED-TTL:PT4H';
		}

		foreach ( $events as $data ) {
			if ( ! $data['start_ts'] || ! $data['start_raw'] ) {
				continue;
			}

			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:cec-event-' . $data['id'] . '@' . self::host();
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );

			$end_raw = get_post_meta( $data['id'], '_cec_end', true );
			if ( 'all_day' === $data['time_mode'] ) {
				// RFC 5545: an all-day DTEND is EXCLUSIVE (the day after the
				// last day actually included) — so a single-day all-day event
				// needs start+1 day as its end, never the same date twice.
				$lines[] = 'DTSTART;VALUE=DATE:' . date( 'Ymd', $data['start_ts'] );
				$end_ts  = $end_raw ? strtotime( $end_raw ) : $data['start_ts'];
				$lines[] = 'DTEND;VALUE=DATE:' . date( 'Ymd', strtotime( '+1 day', $end_ts ) );
			} else {
				$lines[] = 'DTSTART:' . self::utc_ics( $data['start_raw'], $data['timezone'] );
				if ( $end_raw ) {
					$lines[] = 'DTEND:' . self::utc_ics( $end_raw, $data['timezone'] );
				}
			}

			$lines[] = 'SUMMARY:' . self::escape( $data['title_plain'] );

			$desc = CEC_Event_Helper::plain_text( $data['excerpt'] ? $data['excerpt'] : $data['content'] );
			if ( $desc ) {
				$lines[] = 'DESCRIPTION:' . self::escape( $desc );
			}

			$lines[] = 'URL:' . $data['permalink'];

			$where     = CEC_Event_Helper::location_display( $data );
			$location  = 'online' === $data['location_mode'] ? ( $where['online_url'] ? $where['online_url'] : $where['label'] ) : trim( $data['venue_name'] . ( $data['address'] && $data['address'] !== $data['venue_name'] ? ', ' . $data['address'] : '' ) );
			if ( $location ) {
				$lines[] = 'LOCATION:' . self::escape( $location );
			}

			if ( 'cancelled' === $data['event_status'] ) {
				$lines[] = 'STATUS:CANCELLED';
			} elseif ( 'postponed' === $data['event_status'] || in_array( (int) $data['id'], $tentative, true ) ) {
				$lines[] = 'STATUS:TENTATIVE';
			} else {
				$lines[] = 'STATUS:CONFIRMED';
			}

			$lines[] = 'END:VEVENT';
		}

		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", self::fold_lines( $lines ) ) . "\r\n";
	}

	/**
	 * Converts a stored "local" datetime string (the event's own wall-clock
	 * time, no offset info of its own) into a UTC ICS timestamp, using the
	 * event's resolved timezone (data()['timezone']) — not the site's, which
	 * is what get_gmt_from_date() assumed before 1.25.1 and which put every
	 * event set to a different timezone at the wrong time.
	 */
	private static function utc_ics( $raw, $timezone ) {
		$dt = CEC_Event_Helper::local_datetime( $raw, $timezone );
		if ( ! $dt ) {
			return '';
		}
		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
	}

	private static function host() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host ? $host : 'localhost';
	}

	private static function escape( $text ) {
		$text = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\\;', '\\,' ), $text );
		$text = str_replace( array( "\r\n", "\n", "\r" ), '\\n', $text );
		return $text;
	}

	private static function fold_lines( $lines ) {
		$out = array();
		foreach ( $lines as $line ) {
			while ( mb_strlen( $line, 'UTF-8' ) > 75 ) {
				$out[] = mb_substr( $line, 0, 75, 'UTF-8' );
				$line  = ' ' . mb_substr( $line, 75, null, 'UTF-8' );
			}
			$out[] = $line;
		}
		return $out;
	}

	public static function single_ics_url( $data ) {
		return self::event_ics_url( (int) $data['id'] );
	}

	public static function event_ics_url( $event_id ) {
		return add_query_arg( array( 'action' => 'cec_ical', 'event' => (int) $event_id ), admin_url( 'admin-post.php' ) );
	}

	public static function feed_url() {
		return add_query_arg( array( 'action' => 'cec_ical', 'feed' => 'all' ), admin_url( 'admin-post.php' ) );
	}

	public static function google_calendar_url( $data ) {
		$end_raw = get_post_meta( $data['id'], '_cec_end', true );

		if ( 'all_day' === $data['time_mode'] ) {
			$end_ts = $end_raw ? strtotime( $end_raw ) : $data['start_ts'];
			$dates  = date( 'Ymd', $data['start_ts'] ) . '/' . date( 'Ymd', strtotime( '+1 day', $end_ts ) );
		} else {
			$start_raw = $data['start_raw'];
			if ( ! $end_raw ) {
				$end_raw = $start_raw;
			}
			$dates = self::utc_ics( $start_raw, $data['timezone'] ) . '/' . self::utc_ics( $end_raw, $data['timezone'] );
		}

		$where    = CEC_Event_Helper::location_display( $data );
		$location = 'online' === $data['location_mode'] ? ( $where['online_url'] ? $where['online_url'] : $where['label'] ) : trim( $data['venue_name'] . ( $data['address'] && $data['address'] !== $data['venue_name'] ? ', ' . $data['address'] : '' ) );
		$desc     = CEC_Event_Helper::plain_text( $data['excerpt'] ? $data['excerpt'] : $data['content'] );

		return add_query_arg(
			array(
				'action'   => 'TEMPLATE',
				'text'     => rawurlencode( $data['title_plain'] ),
				'dates'    => $dates,
				'details'  => rawurlencode( $desc . "\n\n" . $data['permalink'] ),
				'location' => rawurlencode( $location ),
				'sf'       => 'true',
				'output'   => 'xml',
			),
			'https://www.google.com/calendar/render'
		);
	}
}
