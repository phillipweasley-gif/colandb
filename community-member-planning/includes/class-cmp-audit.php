<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append-only audit log (brief §5: "Maintain append-only audit entries ...
 * Every entry includes actor ID, action, record ID, and timestamp. Updates
 * store old and new values; creates store the new value; deletes store the
 * prior value and reason."). Deliberately has no update or delete method.
 */
class CMP_Audit {

	/**
	 * @param string     $action      Machine key, e.g. 'email_verified'.
	 * @param string     $object_type e.g. 'user', 'calendar', 'connection'.
	 * @param int        $object_id
	 * @param mixed|null $old_value   Anything JSON-encodable, or null.
	 * @param mixed|null $new_value   Anything JSON-encodable, or null.
	 * @param string     $reason      Required by the brief for deletes.
	 * @param int|null   $actor_id    Defaults to the current user (0 = system).
	 * @return int|false Inserted row ID.
	 */
	public static function log( $action, $object_type, $object_id, $old_value = null, $new_value = null, $reason = '', $actor_id = null ) {
		global $wpdb;
		$inserted = $wpdb->insert(
			CMP_Install::table( 'audit_log' ),
			array(
				'actor_id'    => null === $actor_id ? get_current_user_id() : (int) $actor_id,
				'action'      => substr( sanitize_key( $action ), 0, 64 ),
				'object_type' => substr( sanitize_key( $object_type ), 0, 40 ),
				'object_id'   => (int) $object_id,
				'old_value'   => null === $old_value ? null : wp_json_encode( $old_value ),
				'new_value'   => null === $new_value ? null : wp_json_encode( $new_value ),
				'reason'      => '' === $reason ? null : $reason,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Read-only access for admin screens and tests.
	 */
	public static function for_object( $object_type, $object_id, $limit = 100 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . CMP_Install::table( 'audit_log' ) . ' WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$object_type,
				$object_id,
				$limit
			)
		);
	}
}
