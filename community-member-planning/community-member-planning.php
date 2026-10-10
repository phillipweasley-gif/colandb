<?php
/**
 * Plugin Name: Community Member Planning
 * Description: Private member area for the Community Events Calendar: member access (verified email + 18+ self-attestation), in-app notifications, an append-only audit log, and a front-end Account page (name, email, password, devices, privacy requests) that replaces the WordPress dashboard for members, and member profiles with per-field visibility and private profile/cover photos. My Calendars, connections, sharing and the member directory are added in later releases. Reads public events only through the Community Events Calendar's read-only API. Shortcode: [cmp_member_area]. Setup: Settings → Member Planning.
 * Version: 0.20.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: RA Marketing
 * Text Domain: cmp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CMP_VERSION', '0.20.0' );
define( 'CMP_DB_VERSION', 16 );
define( 'CMP_FILE', __FILE__ );
define( 'CMP_DIR', plugin_dir_path( __FILE__ ) );
define( 'CMP_URL', plugin_dir_url( __FILE__ ) );

// The Community Events Calendar release that first shipped cec_get_public_event().
define( 'CMP_MIN_CEC_VERSION', '1.26.0' );

require_once CMP_DIR . 'includes/class-cmp-install.php';
require_once CMP_DIR . 'includes/class-cmp-settings.php';
require_once CMP_DIR . 'includes/class-cmp-audit.php';
require_once CMP_DIR . 'includes/class-cmp-access.php';
require_once CMP_DIR . 'includes/class-cmp-birth-date.php';
require_once CMP_DIR . 'includes/class-cmp-email-verification.php';
require_once CMP_DIR . 'includes/class-cmp-notifications.php';
require_once CMP_DIR . 'includes/class-cmp-rest.php';
require_once CMP_DIR . 'includes/class-cmp-member-area.php';
require_once CMP_DIR . 'includes/class-cmp-account.php';
require_once CMP_DIR . 'includes/class-cmp-profile-fields.php';
require_once CMP_DIR . 'includes/class-cmp-profiles.php';
require_once CMP_DIR . 'includes/class-cmp-profile-images.php';
require_once CMP_DIR . 'includes/class-cmp-onboarding.php';
require_once CMP_DIR . 'includes/class-cmp-dynamics.php';
require_once CMP_DIR . 'includes/class-cmp-homework.php';
require_once CMP_DIR . 'includes/class-cmp-chastity.php';
require_once CMP_DIR . 'includes/class-cmp-feed.php';
require_once CMP_DIR . 'includes/class-cmp-messages.php';
require_once CMP_DIR . 'includes/class-cmp-follows.php';
require_once CMP_DIR . 'includes/class-cmp-groups.php';
require_once CMP_DIR . 'includes/class-cmp-directory.php';
require_once CMP_DIR . 'includes/class-cmp-nods.php';
require_once CMP_DIR . 'includes/class-cmp-calendar.php';
require_once CMP_DIR . 'includes/class-cmp-app.php';
require_once CMP_DIR . 'includes/class-cmp-reminders.php';
require_once CMP_DIR . 'includes/class-cmp-retention.php';
require_once CMP_DIR . 'includes/class-cmp-profile-options-admin.php';
require_once CMP_DIR . 'includes/class-cmp-site-menu.php';
require_once CMP_DIR . 'includes/class-cmp-account-bar.php';

register_activation_hook( __FILE__, array( 'CMP_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CMP_Retention', 'unschedule' ) );
register_deactivation_hook( __FILE__, array( 'CMP_Reminders', 'unschedule' ) );

final class CMP_Plugin {

	public static function init() {
		add_action( 'plugins_loaded', array( 'CMP_Install', 'maybe_upgrade' ) );
		add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );

		CMP_Settings::init();
		CMP_Email_Verification::init();
		CMP_Birth_Date::init();
		CMP_Rest::init();
		CMP_Member_Area::init();
		CMP_Account::init();
		CMP_Profiles::init();
		CMP_Profile_Images::init();
		CMP_Onboarding::init();
		CMP_Dynamics::init();
		CMP_Homework::init();
		CMP_Chastity::init();
		CMP_Feed::init();
		CMP_Messages::init();
		CMP_Follows::init();
		CMP_Groups::init();
		CMP_Directory::init();
		CMP_Nods::init();
		CMP_Calendar::init();
		CMP_App::init();
		CMP_Reminders::init();
		CMP_Retention::init();
		CMP_Profile_Options_Admin::init();
		CMP_Site_Menu::init();
		CMP_Account_Bar::init();
	}

	/**
	 * The member area itself works without the events plugin, but every
	 * calendar feature (2.2 onward) reads events through its API, so an
	 * administrator is told as soon as it's missing or too old.
	 */
	public static function dependency_notice() {
		if ( ! current_user_can( 'activate_plugins' ) || self::events_api_available() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: minimum Community Events Calendar version */
					__( 'Community Member Planning needs Community Events Calendar %s or newer for its calendar features. The member area still works without it.', 'cmp' ),
					CMP_MIN_CEC_VERSION
				)
			)
		);
	}

	public static function events_api_available() {
		return function_exists( 'cec_get_public_event' )
			&& defined( 'CEC_VERSION' )
			&& version_compare( CEC_VERSION, CMP_MIN_CEC_VERSION, '>=' );
	}
}

CMP_Plugin::init();
