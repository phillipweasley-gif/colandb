<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [cmp_account_bar]: the sign-in controls for the site header (top right).
 * Signed out: "Log In" and "Create an Account". Signed in: "Hello, <name>"
 * opening a menu with the member area, profile, account, the calendar
 * managers' dashboard (managers only) and "Sign Out".
 *
 * Built on <details>/<summary>, so it opens with a click, tap, Enter or
 * Space without any script; a few lines of script add closing with Escape
 * or a click elsewhere. Signed-in pages aren't cached by the host, and
 * Elementor never caches Shortcode widget output, so nobody ever sees
 * another visitor's name.
 */
class CMP_Account_Bar {

	public static function init() {
		add_shortcode( 'cmp_account_bar', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** Small and site-wide: the bar sits in the header on every page. */
	public static function assets() {
		wp_enqueue_style( 'cmp-account-bar', CMP_URL . 'assets/css/account-bar.css', array(), CMP_VERSION );
		wp_enqueue_script( 'cmp-account-bar', CMP_URL . 'assets/js/account-bar.js', array(), CMP_VERSION, true );
	}

	private static function cec_url( $key ) {
		return class_exists( 'CEC_Admin_Settings' ) ? (string) CEC_Admin_Settings::get( $key ) : '';
	}

	/**
	 * The signed-in menu, in order. Filterable so later increments can add
	 * items (connections in 2.3).
	 *
	 * @return array of array( label, url, extra-class, badge )
	 */
	public static function items( WP_User $user ) {
		$items = array();
		if ( (int) CMP_Settings::get( 'member_page_id' ) ) {
			$member = CMP_Access::is_member( $user->ID );
			$unread = $member ? CMP_Notifications::unread_count( $user->ID ) : 0;
			$items[] = array( $member ? __( 'Member Area', 'cmp' ) : __( 'Finish joining the member area', 'cmp' ), CMP_Settings::member_page_url(), '', $unread );
			if ( $member ) {
				$items[] = array( __( 'My Profile', 'cmp' ), CMP_Profiles::url(), '', 0 );
				$items[] = array( __( 'My Calendar', 'cmp' ), CMP_Calendar::url(), '', 0 ); // 0.17.3
			}
			$items[] = array( __( 'Account Settings', 'cmp' ), CMP_Account::url(), '', 0 );
		}
		$dashboard = self::cec_url( 'dashboard_page_url' );
		if ( $dashboard && ( user_can( $user, 'cec_manage_events' ) || user_can( $user, 'manage_options' ) ) ) {
			$items[] = array( __( 'Calendar Admin', 'cmp' ), $dashboard, '', 0 );
		}
		if ( user_can( $user, 'manage_options' ) ) {
			$items[] = array( __( 'WordPress Dashboard', 'cmp' ), admin_url(), '', 0 );
		}
		/**
		 * Filters the account bar's menu (before "Sign Out").
		 *
		 * @param array   $items array( label, url, class, badge count )
		 * @param WP_User $user
		 */
		$items   = apply_filters( 'cmp_account_bar_items', $items, $user );
		$items[] = array( __( 'Sign Out', 'cmp' ), wp_logout_url( home_url( '/' ) ), 'cmp-bar-signout', 0 );
		return $items;
	}

	public static function render() {
		if ( ! is_user_logged_in() ) {
			$login    = self::cec_url( 'login_page_url' );
			$register = self::cec_url( 'register_page_url' );
			$html     = '<nav class="cmp-account-bar is-signed-out" aria-label="' . esc_attr__( 'Account', 'cmp' ) . '">';
			$html    .= '<a class="cmp-bar-link" href="' . esc_url( $login ? $login : wp_login_url() ) . '">' . esc_html__( 'Log In', 'cmp' ) . '</a>';
			if ( get_option( 'users_can_register' ) || $register ) {
				$html .= '<a class="cmp-bar-link cmp-bar-primary" href="' . esc_url( $register ? $register : wp_registration_url() ) . '">' . esc_html__( 'Create an Account', 'cmp' ) . '</a>';
			}
			return $html . '</nav>';
		}

		$user   = wp_get_current_user();
		$name   = $user->first_name ? $user->first_name : $user->display_name;
		$items  = self::items( $user );
		$badge  = isset( $items[0][3] ) ? (int) $items[0][3] : 0;
		$avatar = CMP_Access::is_member( $user->ID ) ? CMP_Profile_Images::get( $user->ID, 'avatar' ) : null;

		$html  = '<nav class="cmp-account-bar is-signed-in" aria-label="' . esc_attr__( 'Account', 'cmp' ) . '">';
		$html .= '<details class="cmp-bar-menu"><summary class="cmp-bar-toggle">';
		$html .= $avatar
			? '<img class="cmp-bar-avatar" src="' . esc_url( CMP_Profile_Images::url( $user->ID, 'avatar', $avatar ) ) . '" alt="" width="28" height="28" />'
			: '<span class="cmp-bar-avatar cmp-bar-initial" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ) . '</span>';
		/* translators: %s: the member's first or display name */
		$html .= '<span class="cmp-bar-hello">' . esc_html( sprintf( __( 'Hello, %s', 'cmp' ), $name ) ) . '</span>';
		if ( $badge ) {
			/* translators: %d: number of unread notifications */
			$html .= '<span class="cmp-bar-badge"><span aria-hidden="true">' . (int) $badge . '</span><span class="screen-reader-text">' . esc_html( sprintf( _n( '%d unread notification', '%d unread notifications', $badge, 'cmp' ), $badge ) ) . '</span></span>';
		}
		$html .= '<span class="cmp-bar-caret" aria-hidden="true"></span></summary><ul class="cmp-bar-list">';
		foreach ( $items as $item ) {
			$html .= '<li><a href="' . esc_url( $item[1] ) . '"' . ( $item[2] ? ' class="' . esc_attr( $item[2] ) . '"' : '' ) . '>' . esc_html( $item[0] );
			if ( ! empty( $item[3] ) ) {
				$html .= ' <span class="cmp-bar-badge" aria-hidden="true">' . (int) $item[3] . '</span>';
			}
			$html .= '</a></li>';
		}
		return $html . '</ul></details></nav>';
	}
}
