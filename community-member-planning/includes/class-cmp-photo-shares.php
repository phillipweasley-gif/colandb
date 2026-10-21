<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Private share links for chastity photos (0.31.0). Owner, 2026-10-21: can
 * the wearer show someone a photo without that person being a keyholder or
 * a member? The daily code isn't a password and opens nothing; instead the
 * wearer makes a link for one photo.
 *
 * - Only the wearer can make one, for one of their own photos.
 * - It expires after 1 hour, 1 day or 7 days, can be set to work once, and
 *   the wearer can turn it off at any time. Views are counted and the wearer
 *   is told the first time it's opened.
 * - The link is ?cmp_share=<id>.<token>. The token is a keyed hash (site
 *   secret, CMP_Photo_Vault::mac) of the share id and a random salt, so it
 *   can't be guessed or worked out from the database alone, and the wearer
 *   can copy the link again later.
 * - Opening the link shows a page with a "View photo" button; the photo is
 *   only sent after that button (a POST). Chat apps that fetch links for a
 *   preview (Telegram, iMessage, Slack …) only ever see that first page, so
 *   they neither show the photo nor use up a one-time link.
 * - The photo goes out inside the page (a data: URL) with no-store, noindex,
 *   no-referrer and a strict Content-Security-Policy.
 * - Links stop working when the photo is purged (90 days), the lock or the
 *   account is deleted, or the wearer turns them off. Rows are cleared a day
 *   after they stop working.
 */
class CMP_Photo_Shares {

