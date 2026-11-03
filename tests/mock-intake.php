<?php
/**
 * Test-only router: a fake Telegram Bot API and a fake Claude Messages API
 * for tests/telegram-intake-e2e.sh. Run with
 *   MOCK_DIR=<folder> php -S 127.0.0.1:8898 tests/mock-intake.php
 * Telegram: /bot<token>/<method> and /file/bot<token>/<path> (files in $MOCK_DIR/files).
 * Claude:   /v1/messages. The reply is chosen by a marker "FX:<name>" in
 *           the post text; with no marker and an image, "flyer". Every
 *           request is appended to $MOCK_DIR/anthropic.log.
 */
$dir   = getenv( 'MOCK_DIR' );
$token = '123456:TESTTOKENaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$path  = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$body  = file_get_contents( 'php://input' );
header( 'Content-Type: application/json' );

function tg_ok( $result ) {
	echo json_encode( array( 'ok' => true, 'result' => $result ) );
	exit;
}

if ( preg_match( '#^/file/bot([^/]+)/(.+)$#', $path, $m ) ) {
	if ( $m[1] !== $token ) {
		http_response_code( 401 );
		exit;
	}
	$f = $dir . '/files/' . basename( $m[2] );
	if ( ! is_file( $f ) ) {
		http_response_code( 404 );
		exit;
	}
	header( 'Content-Type: application/octet-stream' );
	readfile( $f );
	exit;
}

if ( preg_match( '#^/bot([^/]+)/(\w+)$#', $path, $m ) ) {
	if ( $m[1] !== $token ) {
		http_response_code( 401 );
		echo json_encode( array( 'ok' => false, 'description' => 'Unauthorized' ) );
		exit;
	}
	$p = json_decode( $body, true );
	file_put_contents( $dir . '/telegram.log', $m[2] . ' ' . $body . "\n", FILE_APPEND );
	switch ( $m[2] ) {
		case 'getMe':
			tg_ok( array( 'id' => 123456, 'is_bot' => true, 'username' => 'colb_test_bot' ) );
		case 'getWebhookInfo':
			tg_ok( array( 'url' => '', 'pending_update_count' => 0 ) );
		case 'setWebhook':
		case 'deleteWebhook':
			tg_ok( true );
		case 'getChatAdministrators':
			tg_ok( array( array( 'status' => 'creator', 'user' => array( 'id' => 777, 'is_bot' => false, 'first_name' => 'Owner' ) ), array( 'status' => 'administrator', 'user' => array( 'id' => 123456, 'is_bot' => true, 'first_name' => 'Bot' ) ) ) );
		case 'getFile':
			$id = (string) $p['file_id'];
			$sizes = array( 'big' => 50000000 );
			tg_ok( array( 'file_id' => $id, 'file_path' => 'photos/' . $id . '.jpg', 'file_size' => isset( $sizes[ $id ] ) ? $sizes[ $id ] : ( is_file( $dir . '/files/' . $id . '.jpg' ) ? filesize( $dir . '/files/' . $id . '.jpg' ) : 100 ) ) );
	}
	http_response_code( 404 );
	echo json_encode( array( 'ok' => false, 'description' => 'Not Found' ) );
	exit;
}

