<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional end-to-end encryption for chastity photos (0.33.0). Owner,
 * 2026-10-21: encryption so nobody but the wearer and keyholder can see the
 * photos; chose "both" (server-side, 0.31.0, plus optional end-to-end).
 * Design: docs/privacy-security-plan.md §7. The browser half is
 * assets/js/e2e-photos.js.
 *
 * The server only ever stores, checks the shape of, and hands out data it
 * cannot decrypt:
 * - user meta cmp_e2e_pub: the member's P-256 public key (base64, 65 bytes);
 * - cmp_e2e_box: their private key, AES-GCM encrypted under a key derived
 *   from their photo passphrase (JSON: v, salt, iter, iv, ct);
 * - cmp_e2e_on: new chastity photos are encrypted end to end;
 * - cmp_e2e_at: when the key was set up.
 * Photos with the mode on arrive already encrypted ("CMPE2E1" envelopes,
 * see validate()), are stored with photo_e2e = 1 and sealed by the vault as
 * well, and are served as-is to the same people as before.
 * Losing the passphrase means losing those photos: there's no recovery,
 * by design (they're deleted after 90 days anyway).
 */
class CMP_E2E {

	const META_PUB = 'cmp_e2e_pub';
	const META_BOX = 'cmp_e2e_box';
	const META_ON  = 'cmp_e2e_on';
	const META_AT  = 'cmp_e2e_at';
	const MAGIC    = 'CMPE2E1';
	const MAX      = 8388608; // 8 MB

	public static function pub( $user_id ) {
		return (string) get_user_meta( (int) $user_id, self::META_PUB, true );
	}

	public static function box( $user_id ) {
		return (string) get_user_meta( (int) $user_id, self::META_BOX, true );
	}

	public static function has_key( $user_id ) {
		return '' !== self::pub( $user_id ) && '' !== self::box( $user_id );
	}

	/** New photos from this member are end to end. */
	public static function is_on( $user_id ) {
		return self::has_key( $user_id ) && '1' === (string) get_user_meta( (int) $user_id, self::META_ON, true );
	}

	/** Key id: first 8 bytes of SHA-256 of the raw public key, hex (the browser computes the same). */
	public static function kid( $user_id ) {
		$pub = self::pub( $user_id );
		return '' === $pub ? '' : substr( hash( 'sha256', (string) base64_decode( $pub, true ) ), 0, 16 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	private static function b64len( $s, $len = 0 ) {
		$raw = is_string( $s ) ? base64_decode( $s, true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return false;
		}
		return $len ? strlen( $raw ) === $len : strlen( $raw );
	}

	public static function valid_pub( $pub ) {
		$raw = is_string( $pub ) ? base64_decode( $pub, true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return false !== $raw && 65 === strlen( $raw ) && "\x04" === $raw[0];
	}

	public static function valid_box( $box ) {
		$b = json_decode( (string) $box, true );
		if ( ! is_array( $b ) || 1 !== ( $b['v'] ?? 0 ) || ! is_int( $b['iter'] ?? null ) || $b['iter'] < 100000 || $b['iter'] > 10000000 ) {
			return false;
		}
		$ct = self::b64len( $b['ct'] ?? null );
		return self::b64len( $b['salt'] ?? null, 16 ) && self::b64len( $b['iv'] ?? null, 12 ) && $ct && $ct >= 64 && $ct <= 2048;
	}

	public static function save_key( $user_id, $pub, $box ) {
		if ( ! self::valid_pub( $pub ) || ! self::valid_box( $box ) ) {
			return false;
		}
		update_user_meta( (int) $user_id, self::META_PUB, $pub );
		update_user_meta( (int) $user_id, self::META_BOX, wp_slash( $box ) );
		update_user_meta( (int) $user_id, self::META_AT, gmdate( 'Y-m-d H:i:s' ) );
		CMP_Audit::log( 'e2e_key_set', 'user', (int) $user_id, null, array( 'kid' => self::kid( $user_id ) ) );
		return true;
	}

	/** A new passphrase: same key pair, new box. */
	public static function rewrap( $user_id, $box ) {
		if ( ! self::has_key( $user_id ) || ! self::valid_box( $box ) ) {
			return false;
		}
		update_user_meta( (int) $user_id, self::META_BOX, wp_slash( $box ) );
		CMP_Audit::log( 'e2e_passphrase_changed', 'user', (int) $user_id );
		return true;
	}

	/** Forget the key (lost passphrase). Photos encrypted to it can't be opened any more. */
	public static function reset( $user_id ) {
		foreach ( array( self::META_PUB, self::META_BOX, self::META_ON, self::META_AT ) as $k ) {
			delete_user_meta( (int) $user_id, $k );
		}
		CMP_Audit::log( 'e2e_key_reset', 'user', (int) $user_id );
	}

	public static function set_mode( $user_id, $on ) {
		if ( $on && ! self::has_key( $user_id ) ) {
			return false;
		}
		update_user_meta( (int) $user_id, self::META_ON, $on ? '1' : '0' );
		return true;
	}

	/**
	 * Who a new photo on $lock is encrypted for: the wearer and, if they have
	 * a key, the member keyholder.
	 *
	 * @return array[] [ [ 'u' => user id, 'p' => public key ] ]
	 */
	public static function recipients( $lock ) {
		$out = array();
		foreach ( array_unique( array_filter( array( (int) $lock->wearer_id, (int) $lock->keyholder_id ) ) ) as $uid ) {
			if ( self::has_key( $uid ) ) {
				$out[] = array( 'u' => $uid, 'p' => self::pub( $uid ) );
			}
		}
		return $out;
	}

	/** Header of an envelope, or null. */
	public static function header( $bytes ) {
		if ( ! is_string( $bytes ) || strlen( $bytes ) < 11 || 0 !== strpos( $bytes, self::MAGIC ) ) {
			return null;
		}
		$n = unpack( 'N', substr( $bytes, 7, 4 ) )[1];
		if ( $n < 2 || $n > 8192 || strlen( $bytes ) < 11 + $n ) {
			return null;
		}
		$h = json_decode( substr( $bytes, 11, $n ), true );
		return is_array( $h ) ? $h + array( '_len' => $n ) : null;
	}

	/**
	 * The shape of an uploaded envelope: magic, header, a wrapped key for the
	 * wearer, recipients only among the lock's wearer and keyholder, sizes
	 * that match AES-GCM / P-256, and a ciphertext. The server can't check
	 * that it's a photo; it can only refuse anything malformed.
	 */
	public static function validate( $bytes, $lock ) {
		$h = self::header( $bytes );
		if ( ! $h || strlen( $bytes ) > self::MAX || 1 !== ( $h['v'] ?? 0 ) || ! self::b64len( $h['iv'] ?? null, 12 ) || empty( $h['r'] ) || ! is_array( $h['r'] ) || count( $h['r'] ) > 2 ) {
			return false;
		}
		$ct = strlen( $bytes ) - 11 - $h['_len'];
		if ( $ct < 128 ) {
			return false;
		}
		$allowed = array_filter( array( (int) $lock->wearer_id, (int) $lock->keyholder_id ) );
		$seen    = array();
		foreach ( $h['r'] as $r ) {
			$u = is_array( $r ) && isset( $r['u'] ) ? (int) $r['u'] : 0;
			if ( ! in_array( $u, $allowed, true ) || isset( $seen[ $u ] ) || ! self::valid_pub( $r['e'] ?? null ) || ! self::b64len( $r['i'] ?? null, 12 ) || ! self::b64len( $r['w'] ?? null, 48 ) ) {
				return false;
			}
			$seen[ $u ] = true;
		}
		return isset( $seen[ (int) $lock->wearer_id ] );
	}

	/* UI */

	private static function i18n() {
		return array(
			'unsupported' => __( 'This browser can\'t do end-to-end encryption. Use a current version of Chrome, Safari, Firefox or Edge.', 'cmp' ),
			'unlocked'    => __( 'Unlocked on this device.', 'cmp' ),
			'locked'      => __( 'Locked on this device. Enter your photo passphrase to see end-to-end photos.', 'cmp' ),
			'notForYou'   => __( 'This photo is end-to-end encrypted and wasn\'t encrypted for you, so you can\'t see it.', 'cmp' ),
			'cantOpen'    => __( 'This photo couldn\'t be opened on this device.', 'cmp' ),
			'tooShort'    => __( 'Use at least 10 characters.', 'cmp' ),
			'noMatch'     => __( 'The two passphrases don\'t match.', 'cmp' ),
			'working'     => __( 'Working… this takes a few seconds.', 'cmp' ),
			'failed'      => __( 'That didn\'t work. Nothing was sent. Try again.', 'cmp' ),
			'wrongPass'   => __( 'That passphrase isn\'t right.', 'cmp' ),
			'choose'      => __( 'Choose a photo first.', 'cmp' ),
			'encrypting'  => __( 'Encrypting your photo on this device…', 'cmp' ),
		);
	}

	private static function form_open( $do, $attrs = '' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cmp-form"' . $attrs . '><input type="hidden" name="action" value="cmp_chastity" /><input type="hidden" name="do" value="' . esc_attr( $do ) . '" /><input type="hidden" name="_cmp_nonce" value="' . esc_attr( wp_create_nonce( CMP_Chastity::NONCE ) ) . '" />';
	}

	private static function pass( $name, $id, $label, $autocomplete = 'current-password' ) {
		// No name attribute: a passphrase is never posted, even without JavaScript.
		return '<p class="cmp-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><input type="password" id="' . esc_attr( $id ) . '" data-cmp-e2e-field="' . esc_attr( $name ) . '" autocomplete="' . esc_attr( $autocomplete ) . '" minlength="10" /></p>';
	}

	/** The "End-to-end photos" panel on the Chastity tab (and a lock's keyholder page). */
	public static function panel_html( $user_id, $context = 'tab' ) {
		wp_enqueue_script( 'cmp-e2e', CMP_URL . 'assets/js/e2e-photos.js', array(), CMP_VERSION, true );
		$has  = self::has_key( $user_id );
		$cfg  = array( 'uid' => (int) $user_id, 'kid' => self::kid( $user_id ), 'box' => self::box( $user_id ), 'i18n' => self::i18n() );
		$html = '<section class="cmp-panel cmp-e2e" id="cmp-e2e" data-config="' . esc_attr( wp_json_encode( $cfg ) ) . '"><details' . ( isset( $_GET['e2e'] ) ? ' open' : '' ) . '><summary class="cmp-panel-title">' . esc_html__( 'End-to-end photos', 'cmp' ) . ' <span class="cmp-muted">' . esc_html( $has ? ( self::is_on( $user_id ) ? __( '(on)', 'cmp' ) : __( '(set up)', 'cmp' ) ) : __( '(optional)', 'cmp' ) ) . '</span></summary>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$html .= '<p class="cmp-muted" data-cmp-e2e-status role="status"></p>';
		if ( ! $has ) {
			$html .= '<p>' . esc_html__( 'Your photos are already encrypted on our server. End-to-end goes further: photos are encrypted on your device, and only you and your keyholder can decrypt them, with a photo passphrase only you know. Not even the site can see them, and neither could anyone who stole a copy of the site.', 'cmp' ) . '</p>';
			$html .= '<p class="cmp-hw-std">' . esc_html__( 'If you forget your photo passphrase, your end-to-end photos can\'t be recovered by anyone. Your keyholder needs to set this up too to see your photos.', 'cmp' ) . '</p>';
			$html .= self::form_open( 'e2e_setup', ' data-cmp-e2e-setup' ) . '<input type="hidden" name="pub" value="" /><input type="hidden" name="box" value="" />';
			$html .= self::pass( 'pass', 'cmp_e2e_p1', __( 'New photo passphrase (at least 10 characters; not your account password)', 'cmp' ), 'new-password' ) . self::pass( 'pass2', 'cmp_e2e_p2', __( 'Type it again', 'cmp' ), 'new-password' );
			$html .= '<p class="cmp-muted" data-cmp-e2e-msg role="alert"></p><button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Set up end-to-end photos', 'cmp' ) . '</button></form>';
			return $html . '</details></section>';
		}
		// Unlock on this device.
		$html .= self::form_open( 'e2e_unlock', ' data-cmp-e2e-unlock' ) . self::pass( 'pass', 'cmp_e2e_unlock', __( 'Photo passphrase', 'cmp' ) ) . '<p class="cmp-muted" data-cmp-e2e-msg role="alert"></p><div class="cmp-actions"><button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Unlock on this device', 'cmp' ) . '</button><button type="button" class="cmp-btn cmp-btn-small cmp-btn-outline" data-cmp-e2e-forget>' . esc_html__( 'Forget on this device', 'cmp' ) . '</button></div></form>';
		if ( 'tab' === $context ) {
			$on    = self::is_on( $user_id );
			$html .= self::form_open( 'e2e_mode' ) . '<input type="hidden" name="on" value="' . ( $on ? '0' : '1' ) . '" /><p>' . esc_html( $on ? __( 'New chastity photos you send are encrypted end to end.', 'cmp' ) : __( 'New chastity photos you send use the server\'s encryption.', 'cmp' ) ) . '</p><button type="submit" class="cmp-btn cmp-btn-small' . ( $on ? ' cmp-btn-outline' : '' ) . '">' . esc_html( $on ? __( 'Turn off for new photos', 'cmp' ) : __( 'Encrypt my new photos end to end', 'cmp' ) ) . '</button></form>';
			$html .= '<details class="cmp-hw-taskedit"><summary>' . esc_html__( 'Change photo passphrase', 'cmp' ) . '</summary>' . self::form_open( 'e2e_rewrap', ' data-cmp-e2e-rewrap' ) . '<input type="hidden" name="box" value="" />' . self::pass( 'old', 'cmp_e2e_old', __( 'Current photo passphrase', 'cmp' ) ) . self::pass( 'new', 'cmp_e2e_new', __( 'New photo passphrase', 'cmp' ), 'new-password' ) . self::pass( 'new2', 'cmp_e2e_new2', __( 'Type it again', 'cmp' ), 'new-password' ) . '<p class="cmp-muted" data-cmp-e2e-msg role="alert"></p><button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Change passphrase', 'cmp' ) . '</button></form></details>';
			$html .= '<details class="cmp-hw-taskedit"><summary>' . esc_html__( 'Forgot your passphrase?', 'cmp' ) . '</summary><p class="cmp-muted">' . esc_html__( 'Nobody can recover it. You can start again with a new key: photos already encrypted end to end for you can then no longer be opened by you (your keyholder keeps seeing the ones encrypted for them). New photos go back to the server\'s encryption until you turn end-to-end on again.', 'cmp' ) . '</p>' . self::form_open( 'e2e_reset' ) . '<button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline cmp-btn-danger" data-cmp-confirm="' . esc_attr__( 'Remove your end-to-end key? Photos encrypted end to end for you can\'t be opened by you any more.', 'cmp' ) . '">' . esc_html__( 'Remove my key and start again', 'cmp' ) . '</button></form></details>';
		}
		return $html . '</details></section>';
	}

	/** do=e2e_* from CMP_Chastity::handle(). */
	public static function handle( $do, $user_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- CMP_Chastity::handle() checked the nonce.
		$back = array( 'e2e' => 1 );
		$ok   = false;
		if ( 'e2e_setup' === $do ) {
			$ok = ! self::has_key( $user_id ) && self::save_key( $user_id, isset( $_POST['pub'] ) ? sanitize_text_field( wp_unslash( $_POST['pub'] ) ) : '', isset( $_POST['box'] ) ? wp_unslash( $_POST['box'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, checked by valid_box().
			$ok && self::set_mode( $user_id, true );
			self::go( $back, $ok ? 'cl_e2e_ready' : 'cl_e2e_bad' );
		}
		if ( 'e2e_rewrap' === $do ) {
			$ok = self::rewrap( $user_id, isset( $_POST['box'] ) ? wp_unslash( $_POST['box'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			self::go( $back, $ok ? 'cl_e2e_rewrapped' : 'cl_e2e_bad' );
		}
		if ( 'e2e_reset' === $do ) {
			self::reset( $user_id );
			self::go( $back, 'cl_e2e_reset' );
		}
		if ( 'e2e_mode' === $do ) {
			$ok = self::set_mode( $user_id, ! empty( $_POST['on'] ) );
			self::go( $back, $ok ? 'cl_saved' : 'cl_e2e_bad' );
		}
		// e2e_unlock only happens in the browser.
		self::go( $back, 'cl_e2e_bad' );
		// phpcs:enable
	}

	private static function go( $args, $notice ) {
		wp_safe_redirect( CMP_Chastity::url( $args, $notice ) . '#cmp-e2e' );
		exit;
	}

	public static function notices() {
		return array(
			'cl_e2e_ready'     => array( 'success', __( 'End-to-end photos are set up and on. New photos are encrypted on your device. Remember your photo passphrase: nobody can recover it.', 'cmp' ) ),
			'cl_e2e_rewrapped' => array( 'success', __( 'Photo passphrase changed. Other devices need the new one next time they unlock.', 'cmp' ) ),
			'cl_e2e_reset'     => array( 'success', __( 'Your end-to-end key was removed. New photos use the server\'s encryption until you set it up again.', 'cmp' ) ),
			'cl_e2e_bad'       => array( 'error', __( 'That didn\'t work. Reload the page and try again.', 'cmp' ) ),
			'cl_e2e_needed'    => array( 'error', __( 'Nothing was sent. You\'ve turned on end-to-end photos, and this photo wasn\'t encrypted on your device. Reload the page and try again in a current browser.', 'cmp' ) ),
			'cl_e2e_lockphoto' => array( 'error', __( 'With end-to-end photos on, send your photo as a verification once the lock has started.', 'cmp' ) ),
		);
	}

	/* Privacy */

	public static function export_rows( $user_id ) {
		$at = (string) get_user_meta( (int) $user_id, self::META_AT, true );
		return '' === $at ? array() : array( array( 'name' => __( 'End-to-end photo key', 'cmp' ), 'value' => sprintf( /* translators: 1: date, 2: on/off */ __( 'set up %1$s UTC; new photos end to end: %2$s. Your private key is stored only encrypted with your passphrase.', 'cmp' ), $at, self::is_on( $user_id ) ? __( 'yes', 'cmp' ) : __( 'no', 'cmp' ) ) ) );
	}

	public static function erase( $user_id ) {
		foreach ( array( self::META_PUB, self::META_BOX, self::META_ON, self::META_AT ) as $k ) {
			delete_user_meta( (int) $user_id, $k );
		}
	}
}
