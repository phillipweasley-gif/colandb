<?php
/**
 * Plugin Name: COL&B Plugin Updater
 * Description: Keeps this site's custom plugins (Community Events Calendar, Community Member Planning, and any future plugin from the same GitHub repository) up to date from the repository's releases. On a staging site it installs pre-releases automatically; on the live site it offers stable releases as a normal one-click "Update now". Setup: Settings → Plugin Updates.
 * Version: 1.0.6
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: RA Marketing
 * Text Domain: colandb-updater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COLANDB_UPDATER_VERSION', '1.0.6' );

/**
 * How it works
 * - Every plugin in the repository is released on GitHub as a release
 *   tagged "<plugin-folder>-v<version>" with one asset named
 *   "<plugin-folder>-<version>.zip" (built by .github/workflows/release.yml).
 *   New releases are pre-releases; marking one stable approves it for live.
 * - This plugin reads the release list with a read-only GitHub token,
 *   tells WordPress about newer versions of the managed plugins it finds
 *   installed, and downloads the zip itself when WordPress installs one
 *   (the token goes only to api.github.com, never to the storage server
 *   GitHub redirects the download to).
 * - "staging" channel: newest release including pre-releases, installed
 *   automatically by WordPress's background updates.
 * - "stable" channel: newest non-pre-release only, installed when an
 *   administrator clicks Update (unless they turn on auto-updates for it).
 */
final class COLANDB_Updater {

