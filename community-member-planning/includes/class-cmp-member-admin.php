<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Users → Member Dynamics & Locks (0.26.0): the site team's tools for when
 * members can't sort something out themselves.
 *
 * - Find a member; see their dynamics, add-ons and locks.
 * - Locks: correct the start, the planned end, or the end (by date and time,
 *   or by "days locked"); end a lock; remove a stuck or duplicate one; record
 *   a lock that should exist (for example an Accept that failed).
 * - Dynamics: end one, turn off an add-on, or cancel a request.
 *
 * Status, names and dates only: no verification photos, rules text, notes
 * or messages. Every change needs a reason and is written to the audit log
 * with the old and new values. Lock corrections also add a "Corrected by the
 * site team" line to the lock's history. Members get no notification unless
 * "Notify both members" is ticked (0.27.0: the owner tells them by email).
 * Nothing here accepts a lock or a dynamic on someone's behalf.
 */
class CMP_Member_Admin {

	const PAGE   = 'cmp-member-dynamics';
	const ACTION = 'cmp_member_admin';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function menu() {
		add_users_page( __( 'Member Dynamics & Locks', 'cmp' ), __( 'Dynamics & locks', 'cmp' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'users.php' ) );
	}

	private static function messages() {
		return array(
			'saved'     => array( 'success', __( 'Saved.', 'cmp' ) ),
			'ended'     => array( 'success', __( 'Ended.', 'cmp' ) ),
			'removed'   => array( 'success', __( 'Removed.', 'cmp' ) ),
			'recorded'  => array( 'success', __( 'Lock recorded.', 'cmp' ) ),
			'same'      => array( 'warning', __( 'Nothing changed: the values were the same.', 'cmp' ) ),
			'times'     => array( 'error', __( 'Those times don\'t work: the start must be in the past, an end must come after the start (and not be in the future), and a planned end must come after the start.', 'cmp' ) ),
			'reason'    => array( 'error', __( 'Add a short reason. It goes in the audit log.', 'cmp' ) ),
			'member'    => array( 'error', __( 'That member wasn\'t found. Use their username or email.', 'cmp' ) ),
			'keyholder' => array( 'error', __( 'For a running lock, the keyholder must hold the wearer\'s key now (an active dynamic with chastity, them leading). Leave Keyholder empty for a self-lock.', 'cmp' ) ),
			'invalid_settings' => array( 'error', __( 'Those settings couldn\'t be saved. A Custom option needs a name.', 'cmp' ) ),
			'busy'      => array( 'error', __( 'That member already has a running lock. Correct or end it instead.', 'cmp' ) ),
			'invalid'   => array( 'error', __( 'That couldn\'t be saved. Check the form.', 'cmp' ) ),
			'gone'      => array( 'error', __( 'That isn\'t available any more.', 'cmp' ) ),
		);
	}

	/* ------------------------------------------------------------------
	 * Input
	 * ---------------------------------------------------------------- */

	private static function back( $args, $msg ) {
		wp_safe_redirect( self::url( $args + array( 'msg' => $msg ) ) );
		exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification -- handle() checks the nonce before calling these.
	private static function post( $key ) {
		return isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
	}

	/** A site-time "Y-m-d\TH:i" field as UTC; '' when empty, false when unreadable. */
	private static function post_time( $key ) {
		$v = self::post( $key );
		if ( '' === $v ) {
			return '';
		}
		$d = DateTime::createFromFormat( 'Y-m-d\TH:i', $v, wp_timezone() );
		return $d ? $d->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : false;
	}

	/** "Days locked" fields: seconds, or null when both are empty. */
	private static function post_days() {
		$d = self::post( 'days' );
		$h = self::post( 'hours' );
		if ( '' === $d && '' === $h ) {
			return null;
		}
		return max( 0, (int) $d ) * DAY_IN_SECONDS + min( 23, max( 0, (int) $h ) ) * HOUR_IN_SECONDS;
	}
	// phpcs:enable

	/** A username, email or user id typed in a field. */
	private static function find_user( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return 0;
		}
		$u = ctype_digit( $text ) ? get_userdata( (int) $text ) : ( get_user_by( 'login', $text ) ? get_user_by( 'login', $text ) : get_user_by( 'email', $text ) );
		if ( ! $u ) {
			$found = get_users( array( 'search' => $text, 'search_columns' => array( 'display_name', 'user_nicename' ), 'number' => 2, 'fields' => 'ID' ) );
			$u     = 1 === count( $found ) ? get_userdata( (int) $found[0] ) : null;
		}
		return $u ? (int) $u->ID : 0;
	}

