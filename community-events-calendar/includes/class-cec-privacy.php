<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires this plugin's four PII stores (RSVPs, RSVP waitlist, email
 * subscribers, volunteer inquiries) plus guest-submitter emails on events
 * into WordPress core's built-in Export/Erase Personal Data tools
 * (Tools > Export/Erase Personal Data, wp_privacy_personal_data_exporters/
 * _erasers — added in WP 4.9.6 for GDPR compliance). Without this, a site
 * owner running that flow silently never reaches this plugin's data.
 */
class CEC_Privacy {

	public static function register_exporters( $exporters ) {
		$exporters['community-events-calendar'] = array(
			'exporter_friendly_name' => __( 'Community Events Calendar', 'cec' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function register_erasers( $erasers ) {
		$erasers['community-events-calendar'] = array(
			'eraser_friendly_name' => __( 'Community Events Calendar', 'cec' ),
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	public static function export_data( $email_address, $page = 1 ) {
		$export_items = array();

		if ( $page > 1 ) {
			// Every source below is queried and returned in full on page 1;
			// nothing left to add on later pages.
			return array( 'data' => $export_items, 'done' => true );
		}

		global $wpdb;

		$rsvp_table = $wpdb->prefix . CEC_TABLE_RSVP;
		$rsvps      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$rsvp_table} WHERE email = %s", $email_address ) ); // phpcs:ignore
		foreach ( $rsvps as $row ) {
			$export_items[] = array(
				'group_id'    => 'cec-rsvps',
				'group_label' => __( 'Event RSVPs', 'cec' ),
				'item_id'     => 'cec-rsvp-' . $row->id,
				'data'        => array(
					array( 'name' => __( 'Event', 'cec' ), 'value' => get_the_title( $row->event_id ) ),
					array( 'name' => __( 'Name', 'cec' ), 'value' => $row->name ),
					array( 'name' => __( 'Email', 'cec' ), 'value' => $row->email ),
					array( 'name' => __( 'Additional Guests', 'cec' ), 'value' => $row->guests ),
					array( 'name' => __( 'RSVP Date', 'cec' ), 'value' => $row->created_at ),
				),
			);
		}

		$waitlist_table = $wpdb->prefix . CEC_RSVP::TABLE_WAITLIST;
		$waitlist       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$waitlist_table} WHERE email = %s", $email_address ) ); // phpcs:ignore
		foreach ( $waitlist as $row ) {
			$export_items[] = array(
				'group_id'    => 'cec-rsvp-waitlist',
				'group_label' => __( 'Event RSVP Waitlist', 'cec' ),
				'item_id'     => 'cec-waitlist-' . $row->id,
				'data'        => array(
					array( 'name' => __( 'Event', 'cec' ), 'value' => get_the_title( $row->event_id ) ),
					array( 'name' => __( 'Name', 'cec' ), 'value' => $row->name ),
					array( 'name' => __( 'Email', 'cec' ), 'value' => $row->email ),
					array( 'name' => __( 'Additional Guests', 'cec' ), 'value' => $row->guests ),
					array( 'name' => __( 'Joined Waitlist', 'cec' ), 'value' => $row->created_at ),
				),
			);
		}

		$sub_table    = $wpdb->prefix . CEC_Subscribers::TABLE;
		$subscribers  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$sub_table} WHERE email = %s", $email_address ) ); // phpcs:ignore
		foreach ( $subscribers as $row ) {
			$export_items[] = array(
				'group_id'    => 'cec-subscribers',
				'group_label' => __( 'Event Subscriptions', 'cec' ),
				'item_id'     => 'cec-subscriber-' . $row->id,
				'data'        => array(
					array( 'name' => __( 'Email', 'cec' ), 'value' => $row->email ),
					array( 'name' => __( 'Subscribed To', 'cec' ), 'value' => 'all' === $row->target ? __( 'All events', 'cec' ) : get_the_title( (int) $row->target ) ),
					array( 'name' => __( 'Confirmed', 'cec' ), 'value' => $row->confirmed ? __( 'Yes', 'cec' ) : __( 'No', 'cec' ) ),
					array( 'name' => __( 'Subscribed Date', 'cec' ), 'value' => $row->created_at ),
				),
			);
		}

		$vol_table    = $wpdb->prefix . CEC_Volunteers::TABLE;
		$volunteers   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$vol_table} WHERE email = %s", $email_address ) ); // phpcs:ignore
		foreach ( $volunteers as $row ) {
			$export_items[] = array(
				'group_id'    => 'cec-volunteer-inquiries',
				'group_label' => __( 'Volunteer Inquiries', 'cec' ),
				'item_id'     => 'cec-volunteer-' . $row->id,
				'data'        => array(
					array( 'name' => __( 'Name', 'cec' ), 'value' => $row->name ),
					array( 'name' => __( 'Email', 'cec' ), 'value' => $row->email ),
					array( 'name' => __( 'Phone', 'cec' ), 'value' => $row->phone ),
					array( 'name' => __( 'Interests', 'cec' ), 'value' => $row->interests ),
					array( 'name' => __( 'Availability', 'cec' ), 'value' => $row->availability ),
					array( 'name' => __( 'Message', 'cec' ), 'value' => $row->message ),
					array( 'name' => __( 'Submitted', 'cec' ), 'value' => $row->created_at ),
				),
			);
		}

		$guest_events = get_posts(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => array( 'publish', 'pending', 'draft', 'cec_in_review', 'trash' ),
				'posts_per_page' => -1,
				'meta_query'     => array( array( 'key' => '_cec_submitter_email', 'value' => $email_address ) ), // phpcs:ignore
			)
		);
		foreach ( $guest_events as $post ) {
			$export_items[] = array(
				'group_id'    => 'cec-guest-submissions',
				'group_label' => __( 'Guest Event Submissions', 'cec' ),
				'item_id'     => 'cec-guest-event-' . $post->ID,
				'data'        => array(
					array( 'name' => __( 'Event', 'cec' ), 'value' => $post->post_title ),
					array( 'name' => __( 'Submitter Email', 'cec' ), 'value' => $email_address ),
					array( 'name' => __( 'Submitted', 'cec' ), 'value' => $post->post_date ),
				),
			);
		}

		return array( 'data' => $export_items, 'done' => true );
	}

