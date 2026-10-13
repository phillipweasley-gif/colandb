<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chastity tracking (0.8.0), modeled on Chaster's lock timer, keyholder
 * controls, verification pictures and hygiene openings, and on the chastity
 * options in the owner's example homework tracker.
 *
 * - The wearer starts a lock: on their own (self-lock) or with a keyholder
 *   who leads them in an active directed dynamic (CMP_Dynamics). One active
 *   lock per wearer.
 * - The keyholder adds or removes time, hides the time left, sets the rules
 *   (option, release policy, daily verification, hygiene allowance), allows
 *   a release, reviews verification photos and unlocks.
 * - The wearer can always end the lock with "Emergency unlock". This tracks a
 *   lock; it can't stop anyone taking a device off.
 * - Verification: a code for each lock and day (keyed with the site salt) to
 *   write on paper in the photo. Photos are re-encoded (CMP_Homework) and
 *   served only to the wearer and their current keyholder.
 * - When the dynamic ends, the keyholder loses access at once and the lock
 *   carries on as a self-lock.
 * - 0.24.0: the keyholder can set up a lock for the wearer (a row with
 *   status 'proposed'). Nothing is locked until the wearer accepts; the timer
 *   starts then. The wearer can decline, the keyholder can withdraw, and a
 *   proposal disappears when the keyholder can no longer direct the wearer.
 * - 0.25.0: profile badges name both sides. The wearer's badge (their
 *   choice, show_profile) reads "Locked by <keyholder> · N days"; the
 *   keyholder's profile shows "Holds the key for <wearer> · N days" (0.29.0:
 *   both add "· last verified <day> · photo|elsewhere (<where>)"). The
 *   keyholder can leave their name off (kh_show), and then neither profile
 *   names them. A full POST over post_max_size (PHP drops every field) now
 *   comes back with a "too large" notice instead of a blank page.
 * - 0.26.0: the site team can correct a lock's start, planned end and end
 *   (Users → Member Dynamics & Locks, CMP_Member_Admin), record a lock that
 *   never got started, end one, or remove a stuck or duplicate one. Each
 *   correction is an 'admin_fix' history entry both members see (planned-end
 *   changes stay hidden from a wearer whose timer is hidden), a notification
 *   to both, and an audit-log row with the old and new values.
 * - 0.27.0: the site team can also change a lock's settings (badge,
 *   keyholder's name on badges, hidden timer, daily verification, hygiene,
 *   option and release rule) and its keyholder, for members who can't.
 */
class CMP_Chastity {

