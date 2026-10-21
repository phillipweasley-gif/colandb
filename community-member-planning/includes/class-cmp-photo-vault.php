<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encryption at rest for chastity photos (0.31.0). Owner, 2026-10-21: make
 * sure no one other than the keyholder or the wearer can see them.
 *
 * - Every photo is sealed with XChaCha20-Poly1305 (libsodium, or WordPress's
 *   bundled sodium_compat) before it reaches the database.
 * - Each wearer's lock has its own key, derived from a site secret plus the
 *   lock id and the wearer's user id. The site secret is CMP_PHOTO_KEY if
 *   wp-config.php defines it, otherwise the AUTH_KEY and SECURE_AUTH_KEY
 *   salts. It lives in wp-config.php, never in the database, so a copy of
 *   the database or a database backup alone can't open a photo.
 * - The lock and wearer are bound in as authenticated data: a sealed photo
 *   moved to another lock or member fails to open instead of showing.
 * - Stored form: "CMPv1:" . key id (8 bytes) . nonce (24) . ciphertext.
 *   Anything without the prefix is an older, unsealed photo and is read as
 *   is until seal_existing() converts it.
 *
 * Caveat, said plainly in the privacy statement: the site can still open the
 * photos to show them to the wearer and keyholder. Adding CMP_PHOTO_KEY later
 * is safe (older photos still open with the salts), but if the salts change
 * before then, older photos can no longer be opened; they're shown as not
 * found and purged on schedule like any other.
 */
class CMP_Photo_Vault {

	const PREFIX = 'CMPv1:';

	/**
	 * 32-byte master keys from the site secrets (wp-config.php), the one to
	 * seal with first. Older keys stay listed so adding CMP_PHOTO_KEY later
	 * doesn't lock out photos sealed with the salts.
	 */
	private static function masters() {
		$out = array();
		if ( defined( 'CMP_PHOTO_KEY' ) && strlen( (string) CMP_PHOTO_KEY ) >= 32 ) {
			$out[] = sodium_crypto_generichash( 'cmp-photo-vault|' . CMP_PHOTO_KEY, '', 32 );
		}
		$out[] = sodium_crypto_generichash( 'cmp-photo-vault|' . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . '|' . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' ), '', 32 );
		return $out;
	}

	private static function master() {
		return self::masters()[0];
	}

	private static function key_id( $master ) {
		return substr( sodium_crypto_generichash( 'key-id', $master, 16 ), 0, 8 );
	}

	/**
	 * Context: 'lockpic' (chastity: lock id, wearer id) or, 0.32.0,
	 * 'hwproof' (homework: program id, member id).
	 */
	private static function key( $master, $a, $b, $ctx = 'lockpic' ) {
		$label = 'lockpic' === $ctx ? 'lock:' . (int) $a . '|wearer:' . (int) $b : $ctx . '|a:' . (int) $a . '|b:' . (int) $b;
		return sodium_crypto_generichash( $label, $master, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES );
	}

	private static function aad( $a, $b, $ctx = 'lockpic' ) {
		return 'lockpic' === $ctx ? 'cmp-lockpic|lock:' . (int) $a . '|wearer:' . (int) $b : 'cmp-' . $ctx . '|a:' . (int) $a . '|b:' . (int) $b;
	}

	/** A keyed hash with the site secret (share-link tokens). */
	public static function mac( $data ) {
		return sodium_crypto_generichash( 'mac|' . $data, self::master(), 32 );
	}

	/** Whether $mac matches $data under any current master key. */
	public static function mac_ok( $data, $mac ) {
		foreach ( self::masters() as $m ) {
			if ( hash_equals( sodium_crypto_generichash( 'mac|' . $data, $m, 32 ), (string) $mac ) ) {
				return true;
			}
		}
		return false;
	}

	public static function is_sealed( $blob ) {
		return is_string( $blob ) && 0 === strpos( $blob, self::PREFIX );
	}

	/** JPEG bytes in, sealed bytes out. */
	public static function seal( $bytes, $lock_id, $wearer_id, $ctx = 'lockpic' ) {
		$master = self::master();
		$nonce  = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( (string) $bytes, self::aad( $lock_id, $wearer_id, $ctx ), $nonce, self::key( $master, $lock_id, $wearer_id, $ctx ) );
		return self::PREFIX . self::key_id( $master ) . $nonce . $cipher;
	}

	/** Sealed (or older plain) bytes in; JPEG bytes out, or null when it can't be opened. */
	public static function open( $blob, $lock_id, $wearer_id, $ctx = 'lockpic' ) {
		if ( ! is_string( $blob ) || '' === $blob ) {
			return null;
		}
		if ( ! self::is_sealed( $blob ) ) {
			return $blob; // Stored before 0.31.0; seal_existing() converts it.
		}
		$p    = strlen( self::PREFIX );
		$nlen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		if ( strlen( $blob ) < $p + 8 + $nlen + 16 ) {
			return null;
		}
		$plain = false;
		foreach ( self::masters() as $master ) {
			if ( hash_equals( self::key_id( $master ), substr( $blob, $p, 8 ) ) ) {
				$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $blob, $p + 8 + $nlen ), self::aad( $lock_id, $wearer_id, $ctx ), substr( $blob, $p + 8, $nlen ), self::key( $master, $lock_id, $wearer_id, $ctx ) );
				break;
			}
		}
		return false === $plain ? null : $plain;
	}

	/**
	 * Seal chastity photos stored before 0.31.0 (and, 0.32.0, homework proof
	 * photos stored before 0.32.0), in batches. Runs on the
	 * database upgrade and again with each daily clean-up until none are left.
	 *
	 * @return int Photos sealed.
	 */
	public static function seal_existing( $batch = 50 ) {
		global $wpdb;
		$events = CMP_Install::table( 'lock_events' );
		$locks  = CMP_Install::table( 'locks' );
		$n      = 0;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT e.id, e.lock_id, l.wearer_id FROM $events e JOIN $locks l ON l.id = e.lock_id WHERE e.photo IS NOT NULL AND LEFT(e.photo, 6) <> %s ORDER BY e.id LIMIT %d", self::PREFIX, $batch ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			$plain = $wpdb->get_var( $wpdb->prepare( "SELECT photo FROM $events WHERE id = %d", $r->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! is_string( $plain ) || '' === $plain || self::is_sealed( $plain ) ) {
				continue;
			}
			$n += (int) $wpdb->update( $events, array( 'photo' => self::seal( $plain, $r->lock_id, $r->wearer_id ) ), array( 'id' => (int) $r->id ) );
		}
		// 0.32.0: homework proof photos.
		$entries = CMP_Install::table( 'task_entries' );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT id, program_id, member_id FROM $entries WHERE photo IS NOT NULL AND LEFT(photo, 6) <> %s ORDER BY id LIMIT %d", self::PREFIX, $batch ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			$plain = $wpdb->get_var( $wpdb->prepare( "SELECT photo FROM $entries WHERE id = %d", $r->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! is_string( $plain ) || '' === $plain || self::is_sealed( $plain ) ) {
				continue;
			}
			$n += (int) $wpdb->update( $entries, array( 'photo' => self::seal( $plain, $r->program_id, $r->member_id, 'hwproof' ) ), array( 'id' => (int) $r->id ) );
		}
		return $n;
	}
}
