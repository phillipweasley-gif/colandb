<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single server-side answer to "may this account use the member area?"
 * Every page, REST route, feed and file route in this plugin goes through
 * is_member() (brief §5: "Enforce authorization server-side on every page,
 * API request, calendar feed, image upload, and file download"). Being
 * logged in is not enough: the account must also have verified its current
 * email address (decision D2) and accepted the 18+ self-attestation (brief
 * §2: "before first access to the member area").
 */
class CMP_Access {

	const STATE_LOGGED_OUT = 'logged_out';
	const STATE_UNVERIFIED = 'unverified';
	const STATE_UNATTESTED = 'unattested';
	const STATE_MEMBER     = 'member';

	const META_ATTESTED_AT      = 'cmp_age_attested_at';
	const META_ATTESTED_VERSION = 'cmp_age_attestation_version';

	public static function state( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return self::STATE_LOGGED_OUT;
		}
		if ( ! CMP_Email_Verification::is_verified( $user_id ) ) {
			return self::STATE_UNVERIFIED;
		}
		if ( ! self::has_attested( $user_id ) ) {
			return self::STATE_UNATTESTED;
		}
		return self::STATE_MEMBER;
	}

	public static function is_member( $user_id = null ) {
		return self::STATE_MEMBER === self::state( $user_id );
	}

	public static function has_attested( $user_id ) {
		return (bool) get_user_meta( $user_id, self::META_ATTESTED_AT, true );
	}

	/**
	 * Stores only the flag (as a UTC timestamp) and the wording version —
	 * never a date of birth or ID (brief §2).
	 */
	public static function record_attestation( $user_id ) {
		if ( self::has_attested( $user_id ) ) {
			return;
		}
		$at      = gmdate( 'Y-m-d H:i:s' );
		$version = (int) CMP_Settings::get( 'attestation_version' );
		update_user_meta( $user_id, self::META_ATTESTED_AT, $at );
		update_user_meta( $user_id, self::META_ATTESTED_VERSION, $version );
		CMP_Audit::log( 'age_attested', 'user', $user_id, null, array( 'attested_at' => $at, 'version' => $version ), '', $user_id );
	}
}
