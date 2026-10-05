<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installable app (0.19.0; owner, 2026-10-05: "build the installable app
 * with reminders", release 1 of 2; push notifications are release 2).
 *
 * The site becomes an installable web app: a home-screen icon that opens
 * full-screen, and a simple offline page.
 * - The manifest, service worker and offline page are served from
 *   wp-admin/admin-post.php, which the host's CDN never caches (it caches
 *   signed-out front-end responses for up to 7 days, which would hold back
 *   updates). The service worker is allowed the whole site with the
 *   Service-Worker-Allowed header.
 * - The service worker caches nothing but the offline page and never
 *   touches member pages, form posts or wp-admin: a page either comes from
 *   the site or, with no connection, the offline page shows.
 * - Install prompt in the member area: a one-tap Install button where the
 *   browser offers one (Android, desktop Chrome/Edge), the three "Add to
 *   Home Screen" steps on iPhone/iPad, nothing once installed. "Not now"
 *   hides it for 30 days on that device. Account → App & notifications
 *   always has it.
 * - Icons come from the site icon (Appearance → Customize → Site Identity),
 *   centred on a square when it isn't square (0.19.1), or the bundled ones.
 */
class CMP_App {

	const NONCE = 'cmp_app';

	public static function init() {
		foreach ( array( 'manifest', 'sw', 'offline' ) as $what ) {
			add_action( 'admin_post_nopriv_cmp_app_' . $what, array( __CLASS__, 'serve_' . $what ) );
			add_action( 'admin_post_cmp_app_' . $what, array( __CLASS__, 'serve_' . $what ) );
		}
		add_action( 'wp_head', array( __CLASS__, 'head' ), 2 );
		add_action( 'wp_head', array( __CLASS__, 'touch_icon' ), 100 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	private static function endpoint( $what ) {
		return add_query_arg( 'action', 'cmp_app_' . $what, admin_url( 'admin-post.php' ) );
	}

	/** "Central Ohio Leather & Beyond" → "COL&B"; short names are kept. */
	public static function short_name() {
		$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( mb_strlen( $name ) > 12 ) {
			$short = '';
			foreach ( preg_split( '/\s+/', $name ) as $word ) {
				$short .= '&' === $word ? '&' : mb_strtoupper( mb_substr( $word, 0, 1 ) );
			}
			$name = $short;
		}
		return (string) apply_filters( 'cmp_app_short_name', $name );
	}

	private static function colors() {
		$bg    = '#15121f';
		$theme = '#15121f';
		if ( class_exists( 'CEC_Admin_Settings' ) ) {
			$bg    = CEC_Admin_Settings::get( 'color_bg' ) ? CEC_Admin_Settings::get( 'color_bg' ) : $bg;
			$theme = $bg;
		}
		return array( sanitize_hex_color( $bg ) ? $bg : '#15121f', sanitize_hex_color( $theme ) ? $theme : '#15121f' );
	}

	/**
	 * App icons (0.19.1): the site icon when it's square; when it isn't (a
	 * wide logo set as the site icon), it's centred on a square in the app's
	 * background colour, so phones don't squash or crop it. Those squares
	 * are made once with GD and kept in uploads/cmp-app/. Without a site
	 * icon, or without GD, the bundled icons.
	 */
	public static function icons() {
		$out = array();
		foreach ( array( 192, 512 ) as $size ) {
			$out[] = array(
				'src'     => self::icon_url( $size ),
				'sizes'   => $size . 'x' . $size,
				'type'    => 'image/png',
				'purpose' => 'any',
			);
		}
		return $out;
	}

	public static function icon_url( $size ) {
		$id = (int) get_option( 'site_icon' );
		if ( $id && get_site_icon_url( $size ) ) {
			$meta = wp_get_attachment_metadata( $id );
			if ( ! empty( $meta['width'] ) && (int) $meta['width'] === (int) $meta['height'] ) {
				return get_site_icon_url( $size );
			}
			$made = self::padded_icon( $id, $size );
			if ( $made ) {
				return $made;
			}
		}
		return CMP_URL . 'assets/img/app-icon-' . $size . '.png';
	}

	/** The site icon centred on a square (80% of it), as a URL; '' if it can't be made. */
	private static function padded_icon( $id, $size ) {
		$file = get_attached_file( $id );
		$up   = wp_upload_dir();
		if ( ! $file || ! is_readable( $file ) || ! empty( $up['error'] ) || ! function_exists( 'imagecreatetruecolor' ) ) {
			return '';
		}
		$name = 'icon-' . $id . '-' . (int) filemtime( $file ) . '-' . $size . '.png';
		$dir  = trailingslashit( $up['basedir'] ) . 'cmp-app';
		$path = $dir . '/' . $name;
		$url  = trailingslashit( $up['baseurl'] ) . 'cmp-app/' . $name;
		if ( file_exists( $path ) ) {
			return $url;
		}
		$src = @imagecreatefromstring( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! $src || ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		list( $bg ) = self::colors();
		$rgb        = sscanf( $bg, '#%02x%02x%02x' );
		$canvas     = imagecreatetruecolor( $size, $size );
		imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2] ) );
		imagealphablending( $canvas, true );
		$w     = imagesx( $src );
		$h     = imagesy( $src );
		$scale = ( $size * 0.8 ) / max( $w, $h );
		$nw    = max( 1, (int) round( $w * $scale ) );
		$nh    = max( 1, (int) round( $h * $scale ) );
		imagecopyresampled( $canvas, $src, (int) ( ( $size - $nw ) / 2 ), (int) ( ( $size - $nh ) / 2 ), 0, 0, $nw, $nh, $w, $h );
		$ok = imagepng( $canvas, $path );
		imagedestroy( $canvas );
		imagedestroy( $src );
		return $ok ? $url : '';
	}

