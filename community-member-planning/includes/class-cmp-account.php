<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The member area's Account tab: everything an account holder used to do
 * on wp-admin/profile.php (name, email, password, devices, privacy
 * requests), on the front end. Also keeps members out of the WordPress
 * dashboard altogether: they never see wp-admin, the admin bar, or a link
 * to either.
 *
 * Available to every signed-in account, not only full members: someone who
 * signed up with a mistyped address must be able to fix it before they
 * can verify it.
 */
class CMP_Account {

	const TAB = 'account';

	// Pending email change: the new address only replaces the current one
	// once a link sent to it is opened (proves the new inbox, and a typo
	// can't lock anyone out of their account).
	const META_CHANGE_HASH    = 'cmp_email_change_hash';
	const META_CHANGE_EXPIRES = 'cmp_email_change_expires';
	const META_CHANGE_EMAIL   = 'cmp_email_change_email';
	const CHANGE_TTL          = DAY_IN_SECONDS;
	const CHANGE_WAIT         = 5 * MINUTE_IN_SECONDS;

	const MIN_PASSWORD = 10;

	const FORMS = array( 'details', 'email', 'email_cancel', 'password', 'sessions', 'preferences', 'privacy' );

	public static function init() {
		foreach ( self::FORMS as $form ) {
			add_action( 'admin_post_cmp_account_' . $form, array( __CLASS__, 'handle_' . $form ) );
			add_action( 'admin_post_nopriv_cmp_account_' . $form, array( 'CMP_Member_Area', 'redirect_to_login' ) );
		}
		add_action( 'template_redirect', array( __CLASS__, 'handle_email_link' ), 1 );

		// Keep members out of the dashboard.
		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_dashboard' ), 1 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_filter( 'edit_profile_url', array( __CLASS__, 'profile_url' ), 10, 2 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 ); // After the events plugin's (10).

		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	public static function url( $notice = '' ) {
		$url = add_query_arg( 'cmp_tab', self::TAB, CMP_Settings::member_page_url() );
		return $notice ? add_query_arg( 'cmp_notice', $notice, $url ) : $url;
	}

	/* ------------------------------------------------------------------
	 * Dashboard lockout
	 * ---------------------------------------------------------------- */

	/**
	 * Whether this user is kept out of wp-admin. Anyone who can write
	 * posts, manage calendar events or administer the site still uses the
	 * dashboard; everyone else is a member and uses the member area.
	 */
	public static function is_kept_out( $user = null ) {
		$user = $user instanceof WP_User ? $user : wp_get_current_user();
		if ( ! $user || ! $user->exists() || ! CMP_Settings::get( 'lock_dashboard' ) || ! (int) CMP_Settings::get( 'member_page_id' ) ) {
			return false;
		}
		$staff = $user->has_cap( 'edit_posts' ) || $user->has_cap( 'cec_manage_events' ) || $user->has_cap( 'manage_options' );
		/**
		 * Filters whether a signed-in account is kept out of wp-admin.
		 *
		 * @param bool    $kept_out
		 * @param WP_User $user
		 */
		return (bool) apply_filters( 'cmp_keep_out_of_dashboard', ! $staff, $user );
	}

	public static function keep_out_of_dashboard() {
		global $pagenow;
		// Form posts (admin-post.php) and background requests (admin-ajax.php)
		// run admin_init too, and must keep working.
		if ( wp_doing_ajax() || wp_doing_cron() || in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php', 'async-upload.php' ), true ) ) {
			return;
		}
		// The events plugin already sends every non-administrator from wp-admin
		// to the front end, except its profile screen; with it active, only
		// that screen is taken over here, so its routing stays as it is.
		if ( ! self::is_kept_out() || ( class_exists( 'CEC_Roles' ) && 'profile.php' !== $pagenow ) ) {
			return;
		}
		wp_safe_redirect( 'profile.php' === $pagenow ? self::url() : CMP_Settings::member_page_url() );
		exit;
	}

	public static function admin_bar( $show ) {
		return self::is_kept_out() ? false : $show;
	}

	public static function profile_url( $url, $user_id ) {
		$user = get_userdata( $user_id );
		return $user && get_current_user_id() === (int) $user_id && self::is_kept_out( $user ) ? self::url() : $url;
	}

	/**
	 * Straight to the member area after signing in, instead of bouncing
	 * through wp-admin (WordPress sends accounts without dashboard access
	 * to profile.php by default).
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof WP_User || ! self::is_kept_out( $user ) ) {
			return $redirect_to;
		}
		if ( class_exists( 'CEC_Roles' ) && 0 !== strpos( (string) $requested, admin_url( 'profile.php' ) ) && 0 !== strpos( (string) $redirect_to, admin_url( 'profile.php' ) ) ) {
			return $redirect_to; // The events plugin already chose a front-end page.
		}
		$profile = admin_url( 'profile.php' );
		if ( 0 === strpos( (string) $requested, $profile ) || 0 === strpos( (string) $redirect_to, $profile ) ) {
			return self::url();
		}
		if ( '' === (string) $redirect_to || 0 === strpos( (string) $redirect_to, admin_url() ) ) {
			return CMP_Settings::member_page_url();
		}
		return $redirect_to;
	}

	/* ------------------------------------------------------------------
	 * Form handlers
	 * ---------------------------------------------------------------- */

	private static function check( $form ) {
		if ( ! is_user_logged_in() ) {
			CMP_Member_Area::redirect_to_login();
		}
		if ( ! isset( $_POST['_cmp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cmp_nonce'] ) ), 'cmp_account_' . $form ) ) {
			self::back( 'expired' );
		}
		return wp_get_current_user();
	}

	private static function back( $notice, $form = '' ) {
		$url = self::url( $notice );
		wp_safe_redirect( $form ? $url . '#cmp-' . $form : $url );
		exit;
	}

	private static function post( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller runs check() first.
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	}

	private static function password_ok( WP_User $user, $password ) {
		return '' !== $password && wp_check_password( $password, $user->user_pass, $user->ID );
	}

	/**
	 * Wrong current password: counted per account, at most 5 in 15 minutes,
	 * so the forms can't be used to guess a password.
	 */
	private static function too_many_attempts( $user_id, $failed = false ) {
		$key   = 'cmp_pw_fail_' . $user_id;
		$count = (int) get_transient( $key );
		if ( $failed ) {
			set_transient( $key, ++$count, 15 * MINUTE_IN_SECONDS );
		}
		return $count >= 5;
	}

	public static function handle_details() {
		$user    = self::check( 'details' );
		$first   = sanitize_text_field( self::post( 'first_name' ) );
		$last    = sanitize_text_field( self::post( 'last_name' ) );
		$display = sanitize_text_field( self::post( 'display_name' ) );
		if ( '' === $display || mb_strlen( $display ) > 80 || mb_strlen( $first ) > 80 || mb_strlen( $last ) > 80 ) {
			self::back( 'details_invalid', 'details' );
		}
		$old = array( 'first_name' => $user->first_name, 'last_name' => $user->last_name, 'display_name' => $user->display_name );
		$new = array( 'first_name' => $first, 'last_name' => $last, 'display_name' => $display );
		if ( $old === $new ) {
			self::back( 'details_saved', 'details' );
		}
		$result = wp_update_user( array( 'ID' => $user->ID ) + $new );
		if ( is_wp_error( $result ) ) {
			self::back( 'server_error', 'details' );
		}
		CMP_Audit::log( 'account_details_changed', 'user', $user->ID, array_diff_assoc( $old, $new ), array_diff_assoc( $new, $old ) );
		self::back( 'details_saved', 'details' );
	}

	public static function handle_email() {
		$user  = self::check( 'email' );
		$email = strtolower( sanitize_email( self::post( 'new_email' ) ) );
		if ( self::too_many_attempts( $user->ID ) ) {
			self::back( 'password_locked', 'email' );
		}
		if ( ! is_email( $email ) ) {
			self::back( 'email_invalid', 'email' );
		}
		if ( strtolower( $user->user_email ) === $email ) {
			self::back( 'email_same', 'email' );
		}
		if ( ! self::password_ok( $user, self::post( 'email_current_password' ) ) ) {
			self::too_many_attempts( $user->ID, true );
			self::back( 'password_wrong', 'email' );
		}
		$owner = email_exists( $email );
		if ( $owner && (int) $owner !== $user->ID ) {
			// Same wording whether or not it exists would be kinder to privacy,
			// but WordPress itself tells people this on sign-up anyway.
			self::back( 'email_taken', 'email' );
		}
		$wait = 'cmp_email_change_wait_' . $user->ID;
		if ( get_transient( $wait ) ) {
			self::back( 'rate_limited', 'email' );
		}

		$token = bin2hex( random_bytes( 32 ) );
		update_user_meta( $user->ID, self::META_CHANGE_HASH, hash( 'sha256', $token ) );
		update_user_meta( $user->ID, self::META_CHANGE_EXPIRES, time() + self::CHANGE_TTL );
		update_user_meta( $user->ID, self::META_CHANGE_EMAIL, $email );
		set_transient( $wait, 1, self::CHANGE_WAIT );

		$url  = add_query_arg( array( 'cmp_email_change' => $token, 'cmp_uid' => $user->ID ), home_url( '/' ) );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$sent = wp_mail(
			$email,
			sprintf( /* translators: %s: site name */ __( '[%s] Confirm your new email address', 'cmp' ), $site ),
			sprintf(
				/* translators: 1: display name, 2: site name, 3: confirmation link */
				__( "Hi %1\$s,\n\nYou asked to use this address for your %2\$s account. Open this link to confirm the change:\n\n%3\$s\n\nThe link works once and expires in 24 hours. Until then your account keeps its current address. If you didn't ask for this, ignore this email; nothing will change.", 'cmp' ),
				$user->display_name,
				$site,
				$url
			)
		);
		if ( ! $sent ) {
			self::clear_change( $user->ID );
			delete_transient( $wait );
			self::back( 'mail_failed', 'email' );
		}
		CMP_Audit::log( 'email_change_requested', 'user', $user->ID, array( 'email' => strtolower( $user->user_email ) ), array( 'email' => $email ) );
		self::back( 'email_change_sent', 'email' );
	}

	public static function handle_email_cancel() {
		$user = self::check( 'email_cancel' );
		if ( get_user_meta( $user->ID, self::META_CHANGE_EMAIL, true ) ) {
			CMP_Audit::log( 'email_change_cancelled', 'user', $user->ID, array( 'email' => get_user_meta( $user->ID, self::META_CHANGE_EMAIL, true ) ), null );
		}
		self::clear_change( $user->ID );
		delete_transient( 'cmp_email_change_wait_' . $user->ID );
		self::back( 'email_change_cancelled', 'email' );
	}

	/**
	 * The link in the confirmation email. Works signed out and on another
	 * device: the token alone proves control of the new inbox. Opening it
	 * also counts as verifying the new address for the member area.
	 */
	public static function handle_email_link() {
		if ( ! isset( $_GET['cmp_email_change'], $_GET['cmp_uid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		nocache_headers();
		$token   = sanitize_text_field( wp_unslash( $_GET['cmp_email_change'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = absint( $_GET['cmp_uid'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result  = self::confirm_email_change( $user_id, $token );
		wp_safe_redirect( self::url( true === $result ? 'email_changed' : $result ) );
		exit;
	}

	/**
	 * @return true|string True, or a notice key.
	 */
	public static function confirm_email_change( $user_id, $token ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_string( $token ) || 64 !== strlen( $token ) ) {
			return 'email_link_failed';
		}
		$hash    = get_user_meta( $user_id, self::META_CHANGE_HASH, true );
		$expires = (int) get_user_meta( $user_id, self::META_CHANGE_EXPIRES, true );
		$email   = (string) get_user_meta( $user_id, self::META_CHANGE_EMAIL, true );
		if ( ! $hash || ! hash_equals( $hash, hash( 'sha256', $token ) ) ) {
			return 'email_link_failed';
		}
		self::clear_change( $user_id );
		if ( $expires < time() || ! is_email( $email ) ) {
			return 'email_link_failed';
		}
		$owner = email_exists( $email );
		if ( $owner && (int) $owner !== (int) $user_id ) {
			return 'email_taken';
		}
		// WordPress emails the old address that it was changed.
		$result = wp_update_user( array( 'ID' => $user_id, 'user_email' => $email ) );
		if ( is_wp_error( $result ) ) {
			return 'server_error';
		}
		update_user_meta( $user_id, CMP_Email_Verification::META_VERIFIED_EMAIL, $email );
		update_user_meta( $user_id, CMP_Email_Verification::META_VERIFIED_AT, gmdate( 'Y-m-d H:i:s' ) );
		CMP_Audit::log( 'email_verified', 'user', $user_id, null, array( 'email' => $email, 'via' => 'email_change' ), '', $user_id );
		return true;
	}

	private static function clear_change( $user_id ) {
		delete_user_meta( $user_id, self::META_CHANGE_HASH );
		delete_user_meta( $user_id, self::META_CHANGE_EXPIRES );
		delete_user_meta( $user_id, self::META_CHANGE_EMAIL );
	}

	public static function handle_password() {
		$user = self::check( 'password' );
		$new  = (string) self::post( 'new_password' );
		if ( self::too_many_attempts( $user->ID ) ) {
			self::back( 'password_locked', 'password' );
		}
		if ( ! self::password_ok( $user, self::post( 'current_password' ) ) ) {
			self::too_many_attempts( $user->ID, true );
			self::back( 'password_wrong', 'password' );
		}
		if ( mb_strlen( $new ) < self::MIN_PASSWORD || trim( $new ) !== $new ) {
			self::back( 'password_short', 'password' );
		}
		if ( $new !== (string) self::post( 'confirm_password' ) ) {
			self::back( 'password_mismatch', 'password' );
		}
		if ( 0 === strcasecmp( $new, $user->user_login ) || 0 === strcasecmp( $new, $user->user_email ) ) {
			self::back( 'password_weak', 'password' );
		}
		// WordPress emails the account that its password changed.
		$result = wp_update_user( array( 'ID' => $user->ID, 'user_pass' => $new ) );
		if ( is_wp_error( $result ) ) {
			self::back( 'server_error', 'password' );
		}
		// Sign out every other device, then keep this browser signed in.
		WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		CMP_Audit::log( 'password_changed', 'user', $user->ID, null, array( 'other_sessions_ended' => true ) );
		self::back( 'password_changed', 'password' );
	}

	public static function handle_sessions() {
		$user     = self::check( 'sessions' );
		$sessions = WP_Session_Tokens::get_instance( $user->ID );
		$others   = max( 0, count( $sessions->get_all() ) - 1 );
		$sessions->destroy_others( wp_get_session_token() );
		CMP_Audit::log( 'sessions_ended', 'user', $user->ID, array( 'other_sessions' => $others ), array( 'other_sessions' => 0 ) );
		self::back( 'sessions_ended', 'sessions' );
	}

	/**
	 * Notification categories a member can switch off. Account and
	 * security notices always stay on.
	 */
	public static function categories() {
		return array(
			'invitation'      => __( 'Invitations and connection requests', 'cmp' ),
			'access_change'   => __( 'Changes to what is shared with me', 'cmp' ),
			'assignment'      => __( 'New task assignments', 'cmp' ),
			'due_reminder'    => __( 'Task due reminders', 'cmp' ),
			'submission'      => __( 'Submissions waiting for my review', 'cmp' ),
			'review_decision' => __( 'Review decisions on my submissions', 'cmp' ),
			'lock'            => __( 'Chastity lock updates', 'cmp' ),
			'message'         => __( 'New message requests', 'cmp' ),
		);
	}

	public static function handle_preferences() {
		$user = self::check( 'preferences' );
		$tz   = sanitize_text_field( self::post( 'timezone' ) );
		if ( '' !== $tz && ! in_array( $tz, timezone_identifiers_list(), true ) ) {
			self::back( 'timezone_invalid', 'preferences' );
		}
		$on       = array_map( 'sanitize_key', (array) self::post( 'notify' ) );
		$disabled = array_values( array_diff( array_keys( self::categories() ), $on ) );
		$old      = array(
			'timezone' => (string) get_user_meta( $user->ID, CMP_Notifications::META_TIMEZONE, true ),
			'disabled' => array_values( (array) get_user_meta( $user->ID, CMP_Notifications::META_DISABLED, true ) ),
		);
		if ( '' === $tz ) {
			delete_user_meta( $user->ID, CMP_Notifications::META_TIMEZONE );
		} else {
			update_user_meta( $user->ID, CMP_Notifications::META_TIMEZONE, $tz );
		}
		update_user_meta( $user->ID, CMP_Notifications::META_DISABLED, $disabled );
		$new = array( 'timezone' => $tz, 'disabled' => $disabled );
		if ( $old['timezone'] !== $new['timezone'] || array_diff( $old['disabled'], $disabled ) || array_diff( $disabled, $old['disabled'] ) ) {
			CMP_Audit::log( 'preferences_changed', 'user', $user->ID, $old, $new );
		}
		self::back( 'preferences_saved', 'preferences' );
	}

	/**
	 * WordPress's own privacy requests: the account holder confirms by
	 * email, then an administrator completes it under Tools → Export /
	 * Erase Personal Data, which also runs this plugin's exporter/eraser.
	 */
	public static function handle_privacy() {
		$user = self::check( 'privacy' );
		$type = 'erase' === self::post( 'request' ) ? 'remove_personal_data' : 'export_personal_data';
		$id   = wp_create_user_request( $user->user_email, $type );
		if ( is_wp_error( $id ) ) {
			self::back( 'duplicate_request' === $id->get_error_code() ? 'privacy_pending' : 'server_error', 'privacy' );
		}
		$sent = wp_send_user_request( $id );
		if ( is_wp_error( $sent ) ) {
			self::back( 'mail_failed', 'privacy' );
		}
		CMP_Audit::log( 'privacy_request', 'user', $user->ID, null, array( 'type' => $type, 'request_id' => (int) $id ) );
		self::back( 'remove_personal_data' === $type ? 'erase_requested' : 'export_requested', 'privacy' );
	}

	/* ------------------------------------------------------------------
	 * Personal data export / erasure (Tools → Export / Erase Personal Data)
	 * ---------------------------------------------------------------- */

	public static function register_exporter( $exporters ) {
		$exporters['community-member-planning'] = array(
			'exporter_friendly_name' => __( 'Member area', 'cmp' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['community-member-planning'] = array(
			'eraser_friendly_name' => __( 'Member area', 'cmp' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	public static function export( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( $user && 1 === (int) $page ) {
			$fields = array(
				__( 'Verified email address', 'cmp' )   => get_user_meta( $user->ID, CMP_Email_Verification::META_VERIFIED_EMAIL, true ),
				__( 'Email verified at (UTC)', 'cmp' )  => get_user_meta( $user->ID, CMP_Email_Verification::META_VERIFIED_AT, true ),
				__( 'Confirmed 18 or older at (UTC)', 'cmp' ) => get_user_meta( $user->ID, CMP_Access::META_ATTESTED_AT, true ),
				__( '18+ wording version', 'cmp' )      => get_user_meta( $user->ID, CMP_Access::META_ATTESTED_VERSION, true ),
			);
			$rows = array();
			foreach ( $fields as $name => $value ) {
				if ( '' !== (string) $value ) {
					$rows[] = array( 'name' => $name, 'value' => (string) $value );
				}
			}
			$rows = array_merge( $rows, CMP_Birth_Date::export_rows( $user->ID ), CMP_Dynamics::export_rows( $user->ID ), CMP_Homework::export_rows( $user->ID ), CMP_Chastity::export_rows( $user->ID ), CMP_Feed::export_rows( $user->ID ), CMP_Messages::export_rows( $user->ID ) );
			if ( $rows ) {
				$data[] = array( 'group_id' => 'cmp-access', 'group_label' => __( 'Member area access', 'cmp' ), 'item_id' => 'cmp-access-' . $user->ID, 'data' => $rows );
			}
			$profile = CMP_Profiles::export_items( $user->ID );
			if ( $profile ) {
				$data[] = array( 'group_id' => 'cmp-profile', 'group_label' => __( 'Member profile', 'cmp' ), 'item_id' => 'cmp-profile-' . $user->ID, 'data' => $profile );
			}
			foreach ( CMP_Notifications::for_user( $user->ID, 1000 ) as $n ) {
				$n      = CMP_Notifications::to_public( $n );
				$data[] = array(
					'group_id'    => 'cmp-notifications',
					'group_label' => __( 'Member area notifications', 'cmp' ),
					'item_id'     => 'cmp-notification-' . $n['id'],
					'data'        => array(
						array( 'name' => __( 'Message', 'cmp' ), 'value' => $n['message'] ),
						array( 'name' => __( 'Date (UTC)', 'cmp' ), 'value' => $n['date'] ),
						array( 'name' => __( 'Read', 'cmp' ), 'value' => $n['read'] ? __( 'Yes', 'cmp' ) : __( 'No', 'cmp' ) ),
					),
				);
			}
		}
		return array( 'data' => $data, 'done' => true );
	}

	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$user    = get_user_by( 'email', $email );
		$removed = false;
		if ( $user ) {
			$removed = (bool) $wpdb->delete( CMP_Install::table( 'notifications' ), array( 'user_id' => $user->ID ), array( '%d' ) );
			$removed = CMP_Profiles::delete_all( $user->ID ) > 0 || $removed;
			$removed = CMP_Profile_Images::delete_all( $user->ID ) > 0 || $removed;
			$removed = CMP_Birth_Date::erase( $user->ID ) || $removed;
			$removed = CMP_Messages::erase( $user->ID ) > 0 || $removed;
			$removed = CMP_Feed::erase( $user->ID ) > 0 || $removed;
			$removed = CMP_Chastity::erase( $user->ID ) > 0 || $removed;
			$removed = CMP_Homework::erase( $user->ID ) > 0 || $removed;
			$removed = CMP_Dynamics::erase( $user->ID ) > 0 || $removed;
			foreach ( array( CMP_Email_Verification::META_VERIFIED_EMAIL, CMP_Email_Verification::META_VERIFIED_AT, CMP_Email_Verification::META_TOKEN_HASH, CMP_Email_Verification::META_TOKEN_EXPIRES, CMP_Email_Verification::META_TOKEN_EMAIL, CMP_Access::META_ATTESTED_AT, CMP_Access::META_ATTESTED_VERSION, CMP_Notifications::META_DISABLED, CMP_Notifications::META_TIMEZONE, self::META_CHANGE_HASH, self::META_CHANGE_EXPIRES, self::META_CHANGE_EMAIL ) as $key ) {
				$removed = delete_user_meta( $user->ID, $key ) || $removed;
			}
			CMP_Audit::log( 'personal_data_erased', 'user', $user->ID, null, null, 'Privacy erasure request' );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => (bool) $user,
			'messages'       => $user ? array_filter(
				array(
					__( 'Member area: the security audit log (who changed what, and when) is kept, as required for account security.', 'cmp' ),
					CMP_Birth_Date::is_blocked( $user->ID ) ? __( 'Member area: the lock on this account (it gave a date of birth under 18) is kept, so erasing data can\'t reopen the member area to it.', 'cmp' ) : '',
				)
			) : array(),
			'done'           => true,
		);
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	public static function notices() {
		return array(
			'details_saved'          => array( 'success', __( 'Your details are saved.', 'cmp' ) ),
			'details_invalid'        => array( 'error', __( 'Please enter a display name (up to 80 characters). First and last name can be up to 80 characters each.', 'cmp' ) ),
			'email_invalid'          => array( 'error', __( "That doesn't look like an email address. Please check it and try again.", 'cmp' ) ),
			'email_same'             => array( 'error', __( "That's already your email address.", 'cmp' ) ),
			'email_taken'            => array( 'error', __( 'Another account already uses that email address.', 'cmp' ) ),
			'email_change_sent'      => array( 'success', __( 'Check the new inbox: we sent a link to confirm the change. Your current address stays in use until you open it.', 'cmp' ) ),
			'email_change_cancelled' => array( 'success', __( 'Email change cancelled. Your address stays as it is.', 'cmp' ) ),
			'email_changed'          => array( 'success', __( 'Your email address is changed and confirmed.', 'cmp' ) ),
			'email_link_failed'      => array( 'error', __( 'That email-change link is invalid, already used, or expired. Request the change again below.', 'cmp' ) ),
			'password_wrong'         => array( 'error', __( "Your current password wasn't right. Please try again.", 'cmp' ) ),
			'password_locked'        => array( 'error', __( 'Too many wrong passwords. Please wait 15 minutes and try again.', 'cmp' ) ),
			'password_short'         => array( 'error', sprintf( /* translators: %d: minimum length */ __( 'Your new password needs at least %d characters, without spaces at the start or end.', 'cmp' ), self::MIN_PASSWORD ) ),
			'password_mismatch'      => array( 'error', __( "The two new passwords don't match.", 'cmp' ) ),
			'password_weak'          => array( 'error', __( "Your password can't be your username or email address.", 'cmp' ) ),
			'password_changed'       => array( 'success', __( 'Password changed. You were signed out on every other device.', 'cmp' ) ),
			'sessions_ended'         => array( 'success', __( "You're now signed out everywhere except here.", 'cmp' ) ),
			'export_requested'       => array( 'success', __( 'Request sent. Confirm it from the email we just sent you; we will then email you a copy of your data.', 'cmp' ) ),
			'erase_requested'        => array( 'success', __( 'Request sent. Confirm it from the email we just sent you; the site administrator then deletes your data and lets you know.', 'cmp' ) ),
			'privacy_pending'        => array( 'error', __( 'You already have a request like this in progress. Check your email for the confirmation link.', 'cmp' ) ),
			'preferences_saved'      => array( 'success', __( 'Your preferences are saved.', 'cmp' ) ),
			'timezone_invalid'       => array( 'error', __( 'Please choose a time zone from the list.', 'cmp' ) ),
			'server_error'           => array( 'error', __( 'Something went wrong on our side. Please try again in a moment.', 'cmp' ) ),
		);
	}

	private static function form_open( $form, $label_id ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cmp-form" aria-labelledby="' . esc_attr( $label_id ) . '">'
			. '<input type="hidden" name="action" value="cmp_account_' . esc_attr( $form ) . '" />'
			// Not wp_nonce_field(): several forms share this page, and its fixed
			// id="_cmp_nonce" would repeat.
			. '<input type="hidden" name="_cmp_nonce" value="' . esc_attr( wp_create_nonce( 'cmp_account_' . $form ) ) . '" />';
	}

	private static function field( $name, $label, $value = '', $type = 'text', $attrs = '', $help = '' ) {
		$id = 'cmp_' . $name;
		return '<p class="cmp-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>'
			. '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . $attrs . ( $help ? ' aria-describedby="' . esc_attr( $id ) . '_help"' : '' ) . ' />'
			. ( $help ? '<span class="cmp-muted" id="' . esc_attr( $id ) . '_help">' . esc_html( $help ) . '</span>' : '' )
			. '</p>';
	}

	/**
	 * Time zone (used for quiet hours and dates) and notification
	 * categories. Members only: the categories are about member features.
	 */
	private static function render_preferences( $user ) {
		if ( ! CMP_Access::is_member( $user->ID ) ) {
			return '';
		}
		$tz       = (string) get_user_meta( $user->ID, CMP_Notifications::META_TIMEZONE, true );
		$disabled = (array) get_user_meta( $user->ID, CMP_Notifications::META_DISABLED, true );
		// Real place names only (they follow daylight saving time), grouped
		// by region; no "UTC+5"-style fixed offsets.
		$groups = array();
		foreach ( timezone_identifiers_list() as $zone ) {
			$parts = explode( '/', $zone, 2 );
			if ( 2 === count( $parts ) ) {
				$groups[ $parts[0] ][ $zone ] = str_replace( array( '_', '/' ), array( ' ', ' – ' ), $parts[1] );
			}
		}
		$choices = '';
		foreach ( $groups as $region => $zones ) {
			$choices .= '<optgroup label="' . esc_attr( $region ) . '">';
			foreach ( $zones as $zone => $label ) {
				$choices .= '<option value="' . esc_attr( $zone ) . '"' . selected( $tz, $zone, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$choices .= '</optgroup>';
		}
		ob_start();
		?>
		<section class="cmp-panel" id="cmp-preferences">
			<h3 class="cmp-panel-title" id="cmp-preferences-title"><?php esc_html_e( 'Notifications and time zone', 'cmp' ); ?></h3>
			<?php echo self::form_open( 'preferences', 'cmp-preferences-title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p class="cmp-field">
				<label for="cmp_timezone"><?php esc_html_e( 'Your time zone', 'cmp' ); ?></label>
				<select id="cmp_timezone" name="timezone" aria-describedby="cmp_timezone_help">
					<option value=""<?php selected( '', $tz ); ?>><?php echo esc_html( sprintf( /* translators: %s: the site's time zone */ __( 'Same as this site (%s)', 'cmp' ), wp_timezone_string() ) ); ?></option>
					<?php echo $choices; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
				</select>
				<span class="cmp-muted" id="cmp_timezone_help"><?php esc_html_e( 'Dates are shown in this time zone, and notifications that arrive between 10 p.m. and 8 a.m. wait until 8 a.m.', 'cmp' ); ?></span>
			</p>
			<fieldset class="cmp-fieldset">
				<legend><?php esc_html_e( 'Notify me in the member area about', 'cmp' ); ?></legend>
				<?php foreach ( self::categories() as $key => $label ) : ?>
					<p class="cmp-check cmp-check-small"><input type="checkbox" id="cmp_notify_<?php echo esc_attr( $key ); ?>" name="notify[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( ! in_array( $key, $disabled, true ) ); ?> /><label for="cmp_notify_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></p>
				<?php endforeach; ?>
				<p class="cmp-muted"><?php esc_html_e( 'Account and security notices are always on. Email notifications are not sent yet.', 'cmp' ); ?></p>
			</fieldset>
			<button type="submit" class="cmp-btn"><?php esc_html_e( 'Save preferences', 'cmp' ); ?></button>
			</form>
		</section>
		<?php
		return ob_get_clean();
	}

	public static function render() {
		$user    = wp_get_current_user();
		$pending = (string) get_user_meta( $user->ID, self::META_CHANGE_EMAIL, true );
		if ( $pending && (int) get_user_meta( $user->ID, self::META_CHANGE_EXPIRES, true ) < time() ) {
			$pending = '';
		}
		$verified = CMP_Email_Verification::is_verified( $user->ID );
		$others   = max( 0, count( WP_Session_Tokens::get_instance( $user->ID )->get_all() ) - 1 );
		$current  = 'autocomplete="current-password" required';

		ob_start();
		?>
		<section class="cmp-step" aria-labelledby="cmp-account-title">
			<h2 id="cmp-account-title" class="cmp-title"><?php esc_html_e( 'Your account', 'cmp' ); ?></h2>
			<p class="cmp-muted"><?php esc_html_e( 'Your name, email, password and privacy. Only you can see this page.', 'cmp' ); ?></p>
		</section>

		<section class="cmp-panel" id="cmp-details">
			<h3 class="cmp-panel-title" id="cmp-details-title"><?php esc_html_e( 'Your details', 'cmp' ); ?></h3>
			<?php
			echo self::form_open( 'details', 'cmp-details-title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in form_open().
			echo '<div class="cmp-grid">';
			echo self::field( 'first_name', __( 'First name', 'cmp' ), $user->first_name, 'text', 'maxlength="80" autocomplete="given-name"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::field( 'last_name', __( 'Last name', 'cmp' ), $user->last_name, 'text', 'maxlength="80" autocomplete="family-name"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
			echo self::field( 'display_name', __( 'Display name (required)', 'cmp' ), $user->display_name, 'text', 'maxlength="80" required autocomplete="nickname"', __( 'The name other people see, for example on events you submit.', 'cmp' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<p class="cmp-muted"><?php printf( /* translators: %s: username */ esc_html__( 'Username: %s (can\'t be changed)', 'cmp' ), '<strong>' . esc_html( $user->user_login ) . '</strong>' ); ?></p>
			<button type="submit" class="cmp-btn"><?php esc_html_e( 'Save details', 'cmp' ); ?></button>
			</form>
		</section>

		<section class="cmp-panel" id="cmp-email">
			<h3 class="cmp-panel-title" id="cmp-email-title"><?php esc_html_e( 'Email address', 'cmp' ); ?></h3>
			<p>
				<?php
				printf( /* translators: %s: current email address */ esc_html__( 'Current address: %s', 'cmp' ), '<strong>' . esc_html( $user->user_email ) . '</strong>' );
				echo ' <span class="cmp-badge' . ( $verified ? '' : ' cmp-badge-warn' ) . '">' . ( $verified ? esc_html__( '✓ Confirmed', 'cmp' ) : esc_html__( '! Not confirmed yet', 'cmp' ) ) . '</span>';
				?>
			</p>
			<?php if ( $pending ) : ?>
				<div class="cmp-notice cmp-notice-info" role="status">
					<?php printf( /* translators: %s: new email address */ esc_html__( 'Waiting for you to confirm %s from the link we emailed there.', 'cmp' ), '<strong>' . esc_html( $pending ) . '</strong>' ); ?>
					<?php echo self::form_open( 'email_cancel', 'cmp-email-title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline"><?php esc_html_e( 'Cancel the change', 'cmp' ); ?></button>
					</form>
				</div>
			<?php endif; ?>
			<?php
			echo self::form_open( 'email', 'cmp-email-title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::field( 'new_email', __( 'New email address', 'cmp' ), '', 'email', 'required autocomplete="email"', __( 'We email a link to the new address. The change happens once you open it.', 'cmp' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::field( 'email_current_password', __( 'Current password', 'cmp' ), '', 'password', $current ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<button type="submit" class="cmp-btn"><?php esc_html_e( 'Change email', 'cmp' ); ?></button>
			</form>
		</section>

		<section class="cmp-panel" id="cmp-password">
			<h3 class="cmp-panel-title" id="cmp-password-title"><?php esc_html_e( 'Password', 'cmp' ); ?></h3>
			<?php
			echo self::form_open( 'password', 'cmp-password-title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::field( 'current_password', __( 'Current password', 'cmp' ), '', 'password', $current ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::field( 'new_password', __( 'New password', 'cmp' ), '', 'password', 'required minlength="' . self::MIN_PASSWORD . '" autocomplete="new-password"', sprintf( /* translators: %d: minimum length */ __( 'At least %d characters. A few unrelated words make a strong, memorable password.', 'cmp' ), self::MIN_PASSWORD ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::field( 'confirm_password', __( 'New password again', 'cmp' ), '', 'password', 'required minlength="' . self::MIN_PASSWORD . '" autocomplete="new-password"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<p class="cmp-check cmp-check-small"><input type="checkbox" id="cmp_show_passwords" data-cmp-show-passwords /><label for="cmp_show_passwords"><?php esc_html_e( 'Show passwords', 'cmp' ); ?></label></p>
			<button type="submit" class="cmp-btn"><?php esc_html_e( 'Change password', 'cmp' ); ?></button>
			</form>
			<p class="cmp-muted"><?php esc_html_e( 'Changing your password signs you out on every other device.', 'cmp' ); ?></p>
		</section>

		<section class="cmp-panel" id="cmp-sessions">
			<h3 class="cmp-panel-title" id="cmp-sessions-title"><?php esc_html_e( 'Signed-in devices', 'cmp' ); ?></h3>
			<p>
				<?php
				echo esc_html(
					$others
						? sprintf( /* translators: %d: number of other signed-in sessions */ _n( 'You are also signed in on %d other device or browser.', 'You are also signed in on %d other devices or browsers.', $others, 'cmp' ), $others )
						: __( 'You are only signed in here.', 'cmp' )
				);
				?>
			</p>
			<div class="cmp-actions">
				<?php if ( $others ) : ?>
					<?php echo self::form_open( 'sessions', 'cmp-sessions-title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<button type="submit" class="cmp-btn cmp-btn-outline"><?php esc_html_e( 'Sign out everywhere else', 'cmp' ); ?></button>
					</form>
				<?php endif; ?>
				<a class="cmp-btn cmp-btn-outline" href="<?php echo esc_url( wp_logout_url( CMP_Settings::member_page_url() ) ); ?>"><?php esc_html_e( 'Sign out', 'cmp' ); ?></a>
			</div>
		</section>

		<?php echo self::render_preferences( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

		<section class="cmp-panel" id="cmp-privacy">
			<h3 class="cmp-panel-title" id="cmp-privacy-title"><?php esc_html_e( 'Your data', 'cmp' ); ?></h3>
			<p><?php esc_html_e( 'Ask for a copy of everything this site stores about you, or ask for your account and data to be deleted. We email you first to confirm it was you.', 'cmp' ); ?></p>
			<div class="cmp-actions">
				<?php echo self::form_open( 'privacy', 'cmp-privacy-title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="request" value="export" />
				<button type="submit" class="cmp-btn cmp-btn-outline"><?php esc_html_e( 'Email me a copy of my data', 'cmp' ); ?></button>
				</form>
				<?php echo self::form_open( 'privacy', 'cmp-privacy-title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="request" value="erase" />
				<button type="submit" class="cmp-btn cmp-btn-outline cmp-btn-danger" data-cmp-confirm="<?php esc_attr_e( 'Ask for your account and data to be deleted? We will email you to confirm first.', 'cmp' ); ?>"><?php esc_html_e( 'Ask to delete my account', 'cmp' ); ?></button>
				</form>
			</div>
			<?php if ( CMP_Settings::get( 'privacy_notice_url' ) ) : ?>
				<p class="cmp-muted"><a href="<?php echo esc_url( CMP_Settings::get( 'privacy_notice_url' ) ); ?>"><?php esc_html_e( 'Member privacy notice', 'cmp' ); ?></a></p>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}
}
