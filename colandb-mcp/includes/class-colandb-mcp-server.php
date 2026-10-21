<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A small MCP server (Model Context Protocol, "Streamable HTTP" transport,
 * stateless JSON responses) at /wp-json/colandb/mcp, serving the abilities
 * in COLANDB_MCP_Abilities::NAMES as tools.
 *
 * Written here rather than bundling the MCP Adapter library: the adapter's
 * own docs advise against bundling it because other plugins (Elementor
 * already does on this site) bundle their own copy and the classes clash.
 * Only the parts of the protocol this server needs are implemented:
 * initialize, ping, tools/list, tools/call and notifications.
 *
 * Authentication is WordPress's: an application password (HTTP Basic) for
 * a user with the colandb_mcp_connect capability. Each tool then checks its
 * own capability through the Abilities API.
 */
class COLANDB_MCP_Server {

	const NS    = 'colandb';
	const ROUTE = '/mcp';

	// Newest first. An unknown version from the client gets the newest.
	const PROTOCOL_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function url() {
		return rest_url( self::NS . self::ROUTE );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
				array(
					// No server-to-client stream and no sessions: the spec's
					// answer for both is 405.
					'methods'             => 'GET, DELETE',
					'callback'            => array( __CLASS__, 'not_allowed' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	public static function permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'colandb_mcp_unauthorized',
				__( 'Sign in with the Claude Agent user\'s application password (HTTP Basic).', 'colandb-mcp' ),
				array( 'status' => 401 )
			);
		}
		if ( ! current_user_can( COLANDB_MCP_Role::CAP_CONNECT ) ) {
			return new WP_Error(
				'colandb_mcp_forbidden',
				__( 'This user may not use the Claude connector. Give it the Claude Agent role.', 'colandb-mcp' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	public static function not_allowed() {
		$response = new WP_REST_Response( null, 405 );
		$response->header( 'Allow', 'POST' );
		return $response;
	}

	private static function respond( $body, $status = 200 ) {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	private static function error( $id, $code, $message ) {
		return self::respond(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			)
		);
	}

	private static function result( $id, $result ) {
		return self::respond(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			)
		);
	}

	public static function handle( WP_REST_Request $request ) {
		$message = json_decode( $request->get_body(), true );
		if ( ! is_array( $message ) ) {
			return self::error( null, -32700, 'Parse error' );
		}
		if ( ! $message || array_keys( $message ) === range( 0, count( $message ) - 1 ) ) {
			return self::error( null, -32600, 'Batches are not supported.' );
		}

		$id     = array_key_exists( 'id', $message ) ? $message['id'] : null;
		$method = isset( $message['method'] ) && is_string( $message['method'] ) ? $message['method'] : '';
		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();

		// Notifications (no id) and responses to us need no answer.
		if ( ! array_key_exists( 'id', $message ) || '' === $method ) {
			return self::respond( null, 202 );
		}
		if ( ! is_string( $id ) && ! is_int( $id ) ) {
			return self::error( null, -32600, 'Invalid request id.' );
		}

		switch ( $method ) {
			case 'initialize':
				return self::result( $id, self::initialize( $params ) );
			case 'ping':
				return self::result( $id, new stdClass() );
			case 'tools/list':
				return self::result( $id, array( 'tools' => self::tools() ) );
			case 'tools/call':
				return self::call( $id, $params );
			default:
				return self::error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	private static function initialize( $params ) {
		$asked   = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$version = in_array( $asked, self::PROTOCOL_VERSIONS, true ) ? $asked : self::PROTOCOL_VERSIONS[0];

		return array(
			'protocolVersion' => $version,
			'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
			'serverInfo'      => array(
				'name'    => 'colandb',
				'title'   => 'colandb.com',
				'version' => COLANDB_MCP_VERSION,
			),
			'instructions'    => sprintf(
				'Read-only tools for %1$s (%2$s site), a community events calendar. Event start/end times are local to each event\'s own time zone. Nothing here can publish, change or delete anything, or read member data; changes are made by a person in wp-admin.',
				home_url( '/' ),
				wp_get_environment_type()
			),
		);
	}

	/** MCP tool name for an ability: "colandb/list-events" → "list_events". */
	private static function tool_name( $ability_name ) {
		return str_replace( '-', '_', substr( $ability_name, strlen( 'colandb/' ) ) );
	}

	/** @return WP_Ability|null */
	private static function ability_for_tool( $tool ) {
		foreach ( COLANDB_MCP_Abilities::NAMES as $name ) {
			if ( self::tool_name( $name ) === $tool ) {
				return wp_get_ability( $name );
			}
		}
		return null;
	}

	/** JSON Schema with empty "properties" as {} rather than []. */
	private static function schema_for_json( $schema ) {
		if ( ! is_array( $schema ) ) {
			return $schema;
		}
		foreach ( $schema as $key => $value ) {
			if ( 'properties' === $key ) {
				$props = array();
				foreach ( (array) $value as $prop => $sub ) {
					$props[ $prop ] = self::schema_for_json( $sub );
				}
				$schema[ $key ] = $props ? $props : new stdClass();
			} elseif ( is_array( $value ) && in_array( $key, array( 'items', 'default' ), true ) ) {
				$schema[ $key ] = 'items' === $key ? self::schema_for_json( $value ) : ( $value ? $value : new stdClass() );
			}
		}
		if ( isset( $schema['type'] ) && 'object' === $schema['type'] && ! isset( $schema['properties'] ) ) {
			$schema['properties'] = new stdClass();
		}
		return $schema;
	}

	/** Tools the signed-in user may call. */
	private static function tools() {
		$tools = array();
		foreach ( COLANDB_MCP_Abilities::NAMES as $name ) {
			$ability = wp_get_ability( $name );
			if ( ! $ability || true !== $ability->check_permissions( $ability->normalize_input( null ) ) ) {
				continue;
			}
			$annotations = (array) $ability->get_meta_item( 'annotations', array() );
			$tools[]     = array(
				'name'         => self::tool_name( $name ),
				'title'        => $ability->get_label(),
				'description'  => $ability->get_description(),
				'inputSchema'  => self::schema_for_json( $ability->get_input_schema() ),
				'outputSchema' => self::schema_for_json( $ability->get_output_schema() ),
				'annotations'  => array(
					'readOnlyHint'    => ! empty( $annotations['readonly'] ),
					'destructiveHint' => ! empty( $annotations['destructive'] ),
					'idempotentHint'  => ! empty( $annotations['idempotent'] ),
					'openWorldHint'   => false,
				),
			);
		}
		return $tools;
	}

	private static function call( $id, $params ) {
		$tool = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
		$args = isset( $params['arguments'] ) ? $params['arguments'] : null;

		$ability = self::ability_for_tool( $tool );
		if ( ! $ability ) {
			COLANDB_MCP_Log::add( $tool, $args, 'unknown_tool', 0 );
			return self::error( $id, -32602, 'Unknown tool: ' . $tool );
		}
		if ( null !== $args && ! is_array( $args ) ) {
			COLANDB_MCP_Log::add( $tool, $args, 'invalid_arguments', 0 );
			return self::error( $id, -32602, 'Tool arguments must be an object.' );
		}

		$started = microtime( true );
		$result  = $ability->execute( $args );
		$ms      = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $result ) ) {
			COLANDB_MCP_Log::add( $tool, $args, $result->get_error_code(), $ms );
			return self::result(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $result->get_error_message(),
						),
					),
					'isError' => true,
				)
			);
		}

		COLANDB_MCP_Log::add( $tool, $args, 'ok', $ms );
		return self::result(
			$id,
			array(
				'content'           => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
					),
				),
				'structuredContent' => $result,
			)
		);
	}
}