	private static function to_utc( $ts ) {
		return gmdate( 'Y-m-d H:i:s', (int) $ts );
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ---------------------------------------------------------------- */

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cmp' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$admin  = get_current_user_id();
		$do     = sanitize_key( self::post( 'do' ) );
		$reason = mb_substr( self::post( 'reason' ), 0, 200 );
		$lock   = absint( self::post( 'lock' ) );
		$member = absint( self::post( 'member' ) );
		$ret    = $lock ? array( 'lock' => $lock ) : ( $member ? array( 'member' => $member ) : array() );
		if ( '' === $reason ) {
			self::back( $ret, 'reason' );
		}
		CMP_Notifications::$muted = ! self::post( 'notify' );

		if ( 'settings' === $do ) {
			$in = array();
			foreach ( array( 'show_profile', 'kh_show', 'hide_timer', 'verify_daily' ) as $k ) {
				$in[ $k ] = self::post( $k ) ? 1 : 0;
			}
			$in['hygiene_minutes'] = absint( self::post( 'hygiene_minutes' ) );
			$in['option']          = sanitize_key( self::post( 'option' ) );
			$in['option_name']     = mb_substr( self::post( 'option_name' ), 0, 60 );
			$in['release_policy']  = sanitize_key( self::post( 'release_policy' ) );
			$kh_txt                = self::post( 'keyholder' );
			$in['keyholder']       = '' === $kh_txt ? 0 : self::find_user( $kh_txt );
			if ( '' !== $kh_txt && ! $in['keyholder'] ) {
				self::back( $ret, 'member' );
			}
			$err = CMP_Chastity::admin_settings( $lock, $in, $admin, $reason );
			self::back( $ret, $err ? ( 'invalid' === $err ? 'invalid_settings' : $err ) : 'saved' );
		}

		if ( 'dyn_show' === $do ) {
			self::back( $ret, CMP_Dynamics::admin_set_show( absint( self::post( 'dynamic' ) ), absint( self::post( 'user' ) ), (bool) self::post( 'show' ), $admin, $reason ) ? 'saved' : 'gone' );
		}

		if ( 'times' === $do ) {
			$l = CMP_Chastity::lock( $lock );
			if ( ! $l ) {
				self::back( array(), 'gone' );
			}
			$fields = array();
			$days   = self::post_days();
			$start  = self::post_time( 'started_at' );
			$end    = self::post_time( 'ended_at' );
			$plan   = self::post_time( 'planned_end' );
			if ( false === $start || false === $end || false === $plan ) {
				self::back( $ret, 'times' );
			}
			if ( 'ended' === $l->status && '' !== $end ) {
				$fields['ended_at'] = $end;
			}
			$anchor = 'ended' === $l->status ? strtotime( ( isset( $fields['ended_at'] ) ? $fields['ended_at'] : $l->ended_at ) . ' UTC' ) : time();
			if ( null !== $days ) {
				$fields['started_at'] = self::to_utc( $anchor - $days );
			} elseif ( '' !== $start ) {
				$fields['started_at'] = $start;
			}
			if ( 'locked' === $l->status ) {
				if ( self::post( 'open_ended' ) ) {
					$fields['planned_end'] = null;
				} elseif ( '' !== $plan ) {
					$fields['planned_end'] = $plan;
				}
			}
			$err = CMP_Chastity::admin_set_times( $lock, $fields, $admin, $reason );
			self::back( $ret, $err ? $err : 'saved' );
		}

		if ( 'end_lock' === $do ) {
			self::back( $ret, CMP_Chastity::admin_end( $lock, $admin, $reason ) ? 'ended' : 'gone' );
		}

		if ( 'remove_lock' === $do ) {
			$l = CMP_Chastity::lock( $lock );
			$w = $l ? (int) $l->wearer_id : 0;
			self::back( $w ? array( 'member' => $w ) : array(), CMP_Chastity::admin_delete( $lock, $admin, $reason ) ? 'removed' : 'gone' );
		}

		if ( 'record' === $do ) {
			$wearer = self::find_user( self::post( 'wearer' ) );
			$kh_txt = self::post( 'keyholder' );
			$kh     = '' === $kh_txt ? 0 : self::find_user( $kh_txt );
			if ( ! $wearer || ( '' !== $kh_txt && ! $kh ) ) {
				self::back( $ret, 'member' );
			}
			$start = self::post_time( 'started_at' );
			$plan  = self::post_time( 'planned_end' );
			$end   = self::post_time( 'ended_at' );
			$days  = self::post_days();
			if ( false === $start || false === $plan || false === $end ) {
				self::back( array( 'member' => $wearer ), 'times' );
			}
			if ( null !== $days ) {
				$start = self::to_utc( ( '' !== $end ? strtotime( $end . ' UTC' ) : time() ) - $days );
			}
			$id = CMP_Chastity::admin_record( $wearer, $kh, sanitize_key( self::post( 'option' ) ), $start, '' !== $plan ? $plan : null, '' !== $end ? $end : null, $admin, $reason );
			if ( is_int( $id ) && self::post( 'show_profile' ) ) {
				CMP_Chastity::admin_settings( $id, array( 'show_profile' => 1 ), $admin, $reason );
			}
			self::back( is_int( $id ) ? array( 'lock' => $id ) : array( 'member' => $wearer ), is_int( $id ) ? 'recorded' : $id );
		}

		if ( 'end_dynamic' === $do ) {
			self::back( $ret, CMP_Dynamics::admin_end( absint( self::post( 'dynamic' ) ), $admin ) ? 'ended' : 'gone' );
		}

		if ( 'addon_off' === $do ) {
			self::back( $ret, CMP_Dynamics::admin_addon_off( absint( self::post( 'addon' ) ), $admin ) ? 'saved' : 'gone' );
		}

		self::back( $ret, 'invalid' );
	}

