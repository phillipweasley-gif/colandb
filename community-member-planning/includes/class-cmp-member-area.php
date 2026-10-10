<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The [cmp_member_area] page: walks an account through the access gate
 * (sign in → verify email → 18+ attestation) and then shows the member
 * home. Also keeps the member page out of caches, search engines, the
 * sitemap and site search (brief §2/§5).
 */
class CMP_Member_Area {

	const NONCE_SEND   = 'cmp_send_verification';
	const NONCE_ATTEST = 'cmp_attest';

	public static function init() {
		add_shortcode( 'cmp_member_area', array( __CLASS__, 'render' ) );
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_on_member_page' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'leave_out_ai_tools' ), 9999 );
		add_action( 'wp_print_scripts', array( __CLASS__, 'leave_out_ai_tools' ), 1 );
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'leave_out_ai_tools' ), 1 );
		// Elementor's editor preview renders the shortcode without running the
		// page's normal head, so the stylesheet is loaded there explicitly.
		add_action( 'elementor/preview/enqueue_styles', array( __CLASS__, 'enqueue_style' ) );

		add_action( 'admin_post_cmp_send_verification', array( __CLASS__, 'handle_send_verification' ) );
		add_action( 'admin_post_cmp_attest', array( __CLASS__, 'handle_attest' ) );
		add_action( 'admin_post_nopriv_cmp_send_verification', array( __CLASS__, 'redirect_to_login' ) );
		add_action( 'admin_post_nopriv_cmp_attest', array( __CLASS__, 'redirect_to_login' ) );

		add_action( 'template_redirect', array( __CLASS__, 'private_page_headers' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'exclude_from_sitemap' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_from_search' ) );
	}

	public static function register_assets() {
		wp_register_style( 'cmp-member', CMP_URL . 'assets/css/member.css', array(), CMP_VERSION );
		wp_register_script( 'cmp-member', CMP_URL . 'assets/js/member.js', array(), CMP_VERSION, true );
	}

	public static function enqueue_style() {
		wp_enqueue_style( 'cmp-member' );
	}

	/**
	 * In the <head> on the member page, so it never renders unstyled first.
	 * (render() also enqueues it, for the shortcode used on another page.)
	 */
	public static function enqueue_on_member_page() {
		if ( self::is_member_page() ) {
			self::enqueue_style();
		}
	}

	/**
	 * Elementor's AI assistant ("Angie") loads for administrators on every
	 * front-end page, inside a frame from Elementor's own site. That frame
	 * makes the browser ask "Leave site?" on every link and form, which our
	 * page can't switch off (it's another site's frame), so sending a
	 * message looked like it failed (owner, 2026-10-05). It also has no
	 * business on private member pages: the privacy statement promises
	 * member content isn't shown to AI tools. So on the member page its
	 * scripts and styles are left out; it still works everywhere else,
	 * including the Elementor editor. (0.15.2)
	 *
	 * Filter cmp_leave_out_handle( $leave_out, $handle ) to change the list.
	 */
	public static function leave_out_ai_tools() {
		if ( is_admin() || ! self::is_member_page() ) {
			return;
		}
		foreach ( array( wp_scripts(), wp_styles() ) as $deps ) {
			foreach ( array_merge( $deps->queue, array_keys( $deps->registered ) ) as $handle ) {
				$match = 0 === strpos( $handle, 'angie' ) || false !== strpos( $handle, 'to-angie' );
				if ( apply_filters( 'cmp_leave_out_handle', $match, $handle ) ) {
					$deps->dequeue( $handle );
				}
			}
		}
	}

	private static function is_member_page() {
		$page_id = (int) CMP_Settings::get( 'member_page_id' );
		return $page_id && is_page( $page_id );
	}

	public static function private_page_headers() {
		if ( ! self::is_member_page() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // Honoured by the common page-cache plugins.
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		// The host replaces Cache-Control with "public, max-age=300" for
		// signed-out visitors, so a browser could otherwise show its stored
		// signed-out copy for 5 minutes after the visitor signs in. Vary:
		// Cookie makes the browser treat a different login state as a
		// different page.
		header( 'Vary: Cookie', false );
	}

	public static function robots( $robots ) {
		if ( self::is_member_page() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	public static function exclude_from_sitemap( $args, $post_type ) {
		$page_id = (int) CMP_Settings::get( 'member_page_id' );
		if ( 'page' === $post_type && $page_id ) {
			$args['post__not_in'] = array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), array( $page_id ) );
		}
		return $args;
	}

	public static function exclude_from_search( $query ) {
		$page_id = (int) CMP_Settings::get( 'member_page_id' );
		if ( $page_id && ! is_admin() && $query->is_main_query() && $query->is_search() ) {
			$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), array( $page_id ) ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Form handlers
	 * ---------------------------------------------------------------- */

	public static function redirect_to_login() {
		wp_safe_redirect( self::login_url() );
		exit;
	}

	public static function handle_send_verification() {
		self::check_nonce( self::NONCE_SEND );
		$result = CMP_Email_Verification::send( get_current_user_id() );
		self::back( is_wp_error( $result ) ? $result->get_error_code() : 'sent' );
	}

	public static function handle_attest() {
		self::check_nonce( self::NONCE_ATTEST );
		$user_id = get_current_user_id();

		// The gate is ordered: no attestation before the email is verified.
		if ( CMP_Access::STATE_UNATTESTED !== CMP_Access::state( $user_id ) ) {
			self::back();
		}
		if ( CMP_Birth_Date::is_blocked( $user_id ) ) {
			self::back();
		}
		$dob = CMP_Birth_Date::from_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in check_nonce().
		if ( is_wp_error( $dob ) ) {
			if ( 'dob_under_18' === $dob->get_error_code() ) {
				// Lock rather than let a different year be tried next.
				CMP_Birth_Date::block( $user_id );
				self::back();
			}
			self::back( $dob->get_error_code() );
		}

		CMP_Birth_Date::store( $user_id, $dob, 'member_area' );
		CMP_Access::record_attestation( $user_id );
		CMP_Notifications::add(
			$user_id,
			'account',
			__( 'Welcome to the member area. Your calendars stay private. On your profile, anything you fill in is shown to signed-in members unless you switch it off.', 'cmp' ),
			'',
			false
		);
		self::back( 'welcome' );
	}

	private static function check_nonce( $action ) {
		if ( ! isset( $_POST['_cmp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cmp_nonce'] ) ), $action ) ) {
			self::back( 'expired' );
		}
	}

	private static function back( $notice = '' ) {
		$url = CMP_Settings::member_page_url();
		wp_safe_redirect( $notice ? add_query_arg( 'cmp_notice', $notice, $url ) : $url );
		exit;
	}

	private static function login_url() {
		// A unique address to come back to after signing in, so no cached
		// signed-out copy of the member page can be shown instead.
		$back = add_query_arg( 'cmp_in', wp_rand( 100000, 999999 ), CMP_Settings::member_page_url() );
		return class_exists( 'CEC_Admin_Settings' ) ? CEC_Admin_Settings::login_url( $back ) : wp_login_url( $back );
	}

	private static function register_url() {
		$back = CMP_Settings::member_page_url();
		return class_exists( 'CEC_Admin_Settings' ) ? CEC_Admin_Settings::register_url( $back ) : wp_registration_url();
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	private static function notice_html() {
		$notice = isset( $_GET['cmp_notice'] ) ? sanitize_key( wp_unslash( $_GET['cmp_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map    = array(
			'sent'            => array( 'success', __( 'Verification email sent. Open the link in it to continue. It can take a few minutes to arrive; check your spam folder too.', 'cmp' ) ),
			'verified'        => array( 'success', __( 'Thanks, your email address is confirmed.', 'cmp' ) ),
			'verify_failed'   => array( 'error', __( 'That verification link is invalid, already used, or expired. Request a new one below.', 'cmp' ) ),
			'rate_limited'    => array( 'error', __( 'A verification email was sent a few minutes ago. Please check your inbox (and spam folder) before requesting another.', 'cmp' ) ),
			'mail_failed'     => array( 'error', __( "We couldn't send the verification email. Please try again later or contact the site administrator.", 'cmp' ) ),
			'no_email'        => array( 'error', __( 'Your account has no valid email address. Please change it on the Account tab.', 'cmp' ) ),
			'dob_missing'     => array( 'error', __( 'Please enter your date of birth.', 'cmp' ) ),
			'dob_invalid'     => array( 'error', __( 'That date of birth isn\'t a real date. Please check it.', 'cmp' ) ),
			'expired'         => array( 'error', __( 'Your session expired. Please try again.', 'cmp' ) ),
			'welcome'         => array( 'success', __( "You're in. Welcome to the member area.", 'cmp' ) ),
		);
		$map += CMP_Account::notices() + CMP_Profiles::notices() + CMP_Profile_Images::notices() + CMP_Dynamics::notices() + CMP_Homework::notices() + CMP_Chastity::notices() + CMP_Feed::notices() + CMP_Messages::notices() + CMP_Follows::notices() + CMP_Groups::notices() + CMP_Directory::notices() + CMP_Nods::notices() + CMP_Calendar::notices() + CMP_Reminders::notices();
		if ( ! isset( $map[ $notice ] ) ) {
			return '';
		}
		$role = 'error' === $map[ $notice ][0] ? 'alert' : 'status';
		return sprintf(
			'<div class="cmp-notice cmp-notice-%1$s" role="%2$s">%3$s</div>',
			esc_attr( $map[ $notice ][0] ),
			esc_attr( $role ),
			esc_html( $map[ $notice ][1] )
		);
	}

	public static function render() {
		wp_enqueue_style( 'cmp-member' );

		$state = CMP_Access::state();
		$tab   = self::current_tab();
		// Forms and the feed keep a reading width; profiles, Members, messages and the rest use the full width (0.13.1).
		$narrow = in_array( $tab, array( CMP_Profiles::TAB, CMP_Account::TAB, CMP_Onboarding::TAB, CMP_Feed::TAB ), true ) || CMP_Access::STATE_MEMBER !== $state;
		$out    = '<div class="cmp-member-area' . ( $narrow ? ' is-narrow' : '' ) . '">';
		$out   .= self::config_warning_html();
		if ( CMP_Access::STATE_LOGGED_OUT !== $state ) {
			$out .= self::tabs_html( $tab, $state );
		}
		$out .= self::notice_html();
		if ( CMP_Access::STATE_MEMBER === $state && CMP_Account::TAB !== $tab ) {
			$out .= CMP_App::install_html( true ); // Shown by app.js only where it applies (0.19.0).
		}
		if ( CMP_Access::STATE_LOGGED_OUT !== $state && CMP_Account::TAB === $tab ) {
			wp_enqueue_script( 'cmp-member' );
			return $out . CMP_Account::render() . '</div>';
		}
		// Step-by-step profile setup (0.5.0): its own tab, and what a new
		// member's home shows until they finish or leave it.
		$setup_step = isset( $_GET['cmp_step'] ) ? absint( $_GET['cmp_step'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( CMP_Access::STATE_MEMBER === $state && ( CMP_Onboarding::TAB === $tab || ( 'home' === $tab && ! isset( $_GET['cmp_member'] ) && CMP_Onboarding::should_start( get_current_user_id() ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_enqueue_script( 'cmp-member' );
			return $out . CMP_Onboarding::render( get_current_user_id(), $setup_step ) . '</div>';
		}
		if ( CMP_Access::STATE_MEMBER === $state && CMP_Directory::TAB === $tab ) {
			wp_enqueue_script( 'cmp-member' );
			return $out . CMP_Directory::render( get_current_user_id() ) . '</div>';
		}
		if ( CMP_Access::STATE_MEMBER === $state && CMP_Messages::TAB === $tab ) {
			return $out . CMP_Messages::render( get_current_user_id() ) . '</div>';
		}
		if ( CMP_Access::STATE_MEMBER === $state && CMP_Feed::TAB === $tab ) {
			return $out . CMP_Feed::render( get_current_user_id() ) . '</div>';
		}
		if ( CMP_Access::STATE_MEMBER === $state && CMP_Chastity::TAB === $tab ) {
			return $out . CMP_Chastity::render( get_current_user_id() ) . '</div>';
		}
		if ( CMP_Access::STATE_MEMBER === $state && CMP_Homework::TAB === $tab ) {
			return $out . CMP_Homework::render( get_current_user_id() ) . '</div>';
		}
		if ( CMP_Access::STATE_MEMBER === $state && CMP_Calendar::TAB === $tab ) {
			return $out . CMP_Calendar::render( get_current_user_id() ) . '</div>';
		}
		if ( CMP_Access::STATE_MEMBER === $state && CMP_Dynamics::TAB === $tab ) {
			return $out . CMP_Dynamics::render( get_current_user_id() ) . '</div>';
		}
		// Profiles are for full members only; anyone else sees their next step.
		if ( CMP_Access::STATE_MEMBER === $state && CMP_Profiles::TAB === $tab ) {
			wp_enqueue_script( 'cmp-member' );
			return $out . CMP_Profiles::render() . '</div>';
		}
		$member_id = isset( $_GET['cmp_member'] ) ? absint( $_GET['cmp_member'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( CMP_Access::STATE_MEMBER === $state && $member_id ) {
			wp_enqueue_script( 'cmp-member' ); // Profile tabs, the Propose form, confirmations.
			return $out . CMP_Profiles::render_member( $member_id ) . '</div>';
		}

		switch ( $state ) {
			case CMP_Access::STATE_LOGGED_OUT:
				$out .= self::render_logged_out();
				break;
			case CMP_Access::STATE_UNVERIFIED:
				$out .= self::render_unverified();
				break;
			case CMP_Access::STATE_UNATTESTED:
				$out .= self::render_unattested();
				break;
			default:
				$out .= self::render_home();
		}
		return $out . '</div>';
	}

	private static function current_tab() {
		$tab = isset( $_GET['cmp_tab'] ) ? sanitize_key( wp_unslash( $_GET['cmp_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, array( CMP_Account::TAB, CMP_Profiles::TAB, CMP_Onboarding::TAB, CMP_Dynamics::TAB, CMP_Homework::TAB, CMP_Chastity::TAB, CMP_Feed::TAB, CMP_Messages::TAB, CMP_Directory::TAB, CMP_Calendar::TAB ), true ) ? $tab : 'home';
	}

	/**
	 * Home | Account. Before the account is a full member, "Home" is the
	 * next step of the sign-up (confirm email, 18+).
	 */
	private static function tabs_html( $current, $state ) {
		$tabs = array(
			'home'           => array( CMP_Access::STATE_MEMBER === $state ? __( 'Home', 'cmp' ) : __( 'Get started', 'cmp' ), CMP_Settings::member_page_url() ),
			CMP_Account::TAB => array( __( 'Account', 'cmp' ), CMP_Account::url() ),
		);
		if ( CMP_Access::STATE_MEMBER === $state ) {
			$waiting = CMP_Dynamics::pending_for( get_current_user_id() );
			/* translators: %d: invitations waiting */
			$dyn_label = $waiting ? sprintf( __( 'Dynamics (%d)', 'cmp' ), $waiting ) : __( 'Dynamics', 'cmp' );
			$tabs      = array_slice( $tabs, 0, 1, true ) + array( CMP_Feed::TAB => array( __( 'Feed', 'cmp' ), CMP_Feed::url() ), CMP_Directory::TAB => array( __( 'Members', 'cmp' ), CMP_Directory::url() ), CMP_Messages::TAB => array( CMP_Messages::tab_label( get_current_user_id() ), CMP_Messages::url() ), CMP_Calendar::TAB => array( __( 'Calendar', 'cmp' ), CMP_Calendar::url() ), CMP_Profiles::TAB => array( __( 'Profile', 'cmp' ), CMP_Profiles::url() ), CMP_Dynamics::TAB => array( $dyn_label, CMP_Dynamics::url() ), CMP_Homework::TAB => array( __( 'Homework', 'cmp' ), CMP_Homework::url() ), CMP_Chastity::TAB => array( __( 'Chastity', 'cmp' ), CMP_Chastity::url() ) ) + array_slice( $tabs, 1, null, true );
		}
		$html = '<nav class="cmp-tabs" aria-label="' . esc_attr__( 'Member area', 'cmp' ) . '"><ul>';
		foreach ( $tabs as $key => $tab ) {
			$html .= sprintf(
				'<li><a href="%1$s"%2$s>%3$s</a></li>',
				esc_url( $tab[1] ),
				$key === $current ? ' aria-current="page" class="is-current"' : '',
				esc_html( $tab[0] )
			);
		}
		$html .= '</ul><a class="cmp-tabs-signout" href="' . esc_url( wp_logout_url( CMP_Settings::member_page_url() ) ) . '">' . esc_html__( 'Sign out', 'cmp' ) . '</a></nav>';
		return $html;
	}

	/**
	 * Only administrators see this: the shortcode works anywhere, but the
	 * no-cache/noindex protection only applies to the configured page.
	 */
	private static function config_warning_html() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		// The page being shown or edited. In Elementor's editor the shortcode
		// is rendered outside the normal page request, so the queried object
		// isn't the page; Elementor passes the page ID instead.
		$member_page = (int) CMP_Settings::get( 'member_page_id' );
		$candidates  = array(
			get_queried_object_id(),
			(int) get_the_ID(),
			isset( $_GET['elementor-preview'] ) ? absint( $_GET['elementor-preview'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			isset( $_REQUEST['editor_post_id'] ) ? absint( $_REQUEST['editor_post_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		if ( $member_page && in_array( $member_page, $candidates, true ) ) {
			return '';
		}
		return '<div class="cmp-notice cmp-notice-error" role="alert">' . esc_html__( 'Administrator note: this page is not set as the member area page under Settings → Member Planning, so it is not protected from page caching and search indexing. Only administrators see this message.', 'cmp' ) . '</div>';
	}

	private static function render_logged_out() {
		ob_start();
		?>
		<section class="cmp-step" aria-labelledby="cmp-step-title">
			<h2 id="cmp-step-title" class="cmp-title"><?php esc_html_e( 'Member area', 'cmp' ); ?></h2>
			<p><?php esc_html_e( 'Sign in with your site account to use the member area.', 'cmp' ); ?></p>
			<p class="cmp-actions">
				<a class="cmp-btn" href="<?php echo esc_url( self::login_url() ); ?>"><?php esc_html_e( 'Sign in', 'cmp' ); ?></a>
				<?php if ( get_option( 'users_can_register' ) ) : ?>
					<a class="cmp-btn cmp-btn-outline" href="<?php echo esc_url( self::register_url() ); ?>"><?php esc_html_e( 'Create an account', 'cmp' ); ?></a>
				<?php endif; ?>
			</p>
		</section>
		<?php
		return ob_get_clean();
	}

	private static function steps_html( $current ) {
		$steps = array(
			1 => __( 'Confirm your email', 'cmp' ),
			2 => __( 'Enter your date of birth', 'cmp' ),
		);
		$html = '<ol class="cmp-steps">';
		foreach ( $steps as $n => $label ) {
			$state = $n < $current ? 'done' : ( $n === $current ? 'current' : 'todo' );
			$html .= sprintf(
				'<li class="cmp-steps-%1$s"%2$s><span class="cmp-steps-num" aria-hidden="true">%3$s</span> %4$s<span class="screen-reader-text"> (%5$s)</span></li>',
				esc_attr( $state ),
				'current' === $state ? ' aria-current="step"' : '',
				'done' === $state ? '&#10003;' : (int) $n,
				esc_html( $label ),
				esc_html( 'done' === $state ? __( 'completed', 'cmp' ) : ( 'current' === $state ? __( 'current step', 'cmp' ) : __( 'not started', 'cmp' ) ) )
			);
		}
		return $html . '</ol>';
	}

	private static function render_unverified() {
		$user = wp_get_current_user();
		ob_start();
		?>
		<section class="cmp-step" aria-labelledby="cmp-step-title">
			<h2 id="cmp-step-title" class="cmp-title"><?php esc_html_e( 'Confirm your email', 'cmp' ); ?></h2>
			<?php echo self::steps_html( 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in steps_html(). ?>
			<p>
				<?php
				printf(
					/* translators: %s: the account's email address */
					esc_html__( 'Before you can use the member area, we need to confirm that %s is your email address. We will send you a link that works once and expires in 7 days.', 'cmp' ),
					'<strong>' . esc_html( $user->user_email ) . '</strong>'
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cmp_send_verification" />
				<?php wp_nonce_field( self::NONCE_SEND, '_cmp_nonce' ); ?>
				<button type="submit" class="cmp-btn"><?php esc_html_e( 'Send verification email', 'cmp' ); ?></button>
			</form>
			<p class="cmp-muted">
				<?php
				printf(
					/* translators: %s: link to the Account tab */
					esc_html__( 'Wrong address? Change it in %s first.', 'cmp' ),
					'<a href="' . esc_url( CMP_Account::url() . '#cmp-email' ) . '">' . esc_html__( 'your account', 'cmp' ) . '</a>'
				);
				?>
			</p>
		</section>
		<?php
		return ob_get_clean();
	}

	private static function render_unattested() {
		$privacy = CMP_Settings::get( 'privacy_notice_url' );
		ob_start();
		if ( CMP_Birth_Date::is_blocked( get_current_user_id() ) ) :
			?>
			<section class="cmp-step" aria-labelledby="cmp-step-title">
				<h2 id="cmp-step-title" class="cmp-title"><?php esc_html_e( 'The member area is for adults 18 and over', 'cmp' ); ?></h2>
				<p><?php esc_html_e( 'The date of birth given for this account is under 18, so the member area is closed to it. If that date was a mistake, please contact the site team.', 'cmp' ); ?></p>
			</section>
			<?php
			return ob_get_clean();
		endif;
		?>
		<section class="cmp-step" aria-labelledby="cmp-step-title">
			<h2 id="cmp-step-title" class="cmp-title"><?php esc_html_e( 'One more step', 'cmp' ); ?></h2>
			<?php echo self::steps_html( 2 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in steps_html(). ?>
			<p><?php esc_html_e( 'The member area is for adults 18 and over, so we ask every member for their date of birth. You\'ll only be asked this once.', 'cmp' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmp-form">
				<input type="hidden" name="action" value="cmp_attest" />
				<?php wp_nonce_field( self::NONCE_ATTEST, '_cmp_nonce' ); ?>
				<div class="cmp-field">
					<span class="cmp-label" id="cmp_dob_label"><?php esc_html_e( 'Date of birth', 'cmp' ); ?></span>
					<?php echo CMP_Birth_Date::fields_html( 'cmp_dob', '', 'cmp_attest_help' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
					<p id="cmp_attest_help" class="cmp-muted">
						<?php esc_html_e( 'Never shown to anyone; only your age can appear on your profile, and only if you choose. It can\'t be changed later from your account. If it\'s wrong, contact the site team.', 'cmp' ); ?>
						<?php if ( $privacy ) : ?>
							<?php
							printf(
								/* translators: %s: link to the member privacy notice */
								esc_html__( 'See the %s for how member information is handled.', 'cmp' ),
								'<a href="' . esc_url( $privacy ) . '">' . esc_html__( 'member privacy notice', 'cmp' ) . '</a>'
							);
							?>
						<?php endif; ?>
					</p>
				</div>
				<button type="submit" class="cmp-btn"><?php esc_html_e( 'Continue to the member area', 'cmp' ); ?></button>
			</form>
		</section>
		<?php
		return ob_get_clean();
	}

	private static function render_home() {
		$user_id = get_current_user_id();
		$items   = array_map( array( 'CMP_Notifications', 'to_public' ), CMP_Notifications::for_user( $user_id, 20 ) );
		$unread  = CMP_Notifications::unread_count( $user_id );

		wp_enqueue_script( 'cmp-member' );
		wp_localize_script(
			'cmp-member',
			'CMP',
			array(
				'root'  => esc_url_raw( rest_url( CMP_Rest::NS . '/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'markRead' => __( 'Mark as read', 'cmp' ),
					'read'     => __( 'Read', 'cmp' ),
					'error'    => __( 'Something went wrong. Please try again.', 'cmp' ),
					'offline'  => __( "You're offline. Reconnect and try again.", 'cmp' ),
					'denied'   => __( 'Your session has ended. Reload the page and sign in again.', 'cmp' ),
					'retry'    => __( 'Retry', 'cmp' ),
				),
			)
		);

		ob_start();
		?>
		<section class="cmp-home cmp-step" aria-labelledby="cmp-home-title">
			<h2 id="cmp-home-title" class="cmp-title">
				<?php
				/* translators: %s: member's display name */
				printf( esc_html__( 'Welcome, %s', 'cmp' ), esc_html( wp_get_current_user()->display_name ) );
				?>
			</h2>

			<?php echo CMP_Onboarding::nudge_html( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

			<div class="cmp-section cmp-inbox" data-cmp-inbox>
				<div class="cmp-panel-head">
					<h3 class="cmp-panel-title">
						<?php esc_html_e( 'Notifications', 'cmp' ); ?>
						<span class="cmp-count" data-cmp-unread aria-label="<?php echo esc_attr( sprintf( /* translators: %d: unread count */ _n( '%d unread', '%d unread', $unread, 'cmp' ), $unread ) ); ?>"<?php echo $unread ? '' : ' hidden'; ?>><?php echo (int) $unread; ?></span>
					</h3>
					<button type="button" class="cmp-btn cmp-btn-small cmp-btn-outline" data-cmp-read-all<?php echo $unread ? '' : ' hidden'; ?>><?php esc_html_e( 'Mark all as read', 'cmp' ); ?></button>
				</div>
				<div class="cmp-inline-status" data-cmp-status role="status" aria-live="polite"></div>
				<?php if ( ! $items ) : ?>
					<p class="cmp-empty"><?php esc_html_e( "No notifications yet. You'll see invitations and changes to what's shared with you here.", 'cmp' ); ?></p>
				<?php else : ?>
					<ul class="cmp-notifications">
						<?php foreach ( $items as $n ) : ?>
							<li class="cmp-notification<?php echo $n['read'] ? ' is-read' : ''; ?>" data-id="<?php echo (int) $n['id']; ?>">
								<span class="cmp-notification-state" aria-hidden="true"><?php echo $n['read'] ? '&#9675;' : '&#9679;'; ?></span>
								<div class="cmp-notification-body">
									<?php if ( $n['url'] ) : ?>
										<a href="<?php echo esc_url( $n['url'] ); ?>"><?php echo esc_html( $n['message'] ); ?></a>
									<?php else : ?>
										<span><?php echo esc_html( $n['message'] ); ?></span>
									<?php endif; ?>
									<time class="cmp-muted" datetime="<?php echo esc_attr( $n['date'] ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $n['date'] ), CMP_Notifications::user_timezone( $user_id ) ) ); ?></time>
									<span class="screen-reader-text"><?php echo $n['read'] ? esc_html__( 'Read', 'cmp' ) : esc_html__( 'Unread', 'cmp' ); ?></span>
								</div>
								<?php if ( ! $n['read'] ) : ?>
									<button type="button" class="cmp-btn cmp-btn-small cmp-btn-outline" data-cmp-read><?php esc_html_e( 'Mark as read', 'cmp' ); ?></button>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}
}
