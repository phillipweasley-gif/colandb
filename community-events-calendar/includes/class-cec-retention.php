<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data retention (1.29.0): once a day, removes personal data the site's
 * privacy statement says it keeps only for a while. Owner, 2026-10-05:
 * "Yes" to building the automatic clean-up.
 *
 * - RSVPs and waitlist entries: 12 months after the event ends. The event
 *   itself stays (its headcount history goes with the rows).
 * - The submitter's email and private edit link on an event: 12 months
 *   after it ends. The event stays published.
 * - Volunteer sign-ups: 2 years after they were sent.
 * - The event change log: 2 years.
 *
 * (Both tables store site-local times, so cut-offs are local too.)
 * Periods can be changed with the cec_retention_periods filter (in days).
 * The last run's counts are kept in the cec_retention_last option.
 */
class CEC_Retention {

	const HOOK = 'cec_daily_retention';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function periods() {
		return (array) apply_filters(
			'cec_retention_periods',
			array(
				'rsvps'      => 365,
				'submitters' => 365,
				'volunteers' => 730,
				'event_log'  => 730,
			)
		);
	}

	/** Events that ended before $date (Y-m-d, the event's own local date). */
	private static function events_ended_before( $date ) {
		global $wpdb;
		// _cec_end when set, otherwise _cec_start; both are "Y-m-d\TH:i" strings.
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_cec_end'
					LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_cec_start'
					WHERE p.post_type = 'cec_event' AND COALESCE( NULLIF( e.meta_value, '' ), s.meta_value ) < %s",
					$date
				)
			)
		);
	}

	public static function run() {
		global $wpdb;
		$p     = self::periods();
		$done  = array( 'rsvps' => 0, 'waitlist' => 0, 'submitters' => 0, 'volunteers' => 0, 'event_log' => 0 );
		$rsvp  = $wpdb->prefix . CEC_TABLE_RSVP;
		$wait  = $wpdb->prefix . CEC_RSVP::TABLE_WAITLIST;
		$old   = self::events_ended_before( wp_date( 'Y-m-d', time() - (int) $p['rsvps'] * DAY_IN_SECONDS ) );
		foreach ( array_chunk( $old, 200 ) as $chunk ) {
			$in                = implode( ',', array_map( 'intval', $chunk ) );
			$done['rsvps']    += (int) $wpdb->query( "DELETE FROM $rsvp WHERE event_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$done['waitlist'] += (int) $wpdb->query( "DELETE FROM $wait WHERE event_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$old = (int) $p['submitters'] === (int) $p['rsvps'] ? $old : self::events_ended_before( wp_date( 'Y-m-d', time() - (int) $p['submitters'] * DAY_IN_SECONDS ) );
		foreach ( $old as $event_id ) {
			$had = get_post_meta( $event_id, '_cec_submitter_email', true );
			delete_post_meta( $event_id, '_cec_submitter_email' );
			delete_post_meta( $event_id, '_cec_edit_token_hash' );
			delete_post_meta( $event_id, '_cec_edit_token_expires' );
			$done['submitters'] += $had ? 1 : 0;
		}
		$done['volunteers'] = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . CEC_Volunteers::TABLE . ' WHERE created_at < %s', wp_date( 'Y-m-d H:i:s', time() - (int) $p['volunteers'] * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$done['event_log']  = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . CEC_Audit_Log::TABLE . ' WHERE created_at < %s', wp_date( 'Y-m-d H:i:s', time() - (int) $p['event_log'] * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		update_option( 'cec_retention_last', array( 'time' => time(), 'removed' => $done ), false );
		return $done;
	}
}
