<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_Admin_List {

	public static function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['cec_when']      = __( 'When', 'cec' );
				$new['cec_venue']     = __( 'Venue', 'cec' );
				$new['cec_admission'] = __( 'Admission', 'cec' );
			}
		}
		return $new;
	}

	public static function column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'cec_when':
				$start = get_post_meta( $post_id, '_cec_start', true );
				echo $start ? esc_html( date_i18n( 'M j, Y g:i a', strtotime( $start ) ) ) : '&#8212;';
				break;
			case 'cec_venue':
				$terms = get_the_terms( $post_id, 'cec_venue' );
				if ( $terms && ! is_wp_error( $terms ) ) {
					echo esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) );
				} else {
					$custom = get_post_meta( $post_id, '_cec_venue_custom_address', true );
					echo $custom ? esc_html( $custom ) : '&#8212;';
				}
				break;
			case 'cec_admission':
				$badge = CEC_Event_Helper::admission_badge( CEC_Event_Helper::data( $post_id ) );
				echo esc_html( $badge['label'] );
				if ( get_post_meta( $post_id, '_cec_price_needs_review', true ) ) {
					echo ' <span style="color:#d63638;">(' . esc_html__( 'needs review', 'cec' ) . ')</span>';
				}
				break;
		}
	}

	/**
	 * Makes the "Pending" status view easy to find for admins approving
	 * submissions, and adds a "Needs Price Review" view for events the
	 * Phase 1a admission migration flagged (see CEC_Migrations) — a
	 * previously-checked "Free" event whose migrated status is now "Price
	 * not posted" until an editor re-confirms it, so that re-confirmation
	 * has an obvious, one-click path instead of silently waiting to be
	 * noticed.
	 */
	public static function status_views( $views ) {
		$pending_count = wp_count_posts( 'cec_event' )->pending;
		if ( $pending_count > 0 ) {
			$url            = admin_url( 'edit.php?post_status=pending&post_type=cec_event' );
			$views['cec_pending_highlight'] = sprintf(
				'<a href="%s" style="color:#d63638;font-weight:600;">%s</a>',
				esc_url( $url ),
				sprintf( _n( '%d Awaiting Approval', '%d Awaiting Approval', $pending_count, 'cec' ), $pending_count )
			);
		}

		$review_query = new WP_Query(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( array( 'key' => '_cec_price_needs_review', 'value' => '1' ) ),
			)
		);
		if ( $review_query->found_posts > 0 ) {
			$url = admin_url( 'edit.php?post_type=cec_event&cec_needs_price_review=1' );
			$views['cec_needs_price_review'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $url ),
				sprintf( _n( '%d Needs Price Review', '%d Needs Price Review', $review_query->found_posts, 'cec' ), $review_query->found_posts )
			);
		}
		return $views;
	}

	/**
	 * Backs the "Needs Price Review" view link above.
	 */
	public static function filter_needs_price_review( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'cec_event' !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( empty( $_GET['cec_needs_price_review'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$query->set( 'post_status', 'any' );
		$query->set( 'meta_query', array( array( 'key' => '_cec_price_needs_review', 'value' => '1' ) ) );
	}
}
