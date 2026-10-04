<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Member profiles (brief §2 "Member profile"): the values a member enters
 * for each field in CMP_Profile_Fields, with a visibility per field
 * (Private / Connections / Members, default Private), the member area's
 * Profile tab, and the one rule for who may see what (can_view()).
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
		$input = isset( $_POST['f'] ) && is_array( $_POST['f'] ) ? wp_unslash( $_POST['f'] ) : array();
		$vis   = isset( $_POST['v'] ) && is_array( $_POST['v'] ) ? wp_unslash( $_POST['v'] ) : array();
		// phpcs:enable

		$rows   = self::rows( $user_id );
		$clean  = array();
		$errors = array();
		foreach ( CMP_Profile_Fields::fields() as $key => $f ) {
			if ( in_array( $f['type'], array( 'account', 'system', 'image' ), true ) ) {
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
			set_transient( self::ERRORS . $user_id, array( 'errors' => $errors, 'input' => $input, 'vis' => $vis ), self::ERRORS_TTL );
			wp_safe_redirect( self::url( 'profile_invalid', 'cmp-profile-errors' ) );
			exit;
		}
		delete_transient( self::ERRORS . $user_id );

		$changed_values = array();
		$vis_old        = array();
		$vis_new        = array();
		foreach ( CMP_Profile_Fields::fields() as $key => $f ) {
			if ( 'image' === $f['type'] ) {
				continue; // Saved with the photo itself.
			}
			$v = isset( $vis[ $key ] ) ? sanitize_key( $vis[ $key ] ) : 'private';
			$v = in_array( $v, CMP_Profile_Fields::VISIBILITY, true ) ? $v : 'private';
			$has_value = array_key_exists( $key, $clean );
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
		wp_safe_redirect( self::url( 'profile_saved' ) );
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

	private static function visibility_select( $key, $current, $label ) {
		$id   = 'cmp_v_' . $key;
		$html = '<span class="cmp-visibility"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Who can see this', 'cmp' ) . '<span class="screen-reader-text">: ' . esc_html( $label ) . '</span></label>'
			. '<select id="' . esc_attr( $id ) . '" name="v[' . esc_attr( $key ) . ']">';
		foreach ( self::visibility_labels() as $value => $text ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		return $html . '</select></span>';
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
				$value = is_array( $value ) ? $value : array();
				$mode  = isset( $value['mode'] ) ? (string) $value['mode'] : '';
				$exact = 'exact' === $mode && isset( $value['value'] ) ? (string) $value['value'] : ( isset( $value['exact'] ) ? (string) $value['exact'] : '' );
				$band  = 'band' === $mode && isset( $value['value'] ) ? (string) $value['value'] : ( isset( $value['band'] ) ? (string) $value['band'] : '' );
				$html  = '<span class="cmp-age" data-cmp-age>';
				$html .= '<span class="cmp-choice"><input type="radio" id="' . esc_attr( $id ) . '_none" name="' . esc_attr( $name . '[mode]' ) . '" value=""' . checked( '', $mode, false ) . ' /><label for="' . esc_attr( $id ) . '_none">' . esc_html__( "Don't show an age", 'cmp' ) . '</label></span>';
				$html .= '<span class="cmp-choice"><input type="radio" id="' . esc_attr( $id ) . '_exact" name="' . esc_attr( $name . '[mode]' ) . '" value="exact"' . checked( 'exact', $mode, false ) . ' /><label for="' . esc_attr( $id ) . '_exact">' . esc_html__( 'My age:', 'cmp' ) . '</label>'
					. '<input type="number" min="18" max="120" step="1" inputmode="numeric" class="cmp-age-number" id="' . esc_attr( $id ) . '_exact_value" name="' . esc_attr( $name . '[exact]' ) . '" value="' . esc_attr( $exact ) . '" aria-label="' . esc_attr__( 'Age in years, 18 to 120', 'cmp' ) . '"' . $bad . ' /></span>';
				$html .= '<span class="cmp-choice"><input type="radio" id="' . esc_attr( $id ) . '_band" name="' . esc_attr( $name . '[mode]' ) . '" value="band"' . checked( 'band', $mode, false ) . ' /><label for="' . esc_attr( $id ) . '_band">' . esc_html__( 'An age range:', 'cmp' ) . '</label>'
					. '<select id="' . esc_attr( $id ) . '_band_value" name="' . esc_attr( $name . '[band]' ) . '" aria-label="' . esc_attr__( 'Age range', 'cmp' ) . '"><option value="">' . esc_html__( 'Choose…', 'cmp' ) . '</option>';
				foreach ( CMP_Profile_Fields::AGE_BANDS as $k => $label ) {
					$html .= '<option value="' . esc_attr( $k ) . '"' . selected( $band, $k, false ) . '>' . esc_html( $label ) . '</option>';
				}
				return $html . '</select></span></span>';
		}
		return '';
	}

	private static function help( $key, $f ) {
		$help = array(
			'display_name' => '',
			'bio'          => sprintf( /* translators: %d: maximum characters */ __( 'Up to %d characters.', 'cmp' ), 500 ),
			'pronouns'     => __( 'For example: she/her, he/him, they/them.', 'cmp' ),
			'location'     => __( 'City, region and country only. Never a street address.', 'cmp' ),
			'interests'    => sprintf( /* translators: %d: maximum choices */ __( 'Choose up to %d.', 'cmp' ), 20 ),
			'roles'        => sprintf( /* translators: %d: maximum choices */ __( 'Choose up to %d.', 'cmp' ), 10 ),
			'identity'     => sprintf( /* translators: %d: maximum choices */ __( 'Choose up to %d, and/or describe it yourself.', 'cmp' ), 10 ),
			'availability' => __( 'Set by you; never worked out from when you sign in.', 'cmp' ),
			'looking_for'  => sprintf( /* translators: %d: maximum choices */ __( 'Choose up to %d.', 'cmp' ), 10 ),
			'age'          => __( 'Optional. We never ask for your date of birth, and an age you enter doesn\'t change by itself.', 'cmp' ),
			'member_since' => __( 'Set automatically from when you joined.', 'cmp' ),
		);
		return isset( $help[ $key ] ) ? $help[ $key ] : '';
	}

	public static function render() {
		$user_id = get_current_user_id();
		$rows    = self::rows( $user_id );
		$saved   = get_transient( self::ERRORS . $user_id );
		$errors  = $saved && ! empty( $saved['errors'] ) ? $saved['errors'] : array();
		$input   = $saved && isset( $saved['input'] ) ? (array) $saved['input'] : array();
		$vis_in  = $saved && isset( $saved['vis'] ) ? (array) $saved['vis'] : array();
		$fields  = CMP_Profile_Fields::fields();

		ob_start();
		?>
		<section class="cmp-step" aria-labelledby="cmp-profile-title">
			<h2 id="cmp-profile-title" class="cmp-title"><?php esc_html_e( 'Your profile', 'cmp' ); ?></h2>
			<p><?php esc_html_e( 'Everything here is optional apart from your display name, and starts out visible only to you. For each item, choose who can see it.', 'cmp' ); ?></p>
			<ul class="cmp-legend">
				<li><strong><?php esc_html_e( 'Only me', 'cmp' ); ?></strong> — <?php esc_html_e( 'nobody else, not even site administrators.', 'cmp' ); ?></li>
				<li><strong><?php esc_html_e( 'My connections', 'cmp' ); ?></strong> — <?php esc_html_e( 'members you have connected with. Connections are coming in a later update; until then this is the same as Only me.', 'cmp' ); ?></li>
				<li><strong><?php esc_html_e( 'All members', 'cmp' ); ?></strong> — <?php esc_html_e( 'anyone signed in to the member area. Never the public, search engines or anyone signed out.', 'cmp' ); ?></li>
			</ul>
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
				foreach ( $fields as $key => $f ) {
					if ( 'image' === $f['type'] ) {
						continue;
					}
					$value  = array_key_exists( $key, $input ) ? $input[ $key ] : self::value( $user_id, $key, $rows );
					$vis    = isset( $vis_in[ $key ] ) ? sanitize_key( $vis_in[ $key ] ) : $rows[ $key ]['visibility'];
					$help   = self::help( $key, $f );
					$is_set = in_array( $f['type'], array( 'location', 'multi', 'age' ), true );
					$label  = esc_html( $f['label'] );
					echo '<div class="cmp-profile-row' . ( isset( $errors[ $key ] ) ? ' has-error' : '' ) . '" id="cmp-row-' . esc_attr( $key ) . '">';
					if ( $is_set ) {
						echo '<fieldset class="cmp-profile-input"><legend>' . $label . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					} else {
						echo '<div class="cmp-profile-input">';
						if ( in_array( $f['type'], array( 'account', 'system' ), true ) ) {
							echo '<span class="cmp-label">' . $label . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							echo '<label for="cmp_f_' . esc_attr( $key ) . '">' . $label . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
					}
					if ( $help ) {
						echo '<span class="cmp-muted">' . esc_html( $help ) . '</span>';
					}
					echo self::error_html( $key, $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					if ( 'account' === $f['type'] ) {
						echo '<span class="cmp-readonly">' . esc_html( (string) $value ) . ' <a href="' . esc_url( CMP_Account::url() . '#cmp-details' ) . '">' . esc_html__( 'Change on the Account tab', 'cmp' ) . '</a></span>';
					} elseif ( 'system' === $f['type'] ) {
						echo '<span class="cmp-readonly">' . esc_html( (string) $value ) . '</span>';
					} else {
						echo self::input_html( $key, $f, $value, $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					echo $is_set ? '</fieldset>' : '</div>';
					echo self::visibility_select( $key, $vis, $f['label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '</div>';
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
			<p class="cmp-muted"><?php esc_html_e( 'Based on what is saved now: only the items set to All members.', 'cmp' ); ?></p>
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
		$rows    = self::rows( $owner_id );
		$fields  = self::visible( $owner_id, $viewer_id, $as_members );
		$can_img = function ( $kind ) use ( $owner_id, $viewer_id, $as_members, $rows ) {
			return $as_members ? 'members' === $rows[ $kind ]['visibility'] : self::can_view( $kind, $owner_id, $viewer_id, $rows );
		};
		$cover   = $can_img( 'cover' ) ? CMP_Profile_Images::get( $owner_id, 'cover', false ) : null;
		$avatar  = $can_img( 'avatar' ) ? CMP_Profile_Images::get( $owner_id, 'avatar', false ) : null;
		$name    = isset( $fields['display_name'] ) ? $fields['display_name']['text'] : __( 'Member', 'cmp' );
		unset( $fields['display_name'] );

		$html = '<div class="cmp-card">';
		$html .= '<div class="cmp-card-cover">' . ( $cover ? CMP_Profile_Images::img_html( $owner_id, 'cover', $cover ) : '' ) . '</div>';
		$html .= '<div class="cmp-card-head"><span class="cmp-card-avatar">' . ( $avatar ? CMP_Profile_Images::img_html( $owner_id, 'avatar', $avatar ) : '<span class="cmp-avatar-placeholder" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ) . '</span>' ) . '</span>';
		$html .= '<span class="cmp-card-name">' . esc_html( $name ) . '</span></div>';
		if ( $fields ) {
			$html .= '<dl class="cmp-card-fields">';
			foreach ( $fields as $f ) {
				$html .= '<div><dt>' . esc_html( $f['label'] ) . '</dt><dd>' . nl2br( esc_html( $f['text'] ) ) . '</dd></div>';
			}
			$html .= '</dl>';
		} else {
			$html .= '<p class="cmp-empty">' . esc_html( $as_members ? __( 'Nothing else is shared with members yet.', 'cmp' ) : __( 'This member hasn\'t shared anything else with you.', 'cmp' ) ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * Another member's profile: ?cmp_member=<user ID>. A profile the viewer
	 * may not see looks exactly like one that doesn't exist.
	 */
	public static function render_member( $owner_id ) {
		$viewer = get_current_user_id();
		$ok     = $owner_id && $owner_id !== $viewer && CMP_Access::is_member( $owner_id );
		ob_start();
		?>
		<section class="cmp-step" aria-labelledby="cmp-member-title">
			<h2 id="cmp-member-title" class="cmp-title"><?php esc_html_e( 'Member profile', 'cmp' ); ?></h2>
			<?php if ( $ok ) : ?>
				<?php echo self::card_html( $owner_id, $viewer ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
