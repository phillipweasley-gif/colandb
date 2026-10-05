<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Member messages (0.11.0): private one-to-one text conversations, with
 * block and report.
 *
 * - Any full member can write to any other. Between members who aren't
 *   connected (no active dynamic) the first message is a request: it waits
 *   under Requests until the recipient accepts, and the sender can send at
 *   most REQUEST_MAX messages until then. Members can turn requests off.
 *   At most DAILY_NEW new conversations a day per member.
 * - Block (either direction) stops messages, hides each member's profile,
 *   feed posts and conversation from the other, stops dynamic proposals, and
 *   ends any dynamic between them. The blocked member isn't told.
 * - Report: a reason and optional note; the site admin is emailed a link,
 *   and administrators can read the whole reported conversation on
 *   Users → Message reports. Otherwise nobody but the two members can read
 *   a conversation.
 * - Deleting a conversation hides it from your side only (up to its last
 *   message; a new message brings it back with just the new ones).
 */
class CMP_Messages {

	const TAB         = 'messages';
	const NONCE       = 'cmp_messages';
	const MAX_BODY    = 2000;
	const REQUEST_MAX = 3;
	const DAILY_NEW   = 20;
	const PAGE        = 'cmp-message-reports';
	const META_NO_REQ = 'cmp_msg_requests_off';

	public static function init() {
		add_action( 'admin_post_cmp_msg', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_cmp_msg', array( 'CMP_Member_Area', 'redirect_to_login' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_cmp_msg_report_close', array( __CLASS__, 'handle_report_close' ) );
	}

	public static function reasons() {
		return array(
			'harassment' => __( 'Harassment or threats', 'cmp' ),
			'sexual'     => __( 'Unwanted sexual content', 'cmp' ),
			'spam'       => __( 'Spam or scam', 'cmp' ),
			'underage'   => __( 'Underage or not consenting', 'cmp' ),
			'other'      => __( 'Something else', 'cmp' ),
		);
	}

	public static function url( $args = array(), $notice = '' ) {
		$url = add_query_arg( array_merge( array( 'cmp_tab' => self::TAB ), $args ), CMP_Settings::member_page_url() );
		return $notice ? add_query_arg( 'cmp_notice', $notice, $url ) : $url;
	}

	public static function notices() {
		return array(
			'msg_sent'      => array( 'success', __( 'Sent.', 'cmp' ) ),
			'msg_requested' => array( 'success', __( 'Sent as a request. They\'ll see it under Requests and can accept it.', 'cmp' ) ),
			'msg_accepted'  => array( 'success', __( 'Request accepted.', 'cmp' ) ),
			'msg_deleted'   => array( 'success', __( 'Conversation deleted from your messages.', 'cmp' ) ),
			'msg_blocked'   => array( 'success', __( 'Blocked. They can\'t message you or see your profile, and they aren\'t told.', 'cmp' ) ),
			'msg_unblocked' => array( 'success', __( 'Unblocked.', 'cmp' ) ),
			'msg_reported'  => array( 'success', __( 'Thank you. The site team has been told and will review the conversation.', 'cmp' ) ),
			'msg_saved'     => array( 'success', __( 'Saved.', 'cmp' ) ),
			'msg_empty'     => array( 'error', __( 'Write a message first.', 'cmp' ) ),
			'msg_long'      => array( 'error', __( 'Messages can be at most 2,000 characters.', 'cmp' ) ),
			'msg_wait'      => array( 'error', __( 'Wait for them to accept your request before sending more.', 'cmp' ) ),
			'msg_limit'     => array( 'error', __( 'You\'ve started as many new conversations as allowed today. Try again tomorrow.', 'cmp' ) ),
			'msg_closed'    => array( 'error', __( 'This member isn\'t accepting messages from people they\'re not connected with.', 'cmp' ) ),
			'msg_gone'      => array( 'error', __( 'That conversation isn\'t available.', 'cmp' ) ),
		);
	}

	/* ------------------------------------------------------------------
	 * Data
	 * ---------------------------------------------------------------- */

	private static function t( $name ) {
		return CMP_Install::table( $name );
	}

