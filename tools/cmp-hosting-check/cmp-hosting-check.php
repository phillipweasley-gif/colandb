<?php
/**
 * Plugin Name: CMP Hosting Check
 * Description: Temporary, read-only diagnostic for the Community Member Planning build. Tools → Hosting Check reports whether private member pages stay uncached through the host/CDN, where private photos can be stored, which image formats the server can process, and the server limits. Creates only short-lived test files and deletes them in the same request. Delete this plugin once the report has been sent.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Author: RA Marketing
 * Text Domain: cmp-hosting-check
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CMP_Hosting_Check {

	const VERSION   = '1.0.0';
	const PAGE      = 'cmp-hosting-check';
	const PROBE_KEY = 'cmp_hc_probe';
	const TOKEN_TTL = HOUR_IN_SECONDS;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_probe' ), 0 );
	}

	public static function menu() {
		add_management_page( 'Hosting Check', 'Hosting Check', 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	/* ------------------------------------------------------------------
	 * Front-end probe: a token-gated URL that answers exactly the way the
	 * member area does (private, no-store, noindex), with a body that is
	 * different on every request. Fetching it twice tells us whether
	 * anything between WordPress and the visitor (host cache, Cloudflare)
	 * served a stored copy anyway. Without a valid token it does nothing.
	 * ---------------------------------------------------------------- */

	private static function probe_token() {
		$token = get_transient( 'cmp_hc_token' );
		if ( ! $token ) {
			$token = bin2hex( random_bytes( 16 ) );
			set_transient( 'cmp_hc_token', $token, self::TOKEN_TTL );
		}
		return $token;
	}

	private static function probe_url() {
		return add_query_arg( self::PROBE_KEY, self::probe_token(), home_url( '/' ) );
	}

	public static function serve_probe() {
		if ( ! isset( $_GET[ self::PROBE_KEY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token = get_transient( 'cmp_hc_token' );
		if ( ! $token || ! hash_equals( $token, (string) wp_unslash( $_GET[ self::PROBE_KEY ] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode(
			array(
				'probe'     => 'cmp-hosting-check',
				'unique'    => bin2hex( random_bytes( 8 ) ),
				'time'      => microtime( true ),
				'logged_in' => is_user_logged_in(),
			)
		);
		exit;
	}

	/* ------------------------------------------------------------------
	 * Checks. Each returns a list of rows:
	 *   array( label, value, status ) with status ok | warn | bad | info.
	 * ---------------------------------------------------------------- */

	private static function row( $label, $value, $status = 'info' ) {
		return array( $label, is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value, $status );
	}

	private static function check_environment() {
		global $wp_version;
		$uploads = wp_get_upload_dir();
		$rows    = array(
			self::row( 'WordPress', $wp_version ),
			self::row( 'PHP', PHP_VERSION ),
			self::row( 'Server software', isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'not reported' ),
			self::row( 'HTTPS', is_ssl() ),
			self::row( 'Multisite', is_multisite() ),
			self::row( 'Persistent object cache', (bool) wp_using_ext_object_cache() ),
			self::row( 'PHP memory_limit', ini_get( 'memory_limit' ) ),
			self::row( 'WP_MEMORY_LIMIT', defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'not set' ),
			self::row( 'upload_max_filesize', ini_get( 'upload_max_filesize' ), wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) ) >= 10 * MB_IN_BYTES ? 'ok' : 'warn' ),
			self::row( 'post_max_size', ini_get( 'post_max_size' ), wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ) >= 12 * MB_IN_BYTES ? 'ok' : 'warn' ),
			self::row( 'WordPress max upload size', size_format( wp_max_upload_size() ) ),
			self::row( 'max_execution_time', ini_get( 'max_execution_time' ) . 's' ),
			self::row( 'open_basedir', ini_get( 'open_basedir' ) ? ini_get( 'open_basedir' ) : 'not set (no restriction)' ),
			self::row( 'Web root (ABSPATH)', ABSPATH ),
			self::row( 'Document root', isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : 'not reported' ),
			self::row( 'wp-content', WP_CONTENT_DIR ),
			self::row( 'Uploads folder', $uploads['basedir'] ),
			self::row( 'Uploads writable', wp_is_writable( $uploads['basedir'] ), wp_is_writable( $uploads['basedir'] ) ? 'ok' : 'bad' ),
		);
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $uploads['basedir'] ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$rows[] = self::row( 'Free disk space (uploads)', false === $free ? 'not reported' : size_format( $free ) );
		$rows[] = self::row( 'DISABLE_WP_CRON', defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'true (host or system cron must run wp-cron)' : 'false (runs on page visits)' );
		$rows[] = self::row( 'Site timezone', wp_timezone_string() );
		return $rows;
	}

	private static function check_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$rows   = array();
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( get_plugins() as $file => $p ) {
			if ( in_array( $file, $active, true ) ) {
				$rows[] = self::row( $p['Name'], $p['Version'] . '  (' . $file . ')' );
			}
		}
		foreach ( get_mu_plugins() as $file => $p ) {
			$rows[] = self::row( 'Must-use: ' . ( $p['Name'] ? $p['Name'] : $file ), ( $p['Version'] ? $p['Version'] : '–' ) . '  (' . $file . ')' );
		}
		foreach ( array( 'advanced-cache.php', 'object-cache.php', 'db.php' ) as $dropin ) {
			if ( file_exists( WP_CONTENT_DIR . '/' . $dropin ) ) {
				$rows[] = self::row( 'Drop-in', $dropin );
			}
		}
		$theme  = wp_get_theme();
		$rows[] = self::row( 'Active theme', $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( $theme->parent() ? ' (child of ' . $theme->parent()->get( 'Name' ) . ')' : '' ) );
		return $rows;
	}

	private static function check_images() {
		$rows    = array();
		$editors = array();
		foreach ( array( 'image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/webp' => 'WebP', 'image/avif' => 'AVIF', 'image/heic' => 'HEIC (iPhone photos)' ) as $mime => $label ) {
			$ok     = wp_image_editor_supports( array( 'mime_type' => $mime ) );
			$rows[] = self::row( 'WordPress can process ' . $label, $ok, $ok ? 'ok' : ( 'image/heic' === $mime ? 'warn' : ( 'image/avif' === $mime ? 'info' : 'bad' ) ) );
		}
		$rows[] = self::row( 'Imagick extension', extension_loaded( 'imagick' ) );
		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			$v      = Imagick::getVersion();
			$rows[] = self::row( 'ImageMagick version', isset( $v['versionString'] ) ? $v['versionString'] : 'unknown' );
			$heic   = Imagick::queryFormats( 'HEI*' );
			$rows[] = self::row( 'ImageMagick HEIC/HEIF formats', $heic ? implode( ', ', $heic ) : 'none', $heic ? 'ok' : 'warn' );
		}
		$rows[] = self::row( 'GD extension', extension_loaded( 'gd' ) );
		if ( function_exists( 'gd_info' ) ) {
			$gd     = gd_info();
			$rows[] = self::row( 'GD WebP support', ! empty( $gd['WebP Support'] ) );
		}
		$rows[] = self::row( 'EXIF extension (read photo metadata)', extension_loaded( 'exif' ) );
		$rows[] = self::row( 'Fileinfo extension (check real file type)', extension_loaded( 'fileinfo' ), extension_loaded( 'fileinfo' ) ? 'ok' : 'bad' );
		$editors = apply_filters( 'wp_image_editors', array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ) );
		$rows[]  = self::row( 'Image editors (in order)', implode( ', ', $editors ) );
		return $rows;
	}

	/**
	 * Option A: can WordPress write somewhere the web server won't serve?
	 * Each candidate gets a throwaway subfolder that is removed again.
	 */
	private static function check_private_storage() {
		$rows       = array();
		$docroot    = isset( $_SERVER['DOCUMENT_ROOT'] ) ? wp_normalize_path( realpath( sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) ) ) : '';
		$webroot    = wp_normalize_path( realpath( ABSPATH ) );
		$candidates = array_unique(
			array_filter(
				array(
					dirname( $webroot ),
					dirname( dirname( $webroot ) ),
					$docroot ? dirname( $docroot ) : '',
					defined( 'WP_CONTENT_DIR' ) ? dirname( wp_normalize_path( WP_CONTENT_DIR ) ) : '',
					getenv( 'HOME' ) ? wp_normalize_path( getenv( 'HOME' ) ) : '',
				)
			)
		);

		$found = false;
		foreach ( $candidates as $dir ) {
			$inside_web = ( $webroot && 0 === strpos( trailingslashit( $dir ), trailingslashit( $webroot ) ) ) || ( $docroot && 0 === strpos( trailingslashit( $dir ), trailingslashit( $docroot ) ) );
			if ( $inside_web ) {
				$rows[] = self::row( $dir, 'inside the public web folder – not usable', 'info' );
				continue;
			}
			$result = self::try_write( $dir );
			if ( true === $result ) {
				$found  = true;
				$rows[] = self::row( $dir, 'writable, outside the public web folder', 'ok' );
			} else {
				$rows[] = self::row( $dir, $result, 'info' );
			}
		}
		array_unshift(
			$rows,
			self::row( 'Result', $found ? 'A private folder outside the web root is available (option A possible)' : 'No writable folder outside the web root (use option B or C)', $found ? 'ok' : 'warn' )
		);
		$rows[] = self::row( 'Note', 'Check with the host that a folder outside the web root is kept across restarts, migrations and staging pushes, and is included in backups.', 'info' );
		return $rows;
	}

	private static function try_write( $dir ) {
		if ( ! @is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- open_basedir may forbid even looking.
			return 'not accessible (missing or blocked by open_basedir)';
		}
		if ( ! @wp_is_writable( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return 'exists, not writable';
		}
		$test = trailingslashit( $dir ) . 'cmp-hc-' . bin2hex( random_bytes( 6 ) );
		if ( ! @mkdir( $test, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			return 'reported writable, but creating a folder failed';
		}
		$file = $test . '/probe.txt';
		$ok   = false !== @file_put_contents( $file, 'probe' ) && 'probe' === @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@rmdir( $test ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		return $ok ? true : 'folder created, but writing/reading a file failed';
	}

	/**
	 * Why a "protected" folder inside uploads isn't good enough: put a file
	 * behind deny rules (.htaccess for Apache/LiteSpeed) and see whether it
	 * can still be downloaded through the public site address.
	 */
	private static function check_protected_uploads() {
		$uploads = wp_get_upload_dir();
		$name    = 'cmp-hc-' . bin2hex( random_bytes( 8 ) );
		$dir     = trailingslashit( $uploads['basedir'] ) . $name;
		$url     = trailingslashit( $uploads['baseurl'] ) . $name . '/secret.txt';
		$secret  = 'cmp-hc-secret-' . bin2hex( random_bytes( 8 ) );

		if ( ! wp_mkdir_p( $dir ) ) {
			return array( self::row( 'Result', 'Could not create a test folder in uploads', 'warn' ) );
		}
		file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $dir . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $dir . '/secret.txt', $secret ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$res = wp_remote_get( $url, array( 'timeout' => 15, 'sslverify' => false, 'redirection' => 0 ) );

		foreach ( array( 'secret.txt', 'index.php', '.htaccess' ) as $f ) {
			@unlink( $dir . '/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		$cleaned = ! file_exists( $dir );

		if ( is_wp_error( $res ) ) {
			$rows = array( self::row( 'Result', 'Inconclusive: the server could not request its own public address (' . $res->get_error_message() . ')', 'warn' ) );
		} else {
			$code    = wp_remote_retrieve_response_code( $res );
			$leaked  = false !== strpos( wp_remote_retrieve_body( $res ), $secret );
			$rows    = array(
				self::row( 'Result', $leaked ? 'A file behind deny rules in uploads WAS publicly downloadable – uploads cannot hold private files' : 'Deny rules blocked direct download (HTTP ' . $code . ')', $leaked ? 'warn' : 'ok' ),
				self::row( 'HTTP status', $code ),
			);
		}
		$rows[] = self::row( 'Test files removed', $cleaned, $cleaned ? 'ok' : 'bad' );
		$rows[] = self::row( 'Note', 'Even if blocked, the member plugin will not rely on this: a server or CDN change could silently expose the files.', 'info' );
		return $rows;
	}

	/**
	 * Fetches the probe twice from the server (logged out, then with the
	 * admin's own cookies, as WordPress Site Health's loopback test does)
	 * and reports what came back. The browser-side check runs in JS.
	 */
	private static function check_caching() {
		$rows = array();
		$url  = self::probe_url();

		$cookies = array();
		foreach ( $_COOKIE as $name => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( 0 === strpos( $name, 'wordpress_' ) ) {
				$cookies[] = new WP_Http_Cookie( array( 'name' => $name, 'value' => $value ) );
			}
		}

		foreach ( array( 'Signed out' => array(), 'Signed in (your session)' => $cookies ) as $who => $jar ) {
			$a = wp_remote_get( $url, array( 'timeout' => 15, 'sslverify' => false, 'cookies' => $jar, 'redirection' => 0 ) );
			$b = wp_remote_get( $url, array( 'timeout' => 15, 'sslverify' => false, 'cookies' => $jar, 'redirection' => 0 ) );
			if ( is_wp_error( $a ) || is_wp_error( $b ) ) {
				$rows[] = self::row( $who, 'Inconclusive: loopback request failed (' . ( is_wp_error( $a ) ? $a->get_error_message() : $b->get_error_message() ) . '). Use the browser check below.', 'warn' );
				continue;
			}
			$ja     = json_decode( wp_remote_retrieve_body( $a ), true );
			$jb     = json_decode( wp_remote_retrieve_body( $b ), true );
			$probe  = is_array( $ja ) && isset( $ja['probe'] );
			$cached = $probe && is_array( $jb ) && $ja['unique'] === $jb['unique'];
			$cc     = wp_remote_retrieve_header( $b, 'cache-control' );
			$rows[] = self::row( $who . ': second request served a stored copy?', ! $probe ? 'Inconclusive: probe did not answer (HTTP ' . wp_remote_retrieve_response_code( $a ) . ')' : ( $cached ? 'YES – cached despite no-store' : 'no' ), ! $probe ? 'warn' : ( $cached ? 'bad' : 'ok' ) );
			$rows[] = self::row( $who . ': Cache-Control received', $cc ? $cc : '(none)', ( $cc && false !== stripos( $cc, 'no-store' ) ) ? 'ok' : 'bad' );
			foreach ( array( 'cf-cache-status', 'age', 'x-cache', 'x-cache-status', 'x-proxy-cache', 'x-litespeed-cache', 'x-ac', 'server', 'x-powered-by' ) as $h ) {
				$v = wp_remote_retrieve_header( $b, $h );
				if ( '' !== $v && array() !== $v ) {
					$rows[] = self::row( $who . ': ' . $h, is_array( $v ) ? implode( ', ', $v ) : $v );
				}
			}
			if ( $probe && 'Signed in (your session)' === $who ) {
				$rows[] = self::row( $who . ': WordPress saw you as signed in', ! empty( $jb['logged_in'] ), empty( $jb['logged_in'] ) ? 'warn' : 'ok' );
			}
		}

		$member_page = 0;
		$cmp         = get_option( 'cmp_settings', array() );
		if ( ! empty( $cmp['member_page_id'] ) ) {
			$member_page = (int) $cmp['member_page_id'];
		}
		if ( $member_page && get_permalink( $member_page ) ) {
			$res = wp_remote_get( get_permalink( $member_page ), array( 'timeout' => 15, 'sslverify' => false, 'redirection' => 0 ) );
			if ( ! is_wp_error( $res ) ) {
				$cc     = wp_remote_retrieve_header( $res, 'cache-control' );
				$rows[] = self::row( 'Member area page (signed out): Cache-Control', $cc ? $cc : '(none)', ( $cc && false !== stripos( $cc, 'no-store' ) ) ? 'ok' : 'bad' );
				$cf     = wp_remote_retrieve_header( $res, 'cf-cache-status' );
				if ( $cf ) {
					$rows[] = self::row( 'Member area page (signed out): cf-cache-status', $cf );
				}
			}
		} else {
			$rows[] = self::row( 'Member area page', 'Community Member Planning not set up yet – install it and choose the page, then re-run to test that page too', 'info' );
		}

		$home = wp_remote_get( home_url( '/' ), array( 'timeout' => 15, 'sslverify' => false ) );
		if ( ! is_wp_error( $home ) ) {
			$rows[] = self::row( 'Ordinary public page: Cache-Control (for comparison)', wp_remote_retrieve_header( $home, 'cache-control' ) ? wp_remote_retrieve_header( $home, 'cache-control' ) : '(none)' );
		}
		return $rows;
	}

	/* ------------------------------------------------------------------
	 * Page
	 * ---------------------------------------------------------------- */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$run = isset( $_POST['cmp_hc_run'] ) && check_admin_referer( 'cmp_hc_run' );

		echo '<div class="wrap"><h1>Hosting Check</h1>';
		echo '<p style="max-width:720px">Runs read-only checks for the Community Member Planning build. It creates a few test files and deletes them straight away. Run it on the <strong>staging</strong> site first, then (if asked) on the live site. When done, copy or download the report, send it to your developer, and delete this plugin.</p>';
		echo '<form method="post">';
		wp_nonce_field( 'cmp_hc_run' );
		echo '<p><button type="submit" name="cmp_hc_run" value="1" class="button button-primary">' . ( $run ? 'Run checks again' : 'Run checks' ) . '</button></p></form>';

		if ( ! $run ) {
			echo '</div>';
			return;
		}

		$sections = array(
			'1. Private pages and caching (server-side test)' => self::check_caching(),
			'2. Private photo storage outside the web root'   => self::check_private_storage(),
			'3. "Protected" folder inside uploads'            => self::check_protected_uploads(),
			'4. Image processing'                              => self::check_images(),
			'5. Server and WordPress'                          => self::check_environment(),
			'6. Active plugins and theme'                      => self::check_plugins(),
		);

		$text  = 'CMP Hosting Check ' . self::VERSION . "\n";
		$text .= 'Site: ' . home_url( '/' ) . "\nRun at: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";

		$icons = array( 'ok' => '✔', 'warn' => '⚠', 'bad' => '✖', 'info' => '·' );
		foreach ( $sections as $title => $rows ) {
			echo '<h2>' . esc_html( $title ) . '</h2><table class="widefat striped" style="max-width:1000px"><tbody>';
			$text .= "\n== " . $title . " ==\n";
			foreach ( $rows as $r ) {
				$label = array( 'ok' => 'OK', 'warn' => 'Check', 'bad' => 'Problem', 'info' => 'Info' );
				printf(
					'<tr><td style="width:34%%">%s</td><td><span aria-hidden="true">%s</span> <span class="screen-reader-text">%s: </span>%s</td></tr>',
					esc_html( $r[0] ),
					esc_html( $icons[ $r[2] ] ),
					esc_html( $label[ $r[2] ] ),
					esc_html( $r[1] )
				);
				$text .= '[' . strtoupper( $r[2] ) . '] ' . $r[0] . ': ' . $r[1] . "\n";
			}
			echo '</tbody></table>';
		}

		// Browser-side check: what actually reaches a visitor's browser
		// through the CDN, which a server-to-itself request may bypass.
		echo '<h2>7. Private pages and caching (browser test)</h2>';
		echo '<table class="widefat striped" style="max-width:1000px"><tbody id="cmp-hc-browser"><tr><td>Running…</td></tr></tbody></table>';

		echo '<h2>Report</h2><p>Copy this and send it to your developer. It contains no passwords or keys.</p>';
		echo '<textarea id="cmp-hc-report" readonly rows="18" style="width:100%;max-width:1000px;font-family:monospace">' . esc_textarea( $text ) . '</textarea>';
		echo '<p><button type="button" class="button" id="cmp-hc-copy">Copy report</button> <button type="button" class="button" id="cmp-hc-download">Download report</button> <span id="cmp-hc-msg" role="status"></span></p>';
		?>
		<script>
		( function () {
			var url = <?php echo wp_json_encode( self::probe_url() ); ?>;
			var body = document.getElementById( 'cmp-hc-browser' );
			var report = document.getElementById( 'cmp-hc-report' );
			var rows = [];
			function add( label, value, status ) {
				rows.push( [ label, value, status ] );
			}
			function headers( res ) {
				var out = {};
				[ 'cache-control', 'cf-cache-status', 'age', 'x-cache', 'server', 'x-powered-by' ].forEach( function ( h ) {
					if ( res.headers.get( h ) ) { out[ h ] = res.headers.get( h ); }
				} );
				return out;
			}
			function get( creds ) {
				// Same URL both times on purpose: a cache-busting parameter would hide
				// exactly the CDN caching this is meant to detect.
				return fetch( url, { credentials: creds, cache: 'default' } ).then( function ( r ) {
					return r.json().then( function ( j ) { return { j: j, h: headers( r ) }; } );
				} );
			}
			function run( who, creds ) {
				return get( creds ).then( function ( a ) {
					return get( creds ).then( function ( b ) {
						var cached = a.j.unique === b.j.unique;
						add( who + ': second request served a stored copy?', cached ? 'YES – cached despite no-store' : 'no', cached ? 'bad' : 'ok' );
						add( who + ': WordPress saw a signed-in visitor', b.j.logged_in ? 'yes' : 'no', 'info' );
						Object.keys( b.h ).forEach( function ( k ) {
							add( who + ': ' + k, b.h[ k ], k === 'cache-control' ? ( /no-store/i.test( b.h[ k ] ) ? 'ok' : 'bad' ) : 'info' );
						} );
					} );
				} );
			}
			run( 'Browser, signed in', 'same-origin' )
				.then( function () { return run( 'Browser, no cookies', 'omit' ); } )
				.catch( function ( e ) { add( 'Browser test', 'Could not run: ' + e, 'warn' ); } )
				.then( function () {
					var icons = { ok: '✔', warn: '⚠', bad: '✖', info: '·' };
					body.innerHTML = '';
					var text = '\n== 7. Private pages and caching (browser test) ==\n';
					rows.forEach( function ( r ) {
						var tr = document.createElement( 'tr' );
						var td1 = document.createElement( 'td' ); td1.style.width = '34%'; td1.textContent = r[0];
						var td2 = document.createElement( 'td' ); td2.textContent = icons[ r[2] ] + ' ' + r[1];
						tr.appendChild( td1 ); tr.appendChild( td2 ); body.appendChild( tr );
						text += '[' + r[2].toUpperCase() + '] ' + r[0] + ': ' + r[1] + '\n';
					} );
					report.value += text;
				} );

			var msg = document.getElementById( 'cmp-hc-msg' );
			document.getElementById( 'cmp-hc-copy' ).addEventListener( 'click', function () {
				report.select();
				( navigator.clipboard ? navigator.clipboard.writeText( report.value ) : Promise.reject() )
					.then( function () { msg.textContent = 'Copied.'; } )
					.catch( function () { document.execCommand( 'copy' ); msg.textContent = 'Copied.'; } );
			} );
			document.getElementById( 'cmp-hc-download' ).addEventListener( 'click', function () {
				var a = document.createElement( 'a' );
				a.href = URL.createObjectURL( new Blob( [ report.value ], { type: 'text/plain' } ) );
				a.download = 'hosting-check-' + location.hostname + '.txt';
				document.body.appendChild( a ); a.click(); a.remove();
			} );
		}() );
		</script>
		<?php
		echo '</div>';
	}
}

CMP_Hosting_Check::init();

register_uninstall_hook( __FILE__, 'cmp_hosting_check_uninstall' );
register_deactivation_hook( __FILE__, 'cmp_hosting_check_uninstall' );

function cmp_hosting_check_uninstall() {
	delete_transient( 'cmp_hc_token' );
}
