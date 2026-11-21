<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event agreements (0.40.0): rules people agree to on their phone before
 * joining an activity, first the mosh pit (owner, 2026-11-15: "a consent
 * form on colandb.com ... that can be scanned using a QR code, they can
 * login as a member and confirm or signup for free and confirm").
 *
 * This class is the data only. The page people use is CMP_Agreement_Form,
 * the team's screen is CMP_Agreements_Admin.
 *
 * - Agreements live in the option `cmp_agreements`, keyed by a short key
 *   ("mosh-pit"): title, intro, rules (one per line), the full terms (HTML),
 *   how long an agreement lasts (renew_hours; 0 = until the rules change),
 *   active, version and the text of every earlier version, so the team can
 *   always see exactly what someone agreed to.
 * - Each agreement is a row in `{prefix}cmp_agreement_signs` with the
 *   version, the account and a copy of its email and display name at the
 *   time (the row outlives an account removed for never verifying its
 *   email). Kept 3 years (CMP_Retention 'agreements'); exported and erased
 *   by email like help requests.
 */
class CMP_Agreements {

	const OPTION = 'cmp_agreements';

	/** Starter agreements, one file each in includes/agreements/. */
	const STARTERS = array( 'mosh-pit' );

	public static function t() {
		return CMP_Install::table( 'agreement_signs' );
	}

	/* ------------------------------------------------------------------
	 * Agreements
	 * ---------------------------------------------------------------- */

	public static function all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] + array( 'key' => $key ) : null;
	}

	public static function clean_key( $key ) {
		return substr( sanitize_key( (string) $key ), 0, 40 );
	}

	/** Adds any starter agreement that doesn't exist yet. Never overwrites. */
	public static function seed() {
		$all     = self::all();
		$changed = false;
		foreach ( self::STARTERS as $key ) {
			if ( isset( $all[ $key ] ) ) {
				continue;
			}
			$starter = include CMP_DIR . 'includes/agreements/' . $key . '.php';
			if ( ! is_array( $starter ) ) {
				continue;
			}
			$all[ $key ] = self::normalize( $starter ) + array(
				'active'     => true,
				'version'    => 1,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				'history'    => array(),
			);
			$changed = true;
		}
		if ( $changed ) {
			update_option( self::OPTION, $all, false );
		}
	}

	/** The parts of an agreement people read, cleaned. */
	public static function normalize( $in ) {
		$rules = isset( $in['rules'] ) ? $in['rules'] : array();
		if ( is_string( $rules ) ) {
			$rules = preg_split( '/\r\n|\r|\n/', $rules );
		}
		$rules = array_values( array_filter( array_map( static function ( $r ) {
			return mb_substr( trim( sanitize_text_field( (string) $r ) ), 0, 300 );
		}, (array) $rules ), 'strlen' ) );
		return array(
			'title'       => mb_substr( sanitize_text_field( (string) ( $in['title'] ?? '' ) ), 0, 120 ),
			'intro'       => mb_substr( sanitize_textarea_field( (string) ( $in['intro'] ?? '' ) ), 0, 600 ),
			'rules'       => array_slice( $rules, 0, 30 ),
			'body'        => wp_kses_post( (string) ( $in['body'] ?? '' ) ),
			'renew_hours' => max( 0, min( 24 * 365, (int) ( $in['renew_hours'] ?? 0 ) ) ),
		);
	}

	/** What a version's people read (for the record and the comparison). */
	private static function wording( $a ) {
		return array( 'title' => $a['title'], 'intro' => $a['intro'], 'rules' => $a['rules'], 'body' => $a['body'] );
	}

	/**
	 * Creates or updates an agreement. A change to the wording starts a new
	 * version (the old wording is kept in history), so everyone is asked to
	 * agree again; changing only how long it lasts or switching it off does not.
	 *
	 * @return array|WP_Error the saved agreement
	 */
	public static function save( $key, $input, $active ) {
		$key = self::clean_key( $key );
		if ( '' === $key ) {
			return new WP_Error( 'bad_key', __( 'Give the agreement a short key using letters, numbers and dashes, for example mosh-pit.', 'cmp' ) );
		}
		$new = self::normalize( $input );
		if ( '' === $new['title'] || ! $new['rules'] ) {
			return new WP_Error( 'incomplete', __( 'An agreement needs a title and at least one rule.', 'cmp' ) );
		}
		$all = self::all();
		$old = isset( $all[ $key ] ) ? $all[ $key ] : null;
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( ! $old ) {
			$saved = $new + array( 'active' => (bool) $active, 'version' => 1, 'updated_at' => $now, 'history' => array() );
		} else {
			$saved = $new + array( 'active' => (bool) $active, 'version' => (int) $old['version'], 'updated_at' => $old['updated_at'], 'history' => (array) $old['history'] );
			if ( self::wording( $new ) !== self::wording( $old ) ) {
				$saved['history'][ (int) $old['version'] ] = self::wording( $old ) + array( 'from' => $old['updated_at'], 'to' => $now );
				$saved['version']    = (int) $old['version'] + 1;
				$saved['updated_at'] = $now;
			}
		}
		$all[ $key ] = $saved;
		update_option( self::OPTION, $all, false );
		CMP_Audit::log(
			$old ? 'agreement_updated' : 'agreement_created',
			'agreement',
			0,
			$old ? array( 'key' => $key, 'version' => (int) $old['version'], 'active' => (bool) $old['active'], 'renew_hours' => (int) $old['renew_hours'] ) : null,
			array( 'key' => $key, 'version' => $saved['version'], 'active' => $saved['active'], 'renew_hours' => $saved['renew_hours'] )
		);
		return $saved + array( 'key' => $key );
	}

	/** The wording of a given version: current or from history. */
	public static function version_text( $a, $version ) {
		if ( (int) $a['version'] === (int) $version ) {
			return self::wording( $a );
		}
		return isset( $a['history'][ (int) $version ] ) ? $a['history'][ (int) $version ] : null;
	}

	/* ------------------------------------------------------------------
	 * Pages
	 * ---------------------------------------------------------------- */

	/** The published page holding [cmp_agreement key="<key>"], or 0. */
	public static function page_id( $key ) {
		global $wpdb;
		$key   = self::clean_key( $key );
		$cache = (array) get_option( 'cmp_agreement_pages', array() );
		$id    = isset( $cache[ $key ] ) ? (int) $cache[ $key ] : 0;
		if ( $id && 'publish' === get_post_status( $id ) && self::page_has( $id, $key ) ) {
			return $id;
		}
		$id   = 0;
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID", '%' . $wpdb->esc_like( '[' . CMP_Agreement_Form::SHORTCODE ) . '%' ) );
		foreach ( $rows as $row ) {
			if ( self::page_has( (int) $row, $key ) ) {
				$id = (int) $row;
				break;
			}
		}
		$cache[ $key ] = $id;
		update_option( 'cmp_agreement_pages', $cache, false );
		return $id;
	}

	private static function page_has( $id, $key ) {
		$content = (string) get_post_field( 'post_content', $id );
		if ( ! preg_match_all( '/' . get_shortcode_regex( array( CMP_Agreement_Form::SHORTCODE ) ) . '/', $content, $m, PREG_SET_ORDER ) ) {
			return false;
		}
		foreach ( $m as $sc ) {
			$atts = shortcode_parse_atts( $sc[3] );
			if ( is_array( $atts ) && isset( $atts['key'] ) && self::clean_key( $atts['key'] ) === $key ) {
				return true;
			}
		}
		return false;
	}

	/** The permanent link a QR code should open: never changes with the page. */
	public static function qr_url( $key ) {
		return add_query_arg( 'cmp_agree', self::clean_key( $key ), home_url( '/' ) );
	}

	/* ------------------------------------------------------------------
	 * Who agreed
	 * ---------------------------------------------------------------- */

	public static function record( $key, $user_id, $via ) {
		global $wpdb;
		$a    = self::get( $key );
		$user = get_userdata( (int) $user_id );
		if ( ! $a || ! $user ) {
			return 0;
		}
		$wpdb->insert(
			self::t(),
			array(
				'agreement'  => $key,
				'version'    => (int) $a['version'],
				'user_id'    => (int) $user->ID,
				'email'      => strtolower( $user->user_email ),
				'name'       => mb_substr( $user->display_name, 0, 100 ),
				'via'        => in_array( $via, array( 'member', 'signin', 'signup' ), true ) ? $via : 'member',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'ip_hash'    => self::ip_hash(),
			)
		);
		$id = (int) $wpdb->insert_id;
		CMP_Audit::log( 'agreement_signed', 'user', (int) $user->ID, null, array( 'agreement' => $key, 'version' => (int) $a['version'], 'via' => $via ), '', (int) $user->ID );
		return $id;
	}

	/** The latest row for this account and agreement, or null. */
	public static function latest( $key, $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE agreement = %s AND user_id = %d ORDER BY id DESC LIMIT 1', $key, (int) $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * The row that still counts: same version, and within renew_hours if the
	 * agreement asks again after a while (12 hours = each event night).
	 */
	public static function current( $key, $user_id ) {
		$a   = self::get( $key );
		$row = $a ? self::latest( $key, $user_id ) : null;
		if ( ! $row || (int) $row->version !== (int) $a['version'] ) {
			return null;
		}
		if ( (int) $a['renew_hours'] > 0 && strtotime( $row->created_at . ' UTC' ) < time() - (int) $a['renew_hours'] * HOUR_IN_SECONDS ) {
			return null;
		}
		return $row;
	}

	/** Rows for the team's list: one agreement, newest first, optional dates (site timezone, Y-m-d). */
	public static function rows( $key, $from = '', $to = '', $limit = 1000 ) {
		global $wpdb;
		$where = $wpdb->prepare( 'agreement = %s', $key );
		if ( '' !== $from ) {
			$where .= $wpdb->prepare( ' AND created_at >= %s', get_gmt_from_date( $from . ' 00:00:00' ) );
		}
		if ( '' !== $to ) {
			$where .= $wpdb->prepare( ' AND created_at <= %s', get_gmt_from_date( $to . ' 23:59:59' ) );
		}
		return $wpdb->get_results( 'SELECT * FROM ' . self::t() . " WHERE $where ORDER BY id DESC LIMIT " . max( 1, (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function count( $key, $since_gmt = '' ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t() . ' WHERE agreement = %s', $key ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== $since_gmt ) {
			$sql .= $wpdb->prepare( ' AND created_at >= %s', $since_gmt );
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return hash_hmac( 'sha256', 'ip|' . $ip, wp_salt( 'auth' ) );
	}

	/* ------------------------------------------------------------------
	 * Retention and privacy
	 * ---------------------------------------------------------------- */

	public static function purge( $days ) {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function export_rows( $email ) {
		global $wpdb;
		$rows = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE email = %s ORDER BY id', strtolower( $email ) ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$a      = self::get( $r->agreement );
			$title  = $a ? $a['title'] : $r->agreement;
			$rows[] = array( 'name' => __( 'Event agreement you accepted', 'cmp' ), 'value' => sprintf( '%s · %s %d · %s UTC · %s', $title, __( 'version', 'cmp' ), $r->version, $r->created_at, $r->name ) );
		}
		return $rows;
	}

	public static function erase( $email ) {
		global $wpdb;
		return (int) $wpdb->delete( self::t(), array( 'email' => strtolower( $email ) ), array( '%s' ) );
	}
}
