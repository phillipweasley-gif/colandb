<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes the site's own navigation menus (including Elementor's Nav Menu
 * widget, which uses WordPress menus) follow the visitor's sign-in state:
 * once signed in, "Log In" becomes "Sign Out" and "Create an Account"
 * disappears. Without this, a signed-in visitor saw the same static links
 * and had no way to sign out outside the member area.
 *
 * Menu items are recognised by where they link (the events plugin's
 * sign-in, registration and calendar-manager pages, or wp-login.php), so
 * nothing has to be changed in the menu itself. Any other item can be
 * shown to one group only with the CSS class "cmp-signed-in-only" or
 * "cmp-signed-out-only" (Appearance → Menus → Screen Options → CSS Classes).
 *
 * Signed-in pages aren't cached by the host, so each visitor always gets
 * the right version.
 */
class CMP_Site_Menu {

	public static function init() {
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'filter' ), 20 );
	}

	private static function normalise( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( empty( $parts['path'] ) && empty( $parts['host'] ) ) {
			return '';
		}
		$path = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
		// ?page_id=… links (sites without pretty permalinks).
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			if ( ! empty( $q['page_id'] ) ) {
				$path .= '?page_id=' . (int) $q['page_id'];
			}
		}
		return strtolower( ( isset( $parts['host'] ) ? $parts['host'] : '' ) . $path );
	}

	private static function page_url( $key ) {
		return class_exists( 'CEC_Admin_Settings' ) ? self::normalise( CEC_Admin_Settings::get( $key ) ) : '';
	}

	/**
	 * @return string 'login' | 'register' | 'manager' | ''
	 */
	public static function kind( $url ) {
		$u = self::normalise( $url );
		if ( '' === $u ) {
			return '';
		}
		if ( false !== strpos( $u, 'wp-login.php' ) && false === strpos( (string) $url, 'action=register' ) ) {
			return 'login';
		}
		$map = array(
			'login'    => self::page_url( 'login_page_url' ),
			'register' => self::page_url( 'register_page_url' ),
			'manager'  => self::page_url( 'dashboard_page_url' ),
		);
		foreach ( $map as $kind => $page ) {
			if ( $page && $page === $u ) {
				return $kind;
			}
		}
		if ( false !== strpos( (string) $url, 'action=register' ) ) {
			return 'register';
		}
		return '';
	}

	public static function filter( $items ) {
		$signed_in = is_user_logged_in();
		$removed   = array();
		$out       = array();
		foreach ( $items as $item ) {
			$classes = array_filter( (array) $item->classes );
			$kind    = self::kind( $item->url );
			$parent  = (int) $item->menu_item_parent;
			$drop    = isset( $removed[ $parent ] )
				|| ( $signed_in && in_array( 'cmp-signed-out-only', $classes, true ) )
				|| ( ! $signed_in && in_array( 'cmp-signed-in-only', $classes, true ) )
				|| ( $signed_in && 'register' === $kind )
				// The calendar managers' dashboard is no use to anyone else once signed in.
				|| ( $signed_in && 'manager' === $kind && ! current_user_can( 'cec_manage_events' ) && ! current_user_can( 'manage_options' ) );
			if ( $drop ) {
				$removed[ (int) $item->ID ] = true;
				continue;
			}
			if ( $signed_in && 'login' === $kind ) {
				$item          = clone $item;
				$item->title   = __( 'Sign Out', 'cmp' );
				$item->url     = wp_logout_url( home_url( '/' ) );
				$item->classes = array_merge( $classes, array( 'cmp-sign-out' ) );
				$item->attr_title = '';
			}
			$out[] = $item;
		}
		return $out;
	}
}
