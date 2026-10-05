<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * My calendar (0.17.0; owner, 2026-10-05: "I don't have a way to 'Add to my
 * calendar' or have a public, friends/dynamic, or private calendar for my
 * profile. That was the whole goal of merging the two features."). Owner's
 * choices: one calendar per member with an audience per event; audiences
 * are all signed-in members, dynamic partners (an active dynamic) or only
 * me; "Going" also counts as the event's RSVP; the profile's "Going to"
 * becomes its Calendar tab, filtered by who's looking.
 *
 * - "Add to my calendar" sits on every event page (via the Community Events
 *   Calendar hook cec_single_event_calendar_actions): Going / Interested
 *   and who can see it, defaulting to the member's own choice.
 * - Going on an event that takes RSVPs here adds the member's RSVP (with
 *   the event's capacity rule); Interested or Remove takes it away. An RSVP
 *   made with the event's own form while signed in lands here as Going.
 * - Who sees an entry is decided in one place, can_see(): blocks hide
 *   everything both ways. Followers' notifications and the "people you
 *   follow are going" marker on the community calendar follow the same rule.
 */
class CMP_Calendar {

	const TAB          = 'calendar';
	const NONCE        = 'cmp_calendar';
	const META_DEFAULT = 'cmp_cal_default';
	const MIGRATED     = 'cmp_calendar_migrated';
	const AUDIENCES    = array( 'members', 'partners', 'private' );
	const RESPONSES    = array( 'going', 'interested' );

	/** Per-request cache for the calendar marker: viewer => followed ids. */
	private static $followed = array();

	public static function init() {
		add_action( 'admin_post_cmp_cal', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_cmp_cal', array( 'CMP_Member_Area', 'redirect_to_login' ) );
		add_action( 'cec_single_event_calendar_actions', array( __CLASS__, 'event_box' ) );
		add_action( 'cec_rsvp_created', array( __CLASS__, 'on_form_rsvp' ), 10, 2 );
		add_filter( 'cec_event_social_label', array( __CLASS__, 'calendar_label' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets() {
		if ( is_singular( 'cec_event' ) ) {
			wp_enqueue_style( 'cmp-calendar', CMP_URL . 'assets/css/calendar.css', array(), CMP_VERSION );
		}
	}

	public static function url( $notice = '' ) {
		$url = add_query_arg( 'cmp_tab', self::TAB, CMP_Settings::member_page_url() );
		return $notice ? add_query_arg( 'cmp_notice', $notice, $url ) : $url;
	}

	public static function notices() {
		return array(
			'cal_saved'   => array( 'success', __( 'Saved to your calendar.', 'cmp' ) ),
			'cal_removed' => array( 'success', __( 'Removed from your calendar.', 'cmp' ) ),
			'cal_default' => array( 'success', __( 'Saved. New events start with this choice.', 'cmp' ) ),
			'cal_full'    => array( 'error', __( 'This event is full, so you can\'t RSVP as Going. You can add it as Interested.', 'cmp' ) ),
			'cal_gone'    => array( 'error', __( 'That event isn\'t available.', 'cmp' ) ),
		);
	}

	public static function audience_labels() {
		return array(
			'members'  => __( 'All members', 'cmp' ),
			'partners' => __( 'Dynamic partners', 'cmp' ),
			'private'  => __( 'Only me', 'cmp' ),
		);
	}

	public static function response_labels() {
		return array(
			'going'      => __( 'Going', 'cmp' ),
			'interested' => __( 'Interested', 'cmp' ),
		);
	}

	public static function default_audience( $user_id ) {
		$v = get_user_meta( $user_id, self::META_DEFAULT, true );
		return in_array( $v, self::AUDIENCES, true ) ? $v : 'partners';
	}

	/* ------------------------------------------------------------------
	 * Reading
	 * ---------------------------------------------------------------- */

	private static function t() {
		return CMP_Install::table( 'calendar' );
	}

	private static function events_ready() {
		return post_type_exists( 'cec_event' ) && class_exists( 'CEC_Event_Helper' );
	}

	public static function get( $user_id, $event_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE user_id = %d AND event_id = %d', $user_id, $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Whether $viewer may see an entry with this audience on $owner's
	 * calendar. The member themselves always can; otherwise the viewer must
	 * be a member, not blocked either way, and in the audience.
	 */
	public static function can_see( $audience, $owner_id, $viewer_id ) {
		$owner_id  = (int) $owner_id;
		$viewer_id = (int) $viewer_id;
		if ( $owner_id && $owner_id === $viewer_id ) {
			return true;
		}
		if ( ! $viewer_id || ! CMP_Access::is_member( $viewer_id ) || CMP_Messages::is_blocked( $owner_id, $viewer_id ) ) {
			return false;
		}
		if ( 'members' === $audience ) {
			return true;
		}
		return 'partners' === $audience && (bool) CMP_Dynamics::active_between( $owner_id, $viewer_id );
	}

	/**
	 * A member's calendar as $viewer may see it, soonest first (upcoming) or
	 * latest first (past). Published events only.
	 *
	 * @return array of objects: entry columns + start (event's local "Y-m-d\TH:i").
	 */
	public static function entries( $owner_id, $viewer_id, $upcoming = true, $limit = 50 ) {
		global $wpdb;
		if ( ! self::events_ready() ) {
			return array();
		}
		$today = wp_date( 'Y-m-d' );
		$cmp   = $upcoming ? '>=' : '<';
		$order = $upcoming ? 'ASC' : 'DESC';
		// The end date when set, else the start, so an event still running counts as upcoming.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT c.*, s.meta_value AS start FROM ' . self::t() . " c JOIN {$wpdb->posts} p ON p.ID = c.event_id AND p.post_type = 'cec_event' AND p.post_status = 'publish' JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_cec_start' LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_cec_end' WHERE c.user_id = %d AND SUBSTR( COALESCE( NULLIF( e.meta_value, '' ), s.meta_value ), 1, 10 ) $cmp %s ORDER BY s.meta_value $order LIMIT %d", $owner_id, $today, $limit * 3 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			if ( self::can_see( $r->audience, $owner_id, $viewer_id ) ) {
				$out[] = $r;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Saving
	 * ---------------------------------------------------------------- */

	/**
	 * Put an event on a member's calendar (or change it). Going adds their
	 * RSVP where the event takes RSVPs here; Interested removes it.
	 *
	 * @return string 'ok', 'full' or 'gone'.
	 */
	public static function save( $user_id, $event_id, $response, $audience, $sync_rsvp = true ) {
		global $wpdb;
		if ( ! self::events_ready() || 'cec_event' !== get_post_type( $event_id ) || 'publish' !== get_post_status( $event_id ) || ! in_array( $response, self::RESPONSES, true ) || ! in_array( $audience, self::AUDIENCES, true ) ) {
			return 'gone';
		}
		$was = self::get( $user_id, $event_id );
		if ( $sync_rsvp && class_exists( 'CEC_RSVP' ) && method_exists( 'CEC_RSVP', 'add_member_rsvp' ) ) {
			if ( 'going' === $response && CEC_RSVP::accepts_member_rsvp( $event_id ) && 'full' === CEC_RSVP::add_member_rsvp( $event_id, $user_id ) ) {
				return 'full';
			}
			if ( 'interested' === $response ) {
				CEC_RSVP::remove_member_rsvp( $event_id, $user_id );
			}
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( $was ) {
			$wpdb->update( self::t(), array( 'response' => $response, 'audience' => $audience, 'updated_at' => $now ), array( 'user_id' => $user_id, 'event_id' => $event_id ), array( '%s', '%s', '%s' ), array( '%d', '%d' ) );
		} else {
			$wpdb->insert( self::t(), array( 'user_id' => $user_id, 'event_id' => $event_id, 'response' => $response, 'audience' => $audience, 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%d', '%s', '%s', '%s', '%s' ) );
		}
		$now_going = 'going' === $response;
		$was_shown = $was && 'going' === $was->response ? $was->audience : '';
		if ( $now_going && $was_shown !== $audience ) {
			self::tell_followers( $user_id, $event_id, $audience, $was_shown );
		}
		return 'ok';
	}

	public static function remove( $user_id, $event_id ) {
		global $wpdb;
		if ( class_exists( 'CEC_RSVP' ) && method_exists( 'CEC_RSVP', 'remove_member_rsvp' ) ) {
			CEC_RSVP::remove_member_rsvp( $event_id, $user_id );
		}
		return (int) $wpdb->delete( self::t(), array( 'user_id' => $user_id, 'event_id' => $event_id ), array( '%d', '%d' ) );
	}

	/**
	 * "X is going to Y" for followers allowed to see it, and only those who
	 * couldn't see it before (changing the audience wider tells the new ones).
	 */
	private static function tell_followers( $user_id, $event_id, $audience, $before = '' ) {
		$end = get_post_meta( $event_id, '_cec_end', true );
		$end = $end ? $end : get_post_meta( $event_id, '_cec_start', true );
		if ( ! $end || substr( $end, 0, 10 ) < wp_date( 'Y-m-d' ) ) {
			return; // Nothing to tell about an event that's over.
		}
		$user  = get_userdata( $user_id );
		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $event_id ) ), ENT_QUOTES, 'UTF-8' );
		foreach ( CMP_Follows::follower_ids( $user_id ) as $f ) {
			if ( self::can_see( $audience, $user_id, $f ) && ( '' === $before || ! self::can_see( $before, $user_id, $f ) ) ) {
				/* translators: 1: member, 2: event */
				CMP_Notifications::add( $f, 'follow', sprintf( __( '%1$s is going to %2$s.', 'cmp' ), $user ? $user->display_name : '', $title ), get_permalink( $event_id ) );
			}
		}
	}

	/** An RSVP made with the event's own form while signed in: on their calendar as Going. */
	public static function on_form_rsvp( $event_id, $user_id ) {
		if ( ! $user_id || ! CMP_Access::is_member( $user_id ) ) {
			return;
		}
		$was = self::get( $user_id, $event_id );
		self::save( $user_id, $event_id, 'going', $was ? $was->audience : self::default_audience( $user_id ), false );
	}

	public static function handle() {
		$user_id = get_current_user_id();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked just below.
		$event_id = isset( $_POST['event'] ) ? absint( $_POST['event'] ) : 0;
		$back_to  = isset( $_POST['back'] ) && 'event' === sanitize_key( wp_unslash( $_POST['back'] ) ) && $event_id ? 'event' : 'tab';
		$go       = function ( $notice ) use ( $back_to, $event_id ) {
			wp_safe_redirect( 'event' === $back_to ? add_query_arg( 'cmp_cal', $notice, get_permalink( $event_id ) ) . '#cmp-cal' : self::url( $notice ) );
			exit;
		};
		if ( ! CMP_Access::is_member( $user_id ) ) {
			wp_safe_redirect( CMP_Settings::member_page_url() );
			exit;
		}
		if ( ! isset( $_POST['_cmp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cmp_nonce'] ) ), self::NONCE ) ) {
			wp_safe_redirect( self::url( 'expired' ) );
			exit;
		}
		$do       = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$response = isset( $_POST['response'] ) ? sanitize_key( wp_unslash( $_POST['response'] ) ) : '';
		$audience = isset( $_POST['audience'] ) ? sanitize_key( wp_unslash( $_POST['audience'] ) ) : '';
		// phpcs:enable
		if ( 'default' === $do ) {
			if ( in_array( $audience, self::AUDIENCES, true ) ) {
				update_user_meta( $user_id, self::META_DEFAULT, $audience );
			}
			$go( 'cal_default' );
		}
		if ( 'remove' === $do ) {
			self::remove( $user_id, $event_id );
			$go( 'cal_removed' );
		}
		$result = 'save' === $do ? self::save( $user_id, $event_id, $response, $audience ) : 'gone';
		$go( 'ok' === $result ? 'cal_saved' : ( 'full' === $result ? 'cal_full' : 'cal_gone' ) );
	}

	/* ------------------------------------------------------------------
	 * Event page
	 * ---------------------------------------------------------------- */

	private static function form_open( $do, $event_id = 0, $back = 'tab', $class = 'cmp-cal-form' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '"><input type="hidden" name="action" value="cmp_cal" /><input type="hidden" name="do" value="' . esc_attr( $do ) . '" />'
			. ( $event_id ? '<input type="hidden" name="event" value="' . (int) $event_id . '" />' : '' )
			. '<input type="hidden" name="back" value="' . esc_attr( $back ) . '" /><input type="hidden" name="_cmp_nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" />';
	}

	/** Two rows of pill choices: Going / Interested, and who can see it. */
	private static function choices_html( $prefix, $response, $audience ) {
		$html = '<fieldset class="cmp-cal-choice"><legend>' . esc_html__( 'Your plan', 'cmp' ) . '</legend><div class="cmp-cal-pills">';
		foreach ( self::response_labels() as $k => $l ) {
			$id    = $prefix . '_r_' . $k;
			$html .= '<span class="cmp-cal-pill"><input type="radio" id="' . esc_attr( $id ) . '" name="response" value="' . esc_attr( $k ) . '"' . checked( $response, $k, false ) . ' /><label for="' . esc_attr( $id ) . '">' . esc_html( $l ) . '</label></span>';
		}
		$html .= '</div></fieldset><fieldset class="cmp-cal-choice"><legend>' . esc_html__( 'Who can see it on your profile', 'cmp' ) . '</legend><div class="cmp-cal-pills">';
		foreach ( self::audience_labels() as $k => $l ) {
			$id    = $prefix . '_a_' . $k;
			$html .= '<span class="cmp-cal-pill"><input type="radio" id="' . esc_attr( $id ) . '" name="audience" value="' . esc_attr( $k ) . '"' . checked( $audience, $k, false ) . ' /><label for="' . esc_attr( $id ) . '">' . esc_html( $l ) . '</label></span>';
		}
		return $html . '</div></fieldset>';
	}

	/** "Add to my calendar" under the event's calendar links. */
	public static function event_box( $data ) {
		$event_id = (int) $data['id'];
		$user_id  = get_current_user_id();
		echo '<div class="cmp-cal-box" id="cmp-cal">';
		if ( ! $user_id ) {
			echo '<a class="cmp-cal-btn" href="' . esc_url( wp_login_url( get_permalink( $event_id ) ) ) . '">' . esc_html__( 'Sign in to add to my calendar', 'cmp' ) . '</a></div>';
			return;
		}
		if ( ! CMP_Access::is_member( $user_id ) ) {
			echo '<p class="cmp-cal-note">' . esc_html__( 'Finish setting up your member account to add events to your calendar.', 'cmp' ) . ' <a href="' . esc_url( CMP_Settings::member_page_url() ) . '">' . esc_html__( 'Member area', 'cmp' ) . '</a></p></div>';
			return;
		}
		$notice  = isset( $_GET['cmp_cal'] ) ? sanitize_key( wp_unslash( $_GET['cmp_cal'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notices = self::notices();
		if ( isset( $notices[ $notice ] ) ) {
			echo '<p class="cmp-cal-msg is-' . esc_attr( $notices[ $notice ][0] ) . '" role="status">' . esc_html( $notices[ $notice ][1] ) . '</p>';
		}
		$entry = self::get( $user_id, $event_id );
		$rsvp  = class_exists( 'CEC_RSVP' ) && method_exists( 'CEC_RSVP', 'accepts_member_rsvp' ) && CEC_RSVP::accepts_member_rsvp( $event_id );
		$form  = self::form_open( 'save', $event_id, 'event' )
			. self::choices_html( 'cmp_cal_' . $event_id, $entry ? $entry->response : 'going', $entry ? $entry->audience : self::default_audience( $user_id ) )
			. ( $rsvp ? '<p class="cmp-cal-note">' . esc_html__( 'Going also counts as your RSVP. Organizers see a headcount; your name is never shown publicly.', 'cmp' ) . '</p>' : '' )
			. '<button type="submit" class="cmp-cal-btn">' . esc_html( $entry ? __( 'Save changes', 'cmp' ) : __( 'Add to my calendar', 'cmp' ) ) . '</button></form>';
		if ( ! $entry ) {
			echo '<h3 class="cmp-cal-title">' . esc_html__( 'Add to my calendar', 'cmp' ) . '</h3>' . $form . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
			return;
		}
		$labels = self::audience_labels();
		$resp   = self::response_labels();
		echo '<p class="cmp-cal-on"><span><span aria-hidden="true">✓</span> ' . esc_html__( 'On your calendar', 'cmp' ) . '</span><small>' . esc_html( $resp[ $entry->response ] . ' · ' . sprintf( /* translators: %s: audience */ __( 'visible to %s', 'cmp' ), mb_strtolower( $labels[ $entry->audience ] ) ) ) . '</small></p>';
		echo '<details class="cmp-cal-change"><summary class="cmp-cal-btn cmp-cal-btn-outline">' . esc_html__( 'Change', 'cmp' ) . '</summary>' . $form . '</details>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		echo self::form_open( 'remove', $event_id, 'event', 'cmp-cal-form cmp-cal-remove' ) . '<button type="submit" class="cmp-cal-btn cmp-cal-btn-outline cmp-cal-btn-danger">' . esc_html__( 'Remove', 'cmp' ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		echo '<a class="cmp-cal-link" href="' . esc_url( self::url() ) . '">' . esc_html__( 'See my calendar', 'cmp' ) . '</a></div>';
	}

	/* ------------------------------------------------------------------
	 * Lists: My calendar tab and the profile's Calendar tab
	 * ---------------------------------------------------------------- */

	private static function when( $entry ) {
		$data = CEC_Event_Helper::data( (int) $entry->event_id );
		$ts   = strtotime( str_replace( 'T', ' ', $entry->start ) );
		$mode = isset( $data['display_time_mode'] ) ? $data['display_time_mode'] : 'exact';
		$time = in_array( $mode, array( 'exact', 'start_only' ), true ) && false !== strpos( $entry->start, 'T' ) ? ' · ' . gmdate( 'g:i A', $ts ) : '';
		return array( $ts, gmdate( 'D', $ts ) . $time );
	}

	/** One event row: date tile, title, plan, and (for the owner) audience and actions. */
	private static function row_html( $entry, $owner_view ) {
		list( $ts, $when ) = self::when( $entry );
		$resp              = self::response_labels();
		$title             = html_entity_decode( wp_strip_all_tags( get_the_title( (int) $entry->event_id ) ), ENT_QUOTES, 'UTF-8' );
		$html              = '<li class="cmp-cal-row"><a class="cmp-cal-row-main" href="' . esc_url( get_permalink( (int) $entry->event_id ) ) . '"><span class="cmp-fl-date"><b>' . esc_html( gmdate( 'j', $ts ) ) . '</b>' . esc_html( gmdate( 'M', $ts ) ) . '</span><span>' . esc_html( $title ) . '<small>' . esc_html( $resp[ $entry->response ] . ' · ' . $when ) . '</small></span></a>';
		if ( $owner_view ) {
			$id    = 'cmp_cal_aud_' . (int) $entry->event_id;
			$html .= '<div class="cmp-cal-row-acts">' . self::form_open( 'save', (int) $entry->event_id, 'tab', 'cmp-form cmp-cal-inline' )
				. '<input type="hidden" name="response" value="' . esc_attr( $entry->response ) . '" />'
				. '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html__( 'Who can see it', 'cmp' ) . '</label><select id="' . esc_attr( $id ) . '" name="audience" class="cmp-cal-aud is-' . esc_attr( $entry->audience ) . '" data-cmp-autosave>';
			foreach ( self::audience_labels() as $k => $l ) {
				$html .= '<option value="' . esc_attr( $k ) . '"' . selected( $entry->audience, $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			$html .= '</select><button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline" data-cmp-autosave-btn>' . esc_html__( 'Save', 'cmp' ) . '</button></form>'
				. self::form_open( 'remove', (int) $entry->event_id, 'tab', 'cmp-form cmp-cal-inline' ) . '<button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline cmp-btn-danger">' . esc_html__( 'Remove', 'cmp' ) . '</button></form></div>';
		}
		return $html . '</li>';
	}

	/** Upcoming entries grouped by month. */
	private static function list_html( $entries, $owner_view ) {
		$html  = '';
		$month = '';
		foreach ( $entries as $e ) {
			$m = gmdate( 'F Y', strtotime( str_replace( 'T', ' ', $e->start ) ) );
			if ( $m !== $month ) {
				$html .= ( $month ? '</ul>' : '' ) . '<h4 class="cmp-cal-month">' . esc_html( $m ) . '</h4><ul class="cmp-fl-events cmp-cal-list">';
				$month = $m;
			}
			$html .= self::row_html( $e, $owner_view );
		}
		return $html . ( $month ? '</ul>' : '' );
	}

	/** The community calendar page: the first page showing the calendar, else the events archive. */
	public static function events_url() {
		global $wpdb;
		$id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND ( post_content LIKE '%[cec_calendar%' OR post_content LIKE '%[cec_events%' OR post_content LIKE '%[cec_upcoming%' ) ORDER BY menu_order, ID LIMIT 1" );
		return (string) apply_filters( 'cmp_events_url', $id ? get_permalink( $id ) : get_post_type_archive_link( 'cec_event' ) );
	}

	/** The member area's My calendar tab. */
	public static function render( $user_id ) {
		wp_enqueue_script( 'cmp-member' );
		$upcoming = self::entries( $user_id, $user_id );
		$past     = self::entries( $user_id, $user_id, false, 10 );
		$labels   = self::audience_labels();
		$html     = '<section class="cmp-step" aria-labelledby="cmp-cal-title"><h2 id="cmp-cal-title" class="cmp-title">' . esc_html__( 'My calendar', 'cmp' ) . '</h2>'
			. '<p>' . esc_html__( 'Events you\'ve added with "Add to my calendar" on the community calendar. Each one shows on your profile only to who you choose: all members, your dynamic partners, or only you.', 'cmp' ) . '</p>'
			. '<p><a class="cmp-btn cmp-btn-small cmp-btn-outline" href="' . esc_url( self::events_url() ) . '">' . esc_html__( 'Browse events', 'cmp' ) . '</a></p></section>';
		$html    .= '<section class="cmp-panel cmp-cal-default"><h3 class="cmp-panel-title">' . esc_html__( 'Who sees new events by default', 'cmp' ) . '</h3>' . self::form_open( 'default', 0, 'tab', 'cmp-form cmp-cal-inline' ) . '<label class="screen-reader-text" for="cmp_cal_default">' . esc_html__( 'Default', 'cmp' ) . '</label><select id="cmp_cal_default" name="audience">';
		foreach ( $labels as $k => $l ) {
			$html .= '<option value="' . esc_attr( $k ) . '"' . selected( self::default_audience( $user_id ), $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		$html .= '</select><button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Save', 'cmp' ) . '</button></form><p class="cmp-muted">' . esc_html__( 'You can still choose for each event when you add it. Dynamic partners are members you\'re in an active dynamic with.', 'cmp' ) . '</p></section>';
		$html .= '<section class="cmp-panel"><h3 class="cmp-panel-title">' . esc_html__( 'Coming up', 'cmp' ) . '</h3>' . ( $upcoming ? self::list_html( $upcoming, true ) : '<p class="cmp-empty">' . esc_html__( 'Nothing yet. Open an event and choose "Add to my calendar".', 'cmp' ) . '</p>' ) . '</section>';
		if ( $past ) {
			$html .= '<section class="cmp-panel"><h3 class="cmp-panel-title">' . esc_html__( 'Recent', 'cmp' ) . '</h3>' . self::list_html( $past, false ) . '</section>';
		}
		return $html;
	}

	/**
	 * The profile's Calendar tab: upcoming events the viewer may see.
	 *
	 * @param bool $as_members The member's own "as members see it" view.
	 */
	public static function profile_html( $owner_id, $viewer_id, $as_members = false ) {
		$self    = (int) $owner_id === (int) $viewer_id;
		$entries = $self && $as_members
			? array_values( array_filter( self::entries( $owner_id, $owner_id, true, 20 ), function ( $e ) { return 'members' === $e->audience; } ) )
			: self::entries( $owner_id, $viewer_id, true, 20 );
		if ( ! $entries ) {
			return '';
		}
		$html = '<section class="cmp-panel cmp-fl-going cmp-cal-profile"><h3 class="cmp-panel-title">' . esc_html__( 'Calendar', 'cmp' ) . '</h3>';
		if ( $self && $as_members ) {
			$html .= '<p class="cmp-muted">' . esc_html__( 'Members see the events you show to all members. Your dynamic partners also see the ones you show to them.', 'cmp' ) . ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'My calendar', 'cmp' ) . '</a></p>';
		}
		return $html . self::list_html( $entries, false ) . '</section>';
	}

	/* ------------------------------------------------------------------
	 * Community calendar marker
	 * ---------------------------------------------------------------- */

	/** "2 people you follow are going", counting only entries the viewer may see. */
	public static function calendar_label( $label, $event_id ) {
		global $wpdb;
		$viewer = get_current_user_id();
		if ( '' !== $label || ! $viewer || ! CMP_Access::is_member( $viewer ) ) {
			return $label;
		}
		if ( ! isset( self::$followed[ $viewer ] ) ) {
			self::$followed[ $viewer ] = array_map( 'intval', CMP_Follows::following_ids( $viewer ) );
		}
		$ids = self::$followed[ $viewer ];
		if ( ! $ids ) {
			return $label;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, audience FROM ' . self::t() . " WHERE event_id = %d AND response = 'going' AND user_id IN (" . implode( ',', $ids ) . ')', $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$n    = 0;
		foreach ( $rows as $r ) {
			$n += self::can_see( $r->audience, (int) $r->user_id, $viewer ) ? 1 : 0;
		}
		/* translators: %d: people */
		return $n ? sprintf( _n( '%d person you follow is going', '%d people you follow are going', $n, 'cmp' ), $n ) : $label;
	}

	/* ------------------------------------------------------------------
	 * Upgrade, retention, privacy
	 * ---------------------------------------------------------------- */

	/**
	 * 0.17.0, once: RSVPs members made while signed in become Going on their
	 * calendar. Members who had "Show events I'm going to" on (anyone could
	 * follow them to see) start at All members; everyone else at Only me,
	 * so nothing becomes visible that wasn't before.
	 */
	public static function migrate() {
		global $wpdb;
		if ( get_option( self::MIGRATED ) || ! defined( 'CEC_TABLE_RSVP' ) ) {
			return;
		}
		$rsvp = $wpdb->prefix . CEC_TABLE_RSVP;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rsvp ) ) !== $rsvp ) {
			return;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $wpdb->get_results( "SELECT DISTINCT user_id, event_id FROM $rsvp WHERE user_id > 0" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( self::get( (int) $r->user_id, (int) $r->event_id ) ) {
				continue;
			}
			$shared = CMP_Follows::shares_going( (int) $r->user_id );
			$wpdb->insert( self::t(), array( 'user_id' => (int) $r->user_id, 'event_id' => (int) $r->event_id, 'response' => 'going', 'audience' => $shared ? 'members' : 'private', 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%d', '%s', '%s', '%s', '%s' ) );
		}
		foreach ( get_users( array( 'meta_key' => CMP_Follows::META_GOING, 'meta_value' => '1', 'fields' => 'ID' ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( ! get_user_meta( $id, self::META_DEFAULT, true ) ) {
				update_user_meta( $id, self::META_DEFAULT, 'members' );
			}
		}
		update_option( self::MIGRATED, $now, false );
	}

	/** Entries for events that ended more than $days ago (privacy statement: 12 months). */
	public static function purge_old( $days = 365 ) {
		global $wpdb;
		$cut = wp_date( 'Y-m-d', time() - (int) $days * DAY_IN_SECONDS );
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT c.event_id FROM ' . self::t() . " c LEFT JOIN {$wpdb->postmeta} s ON s.post_id = c.event_id AND s.meta_key = '_cec_start' LEFT JOIN {$wpdb->postmeta} e ON e.post_id = c.event_id AND e.meta_key = '_cec_end' WHERE s.meta_value IS NULL OR SUBSTR( COALESCE( NULLIF( e.meta_value, '' ), s.meta_value ), 1, 10 ) < %s", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $ids ) {
			return 0;
		}
		return (int) $wpdb->query( 'DELETE FROM ' . self::t() . ' WHERE event_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function export_rows( $user_id ) {
		global $wpdb;
		$rows   = array();
		$labels = self::audience_labels();
		$resp   = self::response_labels();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE user_id = %d ORDER BY event_id', $user_id ) ) as $e ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows[] = array(
				'name'  => __( 'On your calendar', 'cmp' ),
				'value' => sprintf( '%s · %s · %s', html_entity_decode( wp_strip_all_tags( get_the_title( (int) $e->event_id ) ), ENT_QUOTES, 'UTF-8' ), $resp[ $e->response ] ?? $e->response, $labels[ $e->audience ] ?? $e->audience ),
			);
		}
		$rows[] = array( 'name' => __( 'Who sees new calendar events by default', 'cmp' ), 'value' => $labels[ self::default_audience( $user_id ) ] );
		return $rows;
	}

	public static function erase( $user_id ) {
		global $wpdb;
		delete_user_meta( $user_id, self::META_DEFAULT );
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t() . ' WHERE user_id = %d', $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
