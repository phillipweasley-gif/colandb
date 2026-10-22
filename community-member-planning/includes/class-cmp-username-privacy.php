<?php
/**
 * Keeps account usernames out of public view (0.38.1).
 *
 * WordPress shows the login name ("user_nicename") of anyone who has
 * published something: a users sitemap, /author/<name>/ pages, ?author=<id>
 * redirects and the public users API (/wp-json/wp/v2/users). On colandb.com
 * that listed the administrators and every member who had submitted an
 * event (owner, 2026-10-22, while checking SEO). Login names help password
 * guessing and can identify members, so for visitors and members:
 *
 * - the users sitemap is switched off (WordPress's own and Yoast SEO's);
 * - author pages and ?author= show "not found" without redirecting to the name;
 * - links to author pages point to the home page;
 * - /wp/v2/users answers "not allowed" (members still get /wp/v2/users/me);
 * - oEmbed data carries the site's name instead of the author's.
 *
 * People who can edit posts (administrators, calendar managers) keep it all,
 * since the WordPress editor uses the users API for its author picker.
 *
 * Filter cmp_show_usernames( $allowed ) to change who keeps it.
 */
defined( 'ABSPATH' ) || exit;

final class CMP_Username_Privacy {

	public static function init() {
		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'sitemap_provider' ), 10, 2 );
		add_filter( 'wpseo_sitemap_exclude_author', '__return_empty_array' );
		add_filter( 'request', array( __CLASS__, 'request' ) );
		add_filter( 'author_link', array( __CLASS__, 'author_link' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest' ), 10, 3 );
		add_filter( 'oembed_response_data', array( __CLASS__, 'oembed' ) );
	}

	public static function allowed() {
		return (bool) apply_filters( 'cmp_show_usernames', current_user_can( 'edit_posts' ) || current_user_can( 'list_users' ) );
	}

	public static function sitemap_provider( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}

	/**
	 * Author queries become "not found" before WordPress looks the name up,
	 * so ?author=5 can't redirect to /author/<name>/ either.
	 */
	public static function request( $vars ) {
		if ( is_admin() || ( empty( $vars['author'] ) && empty( $vars['author_name'] ) ) || self::allowed() ) {
			return $vars;
		}
		unset( $vars['author'], $vars['author_name'] );
		$vars['error'] = '404';
		return $vars;
	}

	public static function author_link( $link ) {
		return self::allowed() ? $link : home_url( '/' );
	}

	public static function rest( $result, $server, $request ) {
		$route = untrailingslashit( $request->get_route() );
		if ( null !== $result || 0 !== strpos( $route, '/wp/v2/users' ) || self::allowed() ) {
			return $result;
		}
		if ( '/wp/v2/users/me' === $route && is_user_logged_in() ) {
			return $result;
		}
		return new WP_Error( 'rest_user_cannot_view', __( 'Sorry, you are not allowed to list users.', 'cmp' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public static function oembed( $data ) {
		if ( isset( $data['author_name'] ) ) {
			$data['author_name'] = get_bloginfo( 'name' );
			$data['author_url']  = home_url( '/' );
		}
		return $data;
	}
}
