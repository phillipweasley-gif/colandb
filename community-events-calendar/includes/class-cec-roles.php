<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A "Calendar Manager" role that can approve/edit/reject events from the
 * front-end dashboard shortcode without ever needing wp-admin access.
 * Administrators keep full wp-admin access as before; this is additive.
 */
class CEC_Roles {

	const CAP  = 'cec_manage_events';
	const ROLE = 'cec_calendar_manager';

	public static function install() {
		add_role(
			self::ROLE,
			__( 'Calendar Manager', 'cec' ),
			array(
				'read'         => true,
				'upload_files' => true,
				self::CAP      => true,
			)
		);

		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAP ) ) {
			$admin->add_cap( self::CAP );
		}
	}

	public static function can_manage() {
		return current_user_can( self::CAP );
	}

	/**
	 * Nobody without manage_options is a real wp-admin user, whether
	 * they're a delegated Calendar Manager or a plain member/submitter —
	 * send them back to the front end whenever they land in wp-admin,
	 * regardless of how they got there (a bookmarked URL, a core WP flow
	 * like lost-password that this plugin doesn't otherwise redirect).
	 */
	public static function maybe_redirect_from_wp_admin() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! is_user_logged_in() || current_user_can( 'manage_options' ) ) {
			return;
		}

		global $pagenow;
		$allowed_pages = array( 'admin-ajax.php', 'admin-post.php', 'profile.php', 'async-upload.php', 'media-upload.php' );
		if ( in_array( $pagenow, $allowed_pages, true ) ) {
			return;
		}

		wp_safe_redirect( self::front_end_home_for_current_user() );
		exit;
	}

	/**
	 * Where a non-admin belongs on the front end: the manager dashboard for
	 * a delegated Calendar Manager, the submission page for everyone else.
	 */
	private static function front_end_home_for_current_user() {
		if ( current_user_can( self::CAP ) ) {
			$url = CEC_Admin_Settings::get( 'dashboard_page_url' );
		} else {
			$url = CEC_Admin_Settings::get( 'submit_page_url' );
		}
		return $url ? $url : home_url( '/' );
	}

	/**
	 * Keeps a non-admin from ever landing in wp-admin in the first place —
	 * not just catching them once they're already there. Matters most
	 * right after a core WP flow this plugin doesn't otherwise control,
	 * e.g. resetting a password via wp-login.php's lost-password screen,
	 * which by default sends a Subscriber to wp-admin/profile.php.
	 */
	public static function login_redirect_url( $redirect_to, $requested_redirect_to, $user ) {
		if ( ! ( $user instanceof WP_User ) || user_can( $user, 'manage_options' ) ) {
			return $redirect_to;
		}
		// Respect an explicit non-wp-admin destination already requested
		// (e.g. this plugin's own login/register forms already pass one).
		if ( $requested_redirect_to && false === strpos( $requested_redirect_to, 'wp-admin' ) ) {
			return $requested_redirect_to;
		}
		$manager = user_can( $user, self::CAP );
		return $manager
			? ( CEC_Admin_Settings::get( 'dashboard_page_url' ) ?: home_url( '/' ) )
			: ( CEC_Admin_Settings::get( 'submit_page_url' ) ?: home_url( '/' ) );
	}

	public static function hide_admin_bar( $show ) {
		if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return $show;
	}
}
