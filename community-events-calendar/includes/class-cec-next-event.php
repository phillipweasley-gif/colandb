<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Next event" links for a Partner Organization or Titleholder (1.33.0).
 *
 *   /wp-admin/admin-post.php?action=cec_next_event&org=<term slug>
 *
 * sends the visitor to that organization's next published event (one in
 * progress counts), skipping cancelled ones. With nothing scheduled it falls
 * back to the organization's most recent event, and with no events at all to
 * its calendar page (/partner/<slug>/). Used by buttons such as the homepage's
 * "Community Events & Traditions" cards, so they never point at an old event.
 *
 * Served from admin-post.php (like the .ics files since 1.32.1) because the
 * host's CDN caches ordinary public pages for signed-out visitors for days,
 * which would freeze the redirect on whichever event was next when cached.
 * The redirect itself is sent with no-cache headers too.
 */
class CEC_Next_Event {

	const ACTION = 'cec_next_event';

	public static function init() {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'redirect' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'redirect' ) );
	}

	/**
	 * The link to put on a button.
	 */
	public static function url( $org_slug ) {
		return add_query_arg(
			array(
				'action' => self::ACTION,
				'org'    => sanitize_title( $org_slug ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Where the link should go right now, or '' for an unknown organization.
	 */
	public static function target( $org_slug ) {
		$term = get_term_by( 'slug', sanitize_title( $org_slug ), 'cec_partner_org' );
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}
		// _cec_start/_cec_end are stored as site-local "Y-m-d\TH:i" strings,
		// so they compare correctly as text.
		$now   = current_time( 'Y-m-d\TH:i' );
		$from  = gmdate( 'Y-m-d\TH:i', current_time( 'timestamp' ) - DAY_IN_SECONDS );
		$base  = array(
			'post_type'      => 'cec_event',
			'post_status'    => 'publish',
			'no_found_rows'  => true,
			'tax_query'      => array( array( 'taxonomy' => 'cec_partner_org', 'terms' => (int) $term->term_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_key'       => '_cec_start', // phpcs:ignore WordPress.DB.SlowDBQuery
		);

		// Upcoming or in progress: started no more than a day ago (multi-hour
		// events), then checked against their own end time below.
		$upcoming = get_posts(
			$base + array(
				'posts_per_page' => 50,
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_cec_start',
						'value'   => $from,
						'compare' => '>=',
					),
				),
			)
		);
		foreach ( $upcoming as $event ) {
			if ( 'cancelled' === get_post_meta( $event->ID, '_cec_event_status', true ) ) {
				continue;
			}
			$start = (string) get_post_meta( $event->ID, '_cec_start', true );
			$end   = (string) get_post_meta( $event->ID, '_cec_end', true );
			$until = ( '' !== $end && $end >= $start ) ? $end : $start;
			if ( $until >= $now ) {
				return get_permalink( $event );
			}
		}

		// Nothing scheduled: the most recent one.
		$latest = get_posts(
			$base + array(
				'posts_per_page' => 1,
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_cec_start',
						'value'   => $now,
						'compare' => '<',
					),
				),
			)
		);
		if ( $latest ) {
			return get_permalink( $latest[0] );
		}

		$link = get_term_link( $term );
		return is_wp_error( $link ) ? '' : $link;
	}

	public static function redirect() {
		$slug   = isset( $_GET['org'] ) ? sanitize_title( wp_unslash( $_GET['org'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect.
		$target = $slug ? self::target( $slug ) : '';
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Robots-Tag: noindex' );
		wp_safe_redirect( $target ? $target : home_url( '/' ), 302 );
		exit;
	}
}

/**
 * Public helper for themes and page builders: the "next event" link for a
 * Partner Organization or Titleholder, by its slug.
 */
function cec_next_event_url( $org_slug ) {
	return CEC_Next_Event::url( $org_slug );
}
