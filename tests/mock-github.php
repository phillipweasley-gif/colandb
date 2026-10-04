<?php
// Mock of the GitHub REST API + release-asset storage, for tests/updater-e2e.sh.
// Run: MOCKGH_DIR=<work dir> php -S 127.0.0.1:8900 tests/mock-github.php
$dir = getenv( 'MOCKGH_DIR' ) ?: __DIR__;
$uri  = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
file_put_contents( $dir . '/requests.log', $_SERVER['REQUEST_METHOD'] . ' ' . $uri . ' auth=' . ( $auth ? $auth : '-' ) . ' accept=' . ( $_SERVER['HTTP_ACCEPT'] ?? '-' ) . "\n", FILE_APPEND );
$base = 'http://127.0.0.1:8900';
$repo = '/repos/phillipweasley-gif/colandb';
$ok   = 'Bearer good-token';
$state = json_decode( @file_get_contents( $dir . '/state.json' ) ?: '{}', true );

if ( $uri === $repo . '/releases' ) {
	if ( $auth !== $ok ) { http_response_code( 401 ); echo '{"message":"Bad credentials"}'; return true; }
	header( 'Content-Type: application/json' );
	$rel = array(
		array( 'tag_name' => 'community-member-planning-v0.1.0', 'prerelease' => false, 'draft' => false, 'body' => 'First release', 'html_url' => 'https://github.com/x/r1', 'published_at' => '2026-10-03T00:00:00Z',
			'assets' => array( array( 'name' => 'community-member-planning-0.1.0.zip', 'url' => "$base$repo/releases/assets/1" ) ) ),
		array( 'tag_name' => 'community-member-planning-v0.2.0', 'prerelease' => true, 'draft' => false, 'body' => "Profiles\n- avatar upload", 'html_url' => 'https://github.com/x/r2', 'published_at' => '2026-10-04T00:00:00Z',
			'assets' => array( array( 'name' => 'community-member-planning-0.2.0.zip', 'url' => "$base$repo/releases/assets/2" ) ) ),
		array( 'tag_name' => 'community-member-planning-v0.9.0', 'prerelease' => false, 'draft' => true, 'body' => 'draft', 'html_url' => '', 'published_at' => '',
			'assets' => array( array( 'name' => 'community-member-planning-0.9.0.zip', 'url' => "$base$repo/releases/assets/9" ) ) ),
		array( 'tag_name' => 'community-events-calendar-v1.26.0', 'prerelease' => false, 'draft' => false, 'body' => '', 'html_url' => '', 'published_at' => '',
			'assets' => array( array( 'name' => 'community-events-calendar-1.26.0.zip', 'url' => "$base$repo/releases/assets/5" ) ) ),
		array( 'tag_name' => 'some-other-thing', 'prerelease' => false, 'draft' => false, 'assets' => array() ),
	);
	if ( ! empty( $state['html_release'] ) ) {
		$rel[] = array( 'tag_name' => 'community-member-planning-v0.4.0', 'prerelease' => true, 'draft' => false, 'body' => 'html', 'html_url' => '', 'published_at' => '',
			'assets' => array( array( 'name' => 'community-member-planning-0.4.0.zip', 'url' => "$base$repo/releases/assets/4" ) ) );
	}
	if ( ! empty( $state['bad_release'] ) ) {
		$rel[] = array( 'tag_name' => 'community-member-planning-v0.3.0', 'prerelease' => true, 'draft' => false, 'body' => 'bad', 'html_url' => '', 'published_at' => '',
			'assets' => array( array( 'name' => 'community-member-planning-0.3.0.zip', 'url' => "$base$repo/releases/assets/3" ) ) );
	}
	echo json_encode( $rel );
	return true;
}
if ( preg_match( '#^' . preg_quote( $repo, '#' ) . '/releases/assets/(\d+)$#', $uri, $m ) ) {
	if ( $auth !== $ok ) { http_response_code( 404 ); echo '{"message":"Not Found"}'; return true; }
	if ( ( $_SERVER['HTTP_ACCEPT'] ?? '' ) !== 'application/octet-stream' ) { header( 'Content-Type: application/json' ); echo '{"json":"metadata"}'; return true; }
	header( 'Location: ' . $base . '/storage/' . $m[1] . '?sig=abc', true, 302 );
	return true;
}
if ( preg_match( '#^/storage/(\d+)$#', $uri, $m ) ) {
	if ( $auth ) { http_response_code( 400 ); echo '<Error>Only one auth mechanism allowed</Error>'; return true; }
	if ( '4' === $m[1] ) { header( 'Content-Type: text/html' ); echo '<html><body><h1>Access denied</h1><p>Request blocked by proxy</p></body></html>'; return true; }
	$f = $dir . '/zips/' . $m[1] . '.zip';
	if ( ! file_exists( $f ) ) { http_response_code( 404 ); return true; }
	header( 'Content-Type: application/octet-stream' ); readfile( $f );
	return true;
}
http_response_code( 404 );
return true;
