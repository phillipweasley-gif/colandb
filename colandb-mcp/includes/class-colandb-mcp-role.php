<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Claude Agent" role and the connector's own capabilities.
 *
 * Claude signs in as a dedicated user with this role. It holds `read` plus
 * the connector's capabilities and nothing else, so the same application
 * password can't edit, publish or delete anything through the rest of the
 * REST API either. Administrators get the same capabilities so they can try
 * the tools themselves.
 */
class COLANDB_MCP_Role {

	const ROLE = 'colandb_agent';

	// Use the MCP server at all.
	const CAP_CONNECT = 'colandb_mcp_connect';
	// See events that are pending or in review (not yet public).
	const CAP_PENDING = 'colandb_mcp_view_pending_events';
	// See plugin versions, updater state and WordPress/PHP versions.
	const CAP_HEALTH = 'colandb_mcp_view_site_health';

	public static function caps() {
		return array( self::CAP_CONNECT, self::CAP_PENDING, self::CAP_HEALTH );
	}

	public static function init() {
		// Activation hooks don't run when the updater installs a new version,
		// so re-check the role whenever the stored version differs.
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ) );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'colandb_mcp_version' ) !== COLANDB_MCP_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		$caps = array( 'read' => true );
		foreach ( self::caps() as $cap ) {
			$caps[ $cap ] = true;
		}

		remove_role( self::ROLE ); // Re-create so a removed capability really goes.
		add_role( self::ROLE, __( 'Claude Agent', 'colandb-mcp' ), $caps );

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		update_option( 'colandb_mcp_version', COLANDB_MCP_VERSION, false );
	}
}
