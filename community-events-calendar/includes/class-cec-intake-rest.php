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
}