	const OPTION     = 'colandb_updater';
	const CACHE      = 'colandb_updater_releases';
	const CACHE_TTL  = HOUR_IN_SECONDS;
	const REPO       = 'phillipweasley-gif/colandb';
	const PAGE       = 'colandb-updater';
	const UA         = 'colandb-updater';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_updates' ) );
		// Runs before anything else hooked into downloads: our packages are
		// private GitHub assets that only this plugin can fetch (it has the
		// token), so another plugin "helpfully" downloading the address
		// itself gets GitHub's "Not Found" reply instead of a zip.
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), -1000, 4 );
		// Last word on which file an update of one of our plugins installs:
		// whatever wrote the download address into WordPress's update list
		// (on Elementor Cloud something replaces it), it is set back to this
		// plugin's GitHub release here, right before WordPress downloads it.
		add_filter( 'upgrader_package_options', array( __CLASS__, 'package_options' ), PHP_INT_MAX );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		// Diagnostics: runs at the start of every unzip_file(), before either
		// zip reader can fail, and last (so it sees other plugins' choice).
		add_filter( 'unzip_file_use_ziparchive', array( __CLASS__, 'record_unzip' ), PHP_INT_MAX );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'cleanup_copy' ) );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_colandb_updater_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_colandb_updater_check', array( __CLASS__, 'handle_check' ) );
		add_action( 'admin_post_colandb_updater_update', array( __CLASS__, 'handle_update' ) );
		add_action( 'admin_post_colandb_updater_test', array( __CLASS__, 'handle_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
	}

	/* ------------------------------------------------------------------
	 * Configuration
	 * ---------------------------------------------------------------- */

	/**
	 * Plugin folders this updater manages. Future plugins from the same
	 * repository are added here (or through the filter).
	 */
	public static function managed_slugs() {
		return (array) apply_filters(
			'colandb_updater_plugins',
			array( 'community-events-calendar', 'community-member-planning', 'colandb-updater', 'cmp-hosting-check' )
		);
	}

	private static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), array( 'token' => '', 'channel' => '', 'last_check' => 0, 'last_error' => '', 'last_download' => array(), 'last_test' => array(), 'last_unzip' => array(), 'last_attempt' => array() ) );
	}

	private static function update_settings( $changes ) {
		update_option( self::OPTION, array_merge( self::settings(), $changes ), false ); // never autoloaded
	}

	public static function api_base() {
		// Overridable only for automated tests against a mock server.
		return defined( 'COLANDB_UPDATER_API' ) ? rtrim( COLANDB_UPDATER_API, '/' ) : 'https://api.github.com';
	}

	private static function token() {
		if ( defined( 'COLANDB_GITHUB_TOKEN' ) && COLANDB_GITHUB_TOKEN ) {
			return (string) COLANDB_GITHUB_TOKEN;
		}
		return (string) self::settings()['token'];
	}

	private static function token_source() {
		if ( defined( 'COLANDB_GITHUB_TOKEN' ) && COLANDB_GITHUB_TOKEN ) {
			return 'wp-config.php';
		}
		return self::settings()['token'] ? 'settings' : '';
	}

	/**
	 * 'staging' or 'stable'. Unless chosen explicitly, a site whose
	 * WordPress environment type is staging/development/local is staging.
	 */
	public static function channel() {
		$chosen = self::settings()['channel'];
		if ( in_array( $chosen, array( 'staging', 'stable' ), true ) ) {
			return $chosen;
		}
		return in_array( wp_get_environment_type(), array( 'staging', 'development', 'local' ), true ) ? 'staging' : 'stable';
	}

	/* ------------------------------------------------------------------
	 * GitHub
	 * ---------------------------------------------------------------- */

	private static function api_headers( $accept = 'application/vnd.github+json' ) {
		return array(
			'Accept'               => $accept,
			'Authorization'        => 'Bearer ' . self::token(),
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => self::UA,
		);
	}

	private static function error_from_response( $res ) {
		if ( is_wp_error( $res ) ) {
			return 'Could not reach GitHub: ' . $res->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 401 === $code ) {
			return 'GitHub rejected the token (401). It may be mistyped, expired or revoked. Create a new one and save it here.';
		}
		if ( 403 === $code ) {
			return 'GitHub refused the request (403). The token may lack "Contents: Read" on the repository, or the rate limit was reached.';
		}
		if ( 404 === $code ) {
			return 'GitHub returned 404. The token cannot see the ' . self::REPO . ' repository; give it access to that repository.';
		}
		return 'GitHub returned HTTP ' . $code . '.';
	}

	/**
	 * Parsed, non-draft releases (cached for an hour).
	 *
	 * @return array|WP_Error List of array( slug, version, prerelease, asset_url, asset_name, notes, html_url, published ).
	 */
	public static function releases( $force = false ) {
		if ( ! self::token() ) {
			return new WP_Error( 'no_token', 'No GitHub token saved yet.' );
		}
		$cached = $force ? false : get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$res = wp_remote_get(
			self::api_base() . '/repos/' . self::REPO . '/releases?per_page=100',
			array( 'headers' => self::api_headers(), 'timeout' => 20 )
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			$error = self::error_from_response( $res );
			self::update_settings( array( 'last_check' => time(), 'last_error' => $error ) );
			return new WP_Error( 'github', $error );
		}

		$out = array();
		foreach ( (array) json_decode( wp_remote_retrieve_body( $res ), true ) as $r ) {
			if ( ! empty( $r['draft'] ) || empty( $r['tag_name'] ) || ! preg_match( '/^([a-z0-9-]+)-v(\d+(?:\.\d+)*)$/', $r['tag_name'], $m ) ) {
				continue;
			}
			$asset_name = $m[1] . '-' . $m[2] . '.zip';
			foreach ( (array) ( isset( $r['assets'] ) ? $r['assets'] : array() ) as $a ) {
				if ( isset( $a['name'], $a['url'] ) && $a['name'] === $asset_name ) {
					$out[] = array(
						'slug'       => $m[1],
						'version'    => $m[2],
						'prerelease' => ! empty( $r['prerelease'] ),
						'asset_url'  => $a['url'],
						'asset_name' => $asset_name,
						'notes'      => isset( $r['body'] ) ? (string) $r['body'] : '',
						'html_url'   => isset( $r['html_url'] ) ? $r['html_url'] : '',
						'published'  => isset( $r['published_at'] ) ? $r['published_at'] : '',
					);
					break;
				}
			}
		}

		set_site_transient( self::CACHE, $out, self::CACHE_TTL );
		self::update_settings( array( 'last_check' => time(), 'last_error' => '' ) );
		return $out;
	}

	/**
	 * Newest release of a plugin on this site's channel, or null.
	 */
	public static function latest( $slug, $channel = null ) {
		$channel  = $channel ? $channel : self::channel();
		$releases = self::releases();
		if ( is_wp_error( $releases ) ) {
			return null;
		}
		$best = null;
		foreach ( $releases as $r ) {
			if ( $r['slug'] !== $slug || ( 'stable' === $channel && $r['prerelease'] ) ) {
				continue;
			}
			if ( ! $best || version_compare( $r['version'], $best['version'], '>' ) ) {
				$best = $r;
			}
		}
		return $best;
	}

	/**
	 * Managed plugins actually installed here: plugin file => header data.
	 * Only a plugin in its expected folder ("community-events-calendar/…")
	 * is managed; a copy installed under another folder name is ignored.
	 */
	public static function installed() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( in_array( dirname( $file ), self::managed_slugs(), true ) ) {
				$out[ $file ] = $data;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * WordPress update integration
	 * ---------------------------------------------------------------- */

	public static function inject_updates( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}
		foreach ( self::installed() as $file => $data ) {
			$slug   = dirname( $file );
			$latest = self::latest( $slug );
			$item   = (object) array(
				'id'          => 'colandb/' . $slug,
				'slug'        => $slug,
				'plugin'      => $file,
				'new_version' => $data['Version'],
				'url'         => 'https://github.com/' . self::REPO,
				'package'     => '',
				'icons'       => array(),
				'banners'     => array(),
				'tested'      => get_bloginfo( 'version' ),
			);
			if ( $latest && version_compare( $latest['version'], $data['Version'], '>' ) ) {
				$item->new_version = $latest['version'];
				$item->package     = $latest['asset_url'];
				$item->url         = $latest['html_url'] ? $latest['html_url'] : $item->url;
				$transient->response[ $file ] = $item;
				unset( $transient->no_update[ $file ] );
			} else {
				// Listing it under no_update is what makes WordPress show the
				// "Enable auto-updates" control for a non-wordpress.org plugin.
				$transient->no_update[ $file ] = $item;
				unset( $transient->response[ $file ] );
			}
		}
		return $transient;
	}

	/**
	 * Downloads a release asset ourselves: the GitHub API answers an
	 * authenticated asset request with a redirect to a storage server that
	 * must NOT receive the token, so the two hops are made separately.
	 * The zip is checked to contain exactly the expected plugin folder.
	 */
	public static function download( $reply, $package, $upgrader, $hook_extra = array() ) {
		$prefix = self::api_base() . '/repos/' . self::REPO . '/releases/assets/';
		if ( ! is_string( $package ) || 0 !== strpos( $package, $prefix ) ) {
			return $reply;
		}
		// Our own package: handled here whatever an earlier filter returned.

		$slug = '';
		if ( ! empty( $hook_extra['plugin'] ) ) {
			$slug = dirname( $hook_extra['plugin'] );
		} else {
			$releases = self::releases();
			foreach ( is_wp_error( $releases ) ? array() : $releases as $r ) {
				if ( $r['asset_url'] === $package ) {
					$slug = $r['slug'];
				}
			}
		}
		if ( ! in_array( $slug, self::managed_slugs(), true ) ) {
			return new WP_Error( 'colandb_unknown', 'This package does not belong to a plugin managed by COL&B Plugin Updater.' );
		}

		$tmp   = wp_tempnam( $slug . '.zip' );
		$fetch = self::fetch_asset( $package, $tmp );
		if ( is_wp_error( $fetch ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			self::update_settings( array( 'last_download' => array( 'time' => time(), 'slug' => $slug, 'error' => $fetch->get_error_message() ) ) );
			return $fetch;
		}

		$check = self::verify_zip( $tmp, $slug );
		if ( is_wp_error( $check ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			self::update_settings( array( 'last_download' => array( 'time' => time(), 'slug' => $slug, 'error' => $check->get_error_message() ) ) );
			return $check;
		}
		self::update_settings(
			array(
				'last_download' => array(
					'time'   => time(),
					'slug'   => $slug,
					'error'  => '',
					'file'   => $tmp,
					'size'   => (int) filesize( $tmp ),
					'md5'    => (string) md5_file( $tmp ),
					'backup' => self::keep_verified_copy( $tmp ),
				),
				'last_unzip'    => array(),
			)
		);
		return $tmp;
	}

	/**
	 * Fetches a release asset into $tmp in two hops (the token goes only to
	 * the GitHub API; the storage redirect is pre-signed) and checks that
	 * what arrived is a zip. Returns a list of human-readable steps, or a
	 * WP_Error whose message says exactly what came back instead.
	 */
	public static function fetch_asset( $package, $tmp ) {
		$steps = array();
		$res   = wp_remote_get(
			$package,
			array( 'headers' => self::api_headers( 'application/octet-stream' ), 'redirection' => 0, 'timeout' => 300, 'stream' => true, 'filename' => $tmp )
		);
		$code    = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$steps[] = 'GitHub API: ' . ( is_wp_error( $res ) ? 'request failed: ' . $res->get_error_message() : 'HTTP ' . $code );

		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			$location = wp_remote_retrieve_header( $res, 'location' );
			$scheme   = wp_parse_url( $location, PHP_URL_SCHEME );
			$allowed  = 'https' === $scheme || ( 'http' === $scheme && 0 === strpos( self::api_base(), 'http://' ) );
			if ( ! $location || ! $allowed ) {
				return new WP_Error( 'colandb_redirect', 'GitHub sent an unexpected download address.' );
			}
			$steps[] = 'Redirected to ' . wp_parse_url( $location, PHP_URL_HOST );
			// No Authorization header on this hop: the URL is pre-signed.
			$res     = wp_remote_get( $location, array( 'headers' => array( 'User-Agent' => self::UA ), 'redirection' => 3, 'timeout' => 300, 'stream' => true, 'filename' => $tmp ) );
			$code    = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			$steps[] = 'Storage server: ' . ( is_wp_error( $res ) ? 'request failed: ' . $res->get_error_message() : 'HTTP ' . $code );
		}

		if ( 200 !== $code ) {
			return new WP_Error( 'colandb_download', 'Download failed (' . implode( '; ', $steps ) . '). ' . self::error_from_response( $res ) . self::describe_file( $tmp ) );
		}

		// A zip file always starts with "PK\x03\x04". Checked directly, so it
		// works even where PHP's ZipArchive is unavailable.
		$head = (string) @file_get_contents( $tmp, false, null, 0, 4 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		if ( "PK\x03\x04" !== $head ) {
			$type = wp_remote_retrieve_header( $res, 'content-type' );
			return new WP_Error( 'colandb_not_zip', 'GitHub\'s download did not return a zip (' . implode( '; ', $steps ) . ( $type ? '; content type ' . ( is_array( $type ) ? implode( ', ', $type ) : $type ) : '' ) . ').' . self::describe_file( $tmp ) );
		}
		// A download that stopped early still starts with "PK", so also compare
		// against the size the server announced.
		clearstatcache( true, $tmp );
		$got      = (int) filesize( $tmp );
		$expected = (int) wp_remote_retrieve_header( $res, 'content-length' );
		if ( $expected > 0 && $got !== $expected ) {
			return new WP_Error( 'colandb_incomplete', 'The download stopped early: received ' . $got . ' of ' . $expected . ' bytes (' . implode( '; ', $steps ) . ').' );
		}
		$steps[] = 'Received a zip of ' . $got . ' bytes' . ( $expected > 0 ? ' (matches the announced size)' : ' (server did not announce a size)' );
		return $steps;
	}

	/**
	 * Diagnostics: records what WordPress is about to unpack right after one
	 * of our downloads, and how its two zip readers judge that file. Hooked
	 * to unzip_file_use_ziparchive and returns its value unchanged; the file
	 * being unpacked is read from unzip_file()'s arguments.
	 */
	public static function record_unzip( $use_ziparchive ) {
		$last = self::settings()['last_download'];
		if ( empty( $last['time'] ) || time() - (int) $last['time'] > 600 ) {
			return $use_ziparchive;
		}
		$file = null;
		foreach ( debug_backtrace( 0, 10 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			if ( isset( $frame['function'], $frame['args'][0] ) && 'unzip_file' === $frame['function'] && ! isset( $frame['class'] ) ) {
				$file = (string) $frame['args'][0];
				break;
			}
		}
		if ( null === $file ) {
			return $use_ziparchive; // Not an unzip_file() call (e.g. wp_zip_file_is_valid()).
		}
		$result = $use_ziparchive;
		$info = array(
			'time'          => time(),
			'file'          => (string) $file,
			'same_file'     => isset( $last['file'] ) && $last['file'] === $file,
			'exists'        => file_exists( $file ),
			'size'          => file_exists( $file ) ? (int) filesize( $file ) : 0,
			'md5'           => file_exists( $file ) ? (string) md5_file( $file ) : '',
			'head'          => file_exists( $file ) ? bin2hex( (string) @file_get_contents( $file, false, null, 0, 8 ) ) : '', // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			'use_ziparchive' => class_exists( 'ZipArchive', false ) && $use_ziparchive,
			'checkcons'     => '',
			'pclzip'        => '',
		);
		if ( class_exists( 'ZipArchive', false ) && $info['exists'] ) {
			$z    = new ZipArchive();
			$open = $z->open( $file, ZipArchive::CHECKCONS );
			if ( true === $open ) {
				$z->close();
			}
			$info['checkcons'] = true === $open ? 'ok' : 'error ' . $open;
		}
		if ( $info['exists'] ) {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			$pcl            = new PclZip( $file );
			$info['pclzip'] = is_array( $pcl->properties() ) ? 'ok' : $pcl->errorInfo( true );
		}

		// The file we verified a moment ago has been removed or changed before
		// WordPress could unpack it. Put the verified copy back (only ever for
		// the exact file this updater downloaded, matched by fingerprint), and
		// say so on the update screen.
		$changed = $info['same_file'] && ( ! $info['exists'] || $info['md5'] !== $last['md5'] );
		if ( $changed ) {
			$backup = isset( $last['backup'] ) ? (string) $last['backup'] : '';
			if ( $backup && file_exists( $backup ) && md5_file( $backup ) === $last['md5'] && @copy( $backup, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$info['repaired'] = true;
			} else {
				$info['repaired'] = false;
			}
			$what = $info['exists']
				? sprintf( 'had changed (%d bytes, starts %s; expected %d bytes)', $info['size'], $info['head'], (int) $last['size'] )
				: 'had been deleted';
			self::say( 'COL&B Plugin Updater: the downloaded file ' . $what . ' before WordPress could unpack it. ' . ( $info['repaired'] ? 'Restored the verified copy and continuing.' : 'Could not restore the verified copy.' ) );
		} elseif ( ! $info['same_file'] ) {
			self::say( 'COL&B Plugin Updater: WordPress is unpacking ' . $file . ', not the file this updater downloaded (' . ( isset( $last['file'] ) ? $last['file'] : '?' ) . ').' );
		}
		self::update_settings( array( 'last_unzip' => $info ) );
		return $result;
	}

	/**
	 * Diagnostic line on the update screen (never in AJAX/JSON responses).
	 */
	private static function say( $message ) {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::log( $message );
			return;
		}
		echo '<p><strong>' . esc_html( $message ) . '</strong></p>';
	}

	/**
	 * A second, verified copy of a download, in this plugin's own folder
	 * (outside WordPress's temporary and upgrade folders), so the update can
	 * continue if the original is removed or changed before unpacking.
	 * Copies older than an hour are cleaned up on each download.
	 */
	private static function keep_verified_copy( $tmp ) {
		$dir = WP_CONTENT_DIR . '/colandb-updater-cache';
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			@file_put_contents( $dir . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		foreach ( (array) glob( $dir . '/*.zip' ) as $old ) {
			if ( $old && filemtime( $old ) < time() - HOUR_IN_SECONDS ) {
				@unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		$copy = $dir . '/' . wp_generate_password( 32, false ) . '.zip';
		return @copy( $tmp, $copy ) ? $copy : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Removes the verified copy once WordPress has finished an update.
	 */
	public static function cleanup_copy() {
		$last = self::settings()['last_download'];
		if ( ! empty( $last['backup'] ) && file_exists( $last['backup'] ) ) {
			@unlink( $last['backup'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	private static function describe_file( $file ) {
		$body = (string) @file_get_contents( $file, false, null, 0, 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		$body = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ) );
		$body = preg_replace( '/[^\x20-\x7E]/', '?', $body );
		return '' === $body ? '' : ' It began: "' . substr( $body, 0, 200 ) . '"';
	}

	private static function verify_zip( $file, $slug ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			$list = ( new PclZip( $file ) )->listContent();
			if ( ! is_array( $list ) || ! $list ) {
				return new WP_Error( 'colandb_zip', 'The downloaded file is not a valid zip.' );
			}
			$names = wp_list_pluck( $list, 'filename' );
			foreach ( $names as $name ) {
				if ( 0 !== strpos( $name, $slug . '/' ) || false !== strpos( $name, '..' ) ) {
					return new WP_Error( 'colandb_zip', 'The downloaded zip does not contain the ' . $slug . ' plugin folder as expected, so it was not installed.' );
				}
			}
			return in_array( $slug . '/' . $slug . '.php', $names, true ) ? true : new WP_Error( 'colandb_zip', 'The downloaded zip does not contain the ' . $slug . ' plugin folder as expected, so it was not installed.' );
		}
		$zip = new ZipArchive();
		// Same strict mode WordPress's unzip_file() uses.
		$opened = $zip->open( $file, ZipArchive::CHECKCONS );
		if ( true !== $opened ) {
			return new WP_Error( 'colandb_zip', 'The downloaded file is not a valid zip (ZipArchive error ' . $opened . ', ' . (int) filesize( $file ) . ' bytes).' );
		}
		$ok = $zip->numFiles > 0;
		for ( $i = 0; $ok && $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			$ok   = 0 === strpos( $name, $slug . '/' ) && false === strpos( $name, '..' );
		}
		$main = $zip->locateName( $slug . '/' . $slug . '.php' );
		$zip->close();
		if ( ! $ok || false === $main ) {
			return new WP_Error( 'colandb_zip', 'The downloaded zip does not contain the ' . $slug . ' plugin folder as expected, so it was not installed.' );
		}
		return true;
	}

	public static function package_options( $options ) {
		$plugin = isset( $options['hook_extra']['plugin'] ) ? (string) $options['hook_extra']['plugin'] : '';
		$slug   = $plugin ? dirname( $plugin ) : '';
		if ( ! $slug || ! in_array( $slug, self::managed_slugs(), true ) ) {
			return $options;
		}
		$received = isset( $options['package'] ) ? (string) $options['package'] : '';
		$latest   = self::latest( $slug );
		$attempt  = array(
			'time'     => time(),
			'plugin'   => $plugin,
			'received' => $received,
			'replaced' => false,
			'version'  => $latest ? $latest['version'] : '',
		);
		if ( $latest && $received !== $latest['asset_url'] ) {
			$options['package']  = $latest['asset_url'];
			$attempt['replaced'] = true;
			self::say( 'COL&B Plugin Updater: WordPress was about to download ' . self::short_url( $received ) . ' for this plugin; using its GitHub release ' . $latest['version'] . ' instead.' );
		}
		self::update_settings( array( 'last_attempt' => $attempt ) );
		return $options;
	}

	private static function short_url( $url ) {
		if ( '' === $url ) {
			return '(no address)';
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return 'a local file';
		}
		return $parts['host'] . ( isset( $parts['path'] ) ? $parts['path'] : '' );
	}

	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || ! in_array( $args->slug, self::managed_slugs(), true ) ) {
			return $result;
		}
		$installed = null;
		foreach ( self::installed() as $file => $data ) {
			if ( dirname( $file ) === $args->slug ) {
				$installed = $data;
			}
		}
		$latest = self::latest( $args->slug );
		if ( ! $installed && ! $latest ) {
			return $result;
		}
		return (object) array(
			'name'          => $installed ? $installed['Name'] : $args->slug,
			'slug'          => $args->slug,
			'version'       => $latest ? $latest['version'] : $installed['Version'],
			'author'        => $installed ? $installed['Author'] : '',
			'homepage'      => 'https://github.com/' . self::REPO,
			'last_updated'  => $latest ? $latest['published'] : '',
			'download_link' => $latest ? $latest['asset_url'] : '',
			'sections'      => array(
				'changelog' => $latest && '' !== trim( $latest['notes'] ) ? wpautop( esc_html( $latest['notes'] ) ) : '<p>See the plugin\'s CHANGELOG.md.</p>',
			),
		);
	}

	/**
	 * Staging installs managed updates automatically; on stable the
	 * administrator's own auto-update toggle (off by default) decides.
	 */
	public static function auto_update( $update, $item ) {
		if ( 'staging' === self::channel() && isset( $item->slug ) && in_array( $item->slug, self::managed_slugs(), true ) ) {
			return true;
		}
		return $update;
	}

	/* ------------------------------------------------------------------
	 * Settings → Plugin Updates
	 * ---------------------------------------------------------------- */

	public static function menu() {
		add_options_page( 'Plugin Updates', 'Plugin Updates', 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">Settings</a>' );
		return $links;
	}

	private static function back( $notice ) {
		wp_safe_redirect( add_query_arg( 'colandb_notice', $notice, admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'colandb_updater_save' );
		$changes = array();

		$channel            = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';
		$changes['channel'] = in_array( $channel, array( 'staging', 'stable' ), true ) ? $channel : '';

		if ( ! empty( $_POST['remove_token'] ) ) {
			$changes['token'] = '';
		} elseif ( isset( $_POST['token'] ) && '' !== trim( wp_unslash( $_POST['token'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$changes['token'] = preg_replace( '/[^A-Za-z0-9_]/', '', wp_unslash( $_POST['token'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		self::update_settings( $changes );
		delete_site_transient( self::CACHE );
		delete_site_transient( 'update_plugins' );
		self::back( 'saved' );
	}

	public static function handle_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'colandb_updater_check' );
		delete_site_transient( self::CACHE );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		self::back( 'checked' );
	}

	/**
	 * "Update now" on the settings screen: refreshes WordPress's update data
	 * (so it definitely knows about the newest release), then hands over to
	 * WordPress's own plugin updater screen, which does the install.
	 */
	public static function handle_update() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Not allowed.' );
		}
		$file = isset( $_GET['plugin'] ) ? sanitize_text_field( wp_unslash( $_GET['plugin'] ) ) : '';
		check_admin_referer( 'colandb_updater_update_' . $file );
		if ( ! isset( self::installed()[ $file ] ) ) {
			wp_die( 'That plugin is not managed by COL&B Plugin Updater.' );
		}
		delete_site_transient( self::CACHE );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		// Built with add_query_arg(), not wp_nonce_url(): the latter HTML-encodes
		// "&" for use in links, which breaks the nonce in a redirect.
		wp_safe_redirect(
			add_query_arg(
				array(
					'action'   => 'upgrade-plugin',
					'plugin'   => rawurlencode( $file ),
					'_wpnonce' => wp_create_nonce( 'upgrade-plugin_' . $file ),
				),
				self_admin_url( 'update.php' )
			)
		);
		exit;
	}

	/**
	 * "Test download": fetches this updater's own newest release exactly the
	 * way an update would, without installing anything, and records each
	 * step plus anything else hooked into WordPress's download step.
	 */
	public static function handle_test() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'colandb_updater_test' );
		$lines    = array();
		$releases = self::releases( true );
		if ( is_wp_error( $releases ) ) {
			$lines[] = 'FAILED: ' . $releases->get_error_message();
		}
		foreach ( is_wp_error( $releases ) ? array() : self::installed() as $file => $data ) {
			$slug   = dirname( $file );
			$latest = self::latest( $slug, 'staging' );
			if ( ! $latest ) {
				$lines[] = $data['Name'] . ': no release to test.';
				continue;
			}
			$tmp    = wp_tempnam( 'colandb-test.zip' );
			$result = self::fetch_asset( $latest['asset_url'], $tmp );
			if ( is_wp_error( $result ) ) {
				$lines[] = $data['Name'] . ' ' . $latest['version'] . ': FAILED: ' . $result->get_error_message();
			} else {
				$check   = self::verify_zip( $tmp, $slug );
				$lines[] = $data['Name'] . ' ' . $latest['version'] . ': ' . implode( '; ', $result ) . '. ' . ( is_wp_error( $check ) ? 'FAILED: ' . $check->get_error_message() : 'Zip OK.' );
			}
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$lines[] = 'PHP ZipArchive available: ' . ( class_exists( 'ZipArchive' ) ? 'yes' : 'no' );
		global $wp_filter;
		$others = array();
		if ( isset( $wp_filter['upgrader_pre_download'] ) ) {
			foreach ( $wp_filter['upgrader_pre_download']->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					$f = $cb['function'];
					$name = is_array( $f ) ? ( is_object( $f[0] ) ? get_class( $f[0] ) : $f[0] ) . '::' . $f[1] : ( is_string( $f ) ? $f : 'closure' );
					if ( 'COLANDB_Updater::download' !== $name ) {
						$others[] = $name . ' (priority ' . $priority . ')';
					}
				}
			}
		}
		$lines[] = 'Other code hooked into downloads: ' . ( $others ? implode( ', ', $others ) : 'none' );
		self::update_settings( array( 'last_test' => array( 'time' => time(), 'lines' => $lines ) ) );
		self::back( 'tested' );
	}

	private static function can_update_here() {
		return current_user_can( 'update_plugins' ) && wp_is_file_mod_allowed( 'colandb_updater' );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s        = self::settings();
		$source   = self::token_source();
		$channel  = self::channel();
		$releases = $source ? self::releases() : null;
		$notice   = isset( $_GET['colandb_notice'] ) ? sanitize_key( $_GET['colandb_notice'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1>Plugin Updates</h1>
			<?php if ( 'saved' === $notice ) : ?>
				<div class="notice notice-success"><p>Settings saved.</p></div>
			<?php elseif ( 'tested' === $notice && ! empty( $s['last_test']['lines'] ) ) : ?>
				<div class="notice notice-info"><p><strong>Download test</strong></p><ul style="list-style:disc;margin-left:20px">
					<?php foreach ( $s['last_test']['lines'] as $line ) : ?>
						<li><?php echo esc_html( $line ); ?></li>
					<?php endforeach; ?>
				</ul><p>Copy these lines to your developer if anything says FAILED.</p></div>
			<?php elseif ( 'checked' === $notice ) : ?>
				<div class="notice notice-success"><p>Checked GitHub for new releases. Any update appears below and under Plugins.</p></div>
			<?php endif; ?>

			<p style="max-width:760px">Updates these custom plugins from the GitHub repository <code><?php echo esc_html( self::REPO ); ?></code>. The token is stored for this site only and never shown again.</p>

			<h2>Connection</h2>
			<?php if ( ! $source ) : ?>
				<div class="notice notice-warning inline"><p>No GitHub token yet, so no updates can be found. Add one below.</p></div>
			<?php elseif ( is_wp_error( $releases ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $releases->get_error_message() ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-success inline"><p>Connected to GitHub. Last checked <?php echo esc_html( $s['last_check'] ? human_time_diff( $s['last_check'] ) . ' ago' : 'just now' ); ?>.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="colandb_updater_save" />
				<?php wp_nonce_field( 'colandb_updater_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="colandb_token">GitHub token</label></th>
						<td>
							<?php if ( 'wp-config.php' === $source ) : ?>
								<p>Set in <code>wp-config.php</code> (<code>COLANDB_GITHUB_TOKEN</code>).</p>
							<?php else : ?>
								<input type="password" class="regular-text" id="colandb_token" name="token" autocomplete="off" placeholder="<?php echo $source ? 'Saved – leave blank to keep it' : 'github_pat_…'; ?>" />
								<?php if ( $source ) : ?>
									<label style="margin-left:12px"><input type="checkbox" name="remove_token" value="1" /> Remove saved token</label>
								<?php endif; ?>
								<p class="description">A fine-grained personal access token with access to only the <code>colandb</code> repository and the permission <strong>Contents: Read-only</strong>.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="colandb_channel">This site is</label></th>
						<td>
							<select id="colandb_channel" name="channel">
								<?php $detected = in_array( wp_get_environment_type(), array( 'staging', 'development', 'local' ), true ) ? 'staging' : 'live'; ?>
								<option value="" <?php selected( $s['channel'], '' ); ?>>Detect automatically (detected: <?php echo esc_html( $detected ); ?>)</option>
								<option value="staging" <?php selected( $s['channel'], 'staging' ); ?>>Staging – install every new release (including pre-releases) automatically</option>
								<option value="stable" <?php selected( $s['channel'], 'stable' ); ?>>Live – stable releases only, installed when you click Update</option>
							</select>
							<p class="description">WordPress environment type here: <code><?php echo esc_html( wp_get_environment_type() ); ?></code>. Choose explicitly if detection is wrong.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save' ); ?>
			</form>

			<h2>Plugins</h2>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th>Plugin</th><th>Installed</th><th>Newest for this site (<?php echo esc_html( 'staging' === $channel ? 'staging' : 'live' ); ?>)</th><th>Status</th><th><span class="screen-reader-text">Action</span></th></tr></thead>
				<tbody>
				<?php
				$installed = self::installed();
				if ( ! $installed ) {
					echo '<tr><td colspan="5">None of the managed plugins is installed.</td></tr>';
				}
				foreach ( $installed as $file => $data ) {
					$latest = is_array( $releases ) ? self::latest( dirname( $file ) ) : null;
					if ( ! is_array( $releases ) ) {
						$status = '–';
					} elseif ( ! $latest ) {
						$status = 'No release published yet';
					} elseif ( version_compare( $latest['version'], $data['Version'], '>' ) ) {
						$status = 'staging' === $channel ? 'Update available – installs automatically, or update now' : 'Update available';
					} else {
						$status = 'Up to date';
					}
					$action = '';
					if ( $latest && version_compare( $latest['version'], $data['Version'], '>' ) && self::can_update_here() ) {
						$url    = wp_nonce_url( admin_url( 'admin-post.php?action=colandb_updater_update&plugin=' . rawurlencode( $file ) ), 'colandb_updater_update_' . $file );
						$action = '<a class="button button-primary" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( 'Update ' . $data['Name'] . ' to ' . $latest['version'] ) . '">Update now</a>';
					}
					printf(
						'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
						esc_html( $data['Name'] ),
						esc_html( $data['Version'] ),
						esc_html( $latest ? $latest['version'] . ( $latest['prerelease'] ? ' (pre-release)' : '' ) : '–' ),
						esc_html( $status ),
						$action // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
					);
				}
				?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
				<input type="hidden" name="action" value="colandb_updater_check" />
				<?php wp_nonce_field( 'colandb_updater_check' ); ?>
				<?php submit_button( 'Check for updates now', 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
				<input type="hidden" name="action" value="colandb_updater_test" />
				<?php wp_nonce_field( 'colandb_updater_test' ); ?>
				<?php submit_button( 'Test download', 'secondary', 'submit', false ); ?>
				<span class="description">Downloads the newest release of each plugin listed above from GitHub without installing anything, and shows each step.</span>
			</form>
			<?php if ( ! empty( $s['last_attempt']['time'] ) ) : $a = $s['last_attempt']; ?>
				<h2>Last update</h2>
				<ul style="list-style:disc;margin-left:20px">
					<li><?php echo esc_html( human_time_diff( $a['time'] ) . ' ago: ' . $a['plugin'] . ( $a['version'] ? ' → ' . $a['version'] : '' ) ); ?></li>
					<li>Download address WordPress had: <?php echo esc_html( self::short_url( $a['received'] ) ); ?><?php echo $a['replaced'] ? esc_html( ' — replaced with the GitHub release' ) : esc_html( ' — the GitHub release (unchanged)' ); ?></li>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $s['last_download']['time'] ) && '' === $s['last_download']['error'] ) : ?>
				<h2>Last install attempt</h2>
				<ul style="list-style:disc;margin-left:20px">
					<li>Downloaded (<?php echo esc_html( human_time_diff( $s['last_download']['time'] ) ); ?> ago): <?php echo esc_html( $s['last_download']['slug'] . ' — ' . $s['last_download']['size'] . ' bytes, fingerprint ' . substr( (string) $s['last_download']['md5'], 0, 12 ) . ', ' . $s['last_download']['file'] ); ?></li>
					<?php if ( ! empty( $s['last_unzip'] ) ) : $u = $s['last_unzip']; ?>
						<li>WordPress unpacked: <?php echo esc_html( $u['file'] . ' — ' . ( $u['same_file'] ? 'same file' : 'a DIFFERENT file' ) . ', ' . ( $u['exists'] ? $u['size'] . ' bytes, fingerprint ' . substr( $u['md5'], 0, 12 ) . ', starts ' . $u['head'] : 'file missing' ) ); ?></li>
						<li>Zip readers: <?php echo esc_html( 'ZipArchive in use: ' . ( $u['use_ziparchive'] ? 'yes' : 'NO (switched off)' ) . '; strict check: ' . ( $u['checkcons'] ? $u['checkcons'] : 'n/a' ) . '; PclZip: ' . ( $u['pclzip'] ? $u['pclzip'] : 'n/a' ) ); ?></li>
						<?php if ( isset( $u['repaired'] ) ) : ?>
							<li>Repair: <?php echo esc_html( $u['repaired'] ? 'the file had been removed or changed; the verified copy was restored' : 'the file had been removed or changed and could not be restored' ); ?></li>
						<?php endif; ?>
					<?php else : ?>
						<li>WordPress did not reach the unpack step after this download.</li>
					<?php endif; ?>
				</ul>
				<p class="description">If an update fails, copy this section to your developer.</p>
			<?php endif; ?>
			<?php if ( ! empty( $s['last_download']['time'] ) && '' !== $s['last_download']['error'] ) : ?>
				<p><strong>Last failed download</strong> (<?php echo esc_html( human_time_diff( $s['last_download']['time'] ) ); ?> ago, <?php echo esc_html( $s['last_download']['slug'] ); ?>): <?php echo esc_html( $s['last_download']['error'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}

COLANDB_Updater::init();
