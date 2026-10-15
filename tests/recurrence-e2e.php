<?php
// Run by recurrence-e2e.sh through `wp eval-file`. Prints one "<0|1> <label>" line per check.
// Saves go through the real hooks (save_post_cec_event, transition_post_status) with the
// $_POST that wp-admin's post.php sends, as tests/seed.php does.
wp_set_current_user( 1 );
$emailed = array();
add_action( 'transition_post_status', function ( $n, $o, $p ) use ( &$emailed ) {
	if ( 'cec_event' === $p->post_type && 'publish' === $n && $n !== $o && ! CEC_Recurrence::is_occurrence( $p->ID ) ) {
		$emailed[] = $p->ID; // CEC_Subscribers sends "New event" for exactly these.
	}
}, 5, 3 );
$check = function ( $label, $pass ) { echo ( $pass ? 1 : 0 ) . " $label\n"; };
$kids  = function ( $root ) {
	$ids = get_posts( array( 'post_type' => 'cec_event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_cec_recurrence_parent_id', 'meta_value' => $root ) );
	$out = array();
	foreach ( $ids as $id ) {
		$out[ $id ] = get_post_status( $id ) . ' ' . get_post_meta( $id, '_cec_start', true );
	}
	asort( $out );
	return $out;
};
$form = function ( $root, $until ) {
	return array(
		'post_ID'                 => $root,
		'cec_event_meta_nonce'    => wp_create_nonce( 'cec_save_event_meta' ),
		'cec_start'               => '2030-11-12T19:00',
		'cec_end'                 => '2030-11-12T23:00',
		'cec_event_status'        => 'scheduled',
		'cec_recurrence_rule'     => 'monthly_weekday',
		'cec_recurrence_until'    => $until,
		'cec_recurrence_ordinals' => array( '2' ),
		'cec_recurrence_weekdays' => array( '2' ),
	);
};
$dates = array( 'publish 2030-12-10T19:00', 'publish 2031-01-14T19:00', 'publish 2031-02-11T19:00', 'publish 2031-03-11T19:00', 'publish 2031-04-08T19:00' );

// A member submits a monthly "2nd Tuesday" series (as the submission form does): pending root + dates.
$_POST = array();
$root  = wp_insert_post( array( 'post_type' => 'cec_event', 'post_status' => 'pending', 'post_title' => 'ZZ Recurrence Series', 'post_content' => 'x', 'post_excerpt' => 'x' ) );
foreach ( array( '_cec_start' => '2030-11-12T19:00', '_cec_end' => '2030-11-12T23:00', '_cec_recurrence_rule' => 'monthly_weekday', '_cec_recurrence_until' => '2031-04-30', '_cec_recurrence_ordinals' => '2', '_cec_recurrence_weekdays' => '2' ) as $k => $v ) {
	update_post_meta( $root, $k, $v );
}
CEC_Recurrence::update_series( $root );
$check( 'submitted series: 5 pending dates, one per month', array_values( $kids( $root ) ) === str_replace( 'publish', 'pending', $dates ) );

// An admin approves it with Publish on the wp-admin edit screen.
$emailed = array();
$_POST   = $form( $root, '2031-04-30' );
wp_update_post( array( 'ID' => $root, 'post_status' => 'publish' ) );
$_POST   = array();
$check( 'published from wp-admin: every date keeps its own day (1.45.0 moved all to the first)', array_values( $kids( $root ) ) === $dates );
$check( '...and only the series sends a "New event" email', $emailed === array( $root ) );

// The admin changes "Repeat until" (a schedule change regenerates the dates).
$emailed = array();
$_POST   = $form( $root, '2031-02-28' );
wp_update_post( array( 'ID' => $root, 'post_title' => 'ZZ Recurrence Series' ) );
$_POST   = array();
$all     = get_posts( array( 'post_type' => 'cec_event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 's' => 'ZZ Recurrence Series' ) );
$check( 'changing the schedule in wp-admin regenerates once (1.45.0 ran away creating dates of dates)', array_values( $kids( $root ) ) === array_slice( $dates, 0, 3 ) && 4 === count( $all ) );
$check( '...without a "New event" email per regenerated date', array() === $emailed );

foreach ( $all as $id ) {
	wp_delete_post( $id, true );
}
