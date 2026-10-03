<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records who took which moderation action on an event, so it's answerable
 * when multiple delegated Calendar Managers disagree about who approved,
 * rejected, or postponed something. Deliberately narrow: it logs
 * publish/unpublish/trash transitions (via transition_post_status, same
 * hook CEC_Recurrence/CEC_Subscribers already use) and postpone/cancel/
 * reset status changes (logged wherever CEC_Subscribers::
 * maybe_notify_status_change() is already called from) — not a general
 * content-edit changelog.
 */
class CEC_Audit_Log {

	const TABLE = 'cec_event_log';

	public static function create_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(40) NOT NULL,
			detail VARCHAR(190) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY event_id (event_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function log( $event_id, $action, $detail = '' ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'event_id'   => $event_id,
				'user_id'    => get_current_user_id(),
				'action'     => $action,
				'detail'     => $detail,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Hooked to transition_post_status alongside CEC_Recurrence's cascade
	 * and CEC_Subscribers' notification. Skips occurrences so approving a
	 * recurring series root logs one entry, not one per generated date —
	 * cascade_status() re-transitions every child post too, which would
	 * otherwise flood this table.
	 */
	public static function on_status_transition( $new_status, $old_status, $post ) {
		if ( ! is_object( $post ) || 'cec_event' !== $post->post_type || $new_status === $old_status ) {
			return;
		}
		if ( in_array( $old_status, array( 'new', 'auto-draft' ), true ) ) {
			return; // Initial submission, not a moderation action.
		}
		if ( CEC_Recurrence::is_occurrence( $post->ID ) ) {
			return;
		}
		self::log( $post->ID, 'status_' . $new_status, sprintf( '%s to %s', $old_status, $new_status ) );
	}

	public static function entries_for_event( $event_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY created_at DESC LIMIT 50", $event_id ) ); // phpcs:ignore
	}

	public static function action_label( $action ) {
		$labels = array(
			'status_publish'  => __( 'Approved / Published', 'cec' ),
			'status_draft'    => __( 'Unpublished', 'cec' ),
			'status_pending'  => __( 'Sent back to Pending', 'cec' ),
			'status_trash'    => __( 'Deleted', 'cec' ),
			'event_scheduled' => __( 'Reset to Scheduled', 'cec' ),
			'event_postponed' => __( 'Postponed', 'cec' ),
			'event_cancelled' => __( 'Cancelled', 'cec' ),
			'event_imported_ics'  => __( 'Imported from a calendar file', 'cec' ),
			'event_ics_resynced'  => __( 'Re-synced from a calendar file', 'cec' ),
			'admission_changed'   => __( 'Admission/price changed', 'cec' ),
		);
		return isset( $labels[ $action ] ) ? $labels[ $action ] : $action;
	}

	public static function actor_label( $user_id ) {
		if ( ! $user_id ) {
			return __( 'Guest (via edit link)', 'cec' );
		}
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : __( 'Unknown user', 'cec' );
	}
}
