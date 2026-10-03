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
