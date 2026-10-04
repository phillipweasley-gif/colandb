<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email verification for member-area access (decision D2: the site's
 * existing registration logs people in without verifying their address).
 *
 * An account counts as verified only while its *current* email matches the
 * address that was verified, so changing an email automatically requires
 * verifying the new one — no separate "reset" step can be missed.
 *
 * Tokens: 256 bits from random_bytes(), only a SHA-256 hash is stored,
 * single use, 7-day expiry, bound to the email they were sent to, and
 * re-sending is limited to once per 5 minutes per account (brief §5).
 */
class CMP_Email_Verification {

	const META_VERIFIED_EMAIL = 'cmp_verified_email';
	const META_VERIFIED_AT    = 'cmp_email_verified_at';
	const META_TOKEN_HASH     = 'cmp_verify_token_hash';
	const META_TOKEN_EXPIRES  = 'cmp_verify_token_expires';
	const META_TOKEN_EMAIL    = 'cmp_verify_token_email';

	const TOKEN_TTL   = 7 * DAY_IN_SECONDS;
	const RESEND_WAIT = 5 * MINUTE_IN_SECONDS;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_link' ), 1 );
		add_action( 'profile_update', array( __CLASS__, 'on_profile_update' ), 10, 2 );
	}

	public static function is_verified( $user_id ) {
		$user     = get_userdata( $user_id );
		$verified = get_user_meta( $user_id, self::META_VERIFIED_EMAIL, true );
		return $user && $verified && strtolower( $user->user_email ) === strtolower( $verified );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function send( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return new WP_Error( 'no_email', __( 'Your account has no valid email address. Please change it on the Account tab.', 'cmp' ) );
		}
		if ( self::is_verified( $user_id ) ) {
			return true;
		}
		$wait_key = 'cmp_verify_wait_' . $user_id;
		if ( get_transient( $wait_key ) ) {
			return new WP_Error( 'rate_limited', __( 'A verification email was sent a few minutes ago. Please check your inbox (and spam folder) before requesting another.', 'cmp' ) );
		}

		$token = bin2hex( random_bytes( 32 ) );
		update_user_meta( $user_id, self::META_TOKEN_HASH, hash( 'sha256', $token ) );
		update_user_meta( $user_id, self::META_TOKEN_EXPIRES, time() + self::TOKEN_TTL );
		update_user_meta( $user_id, self::META_TOKEN_EMAIL, strtolower( $user->user_email ) );
		set_transient( $wait_key, 1, self::RESEND_WAIT );

		$url = add_query_arg(
			array(
				'cmp_verify' => $token,
				'cmp_uid'    => $user_id,
			),
			home_url( '/' )
		);

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( /* translators: %s: site name */ __( '[%s] Confirm your email address', 'cmp' ), $site );
		$body    = sprintf(
			/* translators: 1: display name, 2: site name, 3: verification link */
			__( "Hi %1\$s,\n\nPlease confirm your email address to use the %2\$s member area:\n\n%3\$s\n\nThis link works once and expires in 7 days. If you didn't ask for this, you can ignore this email.", 'cmp' ),
			$user->display_name,
			$site,
			$url
		);

		if ( ! wp_mail( $user->user_email, $subject, $body ) ) {
			delete_transient( $wait_key );
			return new WP_Error( 'mail_failed', __( "We couldn't send the verification email. Please try again later or contact the site administrator.", 'cmp' ) );
		}

		CMP_Audit::log( 'email_verification_sent', 'user', $user_id, null, array( 'email' => strtolower( $user->user_email ) ) );
		return true;
	}

	/**
	 * Works whether or not the clicker is signed in (the link may be opened
	 * on another device): the token alone proves control of the inbox, and
	 * verifying only sets a flag on the account it was issued for.
	 */
	public static function handle_link() {
		if ( ! isset( $_GET['cmp_verify'], $_GET['cmp_uid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		nocache_headers();

		$token   = sanitize_text_field( wp_unslash( $_GET['cmp_verify'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = absint( $_GET['cmp_uid'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ok      = self::verify_token( $user_id, $token );

		wp_safe_redirect( add_query_arg( 'cmp_notice', $ok ? 'verified' : 'verify_failed', CMP_Settings::member_page_url() ) );
		exit;
	}

	public static function verify_token( $user_id, $token ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_string( $token ) || 64 !== strlen( $token ) ) {
			return false;
		}
		$hash    = get_user_meta( $user_id, self::META_TOKEN_HASH, true );
		$expires = (int) get_user_meta( $user_id, self::META_TOKEN_EXPIRES, true );
		$email   = get_user_meta( $user_id, self::META_TOKEN_EMAIL, true );

		if ( ! $hash || ! hash_equals( $hash, hash( 'sha256', $token ) ) ) {
			return false;
		}
		if ( $expires < time() || strtolower( $user->user_email ) !== $email ) {
			self::clear_token( $user_id );
			return false;
		}

		self::clear_token( $user_id );
		update_user_meta( $user_id, self::META_VERIFIED_EMAIL, $email );
		update_user_meta( $user_id, self::META_VERIFIED_AT, gmdate( 'Y-m-d H:i:s' ) );
		CMP_Audit::log( 'email_verified', 'user', $user_id, null, array( 'email' => $email ), '', $user_id );
		return true;
	}

	/**
	 * A changed address invalidates any outstanding link (it was sent to
	 * the old address); is_verified() already fails on the mismatch.
	 */
	public static function on_profile_update( $user_id, $old_user_data ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! $old_user_data || strtolower( $user->user_email ) === strtolower( $old_user_data->user_email ) ) {
			return;
		}
		self::clear_token( $user_id );
		CMP_Audit::log(
			'email_changed',
			'user',
			$user_id,
			array( 'email' => strtolower( $old_user_data->user_email ) ),
			array( 'email' => strtolower( $user->user_email ), 'verified' => false )
		);
	}

	private static function clear_token( $user_id ) {
		delete_user_meta( $user_id, self::META_TOKEN_HASH );
		delete_user_meta( $user_id, self::META_TOKEN_EXPIRES );
		delete_user_meta( $user_id, self::META_TOKEN_EMAIL );
	}
}
