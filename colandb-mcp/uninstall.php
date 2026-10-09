<?php
/**
 * Runs only when the plugin is deleted (never on deactivation): removes the
 * Claude Agent role, the connector's capabilities and its tool-call log.
 * Users who had the role keep their accounts, with no role.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

remove_role( 'colandb_agent' );
$colandb_mcp_admin = get_role( 'administrator' );
if ( $colandb_mcp_admin ) {
	foreach ( array( 'colandb_mcp_connect', 'colandb_mcp_view_pending_events', 'colandb_mcp_view_site_health' ) as $colandb_mcp_cap ) {
		$colandb_mcp_admin->remove_cap( $colandb_mcp_cap );
	}
}
delete_option( 'colandb_mcp_log' );
delete_option( 'colandb_mcp_version' );