	public static function is_blocked( $a, $b ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::t( 'blocks' ) . ' WHERE ( blocker_id = %d AND blocked_id = %d ) OR ( blocker_id = %d AND blocked_id = %d ) LIMIT 1', $a, $b, $b, $a ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function i_blocked( $me, $them ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::t( 'blocks' ) . ' WHERE blocker_id = %d AND blocked_id = %d', $me, $them ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Everyone this member blocked or was blocked by (for filtering lists). */
	public static function blocked_ids( $user_id ) {
		global $wpdb;
		$t = self::t( 'blocks' );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT blocked_id FROM $t WHERE blocker_id = %d UNION SELECT blocker_id FROM $t WHERE blocked_id = %d", $user_id, $user_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function conversation( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'conversations' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function between( $a, $b ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'conversations' ) . ' WHERE user_a = %d AND user_b = %d', min( $a, $b ), max( $a, $b ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function side( $conv, $user_id ) {
		return (int) $conv->user_a === (int) $user_id ? 'a' : ( (int) $conv->user_b === (int) $user_id ? 'b' : '' );
	}

	public static function other( $conv, $user_id ) {
		return (int) $conv->user_a === (int) $user_id ? (int) $conv->user_b : (int) $conv->user_a;
	}

	/** A member of the conversation, both still members, not blocked. */
	public static function can_use( $conv, $user_id ) {
		return $conv && self::side( $conv, $user_id ) && CMP_Access::is_member( self::other( $conv, $user_id ) ) && ! self::is_blocked( $conv->user_a, $conv->user_b );
	}

	/** Messages after the member's "deleted" point, oldest first. */
	public static function messages( $conv, $user_id, $limit = 100 ) {
		global $wpdb;
		$since = (int) $conv->{ self::side( $conv, $user_id ) . '_deleted_id' };
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, sender_id, body, created_at FROM ' . self::t( 'messages' ) . ' WHERE conversation_id = %d AND id > %d ORDER BY id DESC LIMIT %d', $conv->id, $since, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_reverse( $rows );
	}

	/**
	 * The member's conversations, newest first.
	 *
	 * @param string $box 'inbox' (open, plus requests they sent) or 'requests' (requests sent to them).
	 */
	public static function conversations( $user_id, $box = 'inbox' ) {
		global $wpdb;
		$t    = self::t( 'conversations' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE ( user_a = %d OR user_b = %d ) ORDER BY last_message_at DESC LIMIT 200", $user_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $c ) {
			$side    = self::side( $c, $user_id );
			if ( ! self::can_use( $c, $user_id ) || (int) $c->{ $side . '_deleted_id' } >= (int) $c->last_message_id ) {
				continue;
			}
			$incoming_request = 'request' === $c->status && (int) $c->started_by !== (int) $user_id;
			if ( ( 'requests' === $box ) === $incoming_request ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	public static function is_unread( $conv, $user_id ) {
		// By message ID, not time: two messages can share a second.
		return (int) $conv->last_sender_id !== (int) $user_id && (int) $conv->{ self::side( $conv, $user_id ) . '_read_id' } < (int) $conv->last_message_id;
	}

	/** Unread inbox conversations + waiting requests, for the tab label. */
	public static function unread_count( $user_id ) {
		$n = CMP_Nods::unseen_count( $user_id );
		foreach ( array_merge( self::conversations( $user_id, 'inbox' ), self::conversations( $user_id, 'requests' ) ) as $c ) {
			$n += self::is_unread( $c, $user_id ) ? 1 : 0;
		}
		return $n;
	}

	/** Whether $from may open a new conversation with $to; a notice key if not. */
	public static function can_start( $from, $to ) {
		global $wpdb;
		if ( ! $to || (int) $to === (int) $from || ! CMP_Access::is_member( $to ) || self::is_blocked( $from, $to ) ) {
			return 'msg_gone';
		}
		if ( ! self::connected( $from, $to ) && get_user_meta( $to, self::META_NO_REQ, true ) ) {
			return 'msg_closed';
		}
		$today = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'conversations' ) . ' WHERE started_by = %d AND created_at > %s', $from, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $today >= self::DAILY_NEW ? 'msg_limit' : true;
	}

	/** An active dynamic, or mutual nods (0.14.0). */
	public static function connected( $a, $b ) {
		return (bool) CMP_Dynamics::active_between( $a, $b ) || CMP_Nods::mutual( $a, $b );
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ---------------------------------------------------------------- */

	private static function go( $args, $notice ) {
		wp_safe_redirect( self::url( $args, $notice ) );
		exit;
	}

	private static function posted( $key ) {
		return isset( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

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
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		switch ( $do ) {
			case 'send':
				self::send( $user_id );
				break;
			case 'block':
				self::block( $user_id, self::posted( 'member' ) );
				break;
			case 'unblock':
				self::unblock( $user_id, self::posted( 'member' ) );
				break;
			case 'report':
				self::report( $user_id, self::posted( 'member' ) );
				break;
			case 'settings':
				update_user_meta( $user_id, self::META_NO_REQ, empty( $_POST['requests'] ) ? 1 : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				self::go( array(), 'msg_saved' );
				break;
		}
		$conv = self::conversation( self::posted( 'conversation' ) );
		if ( ! self::can_use( $conv, $user_id ) ) {
			self::go( array(), 'msg_gone' );
		}
		$side = self::side( $conv, $user_id );
		global $wpdb;
		if ( 'accept' === $do && 'request' === $conv->status && (int) $conv->started_by !== (int) $user_id ) {
			$wpdb->update( self::t( 'conversations' ), array( 'status' => 'open' ), array( 'id' => $conv->id ) );
			self::go( array( 'c' => $conv->id ), 'msg_accepted' );
		}
		if ( 'delete' === $do ) {
			$wpdb->update( self::t( 'conversations' ), array( $side . '_deleted_id' => (int) $conv->last_message_id ), array( 'id' => $conv->id ) );
			self::go( array(), 'msg_deleted' );
		}
		self::go( array(), 'msg_gone' );
	}

	private static function send( $user_id ) {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in handle().
		$body = isset( $_POST['body'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) ) : '';
		// phpcs:enable
		$conv = self::posted( 'conversation' ) ? self::conversation( self::posted( 'conversation' ) ) : self::between( $user_id, self::posted( 'to' ) );
		$to   = $conv ? self::other( $conv, $user_id ) : self::posted( 'to' );
		$back = $conv ? array( 'c' => $conv->id ) : array( 'to' => $to );
		if ( '' === $body ) {
			self::go( $back, 'msg_empty' );
		}
		if ( mb_strlen( $body ) > self::MAX_BODY ) {
			self::go( $back, 'msg_long' );
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( $conv ) {
			if ( ! self::can_use( $conv, $user_id ) ) {
				self::go( array(), 'msg_gone' );
			}
			if ( 'request' === $conv->status ) {
				if ( (int) $conv->started_by === (int) $user_id ) {
					$mine = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'messages' ) . ' WHERE conversation_id = %d AND sender_id = %d', $conv->id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					if ( $mine >= self::REQUEST_MAX && ! self::connected( $user_id, $to ) ) {
						self::go( $back, 'msg_wait' );
					}
				} else {
					// Replying to a request accepts it.
					$wpdb->update( self::t( 'conversations' ), array( 'status' => 'open' ), array( 'id' => $conv->id ) );
					$conv->status = 'open';
				}
			}
		} else {
			$ok = self::can_start( $user_id, $to );
			if ( true !== $ok ) {
				self::go( $back, $ok );
			}
			$wpdb->insert(
				self::t( 'conversations' ),
				array(
					'user_a'          => min( $user_id, $to ),
					'user_b'          => max( $user_id, $to ),
					'started_by'      => $user_id,
					'status'          => self::connected( $user_id, $to ) ? 'open' : 'request',
					'last_message_at' => $now,
					'last_sender_id'  => $user_id,
					'created_at'      => $now,
				)
			);
			$conv = self::conversation( (int) $wpdb->insert_id );
			if ( 'request' === $conv->status ) {
				/* translators: %s: member name */
				CMP_Notifications::add( $to, 'message', sprintf( __( '%s sent you a message request.', 'cmp' ), wp_get_current_user()->display_name ), self::url( array( 'box' => 'requests' ) ) );
			}
		}
		$wpdb->insert( self::t( 'messages' ), array( 'conversation_id' => $conv->id, 'sender_id' => $user_id, 'body' => $body, 'created_at' => $now ) );
		$mid  = (int) $wpdb->insert_id;
		$side = self::side( $conv, $user_id );
		$wpdb->update(
			self::t( 'conversations' ),
			array( 'last_message_at' => $now, 'last_sender_id' => $user_id, 'last_message_id' => $mid, $side . '_read_id' => $mid ),
			array( 'id' => $conv->id )
		);
		self::go( array( 'c' => $conv->id ), 'request' === $conv->status ? 'msg_requested' : 'msg_sent' );
	}

	/** Also ends any dynamic between them (and with it, homework and keyholding). */
	private static function block( $user_id, $them ) {
		global $wpdb;
		if ( ! $them || $them === $user_id ) {
			self::go( array(), 'msg_gone' );
		}
		if ( ! self::i_blocked( $user_id, $them ) ) {
			$wpdb->insert( self::t( 'blocks' ), array( 'blocker_id' => $user_id, 'blocked_id' => $them, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
			CMP_Follows::remove_pair( $user_id, $them );
			CMP_Nods::remove_pair( $user_id, $them );
			CMP_Audit::log( 'member_blocked', 'user', $them );
			$d = CMP_Install::table( 'dynamics' );
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $d WHERE status IN ('pending','active') AND ( ( proposer_id = %d AND partner_id = %d ) OR ( proposer_id = %d AND partner_id = %d ) )", $user_id, $them, $them, $user_id ) ) as $dyn ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$was = $dyn->status;
				$wpdb->update( $d, array( 'status' => 'active' === $was ? 'ended' : 'withdrawn', 'ended_at' => gmdate( 'Y-m-d H:i:s' ), 'ended_by' => $user_id ), array( 'id' => $dyn->id ) );
				CMP_Audit::log( 'dynamic_ended', 'dynamic', $dyn->id, array( 'status' => $was ), array( 'status' => 'ended', 'ended_by' => $user_id ), 'Blocked' );
				if ( 'active' === $was ) {
					do_action( 'cmp_dynamic_ended', $dyn, $user_id );
				}
			}
		}
		self::go( array(), 'msg_blocked' );
	}

	private static function unblock( $user_id, $them ) {
		global $wpdb;
		$wpdb->delete( self::t( 'blocks' ), array( 'blocker_id' => $user_id, 'blocked_id' => $them ), array( '%d', '%d' ) );
		CMP_Audit::log( 'member_unblocked', 'user', $them );
		self::go( array( 'box' => 'blocked' ), 'msg_unblocked' );
	}

	private static function report( $user_id, $them ) {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in handle().
		$reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? mb_substr( trim( sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) ), 0, 1000 ) : '';
		$also   = ! empty( $_POST['also_block'] );
		// phpcs:enable
		if ( ! $them || $them === $user_id || ! get_userdata( $them ) || ! isset( self::reasons()[ $reason ] ) ) {
			self::go( array(), 'msg_gone' );
		}
		$conv = self::between( $user_id, $them );
		$wpdb->insert(
			self::t( 'message_reports' ),
			array(
				'reporter_id'     => $user_id,
				'reported_id'     => $them,
				'conversation_id' => $conv ? (int) $conv->id : 0,
				'reason'          => $reason,
				'note'            => $note,
				'status'          => 'open',
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$report_id = (int) $wpdb->insert_id;
		CMP_Audit::log( 'member_reported', 'user', $them, null, array( 'report' => $report_id, 'reason' => $reason ) );
		wp_mail(
			get_option( 'admin_email' ),
			/* translators: %s: site name */
			sprintf( __( '[%s] A member was reported', 'cmp' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			/* translators: 1: reason, 2: link */
			sprintf( __( "A member reported another member for: %1\$s.\n\nSign in as an administrator to review the report and the conversation:\n%2\$s", 'cmp' ), self::reasons()[ $reason ], add_query_arg( array( 'page' => self::PAGE, 'report' => $report_id ), admin_url( 'users.php' ) ) )
		);
		if ( $also ) {
			self::block( $user_id, $them );
		}
		self::go( $conv ? array( 'c' => $conv->id ) : array(), 'msg_reported' );
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	private static function form_open( $do, $extra = array(), $class = 'cmp-form' ) {
		$html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '"><input type="hidden" name="action" value="cmp_msg" /><input type="hidden" name="do" value="' . esc_attr( $do ) . '" /><input type="hidden" name="_cmp_nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" />';
		foreach ( $extra as $k => $v ) {
			$html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" />';
		}
		return $html;
	}

	private static function name( $user_id ) {
		$u = get_userdata( $user_id );
		return $u ? $u->display_name : __( 'Former member', 'cmp' );
	}

	private static function avatar( $user_id, $viewer_id ) {
		$img = CMP_Profiles::can_view( 'avatar', $user_id, $viewer_id ) ? CMP_Profile_Images::get( $user_id, 'avatar', false ) : null;
		return '<span class="cmp-msg-av" aria-hidden="true">' . ( $img ? CMP_Profile_Images::img_html( $user_id, 'avatar', $img ) : esc_html( mb_strtoupper( mb_substr( self::name( $user_id ), 0, 1 ) ) ) ) . '</span>';
	}

	private static function when( $datetime ) {
		$ts = strtotime( $datetime . ' UTC' );
		return wp_date( 'Y-m-d' ) === wp_date( 'Y-m-d', $ts ) ? wp_date( 'g:i A', $ts ) : ( time() - $ts < 6 * DAY_IN_SECONDS ? wp_date( 'D', $ts ) : wp_date( 'M j', $ts ) );
	}

	public static function tab_label( $user_id ) {
		$n = self::unread_count( $user_id );
		/* translators: %d: unread conversations */
		return $n ? sprintf( __( 'Messages (%d)', 'cmp' ), $n ) : __( 'Messages', 'cmp' );
	}

	public static function render( $user_id ) {
		wp_enqueue_script( 'cmp-member' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$c   = isset( $_GET['c'] ) ? absint( $_GET['c'] ) : 0;
		$to  = isset( $_GET['to'] ) ? absint( $_GET['to'] ) : 0;
		$box = isset( $_GET['box'] ) ? sanitize_key( wp_unslash( $_GET['box'] ) ) : 'inbox';
		// phpcs:enable
		if ( $to && ! $c ) {
			$existing = self::between( $user_id, $to );
			if ( $existing && self::can_use( $existing, $user_id ) ) {
				$c = (int) $existing->id;
			} else {
				return self::render_new( $user_id, $to );
			}
		}
		if ( $c ) {
			return self::render_thread( $user_id, self::conversation( $c ) );
		}
		$requests = self::conversations( $user_id, 'requests' );
		$box      = in_array( $box, array( 'inbox', 'requests', 'nods', 'blocked' ), true ) ? $box : 'inbox';
		$nods_n   = CMP_Nods::unseen_count( $user_id );
		$html     = '<section class="cmp-step" aria-labelledby="cmp-msg-title"><h2 id="cmp-msg-title" class="cmp-title">' . esc_html__( 'Messages', 'cmp' ) . '</h2>';
		$html    .= '<p class="cmp-muted">' . esc_html__( 'Private between you and the other member. To start one, open a member\'s profile and choose Message.', 'cmp' ) . '</p>';
		$html    .= '<nav class="cmp-msg-boxes" aria-label="' . esc_attr__( 'Message lists', 'cmp' ) . '">';
		$req_n    = count( $requests );
		foreach ( array( 'inbox' => __( 'Inbox', 'cmp' ), 'requests' => __( 'Requests', 'cmp' ), 'nods' => __( 'Nods', 'cmp' ), 'blocked' => __( 'Blocked', 'cmp' ) ) as $k => $l ) {
			$badge = 'requests' === $k ? $req_n : ( 'nods' === $k ? $nods_n : 0 );
			$html .= '<a href="' . esc_url( self::url( 'inbox' === $k ? array() : array( 'box' => $k ) ) ) . '"' . ( $k === $box ? ' aria-current="page" class="is-current"' : '' ) . '>' . esc_html( $l ) . ( $badge ? ' <span class="cmp-msg-badge">' . (int) $badge . '</span>' : '' ) . '</a>';
		}
		$html .= '</nav></section>';
		if ( 'blocked' === $box ) {
			return $html . self::render_blocked( $user_id );
		}
		if ( 'nods' === $box ) {
			return $html . CMP_Nods::list_html( $user_id );
		}
		$list  = 'requests' === $box ? $requests : self::conversations( $user_id, 'inbox' );
		$html .= '<section class="cmp-panel cmp-msg-list">';
		if ( ! $list ) {
			$html .= '<p class="cmp-empty">' . esc_html( 'requests' === $box ? __( 'No message requests.', 'cmp' ) : __( 'No messages yet.', 'cmp' ) ) . '</p>';
		}
		foreach ( $list as $conv ) {
			$other  = self::other( $conv, $user_id );
			$last   = self::messages( $conv, $user_id, 1 );
			$last   = $last ? $last[0] : null;
			$unread = self::is_unread( $conv, $user_id );
			$prefix = $last && (int) $last->sender_id === (int) $user_id ? __( 'You: ', 'cmp' ) : '';
			$state  = 'request' === $conv->status && (int) $conv->started_by === (int) $user_id ? ' · ' . __( 'request sent', 'cmp' ) : '';
			$html  .= '<a class="cmp-msg-row' . ( $unread ? ' is-unread' : '' ) . '" href="' . esc_url( self::url( array( 'c' => $conv->id ) ) ) . '">' . self::avatar( $other, $user_id ) . '<span class="cmp-msg-mid"><b>' . esc_html( self::name( $other ) ) . '</b><small>' . esc_html( $prefix . ( $last ? wp_html_excerpt( $last->body, 80, '…' ) : '' ) . $state ) . '</small></span><span class="cmp-msg-when">' . esc_html( self::when( $conv->last_message_at ) ) . ( $unread ? '<span class="cmp-msg-dot"><span class="screen-reader-text">' . esc_html__( 'Unread', 'cmp' ) . '</span></span>' : '' ) . '</span></a>';
		}
		$html .= '</section>';
		$off   = (bool) get_user_meta( $user_id, self::META_NO_REQ, true );
		$html .= '<section class="cmp-panel"><h3 class="cmp-panel-title">' . esc_html__( 'Message settings', 'cmp' ) . '</h3>' . self::form_open( 'settings' );
		$html .= '<p class="cmp-check"><input type="checkbox" id="cmp_msg_req" name="requests" value="1"' . checked( ! $off, true, false ) . ' /><label for="cmp_msg_req">' . esc_html__( 'Accept message requests from members I\'m not connected with', 'cmp' ) . '</label></p>';
		$html .= '<button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Save', 'cmp' ) . '</button></form></section>';
		return $html;
	}

	private static function render_blocked( $user_id ) {
		global $wpdb;
		$ids  = $wpdb->get_col( $wpdb->prepare( 'SELECT blocked_id FROM ' . self::t( 'blocks' ) . ' WHERE blocker_id = %d ORDER BY created_at DESC', $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$html = '<section class="cmp-panel"><h3 class="cmp-panel-title">' . esc_html__( 'Members you blocked', 'cmp' ) . '</h3>';
		if ( ! $ids ) {
			$html .= '<p class="cmp-empty">' . esc_html__( 'You haven\'t blocked anyone.', 'cmp' ) . '</p>';
		}
		foreach ( $ids as $id ) {
			$html .= '<div class="cmp-msg-blocked"><span>' . esc_html( self::name( $id ) ) . '</span>' . self::form_open( 'unblock', array( 'member' => (int) $id ), 'cmp-form cmp-inline-form' ) . '<button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Unblock', 'cmp' ) . '</button></form></div>';
		}
		return $html . '</section>';
	}

	private static function render_new( $user_id, $to ) {
		$ok   = self::can_start( $user_id, $to );
		$html = '<section class="cmp-step"><p><a href="' . esc_url( self::url() ) . '">← ' . esc_html__( 'Messages', 'cmp' ) . '</a></p>';
		if ( true !== $ok ) {
			return $html . '<p class="cmp-empty">' . esc_html( self::notices()[ $ok ][1] ) . '</p></section>';
		}
		$html .= '<h2 class="cmp-title">' . esc_html( sprintf( /* translators: %s: member */ __( 'Message %s', 'cmp' ), self::name( $to ) ) ) . '</h2>';
		if ( ! self::connected( $user_id, $to ) ) {
			$html .= '<p class="cmp-muted">' . esc_html( sprintf( /* translators: %d: messages */ __( 'You\'re not connected yet, so this goes as a request. They can accept it, delete it or block you. You can send up to %d messages until they accept.', 'cmp' ), self::REQUEST_MAX ) ) . '</p>';
		}
		$html .= self::compose_html( array( 'to' => (int) $to ) );
		return $html . '</section>';
	}

	private static function compose_html( $extra ) {
		return self::form_open( 'send', $extra, 'cmp-form cmp-msg-compose' ) . '<label for="cmp_msg_body" class="screen-reader-text">' . esc_html__( 'Message', 'cmp' ) . '</label><textarea id="cmp_msg_body" name="body" rows="2" maxlength="' . (int) self::MAX_BODY . '" required placeholder="' . esc_attr__( 'Message', 'cmp' ) . '" data-cmp-msg-body></textarea><button type="submit" class="cmp-btn">' . esc_html__( 'Send', 'cmp' ) . '</button></form>';
	}

	private static function render_thread( $user_id, $conv ) {
		global $wpdb;
		if ( ! self::can_use( $conv, $user_id ) ) {
			return '<section class="cmp-step"><p class="cmp-empty">' . esc_html__( 'That conversation isn\'t available.', 'cmp' ) . '</p><p><a href="' . esc_url( self::url() ) . '">← ' . esc_html__( 'Messages', 'cmp' ) . '</a></p></section>';
		}
		$other    = self::other( $conv, $user_id );
		$side     = self::side( $conv, $user_id );
		$incoming = 'request' === $conv->status && (int) $conv->started_by !== (int) $user_id;
		$wpdb->update( self::t( 'conversations' ), array( $side . '_read_id' => (int) $conv->last_message_id ), array( 'id' => $conv->id ) );
		$profile = CMP_Profiles::member_url( $other );
		$html    = '<section class="cmp-step cmp-msg-thread" aria-labelledby="cmp-msg-title">';
		$html   .= '<div class="cmp-msg-head"><a href="' . esc_url( self::url( $incoming ? array( 'box' => 'requests' ) : array() ) ) . '" class="cmp-msg-back" aria-label="' . esc_attr__( 'Back to messages', 'cmp' ) . '">←</a>' . self::avatar( $other, $user_id ) . '<h2 id="cmp-msg-title" class="cmp-msg-name"><a href="' . esc_url( $profile ) . '">' . esc_html( self::name( $other ) ) . '</a></h2>';
		$html   .= '<details class="cmp-msg-menu"><summary aria-label="' . esc_attr__( 'More', 'cmp' ) . '">⋯</summary><div class="cmp-msg-menu-body">';
		$html   .= '<a href="' . esc_url( $profile ) . '">' . esc_html__( 'View profile', 'cmp' ) . '</a>';
		$html   .= self::form_open( 'delete', array( 'conversation' => (int) $conv->id ) ) . '<button type="submit" class="cmp-msg-menu-item" data-cmp-confirm="' . esc_attr__( 'Delete this conversation from your messages? They keep their copy.', 'cmp' ) . '">' . esc_html__( 'Delete conversation', 'cmp' ) . '</button></form>';
		$html   .= self::form_open( 'block', array( 'member' => $other ) ) . '<button type="submit" class="cmp-msg-menu-item is-bad" data-cmp-confirm="' . esc_attr( sprintf( /* translators: %s: member */ __( 'Block %s? They won\'t be able to message you or see your profile, and any dynamic between you ends. They aren\'t told.', 'cmp' ), self::name( $other ) ) ) . '">' . esc_html( sprintf( /* translators: %s: member */ __( 'Block %s', 'cmp' ), self::name( $other ) ) ) . '</button></form>';
		$html   .= '<a href="#cmp-msg-report">' . esc_html__( 'Report', 'cmp' ) . '</a></div></details></div></section>';

		if ( $incoming ) {
			$html .= '<section class="cmp-panel cmp-msg-request"><p>' . esc_html( sprintf( /* translators: %s: member */ __( '%s isn\'t connected with you. Accept to move this to your inbox (replying accepts it too). They won\'t know you\'ve read it until you reply.', 'cmp' ), self::name( $other ) ) ) . '</p><div class="cmp-actions">';
			$html .= self::form_open( 'accept', array( 'conversation' => (int) $conv->id ), 'cmp-form cmp-inline-form' ) . '<button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Accept', 'cmp' ) . '</button></form>';
			$html .= self::form_open( 'delete', array( 'conversation' => (int) $conv->id ), 'cmp-form cmp-inline-form' ) . '<button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Delete', 'cmp' ) . '</button></form>';
			$html .= self::form_open( 'block', array( 'member' => $other ), 'cmp-form cmp-inline-form' ) . '<button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline cmp-btn-danger" data-cmp-confirm="' . esc_attr__( 'Block them? They aren\'t told.', 'cmp' ) . '">' . esc_html__( 'Block', 'cmp' ) . '</button></form></div></section>';
		}

		$html .= '<section class="cmp-panel cmp-msg-messages" data-cmp-msg-scroll>';
		$day   = '';
		foreach ( self::messages( $conv, $user_id ) as $m ) {
			$ts = strtotime( $m->created_at . ' UTC' );
			if ( wp_date( 'Y-m-d', $ts ) !== $day ) {
				$day   = wp_date( 'Y-m-d', $ts );
				$html .= '<p class="cmp-msg-day">' . esc_html( wp_date( 'Y-m-d' ) === $day ? __( 'Today', 'cmp' ) : wp_date( 'l, M j', $ts ) ) . '</p>';
			}
			$mine  = (int) $m->sender_id === (int) $user_id;
			$html .= '<div class="cmp-msg-bubble ' . ( $mine ? 'is-mine' : 'is-theirs' ) . '"><span class="screen-reader-text">' . esc_html( $mine ? __( 'You:', 'cmp' ) : self::name( $other ) . ':' ) . '</span>' . nl2br( esc_html( $m->body ) ) . '<time>' . esc_html( wp_date( 'g:i A', $ts ) ) . '</time></div>';
		}
		$html .= '</section>';
		if ( 'request' === $conv->status && ! $incoming ) {
			$html .= '<p class="cmp-muted cmp-msg-note">' . esc_html( sprintf( /* translators: %d: messages */ __( 'Waiting for them to accept your request. You can send up to %d messages until then.', 'cmp' ), self::REQUEST_MAX ) ) . '</p>';
		}
		$html .= '<section class="cmp-panel cmp-msg-composer">' . self::compose_html( array( 'conversation' => (int) $conv->id ) ) . '</section>';
		$html .= self::report_html( $other );
		return $html;
	}

	/** The report form (also used on profiles). */
	public static function report_html( $other ) {
		$html  = '<details class="cmp-panel cmp-msg-report" id="cmp-msg-report"><summary>' . esc_html( sprintf( /* translators: %s: member */ __( 'Report %s', 'cmp' ), self::name( $other ) ) ) . '</summary>';
		$html .= '<p class="cmp-muted">' . esc_html__( 'The site team will be able to read your whole conversation with this member so they can review it. Nobody else is told.', 'cmp' ) . '</p>';
		$html .= self::form_open( 'report', array( 'member' => (int) $other ) ) . '<fieldset class="cmp-fieldset"><legend>' . esc_html__( 'What\'s wrong?', 'cmp' ) . '</legend>';
		$first = true;
		foreach ( self::reasons() as $k => $l ) {
			$html .= '<p class="cmp-check cmp-check-small"><input type="radio" id="cmp_rep_' . esc_attr( $k ) . '" name="reason" value="' . esc_attr( $k ) . '"' . ( $first ? ' required' : '' ) . ' /><label for="cmp_rep_' . esc_attr( $k ) . '">' . esc_html( $l ) . '</label></p>';
			$first = false;
		}
		$html .= '</fieldset><p class="cmp-field"><label for="cmp_rep_note">' . esc_html__( 'Anything else the team should know (optional)', 'cmp' ) . '</label><textarea id="cmp_rep_note" name="note" rows="3" maxlength="1000"></textarea></p>';
		$html .= '<p class="cmp-check cmp-check-small"><input type="checkbox" id="cmp_rep_block" name="also_block" value="1" checked /><label for="cmp_rep_block">' . esc_html__( 'Also block them', 'cmp' ) . '</label></p>';
		return $html . '<button type="submit" class="cmp-btn cmp-btn-danger">' . esc_html__( 'Send report', 'cmp' ) . '</button></form></details>';
	}

	/** Message / Block / Report on another member's profile. */
	public static function profile_actions_html( $viewer_id, $owner_id, $with_report = true ) {
		$ok   = self::can_start( $viewer_id, $owner_id );
		$conv = self::between( $viewer_id, $owner_id );
		$html = '<div class="cmp-actions cmp-msg-profile">' . CMP_Follows::button_html( $viewer_id, $owner_id ) . CMP_Nods::button_html( $viewer_id, $owner_id );
		if ( ( $conv && self::can_use( $conv, $viewer_id ) ) || true === $ok ) {
			$html .= '<a class="cmp-btn" href="' . esc_url( self::url( array( 'to' => (int) $owner_id ) ) ) . '">' . esc_html__( 'Message', 'cmp' ) . '</a>';
		} elseif ( 'msg_closed' === $ok ) {
			$html .= '<p class="cmp-muted">' . esc_html__( 'Not accepting message requests.', 'cmp' ) . '</p>';
		}
		$html .= self::form_open( 'block', array( 'member' => (int) $owner_id ), 'cmp-form cmp-inline-form' ) . '<button type="submit" class="cmp-btn cmp-btn-outline cmp-btn-danger" data-cmp-confirm="' . esc_attr__( 'Block this member? They won\'t be able to message you or see your profile, and any dynamic between you ends. They aren\'t told.', 'cmp' ) . '">' . esc_html__( 'Block', 'cmp' ) . '</button></form>';
		return $html . '</div>' . ( $with_report ? self::report_html( $owner_id ) : '' );
	}

	/* ------------------------------------------------------------------
	 * Admin: reports
	 * ---------------------------------------------------------------- */

	public static function admin_menu() {
		global $wpdb;
		$open = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'message_reports' ) . " WHERE status = 'open'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		/* translators: %d: open reports */
		add_users_page( __( 'Member Reports', 'cmp' ), $open ? sprintf( __( 'Member reports (%d)', 'cmp' ), $open ) : __( 'Member reports', 'cmp' ), 'manage_options', self::PAGE, array( __CLASS__, 'render_admin' ) );
	}

	public static function handle_report_close() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cmp' ), 403 );
		}
		check_admin_referer( 'cmp_msg_report_close' );
		$id = isset( $_POST['report'] ) ? absint( $_POST['report'] ) : 0;
		$wpdb->update( self::t( 'message_reports' ), array( 'status' => 'closed', 'closed_by' => get_current_user_id(), 'closed_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) );
		CMP_Audit::log( 'member_report_closed', 'report', $id );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE ), admin_url( 'users.php' ) ) );
		exit;
	}

	public static function render_admin() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$id = isset( $_GET['report'] ) ? absint( $_GET['report'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap"><h1>' . esc_html__( 'Member reports', 'cmp' ) . '</h1>';
		if ( $id ) {
			$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'message_reports' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $r ) {
				echo '<p>' . esc_html__( 'That report doesn\'t exist.', 'cmp' ) . '</p></div>';
				return;
			}
			CMP_Audit::log( 'member_report_viewed', 'report', $id );
			echo '<p><a href="' . esc_url( add_query_arg( 'page', self::PAGE, admin_url( 'users.php' ) ) ) . '">← ' . esc_html__( 'All reports', 'cmp' ) . '</a></p>';
			echo '<table class="widefat" style="max-width:760px"><tbody>';
			echo '<tr><th>' . esc_html__( 'Reported member', 'cmp' ) . '</th><td><a href="' . esc_url( get_edit_user_link( $r->reported_id ) ) . '">' . esc_html( self::name( $r->reported_id ) ) . '</a></td></tr>';
			echo '<tr><th>' . esc_html__( 'Reported by', 'cmp' ) . '</th><td><a href="' . esc_url( get_edit_user_link( $r->reporter_id ) ) . '">' . esc_html( self::name( $r->reporter_id ) ) . '</a></td></tr>';
			echo '<tr><th>' . esc_html__( 'Reason', 'cmp' ) . '</th><td>' . esc_html( isset( self::reasons()[ $r->reason ] ) ? self::reasons()[ $r->reason ] : $r->reason ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Note', 'cmp' ) . '</th><td>' . nl2br( esc_html( $r->note ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'When', 'cmp' ) . '</th><td>' . esc_html( get_date_from_gmt( $r->created_at, 'M j, Y g:i A' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Status', 'cmp' ) . '</th><td>' . esc_html( 'open' === $r->status ? __( 'Open', 'cmp' ) : __( 'Closed', 'cmp' ) ) . '</td></tr></tbody></table>';
			echo '<h2>' . esc_html__( 'The whole conversation', 'cmp' ) . '</h2>';
			$msgs = $r->conversation_id ? $wpdb->get_results( $wpdb->prepare( 'SELECT sender_id, body, created_at FROM ' . self::t( 'messages' ) . ' WHERE conversation_id = %d ORDER BY id', $r->conversation_id ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $msgs ) {
				echo '<p>' . esc_html__( 'They have no messages with each other.', 'cmp' ) . '</p>';
			}
			echo '<div style="max-width:760px">';
			foreach ( $msgs as $m ) {
				echo '<p style="border-left:3px solid ' . ( (int) $m->sender_id === (int) $r->reported_id ? '#d63638' : '#2271b1' ) . ';padding:4px 10px;margin:8px 0;background:#fff"><strong>' . esc_html( self::name( $m->sender_id ) ) . '</strong> <small>' . esc_html( get_date_from_gmt( $m->created_at, 'M j, g:i A' ) ) . '</small><br>' . nl2br( esc_html( $m->body ) ) . '</p>';
			}
			echo '</div>';
			if ( 'open' === $r->status ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cmp_msg_report_close" /><input type="hidden" name="report" value="' . (int) $r->id . '" />';
				wp_nonce_field( 'cmp_msg_report_close' );
				echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Mark as reviewed', 'cmp' ) . '</button></p></form>';
			}
			echo '</div>';
			return;
		}
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'message_reports' ) . " ORDER BY status = 'open' DESC, id DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		echo '<p style="max-width:760px">' . esc_html__( 'Members can report each other from a conversation or a profile. Only reported conversations can be read here; opening one is recorded in the audit log.', 'cmp' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'When', 'cmp' ) . '</th><th>' . esc_html__( 'Reported member', 'cmp' ) . '</th><th>' . esc_html__( 'By', 'cmp' ) . '</th><th>' . esc_html__( 'Reason', 'cmp' ) . '</th><th>' . esc_html__( 'Status', 'cmp' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No reports.', 'cmp' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr><td><a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'report' => (int) $r->id ), admin_url( 'users.php' ) ) ) . '">' . esc_html( get_date_from_gmt( $r->created_at, 'M j, Y g:i A' ) ) . '</a></td><td>' . esc_html( self::name( $r->reported_id ) ) . '</td><td>' . esc_html( self::name( $r->reporter_id ) ) . '</td><td>' . esc_html( isset( self::reasons()[ $r->reason ] ) ? self::reasons()[ $r->reason ] : $r->reason ) . '</td><td>' . esc_html( 'open' === $r->status ? __( 'Open', 'cmp' ) : __( 'Closed', 'cmp' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/* ------------------------------------------------------------------
	 * Privacy
	 * ---------------------------------------------------------------- */

	public static function export_rows( $user_id ) {
		global $wpdb;
		$rows = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT m.body, m.created_at, c.user_a, c.user_b FROM ' . self::t( 'messages' ) . ' m JOIN ' . self::t( 'conversations' ) . ' c ON c.id = m.conversation_id WHERE m.sender_id = %d ORDER BY m.id', $user_id ) ) as $m ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$to     = (int) $m->user_a === (int) $user_id ? $m->user_b : $m->user_a;
			$rows[] = array( 'name' => __( 'Message you sent', 'cmp' ), 'value' => sprintf( '%s · %s · %s', $m->created_at, sprintf( /* translators: %s: member */ __( 'to %s', 'cmp' ), self::name( $to ) ), $m->body ) );
		}
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT blocked_id FROM ' . self::t( 'blocks' ) . ' WHERE blocker_id = %d', $user_id ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows[] = array( 'name' => __( 'Member you blocked', 'cmp' ), 'value' => self::name( $id ) );
		}
		return $rows;
	}

	/**
	 * Conversations the member was in (both sides' messages: a conversation
	 * can't be half-erased), their blocks both ways, and reports they made.
	 * Reports about them are kept for safety, with their content.
	 */
	public static function erase( $user_id ) {
		global $wpdb;
		$n = 0;
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'conversations' ) . ' WHERE user_a = %d OR user_b = %d', $user_id, $user_id ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$n += (int) $wpdb->delete( self::t( 'messages' ), array( 'conversation_id' => $id ), array( '%d' ) );
			$n += (int) $wpdb->delete( self::t( 'conversations' ), array( 'id' => $id ), array( '%d' ) );
		}
		$n += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'blocks' ) . ' WHERE blocker_id = %d OR blocked_id = %d', $user_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$n += (int) $wpdb->delete( self::t( 'message_reports' ), array( 'reporter_id' => $user_id ), array( '%d' ) );
		delete_user_meta( $user_id, self::META_NO_REQ );
		return $n;
	}
}
