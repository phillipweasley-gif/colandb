<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A record of every tool call made through the connector: when, which user,
 * which tool, its input and the outcome. Shown on Tools → Claude Connector.
 * Kept as one non-autoloaded option holding the newest MAX entries; older
 * entries drop off.
 */
class COLANDB_MCP_Log {

	const OPTION = 'colandb_mcp_log';
	const MAX    = 200;

	/**
	 * @param string $tool    Tool name as Claude called it.
	 * @param mixed  $input   Arguments it sent.
	 * @param string $outcome 'ok' or an error code.
	 * @param int    $ms      Time taken.
	 */
	public static function add( $tool, $input, $outcome, $ms ) {
		$user  = wp_get_current_user();
		$input = wp_json_encode( $input ? $input : new stdClass() );
		if ( strlen( $input ) > 300 ) {
			$input = substr( $input, 0, 297 ) . '...';
		}

		$entries   = self::entries();
		$entries[] = array(
			'time'    => time(),
			'user'    => $user && $user->exists() ? $user->user_login : '',
			'tool'    => substr( (string) $tool, 0, 80 ),
			'input'   => $input,
			'outcome' => substr( (string) $outcome, 0, 80 ),
			'ms'      => (int) $ms,
		);
		if ( count( $entries ) > self::MAX ) {
			$entries = array_slice( $entries, -self::MAX );
		}
		update_option( self::OPTION, $entries, false );
	}

	/** Oldest first. */
	public static function entries() {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}
}
