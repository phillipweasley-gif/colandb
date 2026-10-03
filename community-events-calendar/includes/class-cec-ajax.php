<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_Ajax {

	public static function calendar_month() {
		// A host-level page cache doesn't know to skip admin-ajax.php calls
		// unless told to; these headers stop a cached response here from
		// serving a stale calendar to everyone until the cache expires.
		nocache_headers();
		check_ajax_referer( 'cec_frontend', 'nonce' );
		$month = isset( $_POST['month'] ) ? absint( $_POST['month'] ) : (int) date_i18n( 'n' );
		$year  = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : (int) date_i18n( 'Y' );
		wp_send_json_success( array( 'html' => CEC_Shortcodes::render_month_html( $month, $year ) ) );
	}

	public static function filter_events() {
		nocache_headers();
		check_ajax_referer( 'cec_frontend', 'nonce' );

		$filters = array(
			'cec_event_type'  => isset( $_POST['cec_event_type'] ) ? sanitize_title( wp_unslash( $_POST['cec_event_type'] ) ) : '',
			'cec_venue'       => isset( $_POST['cec_venue'] ) ? sanitize_title( wp_unslash( $_POST['cec_venue'] ) ) : '',
			'cec_date_range'  => isset( $_POST['cec_date_range'] ) ? sanitize_key( wp_unslash( $_POST['cec_date_range'] ) ) : 'all_upcoming',
			'cec_sort'        => isset( $_POST['cec_sort'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_sort'] ) ) : 'date_asc',
		);
		$view  = isset( $_POST['view'] ) && 'list' === $_POST['view'] ? 'list' : 'grid';
		$count = isset( $_POST['count'] ) ? absint( $_POST['count'] ) : 12;

		wp_send_json_success( array( 'html' => CEC_Shortcodes::render_events_html( $filters, $view, $count ) ) );
	}
}
