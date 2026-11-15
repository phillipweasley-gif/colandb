<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The page people reach from a poster's QR code (0.40.0): [cmp_agreement
 * key="mosh-pit"]. Shows the rules, then:
 *
 * - signed in: tick "I agree" and confirm (asks a date of birth first if the
 *   account has none, like the member area's one-time step);
 * - signed out: enter an email. A known email asks for its password, signs
 *   in and confirms. A new email joins COL&B free (name, date of birth,
 *   password; 18+ only, same rules as [cec_register]) and confirms.
 * - Once agreed, the page shows a confirmation a monitor can check: a tick,
 *   the name and the time. It lasts until the rules change, or for the
 *   agreement's renew_hours (12 for the mosh pit: once per event night).
 *
 * Every step posts back to the page itself (handled on template_redirect),
 * so the email never appears in a URL. The page is never cached.
 *
 * `?cmp_agree=<key>` on any address goes to the page holding that
 * agreement: the permanent link printed as the QR code, so the page can be
 * renamed or moved without reprinting posters.
 */
class CMP_Agreement_Form {

	const SHORTCODE  = 'cmp_agreement';
	const MIN_SECS   = 2;          // a person can't fill in the form faster
	const MAX_AGE    = DAY_IN_SECONDS;
	const LOOKUPS    = 20;         // email look-ups per address per 10 minutes

	/** What the shortcode shows after a post on this request. */
	private static $view = array();

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'template_redirect', array( __CLASS__, 'permanent_link' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/* ------------------------------------------------------------------
	 * Routing
	 * ---------------------------------------------------------------- */

	/** ?cmp_agree=<key> → the page holding that agreement. */
	public static function permanent_link() {
		if ( ! isset( $_GET['cmp_agree'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$key = CMP_Agreements::clean_key( wp_unslash( $_GET['cmp_agree'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$id  = '' !== $key ? CMP_Agreements::page_id( $key ) : 0;
		nocache_headers();
		if ( $id ) {
			wp_safe_redirect( get_permalink( $id ), 302 );
			exit;
		}
		wp_die(
			esc_html__( 'This agreement isn\'t on the website yet. Please ask a monitor or event host.', 'cmp' ),
			esc_html__( 'Not found', 'cmp' ),
			array( 'response' => 404 )
		);
	}

	private static function current_key() {
		$post = is_singular() ? get_queried_object() : null;
		if ( ! $post || ! isset( $post->post_content ) || ! has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			return null;
		}
		if ( preg_match( '/' . get_shortcode_regex( array( self::SHORTCODE ) ) . '/', $post->post_content, $m ) ) {
			$atts = shortcode_parse_atts( $m[3] );
			return CMP_Agreements::clean_key( is_array( $atts ) && isset( $atts['key'] ) ? $atts['key'] : '' );
		}
		return null;
	}

	public static function assets() {
		if ( null !== self::current_key() ) {
			wp_enqueue_style( 'cmp-agreement', CMP_URL . 'assets/css/agreement.css', array(), CMP_VERSION );
		}
	}

	private static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header_remove( 'ec-cdn-cache-control' );
		header( 'Cache-Control: no-store, private, max-age=0' );
	}

	private static function page_url( $args = array() ) {
		// Each step opens at the form, not back at the top of the rules.
		return add_query_arg( $args, get_permalink() ) . '#cmp-ag-box';
	}

	/* ------------------------------------------------------------------
	 * Handling posts
	 * ---------------------------------------------------------------- */

	private static function stamp( $t ) {
		return $t . '.' . substr( hash_hmac( 'sha256', 'cmp-ag|' . $t, wp_salt( 'nonce' ) ), 0, 16 );
	}

	private static function stamp_ok( $min = self::MIN_SECS ) {
		$s = isset( $_POST['t'] ) ? sanitize_text_field( wp_unslash( $_POST['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$t = (int) strtok( $s, '.' );
		return hash_equals( self::stamp( $t ), $s ) && time() - $t >= $min && time() - $t <= self::MAX_AGE;
	}

	private static function post( $k, $max = 200 ) {
		return isset( $_POST[ $k ] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) ), 0, $max ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	private static function fail( $step, $error, $extra = array() ) {
		self::$view = array( 'step' => $step, 'error' => $error ) + $extra;
	}

	private static function done( $state ) {
		wp_safe_redirect( self::page_url( array( 'cmp_ag' => $state ) ) );
		exit;
	}

	public static function handle() {
		$key = self::current_key();
		if ( null === $key ) {
			return;
		}
		self::no_cache();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['cmp_ag_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$a = CMP_Agreements::get( $key );
		if ( ! $a || empty( $a['active'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['cmp_ag_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' !== self::post( 'website' ) ) { // Honeypot: say nothing useful.
			self::fail( 'email', 'invalid' );
			return;
		}
		switch ( $action ) {
			case 'confirm':
				self::handle_confirm( $key );
				break;
			case 'email':
				self::handle_email();
				break;
			case 'signin':
				self::handle_signin( $key );
				break;
			case 'join':
				self::handle_join( $key );
				break;
		}
	}

	private static function agreed_box_ticked() {
		return ! empty( $_POST['agree'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers check the nonce or stamp.
	}

	/** Signed in: tick and confirm (with a date of birth if none is on file). */
	private static function handle_confirm( $key ) {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! wp_verify_nonce( self::post( '_cmp_ag' ), 'cmp_ag_confirm_' . $key ) ) {
			self::fail( 'confirm', 'expired' );
			return;
		}
		if ( CMP_Birth_Date::is_blocked( $user_id ) ) {
			self::fail( 'confirm', 'blocked' );
			return;
		}
		if ( ! CMP_Birth_Date::qualifies( $user_id ) ) {
			$dob = CMP_Birth_Date::from_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
			if ( is_wp_error( $dob ) ) {
				if ( 'dob_under_18' === $dob->get_error_code() ) {
					CMP_Birth_Date::block( $user_id );
					self::fail( 'confirm', 'blocked' );
					return;
				}
				self::fail( 'confirm', $dob->get_error_code() );
				return;
			}
			CMP_Birth_Date::store( $user_id, $dob, 'agreement' );
			CMP_Access::record_attestation( $user_id );
		}
		if ( ! self::agreed_box_ticked() ) {
			self::fail( 'confirm', 'tick' );
			return;
		}
		CMP_Agreements::record( $key, $user_id, 'member' );
		self::done( 'done' );
	}

	/** Signed out, step 1: is this email already a member? */
	private static function handle_email() {
		$email = sanitize_email( self::post( 'email', 190 ) );
		if ( ! self::stamp_ok() || ! is_email( $email ) ) {
			self::fail( 'email', is_email( $email ) ? 'expired' : 'email', array( 'email' => $email ) );
			return;
		}
		// Look-ups say whether an email has an account, so they're limited
		// per network address to stop anyone checking a list of emails.
		$k = 'cmp_ag_look_' . substr( CMP_Agreements::ip_hash(), 0, 32 );
		$n = (int) get_transient( $k );
		if ( $n >= self::LOOKUPS ) {
			self::fail( 'email', 'slow_down', array( 'email' => $email ) );
			return;
		}
		set_transient( $k, $n + 1, 10 * MINUTE_IN_SECONDS );
		if ( email_exists( $email ) ) {
			self::$view = array( 'step' => 'password', 'email' => $email );
		} elseif ( ! get_option( 'users_can_register' ) ) {
			self::$view = array( 'step' => 'closed', 'email' => $email );
		} else {
			self::$view = array( 'step' => 'join', 'email' => $email );
		}
	}

	/** Known email: password, sign in, agree. */
	private static function handle_signin( $key ) {
		$email = sanitize_email( self::post( 'email', 190 ) );
		$user  = is_email( $email ) ? get_user_by( 'email', $email ) : false;
		if ( ! $user || ! self::stamp_ok( 0 ) ) {
			self::fail( 'email', 'expired', array( 'email' => $email ) );
			return;
		}
		$keep = array( 'email' => $email );
		if ( CMP_Account::too_many_attempts( $user->ID ) ) {
			self::fail( 'password', 'locked', $keep );
			return;
		}
		if ( ! self::agreed_box_ticked() ) {
			self::fail( 'password', 'tick', $keep );
			return;
		}
		$signed = wp_signon(
			array(
				'user_login'    => $email,
				'user_password' => isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a password is used as typed.
				'remember'      => true,
			),
			is_ssl()
		);
		if ( is_wp_error( $signed ) ) {
			CMP_Account::too_many_attempts( $user->ID, true );
			self::fail( 'password', 'password', $keep );
			return;
		}
		wp_set_current_user( $signed->ID );
		if ( CMP_Birth_Date::is_blocked( $signed->ID ) ) {
			self::done( 'blocked' );
		}
		if ( ! CMP_Birth_Date::qualifies( $signed->ID ) ) {
			// Older account with no date of birth: the signed-in form asks for it.
			self::done( 'dob' );
		}
		CMP_Agreements::record( $key, $signed->ID, 'signin' );
		self::done( 'done' );
	}

	/** New email: join free, then agree. Same checks as [cec_register]. */
	private static function handle_join( $key ) {
		$email = sanitize_email( self::post( 'email', 190 ) );
		// Not "name": WordPress reads that from POST as a page slug and answers 404.
		$name  = self::post( 'display_name', 60 );
		$keep  = array( 'email' => $email, 'name' => $name );
		if ( ! is_email( $email ) || ! self::stamp_ok() ) {
			self::fail( 'email', 'expired', $keep );
			return;
		}
		if ( email_exists( $email ) ) {
			self::fail( 'password', '', $keep );
			return;
		}
		if ( ! get_option( 'users_can_register' ) ) {
			self::fail( 'closed', '', $keep );
			return;
		}
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $name ) {
			self::fail( 'join', 'name', $keep );
			return;
		}
		$dob = CMP_Birth_Date::from_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- stamp checked above.
		if ( is_wp_error( $dob ) ) {
			self::fail( 'join', $dob->get_error_code(), $keep );
			return;
		}
		if ( strlen( $password ) < 8 ) {
			self::fail( 'join', 'password_short', $keep );
			return;
		}
		if ( ! self::agreed_box_ticked() ) {
			self::fail( 'join', 'tick', $keep );
			return;
		}
		/** Same hook the sign-up page runs, so other checks apply here too. */
		$extra = apply_filters( 'cec_register_validate', true );
		if ( is_wp_error( $extra ) ) {
			self::fail( 'join', sanitize_key( $extra->get_error_code() ), $keep );
			return;
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => self::new_username( $name ),
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $name,
				'nickname'     => $name,
				'role'         => get_option( 'default_role', 'subscriber' ),
			)
		);
		if ( is_wp_error( $user_id ) ) {
			self::fail( 'join', 'failed', $keep );
			return;
		}
		/** The account exists: the date of birth, 18+ record and profile defaults are stored by the same handlers as on [cec_register]. */
		do_action( 'cec_user_registered', $user_id );
		CMP_Audit::log( 'account_created', 'user', $user_id, null, array( 'route' => 'agreement', 'agreement' => $key ), '', $user_id );
		wp_new_user_notification( $user_id, null, 'admin' );
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		// The same "Confirm your email address" email the member area sends.
		CMP_Email_Verification::send( $user_id );
		CMP_Agreements::record( $key, $user_id, 'signup' );
		self::done( 'joined' );
	}

	/**
	 * A login name from the display name plus digits (never the email, so
	 * it can't reveal it). Login names are kept out of public view (0.38.1),
	 * and people sign in with their email anyway.
	 */
	private static function new_username( $name ) {
		$base = substr( preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( $name ) ) ), 0, 20 );
		$base = '' === $base ? 'member' : $base;
		do {
			$login = $base . wp_rand( 1000, 99999 );
		} while ( username_exists( $login ) );
		return $login;
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	private static function messages() {
		return array(
			'invalid'        => __( 'Something went wrong. Please try again.', 'cmp' ),
			'expired'        => __( 'This page was open a long time, so please try once more.', 'cmp' ),
			'email'          => __( 'Please enter a valid email address.', 'cmp' ),
			'slow_down'      => __( 'Too many tries from this network. Please wait 10 minutes, or ask a monitor.', 'cmp' ),
			'password'       => __( 'That password isn\'t right. Try again, or reset it below.', 'cmp' ),
			'locked'         => __( 'Too many wrong passwords. Please wait 15 minutes, or reset your password below.', 'cmp' ),
			'tick'           => __( 'Please tick the box to agree to the rules.', 'cmp' ),
			'name'           => __( 'Please tell us what to call you.', 'cmp' ),
			'password_short' => __( 'Your password needs at least 8 characters.', 'cmp' ),
			'dob_missing'    => __( 'Please enter your date of birth.', 'cmp' ),
			'dob_invalid'    => __( 'That date of birth isn\'t a real date. Please check it.', 'cmp' ),
			'dob_under_18'   => __( 'You must be 18 or older to join, so you can\'t agree here.', 'cmp' ),
			'blocked'        => __( 'This account can\'t agree to event rules. Please speak to a monitor.', 'cmp' ),
			'failed'         => __( 'We couldn\'t create your account. Please try again or ask a monitor.', 'cmp' ),
		);
	}

	private static function error_html( $code ) {
		$msgs = (array) apply_filters( 'cec_register_error_messages', self::messages() );
		if ( '' === (string) $code || ! isset( $msgs[ $code ] ) ) {
			return '';
		}
		return '<p class="cmp-ag-notice cmp-ag-error" role="alert"><span aria-hidden="true">⚠ </span>' . esc_html( $msgs[ $code ] ) . '</p>';
	}

	private static function hidden( $action ) {
		return '<input type="hidden" name="cmp_ag_action" value="' . esc_attr( $action ) . '" />'
			. '<input type="hidden" name="t" value="' . esc_attr( self::stamp( time() ) ) . '" />'
			. '<p class="cmp-ag-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off" /></label></p>';
	}

	private static function agree_box( $a ) {
		return '<p class="cmp-ag-check"><input type="checkbox" id="cmp_ag_agree" name="agree" value="1" required /><label for="cmp_ag_agree">'
			. esc_html( sprintf( /* translators: %s: agreement title, e.g. Mosh pit rules */ __( 'I\'ve read the %s and the full terms above, and I agree to follow them.', 'cmp' ), $a['title'] ) )
			. '</label></p>';
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( array( 'key' => '' ), $atts, self::SHORTCODE );
		$key  = CMP_Agreements::clean_key( $atts['key'] );
		$a    = '' !== $key ? CMP_Agreements::get( $key ) : null;
		wp_enqueue_style( 'cmp-agreement', CMP_URL . 'assets/css/agreement.css', array(), CMP_VERSION );
		if ( ! $a || empty( $a['active'] ) ) {
			return '<div class="cmp-agreement" id="cmp-agreement"><p class="cmp-ag-notice">' . esc_html__( 'This agreement isn\'t available right now. Please ask a monitor or event host.', 'cmp' ) . '</p></div>';
		}
		$box   = '<div class="cmp-ag-box" id="cmp-ag-box">' . ( is_user_logged_in() ? self::signed_in_html( $key, $a ) : self::signed_out_html( $a ) ) . '</div>';
		$rules = '<ol class="cmp-ag-rules">';
		foreach ( $a['rules'] as $rule ) {
			$rules .= '<li>' . esc_html( $rule ) . '</li>';
		}
		$rules .= '</ol>';
		$terms = '' !== trim( wp_strip_all_tags( $a['body'] ) )
			? '<details class="cmp-ag-terms"><summary>' . esc_html__( 'Read the full code of conduct, assumption of risk and release', 'cmp' ) . '</summary><div class="cmp-ag-terms-body">' . wp_kses_post( $a['body'] ) . '</div></details>'
			: '';
		$html = '<div class="cmp-agreement" id="cmp-agreement"><h2 class="cmp-ag-title">' . esc_html( $a['title'] ) . '</h2>';
		if ( is_user_logged_in() && CMP_Agreements::current( $key, get_current_user_id() ) ) {
			// Agreed: the confirmation first, so a monitor sees it without scrolling.
			return $html . $box . '<details class="cmp-ag-terms"><summary>' . esc_html__( 'The rules you agreed to', 'cmp' ) . '</summary>' . $rules . '</details>' . $terms . '</div>';
		}
		if ( '' !== $a['intro'] ) {
			$html .= '<p class="cmp-ag-intro">' . esc_html( $a['intro'] ) . '</p>';
		}
		return $html . $rules . $terms . $box . '</div>';
	}

	private static function signed_in_html( $key, $a ) {
		$user  = wp_get_current_user();
		$state = isset( $_GET['cmp_ag'] ) ? sanitize_key( wp_unslash( $_GET['cmp_ag'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$out   = add_query_arg( 'cmp_agree', $key, home_url( '/' ) );
		$not   = '<p class="cmp-ag-small">' . esc_html( sprintf( /* translators: %s: display name */ __( 'Signed in as %s.', 'cmp' ), $user->display_name ) ) . ' <a href="' . esc_url( wp_logout_url( $out ) ) . '">' . esc_html__( 'Not you? Sign out', 'cmp' ) . '</a></p>';
		if ( CMP_Birth_Date::is_blocked( $user->ID ) ) {
			return self::error_html( 'blocked' ) . $not;
		}
		$row = CMP_Agreements::current( $key, $user->ID );
		if ( $row ) {
			$html = '<div class="cmp-ag-done" role="status"><p class="cmp-ag-tick" aria-hidden="true">✓</p>'
				. '<p class="cmp-ag-done-title">' . esc_html__( 'You agreed', 'cmp' ) . '</p>'
				. '<p class="cmp-ag-done-name">' . esc_html( $row->name ) . '</p>'
				. '<p class="cmp-ag-done-when">' . esc_html( wp_date( __( 'l, F j \a\t g:i A', 'cmp' ), strtotime( $row->created_at . ' UTC' ) ) ) . '</p>'
				. '<p class="cmp-ag-small">' . esc_html__( 'Show this screen to a monitor if they ask. Have fun, and look out for each other.', 'cmp' ) . '</p></div>';
			if ( 'joined' === $state ) {
				$html .= '<p class="cmp-ag-notice">' . esc_html( sprintf( /* translators: %s: email */ __( 'Welcome to COL&B! We\'ve emailed a link to %s. Open it to confirm your address and unlock the member area.', 'cmp' ), $user->user_email ) ) . '</p>';
			}
			return $html . $not;
		}
		$view  = self::$view;
		$html  = self::error_html( $view['error'] ?? '' );
		if ( 'dob' === $state && empty( $view ) ) {
			$html .= '<p class="cmp-ag-notice">' . esc_html__( 'You\'re signed in. One more thing: we need your date of birth once to confirm you\'re 18 or older.', 'cmp' ) . '</p>';
		}
		$html .= '<form method="post" action="' . esc_url( self::page_url() ) . '" class="cmp-ag-form">'
			. '<input type="hidden" name="cmp_ag_action" value="confirm" />'
			. '<input type="hidden" name="_cmp_ag" value="' . esc_attr( wp_create_nonce( 'cmp_ag_confirm_' . $key ) ) . '" />';
		if ( ! CMP_Birth_Date::qualifies( $user->ID ) ) {
			$html .= '<p><label for="cmp_ag_dob_m">' . esc_html__( 'Date of birth', 'cmp' ) . '</label>'
				. CMP_Birth_Date::fields_html( 'cmp_ag_dob', '', 'cmp_ag_dob_help' )
				. '<span class="cmp-ag-small" id="cmp_ag_dob_help">' . esc_html__( 'Asked once, only to confirm you\'re 18 or older. Never shown to anyone.', 'cmp' ) . '</span></p>';
		}
		$html .= self::agree_box( $a )
			. '<p><button type="submit" class="cmp-ag-btn">' . esc_html__( 'I agree', 'cmp' ) . '</button></p></form>' . $not;
		return $html;
	}

	private static function signed_out_html( $a ) {
		$view  = self::$view;
		$step  = $view['step'] ?? 'email';
		$email = (string) ( $view['email'] ?? '' );
		$html  = self::error_html( $view['error'] ?? '' );
		$again = '<p class="cmp-ag-small">' . esc_html( $email ) . ' · <a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Use a different email', 'cmp' ) . '</a></p>';
		$form  = '<form method="post" action="' . esc_url( self::page_url() ) . '" class="cmp-ag-form">';

		if ( 'password' === $step ) {
			$lost = wp_lostpassword_url( get_permalink() );
			return $html . '<h3 class="cmp-ag-step">' . esc_html__( 'Welcome back! Sign in to agree', 'cmp' ) . '</h3>' . $again . $form . self::hidden( 'signin' )
				. '<input type="hidden" name="email" value="' . esc_attr( $email ) . '" />'
				. '<input type="text" name="username" value="' . esc_attr( $email ) . '" autocomplete="username" hidden />'
				. '<p><label for="cmp_ag_pw">' . esc_html__( 'Your COL&B password', 'cmp' ) . '</label><input type="password" id="cmp_ag_pw" name="password" required autocomplete="current-password" /></p>'
				. self::agree_box( $a )
				. '<p><button type="submit" class="cmp-ag-btn">' . esc_html__( 'Sign in and agree', 'cmp' ) . '</button></p>'
				. '<p class="cmp-ag-small"><a href="' . esc_url( $lost ) . '">' . esc_html__( 'Forgot your password?', 'cmp' ) . '</a></p></form>';
		}
		if ( 'join' === $step ) {
			$privacy = (string) CMP_Settings::get( 'privacy_notice_url' );
			return $html . '<h3 class="cmp-ag-step">' . esc_html__( 'New here? Join COL&B free', 'cmp' ) . '</h3>' . $again . $form . self::hidden( 'join' )
				. '<input type="hidden" name="email" value="' . esc_attr( $email ) . '" />'
				. '<input type="text" name="username" value="' . esc_attr( $email ) . '" autocomplete="username" hidden />'
				. '<p><label for="cmp_ag_name">' . esc_html__( 'What should we call you?', 'cmp' ) . '</label><input type="text" id="cmp_ag_name" name="display_name" maxlength="60" required autocomplete="nickname" value="' . esc_attr( (string) ( $view['name'] ?? '' ) ) . '" />'
				. '<span class="cmp-ag-small">' . esc_html__( 'A first name or scene name is fine. Members can see it.', 'cmp' ) . '</span></p>'
				. '<p><label for="cmp_ag_dob_m">' . esc_html__( 'Date of birth', 'cmp' ) . '</label>' . CMP_Birth_Date::fields_html( 'cmp_ag_dob', '', 'cmp_ag_dob_help' )
				. '<span class="cmp-ag-small" id="cmp_ag_dob_help">' . esc_html__( 'Only to confirm you\'re 18 or older. Never shown to anyone.', 'cmp' ) . '</span></p>'
				. '<p><label for="cmp_ag_newpw">' . esc_html__( 'Choose a password', 'cmp' ) . '</label><input type="password" id="cmp_ag_newpw" name="password" minlength="8" required autocomplete="new-password" />'
				. '<span class="cmp-ag-small">' . esc_html__( 'At least 8 characters. You\'ll sign in with your email and this password.', 'cmp' ) . '</span></p>'
				. self::agree_box( $a )
				. '<p><button type="submit" class="cmp-ag-btn">' . esc_html__( 'Join free and agree', 'cmp' ) . '</button></p>'
				. '<p class="cmp-ag-small">' . esc_html__( 'We keep your name, email and when you agreed, so event hosts can check who agreed to the rules. We\'ll email you a link to confirm your address. No spam.', 'cmp' )
				. ( $privacy ? ' <a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy statement', 'cmp' ) . '</a>' : '' ) . '</p></form>';
		}
		if ( 'closed' === $step ) {
			return $html . $again . '<p class="cmp-ag-notice">' . esc_html__( 'New accounts can\'t be created on the website right now. Please ask a monitor.', 'cmp' ) . '</p>';
		}
		return $html . '<h3 class="cmp-ag-step">' . esc_html__( 'Agree with your COL&B account', 'cmp' ) . '</h3>'
			. '<p class="cmp-ag-small">' . esc_html__( 'Members sign in. New here? You can join free in a minute.', 'cmp' ) . '</p>'
			. $form . self::hidden( 'email' )
			. '<p><label for="cmp_ag_email">' . esc_html__( 'Your email', 'cmp' ) . '</label><input type="email" id="cmp_ag_email" name="email" maxlength="190" required autocomplete="email" inputmode="email" value="' . esc_attr( $email ) . '" /></p>'
			. '<p><button type="submit" class="cmp-ag-btn">' . esc_html__( 'Continue', 'cmp' ) . '</button></p></form>';
	}
}
