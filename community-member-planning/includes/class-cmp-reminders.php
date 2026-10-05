<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event reminders (0.19.0). Owner's choices, 2026-10-05: the evening before
 * (6 pm) and 2 hours before, each switchable; in the member area, and by
 * email only if the member turns it on (kink-event emails can be sensitive
 * in a shared inbox). Push comes in release 2.
 *
 * - Every 15 minutes (WP-Cron), for events on members' calendars: Going
 *   always; Interested only if the member asks for it.
 * - "Evening before" means from 6 pm the day before, in the member's time
 *   zone (Account → time zone); "2 hours before" uses the event's own time
 *   zone, and only for events with a set start time.
 * - Reminders aren't held for quiet hours: they're about a time.
 * - Each reminder is sent once per start time (calendar.rem_*_for holds
 *   the start it was sent for), so a moved event is reminded again.
 * - Cancelled or postponed events, unpublished events and accounts that
 *   are no longer members get nothing.
 */
class CMP_Reminders {

	const HOOK     = 'cmp_event_reminders';
	const SCHEDULE = 'cmp_every_15_minutes';
	const NONCE    = 'cmp_reminders';

	/** meta key => default. */
	const PREFS = array(
		'cmp_rem_evening'    => '1',
		'cmp_rem_2h'         => '1',
		'cmp_rem_interested' => '0',
		'cmp_rem_email'      => '0',
	);

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 15 minutes on purpose.
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'admin_post_cmp_reminders', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_cmp_reminders', array( 'CMP_Member_Area', 'redirect_to_login' ) );
	}

	public static function schedules( $s ) {
		$s[ self::SCHEDULE ] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => __( 'Every 15 minutes', 'cmp' ) );
		return $s;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function pref( $user_id, $key ) {
		$v = get_user_meta( $user_id, $key, true );
		return '1' === ( '' === $v ? self::PREFS[ $key ] : (string) $v );
	}

	public static function notices() {
		return array( 'rem_saved' => array( 'success', __( 'Reminder settings saved.', 'cmp' ) ) );
	}

	public static function handle() {
		$user_id = get_current_user_id();
		if ( ! CMP_Access::is_member( $user_id ) ) {
			wp_safe_redirect( CMP_Settings::member_page_url() );
			exit;
		}
		if ( ! isset( $_POST['_cmp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cmp_nonce'] ) ), self::NONCE ) ) {
			wp_safe_redirect( add_query_arg( 'cmp_notice', 'expired', CMP_Account::url() ) );
			exit;
		}
		foreach ( array_keys( self::PREFS ) as $key ) {
			update_user_meta( $user_id, $key, empty( $_POST[ $key ] ) ? '0' : '1' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		}
		CMP_Audit::log( 'reminder_settings_changed', 'user', $user_id );
		wp_safe_redirect( add_query_arg( 'cmp_notice', 'rem_saved', CMP_Account::url() ) . '#cmp-app' );
		exit;
	}

	/** Account → App & notifications. */
	public static function settings_html( $user_id ) {
		$rows = array(
			'cmp_rem_evening'    => __( 'The evening before (6 pm)', 'cmp' ),
			'cmp_rem_2h'         => __( '2 hours before', 'cmp' ),
			'cmp_rem_interested' => __( 'Also for events I\'m only "Interested" in', 'cmp' ),
			'cmp_rem_email'      => __( 'Also email me reminders', 'cmp' ),
		);
		$html = '<section class="cmp-panel" id="cmp-app"><h3 class="cmp-panel-title">' . esc_html__( 'App & notifications', 'cmp' ) . '</h3>'
			. CMP_App::install_html( false )
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cmp-form"><input type="hidden" name="action" value="cmp_reminders" /><input type="hidden" name="_cmp_nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" />'
			. '<fieldset class="cmp-fieldset"><legend>' . esc_html__( 'Reminders for events on my calendar', 'cmp' ) . '</legend>';
		foreach ( $rows as $key => $label ) {
			$html .= '<p class="cmp-check cmp-check-small"><input type="checkbox" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="1"' . checked( self::pref( $user_id, $key ), true, false ) . ' /><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></p>';
		}
		$html .= '<p class="cmp-muted">' . esc_html__( 'Reminders appear in your notifications here. Emails only say "Reminder" and the time in the subject; the event is inside. Push notifications to your phone come in the next update.', 'cmp' ) . '</p></fieldset>'
			. '<button type="submit" class="cmp-btn">' . esc_html__( 'Save reminder settings', 'cmp' ) . '</button></form></section>';
		return $html;
	}

	/* ------------------------------------------------------------------
	 * Sending
	 * ---------------------------------------------------------------- */

	/**
	 * Send what's due. $now (a timestamp) is for tests.
	 *
	 * @return array counts: evening, two_hours, emails.
	 */
	public static function run( $now = null ) {
		global $wpdb;
		$now  = $now ? (int) $now : time();
		$done = array( 'evening' => 0, 'two_hours' => 0, 'emails' => 0 );
		if ( ! post_type_exists( 'cec_event' ) || ! class_exists( 'CEC_Event_Helper' ) ) {
			return $done;
		}
		$t    = CMP_Install::table( 'calendar' );
		$from = wp_date( 'Y-m-d', $now - DAY_IN_SECONDS );
		$to   = wp_date( 'Y-m-d', $now + 3 * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.*, s.meta_value AS start FROM $t c JOIN {$wpdb->posts} p ON p.ID = c.event_id AND p.post_type = 'cec_event' AND p.post_status = 'publish' JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_cec_start' WHERE SUBSTR( s.meta_value, 1, 10 ) BETWEEN %s AND %s", $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			$user = (int) $r->user_id;
			if ( ! CMP_Access::is_member( $user ) || ( 'interested' === $r->response && ! self::pref( $user, 'cmp_rem_interested' ) ) ) {
				continue;
			}
			$data = CEC_Event_Helper::data( (int) $r->event_id );
			if ( 'scheduled' !== $data['event_status'] || ! $data['start_raw'] ) {
				continue;
			}
			$timed = 'all_day' !== $data['time_mode'] && false !== strpos( $data['start_raw'], 'T' ) && in_array( isset( $data['display_time_mode'] ) ? $data['display_time_mode'] : 'exact', array( 'exact', 'start_only' ), true );
			$start = CEC_Event_Helper::local_datetime( $timed ? $data['start_raw'] : substr( $data['start_raw'], 0, 10 ) . 'T00:00', $data['timezone'] );
			if ( ! $start ) {
				continue;
			}
			$start_ts = $start->getTimestamp();
			$key      = (string) $data['start_raw'];
			$tz       = CMP_Notifications::user_timezone( $user );
			$local    = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
			$sent     = array();

			// 2 hours before: timed events starting within the next 2 hours.
			if ( $timed && $start_ts > $now && $start_ts - $now <= 2 * HOUR_IN_SECONDS && $r->rem_2h_for !== $key && self::pref( $user, 'cmp_rem_2h' ) ) {
				$sent['rem_2h_for'] = $key;
				$sent['rem_evening_for'] = $key; // Too late for "tomorrow" anyway.
				self::send( $user, $data, $start, $timed, 'soon', $tz );
				++$done['two_hours'];
			} elseif ( $r->rem_evening_for !== $key && self::pref( $user, 'cmp_rem_evening' ) && (int) $local->format( 'G' ) >= 18 && $start->setTimezone( $tz )->format( 'Y-m-d' ) === $local->modify( '+1 day' )->format( 'Y-m-d' ) ) {
				// The evening before: from 6 pm (member's time) the day before.
				$sent['rem_evening_for'] = $key;
				self::send( $user, $data, $start, $timed, 'tomorrow', $tz );
				++$done['evening'];
			}
			if ( $sent ) {
				$wpdb->update( $t, $sent, array( 'user_id' => $user, 'event_id' => (int) $r->event_id ) );
				if ( self::pref( $user, 'cmp_rem_email' ) ) {
					++$done['emails'];
				}
			}
		}
		return $done;
	}

	/** "Tomorrow at 7:00 pm" / "Today at 7:00 pm" / "Tomorrow". */
	private static function when( DateTimeImmutable $start, $timed, $kind, DateTimeZone $tz ) {
		$day = 'tomorrow' === $kind ? __( 'Tomorrow', 'cmp' ) : __( 'Today', 'cmp' );
		if ( ! $timed ) {
			return $day;
		}
		/* translators: 1: Tomorrow / Today, 2: time */
		return sprintf( __( '%1$s at %2$s', 'cmp' ), $day, wp_date( get_option( 'time_format' ), $start->getTimestamp(), $tz ) );
	}

	private static function send( $user_id, $data, DateTimeImmutable $start, $timed, $kind, DateTimeZone $tz ) {
		$when  = self::when( $start, $timed, $kind, $tz );
		$title = html_entity_decode( wp_strip_all_tags( $data['title'] ), ENT_QUOTES, 'UTF-8' );
		/* translators: 1: when, 2: event */
		CMP_Notifications::add( $user_id, 'event_reminder', sprintf( __( '%1$s: %2$s', 'cmp' ), $when, $title ), $data['permalink'], false );
		if ( ! self::pref( $user_id, 'cmp_rem_email' ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}
		$where   = CEC_Event_Helper::location_display( $data );
		$place   = trim( $where['label'] . ( ! empty( $data['address'] ) && $data['address'] !== $where['label'] ? ', ' . $data['address'] : '' ) );
		$name    = CMP_App::short_name();
		/* translators: 1: site short name, 2: when */
		$subject = sprintf( __( '%1$s reminder: %2$s', 'cmp' ), $name, $when );
		$body    = $title . "\n" . wp_date( 'l, F j', $start->getTimestamp(), $tz ) . ( $timed ? ', ' . wp_date( get_option( 'time_format' ), $start->getTimestamp(), $tz ) : '' ) . "\n" . ( $place ? $place . "\n" : '' ) . "\n" . $data['permalink'] . "\n\n"
			/* translators: %s: site short name */
			. sprintf( __( 'You get this because you turned on email reminders in %s (Account → App & notifications). You can turn them off there.', 'cmp' ), $name ) . "\n" . CMP_Account::url() . '#cmp-app';
		wp_mail( $user->user_email, $subject, $body );
	}

	/* ------------------------------------------------------------------
	 * Privacy
	 * ---------------------------------------------------------------- */

	public static function export_rows( $user_id ) {
		$rows = array();
		foreach ( array( 'cmp_rem_evening' => __( 'Reminder the evening before', 'cmp' ), 'cmp_rem_2h' => __( 'Reminder 2 hours before', 'cmp' ), 'cmp_rem_interested' => __( 'Reminders for Interested events', 'cmp' ), 'cmp_rem_email' => __( 'Email reminders', 'cmp' ) ) as $key => $label ) {
			$rows[] = array( 'name' => $label, 'value' => self::pref( $user_id, $key ) ? __( 'On', 'cmp' ) : __( 'Off', 'cmp' ) );
		}
		return $rows;
	}

	public static function erase( $user_id ) {
		foreach ( array_keys( self::PREFS ) as $key ) {
			delete_user_meta( $user_id, $key );
		}
		return 0;
	}
}
