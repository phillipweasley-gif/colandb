<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Need help?" (0.34.0). Owner, 2026-10-21: a "Need Help?" link below the
 * member box in the upper right corner, for people to send feedback or
 * report issues.
 *
 * - CMP_Account_Bar shows a small "Need help?" link under the account box,
 *   signed in or out. It opens the page holding [cmp_feedback], passing
 *   the page the visitor was on (?from=).
 * - [cmp_feedback]: a question, something not working, or feedback/an
 *   idea, with the message, the page and (for a problem) the browser. A
 *   signed-in member's account email is used; anyone else gives one.
 *   Honeypot, signed timestamp (3 seconds to 8 days) and 5 a day per
 *   address or email, like CMP_Image_Reports. Never cached (0.32.1 lesson).
 * - The site's admin email gets each one (Reply-To: the sender) and the
 *   sender gets a confirmation. Users → Help requests lists them; the team
 *   marks one done, optionally emailing a reply.
 * - Kept 2 years (CMP_Retention, 'feedback'); export and erase by email.
 */
class CMP_Feedback {

	const SHORTCODE = 'cmp_feedback';
	const ACTION    = 'cmp_feedback';
	const PAGE      = 'cmp-help-requests';
	const NONCE     = 'cmp_feedback_admin';
	const DAY_LIMIT = 5;
	const MIN_SECS  = 3;

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'form_html' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_cmp_feedback_admin', array( __CLASS__, 'handle_admin' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function t() {
		return CMP_Install::table( 'feedback' );
	}

	public static function kinds() {
		return array(
			'problem'  => __( 'Something isn\'t working', 'cmp' ),
			'question' => __( 'I have a question', 'cmp' ),
			'feedback' => __( 'Feedback or an idea', 'cmp' ),
		);
	}

	/** The published page holding the form, or ''. */
	public static function page_url() {
		$id = (int) get_option( 'cmp_feedback_page_id' );
		if ( ! $id || 'publish' !== get_post_status( $id ) ) {
			global $wpdb;
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID LIMIT 1", '%[' . self::SHORTCODE . '%' ) );
			update_option( 'cmp_feedback_page_id', $id, false );
		}
		return $id ? get_permalink( $id ) : '';
	}

	/** The "Need help?" link for the account bar, pointing back at this page. */
	public static function link_url() {
		$url = self::page_url();
		if ( '' === $url ) {
			return '';
		}
		$here = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$here = preg_replace( '/[?&](cmp_[a-z_]+|_wpnonce|token|key)=[^&]*/', '', (string) $here ); // Never pass tokens along.
		$on_form = is_singular() && (int) get_queried_object_id() === (int) get_option( 'cmp_feedback_page_id' );
		return '' !== $here && ! $on_form ? add_query_arg( 'from', rawurlencode( $here ), $url ) : $url;
	}

	public static function no_cache() {
		$post = is_singular() ? get_queried_object() : null;
		if ( ! $post || ! isset( $post->post_content ) || ! has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header_remove( 'ec-cdn-cache-control' );
		header( 'Cache-Control: no-store, private, max-age=0' );
	}

	private static function stamp( $t ) {
		return $t . '.' . substr( hash_hmac( 'sha256', 'cmp-fb|' . $t, wp_salt( 'nonce' ) ), 0, 16 );
	}

	private static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return hash_hmac( 'sha256', 'ip|' . $ip, wp_salt( 'auth' ) );
	}

	/* Public form */

	public static function form_html() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['cmp_fb'] ) ? sanitize_key( wp_unslash( $_GET['cmp_fb'] ) ) : '';
		$from  = isset( $_GET['from'] ) ? mb_substr( sanitize_text_field( rawurldecode( wp_unslash( $_GET['from'] ) ) ), 0, 300 ) : '';
		// phpcs:enable
		$user  = wp_get_current_user();
		$msgs  = array(
			'sent'    => array( 'success', __( 'Thank you! Your message was sent to the COL&B team, and we\'ve emailed you a copy. We\'ll reply by email if we need to.', 'cmp' ) ),
			'invalid' => array( 'error', __( 'Your message wasn\'t sent. Please choose what it\'s about, write a message and give an email address we can reply to.', 'cmp' ) ),
			'limit'   => array( 'error', __( 'You\'ve sent several messages today. Please email us instead so nothing gets missed.', 'cmp' ) ),
		);
		$html  = '<style>.cmp-feedback{max-width:760px;margin:0 auto}.cmp-feedback label{display:block;font-weight:600;margin-bottom:4px}.cmp-feedback .cmp-fb-radio{display:block;margin:6px 0;font-weight:400}.cmp-feedback fieldset{border:1px solid rgba(255,255,255,.25);border-radius:8px;padding:10px 14px;margin:0 0 14px}.cmp-feedback textarea,.cmp-feedback input[type=text],.cmp-feedback input[type=email]{width:100%;box-sizing:border-box}.cmp-feedback .cmp-fb-notice{padding:12px 14px;border-radius:8px;border:2px solid #E1F577}.cmp-feedback .cmp-fb-error{border-color:#FF50D7}.cmp-feedback .cmp-fb-small{font-size:14px;opacity:.85}</style>';
		$html .= '<div class="cmp-feedback" id="cmp-feedback">';
		if ( isset( $msgs[ $state ] ) ) {
			$html .= '<p class="cmp-fb-notice cmp-fb-' . esc_attr( $msgs[ $state ][0] ) . '" role="' . ( 'success' === $msgs[ $state ][0] ? 'status' : 'alert' ) . '">' . esc_html( $msgs[ $state ][1] ) . '</p>';
			if ( 'sent' === $state ) {
				return $html . '</div>';
			}
		}
		$kinds = '';
		foreach ( self::kinds() as $k => $label ) {
			$kinds .= '<label class="cmp-fb-radio"><input type="radio" name="kind" value="' . esc_attr( $k ) . '" required /> ' . esc_html( $label ) . '</label>';
		}
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cmp-fb-form">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />'
			. '<input type="hidden" name="t" value="' . esc_attr( self::stamp( time() ) ) . '" />'
			. '<input type="hidden" name="back" value="' . esc_url( get_permalink() ) . '" />'
			. '<p aria-hidden="true" style="position:absolute;left:-9999px"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off" /></label></p>'
			. '<fieldset><legend>' . esc_html__( 'What\'s it about?', 'cmp' ) . ' *</legend>' . $kinds . '</fieldset>'
			. '<p><label for="cmp_fb_msg">' . esc_html__( 'Your message', 'cmp' ) . ' *</label><textarea id="cmp_fb_msg" name="message" rows="6" maxlength="5000" required placeholder="' . esc_attr__( 'What happened, or what would you like to tell us? If something isn\'t working, what did you expect and what did you see?', 'cmp' ) . '"></textarea></p>'
			. '<p><label for="cmp_fb_page">' . esc_html__( 'Which page is it about? (optional)', 'cmp' ) . '</label><input type="text" id="cmp_fb_page" name="page" maxlength="300" value="' . esc_attr( $from ) . '" /></p>';
		if ( $user->exists() ) {
			$html .= '<p class="cmp-fb-small">' . esc_html( sprintf( /* translators: %s: email */ __( 'We\'ll reply to %s, the email on your account.', 'cmp' ), $user->user_email ) ) . '</p>';
		} else {
			$html .= '<p><label for="cmp_fb_email">' . esc_html__( 'Your email, so we can reply', 'cmp' ) . ' *</label><input type="email" id="cmp_fb_email" name="email" maxlength="190" required autocomplete="email" /></p>'
				. '<p><label for="cmp_fb_name">' . esc_html__( 'Your name (optional)', 'cmp' ) . '</label><input type="text" id="cmp_fb_name" name="name" maxlength="100" autocomplete="name" /></p>';
		}
		$html .= '<p><button type="submit" class="cmp-btn">' . esc_html__( 'Send', 'cmp' ) . '</button></p>'
			. '<p class="cmp-fb-small">' . esc_html__( 'Only the COL&B site team sees this. If something isn\'t working, we also receive the type of browser you\'re using, to help us fix it. Please don\'t include passwords or private photos.', 'cmp' ) . '</p>'
			. '</form></div>';
		return $html;
	}

	public static function handle() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- a public form; spam is limited by the honeypot, timing and daily limits.
		$back = isset( $_POST['back'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['back'] ) ), home_url( '/' ) ) : home_url( '/' );
		$go   = function ( $state ) use ( $back ) {
			wp_safe_redirect( add_query_arg( 'cmp_fb', $state, $back ) . '#cmp-feedback' );
			exit;
		};
		$p = function ( $k, $max, $multi = false ) {
			$v = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$v = $multi ? sanitize_textarea_field( (string) $v ) : sanitize_text_field( (string) $v );
			return mb_substr( trim( $v ), 0, $max );
		};
		$stamp = $p( 't', 64 );
		$t     = (int) strtok( $stamp, '.' );
		if ( '' !== $p( 'website', 200 ) || ! hash_equals( self::stamp( $t ), $stamp ) || time() - $t < self::MIN_SECS || time() - $t > 8 * DAY_IN_SECONDS ) {
			$go( 'invalid' );
		}
		$user = wp_get_current_user();
		$row  = array(
			'user_id' => (int) $user->ID,
			'kind'    => $p( 'kind', 12 ),
			'message' => $p( 'message', 5000, true ),
			'page'    => $p( 'page', 300 ),
			'email'   => $user->exists() ? $user->user_email : sanitize_email( $p( 'email', 190 ) ),
			'name'    => $user->exists() ? $user->display_name : $p( 'name', 100 ),
		);
		// phpcs:enable
		if ( ! isset( self::kinds()[ $row['kind'] ] ) || mb_strlen( $row['message'] ) < 3 || ! is_email( $row['email'] ) ) {
			$go( 'invalid' );
		}
		$ip = self::ip_hash();
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t() . ' WHERE ( ip_hash = %s OR email = %s ) AND created_at > %s', $ip, $row['email'], gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ) >= self::DAY_LIMIT ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$go( 'limit' );
		}
		$ua   = 'problem' === $row['kind'] && isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 300 ) : '';
		$row += array( 'user_agent' => $ua, 'ip_hash' => $ip, 'status' => 'new', 'created_at' => gmdate( 'Y-m-d H:i:s' ) );
		$wpdb->insert( self::t(), $row );
		$id   = (int) $wpdb->insert_id;
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$kind = self::kinds()[ $row['kind'] ];
		/* translators: 1: site, 2: kind, 3: number */
		$subject = sprintf( __( '[%1$s] Help request %3$d: %2$s', 'cmp' ), $site, $kind, $id );
		$body    = sprintf( /* translators: 1: kind, 2: from, 3: page, 4: message, 5: browser, 6: admin link */ __( "%1\$s\nFrom: %2\$s\nPage: %3\$s\n\n%4\$s\n\nBrowser: %5\$s\n\nReply to this email to answer them, or open: %6\$s", 'cmp' ), $kind, trim( $row['name'] . ' <' . $row['email'] . '>' ) . ( $row['user_id'] ? ' ' . __( '(member)', 'cmp' ) : '' ), '' !== $row['page'] ? $row['page'] : '-', $row['message'], '' !== $ua ? $ua : '-', admin_url( 'users.php?page=' . self::PAGE ) );
		wp_mail( get_option( 'admin_email' ), $subject, $body, array( 'Reply-To: ' . $row['email'] ) );
		/* translators: %s: site */
		wp_mail( $row['email'], sprintf( __( '[%s] We got your message', 'cmp' ), $site ), sprintf( /* translators: 1: number, 2: message */ __( "Thanks for getting in touch (number %1\$d). The COL&B team will look at it and reply by email if needed.\n\nYour message:\n%2\$s", 'cmp' ), $id, $row['message'] ) );
		$go( 'sent' );
	}

	/* Admin */

	public static function open_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t() . " WHERE status = 'new'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function menu() {
		$open = self::open_count();
		/* translators: %d: open requests */
		add_users_page( __( 'Help requests', 'cmp' ), $open ? sprintf( __( 'Help requests (%d)', 'cmp' ), $open ) : __( 'Help requests', 'cmp' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function render() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::t() . " ORDER BY status = 'new' DESC, id DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$msg  = isset( $_GET['cmp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['cmp_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap"><h1>' . esc_html__( 'Help requests', 'cmp' ) . '</h1>';
		if ( '' !== $msg ) {
			echo '<div class="notice notice-info"><p>' . esc_html( $msg ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Questions, problems and feedback sent with "Need help?". Each one was also emailed to the site admin address; replying to that email answers the sender directly.', 'cmp' ) . '</p>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing yet.', 'cmp' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Received', 'cmp' ) . '</th><th>' . esc_html__( 'Message', 'cmp' ) . '</th><th>' . esc_html__( 'Status', 'cmp' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$kind = isset( self::kinds()[ $r->kind ] ) ? self::kinds()[ $r->kind ] : $r->kind;
			echo '<tr><td>' . (int) $r->id . '</td><td>' . esc_html( wp_date( 'M j, g:i A', strtotime( $r->created_at . ' UTC' ) ) ) . '</td><td>'
				. '<p><strong>' . esc_html( $kind ) . '</strong>' . ( '' !== (string) $r->page ? ' · ' . esc_html( $r->page ) : '' ) . '</p><p>' . nl2br( esc_html( $r->message ) ) . '</p>'
				. '<p class="description">' . esc_html( trim( $r->name . ' <' . $r->email . '>' ) ) . ( $r->user_id ? ' · ' . esc_html__( 'member', 'cmp' ) : '' ) . ( '' !== (string) $r->user_agent ? '<br />' . esc_html( $r->user_agent ) : '' ) . '</p></td><td>';
			if ( 'new' === $r->status ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cmp_feedback_admin" /><input type="hidden" name="id" value="' . (int) $r->id . '" />' . wp_nonce_field( self::NONCE, '_wpnonce', true, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. '<p><label>' . esc_html__( 'Reply (optional, emailed to them)', 'cmp' ) . '<br /><textarea name="reply" rows="3" class="large-text" maxlength="5000"></textarea></label></p>'
					. '<p><button type="submit" class="button button-primary">' . esc_html__( 'Mark done', 'cmp' ) . '</button></p></form>';
			} else {
				echo esc_html__( 'Done', 'cmp' ) . '<br /><span class="description">' . esc_html( wp_date( 'M j, g:i A', strtotime( $r->handled_at . ' UTC' ) ) ) . '</span>' . ( '' !== (string) $r->note ? '<p>' . nl2br( esc_html( $r->note ) ) . '</p>' : '' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function handle_admin() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cmp' ) );
		}
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$r     = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', $id ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$reply = isset( $_POST['reply'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['reply'] ) ), 0, 5000 ) : '';
		$msg   = __( 'Nothing changed.', 'cmp' );
		if ( $r && 'new' === $r->status ) {
			$wpdb->update( self::t(), array( 'status' => 'done', 'note' => $reply, 'handled_at' => gmdate( 'Y-m-d H:i:s' ), 'handled_by' => get_current_user_id() ), array( 'id' => $r->id ) );
			if ( '' !== $reply ) {
				/* translators: 1: site, 2: number */
				wp_mail( $r->email, sprintf( __( '[%1$s] About your message %2$d', 'cmp' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $r->id ), $reply . "\n\n---\n" . __( 'You wrote:', 'cmp' ) . "\n" . $r->message, array( 'Reply-To: ' . get_option( 'admin_email' ) ) );
			}
			/* translators: %d: number */
			$msg = sprintf( __( 'Request %d marked done.', 'cmp' ), $r->id ) . ( '' !== $reply ? ' ' . __( 'Your reply was emailed.', 'cmp' ) : '' );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'cmp_msg' => rawurlencode( $msg ) ), admin_url( 'users.php' ) ) );
		exit;
	}

	/* Retention and privacy */

	public static function purge( $days ) {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function export_rows( $email ) {
		global $wpdb;
		$rows = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, kind, page, message FROM ' . self::t() . ' WHERE email = %s ORDER BY id', $email ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows[] = array( 'name' => __( 'Help request you sent', 'cmp' ), 'value' => sprintf( '%d · %s UTC · %s · %s · %s', $r->id, $r->created_at, $r->kind, $r->page, $r->message ) );
		}
		return $rows;
	}

	public static function erase( $email ) {
		global $wpdb;
		return (int) $wpdb->delete( self::t(), array( 'email' => $email ), array( '%s' ) );
	}
}
