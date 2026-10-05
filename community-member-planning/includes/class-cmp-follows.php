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
 * - 0.17.0: event plans moved to My calendar (CMP_Calendar), where each
 *   event has its own audience; followers are notified of the ones they may
 *   see. META_GOING is kept only for the one-time upgrade.
 */
class CMP_Follows {

	const NONCE      = 'cmp_follows';
	const META_GOING = 'cmp_show_going';

	public static function init() {
		add_action( 'admin_post_cmp_follow', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_cmp_follow', array( 'CMP_Member_Area', 'redirect_to_login' ) );
		// 0.17.0: event plans, their notifications and the calendar marker moved to CMP_Calendar.
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

	/** Followers on the Profile tab; who sees your events is chosen on My calendar (0.17.0). */
	public static function settings_html( $user_id ) {
		$html  = '<section class="cmp-panel" id="cmp-going"><h3 class="cmp-panel-title">' . esc_html__( 'Followers and your events', 'cmp' ) . '</h3>';
		$html .= '<p>' . esc_html__( 'Followers are told when you\'re going to an event they\'re allowed to see. You choose who sees each event on your calendar.', 'cmp' ) . ' <a href="' . esc_url( CMP_Calendar::url() ) . '">' . esc_html__( 'My calendar', 'cmp' ) . '</a></p>';
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
		return $rows;
	}

	public static function erase( $user_id ) {
		global $wpdb;
		delete_user_meta( $user_id, self::META_GOING );
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t() . ' WHERE follower_id = %d OR followed_id = %d', $user_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