	const TAB         = 'chastity';
	const NONCE       = 'cmp_chastity';
	const PHOTO_QUERY = 'cmp_lockpic';
	const CODE_CHARS  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	public static function init() {
		add_action( 'admin_post_cmp_chastity', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_cmp_chastity', array( 'CMP_Member_Area', 'redirect_to_login' ) );
		add_action( 'cmp_dynamic_ended', array( __CLASS__, 'on_dynamic_ended' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_photo' ), 1 );
		add_action( 'admin_post', array( __CLASS__, 'too_large' ) );
	}

	/**
	 * When a request is bigger than post_max_size, PHP drops every field,
	 * "action" included, so admin-post.php fires the bare admin_post hook and
	 * shows a blank page. Send a member back with a notice instead (0.25.0).
	 */
	public static function too_large() {
		$len = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		$max = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || ! $max || $len <= $max ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		$back = wp_get_referer();
		$home = CMP_Settings::member_page_url();
		$back = $back && 0 === strpos( remove_query_arg( 'cmp_notice', $back ), strtok( $home, '?' ) ) ? $back : self::url();
		wp_safe_redirect( add_query_arg( 'cmp_notice', 'upload_too_large', remove_query_arg( 'cmp_notice', $back ) ) );
		exit;
	}

	/** The tracker's chastity options, plus Custom. */
	public static function options() {
		return array(
			'a'      => array( __( 'Full lockdown', 'cmp' ), __( 'Wear it 24/7.', 'cmp' ), 'none' ),
			'b'      => array( __( 'One release a week', 'cmp' ), __( 'Wear it 24/7. One release a week, only with permission.', 'cmp' ), 'permission' ),
			'c'      => array( __( 'Daily routine, no release', 'cmp' ), __( 'On when you wake up, off at bedtime. No release.', 'cmp' ), 'none' ),
			'custom' => array( __( 'Custom', 'cmp' ), '', 'none' ),
		);
	}

	public static function policies() {
		return array(
			'none'       => __( 'No release', 'cmp' ),
			'permission' => __( 'Only with permission', 'cmp' ),
			'free'       => __( 'Release allowed', 'cmp' ),
		);
	}

	/** Lock lengths offered when starting, and for adding / removing time, in minutes. */
	public static function lengths() {
		return array(
			60    => __( '1 hour', 'cmp' ),
			720   => __( '12 hours', 'cmp' ),
			1440  => __( '1 day', 'cmp' ),
			4320  => __( '3 days', 'cmp' ),
			10080 => __( '1 week', 'cmp' ),
			20160 => __( '2 weeks', 'cmp' ),
			43200 => __( '30 days', 'cmp' ),
		);
	}

	public static function hygiene_choices() {
		return array( 0 => __( 'Not allowed', 'cmp' ), 10 => __( '10 minutes', 'cmp' ), 15 => __( '15 minutes', 'cmp' ), 30 => __( '30 minutes', 'cmp' ), 60 => __( '1 hour', 'cmp' ) );
	}

	public static function url( $args = array(), $notice = '' ) {
		$url = add_query_arg( array_merge( array( 'cmp_tab' => self::TAB ), $args ), CMP_Settings::member_page_url() );
		return $notice ? add_query_arg( 'cmp_notice', $notice, $url ) : $url;
	}

	public static function notices() {
		return array(
			'cl_started'  => array( 'success', __( 'Locked. Your timer is running.', 'cmp' ) ),
			'cl_saved'    => array( 'success', __( 'Saved.', 'cmp' ) ),
			'cl_logged'   => array( 'success', __( 'Recorded.', 'cmp' ) ),
			'cl_ended'    => array( 'success', __( 'The lock has ended.', 'cmp' ) ),
			'cl_invalid'  => array( 'error', __( 'That couldn\'t be saved. Check your answers and try again.', 'cmp' ) ),
			'cl_busy'     => array( 'error', __( 'You already have an active lock.', 'cmp' ) ),
			'cl_photo'    => array( 'error', __( 'That photo couldn\'t be used. Use a JPEG, PNG or WebP photo under 5 MB.', 'cmp' ) ),
			'cl_gone'     => array( 'error', __( 'That lock isn\'t available.', 'cmp' ) ),
			'cl_kh_name'  => array( 'error', __( 'Type your keyholder\'s name, or choose someone else.', 'cmp' ) ),
			'cl_proposed' => array( 'success', __( 'Sent. The lock starts when they accept it.', 'cmp' ) ),
			'cl_accepted' => array( 'success', __( 'Locked. Your timer is running.', 'cmp' ) ),
			'cl_declined' => array( 'success', __( 'Declined. Your keyholder has been told.', 'cmp' ) ),
			'cl_withdrawn'=> array( 'success', __( 'Withdrawn.', 'cmp' ) ),
			'cl_pending'  => array( 'error', __( 'They already have a lock, or a lock from you waiting for an answer.', 'cmp' ) ),
			'cl_verified_day' => array( 'error', __( 'That day already counts as verified, or has a photo waiting for you to review.', 'cmp' ) ),
			'upload_too_large' => array( 'error', __( 'That was too large to send, so nothing was saved. Try a smaller photo, or send it without one.', 'cmp' ) ),
		);
	}

	/* ------------------------------------------------------------------
	 * Data
	 * ---------------------------------------------------------------- */

	private static function t( $name ) {
		return CMP_Install::table( $name );
	}

	public static function lock( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'locks' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function active_lock( $wearer_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'locks' ) . " WHERE wearer_id = %d AND status = 'locked' ORDER BY id DESC LIMIT 1", $wearer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function locks_held( $keyholder_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'locks' ) . " WHERE keyholder_id = %d AND status = 'locked' ORDER BY started_at", $keyholder_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** A lock the keyholder set up that's waiting for the wearer (0.24.0). */
	public static function proposal_for( $wearer_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'locks' ) . " WHERE wearer_id = %d AND status = 'proposed' ORDER BY id DESC LIMIT 1", $wearer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function proposals_sent( $keyholder_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'locks' ) . " WHERE keyholder_id = %d AND status = 'proposed' ORDER BY id", $keyholder_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** The planned length (minutes, 0 = open-ended) of a proposal, kept on its 'proposed' event. */
	public static function proposed_minutes( $lock ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT minutes FROM ' . self::t( 'lock_events' ) . " WHERE lock_id = %d AND type = 'proposed' ORDER BY id DESC LIMIT 1", $lock->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function delete_lock( $lock_id ) {
		global $wpdb;
		$wpdb->delete( self::t( 'lock_events' ), array( 'lock_id' => $lock_id ), array( '%d' ) );
		$wpdb->delete( self::t( 'locks' ), array( 'id' => $lock_id ), array( '%d' ) );
	}

	public static function past_locks( $wearer_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'locks' ) . " WHERE wearer_id = %d AND status = 'ended' ORDER BY id DESC LIMIT 10", $wearer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function events( $lock_id, $limit = 30 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, lock_id, actor_id, type, minutes, note, photo_sha, code, review, created_at FROM ' . self::t( 'lock_events' ) . ' WHERE lock_id = %d ORDER BY id DESC LIMIT %d', $lock_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function event( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'lock_events' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function add_event( $lock_id, $actor_id, $type, $extra = array() ) {
		global $wpdb;
		$wpdb->insert( self::t( 'lock_events' ), array_merge( array( 'lock_id' => $lock_id, 'actor_id' => $actor_id, 'type' => $type, 'minutes' => 0, 'note' => '', 'photo_sha' => '', 'code' => '', 'review' => '', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ), $extra ) );
		return (int) $wpdb->insert_id;
	}

	private static function update_lock( $lock_id, $fields ) {
		global $wpdb;
		$wpdb->update( self::t( 'locks' ), $fields + array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $lock_id ) );
	}

	/* ------------------------------------------------------------------
	 * Rules
	 * ---------------------------------------------------------------- */

	/** The keyholder, while the lock is active and the dynamic still allows it. */
	public static function is_keyholder( $lock, $user_id ) {
		return $lock && 'locked' === $lock->status && $lock->keyholder_id && (int) $lock->keyholder_id === (int) $user_id && CMP_Dynamics::lead_can_direct( $user_id, $lock->wearer_id, 'chastity' );
	}

	public static function is_wearer( $lock, $user_id ) {
		return $lock && (int) $lock->wearer_id === (int) $user_id;
	}

	/** Who the wearer may choose as keyholder: members holding their key through a chastity add-on (0.16.0). */
	public static function keyholder_choices( $wearer_id ) {
		$out = array();
		foreach ( CMP_Dynamics::addons_for_user( $wearer_id, array( 'active' ) ) as $ad ) {
			$lead = (int) $ad->lead_id;
			if ( 'chastity' === $ad->kind && $lead !== (int) $wearer_id && CMP_Dynamics::lead_can_direct( $lead, $wearer_id, 'chastity' ) ) {
				$u = get_userdata( $lead );
				if ( $u ) {
					$out[ $lead ] = $u->display_name;
				}
			}
		}
		return $out;
	}

	/** Members whose key this member holds (chastity add-on, them leading) (0.24.0). */
	public static function wearer_choices( $keyholder_id ) {
		$out = array();
		foreach ( CMP_Dynamics::addons_for_user( $keyholder_id, array( 'active' ) ) as $ad ) {
			$wearer = CMP_Dynamics::addon_other( $ad, $keyholder_id );
			if ( 'chastity' === $ad->kind && (int) $ad->lead_id === (int) $keyholder_id && CMP_Dynamics::lead_can_direct( $keyholder_id, $wearer, 'chastity' ) ) {
				$u = get_userdata( $wearer );
				if ( $u ) {
					$out[ $wearer ] = $u->display_name;
				}
			}
		}
		return $out;
	}

	private static function ts( $datetime ) {
		return $datetime ? strtotime( $datetime . ' UTC' ) : 0;
	}

	public static function locked_seconds( $lock ) {
		$end = 'locked' === $lock->status ? time() : self::ts( $lock->ended_at );
		return max( 0, $end - self::ts( $lock->started_at ) );
	}

	/** Seconds left, or null when there's no planned end. */
	public static function seconds_left( $lock ) {
		return $lock->planned_end ? max( 0, self::ts( $lock->planned_end ) - time() ) : null;
	}

	public static function duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		$d       = intdiv( $seconds, DAY_IN_SECONDS );
		$h       = intdiv( $seconds % DAY_IN_SECONDS, HOUR_IN_SECONDS );
		$m       = intdiv( $seconds % HOUR_IN_SECONDS, MINUTE_IN_SECONDS );
		/* translators: 1: days, 2: hours, 3: minutes */
		return $d ? sprintf( __( '%1$dd %2$dh %3$dm', 'cmp' ), $d, $h, $m ) : sprintf( /* translators: 1: hours, 2: minutes */ __( '%1$dh %2$dm', 'cmp' ), $h, $m );
	}

	/** Today's verification code for a lock (site date). Six characters, no look-alikes. */
	public static function code( $lock_id, $day = '' ) {
		$day   = $day ? $day : wp_date( 'Y-m-d' );
		$bytes = hash_hmac( 'sha256', 'cmp-lock|' . (int) $lock_id . '|' . $day, wp_salt( 'auth' ), true );
		$out   = '';
		for ( $i = 0; $i < 6; $i++ ) {
			$out .= self::CODE_CHARS[ ord( $bytes[ $i ] ) % strlen( self::CODE_CHARS ) ];
		}
		return $out;
	}

	/** The verification logged today (site date), if any. */
	public static function today_verification( $lock ) {
		global $wpdb;
		$tz    = wp_timezone();
		$start = ( new DateTime( 'today', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id, type, photo_sha, code, review, created_at FROM ' . self::t( 'lock_events' ) . " WHERE lock_id = %d AND type IN ('verification','verified_else') AND created_at >= %s ORDER BY id DESC LIMIT 1", $lock->id, $start ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 0.28.0: the days a keyholder may mark "verified elsewhere" (the wearer
	 * sent their verification outside COL&B): today and the 6 days before,
	 * never before the lock started. Site dates, newest first.
	 */
	public static function elsewhere_days( $lock ) {
		$tz    = wp_timezone();
		$start = wp_date( 'Y-m-d', self::ts( $lock->started_at ) );
		$out   = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$d   = ( new DateTime( 'today', $tz ) )->modify( '-' . $i . ' day' );
			$ymd = $d->format( 'Y-m-d' );
			if ( $ymd < $start ) {
				break;
			}
			$out[ $ymd ] = 0 === $i ? __( 'Today', 'cmp' ) : ( 1 === $i ? __( 'Yesterday', 'cmp' ) : wp_date( 'D M j', $d->getTimestamp() ) );
		}
		return $out;
	}

	/** 0.28.0: whether a site date already counts: a "verified elsewhere" mark, or a photo not sent back for another. */
	public static function day_verified( $lock, $ymd ) {
		global $wpdb;
		$utc  = new DateTimeZone( 'UTC' );
		$from = new DateTime( $ymd . ' 00:00:00', wp_timezone() );
		$to   = ( clone $from )->modify( '+1 day' );
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'lock_events' ) . " WHERE lock_id = %d AND created_at >= %s AND created_at < %s AND ( type = 'verified_else' OR ( type = 'verification' AND review <> 'again' ) )", $lock->id, $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $to->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ------------------------------------------------------------------
	 * Photos
	 * ---------------------------------------------------------------- */

	public static function photo_url( $event ) {
		return add_query_arg( array( self::PHOTO_QUERY => (int) $event->id, 'v' => substr( (string) $event->photo_sha, 0, 12 ) ), home_url( '/' ) );
	}

	/** ?cmp_lockpic=<event id>: only the wearer and their current keyholder; everyone else gets 404. */
	public static function maybe_serve_photo() {
		if ( ! isset( $_GET[ self::PHOTO_QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Vary: Cookie' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$event  = self::event( absint( $_GET[ self::PHOTO_QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lock   = $event ? self::lock( $event->lock_id ) : null;
		$viewer = get_current_user_id();
		if ( ! $event || ! $event->photo || ! $lock || ! $viewer || ! ( self::is_wearer( $lock, $viewer ) || self::is_keyholder( $lock, $viewer ) ) ) {
			nocache_headers();
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Not found';
			exit;
		}
		header( 'Cache-Control: private, no-cache, max-age=0' );
		header( 'ETag: "' . $event->photo_sha . '"' );
		status_header( 200 );
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . strlen( $event->photo ) );
		header( 'Content-Disposition: inline; filename="lock.jpg"' );
		header( "Content-Security-Policy: default-src 'none'" );
		echo $event->photo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JPEG bytes.
		exit;
	}

	/** An uploaded photo field: null when none was sent, JPEG bytes, or a redirect on error. */
	private static function posted_photo( $back ) {
		$file = isset( $_FILES['photo'] ) && is_array( $_FILES['photo'] ) ? $_FILES['photo'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing
		if ( ! $file || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			return null;
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::go( $back, 'cl_photo' );
		}
		$photo = CMP_Homework::process_photo( $file['tmp_name'] );
		if ( is_wp_error( $photo ) ) {
			self::go( $back, 'cl_photo' );
		}
		return $photo;
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ---------------------------------------------------------------- */

	private static function go( $args, $notice ) {
		wp_safe_redirect( self::url( $args, $notice ) );
		exit;
	}

	private static function text( $key, $max, $multiline = false ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle() checked the nonce.
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		$v   = trim( $multiline ? sanitize_textarea_field( (string) $raw ) : sanitize_text_field( (string) $raw ) );
		return mb_substr( $v, 0, $max );
	}

	private static function posted_key( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	private static function posted_int( $key ) {
		return isset( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	private static function me() {
		return wp_get_current_user()->display_name;
	}

	/** One entry point; "do" picks the action. */
	public static function handle() {
		$user_id = get_current_user_id();
		if ( ! CMP_Access::is_member( $user_id ) ) {
			wp_safe_redirect( CMP_Settings::member_page_url() );
			exit;
		}
		if ( ! isset( $_POST['_cmp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cmp_nonce'] ) ), self::NONCE ) ) {
			wp_safe_redirect( self::url( array(), 'expired' ) );
			exit;
		}
		$do = self::posted_key( 'do' );
		if ( 'start' === $do ) {
			self::start( $user_id );
		}
		if ( 'propose' === $do ) {
			self::propose( $user_id );
		}
		$lock = self::lock( self::posted_int( 'lock' ) );
		if ( $lock && 'proposed' === $lock->status ) {
			if ( in_array( $do, array( 'accept', 'decline' ), true ) && self::is_wearer( $lock, $user_id ) ) {
				call_user_func( array( __CLASS__, 'do_' . $do ), $lock, $user_id );
			}
			if ( 'withdraw' === $do && (int) $lock->keyholder_id === (int) $user_id ) {
				self::do_withdraw( $lock, $user_id );
			}
			self::go( array(), 'cl_gone' );
		}
		if ( ! $lock || 'locked' !== $lock->status ) {
			self::go( array(), 'cl_gone' );
		}
		$wearer_actions    = array( 'verify', 'hygiene_open', 'hygiene_close', 'release', 'ask_unlock', 'unlock', 'emergency', 'wearer_settings' );
		$keyholder_actions = array( 'time', 'rules', 'allow_release', 'review', 'verified_else', 'kh_unlock' );
		if ( in_array( $do, $wearer_actions, true ) && self::is_wearer( $lock, $user_id ) ) {
			call_user_func( array( __CLASS__, 'do_' . $do ), $lock, $user_id );
		}
		if ( in_array( $do, $keyholder_actions, true ) && self::is_keyholder( $lock, $user_id ) ) {
			call_user_func( array( __CLASS__, 'do_' . $do ), $lock, $user_id );
		}
		self::go( array(), 'cl_gone' );
	}

	private static function start( $user_id ) {
		global $wpdb;
		if ( self::active_lock( $user_id ) ) {
			self::go( array(), 'cl_busy' );
		}
		$named     = 'named' === self::posted_key( 'keyholder' );
		$kh_name   = $named ? self::text( 'keyholder_name', 60 ) : '';
		$keyholder = $named ? 0 : self::posted_int( 'keyholder' );
		if ( $named && '' === $kh_name ) {
			self::go( array(), 'cl_kh_name' );
		}
		$option    = self::posted_key( 'option' );
		$options   = self::options();
		$length    = self::posted_int( 'length' );
		if ( ( $keyholder && ! isset( self::keyholder_choices( $user_id )[ $keyholder ] ) ) || ! isset( $options[ $option ] ) || ( $length && ! isset( self::lengths()[ $length ] ) ) ) {
			self::go( array(), 'cl_invalid' );
		}
		list( $name, $rule, $policy ) = $options[ $option ];
		if ( 'custom' === $option ) {
			$name   = self::text( 'option_name', 60 );
			$rule   = self::text( 'rule', 300 );
			$policy = self::posted_key( 'policy' );
			if ( '' === $name || ! isset( self::policies()[ $policy ] ) ) {
				self::go( array(), 'cl_invalid' );
			}
		}
		$photo = self::posted_photo( array() );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			self::t( 'locks' ),
			array(
				'wearer_id'       => $user_id,
				'keyholder_id'    => $keyholder,
				'keyholder_name'  => $kh_name,
				'option_key'      => $option,
				'option_name'     => $name,
				'rule'            => $rule,
				'release_policy'  => $policy,
				'started_at'      => $now,
				'planned_end'     => $length ? gmdate( 'Y-m-d H:i:s', time() + $length * MINUTE_IN_SECONDS ) : null,
				'hide_timer'      => 0,
				'verify_daily'    => 0,
				'hygiene_minutes' => 15,
				'show_profile'    => empty( $_POST['show_profile'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'release_allowed' => 0,
				'status'          => 'locked',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		$lock_id = (int) $wpdb->insert_id;
		$extra   = array( 'minutes' => $length, 'note' => $name );
		if ( $photo ) {
			$extra += array( 'photo' => $photo, 'photo_sha' => hash( 'sha256', $photo ) );
		}
		self::add_event( $lock_id, $user_id, 'locked', $extra );
		// The typed name is someone else's personal detail: the audit only notes that one was given.
		CMP_Audit::log( 'lock_started', 'lock', $lock_id, null, array( 'keyholder' => $keyholder, 'named_keyholder' => '' !== $kh_name, 'option' => $option, 'minutes' => $length ) );
		if ( $keyholder ) {
			/* translators: 1: wearer, 2: option */
			CMP_Notifications::add( $keyholder, 'lock', sprintf( __( '%1$s locked up with you as keyholder: %2$s.', 'cmp' ), self::me(), $name ), self::url( array( 'lock' => $lock_id ) ) );
		}
		self::go( array(), 'cl_started' );
	}

	/**
	 * The keyholder sets up a lock for a member whose key they hold (0.24.0).
	 * Nothing is locked yet: the wearer accepts or declines.
	 */
	private static function propose( $user_id ) {
		global $wpdb;
		$wearer  = self::posted_int( 'wearer' );
		$option  = self::posted_key( 'option' );
		$options = self::options();
		$length  = self::posted_int( 'length' );
		$hygiene = self::posted_int( 'hygiene_minutes' );
		if ( ! isset( self::wearer_choices( $user_id )[ $wearer ] ) || ! isset( $options[ $option ] ) || ( $length && ! isset( self::lengths()[ $length ] ) ) || ! isset( self::hygiene_choices()[ $hygiene ] ) ) {
			self::go( array(), 'cl_invalid' );
		}
		if ( self::active_lock( $wearer ) || self::proposal_for( $wearer ) ) {
			self::go( array(), 'cl_pending' );
		}
		list( $name, $rule, $policy ) = $options[ $option ];
		if ( 'custom' === $option ) {
			$name   = self::text( 'option_name', 60 );
			$rule   = self::text( 'rule', 300 );
			$policy = self::posted_key( 'policy' );
			if ( '' === $name || ! isset( self::policies()[ $policy ] ) ) {
				self::go( array(), 'cl_invalid' );
			}
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			self::t( 'locks' ),
			array(
				'wearer_id'       => $wearer,
				'keyholder_id'    => $user_id,
				'keyholder_name'  => '',
				'option_key'      => $option,
				'option_name'     => $name,
				'rule'            => $rule,
				'release_policy'  => $policy,
				'started_at'      => $now, // Set again when the wearer accepts.
				'planned_end'     => null,
				'hide_timer'      => empty( $_POST['hide_timer'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'verify_daily'    => empty( $_POST['verify_daily'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'hygiene_minutes' => $hygiene,
				'show_profile'    => empty( $_POST['ask_badge'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a request only; the wearer decides when accepting.
				'kh_show'         => empty( $_POST['kh_show'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'release_allowed' => 0,
				'status'          => 'proposed',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		$lock_id = (int) $wpdb->insert_id;
		self::add_event( $lock_id, $user_id, 'proposed', array( 'minutes' => $length, 'note' => self::text( 'note', 300 ) ) );
		CMP_Audit::log( 'lock_proposed', 'lock', $lock_id, null, array( 'wearer' => $wearer, 'option' => $option, 'minutes' => $length ) );
		/* translators: 1: keyholder, 2: option */
		CMP_Notifications::add( $wearer, 'lock', sprintf( __( '%1$s set up a lock for you: %2$s. It starts only if you accept.', 'cmp' ), self::me(), $name ), self::url() );
		self::go( array(), 'cl_proposed' );
	}

	/** The wearer accepts: the lock starts now, with the keyholder's settings. */
	private static function do_accept( $lock, $user_id ) {
		if ( ! CMP_Dynamics::lead_can_direct( $lock->keyholder_id, $user_id, 'chastity' ) ) {
			self::delete_lock( $lock->id );
			self::go( array(), 'cl_gone' );
		}
		if ( self::active_lock( $user_id ) ) {
			self::go( array(), 'cl_busy' );
		}
		$minutes = self::proposed_minutes( $lock );
		$photo   = self::posted_photo( array() );
		self::update_lock(
			$lock->id,
			array(
				'status'       => 'locked',
				'started_at'   => gmdate( 'Y-m-d H:i:s' ),
				'planned_end'  => $minutes ? gmdate( 'Y-m-d H:i:s', time() + $minutes * MINUTE_IN_SECONDS ) : null,
				'show_profile' => empty( $_POST['show_profile'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			)
		);
		$extra = array( 'minutes' => $minutes, 'note' => $lock->option_name );
		if ( $photo ) {
			$extra += array( 'photo' => $photo, 'photo_sha' => hash( 'sha256', $photo ) );
		}
		self::add_event( $lock->id, $user_id, 'locked', $extra );
		CMP_Audit::log( 'lock_started', 'lock', $lock->id, null, array( 'keyholder' => (int) $lock->keyholder_id, 'proposed' => true, 'option' => $lock->option_key, 'minutes' => $minutes ) );
		/* translators: 1: wearer, 2: option */
		self::notify_keyholder( $lock, sprintf( __( '%1$s accepted your lock and is now locked: %2$s.', 'cmp' ), self::me(), $lock->option_name ) );
		self::go( array(), 'cl_accepted' );
	}

	private static function do_decline( $lock, $user_id ) {
		self::delete_lock( $lock->id );
		CMP_Audit::log( 'lock_declined', 'lock', $lock->id, null, array( 'keyholder' => (int) $lock->keyholder_id ) );
		/* translators: %s: wearer */
		CMP_Notifications::add( (int) $lock->keyholder_id, 'lock', sprintf( __( '%s declined the lock you set up.', 'cmp' ), self::me() ), self::url() );
		self::go( array(), 'cl_declined' );
	}

	private static function do_withdraw( $lock, $user_id ) {
		self::delete_lock( $lock->id );
		CMP_Audit::log( 'lock_withdrawn', 'lock', $lock->id, null, array( 'wearer' => (int) $lock->wearer_id ) );
		/* translators: %s: keyholder */
		CMP_Notifications::add( (int) $lock->wearer_id, 'lock', sprintf( __( '%s withdrew the lock they set up for you.', 'cmp' ), self::me() ), self::url() );
		self::go( array(), 'cl_withdrawn' );
	}

	private static function notify_keyholder( $lock, $message ) {
		if ( $lock->keyholder_id ) {
			CMP_Notifications::add( (int) $lock->keyholder_id, 'lock', $message, self::url( array( 'lock' => $lock->id ) ) );
		}
	}

	private static function end( $lock, $user_id, $reason ) {
		self::update_lock( $lock->id, array( 'status' => 'ended', 'ended_at' => gmdate( 'Y-m-d H:i:s' ), 'ended_by' => $user_id, 'end_reason' => $reason, 'opened_at' => null, 'release_allowed' => 0 ) );
		self::add_event( $lock->id, $user_id, 'unlocked', array( 'note' => $reason ) );
		CMP_Audit::log( 'lock_ended', 'lock', $lock->id, null, array( 'reason' => $reason ) );
	}

	/* Wearer ---------------------------------------------------------- */

	private static function do_verify( $lock, $user_id ) {
		$photo = self::posted_photo( array() );
		if ( ! $photo ) {
			self::go( array(), 'cl_photo' );
		}
		self::add_event( $lock->id, $user_id, 'verification', array( 'photo' => $photo, 'photo_sha' => hash( 'sha256', $photo ), 'code' => self::code( $lock->id ), 'note' => self::text( 'note', 300 ) ) );
		/* translators: %s: wearer */
		self::notify_keyholder( $lock, sprintf( __( '%s added a verification photo.', 'cmp' ), self::me() ) );
		self::go( array(), 'cl_logged' );
	}

	private static function do_hygiene_open( $lock, $user_id ) {
		if ( $lock->opened_at || ( $lock->keyholder_id && ! (int) $lock->hygiene_minutes ) ) {
			self::go( array(), 'cl_invalid' );
		}
		self::update_lock( $lock->id, array( 'opened_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		self::add_event( $lock->id, $user_id, 'hygiene_open' );
		/* translators: 1: wearer, 2: minutes */
		self::notify_keyholder( $lock, sprintf( __( '%1$s opened for hygiene (allowed: %2$d minutes).', 'cmp' ), self::me(), (int) $lock->hygiene_minutes ) );
		self::go( array(), 'cl_logged' );
	}

	private static function do_hygiene_close( $lock, $user_id ) {
		if ( ! $lock->opened_at ) {
			self::go( array(), 'cl_invalid' );
		}
		$minutes = (int) ceil( ( time() - self::ts( $lock->opened_at ) ) / MINUTE_IN_SECONDS );
		$over    = $lock->keyholder_id && $minutes > (int) $lock->hygiene_minutes;
		self::update_lock( $lock->id, array( 'opened_at' => null ) );
		self::add_event( $lock->id, $user_id, 'hygiene_close', array( 'minutes' => $minutes, 'review' => $over ? 'over' : '' ) );
		/* translators: 1: wearer, 2: minutes */
		self::notify_keyholder( $lock, sprintf( __( '%1$s relocked after %2$d minutes.', 'cmp' ), self::me(), $minutes ) . ( $over ? ' ' . __( 'That was longer than allowed.', 'cmp' ) : '' ) );
		self::go( array(), 'cl_logged' );
	}

	/** Recorded whatever the rule says; flagged when it went against it. */
	private static function do_release( $lock, $user_id ) {
		$ok = 'free' === $lock->release_policy || ( 'permission' === $lock->release_policy && (int) $lock->release_allowed ) || ! $lock->keyholder_id;
		self::update_lock( $lock->id, array( 'release_allowed' => 0 ) );
		self::add_event( $lock->id, $user_id, 'release', array( 'note' => self::text( 'note', 300 ), 'review' => $ok ? '' : 'against' ) );
		/* translators: %s: wearer */
		self::notify_keyholder( $lock, sprintf( __( '%s logged a release.', 'cmp' ), self::me() ) . ( $ok ? '' : ' ' . __( 'It wasn\'t allowed by the rules.', 'cmp' ) ) );
		self::go( array(), 'cl_logged' );
	}

	private static function do_ask_unlock( $lock, $user_id ) {
		if ( ! $lock->keyholder_id ) {
			self::go( array(), 'cl_invalid' );
		}
		$note = self::text( 'note', 300 );
		self::add_event( $lock->id, $user_id, 'unlock_asked', array( 'note' => $note ) );
		/* translators: %s: wearer */
		self::notify_keyholder( $lock, sprintf( __( '%s asked to be unlocked.', 'cmp' ), self::me() ) . ( $note ? ' ' . $note : '' ) );
		self::go( array(), 'cl_logged' );
	}

	/** Self-lock with its time up (or no planned end): an ordinary unlock. */
	private static function do_unlock( $lock, $user_id ) {
		if ( $lock->keyholder_id || ( $lock->planned_end && self::seconds_left( $lock ) > 0 ) ) {
			self::go( array(), 'cl_invalid' );
		}
		self::end( $lock, $user_id, 'completed' );
		self::go( array(), 'cl_ended' );
	}

	/** Always available to the wearer. */
	private static function do_emergency( $lock, $user_id ) {
		self::end( $lock, $user_id, 'emergency' );
		/* translators: %s: wearer */
		self::notify_keyholder( $lock, sprintf( __( '%s used Emergency unlock. The lock has ended.', 'cmp' ), self::me() ) );
		self::go( array(), 'cl_ended' );
	}

	private static function do_wearer_settings( $lock, $user_id ) {
		$fields = array( 'show_profile' => empty( $_POST['show_profile'] ) ? 0 : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $lock->keyholder_id ) {
			$fields['verify_daily']   = empty( $_POST['verify_daily'] ) ? 0 : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$fields['keyholder_name'] = self::text( 'keyholder_name', 60 );
		}
		self::update_lock( $lock->id, $fields );
		self::go( array(), 'cl_saved' );
	}

	/* Keyholder ------------------------------------------------------- */

	private static function do_time( $lock, $user_id ) {
		$minutes = self::posted_int( 'minutes' );
		$sign    = 'remove' === self::posted_key( 'direction' ) ? -1 : 1;
		$back    = array( 'lock' => $lock->id );
		if ( ! isset( self::lengths()[ $minutes ] ) || ( $sign < 0 && ! $lock->planned_end ) ) {
			self::go( $back, 'cl_invalid' );
		}
		$base = $lock->planned_end ? max( time(), self::ts( $lock->planned_end ) ) : time();
		$end  = max( time(), $base + $sign * $minutes * MINUTE_IN_SECONDS );
		self::update_lock( $lock->id, array( 'planned_end' => gmdate( 'Y-m-d H:i:s', $end ) ) );
		self::add_event( $lock->id, $user_id, $sign > 0 ? 'time_added' : 'time_removed', array( 'minutes' => $minutes ) );
		if ( ! $lock->hide_timer ) {
			/* translators: 1: keyholder, 2: length */
			CMP_Notifications::add( (int) $lock->wearer_id, 'lock', sprintf( $sign > 0 ? __( '%1$s added %2$s to your lock.', 'cmp' ) : __( '%1$s took %2$s off your lock.', 'cmp' ), self::me(), self::lengths()[ $minutes ] ), self::url() );
		}
		self::go( $back, 'cl_saved' );
	}

	private static function do_rules( $lock, $user_id ) {
		$back    = array( 'lock' => $lock->id );
		$option  = self::posted_key( 'option' );
		$options = self::options();
		$hygiene = self::posted_int( 'hygiene_minutes' );
		$policy  = self::posted_key( 'policy' );
		if ( ! isset( $options[ $option ] ) || ! isset( self::hygiene_choices()[ $hygiene ] ) || ! isset( self::policies()[ $policy ] ) ) {
			self::go( $back, 'cl_invalid' );
		}
		$name = 'custom' === $option ? self::text( 'option_name', 60 ) : $options[ $option ][0];
		$rule = self::text( 'rule', 300 );
		if ( '' === $name ) {
			self::go( $back, 'cl_invalid' );
		}
		self::update_lock(
			$lock->id,
			array(
				'option_key'      => $option,
				'option_name'     => $name,
				'rule'            => '' !== $rule ? $rule : $options[ $option ][1],
				'release_policy'  => $policy,
				'hide_timer'      => empty( $_POST['hide_timer'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'verify_daily'    => empty( $_POST['verify_daily'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'hygiene_minutes' => $hygiene,
				'kh_show'         => empty( $_POST['kh_show'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			)
		);
		self::add_event( $lock->id, $user_id, 'rules', array( 'note' => $name ) );
		/* translators: %s: keyholder */
		CMP_Notifications::add( (int) $lock->wearer_id, 'lock', sprintf( __( '%s changed the rules of your lock.', 'cmp' ), self::me() ), self::url() );
		self::go( $back, 'cl_saved' );
	}

	private static function do_allow_release( $lock, $user_id ) {
		self::update_lock( $lock->id, array( 'release_allowed' => 1 ) );
		self::add_event( $lock->id, $user_id, 'release_allowed' );
		/* translators: %s: keyholder */
		CMP_Notifications::add( (int) $lock->wearer_id, 'lock', sprintf( __( '%s allowed you one release.', 'cmp' ), self::me() ), self::url() );
		self::go( array( 'lock' => $lock->id ), 'cl_saved' );
	}

	private static function do_review( $lock, $user_id ) {
		global $wpdb;
		$event   = self::event( self::posted_int( 'event' ) );
		$verdict = self::posted_key( 'verdict' );
		if ( ! $event || (int) $event->lock_id !== (int) $lock->id || 'verification' !== $event->type || ! in_array( $verdict, array( 'accepted', 'again' ), true ) ) {
			self::go( array( 'lock' => $lock->id ), 'cl_invalid' );
		}
		$wpdb->update( self::t( 'lock_events' ), array( 'review' => $verdict ), array( 'id' => $event->id ) );
		CMP_Notifications::add(
			(int) $lock->wearer_id,
			'lock',
			'accepted' === $verdict
				/* translators: %s: keyholder */
				? sprintf( __( '%s accepted your verification photo.', 'cmp' ), self::me() )
				/* translators: %s: keyholder */
				: sprintf( __( '%s asked for another verification photo.', 'cmp' ), self::me() ),
			self::url()
		);
		self::go( array( 'lock' => $lock->id ), 'cl_saved' );
	}

	/**
	 * 0.28.0: the keyholder marks a day verified because the wearer sent
	 * their verification another way (Telegram, text…). No photo is kept.
	 * A back-dated day is recorded at midday that day (site time), so it
	 * lands on the right day of the calendar.
	 */
	private static function do_verified_else( $lock, $user_id ) {
		$day  = isset( $_POST['day'] ) ? sanitize_text_field( wp_unslash( $_POST['day'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$days = self::elsewhere_days( $lock );
		if ( ! isset( $days[ $day ] ) ) {
			self::go( array( 'lock' => $lock->id ), 'cl_invalid' );
		}
		if ( self::day_verified( $lock, $day ) ) {
			self::go( array( 'lock' => $lock->id ), 'cl_verified_day' );
		}
		$today = wp_date( 'Y-m-d' ) === $day;
		$at    = gmdate( 'Y-m-d H:i:s' );
		if ( ! $today ) {
			$at = ( new DateTime( $day . ' 12:00:00', wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$at = max( $at, $lock->started_at );
		}
		self::add_event( $lock->id, $user_id, 'verified_else', array( 'note' => self::text( 'note', 200 ), 'created_at' => $at ) );
		CMP_Notifications::add(
			(int) $lock->wearer_id,
			'lock',
			/* translators: 1: keyholder, 2: "today" or a date */
			sprintf( __( '%1$s marked %2$s as verified (sent outside COL&B).', 'cmp' ), self::me(), $today ? __( 'today', 'cmp' ) : wp_date( 'D M j', self::ts( $at ) ) ),
			self::url()
		);
		self::go( array( 'lock' => $lock->id ), 'cl_saved' );
	}

	private static function do_kh_unlock( $lock, $user_id ) {
		self::end( $lock, $user_id, 'keyholder' );
		/* translators: %s: keyholder */
		CMP_Notifications::add( (int) $lock->wearer_id, 'lock', sprintf( __( '%s unlocked you. The lock has ended.', 'cmp' ), self::me() ), self::url() );
		self::go( array(), 'cl_ended' );
	}

	/** The keyholder loses control at once; the lock carries on as a self-lock. */
	public static function on_dynamic_ended( $dyn ) {
		global $wpdb;
		foreach ( array( array( $dyn->proposer_id, $dyn->partner_id ), array( $dyn->partner_id, $dyn->proposer_id ) ) as list( $kh, $wearer ) ) {
			if ( CMP_Dynamics::lead_can_direct( $kh, $wearer, 'chastity' ) ) {
				continue;
			}
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'locks' ) . " WHERE keyholder_id = %d AND wearer_id = %d AND status = 'locked'", $kh, $wearer ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$pending = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'locks' ) . " WHERE keyholder_id = %d AND wearer_id = %d AND status = 'proposed'", $kh, $wearer ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $pending as $pid ) {
				self::delete_lock( (int) $pid );
				CMP_Audit::log( 'lock_proposal_dropped', 'lock', (int) $pid, null, null, 'Dynamic ended' );
			}
			foreach ( $ids as $id ) {
				self::update_lock( (int) $id, array( 'keyholder_id' => 0, 'hide_timer' => 0, 'release_allowed' => 0 ) );
				self::add_event( (int) $id, 0, 'keyholder_left' );
				CMP_Audit::log( 'lock_keyholder_left', 'lock', (int) $id, null, null, 'Dynamic ended' );
				CMP_Notifications::add( (int) $wearer, 'lock', __( 'Your keyholder can no longer manage your lock (chastity was turned off or your dynamic ended), so it\'s now a self-lock. You can end it whenever you choose.', 'cmp' ), self::url() );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Site team corrections (0.26.0). Callers check manage_options.
	 * ---------------------------------------------------------------- */

	/** UTC 'Y-m-d H:i:s' to a site-time label. */
	public static function site_time( $utc ) {
		return $utc ? wp_date( 'M j, Y g:i A', self::ts( $utc ) ) : __( 'open-ended', 'cmp' );
	}

	/** Tell both members, and add the history line they both read. */
	private static function admin_note( $lock, $admin_id, $text, $about_end = false ) {
		self::add_event( $lock->id, $admin_id, 'admin_fix', array( 'note' => mb_substr( $text, 0, 500 ), 'review' => $about_end ? 'end' : '' ) );
		$hidden = $about_end && $lock->keyholder_id && (int) $lock->hide_timer;
		/* translators: %s: what changed */
		$msg = sprintf( __( 'The site team corrected your lock: %s', 'cmp' ), $text );
		CMP_Notifications::add( (int) $lock->wearer_id, 'lock', $hidden ? __( 'The site team made a correction to your lock.', 'cmp' ) : $msg, self::url() );
		if ( $lock->keyholder_id ) {
			/* translators: 1: wearer, 2: what changed */
			CMP_Notifications::add( (int) $lock->keyholder_id, 'lock', sprintf( __( 'The site team corrected %1$s\'s lock: %2$s', 'cmp' ), self::name( $lock->wearer_id ), $text ), self::url( array( 'lock' => $lock->id ) ) );
		}
	}

	/**
	 * Correct a lock's times. $fields may hold started_at, planned_end
	 * (null = open-ended) and ended_at, all UTC. Returns an error code or ''.
	 */
	public static function admin_set_times( $lock_id, $fields, $admin_id, $reason ) {
		$lock = self::lock( $lock_id );
		if ( ! $lock || 'proposed' === $lock->status ) {
			return 'gone';
		}
		$start = array_key_exists( 'started_at', $fields ) ? $fields['started_at'] : $lock->started_at;
		$end   = array_key_exists( 'ended_at', $fields ) ? $fields['ended_at'] : $lock->ended_at;
		$plan  = array_key_exists( 'planned_end', $fields ) ? $fields['planned_end'] : $lock->planned_end;
		$now   = time();
		if ( self::ts( $start ) > $now || ( 'ended' === $lock->status && ( ! $end || self::ts( $end ) > $now || self::ts( $end ) <= self::ts( $start ) ) ) || ( $plan && self::ts( $plan ) <= self::ts( $start ) ) ) {
			return 'times';
		}
		$set   = array();
		$lines = array();
		$old   = array();
		if ( $start !== $lock->started_at ) {
			$set['started_at'] = $start;
			$old['started_at'] = $lock->started_at;
			/* translators: 1: old time, 2: new time */
			$lines[] = sprintf( __( 'start changed from %1$s to %2$s', 'cmp' ), self::site_time( $lock->started_at ), self::site_time( $start ) );
		}
		if ( 'ended' === $lock->status && $end !== $lock->ended_at ) {
			$set['ended_at'] = $end;
			$old['ended_at'] = $lock->ended_at;
			/* translators: 1: old time, 2: new time */
			$lines[] = sprintf( __( 'end changed from %1$s to %2$s', 'cmp' ), self::site_time( $lock->ended_at ), self::site_time( $end ) );
		}
		$end_line = '';
		if ( 'locked' === $lock->status && $plan !== $lock->planned_end ) {
			$set['planned_end'] = $plan;
			$old['planned_end'] = $lock->planned_end;
			/* translators: 1: old time, 2: new time */
			$end_line = sprintf( __( 'planned end changed from %1$s to %2$s', 'cmp' ), self::site_time( $lock->planned_end ), self::site_time( $plan ) );
		}
		if ( ! $set ) {
			return 'same';
		}
		self::update_lock( $lock->id, $set );
		CMP_Audit::log( 'lock_admin_fix', 'lock', $lock->id, $old, $set, $reason, $admin_id );
		if ( $lines ) {
			$after = self::lock( $lock->id );
			/* translators: %s: duration */
			$lines[] = sprintf( __( 'now %s locked', 'cmp' ), self::duration( self::locked_seconds( $after ) ) );
			self::admin_note( $lock, $admin_id, implode( '; ', $lines ) . '.' );
		}
		if ( $end_line ) {
			self::admin_note( $lock, $admin_id, $end_line . '.', true );
		}
		return '';
	}

	/**
	 * Change a lock's settings for a member who can't (0.27.0). $in holds any
	 * of show_profile, kh_show, hide_timer, verify_daily (0/1),
	 * hygiene_minutes, option, option_name, release_policy, and keyholder
	 * (member id, 0 = self-lock). Returns an error code or ''.
	 */
	public static function admin_settings( $lock_id, $in, $admin_id, $reason ) {
		$lock = self::lock( $lock_id );
		if ( ! $lock || 'ended' === $lock->status ) {
			return 'gone';
		}
		$set     = array();
		$lines   = array();
		$yes     = function ( $v ) {
			return $v ? __( 'on', 'cmp' ) : __( 'off', 'cmp' );
		};
		$flags   = array(
			'show_profile' => __( 'profile badge', 'cmp' ),
			'kh_show'      => __( 'keyholder\'s name on badges', 'cmp' ),
			'hide_timer'   => __( 'time left hidden from the wearer', 'cmp' ),
			'verify_daily' => __( 'daily verification photo', 'cmp' ),
		);
		foreach ( $flags as $k => $label ) {
			if ( isset( $in[ $k ] ) && (int) $in[ $k ] !== (int) $lock->$k ) {
				$set[ $k ] = (int) $in[ $k ] ? 1 : 0;
				/* translators: 1: setting, 2: on/off */
				$lines[] = sprintf( __( '%1$s %2$s', 'cmp' ), $label, $yes( $set[ $k ] ) );
			}
		}
		if ( isset( $in['hygiene_minutes'] ) && (int) $in['hygiene_minutes'] !== (int) $lock->hygiene_minutes ) {
			if ( ! isset( self::hygiene_choices()[ (int) $in['hygiene_minutes'] ] ) ) {
				return 'invalid';
			}
			$set['hygiene_minutes'] = (int) $in['hygiene_minutes'];
			/* translators: %s: allowance */
			$lines[] = sprintf( __( 'hygiene opening: %s', 'cmp' ), mb_strtolower( self::hygiene_choices()[ $set['hygiene_minutes'] ] ) );
		}
		if ( isset( $in['option'] ) ) {
			$options = self::options();
			$opt     = $in['option'];
			$policy  = isset( $in['release_policy'] ) ? $in['release_policy'] : $lock->release_policy;
			if ( ! isset( $options[ $opt ] ) || ! isset( self::policies()[ $policy ] ) ) {
				return 'invalid';
			}
			$name = 'custom' === $opt ? trim( (string) ( isset( $in['option_name'] ) ? $in['option_name'] : '' ) ) : $options[ $opt ][0];
			if ( '' === $name ) {
				return 'invalid';
			}
			if ( $opt !== $lock->option_key || $name !== $lock->option_name ) {
				$set['option_key']  = $opt;
				$set['option_name'] = mb_substr( $name, 0, 60 );
				if ( 'custom' !== $opt ) {
					$set['rule'] = $options[ $opt ][1];
				}
				/* translators: %s: option */
				$lines[] = sprintf( __( 'option: %s', 'cmp' ), $set['option_name'] );
			}
			if ( $policy !== $lock->release_policy ) {
				$set['release_policy'] = $policy;
				/* translators: %s: release policy */
				$lines[] = sprintf( __( 'release: %s', 'cmp' ), mb_strtolower( self::policies()[ $policy ] ) );
			}
		}
		$old_kh = (int) $lock->keyholder_id;
		if ( isset( $in['keyholder'] ) && (int) $in['keyholder'] !== $old_kh ) {
			$kh = (int) $in['keyholder'];
			if ( $kh && ( ! get_userdata( $kh ) || ! CMP_Dynamics::lead_can_direct( $kh, $lock->wearer_id, 'chastity' ) ) ) {
				return 'keyholder';
			}
			$set['keyholder_id'] = $kh;
			if ( ! $kh ) {
				$set['hide_timer']      = 0;
				$set['release_allowed'] = 0;
			}
			$lines[] = $kh
				/* translators: %s: member */
				? sprintf( __( 'keyholder is now %s', 'cmp' ), self::name( $kh ) )
				: __( 'it\'s now a self-lock', 'cmp' );
		}
		if ( ! $set ) {
			return 'same';
		}
		$old = array();
		foreach ( array_keys( $set ) as $k ) {
			$old[ $k ] = $lock->$k;
		}
		self::update_lock( $lock->id, $set );
		CMP_Audit::log( 'lock_admin_settings', 'lock', $lock->id, $old, $set, $reason, $admin_id );
		$after = self::lock( $lock->id );
		$text  = implode( '; ', $lines ) . '.';
		self::admin_note( $after, $admin_id, $text );
		if ( isset( $set['keyholder_id'] ) && $old_kh && $old_kh !== (int) $set['keyholder_id'] ) {
			/* translators: %s: wearer */
			CMP_Notifications::add( $old_kh, 'lock', sprintf( __( 'The site team moved %s\'s lock to another keyholder or made it a self-lock. You no longer manage it.', 'cmp' ), self::name( $lock->wearer_id ) ), self::url() );
		}
		return '';
	}

	/** End an active lock (reason "site team"). */
	public static function admin_end( $lock_id, $admin_id, $reason ) {
		$lock = self::lock( $lock_id );
		if ( ! $lock || 'locked' !== $lock->status ) {
			return false;
		}
		self::update_lock( $lock->id, array( 'status' => 'ended', 'ended_at' => gmdate( 'Y-m-d H:i:s' ), 'ended_by' => $admin_id, 'end_reason' => 'admin', 'opened_at' => null, 'release_allowed' => 0 ) );
		self::add_event( $lock->id, $admin_id, 'unlocked', array( 'note' => 'admin' ) );
		CMP_Audit::log( 'lock_ended', 'lock', $lock->id, null, array( 'reason' => 'admin' ), $reason, $admin_id );
		self::admin_note( $lock, $admin_id, __( 'the lock was ended.', 'cmp' ) );
		return true;
	}

	/** Remove a lock record entirely (a stuck proposal, or a duplicate). Both members are told. */
	public static function admin_delete( $lock_id, $admin_id, $reason ) {
		$lock = self::lock( $lock_id );
		if ( ! $lock ) {
			return false;
		}
		self::delete_lock( $lock->id );
		CMP_Audit::log( 'lock_admin_removed', 'lock', $lock->id, array( 'wearer' => (int) $lock->wearer_id, 'keyholder' => (int) $lock->keyholder_id, 'status' => $lock->status, 'started_at' => $lock->started_at, 'ended_at' => $lock->ended_at ), null, $reason, $admin_id );
		$what = 'proposed' === $lock->status ? __( 'The site team removed a lock that was waiting to be accepted.', 'cmp' ) : sprintf( /* translators: %s: date */ __( 'The site team removed a lock record that started %s.', 'cmp' ), self::site_time( $lock->started_at ) );
		CMP_Notifications::add( (int) $lock->wearer_id, 'lock', $what, self::url() );
		if ( $lock->keyholder_id ) {
			CMP_Notifications::add( (int) $lock->keyholder_id, 'lock', $what . ' (' . self::name( $lock->wearer_id ) . ')', self::url() );
		}
		return true;
	}

	/**
	 * Record a lock that should exist (e.g. an accept that failed): running
	 * if $ended is null, otherwise a past lock. The keyholder must hold the
	 * wearer's key now for a running lock. Returns the lock id or an error code.
	 */
	public static function admin_record( $wearer, $keyholder, $option, $started, $planned_end, $ended, $admin_id, $reason ) {
		global $wpdb;
		$options = self::options();
		if ( ! get_userdata( $wearer ) || ! isset( $options[ $option ] ) || 'custom' === $option ) {
			return 'invalid';
		}
		if ( $keyholder && ( ! get_userdata( $keyholder ) || ( ! $ended && ! CMP_Dynamics::lead_can_direct( $keyholder, $wearer, 'chastity' ) ) ) ) {
			return 'keyholder';
		}
		if ( ! $started || self::ts( $started ) > time() || ( $ended && ( self::ts( $ended ) > time() || self::ts( $ended ) <= self::ts( $started ) ) ) || ( $planned_end && self::ts( $planned_end ) <= self::ts( $started ) ) ) {
			return 'times';
		}
		if ( ! $ended && self::active_lock( $wearer ) ) {
			return 'busy';
		}
		list( $name, $rule, $policy ) = $options[ $option ];
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			self::t( 'locks' ),
			array(
				'wearer_id'       => $wearer,
				'keyholder_id'    => $keyholder,
				'keyholder_name'  => '',
				'option_key'      => $option,
				'option_name'     => $name,
				'rule'            => $rule,
				'release_policy'  => $policy,
				'started_at'      => $started,
				'planned_end'     => $ended ? null : $planned_end,
				'hide_timer'      => 0,
				'verify_daily'    => 0,
				'hygiene_minutes' => 15,
				'show_profile'    => 0,
				'kh_show'         => 1,
				'release_allowed' => 0,
				'status'          => $ended ? 'ended' : 'locked',
				'ended_at'        => $ended ? $ended : null,
				'ended_by'        => $ended ? $admin_id : null,
				'end_reason'      => $ended ? 'admin' : '',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		self::add_event( $id, $admin_id, 'locked', array( 'note' => $name ) );
		CMP_Audit::log( 'lock_admin_recorded', 'lock', $id, null, array( 'wearer' => (int) $wearer, 'keyholder' => (int) $keyholder, 'started_at' => $started, 'planned_end' => $planned_end, 'ended_at' => $ended ), $reason, $admin_id );
		$lock = self::lock( $id );
		/* translators: 1: start, 2: duration */
		self::admin_note( $lock, $admin_id, sprintf( __( 'a lock was recorded from %1$s (%2$s locked).', 'cmp' ), self::site_time( $started ), self::duration( self::locked_seconds( $lock ) ) ) );
		return $id;
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	private static function form_open( $do, $lock_id = 0, $multipart = false, $class = 'cmp-form' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . ' class="' . esc_attr( $class ) . '"><input type="hidden" name="action" value="cmp_chastity" /><input type="hidden" name="do" value="' . esc_attr( $do ) . '" />' . ( $lock_id ? '<input type="hidden" name="lock" value="' . (int) $lock_id . '" />' : '' ) . '<input type="hidden" name="_cmp_nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" />';
	}

	/**
	 * The keyholder as shown on a lock: a member, or (0.19.2) a name the
	 * wearer typed for someone without an account. A named keyholder
	 * can't sign in, so the lock works as a self-lock: the wearer keeps
	 * every control. '' for a self-lock.
	 */
	private static function keyholder_label( $lock ) {
		if ( $lock->keyholder_id ) {
			return self::name( $lock->keyholder_id );
		}
		return '' !== (string) $lock->keyholder_name ? sprintf( /* translators: %s: name the wearer typed */ __( '%s (no account)', 'cmp' ), $lock->keyholder_name ) : '';
	}

	private static function name( $user_id ) {
		$u = $user_id ? get_userdata( $user_id ) : null;
		return $u ? $u->display_name : __( 'Former member', 'cmp' );
	}

	private static function select( $name, $id, $choices, $selected ) {
		$html = '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $choices as $k => $l ) {
			$html .= '<option value="' . esc_attr( $k ) . '"' . selected( (string) $selected, (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		return $html . '</select>';
	}

	private static function check( $name, $id, $label, $on ) {
		return '<p class="cmp-check"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $on, true, false ) . ' /><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></p>';
	}

	public static function render( $user_id ) {
		wp_enqueue_script( 'cmp-member' );
		$lock_id = isset( $_GET['lock'] ) ? absint( $_GET['lock'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $lock_id ) {
			$lock = self::lock( $lock_id );
			if ( ! self::is_keyholder( $lock, $user_id ) ) {
				return '<section class="cmp-step"><p class="cmp-empty">' . esc_html__( 'That lock isn\'t available.', 'cmp' ) . '</p><p><a href="' . esc_url( self::url() ) . '">← ' . esc_html__( 'Chastity', 'cmp' ) . '</a></p></section>';
			}
			return self::render_keyholder( $lock );
		}
		$lock = self::active_lock( $user_id );
		$held = self::locks_held( $user_id );
		ob_start();
		?>
		<section class="cmp-step" aria-labelledby="cmp-cl-title">
			<h2 id="cmp-cl-title" class="cmp-title"><?php echo esc_html( $lock ? __( 'My lock', 'cmp' ) : __( 'Chastity', 'cmp' ) ); ?></h2>
			<?php if ( $lock ) : ?>
				<p class="cmp-muted"><?php echo esc_html( ( '' !== self::keyholder_label( $lock ) ? sprintf( /* translators: %s: keyholder */ __( 'Keyholder: %s', 'cmp' ), self::keyholder_label( $lock ) ) : __( 'Self-lock', 'cmp' ) ) . ' · ' . sprintf( /* translators: %s: date */ __( 'started %s', 'cmp' ), wp_date( 'M j, g:i A', self::ts( $lock->started_at ) ) ) ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Track a chastity lock: on your own, or with a keyholder you\'ve agreed chastity with in a dynamic. This keeps the record; it doesn\'t control any device, and you can always end a lock yourself.', 'cmp' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
		$proposal = self::proposal_for( $user_id );
		if ( $proposal ) {
			echo self::proposal_html( $proposal, (bool) $lock ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		if ( $lock ) {
			echo self::wearer_html( $lock ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		} else {
			echo self::start_html( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		echo self::calendar_html( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		?>
		<section class="cmp-panel">
			<h3 class="cmp-panel-title"><?php esc_html_e( 'Locks you hold', 'cmp' ); ?></h3>
			<?php if ( ! $held ) : ?>
				<p class="cmp-empty"><?php esc_html_e( 'When a member whose key you hold (chastity in a dynamic) locks up with you as keyholder, or accepts a lock you set up for them, their lock appears here.', 'cmp' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $held as $h ) : ?>
				<p class="cmp-hw-item"><a href="<?php echo esc_url( self::url( array( 'lock' => $h->id ) ) ); ?>"><b><?php echo esc_html( self::name( $h->wearer_id ) ); ?></b></a> · <?php echo esc_html( $h->option_name ); ?> · <?php echo esc_html( sprintf( /* translators: %s: duration */ __( 'locked %s', 'cmp' ), self::duration( self::locked_seconds( $h ) ) ) ); ?></p>
			<?php endforeach; ?>
			<?php echo self::sent_html( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
		</section>
		<?php
		$past = self::past_locks( $user_id );
		if ( $past ) :
			$reasons = array( 'completed' => __( 'completed', 'cmp' ), 'keyholder' => __( 'unlocked by keyholder', 'cmp' ), 'emergency' => __( 'emergency unlock', 'cmp' ), 'admin' => __( 'ended by the site team', 'cmp' ) );
			?>
			<section class="cmp-panel">
				<h3 class="cmp-panel-title"><?php esc_html_e( 'Past locks', 'cmp' ); ?></h3>
				<?php foreach ( $past as $p ) : ?>
					<p class="cmp-hw-item"><?php echo esc_html( wp_date( 'M j', self::ts( $p->started_at ) ) . ' – ' . wp_date( 'M j', self::ts( $p->ended_at ) ) . ' · ' . self::duration( self::locked_seconds( $p ) ) . ' · ' . $p->option_name . ' · ' . ( isset( $reasons[ $p->end_reason ] ) ? $reasons[ $p->end_reason ] : '' ) ); ?></p>
				<?php endforeach; ?>
			</section>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	private static function start_html( $user_id ) {
		$keyholders = array( 0 => __( 'No one (self-lock)', 'cmp' ) ) + self::keyholder_choices( $user_id );
		$options    = array();
		foreach ( self::options() as $k => $o ) {
			$options[ $k ] = $o[0];
		}
		$html  = '<section class="cmp-panel"><h3 class="cmp-panel-title">' . esc_html__( 'Start a lock', 'cmp' ) . '</h3>';
		$html .= self::form_open( 'start', 0, true );
		$keyholders['named'] = __( 'Someone without an account…', 'cmp' );
		$html .= '<div class="cmp-grid cmp-grid-3"><p class="cmp-field"><label for="cmp_cl_kh">' . esc_html__( 'Keyholder', 'cmp' ) . '</label>' . self::select( 'keyholder', 'cmp_cl_kh', $keyholders, 0 ) . '</p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_opt">' . esc_html__( 'Option', 'cmp' ) . '</label>' . self::select( 'option', 'cmp_cl_opt', $options, 'a' ) . '</p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_len">' . esc_html__( 'Planned length', 'cmp' ) . '</label>' . self::select( 'length', 'cmp_cl_len', array( 0 => __( 'Open-ended', 'cmp' ) ) + self::lengths(), 10080 ) . '</p></div>';
		$html .= '<p class="cmp-field" data-cmp-kh-name><label for="cmp_cl_khn">' . esc_html__( 'Keyholder\'s name (if they don\'t have an account)', 'cmp' ) . '</label><input type="text" id="cmp_cl_khn" name="keyholder_name" maxlength="60" autocomplete="off" aria-describedby="cmp_cl_khn_help" /><span class="cmp-muted" id="cmp_cl_khn_help">' . esc_html__( 'Used when Keyholder is "Someone without an account". It\'s just a name for your record: they can\'t sign in to manage the lock, so it works like a self-lock and you keep control. Once they join and you agree chastity in a dynamic, choose them as a member next time.', 'cmp' ) . '</span></p>';
		$html .= '<details class="cmp-hw-taskedit"><summary>' . esc_html__( 'Custom option', 'cmp' ) . '</summary><p class="cmp-muted">' . esc_html__( 'Used when Option is "Custom".', 'cmp' ) . '</p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_name">' . esc_html__( 'Name', 'cmp' ) . '</label><input type="text" id="cmp_cl_name" name="option_name" maxlength="60" /></p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_rule">' . esc_html__( 'Rule', 'cmp' ) . '</label><input type="text" id="cmp_cl_rule" name="rule" maxlength="300" /></p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_pol">' . esc_html__( 'Release', 'cmp' ) . '</label>' . self::select( 'policy', 'cmp_cl_pol', self::policies(), 'none' ) . '</p></details>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_photo">' . esc_html__( 'Photo of the lock (optional)', 'cmp' ) . '</label><input type="file" id="cmp_cl_photo" name="photo" accept="image/jpeg,image/png,image/webp" /></p>';
		$html .= self::check( 'show_profile', 'cmp_cl_show', __( 'Show "Locked · N days · last verified" on my profile (with your keyholder\'s name, if they allow it)', 'cmp' ), false );
		$html .= '<button type="submit" class="cmp-btn">' . esc_html__( 'Lock', 'cmp' ) . '</button></form>';
		if ( count( $keyholders ) < 3 ) {
			$html .= '<p class="cmp-muted">' . esc_html__( 'To have a member as keyholder, agree chastity with them in a dynamic, with them holding the key (any type, or Keyholder / chastity wearer). See the Dynamics tab. If your keyholder doesn\'t have an account yet, choose "Someone without an account" and type their name.', 'cmp' ) . '</p>';
		}
		return $html . '</section>';
	}

	/** What the wearer is asked to agree to (0.24.0). */
	private static function proposal_html( $lock, $busy ) {
		$minutes = self::proposed_minutes( $lock );
		$lengths = self::lengths();
		$hyg     = self::hygiene_choices();
		$note    = '';
		foreach ( self::events( $lock->id, 5 ) as $e ) {
			if ( 'proposed' === $e->type ) {
				$note = $e->note;
				break;
			}
		}
		$length = $lock->hide_timer ? __( 'Hidden by your keyholder', 'cmp' ) : ( $minutes ? ( isset( $lengths[ $minutes ] ) ? $lengths[ $minutes ] : self::duration( $minutes * MINUTE_IN_SECONDS ) ) : __( 'Open-ended', 'cmp' ) );
		$html   = '<section class="cmp-panel cmp-cl-proposal"><h3 class="cmp-panel-title">' . esc_html( sprintf( /* translators: %s: keyholder */ __( '%s set up a lock for you', 'cmp' ), self::name( $lock->keyholder_id ) ) ) . '</h3>';
		$html  .= self::rule_html( $lock );
		$html  .= '<ul class="cmp-cl-terms">';
		$html  .= '<li>' . esc_html( sprintf( /* translators: %s: length */ __( 'Length: %s', 'cmp' ), $length ) ) . '</li>';
		$html  .= '<li>' . esc_html( $lock->verify_daily ? __( 'Daily verification photo: yes', 'cmp' ) : __( 'Daily verification photo: no', 'cmp' ) ) . '</li>';
		$html  .= '<li>' . esc_html( sprintf( /* translators: %s: hygiene allowance */ __( 'Hygiene opening: %s', 'cmp' ), isset( $hyg[ (int) $lock->hygiene_minutes ] ) ? $hyg[ (int) $lock->hygiene_minutes ] : '' ) ) . '</li>';
		$html  .= '</ul>';
		if ( '' !== (string) $note ) {
			$html .= '<p class="cmp-hw-std">“' . esc_html( $note ) . '”</p>';
		}
		$html .= '<p class="cmp-muted">' . esc_html__( 'Nothing starts until you accept. Once you do, your timer starts and your keyholder can manage the lock. Emergency unlock is always available to you.', 'cmp' ) . '</p>';
		if ( $busy ) {
			$html .= '<p class="cmp-muted">' . esc_html__( 'End your current lock to accept this one.', 'cmp' ) . '</p>';
		} else {
			$html .= self::form_open( 'accept', $lock->id, true );
			$html .= '<p class="cmp-field"><label for="cmp_cl_aphoto">' . esc_html__( 'Photo of the lock (optional)', 'cmp' ) . '</label><input type="file" id="cmp_cl_aphoto" name="photo" accept="image/jpeg,image/png,image/webp" /></p>';
			if ( (int) $lock->show_profile ) {
				$html .= '<p class="cmp-muted">' . esc_html__( 'Your keyholder would like the badge shown on your profile. It\'s still your choice.', 'cmp' ) . '</p>';
			}
			$html .= self::check( 'show_profile', 'cmp_cl_ashow', self::badge_label( $lock ), (int) $lock->show_profile );
			$html .= '<button type="submit" class="cmp-btn">' . esc_html__( 'Accept and lock', 'cmp' ) . '</button></form>';
		}
		$html .= self::form_open( 'decline', $lock->id, false, 'cmp-inline-form' ) . '<button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Decline', 'cmp' ) . '</button></form>';
		return $html . '</section>';
	}

	/** The keyholder's "Set up a lock" form and the locks waiting for an answer (0.24.0). */
	private static function sent_html( $user_id ) {
		$html = '';
		foreach ( self::proposals_sent( $user_id ) as $p ) {
			$html .= '<p class="cmp-hw-item"><b>' . esc_html( self::name( $p->wearer_id ) ) . '</b> · ' . esc_html( $p->option_name ) . ' · ' . esc_html__( 'waiting for them to accept', 'cmp' ) . ' ' . self::form_open( 'withdraw', $p->id, false, 'cmp-inline-form' ) . '<button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Withdraw', 'cmp' ) . '</button></form></p>';
		}
		$wearers = array();
		foreach ( self::wearer_choices( $user_id ) as $id => $label ) {
			if ( ! self::active_lock( $id ) && ! self::proposal_for( $id ) ) {
				$wearers[ $id ] = $label;
			}
		}
		if ( ! $wearers ) {
			return $html;
		}
		$options = array();
		foreach ( self::options() as $k => $o ) {
			$options[ $k ] = $o[0];
		}
		$html .= '<details class="cmp-hw-taskedit cmp-cl-propose"><summary>' . esc_html__( 'Set up a lock for someone', 'cmp' ) . '</summary>';
		$html .= '<p class="cmp-muted">' . esc_html__( 'They\'re asked to accept. Their timer starts when they do, with the settings you choose here.', 'cmp' ) . '</p>';
		$html .= self::form_open( 'propose' );
		$html .= '<div class="cmp-grid cmp-grid-3"><p class="cmp-field"><label for="cmp_cl_pw">' . esc_html__( 'Wearer', 'cmp' ) . '</label>' . self::select( 'wearer', 'cmp_cl_pw', $wearers, key( $wearers ) ) . '</p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_popt">' . esc_html__( 'Option', 'cmp' ) . '</label>' . self::select( 'option', 'cmp_cl_popt', $options, 'a' ) . '</p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_plen">' . esc_html__( 'Planned length', 'cmp' ) . '</label>' . self::select( 'length', 'cmp_cl_plen', array( 0 => __( 'Open-ended', 'cmp' ) ) + self::lengths(), 10080 ) . '</p></div>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_phyg">' . esc_html__( 'Hygiene opening', 'cmp' ) . '</label>' . self::select( 'hygiene_minutes', 'cmp_cl_phyg', self::hygiene_choices(), 15 ) . '</p>';
		$html .= '<details class="cmp-hw-taskedit"><summary>' . esc_html__( 'Custom option', 'cmp' ) . '</summary><p class="cmp-muted">' . esc_html__( 'Used when Option is "Custom".', 'cmp' ) . '</p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_pname">' . esc_html__( 'Name', 'cmp' ) . '</label><input type="text" id="cmp_cl_pname" name="option_name" maxlength="60" /></p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_prule">' . esc_html__( 'Rule', 'cmp' ) . '</label><input type="text" id="cmp_cl_prule" name="rule" maxlength="300" /></p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_ppol">' . esc_html__( 'Release', 'cmp' ) . '</label>' . self::select( 'policy', 'cmp_cl_ppol', self::policies(), 'none' ) . '</p></details>';
		$html .= self::check( 'hide_timer', 'cmp_cl_phide', __( 'Hide the time left from them', 'cmp' ), false );
		$html .= self::check( 'verify_daily', 'cmp_cl_pvd', __( 'Daily verification photo', 'cmp' ), false );
		$html .= self::check( 'ask_badge', 'cmp_cl_pbadge', __( 'Ask them to show "Locked by me" on their profile (they decide)', 'cmp' ), true );
		$html .= self::check( 'kh_show', 'cmp_cl_pkhs', __( 'Show "Holds the key for them" on my profile', 'cmp' ), true );
		$html .= '<p class="cmp-field"><label for="cmp_cl_pnote">' . esc_html__( 'Message (optional)', 'cmp' ) . '</label><input type="text" id="cmp_cl_pnote" name="note" maxlength="300" /></p>';
		$html .= '<button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Send to them', 'cmp' ) . '</button></form></details>';
		return $html;
	}

	private static function timer_html( $lock, $show_left ) {
		$left = self::seconds_left( $lock );
		$html = '<div class="cmp-cl-timer"><span class="cmp-cl-icon" aria-hidden="true">' . ( $lock->opened_at ? '🔓' : '🔒' ) . '</span><b data-cmp-since="' . (int) self::ts( $lock->started_at ) . '">' . esc_html( self::duration( self::locked_seconds( $lock ) ) ) . '</b><small>' . esc_html__( 'Locked for', 'cmp' ) . '</small>';
		if ( $lock->opened_at ) {
			$html .= '<p class="cmp-cl-open">' . esc_html( sprintf( /* translators: %s: time */ __( 'Open for hygiene since %s', 'cmp' ), wp_date( 'g:i A', self::ts( $lock->opened_at ) ) ) ) . '</p>';
		}
		if ( ! $show_left ) {
			$html .= '<p class="cmp-cl-left is-hidden">' . esc_html__( 'Time left is hidden by your keyholder', 'cmp' ) . '</p>';
		} elseif ( null === $left ) {
			$html .= '<p class="cmp-cl-left">' . esc_html__( 'Open-ended', 'cmp' ) . '</p>';
		} elseif ( $left > 0 ) {
			$html .= '<p class="cmp-cl-left">' . esc_html( sprintf( /* translators: 1: duration, 2: date */ __( '%1$s left · ends %2$s', 'cmp' ), self::duration( $left ), wp_date( 'M j, g:i A', self::ts( $lock->planned_end ) ) ) ) . '</p>';
		} else {
			$html .= '<p class="cmp-cl-left is-done">' . esc_html__( 'Time is up', 'cmp' ) . '</p>';
		}
		return $html . '</div>';
	}

	private static function rule_html( $lock ) {
		$p = self::policies();
		return '<p class="cmp-cl-rule"><b>' . esc_html( $lock->option_name ) . '</b>' . ( $lock->rule ? esc_html( $lock->rule ) . ' ' : '' ) . esc_html( sprintf( /* translators: %s: release policy */ __( 'Release: %s.', 'cmp' ), mb_strtolower( isset( $p[ $lock->release_policy ] ) ? $p[ $lock->release_policy ] : '' ) ) ) . '</p>';
	}

	private static function wearer_html( $lock ) {
		$has_kh = (bool) $lock->keyholder_id;
		$html   = '<section class="cmp-panel cmp-cl">' . self::timer_html( $lock, ! ( $has_kh && $lock->hide_timer ) ) . self::rule_html( $lock );
		if ( (int) $lock->release_allowed ) {
			$html .= '<p class="cmp-hw-std">' . esc_html__( 'Your keyholder has allowed you one release.', 'cmp' ) . '</p>';
		}
		$html .= '</section>';

		// Verification.
		$today  = self::today_verification( $lock );
		$html  .= '<section class="cmp-panel"><h3 class="cmp-panel-title">' . esc_html__( 'Verification', 'cmp' ) . '</h3>';
		$html  .= '<p>' . esc_html( $lock->verify_daily ? __( 'Every day: a photo of the device with today\'s code written on paper.', 'cmp' ) : __( 'Optional: a photo of the device with today\'s code written on paper.', 'cmp' ) ) . '</p>';
		$html  .= '<p class="cmp-cl-codewrap"><span class="cmp-cl-code" data-cmp-code>' . esc_html( self::code( $lock->id ) ) . '</span></p>';
		if ( $today && 'verified_else' === $today->type ) {
			$html .= '<p class="cmp-muted">' . esc_html__( 'Your keyholder marked today as verified (sent outside COL&B).', 'cmp' ) . '</p>';
		} elseif ( $today ) {
			$state = 'accepted' === $today->review ? __( 'accepted', 'cmp' ) : ( 'again' === $today->review ? __( 'your keyholder asked for another', 'cmp' ) : __( 'sent', 'cmp' ) );
			$html .= '<p class="cmp-muted">' . esc_html( sprintf( /* translators: 1: time, 2: state */ __( 'Today\'s photo: %1$s, %2$s.', 'cmp' ), wp_date( 'g:i A', self::ts( $today->created_at ) ), $state ) ) . '</p>';
		}
		$html .= self::form_open( 'verify', $lock->id, true ) . '<p class="cmp-field"><label for="cmp_cl_vphoto">' . esc_html__( 'Photo', 'cmp' ) . '</label><input type="file" id="cmp_cl_vphoto" name="photo" accept="image/jpeg,image/png,image/webp" required /></p><button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Send verification', 'cmp' ) . '</button></form></section>';

		// Hygiene, release, unlock.
		$html .= '<section class="cmp-panel"><h3 class="cmp-panel-title">' . esc_html__( 'Opening and release', 'cmp' ) . '</h3>';
		if ( $lock->opened_at ) {
			$html .= self::form_open( 'hygiene_close', $lock->id ) . '<button type="submit" class="cmp-btn">' . esc_html__( 'Relocked', 'cmp' ) . '</button></form>';
		} elseif ( ! $has_kh || (int) $lock->hygiene_minutes ) {
			$html .= '<p>' . esc_html( $has_kh ? sprintf( /* translators: %d: minutes */ __( 'Hygiene opening: up to %d minutes. Your keyholder is told when you open and when you relock.', 'cmp' ), (int) $lock->hygiene_minutes ) : __( 'Hygiene opening: record when you open and relock.', 'cmp' ) ) . '</p>';
			$html .= self::form_open( 'hygiene_open', $lock->id ) . '<button type="submit" class="cmp-btn cmp-btn-outline">' . esc_html__( 'Open for hygiene', 'cmp' ) . '</button></form>';
		} else {
			$html .= '<p class="cmp-muted">' . esc_html__( 'Your keyholder hasn\'t allowed hygiene openings.', 'cmp' ) . '</p>';
		}
		$html .= '<details class="cmp-hw-taskedit"><summary>' . esc_html__( 'Log a release', 'cmp' ) . '</summary>' . self::form_open( 'release', $lock->id ) . '<p class="cmp-field"><label for="cmp_cl_rnote">' . esc_html__( 'Note (optional)', 'cmp' ) . '</label><input type="text" id="cmp_cl_rnote" name="note" maxlength="300" /></p><button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Log release', 'cmp' ) . '</button></form></details>';
		if ( $has_kh ) {
			$html .= '<details class="cmp-hw-taskedit"><summary>' . esc_html__( 'Ask to be unlocked', 'cmp' ) . '</summary>' . self::form_open( 'ask_unlock', $lock->id ) . '<p class="cmp-field"><label for="cmp_cl_anote">' . esc_html__( 'Message (optional)', 'cmp' ) . '</label><input type="text" id="cmp_cl_anote" name="note" maxlength="300" /></p><button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Ask', 'cmp' ) . '</button></form></details>';
		} elseif ( ! $lock->planned_end || ! self::seconds_left( $lock ) ) {
			$html .= self::form_open( 'unlock', $lock->id, false, 'cmp-form cmp-cl-unlock' ) . '<button type="submit" class="cmp-btn">' . esc_html__( 'Unlock', 'cmp' ) . '</button></form>';
		}
		$html .= '</section>';

		// Settings.
		$html .= '<section class="cmp-panel"><h3 class="cmp-panel-title">' . esc_html__( 'Settings', 'cmp' ) . '</h3>' . self::form_open( 'wearer_settings', $lock->id );
		$html .= self::check( 'show_profile', 'cmp_cl_show', self::badge_label( $lock ), (int) $lock->show_profile );
		if ( ! $has_kh ) {
			$html .= self::check( 'verify_daily', 'cmp_cl_vd', __( 'Remind me to verify every day', 'cmp' ), (int) $lock->verify_daily );
			$html .= '<p class="cmp-field"><label for="cmp_cl_khn_edit">' . esc_html__( 'Keyholder without an account (optional)', 'cmp' ) . '</label><input type="text" id="cmp_cl_khn_edit" name="keyholder_name" maxlength="60" value="' . esc_attr( $lock->keyholder_name ) . '" aria-describedby="cmp_cl_khn_edit_help" /><span class="cmp-muted" id="cmp_cl_khn_edit_help">' . esc_html__( 'Just a name for your record. They can\'t sign in to manage the lock, so you keep control of it. Leave empty for a self-lock.', 'cmp' ) . '</span></p>';
		}
		$html .= '<button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Save', 'cmp' ) . '</button></form></section>';

		$html .= self::history_html( $lock, false );

		// Emergency unlock: always there.
		$html .= '<section class="cmp-panel cmp-cl-emergency">' . self::form_open( 'emergency', $lock->id ) . '<button type="submit" class="cmp-btn cmp-btn-outline cmp-btn-danger" data-cmp-confirm="' . esc_attr__( 'End this lock now? Your keyholder will be told.', 'cmp' ) . '">' . esc_html( $has_kh || self::seconds_left( $lock ) ? __( 'Emergency unlock', 'cmp' ) : __( 'End lock', 'cmp' ) ) . '</button></form><p class="cmp-muted">' . esc_html__( 'Always available. Ends the lock now; it\'s recorded and your keyholder is told.', 'cmp' ) . '</p></section>';
		return $html;
	}

	private static function render_keyholder( $lock ) {
		$options = array();
		foreach ( self::options() as $k => $o ) {
			$options[ $k ] = $o[0];
		}
		$html  = '<section class="cmp-step cmp-cl"><p><a href="' . esc_url( self::url() ) . '">← ' . esc_html__( 'Chastity', 'cmp' ) . '</a></p>';
		$html .= '<h2 id="cmp-cl-title" class="cmp-title">' . esc_html( sprintf( /* translators: %s: wearer */ __( '%s\'s lock', 'cmp' ), self::name( $lock->wearer_id ) ) ) . '</h2>';
		$html .= '<p class="cmp-muted">' . esc_html__( 'You\'re the keyholder.', 'cmp' ) . ( $lock->hide_timer ? ' ' . esc_html__( 'Time left is hidden from them.', 'cmp' ) : '' ) . '</p>';
		$html .= self::timer_html( $lock, true ) . self::rule_html( $lock ) . '</section>';

		// Time.
		$html .= '<section class="cmp-panel"><h3 class="cmp-panel-title">' . esc_html__( 'Time', 'cmp' ) . '</h3>' . self::form_open( 'time', $lock->id, false, 'cmp-form cmp-cl-time' );
		$html .= '<p class="cmp-field"><label for="cmp_cl_min">' . esc_html__( 'Amount', 'cmp' ) . '</label>' . self::select( 'minutes', 'cmp_cl_min', self::lengths(), 1440 ) . '</p>';
		$html .= '<div class="cmp-actions"><button type="submit" name="direction" value="add" class="cmp-btn cmp-btn-small">' . esc_html__( 'Add time', 'cmp' ) . '</button>' . ( $lock->planned_end ? '<button type="submit" name="direction" value="remove" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Remove time', 'cmp' ) . '</button>' : '' ) . '</div></form></section>';

		// Verification photos waiting.
		$events = self::events( $lock->id, 40 );
		$html  .= '<section class="cmp-panel"><h3 class="cmp-panel-title">' . esc_html( sprintf( /* translators: %s: code */ __( 'Verification · today\'s code %s', 'cmp' ), self::code( $lock->id ) ) ) . '</h3>';
		$shown  = 0;
		foreach ( $events as $e ) {
			if ( 'verification' !== $e->type || $shown >= 3 ) {
				continue;
			}
			$shown++;
			$html .= '<div class="cmp-hw-proof"><p><b>' . esc_html( wp_date( 'D M j, g:i A', self::ts( $e->created_at ) ) ) . '</b> · ' . esc_html( sprintf( /* translators: %s: code */ __( 'code %s', 'cmp' ), $e->code ) ) . ( $e->review ? ' · ' . esc_html( 'accepted' === $e->review ? __( 'accepted', 'cmp' ) : __( 'asked for another', 'cmp' ) ) : '' ) . '</p>';
			if ( $e->photo_sha ) {
				$html .= '<a href="' . esc_url( self::photo_url( $e ) ) . '" target="_blank" rel="noopener"><img src="' . esc_url( self::photo_url( $e ) ) . '" alt="' . esc_attr__( 'Verification photo', 'cmp' ) . '" loading="lazy" /></a>';
			}
			$html .= self::form_open( 'review', $lock->id ) . '<input type="hidden" name="event" value="' . (int) $e->id . '" /><div class="cmp-actions"><button type="submit" name="verdict" value="accepted" class="cmp-btn cmp-btn-small">' . esc_html__( 'Accept', 'cmp' ) . '</button><button type="submit" name="verdict" value="again" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Ask for another', 'cmp' ) . '</button></div></form></div>';
		}
		if ( ! $shown ) {
			$html .= '<p class="cmp-empty">' . esc_html__( 'No verification photos yet.', 'cmp' ) . '</p>';
		}
		$days = self::elsewhere_days( $lock );
		if ( $days ) {
			$html .= '<details class="cmp-hw-taskedit cmp-cl-elsewhere"><summary>' . esc_html__( 'Verified elsewhere', 'cmp' ) . '</summary>' . self::form_open( 'verified_else', $lock->id );
			$html .= '<p class="cmp-muted">' . esc_html__( 'Did they send you their verification another way, like Telegram or a text? Mark the day here so it counts. No photo is kept.', 'cmp' ) . '</p>';
			$html .= '<p class="cmp-field"><label for="cmp_cl_vday">' . esc_html__( 'Day', 'cmp' ) . '</label>' . self::select( 'day', 'cmp_cl_vday', $days, (string) key( $days ) ) . '</p>';
			$html .= '<p class="cmp-field"><label for="cmp_cl_vnote">' . esc_html__( 'Where (optional)', 'cmp' ) . '</label><input type="text" id="cmp_cl_vnote" name="note" maxlength="200" placeholder="' . esc_attr__( 'e.g. Telegram', 'cmp' ) . '" /></p>';
			$html .= '<button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Mark verified', 'cmp' ) . '</button></form></details>';
		}
		$html .= '</section>';

		// Rules.
		$html .= '<section class="cmp-panel"><h3 class="cmp-panel-title">' . esc_html__( 'Rules', 'cmp' ) . '</h3>' . self::form_open( 'rules', $lock->id );
		$html .= '<div class="cmp-grid cmp-grid-3"><p class="cmp-field"><label for="cmp_cl_ropt">' . esc_html__( 'Option', 'cmp' ) . '</label>' . self::select( 'option', 'cmp_cl_ropt', $options, $lock->option_key ) . '</p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_rpol">' . esc_html__( 'Release', 'cmp' ) . '</label>' . self::select( 'policy', 'cmp_cl_rpol', self::policies(), $lock->release_policy ) . '</p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_hyg">' . esc_html__( 'Hygiene opening', 'cmp' ) . '</label>' . self::select( 'hygiene_minutes', 'cmp_cl_hyg', self::hygiene_choices(), (int) $lock->hygiene_minutes ) . '</p></div>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_rname">' . esc_html__( 'Name (for Custom)', 'cmp' ) . '</label><input type="text" id="cmp_cl_rname" name="option_name" maxlength="60" value="' . esc_attr( 'custom' === $lock->option_key ? $lock->option_name : '' ) . '" /></p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_rrule">' . esc_html__( 'Rule', 'cmp' ) . '</label><input type="text" id="cmp_cl_rrule" name="rule" maxlength="300" value="' . esc_attr( $lock->rule ) . '" /></p>';
		$html .= self::check( 'hide_timer', 'cmp_cl_hide', __( 'Hide the time left from them', 'cmp' ), (int) $lock->hide_timer );
		$html .= self::check( 'verify_daily', 'cmp_cl_kvd', __( 'Daily verification photo', 'cmp' ), (int) $lock->verify_daily );
		$html .= self::check( 'kh_show', 'cmp_cl_khs', __( 'Show "Holds the key for them" on my profile, and my name on their badge', 'cmp' ), (int) $lock->kh_show );
		$html .= '<p class="cmp-muted">' . esc_html( (int) $lock->show_profile ? __( 'Their badge is on.', 'cmp' ) : __( 'Their badge is off: nothing shows on either profile until they turn it on.', 'cmp' ) ) . '</p>';
		$html .= '<button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Save rules', 'cmp' ) . '</button></form>';
		if ( 'permission' === $lock->release_policy && ! (int) $lock->release_allowed ) {
			$html .= self::form_open( 'allow_release', $lock->id, false, 'cmp-inline-form' ) . '<button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Allow one release', 'cmp' ) . '</button></form>';
		}
		$html .= '</section>';

		$html .= self::history_html( $lock, true );
		$html .= '<section class="cmp-panel">' . self::form_open( 'kh_unlock', $lock->id ) . '<button type="submit" class="cmp-btn" data-cmp-confirm="' . esc_attr__( 'Unlock them and end this lock?', 'cmp' ) . '">' . esc_html__( 'Unlock now', 'cmp' ) . '</button></form></section>';
		return $html;
	}

	/** @param bool $hide_length Leave out lengths (the wearer, while the time left is hidden). */
	private static function event_text( $e, $hide_length = false ) {
		$lengths = self::lengths();
		$len     = isset( $lengths[ (int) $e->minutes ] ) ? $lengths[ (int) $e->minutes ] : '';
		switch ( $e->type ) {
			case 'locked':
				return sprintf( /* translators: %s: option */ __( 'Locked: %s', 'cmp' ), $e->note ) . ( $len && ! $hide_length ? ' · ' . $len : '' );
			case 'proposed':
				return __( 'Keyholder set up this lock', 'cmp' ) . ( $len && ! $hide_length ? ' · ' . $len : '' );
			case 'verification':
				return sprintf( /* translators: %s: code */ __( 'Verification photo (code %s)', 'cmp' ), $e->code );
			case 'verified_else':
				return __( 'Verified elsewhere (marked by the keyholder; no photo kept)', 'cmp' );
			case 'hygiene_open':
				return __( 'Opened for hygiene', 'cmp' );
			case 'hygiene_close':
				return sprintf( /* translators: %d: minutes */ __( 'Relocked after %d minutes', 'cmp' ), (int) $e->minutes ) . ( 'over' === $e->review ? ' · ' . __( 'longer than allowed', 'cmp' ) : '' );
			case 'release':
				return __( 'Release logged', 'cmp' ) . ( 'against' === $e->review ? ' · ' . __( 'not allowed by the rules', 'cmp' ) : '' );
			case 'release_allowed':
				return __( 'One release allowed', 'cmp' );
			case 'unlock_asked':
				return __( 'Asked to be unlocked', 'cmp' );
			case 'time_added':
				return sprintf( /* translators: %s: length */ __( 'Keyholder added %s', 'cmp' ), $len );
			case 'time_removed':
				return sprintf( /* translators: %s: length */ __( 'Keyholder removed %s', 'cmp' ), $len );
			case 'rules':
				return __( 'Rules changed', 'cmp' );
			case 'keyholder_left':
				return __( 'Dynamic ended; now a self-lock', 'cmp' );
			case 'unlocked':
				return 'admin' === $e->note ? __( 'Ended by the site team', 'cmp' ) : __( 'Unlocked', 'cmp' );
			case 'admin_fix':
				/* translators: %s: what changed */
				return sprintf( __( 'Corrected by the site team: %s', 'cmp' ), $e->note );
		}
		return $e->type;
	}

	/** The wearer doesn't see time changes while the timer is hidden. */
	private static function history_html( $lock, $as_keyholder ) {
		$html = '<section class="cmp-panel cmp-cl-history"><h3 class="cmp-panel-title">' . esc_html__( 'History', 'cmp' ) . '</h3><ul>';
		foreach ( self::events( $lock->id, 20 ) as $e ) {
			if ( ! $as_keyholder && $lock->hide_timer && ( in_array( $e->type, array( 'time_added', 'time_removed' ), true ) || ( 'admin_fix' === $e->type && 'end' === $e->review ) ) ) {
				continue;
			}
			// 0.28.0: a "verified elsewhere" mark is for a day, not a time.
			$when  = wp_date( 'verified_else' === $e->type ? 'M j' : 'M j, g:i A', self::ts( $e->created_at ) );
			$html .= '<li><time>' . esc_html( $when ) . '</time> ' . esc_html( self::event_text( $e, ! $as_keyholder && $lock->hide_timer ) ) . ( $e->note && in_array( $e->type, array( 'release', 'unlock_asked', 'verified_else' ), true ) ? ' · “' . esc_html( $e->note ) . '”' : '' ) . '</li>';
		}
		return $html . '</ul></section>';
	}

	/** This month: days with any time locked, and verified days (a photo, or 0.28.0 "verified elsewhere"). */
	private static function calendar_html( $user_id ) {
		global $wpdb;
		$tz     = wp_timezone();
		$first  = new DateTime( wp_date( 'Y-m-01' ) . ' 00:00:00', $tz );
		$next   = ( clone $first )->modify( '+1 month' );
		$utc    = new DateTimeZone( 'UTC' );
		$from   = ( clone $first )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$to     = ( clone $next )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$locks  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, started_at, ended_at, status FROM ' . self::t( 'locks' ) . ' WHERE wearer_id = %d AND status IN (\'locked\',\'ended\') AND started_at < %s AND ( ended_at IS NULL OR ended_at >= %s )', $user_id, $to, $from ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $locks ) {
			return '';
		}
		$ids      = implode( ',', array_map( 'intval', wp_list_pluck( $locks, 'id' ) ) );
		$verified = array();
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT created_at FROM ' . self::t( 'lock_events' ) . " WHERE lock_id IN ($ids) AND type IN ('verification','verified_else') AND created_at BETWEEN %s AND %s", $from, $to ) ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$verified[ wp_date( 'Y-m-d', self::ts( $c ) ) ] = true;
		}
		$today = wp_date( 'Y-m-d' );
		$lead  = (int) $first->format( 'N' ) - 1;
		$html  = '<section class="cmp-panel cmp-hw-month"><h3 class="cmp-panel-title">' . esc_html( wp_date( 'F Y' ) ) . '</h3><div class="cmp-hw-heat cmp-cl-cal" role="img" aria-label="' . esc_attr__( 'Days locked this month', 'cmp' ) . '">';
		for ( $i = 0; $i < $lead; $i++ ) {
			$html .= '<span class="is-blank"></span>';
		}
		for ( $day = clone $first; $day < $next; $day->modify( '+1 day' ) ) {
			$start  = $day->getTimestamp();
			$end    = ( clone $day )->modify( '+1 day' )->getTimestamp();
			$locked = false;
			foreach ( $locks as $l ) {
				$ls = self::ts( $l->started_at );
				$le = 'locked' === $l->status ? time() : self::ts( $l->ended_at );
				if ( $ls < $end && $le >= $start && $start <= time() ) {
					$locked = true;
					break;
				}
			}
			$ymd   = $day->format( 'Y-m-d' );
			$class = ( $locked ? 'is-locked' : '' ) . ( isset( $verified[ $ymd ] ) ? ' is-verified' : '' ) . ( $ymd === $today ? ' is-today' : '' );
			$html .= '<span class="' . esc_attr( trim( $class ) ) . '" title="' . esc_attr( $ymd ) . '">' . (int) $day->format( 'j' ) . '</span>';
		}
		return $html . '</div><p class="cmp-muted cmp-cl-legend"><span class="is-locked"></span> ' . esc_html__( 'Locked', 'cmp' ) . ' <span class="is-verified"></span> ' . esc_html__( 'Verified', 'cmp' ) . '</p></section>';
	}

	/** Small line icons for the profile badges (0.25.0), drawn in the badge's text colour. */
	private static function icon( $kind ) {
		$paths = array(
			'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/><circle cx="12" cy="16" r="1.2" fill="currentColor"/>',
			'key'  => '<circle cx="7.5" cy="15.5" r="4"/><path d="M10.5 12.5 20 3"/><path d="M16 7l3 3"/><path d="M14 9l2 2"/>',
		);
		return '<svg class="cmp-cl-icon-svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $kind ] . '</svg>';
	}

	/** The wearer's badge switch, saying what it will show (0.29.0: including when it was last verified). */
	private static function badge_label( $lock ) {
		return $lock->keyholder_id && (int) $lock->kh_show
			/* translators: %s: keyholder */
			? sprintf( __( 'Show "Locked by %s · N days · last verified" on my profile (their profile shows they hold your key)', 'cmp' ), self::name( $lock->keyholder_id ) )
			: __( 'Show "Locked · N days · last verified" on my profile', 'cmp' );
	}

	/**
	 * 0.29.0: the latest verification that counts as proof, for the badge.
	 * With a keyholder on COL&B: a photo they accepted, or a day they marked
	 * "verified elsewhere". Without one (self-lock, or a keyholder with no
	 * account): any photo the wearer sent that wasn't sent back.
	 *
	 * @return object|null  { created_at, type, note }
	 */
	public static function last_verified( $lock ) {
		global $wpdb;
		$photo = $lock->keyholder_id ? "( type = 'verification' AND review = 'accepted' )" : "( type = 'verification' AND review <> 'again' )";
		return $wpdb->get_row( $wpdb->prepare( 'SELECT created_at, type, note FROM ' . self::t( 'lock_events' ) . " WHERE lock_id = %d AND ( type = 'verified_else' OR $photo ) ORDER BY created_at DESC, id DESC LIMIT 1", $lock->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** 0.29.0: "last verified Oct 13 · photo" / "… · elsewhere (Snapchat)", escaped; '' when never verified. */
	private static function last_verified_text( $lock ) {
		$v = self::last_verified( $lock );
		if ( ! $v ) {
			return '';
		}
		$ts  = self::ts( $v->created_at );
		$day = wp_date( 'Y-m-d', $ts );
		$when = wp_date( 'Y-m-d' ) === $day ? __( 'today', 'cmp' ) : ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $day ? __( 'yesterday', 'cmp' ) : wp_date( 'M j', $ts ) );
		if ( 'verified_else' === $v->type ) {
			$where = trim( (string) $v->note );
			$how   = '' === $where
				? __( 'elsewhere', 'cmp' )
				/* translators: %s: where it was sent, as the keyholder typed it */
				: sprintf( __( 'elsewhere (%s)', 'cmp' ), mb_strlen( $where ) > 40 ? mb_substr( $where, 0, 39 ) . '…' : $where );
		} else {
			$how = __( 'photo', 'cmp' );
		}
		/* translators: 1: "today", "yesterday" or a date, 2: how ("photo" or "elsewhere (Snapchat)") */
		return esc_html( sprintf( __( 'last verified %1$s · %2$s', 'cmp' ), $when, $how ) );
	}

	/** A member named on a badge: linked, unless the viewer and they are in a block. */
	private static function badge_name( $user_id, $viewer ) {
		if ( ! $user_id || ! get_userdata( $user_id ) || ( $viewer && CMP_Messages::is_blocked( $viewer, $user_id ) ) ) {
			return '';
		}
		return '<a href="' . esc_url( CMP_Profiles::member_url( $user_id ) ) . '">' . esc_html( self::name( $user_id ) ) . '</a>';
	}

	/**
	 * Profile badges (0.25.0). Only when the wearer turned their badge on.
	 * Wearer: "Locked by <keyholder> · N days" (the name only when the
	 * keyholder allows it). Keyholder: "Holds the key for <wearer> · N days"
	 * for each such lock, when they allow it. 0.29.0: both add "· last
	 * verified <day> · photo|elsewhere (<where>)" once there's proof.
	 */
	public static function profile_badge_html( $owner_id ) {
		global $wpdb;
		$viewer = get_current_user_id();
		$out    = '';
		$lock   = self::active_lock( $owner_id );
		if ( $lock && (int) $lock->show_profile ) {
			$days = intdiv( self::locked_seconds( $lock ), DAY_IN_SECONDS );
			$kh   = $lock->keyholder_id && (int) $lock->kh_show ? self::badge_name( (int) $lock->keyholder_id, $viewer ) : '';
			$text = $kh
				/* translators: 1: keyholder link, 2: days */
				? sprintf( _n( 'Locked by %1$s · %2$d day', 'Locked by %1$s · %2$d days', $days, 'cmp' ), $kh, $days )
				/* translators: %d: days */
				: esc_html( sprintf( _n( 'Locked · %d day', 'Locked · %d days', $days, 'cmp' ), $days ) );
			$ver  = self::last_verified_text( $lock );
			$out .= '<p class="cmp-cl-badge">' . self::icon( 'lock' ) . '<span>' . wp_kses( $text, array( 'a' => array( 'href' => true ) ) ) . ( $ver ? ' · ' . $ver : '' ) . '</span></p>';
		}
		$held = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'locks' ) . " WHERE keyholder_id = %d AND status = 'locked' AND show_profile = 1 AND kh_show = 1 ORDER BY started_at", $owner_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $held as $h ) {
			$who = self::badge_name( (int) $h->wearer_id, $viewer );
			if ( ! $who ) {
				continue;
			}
			$days = intdiv( self::locked_seconds( $h ), DAY_IN_SECONDS );
			/* translators: 1: wearer link, 2: days */
			$text = sprintf( _n( 'Holds the key for %1$s · %2$d day', 'Holds the key for %1$s · %2$d days', $days, 'cmp' ), $who, $days );
			$ver  = self::last_verified_text( $h );
			$out .= '<p class="cmp-cl-badge is-key">' . self::icon( 'key' ) . '<span>' . wp_kses( $text, array( 'a' => array( 'href' => true ) ) ) . ( $ver ? ' · ' . $ver : '' ) . '</span></p>';
		}
		return $out ? '<div class="cmp-cl-badges">' . $out . '</div>' : '';
	}

	/* ------------------------------------------------------------------
	 * Privacy
	 * ---------------------------------------------------------------- */

	public static function export_rows( $user_id ) {
		global $wpdb;
		$rows  = array();
		$locks = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'locks' ) . ' WHERE wearer_id = %d OR keyholder_id = %d ORDER BY id', $user_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $locks as $l ) {
			$rows[] = array( 'name' => __( 'Chastity lock', 'cmp' ), 'value' => sprintf( '%s · %s · %s – %s · %s', (int) $l->wearer_id === (int) $user_id ? ( '' !== (string) $l->keyholder_name && ! $l->keyholder_id ? sprintf( /* translators: %s: keyholder name */ __( 'you wore it; keyholder (no account): %s', 'cmp' ), $l->keyholder_name ) : __( 'you wore it', 'cmp' ) ) : __( 'you were keyholder', 'cmp' ), $l->option_name, $l->started_at, $l->ended_at ? $l->ended_at : __( 'now', 'cmp' ), $l->status ) );
			if ( (int) $l->wearer_id === (int) $user_id ) {
				foreach ( array_reverse( self::events( $l->id, 1000 ) ) as $e ) {
					$rows[] = array( 'name' => __( 'Lock history', 'cmp' ), 'value' => $e->created_at . ' · ' . self::event_text( $e ) . ( $e->photo_sha ? ' · ' . __( '(photo kept)', 'cmp' ) : '' ) );
				}
			}
		}
		return $rows;
	}

	/** The wearer's locks go entirely; as keyholder, locks become self-locks. */
	public static function erase( $user_id ) {
		global $wpdb;
		$n = 0;
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'locks' ) . ' WHERE wearer_id = %d', $user_id ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$n += (int) $wpdb->delete( self::t( 'lock_events' ), array( 'lock_id' => $id ), array( '%d' ) );
			$n += (int) $wpdb->delete( self::t( 'locks' ), array( 'id' => $id ), array( '%d' ) );
		}
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'locks' ) . " WHERE keyholder_id = %d AND status = 'proposed'", $user_id ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			self::delete_lock( (int) $id );
			$n++;
		}
		$n += (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'locks' ) . ' SET keyholder_id = 0, hide_timer = 0, release_allowed = 0 WHERE keyholder_id = %d', $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->update( self::t( 'lock_events' ), array( 'actor_id' => 0 ), array( 'actor_id' => $user_id ), array( '%d' ), array( '%d' ) );
		return $n;
	}
}
