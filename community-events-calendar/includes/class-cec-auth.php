<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Styled front-end login and registration, so a visitor with no WordPress
 * account can create a lightweight one (Subscriber by default) to submit
 * and manage events, without ever seeing wp-login.php.
 */
class CEC_Auth {

	const LOGIN_NONCE    = 'cec_login_action';
	const REGISTER_NONCE = 'cec_register_action';

	public static function render_login_shortcode( $atts ) {
		if ( is_user_logged_in() ) {
			return '<div class="cec-auth-form"><div class="cec-notice">' . esc_html__( "You're already logged in.", 'cec' ) . '</div></div>';
		}

		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : CEC_Admin_Settings::submit_url();
		$error    = isset( $_GET['cec_login'] ) && '0' === $_GET['cec_login'];

		ob_start();
		?>
		<div class="cec-auth-form">
			<?php if ( $error ) : ?>
				<div class="cec-notice cec-notice-error"><?php esc_html_e( 'Incorrect username/email or password.', 'cec' ); ?></div>
			<?php endif; ?>
			<form class="cec-submit-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cec_login" />
				<input type="hidden" name="cec_redirect" value="<?php echo esc_url( $redirect ); ?>" />
				<?php wp_nonce_field( self::LOGIN_NONCE, 'cec_login_nonce' ); ?>
				<div class="cec-field"><label><?php esc_html_e( 'Email or Username', 'cec' ); ?></label><input type="text" name="cec_identifier" required autocomplete="username"></div>
				<div class="cec-field"><label><?php esc_html_e( 'Password', 'cec' ); ?></label><input type="password" name="cec_password" required autocomplete="current-password"></div>
				<div class="cec-field"><label class="cec-checkbox"><input type="checkbox" name="cec_remember" value="1" checked> <?php esc_html_e( 'Remember me', 'cec' ); ?></label></div>
				<button type="submit" class="cec-btn"><?php esc_html_e( 'Log In', 'cec' ); ?></button>
				<p class="description">
					<a href="<?php echo esc_url( wp_lostpassword_url( $redirect ) ); ?>"><?php esc_html_e( 'Forgot your password?', 'cec' ); ?></a>
					<?php if ( get_option( 'users_can_register' ) ) : ?>
						&nbsp;·&nbsp; <a href="<?php echo esc_url( CEC_Admin_Settings::register_url( $redirect ) ); ?>"><?php esc_html_e( 'Create an account', 'cec' ); ?></a>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function handle_login() {
		$redirect = isset( $_POST['cec_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['cec_redirect'] ) ) : home_url();

		if ( ! isset( $_POST['cec_login_nonce'] ) || ! wp_verify_nonce( $_POST['cec_login_nonce'], self::LOGIN_NONCE ) ) {
			wp_safe_redirect( add_query_arg( 'cec_login', '0', wp_get_referer() ) );
			exit;
		}

		$creds = array(
			'user_login'    => isset( $_POST['cec_identifier'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_identifier'] ) ) : '',
			'user_password' => isset( $_POST['cec_password'] ) ? (string) $_POST['cec_password'] : '',
			'remember'      => ! empty( $_POST['cec_remember'] ),
		);

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			wp_safe_redirect( add_query_arg( 'cec_login', '0', wp_get_referer() ) );
			exit;
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	public static function render_register_shortcode( $atts ) {
		if ( is_user_logged_in() ) {
			return '<div class="cec-auth-form"><div class="cec-notice">' . esc_html__( "You're already logged in.", 'cec' ) . '</div></div>';
		}
		if ( ! get_option( 'users_can_register' ) ) {
			return '<div class="cec-auth-form"><div class="cec-notice">' . esc_html__( 'New account registration is currently closed. Please contact the site admin.', 'cec' ) . '</div></div>';
		}

		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : CEC_Admin_Settings::submit_url();

		$errors = array(
			'invalid_username'  => __( 'Please enter a username (letters, numbers, spaces, and _ . - @ only).', 'cec' ),
			'username_taken'    => __( 'That username is already taken — please choose another.', 'cec' ),
			'invalid_email'     => __( 'Please enter a valid email address.', 'cec' ),
			'email_taken'       => __( 'An account with that email already exists — try logging in instead.', 'cec' ),
			'password_short'    => __( 'Password must be at least 8 characters.', 'cec' ),
			'password_mismatch' => __( 'Passwords do not match.', 'cec' ),
			'failed'            => __( 'Something went wrong creating your account. Please try again.', 'cec' ),
		);
		/**
		 * Messages for error codes added by cec_register_validate (e.g. the
		 * member plugin's date-of-birth check).
		 *
		 * @param array $errors code => message
		 */
		$errors = (array) apply_filters( 'cec_register_error_messages', $errors );
		$error_key = isset( $_GET['cec_register_error'] ) ? sanitize_key( wp_unslash( $_GET['cec_register_error'] ) ) : '';

		ob_start();
		?>
		<div class="cec-auth-form">
			<?php if ( isset( $errors[ $error_key ] ) ) : ?>
				<div class="cec-notice cec-notice-error"><?php echo esc_html( $errors[ $error_key ] ); ?></div>
			<?php endif; ?>
			<form class="cec-submit-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cec_register" />
				<input type="hidden" name="cec_redirect" value="<?php echo esc_url( $redirect ); ?>" />
				<?php wp_nonce_field( self::REGISTER_NONCE, 'cec_register_nonce' ); ?>
				<div class="cec-field"><label><?php esc_html_e( 'Username', 'cec' ); ?></label><input type="text" name="cec_username" required autocomplete="username">
					<p class="description"><?php esc_html_e( "This is what you'll use to log in.", 'cec' ); ?></p>
				</div>
				<div class="cec-field"><label><?php esc_html_e( 'Email', 'cec' ); ?></label><input type="email" name="cec_email" required autocomplete="email"></div>
				<div class="cec-field"><label><?php esc_html_e( 'Password', 'cec' ); ?></label><input type="password" name="cec_password" required minlength="8" autocomplete="new-password"></div>
				<div class="cec-field"><label><?php esc_html_e( 'Confirm Password', 'cec' ); ?></label><input type="password" name="cec_password_confirm" required minlength="8" autocomplete="new-password"></div>
				<?php
				/**
				 * Extra sign-up fields from other plugins (the member plugin's
				 * date of birth). This plugin stores nothing they collect.
				 */
				do_action( 'cec_register_form_fields' );
				?>
				<p class="cec-hp-field" aria-hidden="true"><label>Leave this field empty</label><input type="text" name="cec_website" tabindex="-1" autocomplete="off"></p>
				<button type="submit" class="cec-btn"><?php esc_html_e( 'Create Account', 'cec' ); ?></button>
				<p class="description"><?php esc_html_e( 'Already have an account?', 'cec' ); ?> <a href="<?php echo esc_url( CEC_Admin_Settings::login_url( $redirect ) ); ?>"><?php esc_html_e( 'Log in', 'cec' ); ?></a></p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function handle_register() {
		$redirect = isset( $_POST['cec_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['cec_redirect'] ) ) : home_url();
		$back     = wp_get_referer() ? wp_get_referer() : $redirect;

		if ( ! isset( $_POST['cec_register_nonce'] ) || ! wp_verify_nonce( $_POST['cec_register_nonce'], self::REGISTER_NONCE ) ) {
			wp_safe_redirect( add_query_arg( 'cec_register_error', 'failed', $back ) );
			exit;
		}
		if ( ! get_option( 'users_can_register' ) ) {
			wp_safe_redirect( add_query_arg( 'cec_register_error', 'failed', $back ) );
			exit;
		}
		if ( ! empty( $_POST['cec_website'] ) ) {
			// Honeypot tripped — pretend success so bots don't learn anything.
			wp_safe_redirect( $redirect );
			exit;
		}

		$username_raw = isset( $_POST['cec_username'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_username'] ) ) : '';
		$username     = sanitize_user( $username_raw, true );
		$email        = isset( $_POST['cec_email'] ) ? sanitize_email( wp_unslash( $_POST['cec_email'] ) ) : '';
		$password     = isset( $_POST['cec_password'] ) ? (string) $_POST['cec_password'] : '';
		$confirm      = isset( $_POST['cec_password_confirm'] ) ? (string) $_POST['cec_password_confirm'] : '';

		if ( '' === $username ) {
			wp_safe_redirect( add_query_arg( 'cec_register_error', 'invalid_username', $back ) );
			exit;
		}
		if ( username_exists( $username ) ) {
			wp_safe_redirect( add_query_arg( 'cec_register_error', 'username_taken', $back ) );
			exit;
		}
		if ( ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( 'cec_register_error', 'invalid_email', $back ) );
			exit;
		}
		if ( email_exists( $email ) ) {
			wp_safe_redirect( add_query_arg( 'cec_register_error', 'email_taken', $back ) );
			exit;
		}
		if ( strlen( $password ) < 8 ) {
			wp_safe_redirect( add_query_arg( 'cec_register_error', 'password_short', $back ) );
			exit;
		}
		if ( $password !== $confirm ) {
			wp_safe_redirect( add_query_arg( 'cec_register_error', 'password_mismatch', $back ) );
			exit;
		}
		/**
		 * Lets another plugin refuse the sign-up before the account exists.
		 * Return a WP_Error whose code has a message registered through
		 * cec_register_error_messages; anything else lets it continue.
		 *
		 * @param true|WP_Error $result
		 */
		$extra = apply_filters( 'cec_register_validate', true );
		if ( is_wp_error( $extra ) ) {
			wp_safe_redirect( add_query_arg( 'cec_register_error', sanitize_key( $extra->get_error_code() ), $back ) );
			exit;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => $password,
				// Keeps the friendlier, unsanitized form the visitor typed
				// (e.g. "Foxxy" rather than sanitize_user()'s "foxxy") for
				// display purposes, while $username above — the sanitized,
				// uniqueness-checked version — is what they'll actually log
				// in with.
				'display_name' => $username_raw,
				'role'         => get_option( 'default_role', 'subscriber' ),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_safe_redirect( add_query_arg( 'cec_register_error', 'failed', $back ) );
			exit;
		}

		/**
		 * The account now exists; another plugin can store what its
		 * cec_register_form_fields collected (read from $_POST).
		 *
		 * @param int $user_id
		 */
		do_action( 'cec_user_registered', $user_id );

		wp_new_user_notification( $user_id, null, 'admin' );

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		/**
		 * Where a brand-new account goes next. Defaults to the redirect the
		 * form carried (usually Submit an Event); the member plugin sends new
		 * members to their member area to finish setting up instead.
		 *
		 * @param string $redirect
		 * @param int    $user_id
		 */
		$redirect = (string) apply_filters( 'cec_register_redirect', $redirect, $user_id );

		wp_safe_redirect( $redirect );
		exit;
	}
}