	/* ------------------------------------------------------------------
	 * Screen
	 * ---------------------------------------------------------------- */

	private static function form( $do, $hidden = array(), $confirm = '' ) {
		$html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . '>';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" /><input type="hidden" name="do" value="' . esc_attr( $do ) . '" />' . wp_nonce_field( self::ACTION, '_wpnonce', true, false );
		foreach ( $hidden as $k => $v ) {
			$html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" />';
		}
		return $html;
	}

	private static function reason_field( $id ) {
		return '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html__( 'Reason (required, kept in the audit log)', 'cmp' ) . '</strong></label><br /><input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="reason" maxlength="200" required placeholder="' . esc_attr__( 'e.g. Accept failed on his phone; started 9pm Oct 12 per both', 'cmp' ) . '" /></p>' . self::notify_field( $id . '_n' );
	}

	private static function notify_field( $id ) {
		return '<p><label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="notify" value="1" /> ' . esc_html__( 'Notify both members on the site', 'cmp' ) . '</label></p>';
	}

	private static function local( $utc ) {
		return $utc ? wp_date( 'Y-m-d\TH:i', strtotime( $utc . ' UTC' ) ) : '';
	}

	private static function who( $user_id ) {
		$u = $user_id ? get_userdata( $user_id ) : null;
		if ( ! $u ) {
			return $user_id ? esc_html__( 'Former member', 'cmp' ) : '—';
		}
		return '<a href="' . esc_url( self::url( array( 'member' => $u->ID ) ) ) . '">' . esc_html( $u->display_name ) . '</a> <span class="description">(' . esc_html( $u->user_login ) . ')</span>';
	}

	private static function status_label( $lock ) {
		$labels = array( 'proposed' => __( 'Waiting for the wearer to accept', 'cmp' ), 'locked' => __( 'Locked', 'cmp' ), 'ended' => __( 'Ended', 'cmp' ) );
		return isset( $labels[ $lock->status ] ) ? $labels[ $lock->status ] : $lock->status;
	}

