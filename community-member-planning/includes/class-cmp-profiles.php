<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Member profiles (brief §2 "Member profile"): the values a member enters
 * for each field in CMP_Profile_Fields, with a visibility per field
 * (Private / Connections / Members), the member area's Profile tab, and the
 * one rule for who may see what (can_view()).
 *
 * Since 0.4.0 (owner decision 2026-10-04) the Profile tab shows one "Show to
 * members" switch per filled-in field instead of a three-way menu: empty
 * fields have no switch, and a newly filled field starts shown (Members).
 * Switched off is Private; a stored Connections choice is kept until the
 * member switches that field on. Age is calculated from the date of birth
 * (CMP_Birth_Date), so it only has a switch.
 *
 * Search choices ("Include in member search", directory opt-in) are stored
 * per field but not offered yet: they only mean something once the member
 * directory exists (increment 2.4), and until then every value stays out of
 * any search.
 */
class CMP_Profiles {

	const TAB          = 'profile';
	const NONCE        = 'cmp_profile_save';
	const ERRORS       = 'cmp_profile_errors_';
	const ERRORS_TTL   = 10 * MINUTE_IN_SECONDS;

	public static function init() {
		add_action( 'admin_post_cmp_profile_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_nopriv_cmp_profile_save', array( 'CMP_Member_Area', 'redirect_to_login' ) );
	}

	public static function url( $notice = '', $anchor = '' ) {
		$url = add_query_arg( 'cmp_tab', self::TAB, CMP_Settings::member_page_url() );
		$url = $notice ? add_query_arg( 'cmp_notice', $notice, $url ) : $url;
		return $anchor ? $url . '#' . $anchor : $url;
	}

