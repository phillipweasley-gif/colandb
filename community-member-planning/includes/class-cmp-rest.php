<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes under cmp/v1. Every route's permission_callback requires a
 * full member (CMP_Access::is_member()); record-level ownership is checked
 * again inside each handler. Every cmp/v1 response — including errors — is
 * sent with private/no-store cache headers (brief §5).
 */
class CMP_Rest {

	const NS = 'cmp/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_store_headers' ), 10, 3 );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/notifications',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_notifications' ),
				'permission_callback' => array( __CLASS__, 'require_member' ),
				'args'                => array(
					'page' => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/notifications/(?P<id>\d+)/read',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'mark_read' ),
				'permission_callback' => array( __CLASS__, 'require_member' ),
			)
		);
		register_rest_route(
			self::NS,
			'/notifications/read-all',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'mark_all_read' ),
				'permission_callback' => array( __CLASS__, 'require_member' ),
			)
		);
	}

	public static function require_member() {
		if ( CMP_Access::is_member() ) {
			return true;
		}
		return new WP_Error(
			'cmp_forbidden',
			__( 'The member area requires a signed-in, verified member account.', 'cmp' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	public static function list_notifications( WP_REST_Request $request ) {
		$per_page = 20;
		$user_id  = get_current_user_id();
		$rows     = CMP_Notifications::for_user( $user_id, $per_page, ( (int) $request['page'] - 1 ) * $per_page );
		return rest_ensure_response(
			array(
				'unread' => CMP_Notifications::unread_count( $user_id ),
				'items'  => array_map( array( 'CMP_Notifications', 'to_public' ), $rows ),
			)
		);
	}

	public static function mark_read( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! CMP_Notifications::mark_read( $user_id, (int) $request['id'] ) ) {
			return new WP_Error( 'cmp_not_found', __( 'Notification not found.', 'cmp' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'unread' => CMP_Notifications::unread_count( $user_id ) ) );
	}

	public static function mark_all_read() {
		$user_id = get_current_user_id();
		CMP_Notifications::mark_all_read( $user_id );
		return rest_ensure_response( array( 'unread' => 0 ) );
	}

	public static function no_store_headers( $response, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/' . self::NS ) ) {
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		}
		return $response;
	}
}
