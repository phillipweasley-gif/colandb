<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight bearer-token system for guest (no-account) event editing.
 * The raw token only ever exists in the emailed URL; only its SHA-256 hash
 * is stored, so a database leak alone can't be used to edit someone's
 * event. Tokens expire after 7 days and are re-issued each time a guest
 * requests a fresh edit link.
 */
class CEC_Tokens {

	const TTL_DAYS = 7;

	public static function issue_for_event( $event_id ) {
		$raw = bin2hex( random_bytes( 20 ) );
		update_post_meta( $event_id, '_cec_edit_token_hash', hash( 'sha256', $raw ) );
		update_post_meta( $event_id, '_cec_edit_token_expires', time() + ( self::TTL_DAYS * DAY_IN_SECONDS ) );
		return $raw;
	}

	public static function verify( $event_id, $raw_token ) {
		if ( ! $event_id || ! $raw_token || 'cec_event' !== get_post_type( $event_id ) ) {
			return false;
		}
		if ( ! get_post_meta( $event_id, '_cec_submitter_email', true ) ) {
			return false; // not a guest submission — no token access at all.
		}
		$expires = (int) get_post_meta( $event_id, '_cec_edit_token_expires', true );
		if ( ! $expires || $expires < time() ) {
			return false;
		}
		$stored_hash = get_post_meta( $event_id, '_cec_edit_token_hash', true );
		return $stored_hash && hash_equals( $stored_hash, hash( 'sha256', $raw_token ) );
	}

	public static function build_edit_url( $base_url, $event_id, $raw_token ) {
		return add_query_arg(
			array(
				'cec_event' => $event_id,
				'cec_token' => $raw_token,
			),
			$base_url
		);
	}
}
