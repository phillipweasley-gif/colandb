<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data retention (0.15.0): once a day, removes what the privacy statement
 * says the member area keeps only for a while. Owner, 2026-10-05: "Yes" to
 * building the automatic clean-up.
 *
 * - Audit log: entries older than 2 years.
 * - Member reports (Users → Member reports): 3 years after they were made.
 * - Accounts never verified: deleted 60 days after sign-up. Cautious on
 *   purpose: only plain subscriber accounts, created after this clean-up was
 *   first installed (so older accounts are never swept up by surprise), with
 *   no posts or events of any kind and no RSVPs.
 * - Erase requests: an admin notice when a confirmed request has been
 *   waiting 20 days (the statement promises 30).
 *
 * Periods can be changed with the cmp_retention_periods filter (in days).
 * The last run's counts are kept in the cmp_retention_last option.
 */
class CMP_Retention {

	const HOOK         = 'cmp_daily_retention';
	const OPTION_SINCE = 'cmp_retention_since';
	const ERASE_WARN   = 20;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'admin_notices', array( __CLASS__, 'erase_notice' ) );
	}

	public static function schedule() {
		if ( ! get_option( self::OPTION_SINCE ) ) {
			update_option( self::OPTION_SINCE, gmdate( 'Y-m-d H:i:s' ), false );
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function periods() {
		return (array) apply_filters(
			'cmp_retention_periods',
			array(
				'audit_log'  => 730,
				'reports'    => 1095,
				'unverified' => 60,
				'calendar'   => 365,
			)
		);
	}

	/** Never-verified accounts that are safe to remove (see the class comment). */
	public static function stale_unverified() {
		global $wpdb;
		$p      = self::periods();
		$since  = (string) get_option( self::OPTION_SINCE );
		$before = gmdate( 'Y-m-d H:i:s', time() - (int) $p['unverified'] * DAY_IN_SECONDS );
		if ( '' === $since || $since >= $before ) {
			return array();
		}
		$ids = get_users(
			array(
				'role__in'     => array( 'subscriber' ),
				'fields'       => 'ID',
				'number'       => 500,
				'date_query'   => array( array( 'after' => $since, 'before' => $before, 'inclusive' => false, 'column' => 'user_registered' ) ),
				'meta_query'   => array( array( 'key' => CMP_Email_Verification::META_VERIFIED_EMAIL, 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$rsvp = defined( 'CEC_TABLE_RSVP' ) ? $wpdb->prefix . CEC_TABLE_RSVP : '';
		$out  = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$user = get_userdata( $id );
			if ( ! $user || array( 'subscriber' ) !== array_values( (array) $user->roles ) ) {
				continue; // Any other role (organizer, admin …) is never removed here.
			}
			if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d", $id ) ) ) {
				continue; // Has events or other posts.
			}
			if ( $rsvp && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $rsvp WHERE user_id = %d", $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				continue;
			}
			$out[] = $id;
		}
		return $out;
	}

	public static function run() {
		global $wpdb;
		$p    = self::periods();
		$done = array( 'audit_log' => 0, 'reports' => 0, 'unverified' => 0, 'calendar' => 0 );
		$done['reports'] = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CMP_Install::table( 'message_reports' ) . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - (int) $p['reports'] * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( self::stale_unverified() as $id ) {
			if ( wp_delete_user( $id ) ) {
				CMP_Audit::log( 'unverified_account_removed', 'user', $id, null, null, 'Never verified within the retention period' );
				++$done['unverified'];
			}
		}
		$done['calendar'] = CMP_Calendar::purge_old( (int) $p['calendar'] ); // Like RSVPs: 12 months after the event (0.17.0).
		// Last, so this run's own entries aren't the oldest thing left.
		$done['audit_log'] = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CMP_Install::table( 'audit_log' ) . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - (int) $p['audit_log'] * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		update_option( 'cmp_retention_last', array( 'time' => time(), 'removed' => $done ), false );
		return $done;
	}

	/** Confirmed erase requests waiting ERASE_WARN days or more. */
	public static function overdue_erasures() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'user_request' AND post_name = 'remove_personal_data' AND post_status = 'request-confirmed' AND post_modified_gmt < %s", gmdate( 'Y-m-d H:i:s', time() - self::ERASE_WARN * DAY_IN_SECONDS ) ) );
	}

	public static function erase_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$n = self::overdue_erasures();
		if ( ! $n ) {
			return;
		}
		/* translators: 1: requests, 2: days */
		echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( _n( '%1$d confirmed data deletion request has been waiting %2$d days or more. The privacy statement promises to complete them within 30 days.', '%1$d confirmed data deletion requests have been waiting %2$d days or more. The privacy statement promises to complete them within 30 days.', $n, 'cmp' ), $n, self::ERASE_WARN ) ) . ' <a href="' . esc_url( admin_url( 'erase-personal-data.php' ) ) . '">' . esc_html__( 'Erase Personal Data', 'cmp' ) . '</a></p></div>';
	}
}
