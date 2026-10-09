<?php
/**
 * Plugin Name: COL&B Claude Connector
 * Description: Lets Claude work with this site through a small, read-only set of tools (published events, events awaiting review, site health), served as an MCP server at /wp-json/colandb/mcp. Claude signs in as its own low-privilege user ("Claude Agent" role) with an application password; nothing it can do publishes, deletes, or reads member data. Setup: Tools → Claude Connector.
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: RA Marketing
 * Text Domain: colandb-mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COLANDB_MCP_VERSION', '0.1.0' );
define( 'COLANDB_MCP_DIR', plugin_dir_path( __FILE__ ) );

require_once COLANDB_MCP_DIR . 'includes/class-colandb-mcp-role.php';
require_once COLANDB_MCP_DIR . 'includes/class-colandb-mcp-log.php';
require_once COLANDB_MCP_DIR . 'includes/class-colandb-mcp-abilities.php';
require_once COLANDB_MCP_DIR . 'includes/class-colandb-mcp-server.php';
require_once COLANDB_MCP_DIR . 'includes/class-colandb-mcp-admin.php';

register_activation_hook( __FILE__, array( 'COLANDB_MCP_Role', 'install' ) );

COLANDB_MCP_Role::init();
COLANDB_MCP_Abilities::init();
COLANDB_MCP_Server::init();
COLANDB_MCP_Admin::init();

// Let the COL&B Plugin Updater keep this plugin up to date too.
add_filter(
	'colandb_updater_plugins',
	static function ( $slugs ) {
		$slugs[] = 'colandb-mcp';
		return array_values( array_unique( $slugs ) );
	}
);