	public static function visibility_labels() {
		return array(
			'private'     => __( 'Only me', 'cmp' ),
			'connections' => __( 'My connections', 'cmp' ),
			'members'     => __( 'All members', 'cmp' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Storage
	 * ---------------------------------------------------------------- */

	/**
	 * Every field's stored row for a member, with the brief's defaults
	 * (empty, Private, not searchable) for fields never saved.
	 *
	 * @return array field_key => array( value, visibility, searchable )
	 */
	public static function rows( $user_id ) {
		global $wpdb;
		$out = array();
		foreach ( CMP_Profile_Fields::fields() as $key => $f ) {
			$out[ $key ] = array( 'value' => '', 'visibility' => 'private', 'searchable' => false );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT field_key, value, visibility, searchable FROM ' . CMP_Install::table( 'profile_values' ) . ' WHERE user_id = %d', $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $rows as $r ) {
			if ( ! isset( $out[ $r->field_key ] ) ) {
				continue; // A field no longer in the dictionary.
			}
			$out[ $r->field_key ] = array(
				'value'      => null === $r->value ? '' : json_decode( $r->value, true ),
				'visibility' => in_array( $r->visibility, CMP_Profile_Fields::VISIBILITY, true ) ? $r->visibility : 'private',
				'searchable' => (bool) $r->searchable,
			);
		}
		// The display name is always shown to members (owner, 0.9.1): it is
		// how members find and recognise each other, and it already appears
		// on posts, dynamics and homework.
		if ( isset( $out['display_name'] ) ) {
			$out['display_name']['visibility'] = 'members';
		}
		return $out;
	}

	/**
	 * The value to show for a field, wherever it actually lives.
	 */
	public static function value( $user_id, $key, $rows = null ) {
		$f = CMP_Profile_Fields::field( $key );
		if ( ! $f ) {
			return '';
		}
		switch ( $f['type'] ) {
			case 'account':
				$user = get_userdata( $user_id );
				return $user ? $user->display_name : '';
			case 'system':
				$user = get_userdata( $user_id );
				return $user ? wp_date( 'F Y', strtotime( $user->user_registered . ' UTC' ) ) : '';
			case 'image':
				return CMP_Profile_Images::get( $user_id, $key ) ? true : '';
			case 'age':
				// Calculated from the date of birth; never typed in (0.4.0).
				$age = CMP_Birth_Date::age( $user_id );
				return $age ? array( 'mode' => 'exact', 'value' => $age ) : '';
		}
		$rows = null === $rows ? self::rows( $user_id ) : $rows;
		return $rows[ $key ]['value'];
	}

	/**
	 * Writes one field. $value null keeps the stored value (visibility-only
	 * change). Searchable is only ever true for a field that may be
	 * searchable and is visible to Members (brief §2).
	 */
	public static function save_field( $user_id, $key, $value, $visibility, $searchable = false ) {
		global $wpdb;
		$visibility = in_array( $visibility, CMP_Profile_Fields::VISIBILITY, true ) ? $visibility : 'private';
		$searchable = $searchable && 'members' === $visibility && CMP_Profile_Fields::can_be_searchable( $key );
		$table      = CMP_Install::table( 'profile_values' );
		$current    = $wpdb->get_row( $wpdb->prepare( "SELECT value FROM $table WHERE user_id = %d AND field_key = %s", $user_id, $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $value ) {
			$json = $current ? $current->value : null;
		} else {
			$json = ( '' === $value || array() === $value ) ? null : wp_json_encode( $value );
		}
		$wpdb->replace(
			$table,
			array(
				'user_id'    => $user_id,
				'field_key'  => $key,
				'value'      => $json,
				'visibility' => $visibility,
				'searchable' => $searchable ? 1 : 0,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/** Whether the member has ever saved anything (value or choice) for a field. */
	private static function has_row( $user_id, $key ) {
		global $wpdb;
		$table = CMP_Install::table( 'profile_values' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $table WHERE user_id = %d AND field_key = %s", $user_id, $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Drops an age typed in before 0.4.0, keeping its visibility choice: age
	 * now comes only from the date of birth.
	 */
	public static function clear_manual_age( $user_id ) {
		$rows = self::rows( $user_id );
		if ( '' !== $rows['age']['value'] && null !== $rows['age']['value'] ) {
			self::save_field( $user_id, 'age', '', $rows['age']['visibility'] );
		}
	}

	/**
	 * A brand-new account (sign-up with a date of birth): its display name,
	 * age and member-since date are filled in from the start, so like any
	 * filled-in field they start shown to members. Never applied to existing members, whose
	 * earlier choices stay as they were.
	 */
	public static function set_new_member_defaults( $user_id ) {
		foreach ( array( 'display_name', 'age', 'member_since' ) as $key ) {
			if ( ! self::has_row( $user_id, $key ) ) {
				self::save_field( $user_id, $key, null, 'members' );
			}
		}
	}

	/** Whether a field currently has something to show. */
	private static function is_filled( $key, $value ) {
		return '' !== CMP_Profile_Fields::display( $key, $value );
	}

	public static function delete_all( $user_id ) {
		global $wpdb;
		return (int) $wpdb->delete( CMP_Install::table( 'profile_values' ), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/* ------------------------------------------------------------------
	 * Who may see what
	 * ---------------------------------------------------------------- */

	/**
	 * The single rule for every profile field and photo. Site
	 * administrators get no exception: Private means the owner only.
	 */
	public static function can_view( $key, $owner_id, $viewer_id, $rows = null ) {
		$owner_id  = (int) $owner_id;
		$viewer_id = (int) $viewer_id;
		if ( ! $owner_id || ! $viewer_id ) {
			return false;
		}
		if ( $owner_id === $viewer_id ) {
			return true;
		}
		// Only full members see other members, and only of full members.
		if ( ! CMP_Access::is_member( $viewer_id ) || ! CMP_Access::is_member( $owner_id ) ) {
			return false;
		}
		$rows       = null === $rows ? self::rows( $owner_id ) : $rows;
		$visibility = isset( $rows[ $key ] ) ? $rows[ $key ]['visibility'] : 'private';
		if ( 'members' === $visibility ) {
			return true;
		}
		if ( 'connections' === $visibility ) {
			/**
			 * Whether two members are confirmed connections (increment 2.3).
			 *
			 * @param bool $connected
			 * @param int  $owner_id
			 * @param int  $viewer_id
			 */
			return (bool) apply_filters( 'cmp_are_connected', false, $owner_id, $viewer_id );
		}
		return false;
	}

	/**
	 * The fields a viewer may see, with display text, in dictionary order.
	 * Empty values are left out. $as_members previews what any member who
	 * isn't a connection would see.
	 *
	 * @return array key => array( label, text )
	 */
	public static function visible( $owner_id, $viewer_id, $as_members = false ) {
		$rows = self::rows( $owner_id );
		$out  = array();
		foreach ( CMP_Profile_Fields::fields() as $key => $f ) {
			if ( 'image' === $f['type'] ) {
				continue;
			}
			$allowed = $as_members ? 'members' === $rows[ $key ]['visibility'] : self::can_view( $key, $owner_id, $viewer_id, $rows );
			if ( ! $allowed ) {
				continue;
			}
			$text = CMP_Profile_Fields::display( $key, self::value( $owner_id, $key, $rows ) );
			if ( '' !== $text ) {
				$out[ $key ] = array( 'label' => $f['label'], 'text' => $text );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Saving the Profile tab
	 * ---------------------------------------------------------------- */

	public static function handle_save() {
		$user_id = get_current_user_id();
		if ( ! CMP_Access::is_member( $user_id ) ) {
			wp_safe_redirect( CMP_Settings::member_page_url() );
			exit;
		}
		if ( ! isset( $_POST['_cmp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cmp_nonce'] ) ), self::NONCE ) ) {
			wp_safe_redirect( self::url( 'expired' ) );
			exit;
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is cleaned by CMP_Profile_Fields::clean().
		$input   = isset( $_POST['f'] ) && is_array( $_POST['f'] ) ? wp_unslash( $_POST['f'] ) : array();
		$show    = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array();
		$present = isset( $_POST['sp'] ) && is_array( $_POST['sp'] ) ? wp_unslash( $_POST['sp'] ) : array();
		// A setup step saves only its own fields (only[]); the Profile tab saves all.
		$only    = isset( $_POST['only'] ) && is_array( $_POST['only'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['only'] ) ) : null;
		$return  = isset( $_POST['cmp_return'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['cmp_return'] ) ), '' ) : '';
		// phpcs:enable

		$rows   = self::rows( $user_id );
		$clean  = array();
		$errors = array();
		foreach ( CMP_Profile_Fields::fields() as $key => $f ) {
			if ( in_array( $f['type'], array( 'account', 'system', 'image', 'age' ), true ) || ( null !== $only && ! in_array( $key, $only, true ) ) ) {
				continue;
			}
			$value = CMP_Profile_Fields::clean( $key, isset( $input[ $key ] ) ? $input[ $key ] : '' );
			if ( is_wp_error( $value ) ) {
				$errors[ $key ] = $value->get_error_message();
			} else {
				$clean[ $key ] = $value;
			}
		}
		if ( $errors ) {
			set_transient( self::ERRORS . $user_id, array( 'errors' => $errors, 'input' => $input, 'show' => $show, 'present' => $present ), self::ERRORS_TTL );
			wp_safe_redirect( $return ? add_query_arg( 'cmp_notice', 'profile_invalid', $return ) . '#cmp-profile-errors' : self::url( 'profile_invalid', 'cmp-profile-errors' ) );
			exit;
		}
		delete_transient( self::ERRORS . $user_id );

		$changed_values = array();
		$vis_old        = array();
		$vis_new        = array();
		foreach ( CMP_Profile_Fields::fields() as $key => $f ) {
			if ( 'image' === $f['type'] || ( null !== $only && ! in_array( $key, $only, true ) ) ) {
				continue; // Photos save with the photo itself; a step saves only its fields.
			}
			$has_value = array_key_exists( $key, $clean );
			$current   = $rows[ $key ]['visibility'];
			$filled    = self::is_filled( $key, $has_value ? $clean[ $key ] : self::value( $user_id, $key, $rows ) );
			if ( ! $filled || empty( $present[ $key ] ) ) {
				// Nothing to share, or no switch was shown: leave the choice alone.
				$v = $current;
			} elseif ( ! empty( $show[ $key ] ) ) {
				$v = 'members';
			} else {
				// Switched off. A Connections choice made before 0.4.0 stays
				// Connections rather than silently becoming Only me.
				$v = 'connections' === $current ? 'connections' : 'private';
			}
			if ( $has_value && $clean[ $key ] !== $rows[ $key ]['value'] && ! ( '' === $clean[ $key ] && ( '' === $rows[ $key ]['value'] || null === $rows[ $key ]['value'] ) ) ) {
				$changed_values[] = $key;
			}
			if ( $v !== $rows[ $key ]['visibility'] ) {
				$vis_old[ $key ] = $rows[ $key ]['visibility'];
				$vis_new[ $key ] = $v;
			}
			if ( in_array( $key, $changed_values, true ) || isset( $vis_new[ $key ] ) ) {
				self::save_field( $user_id, $key, $has_value ? $clean[ $key ] : null, $v, $rows[ $key ]['searchable'] );
			}
		}
		// Which fields changed is audited, not what they now say: the audit
		// log must not become a second copy of members' personal details.
		if ( $changed_values ) {
			CMP_Audit::log( 'profile_updated', 'user', $user_id, null, array( 'fields' => $changed_values ) );
		}
		if ( $vis_new ) {
			CMP_Audit::log( 'profile_visibility_changed', 'user', $user_id, $vis_old, $vis_new );
		}
		/**
		 * After a profile save (the setup steps advance through this).
		 *
		 * @param int        $user_id
		 * @param array|null $only The fields this save covered (null = all).
		 */
		do_action( 'cmp_profile_saved', $user_id, $only );
		wp_safe_redirect( $return ? $return : self::url( 'profile_saved' ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	public static function notices() {
		return array(
			'profile_saved'   => array( 'success', __( 'Your profile is saved.', 'cmp' ) ),
			'profile_invalid' => array( 'error', __( 'Some answers need fixing. Nothing was saved yet; your entries are kept below.', 'cmp' ) ),
		);
	}

	/**
	 * The "Show to members" switch. $hidden: the field is empty, so there is
	 * nothing to share yet; member.js reveals the switch (already on) once
	 * something is filled in. Without JavaScript the hidden switch still
	 * posts "on", so a newly filled field starts shown either way.
	 */
	private static function show_switch( $key, $on, $label, $hidden ) {
		$id = 'cmp_s_' . $key;
		$sensitive = CMP_Profile_Fields::is_sensitive( $key ) ? ' data-cmp-sensitive' : '';
		return '<span class="cmp-show" data-cmp-show' . $sensitive . ( $hidden ? ' hidden' : '' ) . '>'
			. '<input type="hidden" name="sp[' . esc_attr( $key ) . ']" value="1" />'
			. '<input type="checkbox" class="cmp-switch-input" id="' . esc_attr( $id ) . '" name="s[' . esc_attr( $key ) . ']" value="1"' . checked( $on, true, false ) . ' />'
			. '<label for="' . esc_attr( $id ) . '" class="cmp-switch-label"><span class="cmp-switch" aria-hidden="true"></span>'
			. '<span class="cmp-show-state" data-on="' . esc_attr__( 'Shown', 'cmp' ) . '" data-off="' . esc_attr__( 'Hidden', 'cmp' ) . '"></span>'
			. '<span class="screen-reader-text">' . esc_html( sprintf( /* translators: %s: field label */ __( 'Show %s to members', 'cmp' ), $label ) ) . '</span></label>'
			. '</span>';
	}

	private static function error_html( $key, $errors ) {
		return isset( $errors[ $key ] ) ? '<span class="cmp-field-error" id="cmp_err_' . esc_attr( $key ) . '">' . esc_html( $errors[ $key ] ) . '</span>' : '';
	}

	private static function invalid_attrs( $key, $errors ) {
		return isset( $errors[ $key ] ) ? ' aria-invalid="true" aria-describedby="cmp_err_' . esc_attr( $key ) . '"' : '';
	}

	/**
	 * One field's input(s). $value is the stored value or, after a failed
	 * save, what the member typed.
	 */
	private static function input_html( $key, $f, $value, $errors ) {
		$name = 'f[' . $key . ']';
		$id   = 'cmp_f_' . $key;
		$bad  = self::invalid_attrs( $key, $errors );
		switch ( $f['type'] ) {
			case 'text':
				return '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( is_string( $value ) ? $value : '' ) . '" maxlength="' . (int) $f['max'] . '"' . $bad . ' />';
			case 'textarea':
				return '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="5" maxlength="' . (int) $f['max'] . '" data-cmp-count="' . (int) $f['max'] . '"' . $bad . '>' . esc_textarea( is_string( $value ) ? $value : '' ) . '</textarea>'
					. '<span class="cmp-muted" data-cmp-counter aria-live="polite"></span>';
			case 'location':
				$value = is_array( $value ) ? $value : array();
				$html  = '<span class="cmp-grid cmp-grid-3">';
				foreach ( array( 'city' => __( 'City', 'cmp' ), 'region' => __( 'State / region', 'cmp' ), 'country' => __( 'Country', 'cmp' ) ) as $part => $label ) {
					$auto  = array( 'city' => 'address-level2', 'region' => 'address-level1', 'country' => 'country-name' )[ $part ];
					$html .= '<span class="cmp-subfield"><label for="' . esc_attr( $id . '_' . $part ) . '">' . esc_html( $label ) . '</label><input type="text" id="' . esc_attr( $id . '_' . $part ) . '" name="' . esc_attr( $name . '[' . $part . ']' ) . '" value="' . esc_attr( isset( $value[ $part ] ) ? (string) $value[ $part ] : '' ) . '" maxlength="' . (int) $f['max'] . '" autocomplete="' . esc_attr( $auto ) . '"' . $bad . ' /></span>';
				}
				return $html . '</span>';
			case 'single':
				$options = CMP_Profile_Fields::options( $f['list'] );
				$all     = CMP_Profile_Fields::options( $f['list'], true );
				$value   = is_string( $value ) ? $value : '';
				if ( $value && ! isset( $options[ $value ] ) && isset( $all[ $value ] ) ) {
					$options[ $value ] = $all[ $value ]; // A retired option the member already has.
				}
				$html = '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $bad . '>';
				if ( ! isset( $options['not_listed'] ) ) {
					// A blank first choice (0.10.0): without it, saving the
					// Profile tab quietly set the field to its first option.
					$html .= '<option value=""' . selected( $value, '', false ) . '>' . esc_html__( 'Not set', 'cmp' ) . '</option>';
				}
				foreach ( $options as $k => $label ) {
					$html .= '<option value="' . esc_attr( 'not_listed' === $k ? '' : $k ) . '"' . selected( $value ? $value : '', 'not_listed' === $k ? '' : $k, false ) . '>' . esc_html( $label ) . '</option>';
				}
				return $html . '</select>';
			case 'multi':
				$chosen  = is_array( $value ) && isset( $value['keys'] ) ? array_map( 'strval', (array) $value['keys'] ) : array();
				$options = CMP_Profile_Fields::options( $f['list'] );
				$all     = CMP_Profile_Fields::options( $f['list'], true );
				foreach ( $chosen as $k ) {
					if ( ! isset( $options[ $k ] ) && isset( $all[ $k ] ) ) {
						$options[ $k ] = $all[ $k ];
					}
				}
				$html = '';
				if ( ! $options ) {
					$html .= '<p class="cmp-muted">' . esc_html__( 'No choices yet: the site team is still preparing this list.', 'cmp' ) . '</p>';
				} else {
					$html .= '<span class="cmp-choices" data-cmp-limit="' . (int) $f['limit'] . '">';
					foreach ( $options as $k => $label ) {
						$cid   = $id . '_' . $k;
						$html .= '<span class="cmp-choice"><input type="checkbox" id="' . esc_attr( $cid ) . '" name="' . esc_attr( $name . '[keys][]' ) . '" value="' . esc_attr( $k ) . '"' . checked( in_array( (string) $k, $chosen, true ), true, false ) . ' /><label for="' . esc_attr( $cid ) . '">' . esc_html( $label ) . '</label></span>';
					}
					$html .= '</span>';
				}
				if ( ! empty( $f['other'] ) ) {
					$other = is_array( $value ) && isset( $value['other'] ) ? (string) $value['other'] : '';
					$html .= '<span class="cmp-subfield"><label for="' . esc_attr( $id . '_other' ) . '">' . esc_html__( 'Other (in your own words)', 'cmp' ) . '</label><input type="text" id="' . esc_attr( $id . '_other' ) . '" name="' . esc_attr( $name . '[other]' ) . '" value="' . esc_attr( $other ) . '" maxlength="' . (int) $f['other'] . '" /></span>';
				}
				return $html;
			case 'age':
				$text = CMP_Profile_Fields::display( 'age', $value );
				return '<span class="cmp-readonly cmp-age-value">' . esc_html( '' !== $text ? $text : __( 'Not available yet', 'cmp' ) ) . '</span>';
			case 'height':
				$html = '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $bad . '><option value="">' . esc_html__( 'Not set', 'cmp' ) . '</option>';
				for ( $i = 48; $i <= 96; $i++ ) {
					$html .= '<option value="' . $i . '"' . selected( (int) $value, $i, false ) . '>' . esc_html( CMP_Profile_Fields::format_height( $i ) ) . '</option>';
				}
				return $html . '</select>';
			case 'weight':
				return '<span class="cmp-unit"><input type="number" inputmode="numeric" min="70" max="700" step="1" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '"' . $bad . ' /> ' . esc_html__( 'lb', 'cmp' ) . '</span>';
			case 'month':
				return '<input type="month" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '" max="' . esc_attr( wp_date( 'Y-m' ) ) . '" placeholder="YYYY-MM"' . $bad . ' />';
			case 'rated':
				$options = CMP_Profile_Fields::options( $f['list'] );
				$chosen  = array();
				foreach ( is_array( $value ) ? $value : array() as $k => $row ) {
					// Stored rows are {k,lvl,dir}; typed-back input is keyed by option.
					if ( is_array( $row ) && isset( $row['k'] ) ) {
						$chosen[ $row['k'] ] = $row;
					} elseif ( is_array( $row ) ) {
						$chosen[ (string) $k ] = $row;
					}
				}
				$all = CMP_Profile_Fields::options( $f['list'], true );
				foreach ( array_keys( $chosen ) as $k ) {
					if ( ! isset( $options[ $k ] ) && isset( $all[ $k ] ) ) {
						$options[ $k ] = $all[ $k ];
					}
				}
				if ( ! $options ) {
					return '<p class="cmp-muted">' . esc_html__( 'No choices yet: the site team is still preparing this list.', 'cmp' ) . '</p>';
				}
				// Kink picker (0.10.0): only the member's picks are listed; the
				// rest are found by search or category (member.js). Without
				// JavaScript, "Browse all kinks" lists every kink by category.
				$groups   = CMP_Profile_Fields::kink_groups();
				$group_of = CMP_Profile_Fields::option_groups( $f['list'] );
				$picked   = array();
				foreach ( $chosen as $k => $row ) {
					if ( isset( $options[ $k ] ) && isset( $row['lvl'] ) && in_array( $row['lvl'], CMP_Profile_Fields::KINK_LEVELS, true ) ) {
						$picked[ $k ] = $row;
					}
				}
				$row_html = function ( $k, $label, $row ) use ( $name, $id, $group_of ) {
					$lvl  = $row && isset( $row['lvl'] ) ? (string) $row['lvl'] : '';
					$dir  = $row && isset( $row['dir'] ) ? (string) $row['dir'] : '';
					$base = $name . '[' . $k . ']';
					$rid  = $id . '_' . $k;
					$h    = '<li class="cmp-kp-row" data-k="' . esc_attr( $k ) . '" data-label="' . esc_attr( $label ) . '" data-group="' . esc_attr( isset( $group_of[ $k ] ) ? $group_of[ $k ] : 'other' ) . '">';
					$h   .= '<div class="cmp-kp-top"><span class="cmp-kp-name" id="' . esc_attr( $rid ) . '_n">' . esc_html( $label ) . '</span>';
					$h   .= '<label class="cmp-kp-rm"><input type="radio" class="cmp-kp-input" name="' . esc_attr( $base . '[lvl]' ) . '" value=""' . checked( $lvl, '', false ) . ' data-cmp-kp-rm /><span aria-hidden="true">×</span><span class="screen-reader-text">' . esc_html( sprintf( /* translators: %s: kink */ __( 'Remove %s', 'cmp' ), $label ) ) . '</span></label></div>';
					$h   .= '<div class="cmp-kp-segs"><span class="cmp-kp-seg" role="radiogroup" aria-labelledby="' . esc_attr( $rid ) . '_n">';
					foreach ( CMP_Profile_Fields::kink_level_labels() as $v => $l ) {
						$h .= '<label><input type="radio" class="cmp-kp-input" name="' . esc_attr( $base . '[lvl]' ) . '" value="' . esc_attr( $v ) . '"' . checked( $lvl, $v, false ) . ' /><span>' . esc_html( $l ) . '</span></label>';
					}
					$h .= '</span><span class="cmp-kp-seg cmp-kp-dir" role="radiogroup" aria-label="' . esc_attr( sprintf( /* translators: %s: kink */ __( 'Giving or receiving: %s', 'cmp' ), $label ) ) . '">';
					$h .= '<input type="radio" class="cmp-kp-input cmp-kp-none" name="' . esc_attr( $base . '[dir]' ) . '" value=""' . checked( $dir, '', false ) . ' tabindex="-1" aria-hidden="true" />';
					foreach ( CMP_Profile_Fields::kink_dir_labels() as $v => $l ) {
						$h .= '<label><input type="radio" class="cmp-kp-input" name="' . esc_attr( $base . '[dir]' ) . '" value="' . esc_attr( $v ) . '"' . checked( $dir, $v, false ) . ' data-cmp-kp-dir /><span>' . esc_html( $l ) . '</span></label>';
					}
					return $h . '</span></div></li>';
				};
				$html  = '<div class="cmp-kp" data-cmp-kp data-limit="' . (int) $f['limit'] . '" data-label-all="' . esc_attr__( 'All', 'cmp' ) . '" data-label-none="' . esc_attr__( 'No match. You can suggest it to the site team.', 'cmp' ) . '" data-label-more="' . esc_attr__( 'Showing %1$d of %2$d. Type or pick a category to narrow it down.', 'cmp' ) . '" data-label-full="' . esc_attr__( 'You\'ve picked the most allowed. Remove one to add another.', 'cmp' ) . '">';
				$html .= '<p class="cmp-kp-count" data-cmp-kp-count aria-live="polite">' . esc_html( sprintf( /* translators: 1: picked, 2: maximum */ __( '%1$d of %2$d picked', 'cmp' ), count( $picked ), (int) $f['limit'] ) ) . '</p>';
				$html .= '<ul class="cmp-kp-mine" data-cmp-kp-mine>';
				foreach ( $picked as $k => $row ) {
					$html .= $row_html( $k, $options[ $k ], $row );
				}
				$html .= '</ul><p class="cmp-muted cmp-kp-empty" data-cmp-kp-empty' . ( $picked ? ' hidden' : '' ) . '>' . esc_html__( 'Nothing picked yet.', 'cmp' ) . '</p>';
				$html .= '<div class="cmp-kp-find" data-cmp-kp-find hidden><p class="cmp-field"><label for="' . esc_attr( $id ) . '_q">' . esc_html__( 'Add kinks', 'cmp' ) . '</label><input type="search" id="' . esc_attr( $id ) . '_q" placeholder="' . esc_attr__( 'Search, e.g. rope, pup, boots', 'cmp' ) . '" autocomplete="off" data-cmp-kp-q /></p>';
				$html .= '<div class="cmp-kp-cats" role="group" aria-label="' . esc_attr__( 'Categories', 'cmp' ) . '" data-cmp-kp-cats>';
				foreach ( array( '' => __( 'All', 'cmp' ) ) + $groups as $g => $gl ) {
					$html .= '<button type="button" data-cmp-kp-cat="' . esc_attr( $g ) . '" aria-pressed="' . ( '' === $g ? 'true' : 'false' ) . '">' . esc_html( $gl ) . '</button>';
				}
				$html .= '</div><div class="cmp-kp-results" data-cmp-kp-results></div><p class="cmp-muted cmp-kp-more" data-cmp-kp-more aria-live="polite"></p></div>';
				$html .= '<details class="cmp-kp-browse" data-cmp-kp-browse><summary>' . esc_html__( 'Browse all kinks', 'cmp' ) . '</summary>';
				foreach ( $groups as $g => $gl ) {
					$items = '';
					foreach ( $options as $k => $label ) {
						if ( ! isset( $picked[ $k ] ) && ( isset( $group_of[ $k ] ) ? $group_of[ $k ] : 'other' ) === $g ) {
							$items .= $row_html( $k, $label, null );
						}
					}
					$html .= '<div class="cmp-kp-group" data-cmp-kp-group="' . esc_attr( $g ) . '"><h4>' . esc_html( $gl ) . '</h4><ul>' . $items . '</ul></div>';
				}
				return $html . '</details></div>';
		}
		return '';
	}

	private static function help( $key, $f ) {
		$help = array(
			'display_name' => '',
			'bio'          => 'bio' === $key ? sprintf( /* translators: %d: maximum characters */ __( 'Up to %d characters.', 'cmp' ), (int) $f['max'] ) : '',
			'pronouns'     => __( 'For example: she/her, he/him, they/them.', 'cmp' ),
			'location'     => __( 'City, region and country only. Never a street address.', 'cmp' ),
			'interests'    => sprintf( /* translators: %d: maximum choices */ __( 'Choose up to %d.', 'cmp' ), 20 ),
			'roles'        => sprintf( /* translators: %d: maximum choices */ __( 'Choose up to %d.', 'cmp' ), 10 ),
			'identity'     => sprintf( /* translators: %d: maximum choices */ __( 'Choose up to %d, and/or describe it yourself.', 'cmp' ), 10 ),
			'availability' => __( 'Set by you; never worked out from when you sign in.', 'cmp' ),
			'looking_for'  => sprintf( /* translators: %d: maximum choices */ __( 'Choose up to %d.', 'cmp' ), 10 ),
			'age'          => __( 'Worked out from your date of birth, and updates on your birthday. Your birth date itself is never shown.', 'cmp' ),
			'member_since' => __( 'Set automatically from when you joined.', 'cmp' ),
		);
		if ( isset( $help[ $key ] ) ) {
			return $help[ $key ];
		}
		if ( ! empty( $f['help'] ) ) {
			return $f['help'];
		}
		if ( 'multi' === $f['type'] || 'rated' === $f['type'] ) {
			/* translators: %d: maximum choices */
			return sprintf( __( 'Choose up to %d.', 'cmp' ), (int) $f['limit'] );
		}
		return '';
	}

	/**
	 * One field's row: label, help, input and its Show switch. Shared by the
	 * Profile tab and the setup steps (CMP_Onboarding).
	 *
	 * @param array      $input   What the member typed, after a failed save.
	 * @param array|null $show_in The switches as submitted, after a failed save.
	 */
	public static function row_html( $user_id, $key, $rows, $input = array(), $show_in = null, $errors = array() ) {
		$f      = CMP_Profile_Fields::field( $key );
		$value  = array_key_exists( $key, $input ) ? $input[ $key ] : self::value( $user_id, $key, $rows );
		$filled = self::is_filled( $key, $value );
		if ( null !== $show_in ) {
			$on = ! empty( $show_in[ $key ] );
		} elseif ( ! $filled ) {
			$on = ! CMP_Profile_Fields::is_sensitive( $key ); // What a newly filled field starts as.
		} else {
			$on = 'members' === $rows[ $key ]['visibility'];
		}
		$help   = self::help( $key, $f );
		$is_set = in_array( $f['type'], array( 'location', 'multi', 'age', 'rated' ), true );
		$label  = esc_html( $f['label'] );
		$html   = '<div class="cmp-profile-row' . ( isset( $errors[ $key ] ) ? ' has-error' : '' ) . '" id="cmp-row-' . esc_attr( $key ) . '">';
		if ( $is_set ) {
			$html .= '<fieldset class="cmp-profile-input"><legend>' . $label . '</legend>';
		} else {
			$html .= '<div class="cmp-profile-input">';
			if ( in_array( $f['type'], array( 'account', 'system' ), true ) ) {
				$html .= '<span class="cmp-label">' . $label . '</span>';
			} else {
				$html .= '<label for="cmp_f_' . esc_attr( $key ) . '">' . $label . '</label>';
			}
		}
		if ( $help ) {
			$html .= '<span class="cmp-muted">' . esc_html( $help ) . '</span>';
		}
		$html .= self::error_html( $key, $errors );
		if ( 'account' === $f['type'] ) {
			$html .= '<span class="cmp-readonly">' . esc_html( (string) $value ) . ' <a href="' . esc_url( CMP_Account::url() . '#cmp-details' ) . '">' . esc_html__( 'Change on the Account tab', 'cmp' ) . '</a></span>';
		} elseif ( 'system' === $f['type'] ) {
			$html .= '<span class="cmp-readonly">' . esc_html( (string) $value ) . '</span>';
		} else {
			$html .= self::input_html( $key, $f, $value, $errors );
		}
		$html .= $is_set ? '</fieldset>' : '</div>';
		if ( 'display_name' === $key ) {
			$html .= '<span class="cmp-show cmp-show-fixed">' . esc_html__( 'Always shown', 'cmp' ) . '</span>';
		} else {
			$html .= self::show_switch( $key, $on, $f['label'], ! $filled );
		}
		return $html . '</div>';
	}

	/** Errors and typed answers kept from a failed save (Profile tab or setup step). */
	public static function saved_errors( $user_id ) {
		$saved = get_transient( self::ERRORS . $user_id );
		return array(
			'errors'  => $saved && ! empty( $saved['errors'] ) ? $saved['errors'] : array(),
			'input'   => $saved && isset( $saved['input'] ) ? (array) $saved['input'] : array(),
			'show_in' => $saved && isset( $saved['show'] ) ? (array) $saved['show'] : null,
		);
	}

	public static function render() {
		$user_id = get_current_user_id();
		$rows    = self::rows( $user_id );
		$saved   = get_transient( self::ERRORS . $user_id );
		$errors  = $saved && ! empty( $saved['errors'] ) ? $saved['errors'] : array();
		$input   = $saved && isset( $saved['input'] ) ? (array) $saved['input'] : array();
		$show_in = $saved && isset( $saved['show'] ) ? (array) $saved['show'] : null;
		$fields  = CMP_Profile_Fields::fields();

		ob_start();
		?>
		<section class="cmp-step" aria-labelledby="cmp-profile-title">
			<h2 id="cmp-profile-title" class="cmp-title"><?php esc_html_e( 'Your profile', 'cmp' ); ?></h2>
			<p><?php esc_html_e( 'Fill in only what you want. Anything you fill in is shown to signed-in members; switch it off to keep it to yourself.', 'cmp' ); ?></p>
			<ul class="cmp-legend">
				<li><strong><?php esc_html_e( 'Shown', 'cmp' ); ?></strong> — <?php esc_html_e( 'anyone signed in to the member area. Never the public, search engines or anyone signed out.', 'cmp' ); ?></li>
				<li><strong><?php esc_html_e( 'Hidden', 'cmp' ); ?></strong> — <?php esc_html_e( 'nobody else, not even site administrators.', 'cmp' ); ?></li>
			</ul>
			<p><a class="cmp-btn cmp-btn-small cmp-btn-outline" href="<?php echo esc_url( self::member_url( $user_id ) ); ?>"><?php esc_html_e( 'View my profile as members see it', 'cmp' ); ?></a></p>
		</section>

		<?php echo CMP_Profile_Images::render_panels( $user_id, $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

		<section class="cmp-panel" id="cmp-about">
			<h3 class="cmp-panel-title" id="cmp-about-title"><?php esc_html_e( 'About you', 'cmp' ); ?></h3>
			<?php if ( $errors ) : ?>
				<div class="cmp-notice cmp-notice-error" role="alert" id="cmp-profile-errors" tabindex="-1">
					<p><?php esc_html_e( 'Please fix these and save again:', 'cmp' ); ?></p>
					<ul>
						<?php foreach ( $errors as $key => $message ) : ?>
							<li><a href="#cmp-row-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $message ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmp-form cmp-profile-form" aria-labelledby="cmp-about-title" novalidate>
				<input type="hidden" name="action" value="cmp_profile_save" />
				<input type="hidden" name="_cmp_nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>" />
				<?php
				foreach ( CMP_Profile_Fields::sections() as $section => $section_label ) {
					$keys = array_keys(
						array_filter(
							$fields,
							function ( $f ) use ( $section ) {
								return $section === $f['section'] && 'image' !== $f['type'];
							}
						)
					);
					if ( ! $keys ) {
						continue;
					}
					echo '<h4 class="cmp-section-title" id="cmp-section-' . esc_attr( $section ) . '">' . esc_html( $section_label ) . '</h4>';
					if ( 'health' === $section ) {
						echo '<p class="cmp-muted cmp-section-note">' . esc_html__( 'Optional. Health details start hidden even once filled in; switch on only what you want members to see.', 'cmp' ) . '</p>';
					}
					foreach ( $keys as $key ) {
						echo self::row_html( $user_id, $key, $rows, $input, $show_in, $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
					}
				}
				?>
				<p class="cmp-actions"><button type="submit" class="cmp-btn"><?php esc_html_e( 'Save profile', 'cmp' ); ?></button></p>
			</form>
		</section>

		<?php echo self::render_preview( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php
		return ob_get_clean();
	}

	/**
	 * What every other member sees (fields set to "All members" only), so
	 * the member can check their choices.
	 */
	private static function render_preview( $user_id ) {
		ob_start();
		?>
		<section class="cmp-panel" id="cmp-preview">
			<h3 class="cmp-panel-title" id="cmp-preview-title"><?php esc_html_e( 'What other members see', 'cmp' ); ?></h3>
			<p class="cmp-muted"><?php esc_html_e( 'Based on what is saved now: only the items switched on.', 'cmp' ); ?></p>
			<?php echo self::card_html( $user_id, $user_id, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * A profile as one viewer sees it. Used for the preview and for viewing
	 * another member's profile.
	 */
	public static function card_html( $owner_id, $viewer_id, $as_members = false ) {
		$rows = self::rows( $owner_id );
		$can  = function ( $key ) use ( $owner_id, $viewer_id, $as_members, $rows ) {
			return $as_members ? 'members' === $rows[ $key ]['visibility'] : self::can_view( $key, $owner_id, $viewer_id, $rows );
		};
		// Visible, non-empty values only.
		$val = function ( $key ) use ( $can, $owner_id, $rows ) {
			if ( ! $can( $key ) ) {
				return '';
			}
			$v = self::value( $owner_id, $key, $rows );
			return '' === CMP_Profile_Fields::display( $key, $v ) ? '' : $v;
		};
		$txt = function ( $key ) use ( $val ) {
			$v = $val( $key );
			return '' === $v ? '' : CMP_Profile_Fields::display( $key, $v );
		};
		// Option labels of a visible multi field, for chips.
		$chips = function ( $key ) use ( $val ) {
			$v = $val( $key );
			if ( '' === $v ) {
				return array();
			}
			$f      = CMP_Profile_Fields::field( $key );
			$o      = CMP_Profile_Fields::options( $f['list'], true );
			$labels = array();
			foreach ( isset( $v['keys'] ) ? (array) $v['keys'] : array() as $k ) {
				if ( isset( $o[ $k ] ) ) {
					$labels[] = $o[ $k ];
				}
			}
			if ( ! empty( $v['other'] ) && ! empty( $f['other'] ) ) {
				$labels[] = $v['other'];
			}
			return $labels;
		};
		$chip_html = function ( $labels ) {
			return $labels ? '<ul class="cmp-chips">' . implode( '', array_map( function ( $l ) {
				return '<li>' . esc_html( $l ) . '</li>';
			}, $labels ) ) . '</ul>' : '';
		};

		$name   = $txt( 'display_name' ) ? $txt( 'display_name' ) : __( 'Member', 'cmp' );
		$avatar = $can( 'avatar' ) ? CMP_Profile_Images::get( $owner_id, 'avatar', false ) : null;
		$cover  = $can( 'cover' ) ? CMP_Profile_Images::get( $owner_id, 'cover', false ) : null;
		$roles  = $chips( 'roles' );
		$gender = $chips( 'gender' );

		// FetLife-style "43 M Dom" tag next to the name.
		$tag = trim( $txt( 'age' ) . ' ' . ( $gender ? mb_substr( $gender[0], 0, 1 ) : '' ) . ' ' . ( $roles ? $roles[0] : '' ) );
		// Sniffies-style stat line: whatever is shown, in a fixed order.
		$stat = array_filter(
			array(
				$txt( 'age' ),
				$txt( 'height' ),
				$txt( 'weight' ),
				mb_strtolower( $txt( 'body_type' ) ),
				mb_strtolower( implode( ', ', $chips( 'identity' ) ) ),
				mb_strtolower( $txt( 'position' ) ),
				implode( ', ', array_slice( $roles, 0, 3 ) ),
			)
		);
		$details = array_filter(
			array(
				__( 'Gender', 'cmp' )       => implode( ', ', $gender ),
				__( 'Pronouns', 'cmp' )     => $txt( 'pronouns' ),
				__( 'Orientation', 'cmp' )  => $txt( 'identity' ),
				__( 'Roles', 'cmp' )        => implode( ', ', $roles ),
				__( 'Expression', 'cmp' )   => $txt( 'expression' ),
				__( 'Active', 'cmp' )       => $txt( 'active_level' ),
				__( 'Looking for', 'cmp' )  => $txt( 'looking_for' ),
				__( 'Member since', 'cmp' ) => $txt( 'member_since' ),
			)
		);
		$stats = array_filter(
			array(
				__( 'Age', 'cmp' )       => $txt( 'age' ),
				__( 'Height', 'cmp' )    => $txt( 'height' ),
				__( 'Weight', 'cmp' )    => $txt( 'weight' ),
				__( 'Body type', 'cmp' ) => $txt( 'body_type' ),
				__( 'Position', 'cmp' )  => $txt( 'position' ),
				__( 'Hosting', 'cmp' )   => $txt( 'hosting' ),
				__( 'Availability', 'cmp' ) => $txt( 'availability' ),
			)
		);

		$html  = '<article class="cmp-prof" aria-label="' . esc_attr( $name ) . '">';
		$html .= '<header class="cmp-prof-band">';
		$html .= '<div class="cmp-prof-photo">' . ( $cover ? '<span class="cmp-prof-cover">' . CMP_Profile_Images::img_html( $owner_id, 'cover', $cover ) . '</span>' : '' )
			. ( $avatar ? CMP_Profile_Images::img_html( $owner_id, 'avatar', $avatar ) : '<span class="cmp-avatar-placeholder" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ) . '</span>' ) . '</div>';
		$html .= '<div class="cmp-prof-head"><h3 class="cmp-prof-name">' . esc_html( $name ) . ( $tag ? ' <small>' . esc_html( $tag ) . '</small>' : '' ) . '</h3>';
		if ( $txt( 'location' ) ) {
			$html .= '<p class="cmp-prof-loc">' . esc_html( $txt( 'location' ) ) . '</p>';
		}
		if ( $stat ) {
			$html .= '<p class="cmp-prof-stat">' . esc_html( implode( ' · ', $stat ) ) . '</p>';
		}
		$html .= CMP_Chastity::profile_badge_html( $owner_id );
		if ( $details ) {
			$html .= '<dl class="cmp-prof-table">';
			foreach ( $details as $label => $text ) {
				$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $text ) . '</dd></div>';
			}
			$html .= '</dl>';
		}
		$html .= '</div></header>';

		$side = CMP_Dynamics::profile_card_html( $owner_id );
		if ( $stats ) {
			$side .= '<section class="cmp-prof-card cmp-prof-stats"><h4>' . esc_html__( 'Stats', 'cmp' ) . '</h4><dl class="cmp-prof-table">';
			foreach ( $stats as $label => $text ) {
				$side .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $text ) . '</dd></div>';
			}
			$side .= '</dl></section>';
		}

		$main = '';
		if ( $txt( 'bio' ) ) {
			$main .= '<section class="cmp-prof-card"><h4>' . esc_html__( 'About', 'cmp' ) . '</h4><blockquote class="cmp-prof-bio">' . nl2br( esc_html( $txt( 'bio' ) ) ) . '</blockquote></section>';
		}
		foreach ( array( 'looking_for', 'not_looking_for', 'interests' ) as $key ) {
			$labels = $chips( $key );
			if ( $labels ) {
				$main .= '<section class="cmp-prof-card"><h4>' . esc_html( CMP_Profile_Fields::field( $key )['label'] ) . '</h4>' . $chip_html( $labels ) . '</section>';
			}
		}
		$kinks = $val( 'kinks' );
		if ( '' !== $kinks ) {
			$o    = CMP_Profile_Fields::options( 'kinks', true );
			$lvls = CMP_Profile_Fields::kink_level_labels();
			$dirs = CMP_Profile_Fields::kink_dir_labels();
			// Grouped by how much (0.10.0), like FetLife's fetish lists.
			$by = array();
			foreach ( (array) $kinks as $row ) {
				if ( isset( $row['k'], $o[ $row['k'] ], $lvls[ $row['lvl'] ] ) ) {
					$by[ $row['lvl'] ][] = $row;
				}
			}
			$main .= '<section class="cmp-prof-card cmp-prof-kinks"><h4>' . esc_html__( 'Kinks', 'cmp' ) . '</h4>';
			foreach ( $lvls as $lv => $lv_label ) {
				if ( empty( $by[ $lv ] ) ) {
					continue;
				}
				$main .= '<h5 class="cmp-kink-lvl is-' . esc_attr( $lv ) . '">' . esc_html( $lv_label ) . '</h5><ul class="cmp-chips">';
				foreach ( $by[ $lv ] as $row ) {
					$main .= '<li>' . esc_html( $o[ $row['k'] ] ) . ( ! empty( $row['dir'] ) && isset( $dirs[ $row['dir'] ] ) ? ' <small>' . esc_html( mb_strtolower( $dirs[ $row['dir'] ] ) ) . '</small>' : '' ) . '</li>';
				}
				$main .= '</ul>';
			}
			$main .= '</section>';
		}
		if ( $txt( 'hard_limits' ) ) {
			$main .= '<section class="cmp-prof-card"><h4>' . esc_html__( 'Hard limits', 'cmp' ) . '</h4><p>' . nl2br( esc_html( $txt( 'hard_limits' ) ) ) . '</p></section>';
		}
		$health = array_merge( $chips( 'practices' ), $txt( 'last_tested' ) ? array( sprintf( /* translators: %s: month and year */ __( 'Tested %s', 'cmp' ), $txt( 'last_tested' ) ) ) : array(), $chips( 'substances' ) );
		if ( $health ) {
			$main .= '<section class="cmp-prof-card"><h4>' . esc_html__( 'Health & safer sex', 'cmp' ) . '</h4>' . $chip_html( $health ) . '</section>';
		}

		if ( '' === $main && '' === $side && ! $details ) {
			$main = '<p class="cmp-empty">' . esc_html( $as_members ? __( 'Nothing else is shared with members yet.', 'cmp' ) : __( 'This member hasn\'t shared anything else with you.', 'cmp' ) ) . '</p>';
		}
		$html .= '<div class="cmp-prof-body">' . ( $side ? '<aside class="cmp-prof-side">' . $side . '</aside>' : '' ) . '<div class="cmp-prof-main">' . $main . '</div></div>';
		return $html . '</article>';
	}

	/** A member's own profile page (shareable link). */
	public static function member_url( $user_id ) {
		return add_query_arg( 'cmp_member', (int) $user_id, CMP_Settings::member_page_url() );
	}

	/**
	 * Your own profile at its member link: exactly what other members see
	 * (only items shown to all members), with a way back to editing.
	 */
	private static function render_self( $user_id ) {
		ob_start();
		?>
		<section class="cmp-step" aria-labelledby="cmp-member-title">
			<h2 id="cmp-member-title" class="cmp-title"><?php esc_html_e( 'Member profile', 'cmp' ); ?></h2>
			<div class="cmp-notice cmp-notice-info cmp-self-view"><p><?php esc_html_e( 'This is your profile as other members see it. Items you switched off aren\'t here.', 'cmp' ); ?> <a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Edit my profile', 'cmp' ); ?></a></p></div>
			<?php echo self::card_html( $user_id, $user_id, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo CMP_Feed::profile_html( $user_id, $user_id, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Another member's profile: ?cmp_member=<user ID>. A profile the viewer
	 * may not see looks exactly like one that doesn't exist.
	 */
	public static function render_member( $owner_id ) {
		$viewer = get_current_user_id();
		if ( $owner_id && $owner_id === $viewer ) {
			return self::render_self( $viewer );
		}
		$ok     = $owner_id && $owner_id !== $viewer && CMP_Access::is_member( $owner_id );
		ob_start();
		?>
		<section class="cmp-step" aria-labelledby="cmp-member-title">
			<h2 id="cmp-member-title" class="cmp-title"><?php esc_html_e( 'Member profile', 'cmp' ); ?></h2>
			<?php if ( $ok ) : ?>
				<?php echo self::card_html( $owner_id, $viewer ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo CMP_Dynamics::propose_html( $viewer, $owner_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
				<?php echo CMP_Feed::profile_html( $owner_id, $viewer ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			<?php else : ?>
				<p class="cmp-empty"><?php esc_html_e( 'This profile isn\'t available.', 'cmp' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * Personal data export
	 * ---------------------------------------------------------------- */

	public static function export_items( $user_id ) {
		$rows = self::rows( $user_id );
		$data = array();
		foreach ( CMP_Profile_Fields::fields() as $key => $f ) {
			if ( 'image' === $f['type'] ) {
				$img = CMP_Profile_Images::get( $user_id, $key, false );
				$text = $img ? sprintf( /* translators: 1: width, 2: height */ __( 'Stored (%1$d × %2$d JPEG). Alt text: %3$s', 'cmp' ), $img->width, $img->height, $img->decorative ? __( '(decorative)', 'cmp' ) : $img->alt ) : '';
			} else {
				$text = CMP_Profile_Fields::display( $key, self::value( $user_id, $key, $rows ) );
			}
			if ( '' === $text ) {
				continue;
			}
			$data[] = array(
				'name'  => $f['label'],
				'value' => $text . ' (' . sprintf( /* translators: %s: visibility */ __( 'visible to: %s', 'cmp' ), self::visibility_labels()[ $rows[ $key ]['visibility'] ] ) . ')',
			);
		}
		return $data;
	}
}
