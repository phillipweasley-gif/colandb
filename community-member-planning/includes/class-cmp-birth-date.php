<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Date of birth (owner decision, 2026-10-04, overriding the project brief's
 * "store the attestation flag and timestamp, not date of birth"): required
 * at sign-up, asked once of existing members, used to confirm 18+ and to
 * calculate the profile age. Entered once; members can't edit it, the site
 * team corrects it on the user's wp-admin screen.
 *
 * The date itself is never shown to other members, never searchable, never
 * written to the audit log, and is included in the personal-data export and
 * removed by the eraser. Only the age is ever displayed, and only if the
 * member's Age switch is on.
 *
 * Someone signed in who gives a date under 18 is locked out of the member
 * area (META_BLOCKED) until the site team reviews it, so a different year
 * can't simply be tried next. Sign-up just refuses under-18 dates.
 */
class CMP_Birth_Date {

	const META         = 'cmp_birth_date';       // Y-m-d.
	const META_BLOCKED = 'cmp_age_blocked_at';   // UTC timestamp.
	const MIN_AGE      = 18;
	const MAX_AGE      = 120;
	const FIELD        = 'cmp_dob';              // POST prefix: cmp_dob[m|d|y].

	public static function init() {
		// Sign-up form of the events plugin ([cec_register]).
		add_action( 'cec_register_form_fields', array( __CLASS__, 'render_signup_fields' ) );
		add_filter( 'cec_register_validate', array( __CLASS__, 'validate_signup' ) );
		add_filter( 'cec_register_error_messages', array( __CLASS__, 'signup_messages' ) );
		add_action( 'cec_user_registered', array( __CLASS__, 'store_signup' ) );
		// After sign-up, go to the member area (next: confirm email, then the
		// profile), not the events plugin's default Submit an Event page.
		add_filter( 'cec_register_redirect', array( __CLASS__, 'signup_redirect' ) );

		// Site-team correction on the user's wp-admin screen.
		add_action( 'show_user_profile', array( __CLASS__, 'admin_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'admin_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'admin_save' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'admin_save' ) );
	}

	/* ------------------------------------------------------------------
	 * Reading
	 * ---------------------------------------------------------------- */

	public static function get( $user_id ) {
		$dob = (string) get_user_meta( (int) $user_id, self::META, true );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ? $dob : '';
	}

	public static function is_blocked( $user_id ) {
		return (bool) get_user_meta( (int) $user_id, self::META_BLOCKED, true );
	}

	/**
	 * Whole years between a Y-m-d date and today in the site's timezone.
	 */
	public static function age_from( $dob, $today = null ) {
		$today = null === $today ? wp_date( 'Y-m-d' ) : $today;
		list( $by, $bm, $bd ) = array_map( 'intval', explode( '-', $dob ) );
		list( $ty, $tm, $td ) = array_map( 'intval', explode( '-', $today ) );
		$age = $ty - $by;
		if ( $tm < $bm || ( $tm === $bm && $td < $bd ) ) {
			$age--;
		}
		return $age;
	}

	/** The member's current age, or 0 if no date is on file. */
	public static function age( $user_id ) {
		$dob = self::get( $user_id );
		return $dob ? self::age_from( $dob ) : 0;
	}

	/** May use the member area: a date on file, 18+, not locked. */
	public static function qualifies( $user_id ) {
		return ! self::is_blocked( $user_id ) && self::age( $user_id ) >= self::MIN_AGE;
	}

	/* ------------------------------------------------------------------
	 * Input
	 * ---------------------------------------------------------------- */

	/**
	 * Reads and checks cmp_dob[m|d|y] from a request array.
	 *
	 * @return string|WP_Error Y-m-d, or WP_Error code dob_missing / dob_invalid / dob_under_18.
	 */
	public static function from_request( $source ) {
		$in = isset( $source[ self::FIELD ] ) && is_array( $source[ self::FIELD ] ) ? wp_unslash( $source[ self::FIELD ] ) : array();
		$m  = isset( $in['m'] ) ? absint( $in['m'] ) : 0;
		$d  = isset( $in['d'] ) ? absint( $in['d'] ) : 0;
		$y  = isset( $in['y'] ) ? absint( $in['y'] ) : 0;
		if ( ! $m || ! $d || ! $y ) {
			return new WP_Error( 'dob_missing', __( 'Please enter your date of birth.', 'cmp' ) );
		}
		if ( ! checkdate( $m, $d, $y ) ) {
			return new WP_Error( 'dob_invalid', __( 'That date of birth isn\'t a real date. Please check it.', 'cmp' ) );
		}
		$dob = sprintf( '%04d-%02d-%02d', $y, $m, $d );
		$age = self::age_from( $dob );
		if ( $dob > wp_date( 'Y-m-d' ) || $age > self::MAX_AGE ) {
			return new WP_Error( 'dob_invalid', __( 'That date of birth isn\'t a real date. Please check it.', 'cmp' ) );
		}
		if ( $age < self::MIN_AGE ) {
			return new WP_Error( 'dob_under_18', __( 'You must be 18 or older to join.', 'cmp' ) );
		}
		return $dob;
	}

