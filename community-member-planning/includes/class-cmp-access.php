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
 * email address (decision D2) and given a date of birth showing 18+ (owner
 * decision 2026-10-04, replacing the brief's checkbox attestation; see
 * CMP_Birth_Date). Accounts that only ticked the old checkbox are asked for
 * the date once, through the same "unattested" step.
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

	/**
	 * Past the age step: a date of birth on file showing 18+, and not locked
	 * for having given an under-18 date. (The name predates 0.4.0, when this
	 * was the 18+ checkbox.)
	 */
	public static function has_attested( $user_id ) {
		return CMP_Birth_Date::qualifies( $user_id );
	}

	/**
	 * Records when the member passed the age step and which wording they
	 * saw. The date of birth itself is stored by CMP_Birth_Date.
	 */
	public static function record_attestation( $user_id ) {
		if ( get_user_meta( $user_id, self::META_ATTESTED_AT, true ) ) {
			return;
		}
		$at      = gmdate( 'Y-m-d H:i:s' );
		$version = (int) CMP_Settings::get( 'attestation_version' );
		update_user_meta( $user_id, self::META_ATTESTED_AT, $at );
		update_user_meta( $user_id, self::META_ATTESTED_VERSION, $version );
		CMP_Audit::log( 'age_attested', 'user', $user_id, null, array( 'attested_at' => $at, 'version' => $version ), '', $user_id );
	}
}
