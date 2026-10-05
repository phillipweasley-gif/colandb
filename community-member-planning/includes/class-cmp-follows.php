<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Follow / unfollow (0.12.0): keep up with a member without being
 * connected. Owner's choices (2026-10-05): anyone can follow, no approval;
 * a member's event plans are shown to followers only if that member turns
 * on "Show events I'm going to" (off by default); follows show up as a
 * Following filter on the feed, a "Going to" card on profiles, a marker on
 * the community calendar, and notifications.
 *
 * - Blocks (CMP_Messages) remove follows both ways and stop new ones.
 * - "Going to" is read from Community Events Calendar RSVPs made while
 *   signed in, upcoming published events only.
 */
class CMP_Follows {

	const NONCE      = 'cmp_follows';
	const META_GOING = 'cmp_show_going';

	/** Per-request cache: viewer => ids of followed members who share their events. */
	private static $sharing = array();

	public static function init() {
		add_action( 'admin_post_cmp_follow', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_cmp_follow', array( 'CMP_Member_Area', 'redirect_to_login' ) );
		add_filter( 'cec_event_social_label', array( __CLASS__, 'calendar_label' ), 10, 2 );
		add_action( 'cec_rsvp_created', array( __CLASS__, 'on_rsvp' ), 10, 2 );
	}

	public static function notices() {
		return array(
			'fl_followed'   => array( 'success', __( 'Following. Their posts are in Feed → Following.', 'cmp' ) ),
			'fl_unfollowed' => array( 'success', __( 'Unfollowed.', 'cmp' ) ),
			'fl_saved'      => array( 'success', __( 'Saved.', 'cmp' ) ),
			'fl_gone'       => array( 'error', __( 'That member isn\'t available.', 'cmp' ) ),
		);
	}

	/* ------------------------------------------------------------------
	 * Data
	 * ---------------------------------------------------------------- */

	private static function t() {
		return CMP_Install::table( 'follows' );
	}

	public static function is_following( $follower, $followed ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::t() . ' WHERE follower_id = %d AND followed_id = %d', $follower, $followed ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function following_ids( $user_id ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT followed_id FROM ' . self::t() . ' WHERE follower_id = %d', $user_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function follower_ids( $user_id ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT follower_id FROM ' . self::t() . ' WHERE followed_id = %d', $user_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function counts( $user_id ) {
		global $wpdb;
		return array(
			'followers' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t() . ' WHERE followed_id = %d', $user_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'following' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t() . ' WHERE follower_id = %d', $user_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	public static function shares_going( $user_id ) {
		return (bool) get_user_meta( $user_id, self::META_GOING, true );
	}

	/** Called when a block is made: no follows either way. */
	public static function remove_pair( $a, $b ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t() . ' WHERE ( follower_id = %d AND followed_id = %d ) OR ( follower_id = %d AND followed_id = %d )', $a, $b, $b, $a ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function rsvp_table() {
		global $wpdb;
		return defined( 'CEC_TABLE_RSVP' ) && post_type_exists( 'cec_event' ) ? $wpdb->prefix . CEC_TABLE_RSVP : '';
	}

	/**
	 * Upcoming published events a member RSVP'd to (signed in), soonest first.
	 *
	 * @return array of array( id, title, url, start )
	 */
	public static function going_events( $user_id, $limit = 5 ) {
		global $wpdb;
		$t = self::rsvp_table();
		if ( ! $t ) {
			return array();
		}
		$today = wp_date( 'Y-m-d' );
		// _cec_start is the event's own local "Y-m-d\TH:i"; comparing the date part is enough here.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT p.ID, p.post_title, m.meta_value AS start FROM $t r JOIN {$wpdb->posts} p ON p.ID = r.event_id AND p.post_type = 'cec_event' AND p.post_status = 'publish' JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_cec_start' WHERE r.user_id = %d AND m.meta_value >= %s ORDER BY m.meta_value ASC LIMIT %d", $user_id, $today, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array( 'id' => (int) $r->ID, 'title' => html_entity_decode( wp_strip_all_tags( get_the_title( $r->ID ) ), ENT_QUOTES, 'UTF-8' ), 'url' => get_permalink( $r->ID ), 'start' => (string) $r->start );
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ---------------------------------------------------------------- */

	public static function handle() {
		global $wpdb;
		$user_id = get_current_user_id();
		if ( ! CMP_Access::is_member( $user_id ) ) {
			wp_safe_redirect( CMP_Settings::member_page_url() );
			exit;
		}
		if ( ! isset( $_POST['_cmp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cmp_nonce'] ) ), self::NONCE ) ) {
			wp_safe_redirect( add_query_arg( 'cmp_notice', 'expired', CMP_Settings::member_page_url() ) );
			exit;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$member = isset( $_POST['member'] ) ? absint( $_POST['member'] ) : 0;
		// phpcs:enable
		if ( 'going' === $do ) {
			update_user_meta( $user_id, self::META_GOING, empty( $_POST['show_going'] ) ? 0 : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			CMP_Audit::log( 'show_going_changed', 'user', $user_id, null, array( 'show' => ! empty( $_POST['show_going'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_safe_redirect( add_query_arg( 'cmp_notice', 'fl_saved', CMP_Profiles::url() . '#cmp-going' ) );
			exit;
		}
		$back = CMP_Profiles::member_url( $member );
		if ( ! $member || $member === $user_id || ! CMP_Access::is_member( $member ) || CMP_Messages::is_blocked( $user_id, $member ) ) {
			wp_safe_redirect( add_query_arg( 'cmp_notice', 'fl_gone', CMP_Settings::member_page_url() ) );
			exit;
		}
		if ( 'follow' === $do && ! self::is_following( $user_id, $member ) ) {
			$wpdb->insert( self::t(), array( 'follower_id' => $user_id, 'followed_id' => $member, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
			wp_safe_redirect( add_query_arg( 'cmp_notice', 'fl_followed', $back ) );
			exit;
		}
		if ( 'unfollow' === $do ) {
			$wpdb->delete( self::t(), array( 'follower_id' => $user_id, 'followed_id' => $member ), array( '%d', '%d' ) );
			wp_safe_redirect( add_query_arg( 'cmp_notice', 'fl_unfollowed', $back ) );
			exit;
		}
		wp_safe_redirect( $back );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Notifications
	 * ---------------------------------------------------------------- */

	/** A member who shares their events RSVP'd: tell their followers. */
	public static function on_rsvp( $event_id, $user_id ) {
		if ( ! $user_id || ! CMP_Access::is_member( $user_id ) || ! self::shares_going( $user_id ) || 'publish' !== get_post_status( $event_id ) ) {
			return;
		}
		$user  = get_userdata( $user_id );
		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $event_id ) ), ENT_QUOTES, 'UTF-8' );
		foreach ( self::follower_ids( $user_id ) as $f ) {
			if ( CMP_Access::is_member( $f ) && ! CMP_Messages::is_blocked( $f, $user_id ) ) {
				/* translators: 1: member, 2: event */
				CMP_Notifications::add( $f, 'follow', sprintf( __( '%1$s is going to %2$s.', 'cmp' ), $user->display_name, $title ), get_permalink( $event_id ) );
			}
		}
	}

	/** A new feed post: tell followers who can see it. */
	public static function on_post( $post ) {
		$user = get_userdata( $post->author_id );
		foreach ( self::follower_ids( $post->author_id ) as $f ) {
			if ( CMP_Feed::can_see( $post, $f ) ) {
				/* translators: %s: member */
				CMP_Notifications::add( $f, 'follow', sprintf( __( '%s shared a new post.', 'cmp' ), $user ? $user->display_name : '' ), CMP_Feed::url( array( 'cmp_post' => (int) $post->id ) ) );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Calendar
	 * ---------------------------------------------------------------- */

	/** "2 people you follow are going" on the community calendar. */
	public static function calendar_label( $label, $event_id ) {
		global $wpdb;
		$viewer = get_current_user_id();
		$t      = self::rsvp_table();
		if ( '' !== $label || ! $viewer || ! $t || ! CMP_Access::is_member( $viewer ) ) {
			return $label;
		}
		if ( ! isset( self::$sharing[ $viewer ] ) ) {
			$blocked                  = CMP_Messages::blocked_ids( $viewer );
			self::$sharing[ $viewer ] = array_values(
				array_filter(
					self::following_ids( $viewer ),
					function ( $id ) use ( $blocked ) {
						return ! in_array( $id, $blocked, true ) && self::shares_going( $id ) && CMP_Access::is_member( $id );
					}
				)
			);
		}
		$ids = self::$sharing[ $viewer ];
		if ( ! $ids ) {
			return $label;
		}
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM $t WHERE event_id = %d AND user_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')', $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		/* translators: %d: people */
		return $n ? sprintf( _n( '%d person you follow is going', '%d people you follow are going', $n, 'cmp' ), $n ) : $label;
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	private static function form_open( $do, $member = 0, $class = 'cmp-form cmp-inline-form' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '"><input type="hidden" name="action" value="cmp_follow" /><input type="hidden" name="do" value="' . esc_attr( $do ) . '" />' . ( $member ? '<input type="hidden" name="member" value="' . (int) $member . '" />' : '' ) . '<input type="hidden" name="_cmp_nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" />';
	}

	/** Follow / Following button for a profile. */
	public static function button_html( $viewer_id, $owner_id ) {
		$on = self::is_following( $viewer_id, $owner_id );
		return self::form_open( $on ? 'unfollow' : 'follow', $owner_id ) . '<button type="submit" class="cmp-btn' . ( $on ? ' cmp-btn-outline' : '' ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '">' . esc_html( $on ? __( 'Following', 'cmp' ) : __( 'Follow', 'cmp' ) ) . '</button></form>';
	}

	public static function counts_html( $owner_id ) {
		$c = self::counts( $owner_id );
		/* translators: 1: followers, 2: following */
		return '<p class="cmp-fl-counts">' . esc_html( sprintf( _n( '%1$d follower', '%1$d followers', $c['followers'], 'cmp' ), $c['followers'] ) . ' · ' . sprintf( /* translators: %d: following */ __( '%d following', 'cmp' ), $c['following'] ) ) . '</p>';
	}

	/**
	 * "Going to" on a profile: to the member themselves, and to followers
	 * if the member shares their events.
	 *
	 * @param bool $as_members The member's own "as members see it" view.
	 */
	public static function going_html( $owner_id, $viewer_id, $as_members = false ) {
		$self = (int) $owner_id === (int) $viewer_id;
		if ( ! self::shares_going( $owner_id ) ) {
			return '';
		}
		if ( ! $self && ! self::is_following( $viewer_id, $owner_id ) ) {
			$name = get_userdata( $owner_id );
			/* translators: %s: member */
			return '<section class="cmp-panel cmp-fl-going"><h3 class="cmp-panel-title">' . esc_html__( 'Going to', 'cmp' ) . '</h3><p class="cmp-muted">' . esc_html( sprintf( __( 'Follow %s to see the events they\'re going to.', 'cmp' ), $name ? $name->display_name : '' ) ) . '</p></section>';
		}
		$events = self::going_events( $owner_id );
		$html   = '<section class="cmp-panel cmp-fl-going"><h3 class="cmp-panel-title">' . esc_html__( 'Going to', 'cmp' ) . '</h3>';
		if ( $self && $as_members ) {
			$html .= '<p class="cmp-muted">' . esc_html__( 'Only your followers see this.', 'cmp' ) . '</p>';
		}
		if ( ! $events ) {
			return $html . '<p class="cmp-empty">' . esc_html__( 'No upcoming events.', 'cmp' ) . '</p></section>';
		}
		$html .= '<ul class="cmp-fl-events">';
		foreach ( $events as $e ) {
			$ts = strtotime( str_replace( 'T', ' ', $e['start'] ) );
			// The event's own time setting decides whether a time shows (all day, varies …).
			$mode  = class_exists( 'CEC_Event_Helper' ) ? CEC_Event_Helper::data( $e['id'] )['display_time_mode'] : 'exact';
			$time  = in_array( $mode, array( 'exact', 'start_only' ), true ) && false !== strpos( $e['start'], 'T' ) ? ' · ' . gmdate( 'g:i A', $ts ) : '';
			$html .= '<li><a href="' . esc_url( $e['url'] ) . '"><span class="cmp-fl-date"><b>' . esc_html( gmdate( 'j', $ts ) ) . '</b>' . esc_html( gmdate( 'M', $ts ) ) . '</span><span>' . esc_html( $e['title'] ) . '<small>' . esc_html( gmdate( 'D', $ts ) . $time ) . '</small></span></a></li>';
		}
		return $html . '</ul></section>';
	}

	/** The opt-in switch, on the Profile tab. */
	public static function settings_html( $user_id ) {
		$on   = self::shares_going( $user_id );
		$html = '<section class="cmp-panel" id="cmp-going"><h3 class="cmp-panel-title">' . esc_html__( 'Events I\'m going to', 'cmp' ) . '</h3>';
		$html .= '<p>' . esc_html__( 'Off unless you turn it on. When it\'s on, members who follow you see the upcoming events you\'ve RSVP\'d to (signed in), get a notification when you RSVP, and see a marker on the calendar.', 'cmp' ) . '</p>';
		$html .= self::form_open( 'going', 0, 'cmp-form' ) . '<p class="cmp-check"><input type="checkbox" id="cmp_show_going" name="show_going" value="1"' . checked( $on, true, false ) . ' /><label for="cmp_show_going">' . esc_html__( 'Show events I\'m going to, to my followers', 'cmp' ) . '</label></p><button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Save', 'cmp' ) . '</button></form>';
		return $html . self::counts_html( $user_id ) . '</section>';
	}

	/* ------------------------------------------------------------------
	 * Privacy
	 * ---------------------------------------------------------------- */

	public static function export_rows( $user_id ) {
		$rows = array();
		foreach ( self::following_ids( $user_id ) as $id ) {
			$u      = get_userdata( $id );
			$rows[] = array( 'name' => __( 'Member you follow', 'cmp' ), 'value' => $u ? $u->display_name : (string) $id );
		}
		$rows[] = array( 'name' => __( 'Show events you\'re going to, to followers', 'cmp' ), 'value' => self::shares_going( $user_id ) ? __( 'On', 'cmp' ) : __( 'Off', 'cmp' ) );
		return $rows;
	}

	public static function erase( $user_id ) {
		global $wpdb;
		delete_user_meta( $user_id, self::META_GOING );
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t() . ' WHERE follower_id = %d OR followed_id = %d', $user_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
