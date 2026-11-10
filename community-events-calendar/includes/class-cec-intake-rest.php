<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intake REST routes (1.50.0): the intake API (CEC_Intake_API) over the
 * WordPress REST API, so an administrator's tools working from outside the
 * site (Claude, using an application password) can fill in the fields the
 * standard /wp/v2/cec_event route doesn't expose: start and end, venue,
 * address, host, admission and links.
 *
 * - Administrators only (manage_options).
 * - Same rules as the Telegram intake: events are created as "pending"
 *   and nothing here can publish. Updates only while pending or in
 *   review. A person still publishes, which is what emails subscribers.
 *
 * Routes (namespace cec/v1):
 *   POST /intake/events          create a pending event (create_event() fields)
 *   GET  /intake/events/<id>     one event's fields (event_fields())
 *   POST /intake/events/<id>     update a pending event (update_event() fields)
 *   GET  /intake/candidates      possible duplicates (start, end, urls[], days)
 *   GET  /intake/venues/find     a saved venue that is the same place (name, address, city, region)
 *   POST /intake/venues          save a new venue (name, address, city, region, country)
 *   GET  /intake/orgs/<id>       an organization's or titleholder's profile (1.51.0)
 *   POST /intake/orgs/<id>       change its profile: description, kind, listed, mission,
 *                                title, year, producer, city/state or region, socials{}, logo_id
 *
 * Organizations themselves are created and linked to events through the
 * standard routes (/wp/v2/cec_partner_org, the event's cec_partner_org).
 */
class CEC_Intake_REST {

	const NS = 'cec/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'cec_audit_action_labels', array( __CLASS__, 'labels' ) );
	}

	public static function labels( $labels ) {
		$labels['event_rest_created'] = __( 'Added by an administrator through the intake REST API', 'cec' );
		$labels['event_rest_updated'] = __( 'Updated by an administrator through the intake REST API', 'cec' );
		return $labels;
	}

	public static function routes() {
		$admin = array( __CLASS__, 'can' );
		register_rest_route(
			self::NS,
			'/intake/events',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/intake/events/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'update' ),
					'permission_callback' => $admin,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/intake/candidates',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'candidates' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/intake/venues/find',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'find_venue' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/intake/venues',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_venue' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/intake/orgs/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_org' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'update_org' ),
					'permission_callback' => $admin,
				),
			)
		);
	}

	public static function can() {
		return current_user_can( 'manage_options' )
			? true
			: new WP_Error( 'cec_rest_forbidden', __( 'Only administrators can use the intake API.', 'cec' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/** The request's fields, without the route's own keys. */
	private static function fields( WP_REST_Request $r ) {
		$f = $r->get_json_params();
		if ( ! is_array( $f ) ) {
			$f = $r->get_body_params();
		}
		$f = is_array( $f ) ? $f : array();
		unset( $f['id'], $f['author_id'] );
		if ( isset( $f['event_type_ids'] ) ) {
			$f['event_type_ids'] = array_map( 'intval', (array) $f['event_type_ids'] );
		}
		return $f;
	}

	private static function fail( WP_Error $e ) {
		$e->add_data( array( 'status' => 400 ) );
		return $e;
	}

	public static function create( WP_REST_Request $r ) {
		$f              = self::fields( $r );
		$f['author_id'] = get_current_user_id();
		$id             = CEC_Intake_API::create_event( $f );
		if ( is_wp_error( $id ) ) {
			return self::fail( $id );
		}
		CEC_Audit_Log::log( $id, 'event_rest_created' );
		return new WP_REST_Response( CEC_Intake_API::event_fields( $id ), 201 );
	}

	public static function get( WP_REST_Request $r ) {
		$row = CEC_Intake_API::event_fields( (int) $r['id'] );
		return $row ? $row : new WP_Error( 'cec_intake_missing', __( 'That event no longer exists.', 'cec' ), array( 'status' => 404 ) );
	}

	public static function update( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		$ok = CEC_Intake_API::update_event( $id, self::fields( $r ) );
		if ( is_wp_error( $ok ) ) {
			return self::fail( $ok );
		}
		CEC_Audit_Log::log( $id, 'event_rest_updated' );
		return CEC_Intake_API::event_fields( $id );
	}

	public static function candidates( WP_REST_Request $r ) {
		return CEC_Intake_API::candidates(
			array(
				'start' => (string) $r->get_param( 'start' ),
				'end'   => (string) $r->get_param( 'end' ),
				'urls'  => (array) $r->get_param( 'urls' ),
				'days'  => null === $r->get_param( 'days' ) ? 3 : (int) $r->get_param( 'days' ),
			)
		);
	}

	public static function find_venue( WP_REST_Request $r ) {
		$args = array();
		foreach ( array( 'name', 'address', 'city', 'region' ) as $k ) {
			$args[ $k ] = (string) $r->get_param( $k );
		}
		$found         = CEC_Intake_API::find_venue( $args );
		$found['name'] = '';
		if ( $found['id'] ) {
			$t             = get_term( $found['id'], 'cec_venue' );
			$found['name'] = $t && ! is_wp_error( $t ) ? $t->name : '';
		}
		return $found;
	}

	public static function create_venue( WP_REST_Request $r ) {
		$args = array();
		foreach ( array( 'name', 'address', 'city', 'region', 'country' ) as $k ) {
			$args[ $k ] = (string) $r->get_param( $k );
		}
		$id = CEC_Intake_API::create_venue( $args );
		if ( is_wp_error( $id ) ) {
			return self::fail( $id );
		}
		return new WP_REST_Response( array( 'id' => $id, 'name' => get_term( $id, 'cec_venue' )->name ), 201 );
	}

	/* ------------------------------------------------------------------
	 * Organization and titleholder profiles (1.51.0)
	 * ---------------------------------------------------------------- */

	private static function org( $id ) {
		$p = CEC_Orgs::profile( (int) $id );
		if ( ! $p ) {
			return new WP_Error( 'cec_org_missing', __( 'That organization doesn\'t exist.', 'cec' ), array( 'status' => 404 ) );
		}
		unset( $p['contact'], $p['zip'] ); // Private: never sent out.
		return $p;
	}

	public static function get_org( WP_REST_Request $r ) {
		return self::org( $r['id'] );
	}

	/** Changes only the fields sent. Same checks as the term edit screen. */
	public static function update_org( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		$p  = self::org( $id );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$f   = self::fields( $r );
		$out = array();

		if ( isset( $f['kind'] ) ) {
			if ( ! in_array( $f['kind'], CEC_Orgs::KINDS, true ) ) {
				return self::fail( new WP_Error( 'cec_org_kind', __( 'kind must be organization or titleholder.', 'cec' ) ) );
			}
			$out['kind'] = $f['kind'];
		}
		if ( isset( $f['year'] ) && '' !== (string) $f['year'] && ! preg_match( '/^\d{4}$/', (string) $f['year'] ) ) {
			return self::fail( new WP_Error( 'cec_org_year', __( 'year must be four digits.', 'cec' ) ) );
		}
		if ( isset( $f['socials'] ) ) {
			if ( ! is_array( $f['socials'] ) ) {
				return self::fail( new WP_Error( 'cec_org_socials', __( 'socials must be an object of links.', 'cec' ) ) );
			}
			$socials = $p['socials'];
			foreach ( $f['socials'] as $k => $raw ) {
				if ( ! isset( CEC_Orgs::socials()[ $k ] ) ) {
					/* translators: %s: the key sent */
					return self::fail( new WP_Error( 'cec_org_socials', sprintf( __( '"%s" is not a known link.', 'cec' ), (string) $k ) ) );
				}
				$clean = CEC_Orgs::clean_social( $k, $raw );
				if ( is_wp_error( $clean ) ) {
					return self::fail( $clean );
				}
				$socials[ $k ] = $clean;
			}
			$out['socials'] = array_filter( $socials );
			update_term_meta( $id, 'cec_url', isset( $out['socials']['website'] ) ? $out['socials']['website'] : '' );
		}
		if ( isset( $f['logo_id'] ) ) {
			$logo_id = (int) $f['logo_id'];
			if ( $logo_id && ! wp_attachment_is_image( $logo_id ) ) {
				return self::fail( new WP_Error( 'cec_org_logo', __( 'logo_id must be an image in the media library.', 'cec' ) ) );
			}
			$out['logo_id'] = $logo_id;
			$out['logo']    = $logo_id ? (string) wp_get_attachment_url( $logo_id ) : '';
		}
		if ( isset( $f['city'] ) || isset( $f['state'] ) ) {
			$loc = CEC_Orgs::clean_location( isset( $f['city'] ) ? sanitize_text_field( $f['city'] ) : '', isset( $f['state'] ) ? sanitize_text_field( $f['state'] ) : '', '' );
			if ( is_wp_error( $loc ) ) {
				return self::fail( $loc );
			}
			unset( $loc['zip'] );
			$out = array_merge( $out, $loc );
		} elseif ( isset( $f['region'] ) ) {
			$out['region'] = sanitize_text_field( $f['region'] ); // Free text, e.g. outside the US.
		}
		foreach ( array( 'title', 'producer', 'summary' ) as $k ) {
			if ( isset( $f[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( $f[ $k ] );
			}
		}
		if ( isset( $f['year'] ) ) {
			$out['year'] = (string) $f['year'];
		}
		if ( isset( $f['mission'] ) ) {
			$out['mission'] = mb_substr( sanitize_textarea_field( $f['mission'] ), 0, 2000 );
		}
		if ( isset( $f['highlights'] ) ) {
			$out['highlights'] = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', (array) $f['highlights'] ) ) ), 0, 8 );
		}
		if ( isset( $f['listed'] ) ) {
			$out['listed'] = $f['listed'] ? '1' : '';
			if ( $f['listed'] && ! $p['listed'] && ! $p['order'] ) {
				$out['order'] = CEC_Orgs::next_order();
			}
		}
		if ( isset( $f['description'] ) ) {
			$t = wp_update_term( $id, 'cec_partner_org', array( 'description' => wp_kses_post( $f['description'] ) ) );
			if ( is_wp_error( $t ) ) {
				return self::fail( $t );
			}
		}
		CEC_Orgs::save_profile( $id, $out );
		CEC_Orgs::refresh_pages();
		return self::org( $id );
	}
}