	private static function lock_rows( $locks ) {
		if ( ! $locks ) {
			return '<p>' . esc_html__( 'No locks.', 'cmp' ) . '</p>';
		}
		$html = '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Wearer', 'cmp' ) . '</th><th>' . esc_html__( 'Keyholder', 'cmp' ) . '</th><th>' . esc_html__( 'Status', 'cmp' ) . '</th><th>' . esc_html__( 'Started', 'cmp' ) . '</th><th>' . esc_html__( 'Days locked', 'cmp' ) . '</th><th>' . esc_html__( 'Planned end / ended', 'cmp' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $locks as $l ) {
			$kh    = $l->keyholder_id ? self::who( (int) $l->keyholder_id ) : ( '' !== (string) $l->keyholder_name ? esc_html( $l->keyholder_name ) . ' <span class="description">' . esc_html__( '(no account)', 'cmp' ) . '</span>' : esc_html__( 'Self-lock', 'cmp' ) );
			$html .= '<tr><td>' . self::who( (int) $l->wearer_id ) . '</td><td>' . $kh . '</td><td>' . esc_html( self::status_label( $l ) ) . '</td>';
			$html .= '<td>' . esc_html( 'proposed' === $l->status ? '—' : CMP_Chastity::site_time( $l->started_at ) ) . '</td>';
			$html .= '<td>' . esc_html( 'proposed' === $l->status ? '—' : CMP_Chastity::duration( CMP_Chastity::locked_seconds( $l ) ) ) . '</td>';
			$html .= '<td>' . esc_html( 'ended' === $l->status ? CMP_Chastity::site_time( $l->ended_at ) : ( 'locked' === $l->status ? CMP_Chastity::site_time( $l->planned_end ) : '—' ) ) . '</td>';
			$html .= '<td><a class="button button-small" href="' . esc_url( self::url( array( 'lock' => $l->id ) ) ) . '">' . esc_html__( 'Manage', 'cmp' ) . '</a></td></tr>';
		}
		return $html . '</tbody></table>';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen arguments.
		$msg    = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		$lock   = isset( $_GET['lock'] ) ? absint( $_GET['lock'] ) : 0;
		$member = isset( $_GET['member'] ) ? absint( $_GET['member'] ) : 0;
		$q      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		// phpcs:enable
		if ( '' !== $q ) {
			$member = self::find_user( $q );
			if ( ! $member ) {
				$msg = 'member';
			}
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Member Dynamics & Locks', 'cmp' ) . '</h1>';
		$m = self::messages();
		if ( isset( $m[ $msg ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $m[ $msg ][0] ) . '"><p>' . esc_html( $m[ $msg ][1] ) . '</p></div>';
		}
		echo '<p class="description">' . esc_html__( 'For fixing problems members can\'t sort out themselves. You see names, status and dates only (no photos, rules, notes or messages). Every change needs a reason and is recorded in the audit log; lock changes also show as "Corrected by the site team" in the lock\'s history. Members aren\'t notified unless you tick "Notify both members". Locks and dynamics can\'t be accepted on someone\'s behalf.', 'cmp' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'users.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><p><label for="cmp_ma_q" class="screen-reader-text">' . esc_html__( 'Find a member', 'cmp' ) . '</label><input type="search" id="cmp_ma_q" name="q" class="regular-text" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Username, email or display name', 'cmp' ) . '" /> <button class="button">' . esc_html__( 'Find member', 'cmp' ) . '</button>' . ( $member || $lock ? ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'All', 'cmp' ) . '</a>' : '' ) . '</p></form>';

		if ( $lock ) {
			self::render_lock( $lock );
		} elseif ( $member ) {
			self::render_member( $member );
		} else {
			$t = CMP_Install::table( 'locks' );
			echo '<h2>' . esc_html__( 'Waiting to be accepted', 'cmp' ) . '</h2>' . self::lock_rows( $wpdb->get_results( "SELECT * FROM $t WHERE status = 'proposed' ORDER BY id DESC LIMIT 100" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			echo '<h2>' . esc_html__( 'Running locks', 'cmp' ) . '</h2>' . self::lock_rows( $wpdb->get_results( "SELECT * FROM $t WHERE status = 'locked' ORDER BY started_at LIMIT 300" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			echo '<h2>' . esc_html__( 'Recently ended', 'cmp' ) . '</h2>' . self::lock_rows( $wpdb->get_results( "SELECT * FROM $t WHERE status = 'ended' ORDER BY ended_at DESC LIMIT 30" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			echo self::record_html( 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		echo '</div>';
	}

	private static function render_member( $member ) {
		global $wpdb;
		$u = get_userdata( $member );
		if ( ! $u ) {
			echo '<p>' . esc_html__( 'That member wasn\'t found.', 'cmp' ) . '</p>';
			return;
		}
		echo '<h2>' . esc_html( $u->display_name ) . ' <span class="description">(' . esc_html( $u->user_login ) . ')</span></h2>';

		// Dynamics and add-ons.
		$types = CMP_Dynamics::types();
		echo '<h3>' . esc_html__( 'Dynamics', 'cmp' ) . '</h3>';
		$dyns = CMP_Dynamics::for_user( $member, array( 'active', 'pending' ) );
		if ( ! $dyns ) {
			echo '<p>' . esc_html__( 'No active or pending dynamics.', 'cmp' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'With', 'cmp' ) . '</th><th>' . esc_html__( 'Type', 'cmp' ) . '</th><th>' . esc_html__( 'Status', 'cmp' ) . '</th><th>' . esc_html__( 'Since', 'cmp' ) . '</th><th>' . esc_html__( 'Shown on profiles (needs both on)', 'cmp' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $dyns as $d ) {
				$other = CMP_Dynamics::other( $d, $member );
				$lead  = CMP_Dynamics::lead_id( $d );
				$type  = isset( $types[ $d->type ] ) ? $types[ $d->type ]['label'] : $d->type;
				if ( $lead ) {
					$lu    = get_userdata( $lead );
					$type .= ' · ' . sprintf( /* translators: %s: member */ __( 'led by %s', 'cmp' ), $lu ? $lu->display_name : '?' );
				}
				echo '<tr><td>' . self::who( $other ) . '</td><td>' . esc_html( $type ) . '</td><td>' . esc_html( 'active' === $d->status ? __( 'Active', 'cmp' ) : __( 'Invitation waiting', 'cmp' ) ) . '</td><td>' . esc_html( CMP_Chastity::site_time( $d->created_at ) ) . '</td><td>' . self::show_toggles( $d, $member ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- who() and show_toggles() escape.
				if ( 'active' === $d->status ) {
					echo self::form( 'end_dynamic', array( 'dynamic' => $d->id, 'member' => $member ), __( 'End this dynamic for both members? Any lock it allowed becomes a self-lock.', 'cmp' ) ) . '<input type="text" name="reason" required maxlength="200" placeholder="' . esc_attr__( 'Reason', 'cmp' ) . '" /> <button class="button button-small">' . esc_html__( 'End dynamic', 'cmp' ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$labels = CMP_Dynamics::addon_labels();
		$adds   = CMP_Dynamics::addons_for_user( $member, array( 'active', 'pending' ) );
		echo '<h3>' . esc_html__( 'Chastity and homework add-ons', 'cmp' ) . '</h3>';
		if ( ! $adds ) {
			echo '<p>' . esc_html__( 'None.', 'cmp' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Add-on', 'cmp' ) . '</th><th>' . esc_html__( 'Leads (holds the key / sets homework)', 'cmp' ) . '</th><th>' . esc_html__( 'Other member', 'cmp' ) . '</th><th>' . esc_html__( 'Status', 'cmp' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $adds as $ad ) {
				$follow = (int) $ad->lead_id === (int) $ad->user_a ? (int) $ad->user_b : (int) $ad->user_a;
				echo '<tr><td>' . esc_html( isset( $labels[ $ad->kind ] ) ? $labels[ $ad->kind ][0] : $ad->kind ) . '</td><td>' . self::who( (int) $ad->lead_id ) . '</td><td>' . self::who( $follow ) . '</td><td>' . esc_html( 'active' === $ad->status ? __( 'On', 'cmp' ) : __( 'Requested, waiting', 'cmp' ) ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- who() escapes.
				echo self::form( 'addon_off', array( 'addon' => $ad->id, 'member' => $member ), __( 'Turn this off for both members?', 'cmp' ) ) . '<input type="text" name="reason" required maxlength="200" placeholder="' . esc_attr__( 'Reason', 'cmp' ) . '" /> <button class="button button-small">' . esc_html( 'active' === $ad->status ? __( 'Turn off', 'cmp' ) : __( 'Cancel request', 'cmp' ) ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		// Locks.
		$t = CMP_Install::table( 'locks' );
		echo '<h3>' . esc_html__( 'Locks (as wearer or keyholder)', 'cmp' ) . '</h3>';
		echo self::lock_rows( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE wearer_id = %d OR keyholder_id = %d ORDER BY CASE status WHEN 'proposed' THEN 0 WHEN 'locked' THEN 1 ELSE 2 END, id DESC LIMIT 100", $member, $member ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo self::record_html( $member ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	private static function render_lock( $lock_id ) {
		$l = CMP_Chastity::lock( $lock_id );
		if ( ! $l ) {
			echo '<p>' . esc_html__( 'That lock isn\'t available any more.', 'cmp' ) . '</p>';
			return;
		}
		$wu = get_userdata( $l->wearer_id );
		echo '<p><a href="' . esc_url( self::url( array( 'member' => $l->wearer_id ) ) ) . '">← ' . esc_html( $wu ? $wu->display_name : __( 'Member', 'cmp' ) ) . '</a></p>';
		echo '<h2>' . esc_html( sprintf( /* translators: %d: lock id */ __( 'Lock #%d', 'cmp' ), $l->id ) ) . '</h2>';
		echo self::lock_rows( array( $l ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo '<p class="description">' . esc_html( $l->option_name ) . ( $l->hide_timer ? ' · ' . esc_html__( 'Time left is hidden from the wearer: a planned-end change isn\'t shown to them.', 'cmp' ) : '' ) . '</p>';

		if ( 'proposed' === $l->status ) {
			echo '<h3>' . esc_html__( 'Waiting for the wearer', 'cmp' ) . '</h3><p>' . esc_html__( 'Only the wearer can accept. If their Accept keeps failing, remove this and either have the keyholder send it again, or record the lock below with the time it really started.', 'cmp' ) . '</p>';
			echo self::form( 'remove_lock', array( 'lock' => $l->id ), __( 'Remove this waiting lock? Both members are told.', 'cmp' ) ) . self::reason_field( 'cmp_ma_rr' ) . '<p><button class="button">' . esc_html__( 'Remove waiting lock', 'cmp' ) . '</button></p></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			echo self::record_html( (int) $l->wearer_id, (int) $l->keyholder_id, $l->option_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			return;
		}

		// Times.
		$ended = 'ended' === $l->status;
		echo '<h3>' . esc_html__( 'Correct the times', 'cmp' ) . '</h3>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: timezone */ __( 'Times are in the site\'s time zone (%s). Change only what\'s wrong; fields you leave as they are don\'t change.', 'cmp' ), wp_timezone_string() ) ) . '</p>';
		echo self::form( 'times', array( 'lock' => $l->id ) ) . '<table class="form-table" role="presentation">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo '<tr><th scope="row"><label for="cmp_ma_start">' . esc_html__( 'Started', 'cmp' ) . '</label></th><td><input type="datetime-local" id="cmp_ma_start" name="started_at" value="' . esc_attr( self::local( $l->started_at ) ) . '" /></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Or set days locked', 'cmp' ) . '</th><td><input type="number" name="days" min="0" max="3650" class="small-text" aria-label="' . esc_attr__( 'Days', 'cmp' ) . '" /> ' . esc_html__( 'days', 'cmp' ) . ' <input type="number" name="hours" min="0" max="23" class="small-text" aria-label="' . esc_attr__( 'Hours', 'cmp' ) . '" /> ' . esc_html__( 'hours', 'cmp' ) . '<p class="description">' . esc_html( $ended ? __( 'Counts back from the end time. Overrides Started.', 'cmp' ) : __( 'Counts back from now: the lock will show exactly this much time locked. Overrides Started.', 'cmp' ) ) . '</p></td></tr>';
		if ( $ended ) {
			echo '<tr><th scope="row"><label for="cmp_ma_end">' . esc_html__( 'Ended', 'cmp' ) . '</label></th><td><input type="datetime-local" id="cmp_ma_end" name="ended_at" value="' . esc_attr( self::local( $l->ended_at ) ) . '" /></td></tr>';
		} else {
			echo '<tr><th scope="row"><label for="cmp_ma_plan">' . esc_html__( 'Planned end', 'cmp' ) . '</label></th><td><input type="datetime-local" id="cmp_ma_plan" name="planned_end" value="' . esc_attr( self::local( $l->planned_end ) ) . '" /> <label><input type="checkbox" name="open_ended" value="1" /> ' . esc_html__( 'Open-ended', 'cmp' ) . '</label></td></tr>';
		}
		echo '</table>' . self::reason_field( 'cmp_ma_tr' ) . '<p><button class="button button-primary">' . esc_html__( 'Save correction', 'cmp' ) . '</button></p></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.

		if ( ! $ended ) {
			echo self::settings_html( $l ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			echo '<h3>' . esc_html__( 'End this lock', 'cmp' ) . '</h3>' . self::form( 'end_lock', array( 'lock' => $l->id ), __( 'End this lock now? Both members are told.', 'cmp' ) ) . self::reason_field( 'cmp_ma_er' ) . '<p><button class="button">' . esc_html__( 'End lock', 'cmp' ) . '</button></p></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		echo '<h3>' . esc_html__( 'Remove this record', 'cmp' ) . '</h3><p class="description">' . esc_html__( 'Only for a duplicate or a lock that shouldn\'t exist. Deletes it and its history for both members; this can\'t be undone (the audit log keeps the dates).', 'cmp' ) . '</p>';
		echo self::form( 'remove_lock', array( 'lock' => $l->id ), __( 'Delete this lock and its history permanently?', 'cmp' ) ) . self::reason_field( 'cmp_ma_dr' ) . '<p><button class="button button-link-delete">' . esc_html__( 'Delete lock record', 'cmp' ) . '</button></p></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/** Each member's "Show on my profile" switch for a dynamic, with a button to flip it. */
	private static function show_toggles( $d, $member ) {
		$html = '';
		foreach ( array( array( (int) $d->proposer_id, (int) $d->proposer_show ), array( (int) $d->partner_id, (int) $d->partner_show ) ) as list( $uid, $on ) ) {
			$u     = get_userdata( $uid );
			$html .= self::form( 'dyn_show', array( 'dynamic' => $d->id, 'user' => $uid, 'show' => $on ? '' : '1', 'member' => $member ) );
			$html .= esc_html( ( $u ? $u->display_name : '?' ) . ': ' . ( $on ? __( 'on', 'cmp' ) : __( 'off', 'cmp' ) ) ) . ' <input type="text" name="reason" required maxlength="200" size="12" placeholder="' . esc_attr__( 'Reason', 'cmp' ) . '" /> <button class="button button-small">' . esc_html( $on ? __( 'Turn off', 'cmp' ) : __( 'Turn on', 'cmp' ) ) . '</button></form>';
		}
		return $html;
	}

	/** Everything the wearer and keyholder can set on a lock (0.27.0). */
	private static function settings_html( $l ) {
		$opts = '';
		foreach ( CMP_Chastity::options() as $k => $o ) {
			$opts .= '<option value="' . esc_attr( $k ) . '"' . selected( $l->option_key, $k, false ) . '>' . esc_html( $o[0] ) . '</option>';
		}
		$pol = '';
		foreach ( CMP_Chastity::policies() as $k => $label ) {
			$pol .= '<option value="' . esc_attr( $k ) . '"' . selected( $l->release_policy, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$hyg = '';
		foreach ( CMP_Chastity::hygiene_choices() as $k => $label ) {
			$hyg .= '<option value="' . esc_attr( $k ) . '"' . selected( (int) $l->hygiene_minutes, (int) $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$ku   = $l->keyholder_id ? get_userdata( $l->keyholder_id ) : null;
		$box  = function ( $name, $label, $on ) {
			return '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $on, true, false ) . ' /> ' . esc_html( $label ) . '</label><br />';
		};
		$html  = '<h3>' . esc_html__( 'Settings', 'cmp' ) . '</h3><p class="description">' . esc_html__( 'What the wearer and keyholder can set themselves. Saves the whole form, so change only what\'s wrong.', 'cmp' ) . '</p>';
		$html .= self::form( 'settings', array( 'lock' => $l->id ) ) . '<table class="form-table" role="presentation">';
		$html .= '<tr><th scope="row">' . esc_html__( 'Profile badges', 'cmp' ) . '</th><td>' . $box( 'show_profile', __( 'Show the badge on the wearer\'s profile ("Locked by …" / "Locked · N days", with when they were last verified)', 'cmp' ), $l->show_profile ) . $box( 'kh_show', __( 'Name the keyholder on it, and show "Holds the key for …" on the keyholder\'s profile', 'cmp' ), $l->kh_show ) . '</td></tr>';
		$html .= '<tr><th scope="row">' . esc_html__( 'Keyholder controls', 'cmp' ) . '</th><td>' . $box( 'hide_timer', __( 'Hide the time left from the wearer', 'cmp' ), $l->hide_timer ) . $box( 'verify_daily', __( 'Daily verification photo', 'cmp' ), $l->verify_daily ) . '</td></tr>';
		$html .= '<tr><th scope="row"><label for="cmp_ma_hyg">' . esc_html__( 'Hygiene opening', 'cmp' ) . '</label></th><td><select id="cmp_ma_hyg" name="hygiene_minutes">' . $hyg . '</select></td></tr>';
		$html .= '<tr><th scope="row"><label for="cmp_ma_opt">' . esc_html__( 'Option', 'cmp' ) . '</label></th><td><select id="cmp_ma_opt" name="option">' . $opts . '</select> <input type="text" name="option_name" maxlength="60" value="' . esc_attr( 'custom' === $l->option_key ? $l->option_name : '' ) . '" placeholder="' . esc_attr__( 'Name, for Custom', 'cmp' ) . '" aria-label="' . esc_attr__( 'Custom option name', 'cmp' ) . '" /></td></tr>';
		$html .= '<tr><th scope="row"><label for="cmp_ma_pol">' . esc_html__( 'Release', 'cmp' ) . '</label></th><td><select id="cmp_ma_pol" name="release_policy">' . $pol . '</select></td></tr>';
		$html .= '<tr><th scope="row"><label for="cmp_ma_kh">' . esc_html__( 'Keyholder', 'cmp' ) . '</label></th><td><input type="text" id="cmp_ma_kh" name="keyholder" class="regular-text" value="' . esc_attr( $ku ? $ku->user_login : '' ) . '" placeholder="' . esc_attr__( 'Empty = self-lock', 'cmp' ) . '" /><p class="description">' . esc_html__( 'Username or email of a member who holds the wearer\'s key (an active dynamic with chastity, them leading). Empty makes it a self-lock.', 'cmp' ) . '</p></td></tr>';
		$html .= '</table>' . self::reason_field( 'cmp_ma_sr' ) . '<p><button class="button button-primary">' . esc_html__( 'Save settings', 'cmp' ) . '</button></p></form>';
		return $html;
	}

	/** Record a lock that should exist. */
	private static function record_html( $wearer = 0, $keyholder = 0, $option = 'a' ) {
		$wu   = $wearer ? get_userdata( $wearer ) : null;
		$ku   = $keyholder ? get_userdata( $keyholder ) : null;
		$opts = '';
		foreach ( CMP_Chastity::options() as $k => $o ) {
			if ( 'custom' !== $k ) {
				$opts .= '<option value="' . esc_attr( $k ) . '"' . selected( $option, $k, false ) . '>' . esc_html( $o[0] ) . '</option>';
			}
		}
		$html  = '<h3>' . esc_html__( 'Record a lock', 'cmp' ) . '</h3><p class="description">' . esc_html__( 'When a lock should exist but doesn\'t (for example the wearer\'s Accept failed). Leave Ended empty for a running lock. The keyholder then manages it as usual and can change the rules.', 'cmp' ) . '</p>';
		$html .= self::form( 'record', $wearer ? array( 'member' => $wearer ) : array() ) . '<table class="form-table" role="presentation">';
		$html .= '<tr><th scope="row"><label for="cmp_ma_w">' . esc_html__( 'Wearer', 'cmp' ) . '</label></th><td><input type="text" id="cmp_ma_w" name="wearer" required class="regular-text" value="' . esc_attr( $wu ? $wu->user_login : '' ) . '" placeholder="' . esc_attr__( 'Username or email', 'cmp' ) . '" /></td></tr>';
		$html .= '<tr><th scope="row"><label for="cmp_ma_k">' . esc_html__( 'Keyholder', 'cmp' ) . '</label></th><td><input type="text" id="cmp_ma_k" name="keyholder" class="regular-text" value="' . esc_attr( $ku ? $ku->user_login : '' ) . '" placeholder="' . esc_attr__( 'Username or email; empty for a self-lock', 'cmp' ) . '" /></td></tr>';
		$html .= '<tr><th scope="row"><label for="cmp_ma_o">' . esc_html__( 'Option', 'cmp' ) . '</label></th><td><select id="cmp_ma_o" name="option">' . $opts . '</select></td></tr>';
		$html .= '<tr><th scope="row"><label for="cmp_ma_rs">' . esc_html__( 'Started', 'cmp' ) . '</label></th><td><input type="datetime-local" id="cmp_ma_rs" name="started_at" /> ' . esc_html__( 'or', 'cmp' ) . ' <input type="number" name="days" min="0" max="3650" class="small-text" aria-label="' . esc_attr__( 'Days', 'cmp' ) . '" /> ' . esc_html__( 'days', 'cmp' ) . ' <input type="number" name="hours" min="0" max="23" class="small-text" aria-label="' . esc_attr__( 'Hours', 'cmp' ) . '" /> ' . esc_html__( 'hours locked so far', 'cmp' ) . '</td></tr>';
		$html .= '<tr><th scope="row"><label for="cmp_ma_rp">' . esc_html__( 'Planned end', 'cmp' ) . '</label></th><td><input type="datetime-local" id="cmp_ma_rp" name="planned_end" /> <span class="description">' . esc_html__( 'Empty = open-ended', 'cmp' ) . '</span></td></tr>';
		$html .= '<tr><th scope="row"><label for="cmp_ma_re">' . esc_html__( 'Ended', 'cmp' ) . '</label></th><td><input type="datetime-local" id="cmp_ma_re" name="ended_at" /> <span class="description">' . esc_html__( 'Only for a past lock', 'cmp' ) . '</span></td></tr>';
		$html .= '<tr><th scope="row">' . esc_html__( 'Profile badges', 'cmp' ) . '</th><td><label><input type="checkbox" name="show_profile" value="1" /> ' . esc_html__( 'Show "Locked by …" on the wearer\'s profile and "Holds the key for …" on the keyholder\'s', 'cmp' ) . '</label></td></tr>';
		$html .= '</table>' . self::reason_field( 'cmp_ma_rec' ) . '<p><button class="button button-primary">' . esc_html__( 'Record lock', 'cmp' ) . '</button></p></form>';
		return $html;
	}
}
