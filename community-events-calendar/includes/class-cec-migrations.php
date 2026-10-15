<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time data migrations, each gated on its own option flag rather than
 * a version number — so a later plugin update can't accidentally re-run
 * one (and re-run it against data an editor has since corrected by hand).
 */
class CEC_Migrations {

	public static function maybe_run_all() {
		if ( ! get_option( 'cec_migrated_phase1a_admission_location' ) ) {
			self::phase1a_admission_and_location();
			update_option( 'cec_migrated_phase1a_admission_location', 1 );
		}
		// 1.45.2: series whose dates were all overwritten with the first one (fixed in 1.45.1).
		if ( ! get_option( 'cec_migrated_1452_series_dates' ) ) {
			update_option( 'cec_migrated_1452_series_dates', array( 'at' => gmdate( 'Y-m-d H:i:s' ), 'series' => CEC_Recurrence::repair_collapsed_dates() ) );
		}
		// 1.45.3: events saved with an end before their start.
		if ( ! get_option( 'cec_migrated_1453_event_ends' ) ) {
			update_option( 'cec_migrated_1453_event_ends', array_merge( array( 'at' => gmdate( 'Y-m-d H:i:s' ) ), self::fix_event_ends() ) );
		}
	}

	/**
	 * 1.45.3: events saved with an end before their start, in two passes so
	 * a series' first date is fixed before its other dates follow it.
	 * 1. A series' first date or a single event whose end is earlier the
	 *    same day ("7 PM to 12 AM"): the end moves to the next day.
	 * 2. A series' other dates: each ends the same length of time after its
	 *    own start as the first date does (they used to copy its end).
	 * Anything else (an end on an earlier date, like a 2006 typo) is left
	 * for a person to correct and listed under 'left'.
	 *
	 * @return array{fixed:int[],left:int[]}
	 */
	public static function fix_event_ends() {
		global $wpdb;
		$fixed = array();
		$left  = array();
		$rows  = $wpdb->get_results(
			"SELECT p.ID, s.meta_value AS start_raw, e.meta_value AS end_raw, r.meta_value AS parent_id
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_cec_start'
			JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_cec_end'
			LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = '_cec_recurrence_parent_id'
			WHERE p.post_type = 'cec_event' AND p.post_status NOT IN ('trash','auto-draft') AND e.meta_value <> '' AND e.meta_value < s.meta_value
			ORDER BY ( r.meta_value IS NULL ) DESC, p.ID ASC"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $row ) {
			$id = (int) $row->ID;
			if ( ! $row->parent_id ) {
				if ( substr( $row->end_raw, 0, 10 ) === substr( $row->start_raw, 0, 10 ) ) {
					update_post_meta( $id, '_cec_end', CEC_Event_Helper::normalize_end( $row->start_raw, $row->end_raw ) );
					CEC_Audit_Log::log( $id, 'event_end_fixed' );
					$fixed[] = $id;
				} else {
					$left[] = $id;
				}
				continue;
			}
			$root_start = get_post_meta( (int) $row->parent_id, '_cec_start', true );
			$root_end   = get_post_meta( (int) $row->parent_id, '_cec_end', true );
			$duration   = ( $root_start && $root_end ) ? strtotime( $root_end ) - strtotime( $root_start ) : 0;
			if ( $duration > 0 ) {
				update_post_meta( $id, '_cec_end', date( 'Y-m-d\TH:i', strtotime( $row->start_raw ) + $duration ) );
				CEC_Audit_Log::log( $id, 'event_end_fixed' );
				$fixed[] = $id;
			} else {
				$left[] = $id;
			}
		}
		return array( 'fixed' => $fixed, 'left' => $left );
	}

	/**
	 * Backfills the new four-state admission model and location-mode field
	 * from the old binary is_free checkbox + free-text price note.
	 *
	 * Per the client brief's own explicit migration rule: "Migrate every
	 * legacy blank, null, or unverified-zero admission value to Price not
	 * posted. Preserve a legacy zero as Free — confirmed only if an editor
	 * verifies it against the official source." A plain is_free=1 is
	 * exactly that unverified case (made worse by a since-fixed bug where
	 * the submission form's checkbox defaulted to checked — see CHANGELOG
	 * 1.21.1 — so an unknown number of existing "Free" events were never
	 * actually confirmed free by anyone). So this never auto-promotes
	 * is_free=1 to free_confirmed; it migrates those to "not posted" and
	 * flags them with _cec_price_needs_review so editors have an easy
	 * admin-list filter to revisit and re-confirm them, instead of the
	 * Free badge just silently disappearing with no follow-up path.
	 */
	private static function phase1a_admission_and_location() {
		$ids = get_posts(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private', 'cec_in_review' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $ids as $post_id ) {
			if ( '' === get_post_meta( $post_id, '_cec_admission_status', true ) ) {
				$was_free   = '1' === get_post_meta( $post_id, '_cec_is_free', true );
				$price_note = get_post_meta( $post_id, '_cec_price_note', true );

				if ( $was_free ) {
					update_post_meta( $post_id, '_cec_admission_status', 'not_posted' );
					update_post_meta( $post_id, '_cec_price_needs_review', '1' );
				} elseif ( $price_note && preg_match( '/\$?\s*(\d+(?:\.\d{1,2})?)/', $price_note, $m ) ) {
					update_post_meta( $post_id, '_cec_admission_status', 'paid' );
					update_post_meta( $post_id, '_cec_price_amount', $m[1] );
					update_post_meta( $post_id, '_cec_price_currency', 'USD' );
				} elseif ( $price_note ) {
					update_post_meta( $post_id, '_cec_admission_status', 'paid' );
				} else {
					update_post_meta( $post_id, '_cec_admission_status', 'not_posted' );
				}
			}

			if ( '' === get_post_meta( $post_id, '_cec_location_mode', true ) ) {
				$venue_terms = get_the_terms( $post_id, 'cec_venue' );
				$has_venue   = ( $venue_terms && ! is_wp_error( $venue_terms ) ) || get_post_meta( $post_id, '_cec_venue_custom_address', true );
				update_post_meta( $post_id, '_cec_location_mode', $has_venue ? 'in_person' : 'not_posted' );
			}

			if ( '' === get_post_meta( $post_id, '_cec_time_mode', true ) ) {
				// Preserves every existing event's current display exactly —
				// "exact" is the status quo (always show a specific time).
				update_post_meta( $post_id, '_cec_time_mode', 'exact' );
			}
		}
	}
}
