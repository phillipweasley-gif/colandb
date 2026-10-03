<?php
// Test-only: write every outgoing email to a file instead of sending it.
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	file_put_contents( WP_CONTENT_DIR . '/mail.log', wp_json_encode( $atts ) . "\n", FILE_APPEND );
	return true;
}, 10, 2 );
