<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explicit consent before a member's first intimate photo (0.32.0).
 *
 * Chastity verification photos and homework proof photos are data about a
 * member's sex life: special-category data under GDPR (Art. 9) and
 * "sensitive data" under several US state laws, which ask for opt-in
 * consent. The first time a member sends one, the form shows a tick box
 * saying what's kept, who sees it and for how long. The server refuses the
 * photo without it, then records when it was given (user meta and an
 * audit-log row) and doesn't ask again. Withdrawing consent = deleting the
 * photos (Your photos / the homework log) or the account.
 */
class CMP_Photo_Consent {

	const META = 'cmp_photo_consent_at';

	public static function given( $user_id ) {
		return '' !== (string) get_user_meta( (int) $user_id, self::META, true );
	}

	/** The tick box, or '' once consent is on record. */
	public static function field_html( $user_id, $id, $required = false ) {
		if ( self::given( $user_id ) ) {
			return '';
		}
		$privacy = (string) CMP_Settings::get( 'privacy_notice_url' );
		return '<p class="cmp-check cmp-photo-consent"><input type="checkbox" id="' . esc_attr( $id ) . '" name="photo_consent" value="1"' . ( $required ? ' required' : '' ) . ' /><label for="' . esc_attr( $id ) . '">'
			. esc_html__( 'I agree that COL&B keeps my intimate photos encrypted, shows them only to me and the member I send them to here (my keyholder, or whoever set my homework), and deletes each one 90 days after it\'s taken. I can download or delete them sooner, and I can withdraw this by deleting them.', 'cmp' )
			. ( $privacy ? ' <a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy statement', 'cmp' ) . '</a>' : '' )
			. '</label></p>';
	}

	/**
	 * Call when a photo was posted. True when consent is on record, or was
	 * given with this request (and is then recorded).
	 */
	public static function check( $user_id ) {
		if ( self::given( $user_id ) ) {
			return true;
		}
		if ( empty( $_POST['photo_consent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers checked the nonce.
			return false;
		}
		$at = gmdate( 'Y-m-d H:i:s' );
		update_user_meta( (int) $user_id, self::META, $at );
		CMP_Audit::log( 'photo_consent_given', 'user', (int) $user_id, null, array( 'at' => $at ) );
		return true;
	}

	public static function export_rows( $user_id ) {
		$at = (string) get_user_meta( (int) $user_id, self::META, true );
		return '' === $at ? array() : array( array( 'name' => __( 'Consent to keep intimate photos', 'cmp' ), 'value' => $at . ' UTC' ) );
	}

	public static function erase( $user_id ) {
		return delete_user_meta( (int) $user_id, self::META );
	}
}
