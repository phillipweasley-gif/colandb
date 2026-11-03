<?php
/**
 * Plugin Name: COL&B Telegram Event Intake
 * Description: Reads event announcements from an approved list of Telegram channels and puts them in the Community Events Calendar's review queue. Never publishes.
 * Version: 0.3.2
 * Author: RA Marketing
 * Requires PHP: 7.4
 * Requires Plugins: community-events-calendar
 * Text Domain: cti
 */

defined( 'ABSPATH' ) || exit;

define( 'CTI_VERSION', '0.3.2' );
define( 'CTI_DIR', plugin_dir_path( __FILE__ ) );
// The Community Events Calendar release that first shipped the intake API.
define( 'CTI_MIN_CEC_VERSION', '1.47.0' );

require_once CTI_DIR . 'includes/class-cti-config.php';
require_once CTI_DIR . 'includes/class-cti-install.php';
require_once CTI_DIR . 'includes/class-cti-log.php';
require_once CTI_DIR . 'includes/class-cti-telegram.php';
require_once CTI_DIR . 'includes/class-cti-receiver.php';
require_once CTI_DIR . 'includes/class-cti-extractors.php';
require_once CTI_DIR . 'includes/class-cti-normalizer.php';
require_once CTI_DIR . 'includes/class-cti-duplicates.php';
require_once CTI_DIR . 'includes/class-cti-notify.php';
require_once CTI_DIR . 'includes/class-cti-processor.php';
require_once CTI_DIR . 'includes/class-cti-admin.php';
require_once CTI_DIR . 'includes/class-cti-diagnostics.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once CTI_DIR . 'includes/class-cti-cli.php';
}

register_activation_hook( __FILE__, array( 'CTI_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CTI_Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'CTI_Install', 'maybe_upgrade' ) );
add_action( 'plugins_loaded', array( 'CTI_Receiver', 'init' ) );
add_action( 'plugins_loaded', array( 'CTI_Processor', 'init' ) );
add_action( 'plugins_loaded', array( 'CTI_Admin', 'init' ) );

// The COL&B updater installs new releases of this plugin too.
add_filter(
	'colandb_updater_plugins',
	static function ( $slugs ) {
		$slugs[] = 'colandb-telegram-intake';
		return array_values( array_unique( $slugs ) );
	}
);

/** True when the calendar's intake API is there and new enough. */
function cti_calendar_ready() {
	return defined( 'CEC_VERSION' ) && version_compare( CEC_VERSION, CTI_MIN_CEC_VERSION, '>=' ) && function_exists( 'cec_intake_create_event' );
}
