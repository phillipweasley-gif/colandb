<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nods (0.14.0): a quiet way to show interest, like a nod across the bar.
 * Owner, 2026-10-05: "a way to indicate interest passively … somewhat
 * neutral … related to kink but not aggressive", our own name, not another
 * app's; when two members nod at each other both are told and invited to
 * say hello; wording never assumes anyone's pronouns (names only).
 *
 * - One nod per member per member; it can be taken back. At most DAILY a day.
 * - Members only, never yourself, never across a block (a block removes
 *   nods both ways).
 * - Mutual nods count as connected for messages (straight to the inbox).
 */
class CMP_Nods {

	const NONCE = 'cmp_nods';
	const DAILY = 30;

	public static function init() {
		add_action( 'admin_post_cmp_nod', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_cmp_nod', array( 'CMP_Member_Area', 'redirect_to_login' ) );
	}

	public static function notices() {
		return array(
			'nod_sent'   => array( 'success', __( 'Nod sent. They\'ll see it under Messages → Nods.', 'cmp' ) ),
			'nod_mutual' => array( 'success', __( 'You both nodded. Say hello?', 'cmp' ) ),
			'nod_undone' => array( 'success', __( 'Nod taken back.', 'cmp' ) ),
			'nod_limit'  => array( 'error', __( 'You\'ve sent as many nods as allowed today. Try again tomorrow.', 'cmp' ) ),
			'nod_gone'   => array( 'error', __( 'That member isn\'t available.', 'cmp' ) ),
		);
	}

	private static function t() {
		return CMP_Install::table( 'nods' );
	}

	public static function has_nodded( $from, $to ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::t() . ' WHERE from_id = %d AND to_id = %d', $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function mutual( $a, $b ) {
		return self::has_nodded( $a, $b ) && self::has_nodded( $b, $a );
	}

	/** Who nodded at this member, newest first: rows of from_id, created_at, seen_at. */
	public static function received( $user_id ) {
		global $wpdb;
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT from_id, created_at, seen_at FROM ' . self::t() . ' WHERE to_id = %d ORDER BY created_at DESC LIMIT 200', $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$blocked = CMP_Messages::blocked_ids( $user_id );
		return array_values(
			array_filter(
				$rows,
				function ( $r ) use ( $blocked ) {
					return ! in_array( (int) $r->from_id, $blocked, true ) && CMP_Access::is_member( $r->from_id );
				}
			)
		);
	}

	public static function unseen_count( $user_id ) {
		$n = 0;
		foreach ( self::received( $user_id ) as $r ) {
			$n += $r->seen_at ? 0 : 1;
		}
		return $n;
	}

	public static function mark_seen( $user_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET seen_at = %s WHERE to_id = %d AND seen_at IS NULL', gmdate( 'Y-m-d H:i:s' ), $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function remove_pair( $a, $b ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t() . ' WHERE ( from_id = %d AND to_id = %d ) OR ( from_id = %d AND to_id = %d )', $a, $b, $b, $a ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ---------------------------------------------------------------- */

	private static function back( $member, $notice ) {
		$to = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$to = $to && 0 === strpos( $to, CMP_Settings::member_page_url() ) ? $to : CMP_Profiles::member_url( $member );
		wp_safe_redirect( add_query_arg( 'cmp_notice', $notice, remove_query_arg( 'cmp_notice', $to ) ) );
		exit;
	}

	public static function handle() {
		global $wpdb;
		$user_id = get_current_user_id();
		if ( ! CMP_Access::is_member( $user_id ) || ! isset( $_POST['_cmp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cmp_nonce'] ) ), self::NONCE ) ) {
			wp_safe_redirect( add_query_arg( 'cmp_notice', 'expired', CMP_Settings::member_page_url() ) );
			exit;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$member = isset( $_POST['member'] ) ? absint( $_POST['member'] ) : 0;
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		// phpcs:enable
		if ( ! $member || $member === $user_id || ! CMP_Access::is_member( $member ) || CMP_Messages::is_blocked( $user_id, $member ) ) {
			wp_safe_redirect( add_query_arg( 'cmp_notice', 'nod_gone', CMP_Settings::member_page_url() ) );
			exit;
		}
		if ( 'undo' === $do ) {
			$wpdb->delete( self::t(), array( 'from_id' => $user_id, 'to_id' => $member ), array( '%d', '%d' ) );
			self::back( $member, 'nod_undone' );
		}
		if ( self::has_nodded( $user_id, $member ) ) {
			self::back( $member, self::mutual( $user_id, $member ) ? 'nod_mutual' : 'nod_sent' );
		}
		$today = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t() . ' WHERE from_id = %d AND created_at > %s', $user_id, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $today >= self::DAILY ) {
			self::back( $member, 'nod_limit' );
		}
		$wpdb->insert( self::t(), array( 'from_id' => $user_id, 'to_id' => $member, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		$me   = wp_get_current_user()->display_name;
		$them = get_userdata( $member )->display_name;
		if ( self::has_nodded( $member, $user_id ) ) {
			// Mutual: tell both, by name only (no pronouns assumed).
			/* translators: %s: member */
			CMP_Notifications::add( $member, 'nod', sprintf( __( 'You and %s both nodded. Say hello?', 'cmp' ), $me ), CMP_Messages::url( array( 'to' => $user_id ) ) );
			/* translators: %s: member */
			CMP_Notifications::add( $user_id, 'nod', sprintf( __( 'You and %s both nodded. Say hello?', 'cmp' ), $them ), CMP_Messages::url( array( 'to' => $member ) ), false );
			self::back( $member, 'nod_mutual' );
		}
		/* translators: %s: member */
		CMP_Notifications::add( $member, 'nod', sprintf( __( '%s nodded at you.', 'cmp' ), $me ), CMP_Messages::url( array( 'box' => 'nods' ) ) );
		self::back( $member, 'nod_sent' );
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	private static function form_open( $do, $member, $back ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cmp-form cmp-inline-form"><input type="hidden" name="action" value="cmp_nod" /><input type="hidden" name="do" value="' . esc_attr( $do ) . '" /><input type="hidden" name="member" value="' . (int) $member . '" />' . ( $back ? '<input type="hidden" name="back" value="' . esc_url( $back ) . '" />' : '' ) . '<input type="hidden" name="_cmp_nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" />';
	}

	/** "Nod" / "Nodded" (tap again to take it back) for a profile header. */
	public static function button_html( $viewer_id, $owner_id, $back = '' ) {
		$sent = self::has_nodded( $viewer_id, $owner_id );
		$name = get_userdata( $owner_id );
		$name = $name ? $name->display_name : '';
		if ( $sent ) {
			/* translators: %s: member */
			return self::form_open( 'undo', $owner_id, $back ) . '<button type="submit" class="cmp-btn cmp-btn-outline cmp-nod-btn is-sent" aria-pressed="true" title="' . esc_attr( sprintf( __( 'You nodded at %s. Tap to take it back.', 'cmp' ), $name ) ) . '"><span class="cmp-nod-icon" aria-hidden="true"></span>' . esc_html( self::mutual( $viewer_id, $owner_id ) ? __( 'Nodded both ways', 'cmp' ) : __( 'Nodded', 'cmp' ) ) . '</button></form>';
		}
		/* translators: %s: member */
		return self::form_open( 'nod', $owner_id, $back ) . '<button type="submit" class="cmp-btn cmp-btn-outline cmp-nod-btn" aria-pressed="false" title="' . esc_attr( sprintf( __( 'Let %s know you noticed them. Quiet, no message needed.', 'cmp' ), $name ) ) . '"><span class="cmp-nod-icon" aria-hidden="true"></span>' . esc_html__( 'Nod', 'cmp' ) . '</button></form>';
	}

	/** Messages → Nods: who nodded at you, with Nod back / Message. */
	public static function list_html( $user_id ) {
		$rows = self::received( $user_id );
		self::mark_seen( $user_id );
		$back = CMP_Messages::url( array( 'box' => 'nods' ) );
		$html = '<section class="cmp-panel cmp-nod-list"><h3 class="cmp-panel-title">' . esc_html__( 'Nods', 'cmp' ) . '</h3>';
		$html .= '<p class="cmp-muted">' . esc_html__( 'A nod is a quiet "I noticed you". Nod back, send a message, or let it be: nobody is told if you don\'t respond.', 'cmp' ) . '</p>';
		if ( ! $rows ) {
			return $html . '<p class="cmp-empty">' . esc_html__( 'No nods yet.', 'cmp' ) . '</p></section>';
		}
		$html .= '<ul class="cmp-nod-rows">';
		foreach ( $rows as $r ) {
			$from   = (int) $r->from_id;
			$u      = get_userdata( $from );
			$name   = $u ? $u->display_name : '';
			$mutual = self::has_nodded( $user_id, $from );
			$html  .= '<li class="cmp-nod-row' . ( $r->seen_at ? '' : ' is-new' ) . '"><a class="cmp-nod-who" href="' . esc_url( CMP_Profiles::member_url( $from ) ) . '"><b>' . esc_html( $name ) . '</b><small>' . esc_html( $mutual ? __( 'You both nodded', 'cmp' ) : sprintf( /* translators: %s: time ago */ __( 'nodded %s ago', 'cmp' ), human_time_diff( strtotime( $r->created_at . ' UTC' ) ) ) ) . '</small></a><span class="cmp-actions">';
			if ( ! $mutual ) {
				$html .= self::form_open( 'nod', $from, $back ) . '<button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline cmp-nod-btn"><span class="cmp-nod-icon" aria-hidden="true"></span>' . esc_html__( 'Nod back', 'cmp' ) . '</button></form>';
			}
			$html .= '<a class="cmp-btn cmp-btn-small" href="' . esc_url( CMP_Messages::url( array( 'to' => $from ) ) ) . '">' . esc_html__( 'Message', 'cmp' ) . '</a></span></li>';
		}
		return $html . '</ul></section>';
	}

	/* ------------------------------------------------------------------
	 * Privacy
	 * ---------------------------------------------------------------- */

	public static function export_rows( $user_id ) {
		global $wpdb;
		$rows = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT to_id, created_at FROM ' . self::t() . ' WHERE from_id = %d', $user_id ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$u      = get_userdata( $r->to_id );
			$rows[] = array( 'name' => __( 'Nod you sent', 'cmp' ), 'value' => ( $u ? $u->display_name : (string) $r->to_id ) . ' · ' . $r->created_at );
		}
		return $rows;
	}

	public static function erase( $user_id ) {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t() . ' WHERE from_id = %d OR to_id = %d', $user_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
