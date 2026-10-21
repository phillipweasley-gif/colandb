<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pages with a form are never cached (1.46.1).
 *
 * The host's CDN (Elementor Hosting) adds `ec-cdn-cache-control: public,
 * max-age=300, s-maxage=604800` to every page and keeps signed-out copies for
 * up to 7 days, query strings included. A form's WordPress nonce lasts only
 * 12–24 hours, so a cached sign-in, sign-up or submission page eventually
 * hands out a dead nonce and the form fails. A cached page can also show one
 * visitor's "Thanks!" or error message to the next, and a guest edit page
 * would show the event as it was before the guest's own changes.
 *
 * Same treatment as Community Member Planning 0.32.1 (CMP_Image_Reports::no_cache()).
 *
 * As a second line of defence, form_nonce_ok() lets a signed-out visitor
 * through with an expired nonce on forms that have other protection.
 */
class CEC_No_Cache {

	/**
	 * Shortcodes (and Elementor widget names) whose pages must not be cached.
	 * Public fundraiser and meeting-notes lists have no forms and stay cached.
	 */
	const SHORTCODES = array(
		'cec_login',
		'cec_register',
		'cec_submit_event',
		'cec_submit_form', // Elementor widget for [cec_submit_event].
		'cec_manage_submission',
		'cec_subscribe',
		'cec_volunteer_form', // Shortcode and Elementor widget.
		'cec_submit_org',
		'cec_my_events',
		'cec_admin_dashboard',
		'cec_meeting_admin',
	);

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_no_cache' ), 1 );
	}

	/** True when the page being viewed renders one of the forms. */
	public static function page_has_form( $post = null ) {
		if ( null === $post ) {
			$post = is_singular() ? get_queried_object() : null;
		}
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		$elementor = (string) get_post_meta( $post->ID, '_elementor_data', true );
		/**
		 * Shortcodes (or Elementor widget names) whose pages are never cached.
		 *
		 * @param string[] $shortcodes
		 */
		foreach ( (array) apply_filters( 'cec_no_cache_shortcodes', self::SHORTCODES ) as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) || ( '' !== $elementor && false !== strpos( $elementor, $tag ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function maybe_no_cache() {
		if ( self::page_has_form() ) {
			self::send();
		}
	}

	/**
	 * Tell WordPress page caches, browsers and the host's CDN not to keep
	 * this response. Also called from the forms themselves, in case one sits
	 * somewhere page_has_form() can't see (it only works while headers
	 * haven't gone out yet).
	 */
	public static function send() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header_remove( 'ec-cdn-cache-control' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
	}

	/**
	 * Nonce check for a public form.
	 *
	 * A valid nonce always passes. A signed-in visitor needs a valid one. A
	 * signed-out visitor whose nonce has expired (a page opened, or cached,
	 * more than 12–24 hours ago) still gets through, provided the form sent
	 * a nonce at all. Use this only on forms with other protection: a
	 * honeypot, a password, a secret link, a rate limit or the approval
	 * queue. A signed-out nonce protects no session, which is why WordPress's
	 * own wp-login.php sign-in and sign-up forms have none.
	 *
	 * @param string $field  POST field holding the nonce.
	 * @param string $action Nonce action.
	 */
	public static function form_nonce_ok( $field, $action ) {
		$nonce = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' !== $nonce && wp_verify_nonce( $nonce, $action ) ) {
			return true;
		}
		return '' !== $nonce && ! is_user_logged_in();
	}
}
