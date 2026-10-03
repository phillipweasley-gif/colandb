<?php
// Usage: wp eval-file snap.php <outdir>
$dir = $args[0]; @mkdir( $dir, 0777, true );
$s = get_option( 'cec_settings', array() );
foreach ( array( '0', '1' ) as $fw ) {
	$s['first_weekday'] = $fw; update_option( 'cec_settings', $s );
	foreach ( array( array( 10, 2026 ), array( 11, 2026 ), array( 12, 2026 ), array( 7, 2026 ) ) as $my ) {
		wp_set_current_user( 0 );
		file_put_contents( "$dir/m{$my[0]}-{$my[1]}-fw$fw.html", CEC_Shortcodes::render_month_html( $my[0], $my[1] ) );
	}
	wp_set_current_user( 1 );
	file_put_contents( "$dir/preview-fw$fw.html", CEC_Shortcodes::render_month_html( 11, 2026, 19 ) );
	file_put_contents( "$dir/shortcode-fw$fw.html", do_shortcode( '[cec_calendar month="11" year="2026"]' ) );
}
$s['first_weekday'] = '0'; update_option( 'cec_settings', $s );
echo count( glob( "$dir/*.html" ) ) . " snapshots\n";