	public static function erase_data( $email_address, $page = 1 ) {
		$items_removed  = 0;
		$items_retained = false;
		$messages       = array();

		if ( $page > 1 ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => $messages,
				'done'           => true,
			);
		}

		global $wpdb;

		// RSVPs and the waitlist keep their row (guests/created_at feed
		// capacity counts and per-event history an organizer may still need)
		// but have name/email cleared rather than deleted outright.
		$rsvp_table = $wpdb->prefix . CEC_TABLE_RSVP;
		$items_removed += (int) $wpdb->update( $rsvp_table, array( 'name' => '', 'email' => '' ), array( 'email' => $email_address ) );

		$waitlist_table = $wpdb->prefix . CEC_RSVP::TABLE_WAITLIST;
		$items_removed += (int) $wpdb->update( $waitlist_table, array( 'name' => '', 'email' => '' ), array( 'email' => $email_address ) );

		$vol_table = $wpdb->prefix . CEC_Volunteers::TABLE;
		$items_removed += (int) $wpdb->update(
			$vol_table,
			array( 'name' => '', 'email' => '', 'phone' => '', 'message' => '' ),
			array( 'email' => $email_address )
		);

		// Subscribers are deleted outright rather than anonymized — an
		// anonymized row with confirmed=1 would just be a dead subscription
		// that no longer maps to anyone, functionally equivalent to
		// unsubscribing them, so unsubscribing is the more honest action.
		$sub_table       = $wpdb->prefix . CEC_Subscribers::TABLE;
		$items_removed  += (int) $wpdb->delete( $sub_table, array( 'email' => $email_address ) );

		$guest_events = get_posts(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => array( 'publish', 'pending', 'draft', 'cec_in_review', 'trash' ),
				'posts_per_page' => -1,
				'meta_query'     => array( array( 'key' => '_cec_submitter_email', 'value' => $email_address ) ), // phpcs:ignore
			)
		);
		foreach ( $guest_events as $post ) {
			delete_post_meta( $post->ID, '_cec_submitter_email' );
			delete_post_meta( $post->ID, '_cec_edit_token_hash' );
			delete_post_meta( $post->ID, '_cec_edit_token_expires' );
			$items_removed++;
			// The event itself (public content) is intentionally kept — only
			// the submitter's identifying info and their edit-link access are
			// removed, so they can no longer be traced to or manage it.
			$items_retained = true;
		}

		if ( $items_retained ) {
			$messages[] = __( 'Event listings submitted by this email address were kept as public content, but the submitter email and any active guest edit-link were removed.', 'cec' );
		}

		return array(
			'items_removed'  => $items_removed > 0,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
