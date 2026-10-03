<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress already generates a working RSS feed for any public post type
 * archive and any public taxonomy term archive — since cec_event and
 * cec_partner_org/cec_venue/cec_event_type are registered that way, the
 * "whole calendar" feed and "one organization" feed already exist at
 * /events/feed/ and /partner/<org>/feed/ (or the ?feed= query-string form
 * on plain permalinks) with zero extra registration. This class just makes
 * those feeds actually useful: soonest-first ordering, upcoming-only, and
 * event details (when/where/free) in the description instead of bare post
 * content.
 */
class CEC_Feeds {

	public static function adjust_feed_query( $query ) {
		if ( is_admin() || ! $query->is_feed() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'cec_event' !== $query->get( 'post_type' ) && ! $query->is_tax( array( 'cec_partner_org', 'cec_venue', 'cec_event_type' ) ) && ! $query->is_post_type_archive( 'cec_event' ) ) {
			return;
		}

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = array(
			'key'     => '_cec_start',
			'value'   => date( 'Y-m-d\TH:i', current_time( 'timestamp' ) ),
			'compare' => '>=',
		);
		$query->set( 'meta_query', $meta_query );
		$query->set( 'meta_key', '_cec_start' );
		$query->set( 'orderby', 'meta_value' );
		$query->set( 'order', 'ASC' );
		$query->set( 'posts_per_rss', 50 );
	}

	public static function event_description( $content ) {
		global $post;
		if ( ! $post || 'cec_event' !== $post->post_type ) {
			return $content;
		}

		$data    = CEC_Event_Helper::data( $post->ID );
		$where   = CEC_Event_Helper::location_display( $data );
		$badge   = CEC_Event_Helper::admission_badge( $data );
		$summary = array();
		if ( $data['start_display'] ) {
			$summary[] = esc_html__( 'When:', 'cec' ) . ' ' . esc_html( $data['start_display'] );
		}
		$summary[] = esc_html__( 'Where:', 'cec' ) . ' ' . esc_html( $where['label'] );
		$summary[] = esc_html( $badge['label'] );
		if ( 'scheduled' !== $data['event_status'] ) {
			$summary[] = strtoupper( esc_html( $data['event_status'] ) );
		}

		return '<p>' . implode( ' &middot; ', $summary ) . '</p>' . $content;
	}

	/**
	 * "Subscribe" links use these instead of hardcoding URLs, since WP's
	 * feed-link helpers already handle plain vs. pretty permalinks.
	 */
	public static function all_events_feed_url() {
		return get_post_type_archive_feed_link( 'cec_event' );
	}

	public static function org_feed_url( $term_id ) {
		return get_term_feed_link( $term_id, 'cec_partner_org' );
	}

	/**
	 * "Subscribe" links shown on the calendar/list views and org pages.
	 * $org_term_id narrows both the RSS link and the email-subscribe link
	 * (via ?cec_org=) to that one organization; omit for the whole calendar.
	 */
	public static function subscribe_links_html( $org_term_id = 0 ) {
		$org_term = $org_term_id ? get_term( $org_term_id, 'cec_partner_org' ) : null;
		if ( is_wp_error( $org_term ) ) {
			$org_term = null;
		}
		$feed_url       = $org_term ? self::org_feed_url( $org_term->term_id ) : self::all_events_feed_url();
		$subscribe_page = CEC_Admin_Settings::get( 'subscribe_page_url' );

		ob_start();
		?>
		<div class="cec-subscribe-links">
			<a class="cec-btn cec-btn-small cec-btn-outline" href="<?php echo esc_url( $feed_url ); ?>"><?php esc_html_e( 'Subscribe (RSS)', 'cec' ); ?></a>
			<?php if ( ! $org_term ) : ?>
				<a class="cec-btn cec-btn-small cec-btn-outline" href="<?php echo esc_url( CEC_Ical::feed_url() ); ?>"><?php esc_html_e( 'Subscribe (iCal)', 'cec' ); ?></a>
			<?php endif; ?>
			<?php if ( $subscribe_page ) :
				$email_url = $org_term ? add_query_arg( 'cec_org', $org_term->slug, $subscribe_page ) : $subscribe_page;
				?>
				<a class="cec-btn cec-btn-small cec-btn-outline" href="<?php echo esc_url( $email_url ); ?>"><?php esc_html_e( 'Get Email Updates', 'cec' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