if ( '/v1/messages' === $path ) {
	$req = json_decode( $body, true );
	$headers = array();
	foreach ( array( 'HTTP_X_API_KEY', 'HTTP_ANTHROPIC_VERSION', 'HTTP_ANTHROPIC_BETA' ) as $h ) {
		$headers[ $h ] = isset( $_SERVER[ $h ] ) ? $_SERVER[ $h ] : '';
	}
	$text = '';
	$images = 0;
	foreach ( $req['messages'][0]['content'] as $block ) {
		if ( 'text' === $block['type'] ) {
			$text .= $block['text'];
		} elseif ( 'image' === $block['type'] ) {
			$images++;
		}
	}
	file_put_contents( $dir . '/anthropic.log', json_encode( array( 'headers' => $headers, 'model' => $req['model'], 'fallbacks' => isset( $req['fallbacks'] ) ? $req['fallbacks'] : null, 'format' => isset( $req['output_config']['format']['type'] ) ? $req['output_config']['format']['type'] : '', 'effort' => isset( $req['output_config']['effort'] ) ? $req['output_config']['effort'] : '', 'images' => $images, 'image_md5' => $images ? md5( base64_decode( $req['messages'][0]['content'][0]['source']['data'] ) ) : '', 'media_type' => $images ? $req['messages'][0]['content'][0]['source']['media_type'] : '', 'text' => $text ) ) . "\n", FILE_APPEND );

	$name = preg_match( '/FX:(\w+)/', $text, $mm ) ? $mm[1] : ( $images ? 'flyer' : 'none' );
	if ( 'http529' === $name ) {
		http_response_code( 529 );
		echo json_encode( array( 'type' => 'error', 'error' => array( 'type' => 'overloaded_error', 'message' => 'Overloaded' ) ) );
		exit;
	}
	if ( 'http401' === $name ) {
		http_response_code( 401 );
		echo json_encode( array( 'type' => 'error', 'error' => array( 'type' => 'authentication_error', 'message' => 'invalid x-api-key' ) ) );
		exit;
	}
	if ( 'refusal' === $name ) {
		echo json_encode( array( 'type' => 'message', 'stop_reason' => 'refusal', 'content' => array() ) );
		exit;
	}
	$out = fixture( $name );
	echo json_encode( array( 'type' => 'message', 'model' => $req['model'], 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => json_encode( $out ) ) ) ) );
	exit;
}

http_response_code( 404 );
echo '{}';

function d( $days ) {
	return gmdate( 'Y-m-d', time() + $days * 86400 );
}

/** A full event: every field present; $spec values are [value, evidence?, source?, confidence?]. */
function ev( $spec, $src = 'text' ) {
	$keys = array( 'title', 'short_description', 'event_type', 'start_date', 'end_date', 'start_time', 'end_time', 'timezone', 'location_mode', 'venue_name', 'address', 'city', 'region', 'country', 'online_url', 'organizer', 'official_url', 'ticket_url', 'admission', 'price_amount', 'price_min', 'price_max', 'currency', 'price_note', 'year_stated', 'schedule_notes' );
	$out  = array();
	foreach ( $keys as $k ) {
		$v = isset( $spec[ $k ] ) ? (array) $spec[ $k ] : array( '' );
		$out[ $k ] = array(
			'value'      => (string) $v[0],
			'confidence' => '' === (string) $v[0] ? 0 : ( isset( $v[3] ) ? $v[3] : 0.95 ),
			'evidence'   => isset( $v[1] ) ? $v[1] : (string) $v[0],
			'source'     => '' === (string) $v[0] ? 'none' : ( isset( $v[2] ) ? $v[2] : $src ),
		);
	}
	return $out;
}

function basic( $over = array() ) {
	return array_merge(
		array(
			'title'             => 'Leather Social Night',
			'short_description' => 'A monthly social for the leather community.',
			'event_type'        => 'Social',
			'start_date'        => d( 30 ),
			'start_time'        => '20:00',
			'end_time'          => '23:00',
			'location_mode'     => 'in_person',
			'venue_name'        => 'The Toolbox Saloon',
			'address'           => '744 N High St',
			'city'              => 'Columbus',
			'region'            => 'OH',
			'organizer'         => 'Columbus Leather Collective',
			'ticket_url'        => 'https://tickets.example.com/leather-social?utm_source=tg',
			'admission'         => array( 'paid', '$15 at the door' ),
			'price_amount'      => array( '15', '$15 at the door' ),
			'year_stated'       => 'yes',
		),
		$over
	);
}

