<?php
/**
 * Plugin Name: COL&B Silent Auction
 * Description: Silent auctions for fundraising. The site team adds an auction and its items in wp-admin; signed-in supporters bid on the Auction page; when bidding closes each winner gets one WooCommerce order to pay online, and paid orders are added to the linked fundraiser on the Fundraising page.
 * Version: 0.1.0
 * Author: RA Marketing
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: csa
 */

defined( 'ABSPATH' ) || exit;

define( 'CSA_VERSION', '0.1.0' );
define( 'CSA_FILE', __FILE__ );
define( 'CSA_DIR', plugin_dir_path( __FILE__ ) );
define( 'CSA_URL', plugin_dir_url( __FILE__ ) );

require_once CSA_DIR . 'includes/class-csa-data.php';
require_once CSA_DIR . 'includes/class-csa-install.php';
require_once CSA_DIR . 'includes/class-csa-bids.php';
require_once CSA_DIR . 'includes/class-csa-admin.php';
require_once CSA_DIR . 'includes/class-csa-public.php';
require_once CSA_DIR . 'includes/class-csa-close.php';
require_once CSA_DIR . 'includes/class-csa-emails.php';

register_activation_hook( __FILE__, array( 'CSA_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CSA_Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'CSA_Install', 'maybe_upgrade' ) );
CSA_Data::init();
CSA_Bids::init();
CSA_Admin::init();
CSA_Public::init();
CSA_Close::init();

// The COL&B updater installs new releases of this plugin too.
add_filter(
	'colandb_updater_plugins',
	static function ( $slugs ) {
		$slugs[] = 'colandb-silent-auction';
		return array_values( array_unique( $slugs ) );
	}
);

// No tracking: the site's Privacy Policy promises no analytics. WooCommerce's
// "Order attribution" feature sets sbjs_* visitor cookies on every page, so
// it is kept off while this plugin is active.
add_filter( 'pre_option_woocommerce_feature_order_attribution_enabled', static function () { return 'no'; } );
add_filter( 'wc_order_attribution_allow_tracking', '__return_false' );