	/**
	 * Month / Day / Year selects: quicker on a phone than a calendar picker
	 * scrolled back decades. $current is a Y-m-d to preselect (admin only).
	 */
	public static function fields_html( $id_prefix, $current = '', $described_by = '' ) {
		list( $cy, $cm, $cd ) = $current ? array_map( 'intval', explode( '-', $current ) ) : array( 0, 0, 0 );
		$this_year = (int) wp_date( 'Y' );
		$desc      = $described_by ? ' aria-describedby="' . esc_attr( $described_by ) . '"' : '';
		$html      = '<span class="cmp-dob" role="group" aria-label="' . esc_attr__( 'Date of birth', 'cmp' ) . '">';
		$html     .= '<select id="' . esc_attr( $id_prefix ) . '_m" name="' . esc_attr( self::FIELD ) . '[m]" autocomplete="bday-month" aria-label="' . esc_attr__( 'Month', 'cmp' ) . '" required' . $desc . '><option value="">' . esc_html__( 'Month', 'cmp' ) . '</option>';
		for ( $i = 1; $i <= 12; $i++ ) {
			$html .= '<option value="' . $i . '"' . selected( $cm, $i, false ) . '>' . esc_html( date_i18n( 'F', mktime( 0, 0, 0, $i, 1, 2000 ) ) ) . '</option>';
		}
		$html .= '</select><select id="' . esc_attr( $id_prefix ) . '_d" name="' . esc_attr( self::FIELD ) . '[d]" autocomplete="bday-day" aria-label="' . esc_attr__( 'Day', 'cmp' ) . '" required' . $desc . '><option value="">' . esc_html__( 'Day', 'cmp' ) . '</option>';
		for ( $i = 1; $i <= 31; $i++ ) {
			$html .= '<option value="' . $i . '"' . selected( $cd, $i, false ) . '>' . $i . '</option>';
		}
		$html .= '</select><select id="' . esc_attr( $id_prefix ) . '_y" name="' . esc_attr( self::FIELD ) . '[y]" autocomplete="bday-year" aria-label="' . esc_attr__( 'Year', 'cmp' ) . '" required' . $desc . '><option value="">' . esc_html__( 'Year', 'cmp' ) . '</option>';
		for ( $y = $this_year; $y >= $this_year - self::MAX_AGE; $y-- ) {
			$html .= '<option value="' . $y . '"' . selected( $cy, $y, false ) . '>' . $y . '</option>';
		}
		return $html . '</select></span>';
	}

	/* ------------------------------------------------------------------
	 * Writing
	 * ---------------------------------------------------------------- */

	/**
	 * Stores the date. The audit log records that it was set and by which
	 * route, never the date itself. Any manually entered profile age from
	 * before 0.4.0 is cleared: age now comes from this date only.
	 */
	public static function store( $user_id, $dob, $route ) {
		update_user_meta( $user_id, self::META, $dob );
		CMP_Profiles::clear_manual_age( $user_id );
		CMP_Audit::log( 'birth_date_recorded', 'user', $user_id, null, array( 'route' => $route ) );
	}

	/** Under-18 date from a signed-in account: lock, record, store nothing. */
	public static function block( $user_id ) {
		update_user_meta( $user_id, self::META_BLOCKED, gmdate( 'Y-m-d H:i:s' ) );
		CMP_Audit::log( 'member_area_age_blocked', 'user', $user_id, null, null, 'Gave a date of birth under 18' );
	}

	/* ------------------------------------------------------------------
	 * Sign-up ([cec_register] in the events plugin)
	 * ---------------------------------------------------------------- */

