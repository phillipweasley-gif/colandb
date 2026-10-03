<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One shared endpoint for quick row actions, used by both the manager
 * dashboard and a submitter's own My Events page. "approve"/"reject" are
 * moderation actions and require the Calendar Manager capability; "delete"
 * and "set_status" (postpone/cancel/reset) are also allowed for the event's
 * own author, so submitters can manage their own listing without needing
 * that role.
 */
class CEC_Dashboard_Ajax {

	public static function handle() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Not authorized.', 'cec' ) ), 403 );
		}
		check_ajax_referer( 'cec_frontend', 'nonce' );

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$action   = isset( $_POST['dash_action'] ) ? sanitize_key( wp_unslash( $_POST['dash_action'] ) ) : '';

		if ( ! $event_id || 'cec_event' !== get_post_type( $event_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Event not found.', 'cec' ) ) );
		}

		$is_manager = CEC_Roles::can_manage();
		$is_owner   = (int) get_post_field( 'post_author', $event_id ) === get_current_user_id();

		switch ( $action ) {
			case 'approve':
				if ( ! $is_manager ) {
					wp_send_json_error( array( 'message' => __( 'Not authorized.', 'cec' ) ), 403 );
				}
				wp_update_post( array( 'ID' => $event_id, 'post_status' => 'publish' ) );
				wp_send_json_success( array( 'message' => __( 'Approved and published.', 'cec' ), 'status' => 'publish' ) );
				break;

			case 'reject':
				if ( ! $is_manager ) {
					wp_send_json_error( array( 'message' => __( 'Not authorized.', 'cec' ) ), 403 );
				}
				wp_update_post( array( 'ID' => $event_id, 'post_status' => 'draft' ) );
				wp_send_json_success( array( 'message' => __( 'Unpublished.', 'cec' ), 'status' => 'draft' ) );
				break;

			case 'delete':
				if ( ! $is_manager && ! $is_owner ) {
					wp_send_json_error( array( 'message' => __( 'Not authorized.', 'cec' ) ), 403 );
				}
				wp_trash_post( $event_id );
				wp_send_json_success( array( 'message' => __( 'Moved to trash.', 'cec' ), 'status' => 'trash' ) );
				break;

			case 'set_status':
				if ( ! $is_manager && ! $is_owner ) {
					wp_send_json_error( array( 'message' => __( 'Not authorized.', 'cec' ) ), 403 );
				}
				$event_status = isset( $_POST['event_status'] ) ? sanitize_key( wp_unslash( $_POST['event_status'] ) ) : 'scheduled';
				if ( ! in_array( $event_status, array( 'scheduled', 'postponed', 'cancelled' ), true ) ) {
					wp_send_json_error( array( 'message' => __( 'Invalid status.', 'cec' ) ) );
				}
				$old_status = CEC_Event_Helper::event_status( $event_id );
				update_post_meta( $event_id, '_cec_event_status', $event_status );
				if ( $old_status !== $event_status ) {
					CEC_Audit_Log::log( $event_id, 'event_' . $event_status );
				}
				CEC_Subscribers::maybe_notify_status_change( $event_id, $old_status, $event_status );
				wp_send_json_success( array( 'message' => __( 'Updated.', 'cec' ), 'event_status' => $event_status ) );
				break;

			default:
				wp_send_json_error( array( 'message' => __( 'Unknown action.', 'cec' ) ) );
		}
	}
}
