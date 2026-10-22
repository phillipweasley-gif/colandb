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
 * - 0.30.0: a wearer whose lock is running without a member keyholder
 *   (a self-lock, or a keyholder without an account) can hand it to a
 *   member who holds their key in a dynamic. Only the wearer can do this,
 *   so nobody takes over a lock without the wearer choosing it. From then
 *   on it's an ordinary keyholder lock, and the keyholder can also set the
 *   end date directly (or make it open-ended), besides adding or removing
 *   time.
 * - 0.31.0: photos are encrypted in the database (CMP_Photo_Vault) and
 *   deleted 90 days after they're taken (CMP_Retention; the history entry
 *   stays, marked "photo removed"). The wearer sees their photos on the
 *   Chastity tab and can make a private link to show one to someone without
 *   an account (CMP_Photo_Shares). The daily code is not an access code.
 */
class CMP_Chastity {

	const TAB         = 'chastity';
	const NONCE       = 'cmp_chastity';
	const PHOTO_QUERY = 'cmp_lockpic';
	const CODE_CHARS  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	const PURGED      = 'purged';
	const DELETED     = 'deleted';
	const META_SHOW_STARS = 'cmp_cl_show_stars'; // 0.36.0: longest run and Locktober awards on the profile.
	const RUN_GAP     = DAY_IN_SECONDS;      // 0.36.0: a new lock within a day of the last one continues the run.
	const LATE_START  = 2 * DAY_IN_SECONDS;  // 0.36.0: "I locked earlier", up to 48 hours back.
	const UNDO_WINDOW = HOUR_IN_SECONDS;     // 0.36.0: undo an end made by mistake.