	private static function scope() {
		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return $path ? trailingslashit( $path ) : '/';
	}

	public static function head() {
		if ( is_admin() ) {
			return;
		}
		list( , $theme ) = self::colors();
		echo '<link rel="manifest" href="' . esc_url( self::endpoint( 'manifest' ) ) . '" />' . "\n";
		echo '<meta name="theme-color" content="' . esc_attr( $theme ) . '" />' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes" />' . "\n";
		echo '<meta name="apple-mobile-web-app-capable" content="yes" />' . "\n";
		echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />' . "\n";
		echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( self::short_name() ) . '" />' . "\n";
	}

	/** After WordPress's own site-icon tags, so iPhones use the square one (0.19.1). */
	public static function touch_icon() {
		if ( ! is_admin() ) {
			echo '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url( self::icon_url( 192 ) ) . '" />' . "\n";
		}
	}

	public static function assets() {
		wp_enqueue_script( 'cmp-app', CMP_URL . 'assets/js/app.js', array(), CMP_VERSION, true );
		wp_localize_script( 'cmp-app', 'CMP_APP', array( 'sw' => self::endpoint( 'sw' ), 'scope' => self::scope() ) );
	}

	private static function no_cache( $type ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'X-Robots-Tag: noindex' );
	}

	public static function manifest() {
		list( $bg, $theme ) = self::colors();
		return array(
			'id'               => self::scope(),
			'name'             => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'short_name'       => self::short_name(),
			'description'      => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
			'start_url'        => add_query_arg( 'source', 'app', CMP_Settings::member_page_url() ),
			'scope'            => self::scope(),
			'display'          => 'standalone',
			'orientation'      => 'any',
			'background_color' => $bg,
			'theme_color'      => $theme,
			'icons'            => self::icons(),
		);
	}

	public static function serve_manifest() {
		self::no_cache( 'application/manifest+json; charset=utf-8' );
		echo wp_json_encode( self::manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * The service worker. Release 1 only keeps an offline page; release 2
	 * adds push. Bump CMP_VERSION and the browser fetches the new one.
	 */
	public static function serve_sw() {
		self::no_cache( 'application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . self::scope() );
		$cache   = 'cmp-app-' . CMP_VERSION;
		$offline = self::endpoint( 'offline' );
		$admin   = wp_parse_url( admin_url( '/' ), PHP_URL_PATH );
		echo "/* " . esc_js( self::short_name() ) . " app (Community Member Planning " . esc_js( CMP_VERSION ) . ") */\n";
		echo 'var CACHE = ' . wp_json_encode( $cache ) . ";\n";
		echo 'var OFFLINE = ' . wp_json_encode( $offline ) . ";\n";
		echo 'var ADMIN = ' . wp_json_encode( $admin ) . ";\n";
		echo 'var ICON = ' . wp_json_encode( self::icons()[0]['src'] ) . ";\n";
		echo <<<'JS'
self.addEventListener( 'install', function ( e ) {
	e.waitUntil(
		caches.open( CACHE ).then( function ( c ) {
			return c.addAll( [ new Request( OFFLINE, { cache: 'reload', credentials: 'omit' } ), new Request( ICON, { cache: 'reload', credentials: 'omit' } ) ] );
		} ).then( function () {
			return self.skipWaiting();
		} )
	);
} );
self.addEventListener( 'activate', function ( e ) {
	e.waitUntil(
		caches.keys().then( function ( keys ) {
			return Promise.all( keys.filter( function ( k ) {
				return 0 === k.indexOf( 'cmp-app-' ) && k !== CACHE;
			} ).map( function ( k ) {
				return caches.delete( k );
			} ) );
		} ).then( function () {
			return self.clients.claim();
		} )
	);
} );
// Page loads only: straight from the site; the offline page if that fails.
// Nothing else is cached or touched (member pages, posts, wp-admin, files),
// apart from the app icon the offline page shows.
self.addEventListener( 'fetch', function ( e ) {
	var req = e.request;
	if ( 'GET' === req.method && req.url === ICON ) { // The offline page's icon.
		e.respondWith( fetch( req ).catch( function () {
			return caches.match( ICON ).then( function ( r ) {
				return r || Response.error();
			} );
		} ) );
		return;
	}
	if ( 'navigate' !== req.mode || 'GET' !== req.method ) {
		return;
	}
	if ( 0 === new URL( req.url ).pathname.indexOf( ADMIN ) ) {
		return;
	}
	e.respondWith( fetch( req ).catch( function () {
		return caches.match( OFFLINE ).then( function ( r ) {
			return r || Response.error();
		} );
	} ) );
} );
JS;
		exit;
	}

	public static function serve_offline() {
		self::no_cache( 'text/html; charset=utf-8' );
		list( $bg ) = self::colors();
		$name       = self::short_name();
		$icon       = self::icons()[0]['src'];
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex" />
<title><?php echo esc_html( sprintf( /* translators: %s: site short name */ __( '%s: offline', 'cmp' ), $name ) ); ?></title>
<style>
	body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: <?php echo esc_html( $bg ); ?>; color: #f5f4f7; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; text-align: center; padding: 24px; box-sizing: border-box; }
	img { width: 72px; height: 72px; border-radius: 16px; }
	h1 { font-size: 22px; margin: 16px 0 8px; }
	p { color: #a9a6bb; margin: 0 0 20px; }
	button { min-height: 44px; padding: 0 22px; border: 0; border-radius: 999px; background: #e1f577; color: #1b1826; font: 700 14px system-ui, sans-serif; letter-spacing: .06em; text-transform: uppercase; cursor: pointer; }
</style>
</head>
<body>
	<main>
		<img src="<?php echo esc_url( $icon ); ?>" alt="" />
		<h1><?php esc_html_e( 'You\'re offline', 'cmp' ); ?></h1>
		<p><?php esc_html_e( 'Check your connection, then try again.', 'cmp' ); ?></p>
		<button type="button" onclick="location.reload()"><?php esc_html_e( 'Try again', 'cmp' ); ?></button>
	</main>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Install help, filled in by app.js for this device: a button where the
	 * browser offers one, the iPhone steps, "installed", or a hint.
	 *
	 * @param bool $banner The dismissible member-area banner (else the Account panel).
	 */
	public static function install_html( $banner = false ) {
		$name  = self::short_name();
		$steps = '<ol class="cmp-app-steps"><li>' . esc_html__( 'Tap Share (the square with an arrow) in Safari', 'cmp' ) . '</li><li>' . esc_html__( 'Tap Add to Home Screen', 'cmp' ) . '</li><li>' . sprintf( /* translators: %s: app name */ esc_html__( 'Open %s from your Home Screen', 'cmp' ), esc_html( $name ) ) . '</li></ol>';
		$html  = '<div class="cmp-app-install' . ( $banner ? ' cmp-app-banner' : '' ) . '" data-cmp-install' . ( $banner ? ' data-cmp-install-banner hidden' : '' ) . '>';
		$html .= '<div data-cmp-install-ready hidden><p class="cmp-app-lead"><b>' . sprintf( /* translators: %s: app name */ esc_html__( 'Get the %s app', 'cmp' ), esc_html( $name ) ) . '</b> ' . esc_html__( 'Home-screen icon, full screen, and reminders for events on your calendar.', 'cmp' ) . '</p><button type="button" class="cmp-btn" data-cmp-install-btn>' . esc_html__( 'Install', 'cmp' ) . '</button></div>';
		$html .= '<div data-cmp-install-ios hidden><p class="cmp-app-lead"><b>' . sprintf( /* translators: %s: app name */ esc_html__( 'Add %s to your Home Screen', 'cmp' ), esc_html( $name ) ) . '</b></p>' . $steps . '</div>';
		if ( ! $banner ) {
			$html .= '<p data-cmp-install-done hidden class="cmp-app-done">' . esc_html__( 'You\'re using the app on this device. ✓', 'cmp' ) . '</p>';
			$html .= '<p data-cmp-install-other class="cmp-muted">' . esc_html__( 'In your browser\'s menu, look for "Install app" or "Add to Home screen". On iPhone, use Safari: Share → Add to Home Screen.', 'cmp' ) . '</p>';
		} else {
			$html .= '<button type="button" class="cmp-app-later" data-cmp-install-later>' . esc_html__( 'Not now', 'cmp' ) . '</button>';
		}
		return $html . '</div>';
	}
}
