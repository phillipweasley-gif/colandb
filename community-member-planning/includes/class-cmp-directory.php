<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Members: search and filter (0.13.0). Owner, 2026-10-05: "I also need a
 * way to search for members or filter members so I can find people to
 * connect with … click on one of the items in their profile to get a list
 * of members who have that item in their profile." Choices: everyone is
 * listed but can opt out; location by city / state for now.
 *
 * - Only answers a member shows to all members are ever matched, so a
 *   search can't reveal a hidden or connections-only answer. The display
 *   name is always shown (0.9.1).
 * - Full members only; never the searcher, members who opted out, or
 *   anyone blocked either way.
 * - Member counts are small (hundreds), so matching runs in PHP over one
 *   query of shown profile values.
 */
class CMP_Directory {

	const TAB         = 'members';
	const NONCE       = 'cmp_directory';
	const META_HIDE   = 'cmp_hide_from_search';
	const PER_PAGE    = 24;

	/** Profile fields members can filter by (option lists). */
	const FILTERS = array( 'roles', 'looking_for', 'identity', 'gender', 'position', 'body_type', 'kinks', 'interests', 'active_level', 'hosting' );

	public static function init() {
		add_action( 'admin_post_cmp_directory', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_cmp_directory', array( 'CMP_Member_Area', 'redirect_to_login' ) );
	}

	public static function is_filter( $key ) {
		return in_array( $key, self::FILTERS, true ) || 'location' === $key;
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'cmp_tab' => self::TAB ), $args ), CMP_Settings::member_page_url() );
	}

	public static function notices() {
		return array(
			'dir_saved' => array( 'success', __( 'Saved.', 'cmp' ) ),
		);
	}

	public static function hidden( $user_id ) {
		return (bool) get_user_meta( $user_id, self::META_HIDE, true );
	}

	/* ------------------------------------------------------------------
	 * Search
	 * ---------------------------------------------------------------- */

	/** The filters in the current request, cleaned. */
	public static function request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only search.
		$out = array();
		foreach ( self::FILTERS as $key ) {
			if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) {
				$v = sanitize_key( wp_unslash( $_GET[ $key ] ) );
				$f = CMP_Profile_Fields::field( $key );
				if ( isset( CMP_Profile_Fields::options( $f['list'], true )[ $v ] ) ) {
					$out[ $key ] = $v;
				}
			}
		}
		foreach ( array( 'q' => 60, 'city' => 80 ) as $key => $max ) {
			if ( isset( $_GET[ $key ] ) ) {
				$v = mb_substr( trim( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ), 0, $max );
				if ( '' !== $v ) {
					$out[ $key ] = $v;
				}
			}
		}
		foreach ( array( 'age_min', 'age_max' ) as $key ) {
			$v = isset( $_GET[ $key ] ) ? absint( $_GET[ $key ] ) : 0;
			if ( $v >= 18 && $v <= 120 ) {
				$out[ $key ] = $v;
			}
		}
		foreach ( array( 'photo', 'following' ) as $key ) {
			if ( ! empty( $_GET[ $key ] ) ) {
				$out[ $key ] = 1;
			}
		}
		$out['sort'] = isset( $_GET['sort'] ) && 'name' === $_GET['sort'] ? 'name' : 'newest';
		$out['pg']   = isset( $_GET['pg'] ) ? max( 1, absint( $_GET['pg'] ) ) : 1;
		// phpcs:enable
		return $out;
	}

	/**
	 * Members matching the filters, as the viewer may see them.
	 *
	 * @return array( 'ids' => int[] (this page), 'total' => int, 'values' => [user_id][field_key] => value (shown ones) )
	 */
	public static function search( $viewer_id, $filters ) {
		global $wpdb;
		$users = get_users(
			array(
				'meta_key' => CMP_Email_Verification::META_VERIFIED_EMAIL, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'   => array( 'ID', 'display_name', 'user_registered' ),
				'number'   => 5000,
			)
		);
		$blocked   = CMP_Messages::blocked_ids( $viewer_id );
		$following = ! empty( $filters['following'] ) ? CMP_Follows::following_ids( $viewer_id ) : array();
		$people    = array();
		foreach ( $users as $u ) {
			$id = (int) $u->ID;
			if ( $id === (int) $viewer_id || in_array( $id, $blocked, true ) || self::hidden( $id ) || ! CMP_Access::is_member( $id ) ) {
				continue;
			}
			if ( ! empty( $filters['following'] ) && ! in_array( $id, $following, true ) ) {
				continue;
			}
			if ( ! empty( $filters['q'] ) && false === mb_stripos( $u->display_name, $filters['q'] ) ) {
				continue;
			}
			$people[ $id ] = $u;
		}
		$values = array();
		if ( $people ) {
			$rows = $wpdb->get_results( 'SELECT user_id, field_key, value FROM ' . CMP_Install::table( 'profile_values' ) . " WHERE visibility = 'members' AND user_id IN (" . implode( ',', array_map( 'intval', array_keys( $people ) ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $r ) {
				$values[ (int) $r->user_id ][ $r->field_key ] = null === $r->value ? '' : json_decode( $r->value, true );
			}
		}
		$has = function ( $id, $key, $opt ) use ( &$values ) {
			$v = isset( $values[ $id ][ $key ] ) ? $values[ $id ][ $key ] : '';
			if ( is_string( $v ) ) {
				return $v === $opt;
			}
			if ( isset( $v['keys'] ) ) {
				return in_array( $opt, (array) $v['keys'], true );
			}
			foreach ( (array) $v as $row ) {
				if ( is_array( $row ) && isset( $row['k'], $row['lvl'] ) && $row['k'] === $opt ) {
					return true;
				}
			}
			return false;
		};
		$match = array();
		foreach ( $people as $id => $u ) {
			foreach ( self::FILTERS as $key ) {
				if ( isset( $filters[ $key ] ) && ! $has( $id, $key, $filters[ $key ] ) ) {
					continue 2;
				}
			}
			if ( ! empty( $filters['city'] ) ) {
				$loc = isset( $values[ $id ]['location'] ) && is_array( $values[ $id ]['location'] ) ? implode( ', ', array_filter( array_map( 'strval', $values[ $id ]['location'] ) ) ) : '';
				if ( false === mb_stripos( $loc, $filters['city'] ) ) {
					continue;
				}
			}
			if ( ! empty( $filters['age_min'] ) || ! empty( $filters['age_max'] ) ) {
				// Only members who show their age can match an age filter.
				$age = array_key_exists( 'age', isset( $values[ $id ] ) ? $values[ $id ] : array() ) ? CMP_Birth_Date::age( $id ) : null;
				if ( ! $age || ( ! empty( $filters['age_min'] ) && $age < $filters['age_min'] ) || ( ! empty( $filters['age_max'] ) && $age > $filters['age_max'] ) ) {
					continue;
				}
			}
			if ( ! empty( $filters['photo'] ) && ! ( CMP_Profiles::can_view( 'avatar', $id, $viewer_id ) && CMP_Profile_Images::get( $id, 'avatar' ) ) ) {
				continue;
			}
			$match[] = $id;
		}
		usort(
			$match,
			function ( $a, $b ) use ( $people, $filters ) {
				return 'name' === $filters['sort'] ? strcasecmp( $people[ $a ]->display_name, $people[ $b ]->display_name ) : strcmp( $people[ $b ]->user_registered, $people[ $a ]->user_registered );
			}
		);
		$total = count( $match );
		return array(
			'ids'    => array_slice( $match, ( $filters['pg'] - 1 ) * self::PER_PAGE, self::PER_PAGE ),
			'total'  => $total,
			'values' => $values,
		);
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ---------------------------------------------------------------- */

	public static function handle() {
		$user_id = get_current_user_id();
		if ( ! CMP_Access::is_member( $user_id ) || ! isset( $_POST['_cmp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cmp_nonce'] ) ), self::NONCE ) ) {
			wp_safe_redirect( add_query_arg( 'cmp_notice', 'expired', CMP_Settings::member_page_url() ) );
			exit;
		}
		$hide = ! empty( $_POST['hide'] );
		update_user_meta( $user_id, self::META_HIDE, $hide ? 1 : 0 );
		CMP_Audit::log( 'search_listing_changed', 'user', $user_id, null, array( 'hidden' => $hide ) );
		wp_safe_redirect( add_query_arg( 'cmp_notice', 'dir_saved', CMP_Profiles::url() . '#cmp-dir-settings' ) );
		exit;
	}

	public static function settings_html( $user_id ) {
		$html  = '<section class="cmp-panel" id="cmp-dir-settings"><h3 class="cmp-panel-title">' . esc_html__( 'Member search', 'cmp' ) . '</h3>';
		$html .= '<p>' . esc_html__( 'Members can find you in Members by your name and by the answers you show to all members. Hidden and connections-only answers are never searched.', 'cmp' ) . '</p>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cmp-form"><input type="hidden" name="action" value="cmp_directory" /><input type="hidden" name="_cmp_nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" />';
		$html .= '<p class="cmp-check"><input type="checkbox" id="cmp_dir_hide" name="hide" value="1"' . checked( self::hidden( $user_id ), true, false ) . ' /><label for="cmp_dir_hide">' . esc_html__( 'Hide me from member search', 'cmp' ) . '</label></p>';
		return $html . '<button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Save', 'cmp' ) . '</button></form></section>';
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	private static function label( $key ) {
		$labels = array( 'identity' => __( 'Orientation', 'cmp' ), 'active_level' => __( 'How active', 'cmp' ) );
		return isset( $labels[ $key ] ) ? $labels[ $key ] : CMP_Profile_Fields::field( $key )['label'];
	}

	public static function render( $user_id ) {
		$f      = self::request();
		$result = self::search( $user_id, $f );
		$base   = CMP_Settings::member_page_url();
		$hidden = '';
		// Keep the page's own query (e.g. ?page_id=) in the GET form.
		wp_parse_str( (string) wp_parse_url( $base, PHP_URL_QUERY ), $base_args );
		foreach ( array_merge( (array) $base_args, array( 'cmp_tab' => self::TAB ) ) as $k => $v ) {
			$hidden .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" />';
		}
		$active = array_diff_key( $f, array( 'sort' => 1, 'pg' => 1 ) );

		$html  = '<section class="cmp-step" aria-labelledby="cmp-dir-title"><h2 id="cmp-dir-title" class="cmp-title">' . esc_html__( 'Members', 'cmp' ) . '</h2>';
		$html .= '<p class="cmp-muted">' . esc_html__( 'Find people to connect with. Only what members show to all members is searched.', 'cmp' ) . '</p></section>';
		$html .= '<div class="cmp-dir-wrap"><div class="cmp-dir">';

		// Filters.
		$html .= '<details class="cmp-panel cmp-dir-filters"' . ( $active ? '' : ' open' ) . ' data-cmp-dir-filters><summary>' . esc_html( $active ? sprintf( /* translators: %d: filters */ _n( 'Filters (%d)', 'Filters (%d)', count( $active ), 'cmp' ), count( $active ) ) : __( 'Filters', 'cmp' ) ) . '</summary>';
		$html .= '<form method="get" action="' . esc_url( strtok( $base, '?' ) ) . '" class="cmp-form" role="search">' . $hidden;
		$html .= '<p class="cmp-field"><label for="cmp_dir_q">' . esc_html__( 'Name', 'cmp' ) . '</label><input type="search" id="cmp_dir_q" name="q" maxlength="60" value="' . esc_attr( isset( $f['q'] ) ? $f['q'] : '' ) . '" /></p>';
		foreach ( self::FILTERS as $key ) {
			$field = CMP_Profile_Fields::field( $key );
			$html .= '<p class="cmp-field"><label for="cmp_dir_' . esc_attr( $key ) . '">' . esc_html( self::label( $key ) ) . '</label><select id="cmp_dir_' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '"><option value="">' . esc_html__( 'Any', 'cmp' ) . '</option>';
			foreach ( CMP_Profile_Fields::options( $field['list'] ) as $k => $l ) {
				if ( 'not_listed' === $k ) {
					continue;
				}
				$html .= '<option value="' . esc_attr( $k ) . '"' . selected( isset( $f[ $key ] ) ? $f[ $key ] : '', $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			$html .= '</select></p>';
		}
		$html .= '<div class="cmp-grid cmp-grid-2"><p class="cmp-field"><label for="cmp_dir_amin">' . esc_html__( 'Age from', 'cmp' ) . '</label><input type="number" inputmode="numeric" id="cmp_dir_amin" name="age_min" min="18" max="120" value="' . esc_attr( isset( $f['age_min'] ) ? $f['age_min'] : '' ) . '" /></p><p class="cmp-field"><label for="cmp_dir_amax">' . esc_html__( 'to', 'cmp' ) . '</label><input type="number" inputmode="numeric" id="cmp_dir_amax" name="age_max" min="18" max="120" value="' . esc_attr( isset( $f['age_max'] ) ? $f['age_max'] : '' ) . '" /></p></div>';
		$html .= '<p class="cmp-field"><label for="cmp_dir_city">' . esc_html__( 'City or state', 'cmp' ) . '</label><input type="text" id="cmp_dir_city" name="city" maxlength="80" value="' . esc_attr( isset( $f['city'] ) ? $f['city'] : '' ) . '" placeholder="' . esc_attr__( 'e.g. Columbus', 'cmp' ) . '" /></p>';
		$html .= '<p class="cmp-check cmp-check-small"><input type="checkbox" id="cmp_dir_photo" name="photo" value="1"' . checked( ! empty( $f['photo'] ), true, false ) . ' /><label for="cmp_dir_photo">' . esc_html__( 'With a profile photo', 'cmp' ) . '</label></p>';
		$html .= '<p class="cmp-check cmp-check-small"><input type="checkbox" id="cmp_dir_following" name="following" value="1"' . checked( ! empty( $f['following'] ), true, false ) . ' /><label for="cmp_dir_following">' . esc_html__( 'People I follow', 'cmp' ) . '</label></p>';
		$html .= '<p class="cmp-field"><label for="cmp_dir_sort">' . esc_html__( 'Sort', 'cmp' ) . '</label><select id="cmp_dir_sort" name="sort"><option value="newest"' . selected( $f['sort'], 'newest', false ) . '>' . esc_html__( 'Newest members', 'cmp' ) . '</option><option value="name"' . selected( $f['sort'], 'name', false ) . '>' . esc_html__( 'Name', 'cmp' ) . '</option></select></p>';
		$html .= '<div class="cmp-actions"><button type="submit" class="cmp-btn">' . esc_html__( 'Search', 'cmp' ) . '</button>' . ( $active ? '<a class="cmp-btn cmp-btn-outline" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Clear', 'cmp' ) . '</a>' : '' ) . '</div></form></details>';

		// Results.
		$html .= '<div class="cmp-dir-results">';
		$html .= '<p class="cmp-dir-count" aria-live="polite">' . esc_html( sprintf( /* translators: %d: members */ _n( '%d member', '%d members', $result['total'], 'cmp' ), $result['total'] ) ) . '</p>';
		if ( $active ) {
			$html .= '<p class="cmp-dir-active">';
			foreach ( $active as $key => $v ) {
				$text = self::active_label( $key, $v );
				$args = array_diff_key( $f, array( $key => 1, 'pg' => 1 ) );
				if ( 'newest' === $args['sort'] ) {
					unset( $args['sort'] );
				}
				/* translators: %s: filter */
				$html .= '<a class="cmp-dir-chip" href="' . esc_url( self::url( $args ) ) . '" aria-label="' . esc_attr( sprintf( __( 'Remove filter: %s', 'cmp' ), $text ) ) . '">' . esc_html( $text ) . ' <span aria-hidden="true">✕</span></a>';
			}
			$html .= '</p>';
		}
		if ( ! $result['ids'] ) {
			$html .= '<section class="cmp-panel"><p class="cmp-empty">' . esc_html( $active ? __( 'No members match. Try removing a filter.', 'cmp' ) : __( 'No other members to show yet.', 'cmp' ) ) . '</p></section>';
		} else {
			$html .= '<ul class="cmp-dir-grid">';
			foreach ( $result['ids'] as $id ) {
				$html .= self::card( $id, $user_id, isset( $result['values'][ $id ] ) ? $result['values'][ $id ] : array() );
			}
			$html .= '</ul>';
		}
		$pages = (int) ceil( $result['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			$args  = array_diff_key( $f, array( 'pg' => 1 ) );
			$html .= '<nav class="cmp-fd-pages" aria-label="' . esc_attr__( 'Pages', 'cmp' ) . '">';
			if ( $f['pg'] > 1 ) {
				$html .= '<a class="cmp-btn cmp-btn-small cmp-btn-outline" href="' . esc_url( self::url( $args + array( 'pg' => $f['pg'] - 1 ) ) ) . '">' . esc_html__( 'Previous', 'cmp' ) . '</a>';
			}
			/* translators: 1: page, 2: pages */
			$html .= '<span class="cmp-muted">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'cmp' ), $f['pg'], $pages ) ) . '</span>';
			if ( $f['pg'] < $pages ) {
				$html .= '<a class="cmp-btn cmp-btn-small cmp-btn-outline" href="' . esc_url( self::url( $args + array( 'pg' => $f['pg'] + 1 ) ) ) . '">' . esc_html__( 'Next', 'cmp' ) . '</a>';
			}
			$html .= '</nav>';
		}
		return $html . '</div></div></div>';
	}

	private static function active_label( $key, $v ) {
		if ( in_array( $key, self::FILTERS, true ) ) {
			$o = CMP_Profile_Fields::options( CMP_Profile_Fields::field( $key )['list'], true );
			return self::label( $key ) . ': ' . ( isset( $o[ $v ] ) ? $o[ $v ] : $v );
		}
		$labels = array(
			'q'         => __( 'Name', 'cmp' ) . ': ' . $v,
			'city'      => __( 'City or state', 'cmp' ) . ': ' . $v,
			'age_min'   => sprintf( /* translators: %d: age */ __( 'Age %d+', 'cmp' ), $v ),
			'age_max'   => sprintf( /* translators: %d: age */ __( 'Age up to %d', 'cmp' ), $v ),
			'photo'     => __( 'With a profile photo', 'cmp' ),
			'following' => __( 'People I follow', 'cmp' ),
		);
		return isset( $labels[ $key ] ) ? $labels[ $key ] : (string) $v;
	}

	/** One result: cover strip, photo, name, "43 M Dom", city, two "Looking for" items. */
	private static function card( $id, $viewer_id, $shown ) {
		$user   = get_userdata( $id );
		$name   = $user ? $user->display_name : '';
		$avatar = CMP_Profiles::can_view( 'avatar', $id, $viewer_id ) ? CMP_Profile_Images::get( $id, 'avatar' ) : null;
		$cover  = CMP_Profiles::can_view( 'cover', $id, $viewer_id ) ? CMP_Profile_Images::get( $id, 'cover' ) : null;
		$label  = function ( $key, $opt ) {
			$o = CMP_Profile_Fields::options( CMP_Profile_Fields::field( $key )['list'], true );
			return isset( $o[ $opt ] ) ? $o[ $opt ] : '';
		};
		$gender = isset( $shown['gender']['keys'][0] ) ? $label( 'gender', $shown['gender']['keys'][0] ) : '';
		$role   = isset( $shown['roles']['keys'][0] ) ? $label( 'roles', $shown['roles']['keys'][0] ) : '';
		$age    = array_key_exists( 'age', $shown ) ? CMP_Birth_Date::age( $id ) : null;
		$tag    = trim( ( $age ? $age : '' ) . ' ' . ( $gender ? mb_substr( $gender, 0, 1 ) : '' ) . ' ' . $role );
		$city   = isset( $shown['location'] ) && is_array( $shown['location'] ) ? implode( ', ', array_filter( array( isset( $shown['location']['city'] ) ? $shown['location']['city'] : '', isset( $shown['location']['region'] ) ? $shown['location']['region'] : '' ) ) ) : '';
		$chips  = array();
		foreach ( array_slice( isset( $shown['looking_for']['keys'] ) ? (array) $shown['looking_for']['keys'] : array(), 0, 2 ) as $k ) {
			$l = $label( 'looking_for', $k );
			if ( $l ) {
				$chips[] = $l;
			}
		}
		$html  = '<li class="cmp-dir-card"><a href="' . esc_url( CMP_Profiles::member_url( $id ) ) . '">';
		$html .= '<span class="cmp-dir-cover">' . ( $cover ? CMP_Profile_Images::img_html( $id, 'cover', $cover ) : '' ) . '</span>';
		$html .= '<span class="cmp-dir-av">' . ( $avatar ? CMP_Profile_Images::img_html( $id, 'avatar', $avatar ) : '<span aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ) . '</span>' ) . '</span>';
		$html .= '<span class="cmp-dir-body"><b>' . esc_html( $name ) . '</b>';
		$html .= ( $tag || $city ) ? '<small>' . esc_html( implode( ' · ', array_filter( array( $tag, $city ) ) ) ) . '</small>' : '';
		$html .= $chips ? '<span class="cmp-dir-chips">' . implode( '', array_map( function ( $c ) {
			return '<span>' . esc_html( $c ) . '</span>';
		}, $chips ) ) . '</span>' : '';
		return $html . '</span></a></li>';
	}

	/* ------------------------------------------------------------------
	 * Privacy
	 * ---------------------------------------------------------------- */

	public static function export_rows( $user_id ) {
		return array( array( 'name' => __( 'Hidden from member search', 'cmp' ), 'value' => self::hidden( $user_id ) ? __( 'Yes', 'cmp' ) : __( 'No', 'cmp' ) ) );
	}

	public static function erase( $user_id ) {
		return delete_user_meta( $user_id, self::META_HIDE ) ? 1 : 0;
	}
}
