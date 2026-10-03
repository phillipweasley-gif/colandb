<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * In-app notifications (brief §3: on by default; email is a separate
 * opt-in added later; no SMS/push). Quiet hours default to 10:00 p.m.–
 * 8:00 a.m. in the member's timezone: a notification created then is
 * stored immediately but only appears from 8:00 a.m. (deliver_at).
 * Members can turn off individual categories.
 */
class CMP_Notifications {

	const CATEGORIES = array(
		'account',
		'invitation',
		'access_change',
		'assignment',
		'due_reminder',
		'submission',
		'review_decision',
	);

	const QUIET_START = 22;
	const QUIET_END   = 8;

	const META_DISABLED = 'cmp_notify_disabled_categories';
	const META_TIMEZONE = 'cmp_timezone';

	/**
	 * @param bool $respect_quiet_hours False for a direct response to the
	 *                                  member's own action (e.g. welcome),
	 *                                  which would be odd to delay.
	 * @return int|false Notification ID, or false if not created.
	 */
	public static function add( $user_id, $category, $message, $url = '', $respect_quiet_hours = true ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! $user_id || ! in_array( $category, self::CATEGORIES, true ) ) {
			return false;
		}
		if ( in_array( $category, (array) get_user_meta( $user_id, self::META_DISABLED, true ), true ) ) {
			return false;
		}

		$now        = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$deliver_at = $respect_quiet_hours ? self::deliver_at( $user_id, $now ) : $now;

		$inserted = $wpdb->insert(
			CMP_Install::table( 'notifications' ),
			array(
				'user_id'    => $user_id,
				'category'   => $category,
				'message'    => wp_strip_all_tags( $message ),
				'url'        => esc_url_raw( $url ),
				'created_at' => $now->format( 'Y-m-d H:i:s' ),
				'deliver_at' => $deliver_at->format( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Inside quiet hours → the next 8:00 a.m. in the member's timezone,
	 * as UTC; otherwise $now unchanged.
	 */
	public static function deliver_at( $user_id, DateTimeImmutable $now ) {
		$local = $now->setTimezone( self::user_timezone( $user_id ) );
		$hour  = (int) $local->format( 'G' );
		if ( $hour >= self::QUIET_END && $hour < self::QUIET_START ) {
			return $now;
		}
		$release = $local->setTime( self::QUIET_END, 0 );
		if ( $hour >= self::QUIET_START ) {
			$release = $release->modify( '+1 day' );
		}
		return $release->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	public static function user_timezone( $user_id ) {
		$tz = get_user_meta( $user_id, self::META_TIMEZONE, true );
		if ( $tz && in_array( $tz, timezone_identifiers_list(), true ) ) {
			return new DateTimeZone( $tz );
		}
		return wp_timezone();
	}

	/**
	 * Delivered notifications only (deliver_at has passed), newest first.
	 */
	public static function for_user( $user_id, $limit = 20, $offset = 0 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, category, message, url, deliver_at, read_at FROM ' . CMP_Install::table( 'notifications' ) . ' WHERE user_id = %d AND deliver_at <= %s ORDER BY deliver_at DESC, id DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$user_id,
				gmdate( 'Y-m-d H:i:s' ),
				$limit,
				$offset
			)
		);
	}

	public static function unread_count( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . CMP_Install::table( 'notifications' ) . ' WHERE user_id = %d AND read_at IS NULL AND deliver_at <= %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$user_id,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Only the owner's own notification can be marked; anyone else's ID
	 * behaves exactly like a missing one.
	 *
	 * @return bool Whether a notification with this ID belongs to the user.
	 */
	public static function mark_read( $user_id, $notification_id ) {
		global $wpdb;
		$table  = CMP_Install::table( 'notifications' );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id = %d AND user_id = %d AND deliver_at <= %s", $notification_id, $user_id, gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $exists ) {
			return false;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET read_at = %s WHERE id = %d AND user_id = %d AND read_at IS NULL", gmdate( 'Y-m-d H:i:s' ), $notification_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return true;
	}

	public static function mark_all_read( $user_id ) {
		global $wpdb;
		$table = CMP_Install::table( 'notifications' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE $table SET read_at = %s WHERE user_id = %d AND read_at IS NULL AND deliver_at <= %s", $now, $user_id, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Shape returned to the member (REST and page): no user IDs.
	 */
	public static function to_public( $row ) {
		return array(
			'id'       => (int) $row->id,
			'category' => $row->category,
			'message'  => $row->message,
			'url'      => $row->url,
			'date'     => get_date_from_gmt( $row->deliver_at, 'c' ),
			'read'     => null !== $row->read_at,
		);
	}
}