	const QUERY      = 'cmp_share';
	const MAX_ACTIVE = 20;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 1 );
	}

	public static function t() {
		return CMP_Install::table( 'photo_shares' );
	}

	/** Expiry choices, seconds => label. */
	public static function lengths() {
		return array(
			HOUR_IN_SECONDS => __( '1 hour', 'cmp' ),
			DAY_IN_SECONDS  => __( '1 day', 'cmp' ),
			WEEK_IN_SECONDS => __( '7 days', 'cmp' ),
		);
	}

	private static function token( $share ) {
		return rtrim( strtr( base64_encode( CMP_Photo_Vault::mac( 'share|' . (int) $share->id . '|' . $share->salt ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function token_ok( $share, $token ) {
		$raw = base64_decode( strtr( (string) $token, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return false !== $raw && 32 === strlen( $raw ) && CMP_Photo_Vault::mac_ok( 'share|' . (int) $share->id . '|' . $share->salt, $raw );
	}

	public static function url( $share ) {
		return add_query_arg( self::QUERY, (int) $share->id . '.' . self::token( $share ), home_url( '/' ) );
	}

	public static function is_live( $share ) {
		return $share && ! $share->revoked_at && strtotime( $share->expires_at . ' UTC' ) > time() && ( ! (int) $share->max_views || (int) $share->views < (int) $share->max_views );
	}

	/** Working links the wearer made, newest first, keyed by event id. */
	public static function live_for_wearer( $wearer_id ) {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE wearer_id = %d AND revoked_at IS NULL AND expires_at > %s ORDER BY id DESC', $wearer_id, gmdate( 'Y-m-d H:i:s' ) ) ) as $s ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( self::is_live( $s ) ) {
				$out[ (int) $s->event_id ][] = $s;
			}
		}
		return $out;
	}

	/** @return int|WP_Error New share id. */
	public static function create( $event_id, $lock, $wearer_id, $seconds, $once ) {
		global $wpdb;
		if ( ! isset( self::lengths()[ $seconds ] ) ) {
			return new WP_Error( 'cl_invalid' );
		}
		$live = 0;
		foreach ( self::live_for_wearer( $wearer_id ) as $list ) {
			$live += count( $list );
		}
		if ( $live >= self::MAX_ACTIVE ) {
			return new WP_Error( 'cl_share_max' );
		}
		$now = time();
		$wpdb->insert(
			self::t(),
			array(
				'wearer_id'  => (int) $wearer_id,
				'lock_id'    => (int) $lock->id,
				'event_id'   => (int) $event_id,
				'salt'       => bin2hex( random_bytes( 16 ) ),
				'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $now + $seconds ),
				'max_views'  => $once ? 1 : 0,
				'views'      => 0,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function revoke( $share_id, $wearer_id ) {
		global $wpdb;
		return (bool) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET revoked_at = %s WHERE id = %d AND wearer_id = %d AND revoked_at IS NULL', gmdate( 'Y-m-d H:i:s' ), $share_id, $wearer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function delete_for_event( $event_id ) {
		global $wpdb;
		$wpdb->delete( self::t(), array( 'event_id' => (int) $event_id ), array( '%d' ) );
	}

	public static function delete_for_lock( $lock_id ) {
		global $wpdb;
		$wpdb->delete( self::t(), array( 'lock_id' => (int) $lock_id ), array( '%d' ) );
	}

	/** Daily: links that stopped working over a day ago, and links to purged or deleted photos. */
	public static function purge() {
		global $wpdb;
		$cut    = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$events = CMP_Install::table( 'lock_events' );
		$n      = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t() . ' WHERE expires_at < %s OR revoked_at < %s', $cut, $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$n     += (int) $wpdb->query( 'DELETE s FROM ' . self::t() . " s LEFT JOIN $events e ON e.id = s.event_id WHERE e.id IS NULL OR e.photo IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $n;
	}

	/** ?cmp_share=<id>.<token> */
	public static function maybe_serve() {
		if ( ! isset( $_GET[ self::QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		// 0.31.1: the host's CDN adds a "cache publicly" hint for itself
		// (ec-cdn-cache-control) to every page; a private photo must never have it.
		header_remove( 'ec-cdn-cache-control' );
		nocache_headers();
		header( 'Cache-Control: no-store, private, max-age=0' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive, noimageindex' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		header( 'Referrer-Policy: no-referrer' );
		header( "Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'" );
		header( 'Content-Type: text/html; charset=utf-8' );

		global $wpdb;
		$raw   = sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$parts = explode( '.', $raw, 2 );
		$share = 2 === count( $parts ) && ctype_digit( $parts[0] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', (int) $parts[0] ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$event = $share ? CMP_Chastity::event( (int) $share->event_id ) : null;
		$lock  = $event ? CMP_Chastity::lock( (int) $event->lock_id ) : null;
		if ( ! $share || ! self::token_ok( $share, $parts[1] ) || ! self::is_live( $share ) || ! $event || ! $lock || (int) $lock->id !== (int) $share->lock_id || (int) $lock->wearer_id !== (int) $share->wearer_id || null === $event->photo ) {
			self::page( 404, __( 'This link isn\'t available', 'cmp' ), '<p>' . esc_html__( 'It has expired, has already been used, or the person who shared it turned it off.', 'cmp' ) . '</p>' );
		}
		$expires = sprintf( /* translators: %s: date and time */ __( 'This link stops working %s.', 'cmp' ), wp_date( 'M j, g:i A T', strtotime( $share->expires_at . ' UTC' ) ) );
		$once    = (int) $share->max_views ? __( 'It works once: after you view the photo, the link stops working.', 'cmp' ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- viewing needs no account; the token is the permission.
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || empty( $_POST['cmp_view'] ) ) {
			$form = '<form method="post" action="' . esc_url( self::url( $share ) ) . '"><input type="hidden" name="cmp_view" value="1" /><button type="submit">' . esc_html__( 'View photo', 'cmp' ) . '</button></form>';
			self::page( 200, __( 'A private photo was shared with you', 'cmp' ), '<p>' . esc_html__( 'Someone on COL&B shared a private photo with you. It may be intimate (adults only). Open it only if you\'re 18 or over and you were expecting it.', 'cmp' ) . '</p><p class="m">' . esc_html( trim( $expires . ' ' . $once ) ) . '</p>' . $form );
		}

		// Count the view; a one-time link can only win this once.
		$counted = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET views = views + 1, last_view_at = %s WHERE id = %d AND revoked_at IS NULL AND ( max_views = 0 OR views < max_views )', gmdate( 'Y-m-d H:i:s' ), $share->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$photo   = $counted ? CMP_Photo_Vault::open( $event->photo, $lock->id, $lock->wearer_id ) : null;
		if ( null === $photo ) {
			self::page( 404, __( 'This link isn\'t available', 'cmp' ), '<p>' . esc_html__( 'It has expired, has already been used, or the person who shared it turned it off.', 'cmp' ) . '</p>' );
		}
		if ( ! (int) $share->views ) {
			CMP_Notifications::add( (int) $share->wearer_id, 'lock', __( 'A photo link you shared was just opened.', 'cmp' ), CMP_Chastity::url() );
		}
		$img = '<img src="data:image/jpeg;base64,' . base64_encode( $photo ) . '" alt="' . esc_attr__( 'Shared photo', 'cmp' ) . '" />'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		self::page( 200, __( 'Shared photo', 'cmp' ), $img . '<p class="m">' . esc_html__( 'Shared privately from COL&B. Please don\'t save, screenshot or forward it.', 'cmp' ) . ' ' . esc_html( (int) $share->max_views ? __( 'This link has now stopped working.', 'cmp' ) : $expires ) . '</p>' );
	}

	private static function page( $status, $title, $body ) {
		status_header( $status );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive,noimageindex"><meta name="referrer" content="no-referrer"><title>' . esc_html( $title ) . '</title>'
			. '<style>body{margin:0;background:#100F0F;color:#E6E6E6;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}main{max-width:720px;margin:0 auto;padding:32px 16px}h1{font-size:22px;color:#fff}img{display:block;max-width:100%;height:auto;border-radius:10px;margin:16px 0}.m{color:#B5B5B5;font-size:14px}button{background:#FF50D7;color:#100F0F;border:0;border-radius:999px;padding:12px 22px;font-weight:700;font-size:16px;cursor:pointer}</style></head>'
			. '<body><main><h1>' . esc_html( $title ) . '</h1>' . $body . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		exit;
	}

	/* Privacy */

	public static function export_rows( $user_id ) {
		global $wpdb;
		$rows = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE wearer_id = %d ORDER BY id', $user_id ) ) as $s ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows[] = array( 'name' => __( 'Photo share link', 'cmp' ), 'value' => sprintf( /* translators: 1: created, 2: expires, 3: views */ __( 'made %1$s · expires %2$s · viewed %3$d times', 'cmp' ), $s->created_at, $s->expires_at, (int) $s->views ) . ( $s->revoked_at ? ' · ' . __( 'turned off', 'cmp' ) : '' ) );
		}
		return $rows;
	}

	public static function erase( $user_id ) {
		global $wpdb;
		return (int) $wpdb->delete( self::t(), array( 'wearer_id' => (int) $user_id ), array( '%d' ) );
	}
}