	/** 0.33.0: the photo just posted is an end-to-end envelope. */
	private static $posted_e2e = false;
	const PHOTO_DAYS  = 90;

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
			'cl_pending_lock' => array( 'error', __( 'They already have a lock running. They can hand it to you from the Settings of their lock on the Chastity tab, and then you can change its end date.', 'cmp' ) ),
			'cl_kh_set'   => array( 'success', __( 'Your keyholder now holds this lock. They\'ve been told.', 'cmp' ) ),
			'cl_verified_day' => array( 'error', __( 'That day already counts as verified, or has a photo waiting for you to review.', 'cmp' ) ),
			'cl_shared'   => array( 'success', __( 'Link made. Copy it below and send it to the person you want to show. Anyone with the link can see the photo until it stops working.', 'cmp' ) ),
			'cl_e2e_ready' => CMP_E2E::notices()['cl_e2e_ready'],
			'cl_e2e_rewrapped' => CMP_E2E::notices()['cl_e2e_rewrapped'],
			'cl_e2e_reset' => CMP_E2E::notices()['cl_e2e_reset'],
			'cl_e2e_bad'  => CMP_E2E::notices()['cl_e2e_bad'],
			'cl_e2e_needed' => CMP_E2E::notices()['cl_e2e_needed'],
			'cl_e2e_lockphoto' => CMP_E2E::notices()['cl_e2e_lockphoto'],
			'cl_announced' => array( 'success', __( 'Posted to the member feed. Congratulations!', 'cmp' ) ),
			'cl_undone'   => array( 'success', __( 'Undone. Your lock carries on as if it never ended.', 'cmp' ) ),
			'cl_began'    => array( 'error', __( 'That start time can\'t be used: it must be within the last 48 hours, and not before your last lock ended.', 'cmp' ) ),
			'cl_consent'  => array( 'error', __( 'Nothing was saved. To send a photo, tick the box to agree to how it\'s kept.', 'cmp' ) ),
			'cl_photo_deleted' => array( 'success', __( 'Photo deleted. Any links to it have stopped working.', 'cmp' ) ),
			'cl_unshared' => array( 'success', __( 'Link turned off. It no longer shows the photo.', 'cmp' ) ),
			'cl_share_max'=> array( 'error', __( 'You have 20 working links already. Turn some off first.', 'cmp' ) ),
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
		CMP_Photo_Shares::delete_for_lock( $lock_id );
		$wpdb->delete( self::t( 'lock_events' ), array( 'lock_id' => $lock_id ), array( '%d' ) );
		$wpdb->delete( self::t( 'locks' ), array( 'id' => $lock_id ), array( '%d' ) );
	}

	public static function past_locks( $wearer_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'locks' ) . " WHERE wearer_id = %d AND status = 'ended' ORDER BY id DESC LIMIT 10", $wearer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function events( $lock_id, $limit = 30 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, lock_id, actor_id, type, minutes, note, photo_sha, photo_e2e, code, review, created_at FROM ' . self::t( 'lock_events' ) . ' WHERE lock_id = %d ORDER BY created_at DESC, id DESC LIMIT %d', $lock_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 0.35.0: by date, so a back-dated correction sits on its own day.
	}

	public static function event( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'lock_events' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function add_event( $lock_id, $actor_id, $type, $extra = array() ) {
		global $wpdb;
		if ( isset( $extra['photo'] ) && is_string( $extra['photo'] ) && '' !== $extra['photo'] ) {
			$lock               = self::lock( $lock_id );
			$extra['photo_sha'] = hash( 'sha256', $extra['photo'] );
			$extra['photo_e2e'] = self::$posted_e2e ? 1 : 0; // 0.33.0: the envelope is sealed as well.
			$extra['photo']     = CMP_Photo_Vault::seal( $extra['photo'], $lock_id, $lock ? $lock->wearer_id : 0 ); // 0.31.0
		}
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

	/** 0.31.0: an event whose photo is still kept (not purged). */
	public static function has_photo( $e ) {
		return '' !== (string) $e->photo_sha && self::PURGED !== $e->photo_sha && self::DELETED !== $e->photo_sha;
	}

	/** 0.31.0: when a photo taken at $created_at (UTC) is deleted. */
	public static function photo_delete_at( $created_at ) {
		return self::ts( $created_at ) + self::photo_days() * DAY_IN_SECONDS;
	}

	public static function photo_days() {
		$p = CMP_Retention::periods();
		return isset( $p['lock_photos'] ) ? max( 1, (int) $p['lock_photos'] ) : self::PHOTO_DAYS;
	}

	/**
	 * 0.31.0, daily: delete photos older than $days. The history entry
	 * stays (a verification still counts) and says the photo was removed.
	 *
	 * @return int Photos removed.
	 */
	public static function purge_photos( $days ) {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'lock_events' ) . ' SET photo = NULL, photo_sha = %s WHERE photo IS NOT NULL AND created_at < %s', self::PURGED, gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** 0.31.0: the wearer's photos still kept, across their locks, newest first. */
	public static function wearer_photos( $wearer_id, $limit = 30 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT e.id, e.lock_id, e.type, e.photo_sha, e.photo_e2e, e.code, e.review, e.created_at FROM ' . self::t( 'lock_events' ) . ' e JOIN ' . self::t( 'locks' ) . ' l ON l.id = e.lock_id WHERE l.wearer_id = %d AND e.photo IS NOT NULL ORDER BY e.id DESC LIMIT %d', $wearer_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
	 * sent their verification outside COL&B), never before the lock
	 * started. Site dates, newest first. 0.35.0: any day of the lock, not
	 * just the last week (owner: "someone may have missed a different date
	 * two weeks ago").
	 */
	public static function elsewhere_days( $lock ) {
		$tz    = wp_timezone();
		$start = wp_date( 'Y-m-d', self::ts( $lock->started_at ) );
		$out   = array();
		for ( $i = 0; $i < 3700; $i++ ) {
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
		// 0.31.1: the host's CDN adds a "cache publicly" hint for itself
		// (ec-cdn-cache-control) to every page; a private photo must never have it.
		header_remove( 'ec-cdn-cache-control' );
		$event  = self::event( absint( $_GET[ self::PHOTO_QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lock   = $event ? self::lock( $event->lock_id ) : null;
		$viewer = get_current_user_id();
		$photo  = $event && $lock && $viewer && ( self::is_wearer( $lock, $viewer ) || self::is_keyholder( $lock, $viewer ) ) ? CMP_Photo_Vault::open( $event->photo, $lock->id, $lock->wearer_id ) : null;
		if ( null === $photo ) {
			nocache_headers();
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Not found';
			exit;
		}
		header( 'Cache-Control: private, no-cache, max-age=0' );
		header( 'ETag: "' . $event->photo_sha . '"' );
		status_header( 200 );
		if ( (int) $event->photo_e2e ) {
			// 0.33.0: an envelope only the wearer's and keyholder's browsers can open.
			header( 'Content-Type: application/octet-stream' );
			header( 'X-CMP-E2E: 1' );
			header( 'Content-Length: ' . strlen( $photo ) );
			header( 'Content-Disposition: attachment; filename="lock-' . (int) $event->id . '.cmpe2e"' );
			header( "Content-Security-Policy: default-src 'none'" );
			echo $photo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- encrypted bytes.
			exit;
		}
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . strlen( $photo ) );
		header( 'Content-Disposition: ' . ( isset( $_GET['dl'] ) ? 'attachment' : 'inline' ) . '; filename="' . ( isset( $_GET['dl'] ) ? 'colandb-lock-' . gmdate( 'Y-m-d', self::ts( $event->created_at ) ) . '-' . (int) $event->id : 'lock' ) . '.jpg"' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		header( "Content-Security-Policy: default-src 'none'" );
		echo $photo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JPEG bytes.
		exit;
	}

	/** An uploaded photo field: null when none was sent, JPEG bytes, or a redirect on error. */
	private static function posted_photo( $back, $lock = null ) {
		$file = isset( $_FILES['photo'] ) && is_array( $_FILES['photo'] ) ? $_FILES['photo'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing
		if ( ! $file || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			return null;
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::go( $back, 'cl_photo' );
		}
		if ( ! CMP_Photo_Consent::check( get_current_user_id() ) ) {
			self::go( $back, 'cl_consent' ); // 0.32.0
		}
		// 0.33.0: with end-to-end on, only an envelope made in the browser is accepted.
		if ( CMP_E2E::is_on( get_current_user_id() ) ) {
			if ( ! $lock ) {
				self::go( $back, 'cl_e2e_lockphoto' );
			}
			$bytes = empty( $_POST['e2e'] ) ? '' : (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.WP.AlternativeFunctions
			if ( ! CMP_E2E::validate( $bytes, $lock ) ) {
				self::go( $back, 'cl_e2e_needed' );
			}
			self::$posted_e2e = true;
			return $bytes;
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
		if ( 'announce' === $do ) {
			self::go( array( 'share' => 'star' ), CMP_Star_News::announce( $user_id ) ? 'cl_announced' : 'cl_invalid' ); // 0.37.0
		}
		if ( 'stars_show' === $do ) {
			// 0.38.0: hidden ('0'), stars and labels ('1'), icons only ('icons'), or "Not now" on the prompt.
			$show = isset( $_POST['show'] ) ? sanitize_key( wp_unslash( $_POST['show'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( 'later' === $show ) {
				update_user_meta( $user_id, CMP_Star_News::META_PROMPT, CMP_Star_News::prompt_sig( $user_id ) );
			} elseif ( in_array( $show, array( '0', '1', 'icons' ), true ) ) {
				update_user_meta( $user_id, self::META_SHOW_STARS, $show );
				update_user_meta( $user_id, CMP_Star_News::META_PROMPT, CMP_Star_News::prompt_sig( $user_id ) );
			}
			self::go( array(), 'cl_saved' );
		}
		if ( 'undo_end' === $do ) {
			self::do_undo_end( self::lock( self::posted_int( 'lock' ) ), $user_id ); // 0.36.0
		}
		if ( 0 === strpos( $do, 'e2e_' ) ) {
			CMP_E2E::handle( $do, $user_id ); // 0.33.0
		}
		if ( 'share' === $do || 'unshare' === $do || 'unphoto' === $do ) {
			self::do_share( $do, $user_id ); // 0.31.0: any of the wearer's own locks, running or past.
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
		$wearer_actions    = array( 'verify', 'hygiene_open', 'hygiene_close', 'release', 'ask_unlock', 'unlock', 'emergency', 'wearer_settings', 'assign_keyholder' );
		$keyholder_actions = array( 'time', 'end_date', 'rules', 'allow_release', 'review', 'verified_else', 'kh_unlock' );
		if ( in_array( $do, $wearer_actions, true ) && self::is_wearer( $lock, $user_id ) ) {
			call_user_func( array( __CLASS__, 'do_' . $do ), $lock, $user_id );
		}
		// 0.36.0: without a member keyholder, the wearer catches up a day they forgot to check in.
		if ( 'verified_else' === $do && self::is_wearer( $lock, $user_id ) && ! $lock->keyholder_id ) {
			self::do_verified_else( $lock, $user_id );
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
		$began = self::posted_start( $user_id ); // 0.36.0: "I locked earlier".
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
				'started_at'      => gmdate( 'Y-m-d H:i:s', $began ),
				'planned_end'     => $length ? gmdate( 'Y-m-d H:i:s', $began + $length * MINUTE_IN_SECONDS ) : null,
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
		$extra   = array( 'minutes' => $length, 'note' => $name, 'created_at' => gmdate( 'Y-m-d H:i:s', $began ), 'review' => $began < time() - 300 ? 'late' : '' );
		if ( $photo ) {
			$extra += array( 'photo' => $photo );
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
		if ( self::proposal_for( $wearer ) ) {
			self::go( array(), 'cl_pending' );
		}
		if ( self::active_lock( $wearer ) ) {
			self::go( array(), 'cl_pending_lock' );
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
		$began   = max( self::posted_start( $user_id ), self::ts( $lock->created_at ) ); // 0.36.0, not before it was set up.
		self::update_lock(
			$lock->id,
			array(
				'status'       => 'locked',
				'started_at'   => gmdate( 'Y-m-d H:i:s', $began ),
				'planned_end'  => $minutes ? gmdate( 'Y-m-d H:i:s', $began + $minutes * MINUTE_IN_SECONDS ) : null,
				'show_profile' => empty( $_POST['show_profile'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			)
		);
		$extra = array( 'minutes' => $minutes, 'note' => $lock->option_name, 'created_at' => gmdate( 'Y-m-d H:i:s', $began ), 'review' => $began < time() - 300 ? 'late' : '' );
		if ( $photo ) {
			$extra += array( 'photo' => $photo );
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
		$photo = self::posted_photo( array(), $lock );
		if ( ! $photo ) {
			self::go( array(), 'cl_photo' );
		}
		self::add_event( $lock->id, $user_id, 'verification', array( 'photo' => $photo, 'code' => self::code( $lock->id ), 'note' => self::text( 'note', 300 ) ) );
		/* translators: %s: wearer */
		self::notify_keyholder( $lock, sprintf( __( '%s added a verification photo.', 'cmp' ), self::me() ) );
		self::go( array(), 'cl_logged' );
	}

	/**
	 * 0.32.0: delete one stored photo now (the wearer, or the site team from
	 * a report without seeing it). The history entry stays: "photo deleted".
	 */
	public static function delete_photo( $event, $by, $actor_id ) {
		global $wpdb;
		$wpdb->update( self::t( 'lock_events' ), array( 'photo' => null, 'photo_sha' => self::DELETED ), array( 'id' => (int) $event->id ) );
		CMP_Photo_Shares::delete_for_event( (int) $event->id );
		CMP_Audit::log( 'lock_photo_deleted', 'lock', (int) $event->lock_id, null, array( 'event' => (int) $event->id, 'by' => $by ), '', $actor_id );
	}

	/** 0.31.0: make or turn off a private link to one of the wearer's photos. */
	private static function do_share( $do, $user_id ) {
		$back = array( 'photos' => 1 );
		if ( 'unshare' === $do ) {
			CMP_Photo_Shares::revoke( self::posted_int( 'share' ), $user_id );
			self::go( $back, 'cl_unshared' );
		}
		$event = self::event( self::posted_int( 'event' ) );
		$lock  = $event ? self::lock( $event->lock_id ) : null;
		if ( ! $event || ! $lock || ! self::is_wearer( $lock, $user_id ) || null === $event->photo ) {
			self::go( $back, 'cl_gone' );
		}
		if ( 'unphoto' === $do ) {
			self::delete_photo( $event, 'wearer', $user_id );
			self::go( $back, 'cl_photo_deleted' );
		}
		$id = CMP_Photo_Shares::create( (int) $event->id, $lock, $user_id, self::posted_int( 'expires' ), ! empty( $_POST['once'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( is_wp_error( $id ) ) {
			self::go( $back, $id->get_error_code() );
		}
		self::go( $back + array( 'shared' => $id ), 'cl_shared' );
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

	/**
	 * 0.30.0: the wearer hands a running lock with no member keyholder (a
	 * self-lock, or a keyholder typed by name because they had no account)
	 * to a member who holds their key in a dynamic. Only the wearer can do
	 * this. The lock keeps its start, end and history; the keyholder gets
	 * the usual controls, the wearer the usual keyholder-lock view (and
	 * Emergency unlock, always).
	 */
	private static function do_assign_keyholder( $lock, $user_id ) {
		$keyholder = self::posted_int( 'keyholder' );
		if ( $lock->keyholder_id || ! $keyholder || ! isset( self::keyholder_choices( $user_id )[ $keyholder ] ) ) {
			self::go( array(), 'cl_invalid' );
		}
		$typed = (string) $lock->keyholder_name;
		self::update_lock( $lock->id, array( 'keyholder_id' => $keyholder, 'keyholder_name' => '', 'release_allowed' => 0 ) );
		self::add_event( $lock->id, $user_id, 'keyholder_set' );
		// As at the start of a lock, a typed name is someone else's detail: the audit only notes there was one.
		CMP_Audit::log( 'lock_keyholder_set', 'lock', $lock->id, array( 'keyholder' => 0, 'named_keyholder' => '' !== $typed ), array( 'keyholder' => $keyholder ) );
		CMP_Notifications::add(
			$keyholder,
			'lock',
			/* translators: 1: wearer, 2: how long they've been locked */
			sprintf( __( '%1$s handed you the key to the lock they started on their own (locked %2$s so far). You can now change its end date and rules.', 'cmp' ), self::me(), self::duration( self::locked_seconds( $lock ) ) ),
			self::url( array( 'lock' => $lock->id ) )
		);
		self::go( array(), 'cl_kh_set' );
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

	/**
	 * 0.30.0: the keyholder sets the end date and time directly (site time),
	 * or makes the lock open-ended. The date must be in the future.
	 */
	private static function do_end_date( $lock, $user_id ) {
		$back = array( 'lock' => $lock->id );
		if ( ! empty( $_POST['open_ended'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! $lock->planned_end ) {
				self::go( $back, 'cl_saved' );
			}
			$end = null;
		} else {
			$raw = isset( $_POST['end_at'] ) ? sanitize_text_field( wp_unslash( $_POST['end_at'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$dt  = preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $raw ) ? DateTime::createFromFormat( '!Y-m-d\TH:i', $raw, wp_timezone() ) : false;
			if ( ! $dt || $dt->format( 'Y-m-d\TH:i' ) !== $raw || $dt->getTimestamp() <= time() || $dt->getTimestamp() > time() + 5 * YEAR_IN_SECONDS ) {
				self::go( $back, 'cl_invalid' );
			}
			$end = gmdate( 'Y-m-d H:i:s', $dt->getTimestamp() );
		}
		self::update_lock( $lock->id, array( 'planned_end' => $end ) );
		self::add_event( $lock->id, $user_id, 'end_set', array( 'note' => (string) $end ) );
		if ( ! $lock->hide_timer ) {
			CMP_Notifications::add(
				(int) $lock->wearer_id,
				'lock',
				$end
					/* translators: 1: keyholder, 2: date and time */
					? sprintf( __( '%1$s set your lock to end %2$s.', 'cmp' ), self::me(), wp_date( 'M j, g:i A', self::ts( $end ) ) )
					/* translators: %s: keyholder */
					: sprintf( __( '%s made your lock open-ended.', 'cmp' ), self::me() ),
				self::url()
			);
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
		$self = (int) $user_id === (int) $lock->wearer_id;
		self::add_event( $lock->id, $user_id, 'verified_else', array( 'note' => self::text( 'note', 200 ), 'created_at' => $at, 'review' => $self ? 'self' : '' ) );
		if ( $self ) {
			self::go( array(), 'cl_saved' ); // 0.36.0: a self-lock wearer catching up a day they forgot.
		}
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
		CMP_Star_News::check( $user_id ); // 0.37.0: announce a new star or medal right away.
		$undo = $lock ? null : self::undoable( $user_id ); // 0.36.0
		if ( $undo ) {
			echo '<section class="cmp-panel cmp-cl-undo"><p><b>' . esc_html__( 'Ended by mistake?', 'cmp' ) . '</b> ' . esc_html( sprintf( /* translators: %s: time */ __( 'Your lock ended at %s. You can undo that until an hour after; your run, stars and history carry on as if it never ended.', 'cmp' ), wp_date( 'g:i A', self::ts( $undo->ended_at ) ) ) ) . '</p>' . self::form_open( 'undo_end', $undo->id ) . '<button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Undo, I\'m still locked', 'cmp' ) . '</button></form></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		echo self::locktober_html( $user_id, $lock ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		$proposal = self::proposal_for( $user_id );
		if ( $proposal ) {
			echo self::proposal_html( $proposal, (bool) $lock ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		if ( $lock ) {
			echo self::wearer_html( $lock ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		} else {
			echo self::start_html( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		if ( ! $lock ) { // 0.35.0: a running lock has its own calendar with stars, beside Verification.
			echo self::calendar_html( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		echo self::my_stars_html( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo CMP_E2E::panel_html( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo self::photos_html( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		?>
		<section class="cmp-panel">
			<h3 class="cmp-panel-title"><?php esc_html_e( 'Locks you hold', 'cmp' ); ?></h3>
			<?php if ( ! $held ) : ?>
				<p class="cmp-empty"><?php esc_html_e( 'When a member whose key you hold (chastity in a dynamic) locks up with you as keyholder, or accepts a lock you set up for them, their lock appears here.', 'cmp' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $held as $h ) : ?>
				<p class="cmp-hw-item"><a href="<?php echo esc_url( self::url( array( 'lock' => $h->id ) ) ); ?>"><b><?php echo esc_html( self::name( $h->wearer_id ) ); ?></b></a> · <?php echo esc_html( $h->option_name ); ?> · <?php echo esc_html( sprintf( /* translators: %s: duration */ __( 'locked %s', 'cmp' ), self::duration( self::locked_seconds( $h ) ) ) ); ?> <?php echo self::stars_html( self::run_for( $h ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></p>
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
					<p class="cmp-hw-item"><?php echo esc_html( wp_date( 'M j', self::ts( $p->started_at ) ) . ' – ' . wp_date( 'M j', self::ts( $p->ended_at ) ) . ' · ' . self::duration( self::locked_seconds( $p ) ) . ' · ' . $p->option_name . ' · ' . ( isset( $reasons[ $p->end_reason ] ) ? $reasons[ $p->end_reason ] : '' ) ); ?> <?php echo self::stars_html( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></p>
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
		$html .= self::start_field_html( 'cmp_cl_began' );
		$html .= '<p class="cmp-field" data-cmp-kh-name><label for="cmp_cl_khn">' . esc_html__( 'Keyholder\'s name (if they don\'t have an account)', 'cmp' ) . '</label><input type="text" id="cmp_cl_khn" name="keyholder_name" maxlength="60" autocomplete="off" aria-describedby="cmp_cl_khn_help" /><span class="cmp-muted" id="cmp_cl_khn_help">' . esc_html__( 'Used when Keyholder is "Someone without an account". It\'s just a name for your record: they can\'t sign in to manage the lock, so it works like a self-lock and you keep control. Once they join and you agree chastity in a dynamic, you can hand them the running lock from its Settings.', 'cmp' ) . '</span></p>';
		$html .= '<details class="cmp-hw-taskedit"><summary>' . esc_html__( 'Custom option', 'cmp' ) . '</summary><p class="cmp-muted">' . esc_html__( 'Used when Option is "Custom".', 'cmp' ) . '</p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_name">' . esc_html__( 'Name', 'cmp' ) . '</label><input type="text" id="cmp_cl_name" name="option_name" maxlength="60" /></p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_rule">' . esc_html__( 'Rule', 'cmp' ) . '</label><input type="text" id="cmp_cl_rule" name="rule" maxlength="300" /></p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_pol">' . esc_html__( 'Release', 'cmp' ) . '</label>' . self::select( 'policy', 'cmp_cl_pol', self::policies(), 'none' ) . '</p></details>';
		if ( ! CMP_E2E::is_on( $user_id ) ) { // 0.33.0: with end-to-end on, photos go in as verifications.
			$html .= '<p class="cmp-field"><label for="cmp_cl_photo">' . esc_html__( 'Photo of the lock (optional)', 'cmp' ) . '</label><input type="file" id="cmp_cl_photo" name="photo" accept="image/jpeg,image/png,image/webp" /></p>' . CMP_Photo_Consent::field_html( $user_id, 'cmp_cl_consent_s' );
		}
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
			$html .= self::form_open( 'accept', $lock->id, true ) . self::start_field_html( 'cmp_cl_abegan' );
			if ( ! CMP_E2E::is_on( $lock->wearer_id ) ) {
				$html .= '<p class="cmp-field"><label for="cmp_cl_aphoto">' . esc_html__( 'Photo of the lock (optional)', 'cmp' ) . '</label><input type="file" id="cmp_cl_aphoto" name="photo" accept="image/jpeg,image/png,image/webp" /></p>' . CMP_Photo_Consent::field_html( $lock->wearer_id, 'cmp_cl_consent_a' );
			}
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
		$html .= self::stars_html( self::run_for( $lock ), true ); // 0.35.0; 0.36.0: the whole run.
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
		$html  .= '<section class="cmp-panel cmp-cl-verify"><div class="cmp-cl-verify-main"><h3 class="cmp-panel-title">' . esc_html__( 'Verification', 'cmp' ) . '</h3>';
		$html  .= '<p>' . esc_html( $lock->verify_daily ? __( 'Every day: a photo of the device with today\'s code written on paper.', 'cmp' ) : __( 'Optional: a photo of the device with today\'s code written on paper.', 'cmp' ) ) . '</p>';
		$html  .= '<p class="cmp-cl-codewrap"><span class="cmp-cl-code" data-cmp-code>' . esc_html( self::code( $lock->id ) ) . '</span></p>';
		if ( $today && 'verified_else' === $today->type ) {
			$html .= '<p class="cmp-muted">' . esc_html__( 'Your keyholder marked today as verified (sent outside COL&B).', 'cmp' ) . '</p>';
		} elseif ( $today ) {
			$state = 'accepted' === $today->review ? __( 'accepted', 'cmp' ) : ( 'again' === $today->review ? __( 'your keyholder asked for another', 'cmp' ) : __( 'sent', 'cmp' ) );
			$html .= '<p class="cmp-muted">' . esc_html( sprintf( /* translators: 1: time, 2: state */ __( 'Today\'s photo: %1$s, %2$s.', 'cmp' ), wp_date( 'g:i A', self::ts( $today->created_at ) ), $state ) ) . '</p>';
		}
		if ( CMP_E2E::is_on( $lock->wearer_id ) ) {
			// 0.33.0: the browser re-saves and encrypts the photo for these people before sending it.
			$rcpt  = CMP_E2E::recipients( $lock );
			$html .= '<p class="cmp-muted cmp-e2e-on">' . esc_html__( 'End-to-end is on: this photo is encrypted on your device before it\'s sent.', 'cmp' ) . '</p>';
			if ( $lock->keyholder_id && ! CMP_E2E::has_key( $lock->keyholder_id ) ) {
				$html .= '<p class="cmp-hw-std">' . esc_html__( 'Your keyholder hasn\'t set up end-to-end photos, so they won\'t be able to see photos you send now. Ask them to set it up on their Chastity tab, or turn end-to-end off for now.', 'cmp' ) . '</p>';
			}
			$html .= str_replace( '<form ', '<form data-cmp-e2e-upload="' . esc_attr( wp_json_encode( $rcpt ) ) . '" ', self::form_open( 'verify', $lock->id, true ) ) . '<p class="cmp-muted" data-cmp-e2e-msg role="alert"></p>';
		} else {
			$html .= self::form_open( 'verify', $lock->id, true );
		}
		$html .= '<p class="cmp-field"><label for="cmp_cl_vphoto">' . esc_html__( 'Photo', 'cmp' ) . '</label><input type="file" id="cmp_cl_vphoto" name="photo" accept="image/jpeg,image/png,image/webp" required /></p>' . CMP_Photo_Consent::field_html( $lock->wearer_id, 'cmp_cl_consent_v', true ) . '<button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Send verification', 'cmp' ) . '</button></form>' . self::catch_up_html( $lock ) . '</div>' . self::verify_calendar_html( $lock, false ) . '</section>';

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
		$html .= '<button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Save', 'cmp' ) . '</button></form>';
		if ( ! $has_kh ) {
			$html .= self::assign_html( $lock );
		}
		$html .= '</section>';

		$html .= self::history_html( $lock, false );

		// Emergency unlock: always there.
		$html .= '<section class="cmp-panel cmp-cl-emergency">' . self::form_open( 'emergency', $lock->id ) . '<button type="submit" class="cmp-btn cmp-btn-outline cmp-btn-danger" data-cmp-confirm="' . esc_attr__( 'End this lock now? Your keyholder will be told.', 'cmp' ) . '">' . esc_html( $has_kh || self::seconds_left( $lock ) ? __( 'Emergency unlock', 'cmp' ) : __( 'End lock', 'cmp' ) ) . '</button></form><p class="cmp-muted">' . esc_html__( 'Always available. Ends the lock now; it\'s recorded and your keyholder is told.', 'cmp' ) . '</p></section>';
		return $html;
	}

	/**
	 * 0.30.0: hand a running self-lock to a member keyholder. Shown to the
	 * wearer only, while the lock has no member keyholder.
	 */
	private static function assign_html( $lock ) {
		$choices = self::keyholder_choices( $lock->wearer_id );
		$html    = '<div class="cmp-cl-assign"><h4>' . esc_html__( 'Give your keyholder this lock', 'cmp' ) . '</h4>';
		if ( ! $choices ) {
			return $html . '<p class="cmp-muted">' . esc_html__( 'Started this lock on your own because your keyholder wasn\'t on COL&B yet? Once they join and you agree chastity with them in a dynamic (them holding the key), you can hand them this lock here. It keeps its start date and history.', 'cmp' ) . '</p></div>';
		}
		$html .= self::form_open( 'assign_keyholder', $lock->id );
		$html .= '<p class="cmp-muted">' . esc_html__( 'Your lock keeps its start date and history. Your keyholder can then change the end date, hide the time left and set the rules, and you\'ll ask them to unlock you. Emergency unlock stays available to you.', 'cmp' ) . '</p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_assign">' . esc_html__( 'Keyholder', 'cmp' ) . '</label>' . self::select( 'keyholder', 'cmp_cl_assign', $choices, (string) key( $choices ) ) . '</p>';
		$html .= '<button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline" data-cmp-confirm="' . esc_attr__( 'Hand this lock to your keyholder? They\'ll control its end date and rules. You can still use Emergency unlock.', 'cmp' ) . '">' . esc_html__( 'Hand over the key', 'cmp' ) . '</button></form>';
		return $html . '</div>';
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
		$html .= '<div class="cmp-actions"><button type="submit" name="direction" value="add" class="cmp-btn cmp-btn-small">' . esc_html__( 'Add time', 'cmp' ) . '</button>' . ( $lock->planned_end ? '<button type="submit" name="direction" value="remove" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Remove time', 'cmp' ) . '</button>' : '' ) . '</div></form>';
		// 0.30.0: set the end date directly.
		$end_val = $lock->planned_end ? wp_date( 'Y-m-d\TH:i', self::ts( $lock->planned_end ) ) : '';
		$html   .= '<details class="cmp-hw-taskedit cmp-cl-enddate"><summary>' . esc_html__( 'Set the end date', 'cmp' ) . '</summary>' . self::form_open( 'end_date', $lock->id );
		$html   .= '<p class="cmp-field"><label for="cmp_cl_endat">' . esc_html__( 'Ends', 'cmp' ) . '</label><input type="datetime-local" id="cmp_cl_endat" name="end_at" value="' . esc_attr( $end_val ) . '" min="' . esc_attr( wp_date( 'Y-m-d\TH:i' ) ) . '" aria-describedby="cmp_cl_endat_help" /><span class="cmp-muted" id="cmp_cl_endat_help">' . esc_html( sprintf( /* translators: %s: timezone */ __( 'Site time (%s).', 'cmp' ), wp_timezone_string() ) ) . '</span></p>';
		$html   .= '<div class="cmp-actions"><button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Set end date', 'cmp' ) . '</button>' . ( $lock->planned_end ? '<button type="submit" name="open_ended" value="1" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Make it open-ended', 'cmp' ) . '</button>' : '' ) . '</div></form></details></section>';

		// 0.33.0: unlock end-to-end photos here too.
		$html .= CMP_E2E::panel_html( get_current_user_id(), 'keyholder' );

		// Verification photos waiting.
		$events = self::events( $lock->id, 40 );
		$html  .= '<section class="cmp-panel cmp-cl-verify"><div class="cmp-cl-verify-main"><h3 class="cmp-panel-title">' . esc_html( sprintf( /* translators: %s: code */ __( 'Verification · today\'s code %s', 'cmp' ), self::code( $lock->id ) ) ) . '</h3>';
		$shown  = 0;
		foreach ( $events as $e ) {
			if ( 'verification' !== $e->type || $shown >= 3 ) {
				continue;
			}
			$shown++;
			$html .= '<div class="cmp-hw-proof"><p><b>' . esc_html( wp_date( 'D M j, g:i A', self::ts( $e->created_at ) ) ) . '</b> · ' . esc_html( sprintf( /* translators: %s: code */ __( 'code %s', 'cmp' ), $e->code ) ) . ( $e->review ? ' · ' . esc_html( 'accepted' === $e->review ? __( 'accepted', 'cmp' ) : __( 'asked for another', 'cmp' ) ) : '' ) . '</p>';
			if ( self::PURGED === $e->photo_sha ) {
				$html .= '<p class="cmp-muted">' . esc_html( sprintf( /* translators: %d: days */ __( 'Photo removed after %d days.', 'cmp' ), self::photo_days() ) ) . '</p>';
			} elseif ( self::DELETED === $e->photo_sha ) {
				$html .= '<p class="cmp-muted">' . esc_html__( 'Photo deleted.', 'cmp' ) . '</p>';
			} elseif ( self::has_photo( $e ) ) {
				$html .= self::img_html( $e, __( 'Verification photo', 'cmp' ) );
			}
			$html .= self::form_open( 'review', $lock->id ) . '<input type="hidden" name="event" value="' . (int) $e->id . '" /><div class="cmp-actions"><button type="submit" name="verdict" value="accepted" class="cmp-btn cmp-btn-small">' . esc_html__( 'Accept', 'cmp' ) . '</button><button type="submit" name="verdict" value="again" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Ask for another', 'cmp' ) . '</button></div></form></div>';
		}
		if ( ! $shown ) {
			$html .= '<p class="cmp-empty">' . esc_html__( 'No verification photos yet.', 'cmp' ) . '</p>';
		}
		$days = self::elsewhere_days( $lock );
		if ( $days ) {
			// 0.35.0: any day of the lock, picked with a date field or from the calendar.
			$pick  = isset( $_GET['vday'] ) && isset( $days[ sanitize_text_field( wp_unslash( $_GET['vday'] ) ) ] ) ? sanitize_text_field( wp_unslash( $_GET['vday'] ) ) : (string) key( $days ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$html .= '<details class="cmp-hw-taskedit cmp-cl-elsewhere" id="cmp-cl-elsewhere"' . ( isset( $_GET['vday'] ) ? ' open' : '' ) . '><summary>' . esc_html__( 'Mark a day verified', 'cmp' ) . '</summary>' . self::form_open( 'verified_else', $lock->id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$html .= '<p class="cmp-muted">' . esc_html__( 'Did they send you their verification another way, like Telegram or a text, or was a day missed by mistake? Pick any day of this lock, or tap a day without a star on the calendar, so it counts. No photo is kept.', 'cmp' ) . '</p>';
			$html .= '<p class="cmp-field"><label for="cmp_cl_vday">' . esc_html__( 'Day', 'cmp' ) . '</label><input type="date" id="cmp_cl_vday" name="day" value="' . esc_attr( $pick ) . '" min="' . esc_attr( (string) array_key_last( $days ) ) . '" max="' . esc_attr( (string) key( $days ) ) . '" required /></p>';
			$html .= '<p class="cmp-field"><label for="cmp_cl_vnote">' . esc_html__( 'Where (optional)', 'cmp' ) . '</label><input type="text" id="cmp_cl_vnote" name="note" maxlength="200" placeholder="' . esc_attr__( 'e.g. Telegram', 'cmp' ) . '" /></p>';
			$html .= '<button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Mark verified', 'cmp' ) . '</button></form></details>';
		}
		$html .= '</div>' . self::verify_calendar_html( $lock, true ) . '</section>';

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
		$text = self::event_text_base( $e, $hide_length );
		if ( isset( $e->photo_sha ) && self::DELETED === $e->photo_sha ) {
			return $text . ' · ' . __( 'photo deleted', 'cmp' );
		}
		return isset( $e->photo_sha ) && self::PURGED === $e->photo_sha ? $text . ' · ' . sprintf( /* translators: %d: days */ __( 'photo removed after %d days', 'cmp' ), self::photo_days() ) : $text;
	}

	private static function event_text_base( $e, $hide_length = false ) {
		$lengths = self::lengths();
		$len     = isset( $lengths[ (int) $e->minutes ] ) ? $lengths[ (int) $e->minutes ] : '';
		switch ( $e->type ) {
			case 'locked':
				return sprintf( /* translators: %s: option */ __( 'Locked: %s', 'cmp' ), $e->note ) . ( $len && ! $hide_length ? ' · ' . $len : '' ) . ( 'late' === $e->review ? ' · ' . __( 'logged later', 'cmp' ) : '' );
			case 'proposed':
				return __( 'Keyholder set up this lock', 'cmp' ) . ( $len && ! $hide_length ? ' · ' . $len : '' );
			case 'verification':
				return sprintf( /* translators: %s: code */ __( 'Verification photo (code %s)', 'cmp' ), $e->code );
			case 'verified_else':
				return 'self' === $e->review ? __( 'Checked in late (self-reported; no photo kept)', 'cmp' ) : __( 'Verified elsewhere (marked by the keyholder; no photo kept)', 'cmp' );
			case 'end_undone':
				return __( 'Ending undone (it was a mistake); the lock carries on', 'cmp' );
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
			case 'keyholder_set':
				return __( 'Lock handed to a keyholder', 'cmp' );
			case 'end_set':
				return $hide_length ? __( 'Keyholder changed the end', 'cmp' ) : ( $e->note ? sprintf( /* translators: %s: date and time */ __( 'Keyholder set the end to %s', 'cmp' ), wp_date( 'M j, g:i A', self::ts( $e->note ) ) ) : __( 'Keyholder made the lock open-ended', 'cmp' ) );
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
			if ( ! $as_keyholder && $lock->hide_timer && ( in_array( $e->type, array( 'time_added', 'time_removed', 'end_set' ), true ) || ( 'admin_fix' === $e->type && 'end' === $e->review ) ) ) {
				continue;
			}
			// 0.28.0: a "verified elsewhere" mark is for a day, not a time.
			$when  = wp_date( 'verified_else' === $e->type ? 'M j' : 'M j, g:i A', self::ts( $e->created_at ) );
			$html .= '<li><time>' . esc_html( $when ) . '</time> ' . esc_html( self::event_text( $e, ! $as_keyholder && $lock->hide_timer ) ) . ( $e->note && in_array( $e->type, array( 'release', 'unlock_asked', 'verified_else' ), true ) ? ' · “' . esc_html( $e->note ) . '”' : '' ) . '</li>';
		}
		return $html . '</ul></section>';
	}

	/**
	 * 0.31.0: the wearer's own photos (closed by default, so nothing intimate
	 * shows the moment the tab opens), when each is deleted, and private links.
	 */
	/** 0.33.0: a photo, or a placeholder the browser fills in after decrypting an end-to-end one. */
	private static function img_html( $e, $alt ) {
		if ( (int) ( $e->photo_e2e ?? 0 ) ) {
			return '<span class="cmp-e2e-photo"><img data-cmp-e2e-src="' . esc_url( self::photo_url( $e ) ) . '" alt="' . esc_attr( $alt ) . '" hidden /><span class="cmp-muted" data-cmp-e2e-note>' . esc_html__( 'End-to-end photo: unlock above to see it.', 'cmp' ) . '</span></span>';
		}
		return '<a href="' . esc_url( self::photo_url( $e ) ) . '" target="_blank" rel="noopener"><img src="' . esc_url( self::photo_url( $e ) ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" /></a>';
	}

	private static function photos_html( $user_id ) {
		$photos = self::wearer_photos( $user_id );
		$links  = CMP_Photo_Shares::live_for_wearer( $user_id );
		if ( ! $photos && ! $links ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$open    = isset( $_GET['photos'] );
		$new     = isset( $_GET['shared'] ) ? absint( $_GET['shared'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lengths = CMP_Photo_Shares::lengths();
		$html    = '<section class="cmp-panel cmp-cl-photos" id="cmp-cl-photos"><details' . ( $open ? ' open' : '' ) . '><summary class="cmp-panel-title">' . esc_html( sprintf( /* translators: %d: photos */ _n( 'Your photos (%d)', 'Your photos (%d)', count( $photos ), 'cmp' ), count( $photos ) ) ) . '</summary>';
		$html   .= '<p class="cmp-muted">' . esc_html( sprintf( /* translators: %d: days */ __( 'Only you and your current keyholder can see these. They\'re stored encrypted and deleted %d days after you take them; you can download or delete any of them sooner. To show one to someone else, make a private link: they don\'t need an account, it stops working when you choose, and you can turn it off at any time. Your daily code is only for the photo; it doesn\'t open anything.', 'cmp' ), self::photo_days() ) ) . '</p>';
		$html   .= '<div class="cmp-cl-photo-grid">';
		foreach ( $photos as $e ) {
			$e2e   = (int) $e->photo_e2e;
			$html .= '<figure class="cmp-hw-proof cmp-cl-photo">' . self::img_html( $e, 'verification' === $e->type ? __( 'Verification photo', 'cmp' ) : __( 'Photo of the lock', 'cmp' ) ) . ( $e2e ? '<span class="cmp-e2e-badge">' . esc_html__( 'End to end', 'cmp' ) . '</span>' : '' );
			$html .= '<figcaption><b>' . esc_html( wp_date( 'M j, g:i A', self::ts( $e->created_at ) ) ) . '</b><br /><span class="cmp-muted">' . esc_html( sprintf( /* translators: %s: date */ __( 'Deleted %s', 'cmp' ), wp_date( 'M j, Y', self::photo_delete_at( $e->created_at ) ) ) ) . '</span></figcaption>';
			foreach ( isset( $links[ (int) $e->id ] ) ? $links[ (int) $e->id ] : array() as $sh ) {
				$fid   = 'cmp_cl_share_' . (int) $sh->id;
				$html .= '<div class="cmp-cl-share' . ( $new === (int) $sh->id ? ' is-new' : '' ) . '"><span class="cmp-cal-feed-copy"><input type="text" id="' . esc_attr( $fid ) . '" value="' . ( $e2e ? '' : esc_attr( CMP_Photo_Shares::url( $sh ) ) ) . '" readonly aria-label="' . esc_attr__( 'Private link', 'cmp' ) . '"' . ( $e2e ? ' disabled placeholder="' . esc_attr__( 'Unlock end-to-end photos to copy this link', 'cmp' ) . '" data-cmp-e2e-share="' . esc_url( self::photo_url( $e ) ) . '" data-base="' . esc_url( CMP_Photo_Shares::url( $sh ) ) . '"' : '' ) . ' /><button type="button" class="cmp-btn cmp-btn-small cmp-btn-outline"' . ( $e2e ? ' disabled' : '' ) . ' data-cmp-copy="#' . esc_attr( $fid ) . '" data-cmp-copied="' . esc_attr__( 'Copied', 'cmp' ) . '" data-cmp-select="' . esc_attr__( 'Link selected, copy it', 'cmp' ) . '">' . esc_html__( 'Copy link', 'cmp' ) . '</button></span>';
				$html .= '<p class="cmp-muted">' . esc_html( sprintf( /* translators: 1: date and time, 2: views */ __( 'Works until %1$s · viewed %2$d times', 'cmp' ), wp_date( 'M j, g:i A', self::ts( $sh->expires_at ) ), (int) $sh->views ) . ( (int) $sh->max_views ? ' · ' . __( 'works once', 'cmp' ) : '' ) ) . '</p>';
				$html .= self::form_open( 'unshare', 0, false, 'cmp-form cmp-cl-unshare' ) . '<input type="hidden" name="share" value="' . (int) $sh->id . '" /><button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline cmp-btn-danger">' . esc_html__( 'Turn off link', 'cmp' ) . '</button></form></div>';
			}
			$html .= '<p class="cmp-cl-photo-acts">' . ( $e2e ? '<a href="#" data-cmp-e2e-dl="' . esc_url( self::photo_url( $e ) ) . '" download="colandb-lock-' . esc_attr( gmdate( 'Y-m-d', self::ts( $e->created_at ) ) . '-' . (int) $e->id ) . '.jpg" hidden>' . esc_html__( 'Download', 'cmp' ) . '</a>' : '<a href="' . esc_url( add_query_arg( 'dl', 1, self::photo_url( $e ) ) ) . '" download>' . esc_html__( 'Download', 'cmp' ) . '</a>' ) . '</p>';
			$html .= self::form_open( 'unphoto', 0, false, 'cmp-form cmp-cl-unphoto' ) . '<input type="hidden" name="event" value="' . (int) $e->id . '" /><button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline cmp-btn-danger" data-cmp-confirm="' . esc_attr__( 'Delete this photo now? Your keyholder won\'t see it any more and its links stop working. This can\'t be undone.', 'cmp' ) . '">' . esc_html__( 'Delete photo', 'cmp' ) . '</button></form>';
			$sid   = 'cmp_cl_exp_' . (int) $e->id;
			$html .= '<details class="cmp-hw-taskedit"><summary>' . esc_html__( 'Share privately', 'cmp' ) . '</summary>' . self::form_open( 'share', 0, false, 'cmp-form cmp-cl-shareform' ) . '<input type="hidden" name="event" value="' . (int) $e->id . '" />';
			$html .= '<p class="cmp-field"><label for="' . esc_attr( $sid ) . '">' . esc_html__( 'Link stops working after', 'cmp' ) . '</label>' . self::select( 'expires', $sid, $lengths, (string) DAY_IN_SECONDS ) . '</p>';
			$html .= self::check( 'once', $sid . '_once', __( 'Works once (stops after the first view)', 'cmp' ), true );
			$html .= '<p class="cmp-muted">' . esc_html__( 'Anyone you give the link to can see this photo until it stops working, and could screenshot it. Only share with people you trust.', 'cmp' ) . '</p>';
			$html .= '<button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Make private link', 'cmp' ) . '</button></form></details></figure>';
		}
		if ( ! $photos ) {
			$html .= '<p class="cmp-empty">' . esc_html__( 'No photos kept right now.', 'cmp' ) . '</p>';
		}
		return $html . '</div></details></section>';
	}

	/* ------------------------------------------------------------------
	 * 0.36.0: forgiving human error (Locktober), runs, personal best
	 * ---------------------------------------------------------------- */

	/** 0.36.0: without a member keyholder, the wearer can catch up a day they forgot to check in. */
	private static function catch_up_html( $lock ) {
		if ( $lock->keyholder_id ) {
			return '';
		}
		$days = self::elsewhere_days( $lock );
		$pick = isset( $_GET['vday'] ) && isset( $days[ sanitize_text_field( wp_unslash( $_GET['vday'] ) ) ] ) ? sanitize_text_field( wp_unslash( $_GET['vday'] ) ) : (string) key( $days ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$html = '<details class="cmp-hw-taskedit cmp-cl-elsewhere" id="cmp-cl-elsewhere"' . ( isset( $_GET['vday'] ) ? ' open' : '' ) . '><summary>' . esc_html__( 'Forgot to check in?', 'cmp' ) . '</summary>' . self::form_open( 'verified_else', $lock->id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$html .= '<p class="cmp-muted">' . esc_html__( 'Were you locked on a day you didn\'t check in? Mark it here (or tap the day on the calendar) so it gets its star. It\'s on your honour and shows as self-reported.', 'cmp' ) . '</p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_sday">' . esc_html__( 'Day', 'cmp' ) . '</label><input type="date" id="cmp_cl_sday" name="day" value="' . esc_attr( $pick ) . '" min="' . esc_attr( (string) array_key_last( $days ) ) . '" max="' . esc_attr( (string) key( $days ) ) . '" required /></p>';
		$html .= '<p class="cmp-field"><label for="cmp_cl_snote">' . esc_html__( 'Note (optional)', 'cmp' ) . '</label><input type="text" id="cmp_cl_snote" name="note" maxlength="200" /></p>';
		return $html . '<button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Mark checked in', 'cmp' ) . '</button></form></details>';
	}

	/** "I locked earlier": an optional start time up to 48 hours back. */
	private static function start_field_html( $id ) {
		return '<details class="cmp-hw-taskedit cmp-cl-began"><summary>' . esc_html__( 'I locked earlier (logging it late)', 'cmp' ) . '</summary><p class="cmp-field"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'When you locked', 'cmp' ) . '</label><input type="datetime-local" id="' . esc_attr( $id ) . '" name="began" min="' . esc_attr( wp_date( 'Y-m-d\TH:i', time() - self::LATE_START ) ) . '" max="' . esc_attr( wp_date( 'Y-m-d\TH:i' ) ) . '" /><span class="cmp-muted">' . esc_html( sprintf( /* translators: %s: timezone */ __( 'Up to 48 hours back, site time (%s). Leave it empty if you locked just now.', 'cmp' ), wp_timezone_string() ) ) . '</span></p></details>';
	}

	/** The posted start, as a timestamp: now, or (0.36.0) up to 48 hours back, never overlapping the last lock. */
	private static function posted_start( $user_id ) {
		global $wpdb;
		$raw = isset( $_POST['began'] ) ? sanitize_text_field( wp_unslash( $_POST['began'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $raw ) {
			return time();
		}
		$dt = DateTime::createFromFormat( 'Y-m-d\TH:i', $raw, wp_timezone() );
		if ( ! $dt || $dt->getTimestamp() > time() + 60 || $dt->getTimestamp() < time() - self::LATE_START - 60 ) {
			self::go( array(), 'cl_began' );
		}
		$last = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(ended_at) FROM ' . self::t( 'locks' ) . " WHERE wearer_id = %d AND status = 'ended'", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $last && $dt->getTimestamp() < self::ts( $last ) ) {
			self::go( array(), 'cl_began' );
		}
		return min( time(), $dt->getTimestamp() );
	}

	/** The wearer's last lock, if it ended within the undo window and nothing has started since. */
	public static function undoable( $wearer_id ) {
		global $wpdb;
		if ( self::active_lock( $wearer_id ) ) {
			return null;
		}
		$l = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'locks' ) . " WHERE wearer_id = %d AND status = 'ended' ORDER BY ended_at DESC, id DESC LIMIT 1", $wearer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $l && 'admin' !== $l->end_reason && self::ts( $l->ended_at ) > time() - self::UNDO_WINDOW ? $l : null;
	}

	/**
	 * 0.36.0: the wearer undoes an end made by mistake (their Emergency unlock
	 * or End lock, or the keyholder's Unlock now), within an hour. Only the
	 * wearer can, so nobody is put back in a lock without choosing it.
	 */
	private static function do_undo_end( $lock, $user_id ) {
		$last = self::undoable( $user_id );
		if ( ! $lock || ! $last || (int) $last->id !== (int) $lock->id ) {
			self::go( array(), 'cl_gone' );
		}
		self::update_lock( $lock->id, array( 'status' => 'locked', 'ended_at' => null, 'ended_by' => 0, 'end_reason' => '' ) );
		self::add_event( $lock->id, $user_id, 'end_undone' );
		CMP_Audit::log( 'lock_end_undone', 'lock', $lock->id, array( 'end_reason' => $lock->end_reason ), null );
		if ( $lock->keyholder_id ) {
			/* translators: %s: wearer */
			CMP_Notifications::add( (int) $lock->keyholder_id, 'lock', sprintf( __( '%s undid the end of their lock (it was a mistake). The lock carries on.', 'cmp' ), self::me() ), self::url( array( 'lock' => $lock->id ) ) );
		}
		self::go( array(), 'cl_undone' );
	}

	/**
	 * A wearer's runs: locks joined when one starts within a day of the last
	 * one ending (a mistaken unlock, a cleaning break, logging a new lock),
	 * oldest first. Each is { started_at, ended_at, status, locks, breaks }
	 * and works with locked_seconds() / milestones().
	 */
	public static function runs( $wearer_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, started_at, ended_at, status FROM ' . self::t( 'locks' ) . " WHERE wearer_id = %d AND status IN ('locked','ended') ORDER BY started_at, id", $wearer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$runs = array();
		$cur  = null;
		foreach ( $rows as $r ) {
			if ( $cur && ( 'locked' === $cur->status || self::ts( $r->started_at ) - self::ts( $cur->ended_at ) <= self::RUN_GAP ) ) {
				$cur->locks[] = (int) $r->id;
				++$cur->breaks;
				if ( 'locked' === $r->status || 'locked' === $cur->status ) {
					$cur->status   = 'locked';
					$cur->ended_at = null;
				} elseif ( self::ts( $r->ended_at ) > self::ts( $cur->ended_at ) ) {
					$cur->ended_at = $r->ended_at;
				}
				continue;
			}
			if ( $cur ) {
				$runs[] = $cur;
			}
			$cur = (object) array( 'started_at' => $r->started_at, 'ended_at' => $r->ended_at, 'status' => $r->status, 'locks' => array( (int) $r->id ), 'breaks' => 0 );
		}
		if ( $cur ) {
			$runs[] = $cur;
		}
		return $runs;
	}

	/** The run a lock belongs to (the lock itself if it stands alone). */
	public static function run_for( $lock ) {
		foreach ( self::runs( (int) $lock->wearer_id ) as $run ) {
			if ( in_array( (int) $lock->id, $run->locks, true ) ) {
				return $run;
			}
		}
		return $lock;
	}

	/** The wearer's longest run, or null. */
	public static function best_run( $wearer_id ) {
		$best = null;
		foreach ( self::runs( $wearer_id ) as $run ) {
			if ( ! $best || self::locked_seconds( $run ) > self::locked_seconds( $best ) ) {
				$best = $run;
			}
		}
		return $best;
	}

	/** "now" for Locktober, filterable for tests. */
	private static function now() {
		return (int) apply_filters( 'cmp_chastity_now', time() );
	}

	/**
	 * Locktober (the community's October chastity challenge, no official
	 * rules): completed when one run covered October 2 at 23:59 through
	 * October 31 at 00:00, site time. That allows a day's grace at each end
	 * (locked on the 2nd, or out on Halloween), and runs already forgive
	 * breaks of under a day.
	 *
	 * @return int[] Years completed, newest first.
	 */
	public static function locktober_years( $wearer_id ) {
		$tz    = wp_timezone();
		$years = array();
		foreach ( self::runs( $wearer_id ) as $run ) {
			$s = self::ts( $run->started_at );
			$e = 'locked' === $run->status ? self::now() : self::ts( $run->ended_at );
			for ( $y = (int) wp_date( 'Y', $s ); $y <= (int) wp_date( 'Y', $e ); $y++ ) {
				$need_start = ( new DateTime( $y . '-10-02 23:59:59', $tz ) )->getTimestamp();
				$need_end   = ( new DateTime( $y . '-10-31 00:00:00', $tz ) )->getTimestamp();
				if ( $s <= $need_start && $e >= $need_end ) {
					$years[ $y ] = $y;
				}
			}
		}
		krsort( $years );
		return array_values( $years );
	}

	/** 0.38.0: '0' hidden, '1' stars and labels, 'icons' icons only. */
	public static function stars_mode( $user_id ) {
		$m = (string) get_user_meta( $user_id, self::META_SHOW_STARS, true );
		return in_array( $m, array( '1', 'icons' ), true ) ? $m : '0';
	}

	public static function medal( $year, $plain = false ) {
		if ( $plain ) { // 0.38.0: icons only.
			return '<span class="cmp-cl-medal" role="img" aria-label="' . esc_attr( sprintf( /* translators: %d: year */ __( 'Medal %d', 'cmp' ), $year ) ) . '">🏅 ' . (int) $year . '</span>';
		}
		/* translators: %d: year */
		return '<span class="cmp-cl-medal" title="' . esc_attr( sprintf( __( 'Completed Locktober %d', 'cmp' ), $year ) ) . '">🏅 ' . esc_html( sprintf( __( 'Locktober %d', 'cmp' ), $year ) ) . '</span>';
	}

	/** During October (and the first week of November): progress for this year's Locktober. */
	private static function locktober_html( $user_id, $lock ) {
		$tz  = wp_timezone();
		$now = self::now();
		$y   = (int) wp_date( 'Y', $now );
		$oct = ( new DateTime( $y . '-10-01 00:00:00', $tz ) )->getTimestamp();
		$nov = ( new DateTime( $y . '-11-08 00:00:00', $tz ) )->getTimestamp();
		if ( $now < $oct || $now >= $nov ) {
			return '';
		}
		$html = '<section class="cmp-panel cmp-cl-locktober"><h3 class="cmp-panel-title">' . esc_html( sprintf( /* translators: %d: year */ __( 'Locktober %d', 'cmp' ), $y ) ) . '</h3>';
		if ( in_array( $y, self::locktober_years( $user_id ), true ) ) {
			return $html . '<p>' . self::medal( $y ) . ' ' . esc_html__( 'Completed. Well done!', 'cmp' ) . '</p></section>';
		}
		$day = min( 31, (int) floor( ( $now - $oct ) / DAY_IN_SECONDS ) + 1 );
		if ( $now >= ( new DateTime( $y . '-11-01 00:00:00', $tz ) )->getTimestamp() ) {
			return '';
		}
		/* translators: %d: day of October */
		$html .= '<p class="cmp-cl-lt-day">' . esc_html( sprintf( __( 'Day %d of 31', 'cmp' ), $day ) ) . '</p><div class="cmp-cl-lt-bar" role="img" aria-label="' . esc_attr( sprintf( __( 'Day %d of 31', 'cmp' ), $day ) ) . '"><span style="width:' . esc_attr( (string) round( $day / 31 * 100 ) ) . '%"></span></div>';
		$run   = $lock ? self::run_for( $lock ) : null;
		$late  = ( new DateTime( $y . '-10-02 23:59:59', $tz ) )->getTimestamp();
		if ( $run && self::ts( $run->started_at ) <= $late ) {
			$html .= '<p>' . esc_html__( 'You\'re on track. Stay locked until Halloween (October 31) to complete it. A break of under a day, or a lock ended by mistake and undone, doesn\'t break your run.', 'cmp' ) . '</p>';
		} elseif ( $run ) {
			$html .= '<p class="cmp-muted">' . esc_html( sprintf( /* translators: %s: date */ __( 'Your run started %s, so this year it won\'t count as a full Locktober (it needs a start by October 2). Every day still earns stars. Keep going!', 'cmp' ), wp_date( 'M j', self::ts( $run->started_at ) ) ) ) . '</p>';
		} else {
			$html .= '<p class="cmp-muted">' . esc_html( $day <= 2 ? __( 'Lock up by the end of October 2 to take part. Locked already and forgot to log it? Use "I locked earlier" when you start.', 'cmp' ) : __( 'Not locked right now. You can still lock up and earn stars for the rest of the month.', 'cmp' ) ) . '</p>';
		}
		return $html . '</section>';
	}

	/** "Your stars": the longest run, Locktober medals, and whether they show on the profile. */
	private static function my_stars_html( $user_id ) {
		$best  = self::best_run( $user_id );
		$years = self::locktober_years( $user_id );
		$share = CMP_Star_News::share_html( $user_id ); // 0.37.0
		if ( ( ! $best || self::locked_seconds( $best ) < WEEK_IN_SECONDS ) && ! $years && '' === $share ) {
			return '';
		}
		$html = '<section class="cmp-panel cmp-cl-mystars"><h3 class="cmp-panel-title">' . esc_html__( 'Your stars', 'cmp' ) . '</h3>';
		if ( $best && self::locked_seconds( $best ) >= WEEK_IN_SECONDS ) {
			$end   = 'locked' === $best->status ? __( 'now', 'cmp' ) : wp_date( 'M j, Y', self::ts( $best->ended_at ) );
			$html .= '<p><b>' . esc_html__( 'Longest run', 'cmp' ) . '</b> ' . self::stars_html( $best ) . ' ' . esc_html( self::milestone_label( self::milestones( $best ) ) ) . ' <span class="cmp-muted">' . esc_html( wp_date( 'M j, Y', self::ts( $best->started_at ) ) . ' – ' . $end ) . ( 'locked' === $best->status ? ' · ' . __( 'this is your current lock', 'cmp' ) : '' ) . '</span></p>';
		}
		if ( $years ) {
			$html .= '<p>' . implode( ' ', array_map( array( __CLASS__, 'medal' ), $years ) ) . '</p>';
		}
		// 0.38.0: three choices for the profile.
		$mode  = self::stars_mode( $user_id );
		$says  = array(
			'0'     => __( 'Only you see these.', 'cmp' ),
			'1'     => __( 'Your stars, longest run and Locktober medals show on your profile, with labels, even when you\'re not locked.', 'cmp' ),
			'icons' => __( 'Your stars and medals show on your profile as icons only, without saying what they\'re for.', 'cmp' ),
		);
		$html .= CMP_Star_News::prompt_html( $user_id );
		$html .= self::form_open( 'stars_show', 0, false, 'cmp-form cmp-cl-stars-mode' ) . '<p class="cmp-muted">' . esc_html( $says[ $mode ] ) . '</p><div class="cmp-actions" role="group" aria-label="' . esc_attr__( 'On my profile', 'cmp' ) . '">';
		foreach ( array( '0' => __( 'Hidden', 'cmp' ), '1' => __( 'Stars and labels', 'cmp' ), 'icons' => __( 'Icons only', 'cmp' ) ) as $k => $l ) {
			$html .= '<button type="submit" name="show" value="' . esc_attr( $k ) . '" class="cmp-btn cmp-btn-small' . ( $mode === $k ? '' : ' cmp-btn-outline' ) . '" aria-pressed="' . ( $mode === $k ? 'true' : 'false' ) . '">' . esc_html( $l ) . '</button>';
		}
		$html .= '</div></form>';
		return $html . $share . '</section>';
	}

	/* ------------------------------------------------------------------
	 * 0.35.0: milestone stars and the verification calendar
	 * ---------------------------------------------------------------- */

	/**
	 * Stars for one lock's unbroken time locked (owner, 2026-10-22). After
	 * the usual loyalty ladder (bronze < gold < platinum < diamond), with the
	 * owner's gems (0.35.1): week 1 bronze, week 2 emerald, week 3 ruby (one
	 * star, the latest); then a gold star per month; a sapphire at 6 months
	 * (between gold and platinum: 7 months = sapphire + gold); a platinum
	 * star per year; a diamond for every 3 years.
	 *
	 * @return int[] diamond, platinum, sapphire, gold, ruby, emerald, bronze
	 */
	public static function milestones( $lock ) {
		$secs  = self::locked_seconds( $lock );
		$start = ( new DateTime( '@' . self::ts( $lock->started_at ) ) )->setTimezone( wp_timezone() );
		$now   = ( new DateTime( '@' . ( self::ts( $lock->started_at ) + $secs ) ) )->setTimezone( wp_timezone() );
		$d     = $start->diff( $now );
		$out   = array( 'diamond' => intdiv( $d->y, 3 ), 'platinum' => $d->y % 3, 'sapphire' => intdiv( $d->m, 6 ), 'gold' => $d->m % 6, 'ruby' => 0, 'emerald' => 0, 'bronze' => 0 );
		if ( ! $d->y && ! $d->m ) {
			$weeks = min( 3, intdiv( $secs, WEEK_IN_SECONDS ) );
			$week  = array( 1 => 'bronze', 2 => 'emerald', 3 => 'ruby' );
			if ( $weeks ) {
				$out[ $week[ $weeks ] ] = 1;
			}
		}
		return $out;
	}

	/** "3 weeks", "1 month", "1 year, 7 months"… for a milestone count. */
	public static function milestone_label( $m ) {
		$years  = $m['diamond'] * 3 + $m['platinum'];
		$months = $m['sapphire'] * 6 + $m['gold'];
		$weeks  = $m['ruby'] ? 3 : ( $m['emerald'] ? 2 : ( $m['bronze'] ? 1 : 0 ) );
		$parts  = array();
		if ( $years ) {
			/* translators: %d: years */
			$parts[] = sprintf( _n( '%d year', '%d years', $years, 'cmp' ), $years );
		}
		if ( $months ) {
			/* translators: %d: months */
			$parts[] = sprintf( _n( '%d month', '%d months', $months, 'cmp' ), $months );
		}
		if ( ! $parts && $weeks ) {
			/* translators: %d: weeks */
			$parts[] = sprintf( _n( '%d week', '%d weeks', $weeks, 'cmp' ), $weeks );
		}
		return implode( ', ', $parts );
	}

	/** The star names, highest first. */
	public static function star_tiers() {
		return array(
			'diamond'  => __( 'a diamond', 'cmp' ),
			'platinum' => __( 'a platinum star', 'cmp' ),
			'sapphire' => __( 'a sapphire star', 'cmp' ),
			'gold'     => __( 'a gold star', 'cmp' ),
			'ruby'     => __( 'a ruby star', 'cmp' ),
			'emerald'  => __( 'an emerald star', 'cmp' ),
			'bronze'   => __( 'a bronze star', 'cmp' ),
		);
	}

	/** When the next star comes for a running lock, as a timestamp. */
	public static function next_milestone_at( $lock ) {
		$t0    = self::ts( $lock->started_at );
		$start = ( new DateTime( '@' . $t0 ) )->setTimezone( wp_timezone() );
		$month = ( clone $start )->modify( '+1 month' )->getTimestamp();
		for ( $w = 1; $w <= 3; $w++ ) {
			$t = $t0 + $w * WEEK_IN_SECONDS;
			if ( $t > time() && $t < $month ) {
				return $t;
			}
		}
		for ( $n = 1; $n < 1200; $n++ ) {
			$t = ( clone $start )->modify( '+' . $n . ' months' )->getTimestamp();
			if ( $t > time() ) {
				return $t;
			}
		}
		return 0;
	}

	private static function star_svg( $tier ) {
		$path = 'diamond' === $tier ? 'M6 3h12l4 6-10 12L2 9z' : 'M12 2.5l2.9 6.2 6.8.7-5.1 4.6 1.5 6.7L12 17.3l-6.1 3.4 1.5-6.7-5.1-4.6 6.8-.7z';
		return '<svg class="cmp-star is-' . esc_attr( $tier ) . '" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . $path . '"/></svg>';
	}

	/**
	 * The stars for a lock, with an accessible label. $with_next adds "Next
	 * star: <what> on <date>" for a running lock.
	 */
	public static function stars_html( $lock, $with_next = false, $plain = false ) {
		$m     = self::milestones( $lock );
		$label = self::milestone_label( $m );
		$html  = '';
		if ( '' !== $label ) {
			$stars = '';
			foreach ( array_keys( self::star_tiers() ) as $tier ) {
				$stars .= str_repeat( self::star_svg( $tier ), (int) $m[ $tier ] );
			}
			/* translators: %s: e.g. "2 months" */
			$text  = sprintf( __( 'Stars: %s locked', 'cmp' ), $label );
			// 0.38.0: "icons only" on a profile says nothing about what they're for.
			$html .= $plain ? '<span class="cmp-cl-stars" role="img" aria-label="' . esc_attr__( 'Stars', 'cmp' ) . '">' . $stars . '</span>' : '<span class="cmp-cl-stars" role="img" aria-label="' . esc_attr( $text ) . '" title="' . esc_attr( $text ) . '">' . $stars . '</span>';
		}
		if ( $with_next && 'locked' === $lock->status && self::next_milestone_at( $lock ) ) {
			$at   = self::next_milestone_at( $lock );
			$fake = (object) array( 'started_at' => $lock->started_at, 'status' => 'ended', 'ended_at' => gmdate( 'Y-m-d H:i:s', $at + 60 ) );
			$nm   = self::milestones( $fake );
			$tier = __( 'a star', 'cmp' );
			foreach ( self::star_tiers() as $k => $name ) {
				if ( $nm[ $k ] > $m[ $k ] ) {
					$tier = $name;
					break;
				}
			}
			/* translators: 1: "a gold star", 2: e.g. "1 month", 3: date */
			$html .= '<p class="cmp-cl-next">' . esc_html( sprintf( __( 'Next: %1$s at %2$s, on %3$s', 'cmp' ), $tier, self::milestone_label( $nm ), wp_date( 'M j', $at ) ) ) . '</p>';
		}
		return $html;
	}

	/**
	 * Each site day of one lock in a month: 'done' (an accepted photo, a
	 * "verified elsewhere" mark, or for a lock without a member keyholder any
	 * photo not sent back), 'pending' (a photo waiting for review), 'again'.
	 */
	private static function day_states( $lock, $from_utc, $to_utc ) {
		global $wpdb;
		$out  = array();
		$rank = array( 'again' => 1, 'pending' => 2, 'done' => 3 );
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT type, review, created_at FROM ' . self::t( 'lock_events' ) . " WHERE lock_id = %d AND type IN ('verification','verified_else') AND created_at >= %s AND created_at < %s", $lock->id, $from_utc, $to_utc ) ) as $e ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( 'verified_else' === $e->type || 'accepted' === $e->review || ( ! $lock->keyholder_id && 'again' !== $e->review ) ) {
				$st = 'done';
			} else {
				$st = 'again' === $e->review ? 'again' : 'pending';
			}
			$ymd = wp_date( 'Y-m-d', self::ts( $e->created_at ) );
			if ( ! isset( $out[ $ymd ] ) || $rank[ $st ] > $rank[ $out[ $ymd ] ] ) {
				$out[ $ymd ] = $st;
			}
		}
		return $out;
	}

	/**
	 * A month of one lock beside Verification: a gold star on each day that
	 * counts, an outline star while a photo waits for review, and (for the
	 * keyholder of a running lock) each locked day without a star links to
	 * "Mark a day verified" for that date. ?cmp_cm=YYYY-MM moves between the
	 * lock's months.
	 */
	private static function verify_calendar_html( $lock, $as_keyholder ) {
		$tz     = wp_timezone();
		$startd = wp_date( 'Y-m-d', self::ts( $lock->started_at ) );
		$endd   = 'locked' === $lock->status ? wp_date( 'Y-m-d' ) : wp_date( 'Y-m-d', self::ts( $lock->ended_at ) );
		$first  = substr( $startd, 0, 7 );
		$last   = substr( $endd, 0, 7 );
		$want   = isset( $_GET['cmp_cm'] ) ? sanitize_text_field( wp_unslash( $_GET['cmp_cm'] ) ) : $last; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$month  = preg_match( '/^\d{4}-\d{2}$/', $want ) ? max( $first, min( $last, $want ) ) : $last;
		$mstart = new DateTime( $month . '-01 00:00:00', $tz );
		$mnext  = ( clone $mstart )->modify( '+1 month' );
		$utc    = new DateTimeZone( 'UTC' );
		$states = self::day_states( $lock, ( clone $mstart )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), ( clone $mnext )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
		$base   = $as_keyholder ? array( 'lock' => $lock->id ) : array();
		$prev   = ( clone $mstart )->modify( '-1 month' )->format( 'Y-m' );
		$next   = $mnext->format( 'Y-m' );
		$today  = wp_date( 'Y-m-d' );
		$link   = 'locked' === $lock->status && ( $as_keyholder ? self::is_keyholder( $lock, get_current_user_id() ) : ( ! $lock->keyholder_id && self::is_wearer( $lock, get_current_user_id() ) ) ); // 0.36.0: self-locks catch up too.
		$done   = 0;
		$total  = 0;

		$html  = '<aside class="cmp-cl-verify-cal" aria-labelledby="cmp-cl-cal-title-' . (int) $lock->id . '"><div class="cmp-cl-cal-head">';
		$html .= $prev >= $first ? '<a class="cmp-cl-cal-nav" href="' . esc_url( self::url( $base + array( 'cmp_cm' => $prev ) ) ) . '#cmp-cl-cal-title-' . (int) $lock->id . '" aria-label="' . esc_attr__( 'Previous month', 'cmp' ) . '">‹</a>' : '<span class="cmp-cl-cal-nav is-off" aria-hidden="true">‹</span>';
		$html .= '<h4 id="cmp-cl-cal-title-' . (int) $lock->id . '">' . esc_html( wp_date( 'F Y', $mstart->getTimestamp() ) ) . '</h4>';
		$html .= $next <= $last ? '<a class="cmp-cl-cal-nav" href="' . esc_url( self::url( $base + array( 'cmp_cm' => $next ) ) ) . '#cmp-cl-cal-title-' . (int) $lock->id . '" aria-label="' . esc_attr__( 'Next month', 'cmp' ) . '">›</a>' : '<span class="cmp-cl-cal-nav is-off" aria-hidden="true">›</span>';
		$html .= '</div><div class="cmp-cl-stars-cal">';
		foreach ( array( __( 'Mo', 'cmp' ), __( 'Tu', 'cmp' ), __( 'We', 'cmp' ), __( 'Th', 'cmp' ), __( 'Fr', 'cmp' ), __( 'Sa', 'cmp' ), __( 'Su', 'cmp' ) ) as $wd ) {
			$html .= '<span class="is-wd" aria-hidden="true">' . esc_html( $wd ) . '</span>';
		}
		$html .= str_repeat( '<span class="is-blank"></span>', (int) $mstart->format( 'N' ) - 1 );
		for ( $day = clone $mstart; $day < $mnext; $day->modify( '+1 day' ) ) {
			$ymd    = $day->format( 'Y-m-d' );
			$n      = (int) $day->format( 'j' );
			$in     = $ymd >= $startd && $ymd <= $endd;
			$st     = isset( $states[ $ymd ] ) ? $states[ $ymd ] : '';
			$date   = wp_date( 'l, F j', $day->getTimestamp() + 12 * HOUR_IN_SECONDS );
			$cls    = array( $ymd === $today ? 'is-today' : '' );
			if ( ! $in ) {
				$html .= '<span class="is-out ' . esc_attr( implode( ' ', $cls ) ) . '">' . $n . '</span>';
				continue;
			}
			++$total;
			if ( 'done' === $st ) {
				++$done;
				/* translators: %s: date */
				$html .= '<span class="is-done ' . esc_attr( implode( ' ', $cls ) ) . '" title="' . esc_attr( sprintf( __( '%s: verified', 'cmp' ), $date ) ) . '"><i>' . $n . '</i>' . self::star_svg( 'gold' ) . '<span class="screen-reader-text">' . esc_html__( 'verified', 'cmp' ) . '</span></span>';
				continue;
			}
			/* translators: %s: date */
			$tip = 'pending' === $st ? sprintf( __( '%s: photo waiting for review', 'cmp' ), $date ) : ( 'again' === $st ? sprintf( __( '%s: asked for another photo', 'cmp' ), $date ) : sprintf( __( '%s: not verified', 'cmp' ), $date ) );
			$in_html = '<i>' . $n . '</i>' . ( 'pending' === $st ? self::star_svg( 'outline' ) : '' ) . '<span class="screen-reader-text">' . esc_html( 'pending' === $st ? __( 'waiting for review', 'cmp' ) : __( 'not verified', 'cmp' ) ) . '</span>';
			$cls[]   = 'pending' === $st ? 'is-pending' : 'is-missed';
			if ( $link ) {
				/* translators: %s: date */
				$html .= '<a class="' . esc_attr( implode( ' ', $cls ) ) . '" href="' . esc_url( self::url( $base + array( 'cmp_cm' => $month, 'vday' => $ymd ) ) ) . '#cmp-cl-elsewhere" title="' . esc_attr( $tip . ' · ' . __( 'mark it verified', 'cmp' ) ) . '">' . $in_html . '</a>';
			} else {
				$html .= '<span class="' . esc_attr( implode( ' ', $cls ) ) . '" title="' . esc_attr( $tip ) . '">' . $in_html . '</span>';
			}
		}
		$html .= '</div>';
		/* translators: 1: verified days, 2: days locked this month */
		$html .= '<p class="cmp-cl-cal-sum">' . esc_html( sprintf( _n( '%1$d of %2$d day verified', '%1$d of %2$d days verified', $total, 'cmp' ), $done, $total ) ) . '</p>';
		$html .= '<p class="cmp-muted cmp-cl-legend2">' . self::star_svg( 'gold' ) . ' ' . esc_html__( 'Verified', 'cmp' ) . ' ' . self::star_svg( 'outline' ) . ' ' . esc_html__( 'Waiting for review', 'cmp' ) . ( $link ? ' · ' . esc_html( $as_keyholder ? __( 'Tap a day without a star to mark it verified.', 'cmp' ) : __( 'Tap a day without a star if you forgot to check in.', 'cmp' ) ) : '' ) . '</p>';
		return $html . '</aside>';
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
			$out .= '<p class="cmp-cl-badge">' . self::icon( 'lock' ) . '<span>' . wp_kses( $text, array( 'a' => array( 'href' => true ) ) ) . ' ' . self::stars_html( self::run_for( $lock ) ) . ( $ver ? ' · ' . $ver : '' ) . '</span></p>';
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
			$out .= '<p class="cmp-cl-badge is-key">' . self::icon( 'key' ) . '<span>' . wp_kses( $text, array( 'a' => array( 'href' => true ) ) ) . ' ' . self::stars_html( self::run_for( $h ) ) . ( $ver ? ' · ' . $ver : '' ) . '</span></p>';
		}
		// 0.36.0: the member's choice: their longest run and Locktober medals, locked or not.
		$mode = self::stars_mode( $owner_id );
		if ( '0' !== $mode ) {
			$plain = 'icons' === $mode; // 0.38.0
			$best  = self::best_run( $owner_id );
			$years = self::locktober_years( $owner_id );
			$bits  = array();
			if ( $best && self::locked_seconds( $best ) >= WEEK_IN_SECONDS ) {
				/* translators: %s: e.g. "2 months" */
				$bits[] = $plain ? self::stars_html( $best, false, true ) : esc_html( sprintf( __( 'Longest lock: %s', 'cmp' ), self::milestone_label( self::milestones( $best ) ) ) ) . ' ' . self::stars_html( $best );
			}
			foreach ( $years as $y ) {
				$bits[] = self::medal( $y, $plain );
			}
			if ( $bits ) {
				$out .= '<p class="cmp-cl-badge is-stars' . ( $plain ? ' is-plain' : '' ) . '"><span>' . implode( $plain ? ' ' : ' · ', $bits ) . '</span></p>';
			}
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
					$rows[] = array( 'name' => __( 'Lock history', 'cmp' ), 'value' => $e->created_at . ' · ' . self::event_text( $e ) . ( self::has_photo( $e ) ? ' · ' . sprintf( /* translators: %s: date */ __( '(photo kept, encrypted, until %s)', 'cmp' ), wp_date( 'Y-m-d', self::photo_delete_at( $e->created_at ) ) ) : '' ) );
				}
			}
		}
		return array_merge( $rows, CMP_Photo_Shares::export_rows( $user_id ), CMP_E2E::export_rows( $user_id ) );
	}

	/** The wearer's locks go entirely; as keyholder, locks become self-locks. */
	public static function erase( $user_id ) {
		global $wpdb;
		$n = CMP_Photo_Shares::erase( $user_id );
		CMP_E2E::erase( $user_id ); // 0.33.0
		delete_user_meta( $user_id, self::META_SHOW_STARS ); // 0.36.0
		delete_user_meta( $user_id, CMP_Star_News::META_PROMPT ); // 0.38.0
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