function fixture( $name ) {
	$one = static function ( $ev ) {
		return array( 'is_event' => true, 'is_event_confidence' => 0.95, 'not_event_reason' => '', 'events' => array( $ev ) );
	};
	switch ( $name ) {
		case 'basic':
			return $one( ev( basic() ) );
		case 'basic_repost':
			return $one( ev( basic( array( 'title' => 'Leather Social @ Toolbox', 'ticket_url' => 'https://www.tickets.example.com/leather-social/', 'organizer' => '' ) ) ) );
		case 'basic_title_only':
			return $one( ev( basic( array( 'ticket_url' => '', 'organizer' => '', 'price_amount' => '', 'admission' => '' ) ) ) );
		case 'basic_next_month':
			return $one( ev( basic( array( 'start_date' => d( 60 ), 'ticket_url' => '' ) ) ) );
		case 'basic_edited':
			return $one( ev( basic( array( 'title' => 'Leather Social Night (new time)', 'start_time' => '21:00' ) ) ) );
		case 'basic_edited2':
			return $one( ev( basic( array( 'title' => 'Leather Social Night (new time)', 'start_time' => '21:30', 'admission' => array( 'paid', '$20' ), 'price_amount' => array( '20', '$20' ) ) ) ) );
		case 'nodate':
			return $one( ev( array( 'title' => 'Gear Swap', 'short_description' => 'Bring gear to swap.' ) ) );
		case 'flyer':
			return $one( ev( array( 'title' => 'Pup Mosh', 'start_date' => d( 45 ), 'start_time' => '19:00', 'location_mode' => 'in_person', 'venue_name' => 'AWOL', 'city' => 'Columbus', 'admission' => array( 'free', 'FREE ENTRY' ), 'official_url' => 'https://pupmosh.example.org', 'event_type' => 'Themed', 'year_stated' => 'no' ), 'image' ) );
		case 'free_noword':
			return $one( ev( array( 'title' => 'Bootblack Clinic', 'start_date' => d( 33 ), 'start_time' => '18:00', 'location_mode' => 'in_person', 'venue_name' => 'Club House', 'admission' => array( 'free', '$0' ), 'price_amount' => '0', 'official_url' => 'https://made-up.example.net/clinic', 'event_type' => 'Workshops' ) ) );
		case 'multi':
			return $one( ev( array( 'title' => 'Midwest Leather Weekend', 'start_date' => d( 40 ), 'end_date' => d( 42 ), 'location_mode' => 'in_person', 'city' => 'Columbus', 'timezone' => 'America/New_York', 'schedule_notes' => 'Saturday contest $25; Sunday brunch $15', 'admission' => array( 'price_varies', '$15-$25' ), 'price_min' => '15', 'price_max' => '25' ) ) );
		case 'notevent':
			return array( 'is_event' => false, 'is_event_confidence' => 0.9, 'not_event_reason' => 'Photos from last week.', 'events' => array() );
		case 'twoevents':
			return array( 'is_event' => true, 'is_event_confidence' => 0.9, 'not_event_reason' => '', 'events' => array( ev( array( 'title' => 'Roundup One', 'start_date' => d( 50 ), 'start_time' => '19:00', 'location_mode' => 'online', 'online_url' => 'https://meet.example.com/one' ) ), ev( array( 'title' => 'Roundup Two', 'start_date' => d( 51 ), 'start_time' => '20:00', 'location_mode' => 'hybrid', 'venue_name' => 'Hall' ) ) ) );
		case 'lowconf':
			return $one( ev( array( 'title' => array( 'Smudged Title', 'Smudged', 'image', 0.3 ), 'start_date' => d( 35 ) ) ) );
		case 'race':
			return $one( ev( array( 'title' => 'Race Night', 'start_date' => d( 70 ), 'start_time' => '20:00', 'location_mode' => 'in_person', 'venue_name' => 'Race Hall', 'ticket_url' => 'https://tickets.example.com/race' ) ) );
	}
	// Any other marker: a distinct event of its own (title and date from the name).
	return $one( ev( array( 'title' => 'Event ' . $name, 'start_date' => d( 90 + crc32( $name ) % 200 ), 'start_time' => '19:00', 'location_mode' => 'in_person', 'venue_name' => 'Hall ' . $name ) ) );
}
