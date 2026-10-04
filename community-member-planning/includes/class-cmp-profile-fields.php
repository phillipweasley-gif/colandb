<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The profile field dictionary from the project brief (§2 "Initial profile
 * field dictionary") and the admin-managed option lists behind its select
 * fields. Field keys and option keys are stable machine keys: labels can be
 * renamed and options retired, but a key is never reused or deleted, so
 * stored selections keep their meaning.
 */
class CMP_Profile_Fields {

	const OPTION     = 'cmp_profile_options';
	const CAP        = 'cmp_manage_member_fields';
	const VISIBILITY = array( 'private', 'connections', 'members' );
	const AGE_BANDS  = array(
		'18-24' => '18–24',
		'25-34' => '25–34',
		'35-44' => '35–44',
		'45-54' => '45–54',
		'55-64' => '55–64',
		'65+'   => '65+',
	);

	/**
	 * Admin-managed option lists. 'availability' always includes the
	 * built-in "Not listed" default.
	 */
	public static function lists() {
		return array(
			'interests'   => __( 'Interests', 'cmp' ),
			'roles'       => __( 'Roles / capacities', 'cmp' ),
			'identity'    => __( 'Orientation / identity descriptors', 'cmp' ),
			'availability' => __( 'Availability', 'cmp' ),
			'looking_for' => __( 'Looking for', 'cmp' ),
		);
	}

	/**
	 * Field definitions, in display order.
	 * type: text | textarea | multi | single | location | age | system
	 */
	public static function fields() {
		return array(
			'display_name' => array( 'label' => __( 'Display name', 'cmp' ), 'type' => 'text', 'required' => true, 'max' => 80 ),
			'bio'          => array( 'label' => __( 'About me', 'cmp' ), 'type' => 'textarea', 'max' => 500 ),
			'pronouns'     => array( 'label' => __( 'Pronouns', 'cmp' ), 'type' => 'text', 'max' => 40 ),
			'location'     => array( 'label' => __( 'Location', 'cmp' ), 'type' => 'location', 'max' => 80, 'help' => __( 'City, region and country only. Never a street address.', 'cmp' ) ),
			'interests'    => array( 'label' => __( 'Interests', 'cmp' ), 'type' => 'multi', 'list' => 'interests', 'limit' => 20 ),
			'roles'        => array( 'label' => __( 'Roles / capacities', 'cmp' ), 'type' => 'multi', 'list' => 'roles', 'limit' => 10 ),
			'identity'     => array( 'label' => __( 'Orientation / identity', 'cmp' ), 'type' => 'multi', 'list' => 'identity', 'limit' => 10, 'other' => 80 ),
			'availability' => array( 'label' => __( 'Availability', 'cmp' ), 'type' => 'single', 'list' => 'availability' ),
			'looking_for'  => array( 'label' => __( 'Looking for', 'cmp' ), 'type' => 'multi', 'list' => 'looking_for', 'limit' => 10 ),
			'age'          => array( 'label' => __( 'Age', 'cmp' ), 'type' => 'age' ),
			'member_since' => array( 'label' => __( 'Member since', 'cmp' ), 'type' => 'system', 'searchable' => false ),
		);
	}

	public static function field( $key ) {
		$fields = self::fields();
		return isset( $fields[ $key ] ) ? $fields[ $key ] : null;
	}

	public static function can_be_searchable( $key ) {
		$f = self::field( $key );
		return $f && ( ! isset( $f['searchable'] ) || false !== $f['searchable'] );
	}

	/* ------------------------------------------------------------------
	 * Option lists
	 * ---------------------------------------------------------------- */

	/**
	 * @param bool $include_retired Retired options are kept so old
	 *                              selections can still be shown.
	 * @return array key => label, in admin order.
	 */
	public static function options( $list, $include_retired = false ) {
		$all = get_option( self::OPTION, array() );
		$out = array();
		if ( 'availability' === $list ) {
			$out['not_listed'] = __( 'Not listed', 'cmp' );
		}
		foreach ( isset( $all[ $list ] ) ? (array) $all[ $list ] : array() as $o ) {
			if ( $include_retired || ! empty( $o['active'] ) ) {
				$out[ $o['key'] ] = $o['label'];
			}
		}
		return $out;
	}

	public static function raw_options( $list ) {
		$all = get_option( self::OPTION, array() );
		return isset( $all[ $list ] ) ? (array) $all[ $list ] : array();
	}

	/**
	 * Saves one list from the admin screen. Existing keys keep their key;
	 * a new label gets a new key; nothing is ever deleted.
	 *
	 * @param array $rows array of array( key?, label, active, order ).
	 * @return true|WP_Error
	 */
	public static function save_list( $list, $rows ) {
		if ( ! isset( self::lists()[ $list ] ) ) {
			return new WP_Error( 'cmp_list', 'Unknown list.' );
		}
		$existing = array();
		foreach ( self::raw_options( $list ) as $o ) {
			$existing[ $o['key'] ] = $o;
		}

		$out    = array();
		$labels = array();
		foreach ( $rows as $row ) {
			$label = trim( sanitize_text_field( isset( $row['label'] ) ? $row['label'] : '' ) );
			$key   = isset( $row['key'] ) ? sanitize_key( $row['key'] ) : '';
			if ( '' === $label ) {
				if ( $key && isset( $existing[ $key ] ) ) {
					// A blanked label on an existing option keeps the option (retired).
					$out[ $key ] = array_merge( $existing[ $key ], array( 'active' => false, 'order' => (int) ( isset( $row['order'] ) ? $row['order'] : 0 ) ) );
				}
				continue;
			}
			if ( mb_strlen( $label ) > 80 ) {
				return new WP_Error( 'cmp_label', sprintf( /* translators: %s: label */ __( '"%s" is longer than 80 characters.', 'cmp' ), $label ) );
			}
			$active = ! empty( $row['active'] );
			if ( $active ) {
				$lower = mb_strtolower( $label );
				if ( isset( $labels[ $lower ] ) ) {
					return new WP_Error( 'cmp_dup', sprintf( /* translators: %s: label */ __( '"%s" appears more than once.', 'cmp' ), $label ) );
				}
				$labels[ $lower ] = true;
			}
			if ( ! $key || ! isset( $existing[ $key ] ) ) {
				$key  = substr( sanitize_title( $label ), 0, 60 );
				$base = $key ? $key : 'option';
				$n    = 2;
				while ( isset( $existing[ $key ] ) || isset( $out[ $key ] ) || 'not_listed' === $key || '' === $key ) {
					$key = $base . '-' . $n++;
				}
			}
			$out[ $key ] = array( 'key' => $key, 'label' => $label, 'active' => $active, 'order' => (int) ( isset( $row['order'] ) ? $row['order'] : 0 ) );
		}
		// Options not submitted at all are kept unchanged (never deleted).
		foreach ( $existing as $key => $o ) {
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $o;
			}
		}
		uasort(
			$out,
			function ( $a, $b ) {
				return ( (int) $a['order'] <=> (int) $b['order'] ) ?: strcasecmp( $a['label'], $b['label'] );
			}
		);

		$all          = get_option( self::OPTION, array() );
		$before       = isset( $all[ $list ] ) ? $all[ $list ] : array();
		$all[ $list ] = array_values( $out );
		update_option( self::OPTION, $all, false );
		if ( $before !== $all[ $list ] ) {
			CMP_Audit::log( 'profile_options_changed', 'option_list', 0, array( 'list' => $list, 'options' => $before ), array( 'list' => $list, 'options' => $all[ $list ] ) );
		}
		return true;
	}

	/* ------------------------------------------------------------------
	 * Validation of a member's submitted value
	 * ---------------------------------------------------------------- */

	/**
	 * @return mixed|WP_Error The clean value to store ('' / array() = empty).
	 */
	public static function clean( $key, $raw ) {
		$f = self::field( $key );
		if ( ! $f || 'system' === $f['type'] ) {
			return new WP_Error( 'cmp_field', 'Unknown field.' );
		}
		switch ( $f['type'] ) {
			case 'text':
			case 'textarea':
				$v = 'textarea' === $f['type'] ? sanitize_textarea_field( (string) $raw ) : sanitize_text_field( (string) $raw );
				$v = trim( $v );
				if ( ! empty( $f['required'] ) && '' === $v ) {
					/* translators: %s: field label */
					return new WP_Error( 'cmp_required', sprintf( __( '%s is required.', 'cmp' ), $f['label'] ) );
				}
				if ( mb_strlen( $v ) > $f['max'] ) {
					/* translators: 1: field label, 2: maximum characters */
					return new WP_Error( 'cmp_too_long', sprintf( __( '%1$s can be at most %2$d characters.', 'cmp' ), $f['label'], $f['max'] ) );
				}
				return $v;

			case 'location':
				$v = array();
				foreach ( array( 'city', 'region', 'country' ) as $part ) {
					$p = trim( sanitize_text_field( isset( $raw[ $part ] ) ? (string) $raw[ $part ] : '' ) );
					if ( mb_strlen( $p ) > $f['max'] ) {
						/* translators: %d: maximum characters */
						return new WP_Error( 'cmp_too_long', sprintf( __( 'Each part of your location can be at most %d characters.', 'cmp' ), $f['max'] ) );
					}
					$v[ $part ] = $p;
				}
				return array_filter( $v ) ? $v : '';

			case 'single':
				$v = sanitize_key( (string) $raw );
				return isset( self::options( $f['list'] )[ $v ] ) ? $v : '';

			case 'multi':
				$valid = self::options( $f['list'] );
				$v     = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $raw ), function ( $k ) use ( $valid ) {
					return isset( $valid[ $k ] );
				} ) ) );
				if ( count( $v ) > $f['limit'] ) {
					/* translators: 1: field label, 2: maximum choices */
					return new WP_Error( 'cmp_too_many', sprintf( __( 'Choose at most %2$d for %1$s.', 'cmp' ), $f['label'], $f['limit'] ) );
				}
				$out = array( 'keys' => $v );
				if ( ! empty( $f['other'] ) ) {
					$other = trim( sanitize_text_field( isset( $raw['other'] ) ? (string) $raw['other'] : '' ) );
					if ( mb_strlen( $other ) > $f['other'] ) {
						/* translators: %d: maximum characters */
						return new WP_Error( 'cmp_too_long', sprintf( __( '"Other" can be at most %d characters.', 'cmp' ), $f['other'] ) );
					}
					$out['other'] = $other;
				}
				return ( $out['keys'] || ! empty( $out['other'] ) ) ? $out : '';

			case 'age':
				$mode = isset( $raw['mode'] ) ? sanitize_key( $raw['mode'] ) : '';
				if ( 'exact' === $mode ) {
					$n = isset( $raw['exact'] ) ? (string) $raw['exact'] : '';
					if ( ! preg_match( '/^\d{2,3}$/', $n ) || (int) $n < 18 || (int) $n > 120 ) {
						return new WP_Error( 'cmp_age', __( 'Age must be a whole number from 18 to 120.', 'cmp' ) );
					}
					return array( 'mode' => 'exact', 'value' => (int) $n );
				}
				if ( 'band' === $mode ) {
					$b = isset( $raw['band'] ) ? (string) $raw['band'] : '';
					if ( ! isset( self::AGE_BANDS[ $b ] ) ) {
						return new WP_Error( 'cmp_age', __( 'Choose an age range.', 'cmp' ) );
					}
					return array( 'mode' => 'band', 'value' => $b );
				}
				return '';
		}
		return '';
	}

	/**
	 * Human-readable value, for previews (labels of retired options are
	 * still shown, per the brief).
	 */
	public static function display( $key, $value ) {
		$f = self::field( $key );
		if ( ! $f || '' === $value || array() === $value || null === $value ) {
			return '';
		}
		switch ( $f['type'] ) {
			case 'location':
				return implode( ', ', array_filter( (array) $value ) );
			case 'single':
				$o = self::options( $f['list'], true );
				return isset( $o[ $value ] ) ? $o[ $value ] : '';
			case 'multi':
				$o      = self::options( $f['list'], true );
				$labels = array();
				foreach ( isset( $value['keys'] ) ? $value['keys'] : array() as $k ) {
					if ( isset( $o[ $k ] ) ) {
						$labels[] = $o[ $k ];
					}
				}
				if ( ! empty( $value['other'] ) ) {
					$labels[] = $value['other'];
				}
				return implode( ', ', $labels );
			case 'age':
				return 'exact' === $value['mode'] ? (string) $value['value'] : ( isset( self::AGE_BANDS[ $value['value'] ] ) ? self::AGE_BANDS[ $value['value'] ] : '' );
			default:
				return (string) $value;
		}
	}
}