	public static function render_signup_fields() {
		// The sign-up page isn't the member area, so member.css isn't loaded.
		echo '<style>.cec-auth-form .cmp-dob{display:grid;grid-template-columns:1.4fr 1fr 1.2fr;gap:8px}.cec-auth-form .cmp-dob select{width:100%;min-height:44px}</style>';
		// A real <label> (for the Month list) so it takes the form's own label style.
		echo '<div class="cec-field cmp-dob-field"><label for="cmp_signup_dob_m" id="cmp_signup_dob_label">' . esc_html__( 'Date of birth', 'cmp' ) . '</label>'
			. self::fields_html( 'cmp_signup_dob', '', 'cmp_signup_dob_help' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			. '<p class="description" id="cmp_signup_dob_help">' . esc_html__( 'Required. Only used to confirm you\'re 18+ and to work out your age. Never shown to anyone, and you choose whether your age appears on your profile.', 'cmp' ) . '</p></div>';
	}

	public static function validate_signup( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$dob = self::from_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the events plugin checks its nonce first.
		return is_wp_error( $dob ) ? $dob : $result;
	}

	public static function signup_messages( $messages ) {
		return $messages + array(
			'dob_missing'  => __( 'Please enter your date of birth.', 'cmp' ),
			'dob_invalid'  => __( 'That date of birth isn\'t a real date. Please check it.', 'cmp' ),
			'dob_under_18' => __( 'You must be 18 or older to join.', 'cmp' ),
		);
	}

	public static function signup_redirect( $redirect ) {
		return (int) CMP_Settings::get( 'member_page_id' ) ? CMP_Settings::member_page_url() : $redirect;
	}

	/**
	 * New account: store the date, record the 18+ confirmation it implies,
	 * and (owner decision: a filled-in field starts shown) show the display
	 * name and age to members from the start.
	 */
	public static function store_signup( $user_id ) {
		$dob = self::from_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validated in validate_signup() on the same request.
		if ( is_wp_error( $dob ) ) {
			return;
		}
		self::store( $user_id, $dob, 'signup' );
		CMP_Access::record_attestation( $user_id );
		CMP_Profiles::set_new_member_defaults( $user_id );
	}

	/* ------------------------------------------------------------------
	 * Site-team correction (wp-admin user screen)
	 * ---------------------------------------------------------------- */

	public static function admin_field( $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		$dob     = self::get( $user->ID );
		$blocked = self::is_blocked( $user->ID );
		?>
		<h2><?php esc_html_e( 'Member area: date of birth', 'cmp' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Date of birth', 'cmp' ); ?></th>
				<td>
					<?php wp_nonce_field( 'cmp_admin_dob_' . $user->ID, '_cmp_dob_nonce' ); ?>
					<?php echo self::fields_html( 'cmp_admin_dob', $dob ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
					<p class="description">
						<?php
						echo esc_html(
							$dob
								? sprintf( /* translators: %d: age in years */ __( 'Age %d. Members can\'t change this themselves; correct it here only when asked. Changes are recorded in the audit log (not the date).', 'cmp' ), self::age_from( $dob ) )
								: __( 'Not entered yet. The member is asked for it the next time they open the member area.', 'cmp' )
						);
						?>
					</p>
					<?php if ( $blocked ) : ?>
						<p><label><input type="checkbox" name="cmp_unblock" value="1" /> <?php esc_html_e( 'Unlock the member area. This account gave a date under 18; unlock only after checking.', 'cmp' ); ?></label></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function admin_save( $user_id ) {
		if ( ! current_user_can( 'edit_users' ) || ! isset( $_POST['_cmp_dob_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cmp_dob_nonce'] ) ), 'cmp_admin_dob_' . $user_id ) ) {
			return;
		}
		if ( ! empty( $_POST['cmp_unblock'] ) && self::is_blocked( $user_id ) ) {
			delete_user_meta( $user_id, self::META_BLOCKED );
			CMP_Audit::log( 'member_area_age_unblocked', 'user', $user_id, null, null, 'Unlocked by the site team' );
		}
		$in = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? array_filter( wp_unslash( $_POST[ self::FIELD ] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read through from_request().
		if ( ! $in ) {
			return; // Left blank: nothing to change.
		}
		$dob = self::from_request( $_POST );
		if ( is_wp_error( $dob ) ) {
			if ( 'dob_under_18' === $dob->get_error_code() ) {
				// The site team entered an under-18 date: lock rather than store.
				self::block( $user_id );
			}
			return;
		}
		if ( $dob !== self::get( $user_id ) ) {
			self::store( $user_id, $dob, 'site_team' );
		}
	}

	/* ------------------------------------------------------------------
	 * Privacy
	 * ---------------------------------------------------------------- */

	public static function export_rows( $user_id ) {
		$rows = array();
		$dob  = self::get( $user_id );
		if ( $dob ) {
			$rows[] = array( 'name' => __( 'Date of birth', 'cmp' ), 'value' => $dob );
		}
		$blocked = get_user_meta( $user_id, self::META_BLOCKED, true );
		if ( $blocked ) {
			$rows[] = array( 'name' => __( 'Member area locked (gave a date under 18) at (UTC)', 'cmp' ), 'value' => (string) $blocked );
		}
		return $rows;
	}

	public static function erase( $user_id ) {
		$removed = delete_user_meta( $user_id, self::META );
		// The lock is kept on purpose: erasing data must not reopen the
		// member area to an account that said it was under 18.
		return $removed;
	}
}
