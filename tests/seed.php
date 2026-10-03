<?php
// Run with: wp eval-file seed.php --allow-root
// Creates events through the real wp-admin meta-box save path (save_post_cec_event).
wp_set_current_user( 1 );
$base = strtotime( 'first day of next month' );
$d    = function ( $offset_days, $time ) use ( $base ) {
	return gmdate( 'Y-m-d', $base + $offset_days * DAY_IN_SECONDS ) . 'T' . $time;
};

$events = array(
	array( 'Single Day Paid', $d( 2, '18:00' ), $d( 2, '20:00' ), array( 'cec_time_mode' => 'exact', 'cec_admission_status' => 'paid', 'cec_price_amount' => '15', 'cec_price_currency' => 'USD', 'cec_location_mode' => 'in_person', 'cec_venue_custom_address' => '1 Main St', 'cec_venue_custom_city' => 'Columbus', 'cec_venue_custom_region' => 'OH', 'cec_venue_custom_country' => 'US', 'cec_timezone' => 'America/New_York' ) ),
	array( 'Multi Day Festival', $d( 4, '00:00' ), $d( 10, '23:59' ), array( 'cec_time_mode' => 'all_day', 'cec_admission_status' => 'free_confirmed', 'cec_location_mode' => 'hybrid', 'cec_online_url' => 'https://example.com/stream', 'cec_timezone' => 'America/Chicago' ) ),
	array( 'Week Crossing Conference', $d( 12, '09:00' ), $d( 16, '17:00' ), array( 'cec_time_mode' => 'start_only', 'cec_admission_status' => 'price_varies', 'cec_price_min' => '10', 'cec_price_max' => '50', 'cec_location_mode' => 'online', 'cec_online_url' => 'https://example.com/live' ) ),
	array( 'Schedule Varies Observance', $d( 1, '00:00' ), $d( 27, '00:00' ), array( 'cec_time_mode' => 'varies', 'cec_admission_status' => 'not_posted', 'cec_location_mode' => 'not_posted' ) ),
	array( 'Overlap A', $d( 13, '10:00' ), $d( 14, '12:00' ), array( 'cec_admission_status' => 'not_posted' ) ),
	array( 'Overlap B', $d( 13, '10:00' ), $d( 15, '12:00' ), array( 'cec_admission_status' => 'not_posted' ) ),
	array( 'Overlap C', $d( 13, '10:00' ), $d( 13, '12:00' ), array( 'cec_admission_status' => 'not_posted' ) ),
	array( 'Overlap D', $d( 13, '14:00' ), $d( 13, '16:00' ), array( 'cec_admission_status' => 'not_posted' ) ),
	array( 'Bad Timezone Input', $d( 20, '19:00' ), $d( 20, '21:00' ), array( 'cec_timezone' => 'Not/AZone', 'cec_admission_status' => 'bogus' ) ),
);

$ids = array();
foreach ( $events as $e ) {
	$_POST = array_merge(
		array(
			'cec_event_meta_nonce' => wp_create_nonce( 'cec_save_event_meta' ),
			'cec_start'            => $e[1],
			'cec_end'              => $e[2],
			'cec_event_status'     => 'scheduled',
		),
		$e[3]
	);
	$id    = wp_insert_post( array( 'post_type' => 'cec_event', 'post_status' => 'publish', 'post_title' => $e[0], 'post_content' => 'Body for ' . $e[0], 'post_excerpt' => 'Summary' ) );
	$ids[] = $id;
}
// Duplicate of first event for duplicate detection.
$_POST = array( 'cec_event_meta_nonce' => wp_create_nonce( 'cec_save_event_meta' ), 'cec_start' => $events[0][1], 'cec_end' => $events[0][2] );
$dup   = wp_insert_post( array( 'post_type' => 'cec_event', 'post_status' => 'pending', 'post_title' => 'Single Day Paid', 'post_content' => 'dup' ) );
$_POST = array();

foreach ( array_merge( $ids, array( $dup ) ) as $id ) {
	$m = get_post_meta( $id );
	printf( "%d %-28s start=%s end=%s tz=%s mode=%s adm=%s loc=%s\n", $id, get_the_title( $id ), $m['_cec_start'][0] ?? '-', $m['_cec_end'][0] ?? '-', $m['_cec_timezone'][0] ?? '-', $m['_cec_time_mode'][0] ?? '-', $m['_cec_admission_status'][0] ?? '-', $m['_cec_location_mode'][0] ?? '-' );
}
echo 'duplicates of ' . $dup . ': ' . wp_json_encode( CEC_Event_Helper::find_possible_duplicates( $dup ) ) . "\n";
update_option( 'cec_smoke_ids', array_merge( $ids, array( $dup ) ) );
