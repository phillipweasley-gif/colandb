<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_Admin_Settings {

	const OPTION = 'cec_settings';

	public static function defaults() {
		return array(
			'color_primary'   => '#e1f577',
			'color_secondary' => '#ff50d7',
			'color_accent'    => '#ff50d7',
			'color_free'      => '#e1f577',
			'color_text'      => '#f5f4f7',
			'color_bg'        => '#15121f',
			'upcoming_count'             => 8,
			'first_weekday'              => '0',
			'require_login'              => 1,
			'auto_publish'               => 0,
			'dashboard_page_url'         => '',
			'allow_guest_submission'     => 0,
			'submit_page_url'            => '',
			'login_page_url'             => '',
			'register_page_url'          => '',
			'manage_submission_page_url' => '',
			'subscribe_page_url'         => '',
			'volunteer_interest_areas'   => 'Event Support, Administrative, Outreach, Fundraising, Community Education',
			'volunteer_notify_email'     => '',
		);
	}

	public static function get( $key = null ) {
		$opts = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		if ( $key ) {
			return isset( $opts[ $key ] ) ? $opts[ $key ] : null;
		}
		return $opts;
	}

	public static function submit_url() {
		$page = self::get( 'submit_page_url' );
		return $page ? $page : home_url( '/' );
	}

	public static function login_url( $redirect = '' ) {
		$page = self::get( 'login_page_url' );
		return $page ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $page ) : wp_login_url( $redirect );
	}

	public static function register_url( $redirect = '' ) {
		$page = self::get( 'register_page_url' );
		return $page ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $page ) : wp_registration_url();
	}

	public static function get_colors() {
		$opts = self::get();
		return array(
			'primary'   => $opts['color_primary'],
			'secondary' => $opts['color_secondary'],
			'accent'    => $opts['color_accent'],
			'free'      => $opts['color_free'],
			'text'      => $opts['color_text'],
			'bg'        => $opts['color_bg'],
		);
	}

	const TEST_EMAIL_NONCE = 'cec_send_test_email';
	const CLEANUP_NONCE    = 'cec_cleanup_duplicate_pages';

	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=cec_event',
			__( 'Calendar Settings', 'cec' ),
			__( 'Settings', 'cec' ),
			'manage_options',
			'community-calendar-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		register_setting( self::OPTION, self::OPTION, array( __CLASS__, 'sanitize' ) );
	}

	/**
	 * Every notification the plugin sends — guest edit links, subscription
	 * confirms, approval alerts, volunteer notifications — depends on
	 * wp_mail() working, with no other way to check that from the admin UI
	 * short of triggering a real submission. This just fires one and reports
	 * wp_mail()'s own success/failure back.
	 */
	public static function handle_send_test_email() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cec' ) );
		}
		check_admin_referer( self::TEST_EMAIL_NONCE );

		$user = wp_get_current_user();
		$sent = wp_mail(
			$user->user_email,
			sprintf( __( '[%s] Community Events Calendar test email', 'cec' ), get_bloginfo( 'name' ) ),
			__( "This is a test email from the Community Events Calendar plugin's Settings page. If you received this, outgoing email is working.", 'cec' )
		);

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'community-calendar-settings', 'cec_test_email' => $sent ? '1' : '0' ),
				admin_url( 'edit.php?post_type=cec_event' )
			)
		);
		exit;
	}

	public static function sanitize( $input ) {
		$out = self::defaults();
		foreach ( array( 'color_primary', 'color_secondary', 'color_accent', 'color_free', 'color_text', 'color_bg' ) as $c ) {
			if ( ! empty( $input[ $c ] ) ) {
				$out[ $c ] = sanitize_hex_color( $input[ $c ] );
			}
		}
		$out['upcoming_count']             = isset( $input['upcoming_count'] ) ? absint( $input['upcoming_count'] ) : 8;
		$out['first_weekday']              = isset( $input['first_weekday'] ) && in_array( $input['first_weekday'], array( '0', '1', '2', '3', '4', '5', '6' ), true ) ? $input['first_weekday'] : '0';
		$out['require_login']              = isset( $input['require_login'] ) ? 1 : 0;
		$out['auto_publish']               = isset( $input['auto_publish'] ) ? 1 : 0;
		$out['dashboard_page_url']         = isset( $input['dashboard_page_url'] ) ? esc_url_raw( $input['dashboard_page_url'] ) : '';
		$out['allow_guest_submission']     = isset( $input['allow_guest_submission'] ) ? 1 : 0;
		$out['submit_page_url']            = isset( $input['submit_page_url'] ) ? esc_url_raw( $input['submit_page_url'] ) : '';
		$out['login_page_url']             = isset( $input['login_page_url'] ) ? esc_url_raw( $input['login_page_url'] ) : '';
		$out['register_page_url']          = isset( $input['register_page_url'] ) ? esc_url_raw( $input['register_page_url'] ) : '';
		$out['manage_submission_page_url'] = isset( $input['manage_submission_page_url'] ) ? esc_url_raw( $input['manage_submission_page_url'] ) : '';
		$out['subscribe_page_url']         = isset( $input['subscribe_page_url'] ) ? esc_url_raw( $input['subscribe_page_url'] ) : '';
		$out['volunteer_interest_areas']   = isset( $input['volunteer_interest_areas'] ) ? sanitize_text_field( $input['volunteer_interest_areas'] ) : $out['volunteer_interest_areas'];
		$out['volunteer_notify_email']     = isset( $input['volunteer_notify_email'] ) ? sanitize_email( $input['volunteer_notify_email'] ) : '';
		return $out;
	}

	public static function render_page() {
		$opts = self::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Community Calendar Settings', 'cec' ); ?></h1>
			<?php $cec_test_email_result = isset( $_GET['cec_test_email'] ) ? sanitize_text_field( wp_unslash( $_GET['cec_test_email'] ) ) : ''; ?>
			<?php if ( '' !== $cec_test_email_result ) : ?>
				<?php if ( '1' === $cec_test_email_result ) : ?>
					<div class="notice notice-success is-dismissible"><p><?php echo wp_kses_post( sprintf( __( 'Test email sent to %s. If it doesn\'t arrive, outgoing email likely needs an SMTP plugin — see the note below.', 'cec' ), '<strong>' . esc_html( wp_get_current_user()->user_email ) . '</strong>' ) ); ?></p></div>
				<?php else : ?>
					<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'wp_mail() reported failure sending the test email. Outgoing email is very likely not working on this host — an SMTP plugin is strongly recommended.', 'cec' ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Email Deliverability', 'cec' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Guest edit links, subscription confirmations, approval alerts, and volunteer notifications all depend on outgoing email working. Send a test to confirm it does before relying on it.', 'cec' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cec_send_test_email" />
				<?php wp_nonce_field( self::TEST_EMAIL_NONCE ); ?>
				<?php submit_button( __( 'Send Test Email to Me', 'cec' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php $dupes = self::duplicate_page_counts(); ?>
			<?php if ( ! empty( $dupes ) ) : ?>
				<h2><?php esc_html_e( 'Duplicate Pages', 'cec' ); ?></h2>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: comma-separated "Title (Nx)" list */
							__( 'Found extra copies of pages this plugin created: %s. This can happen from a rare timing issue during an update. Cleaning up moves every extra copy to the Trash (not permanent — you can restore any of them from there) and keeps the one your settings below already point to.', 'cec' ),
							implode( ', ', array_map( function ( $title, $count ) {
								/* translators: 1: page title, 2: number of extra copies */
								return sprintf( __( '%1$s (%2$d extra)', 'cec' ), $title, $count );
							}, array_keys( $dupes ), $dupes ) )
						)
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Move every duplicate page to the Trash? This keeps one copy of each and is reversible from the Trash.', 'cec' ) ); ?>');">
					<input type="hidden" name="action" value="cec_cleanup_duplicate_pages" />
					<?php wp_nonce_field( self::CLEANUP_NONCE ); ?>
					<?php submit_button( __( 'Clean Up Duplicate Pages', 'cec' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION ); ?>
				<h2><?php esc_html_e( 'Color Scheme', 'cec' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Match the calendar to your site theme. These apply everywhere the calendar shortcodes and Elementor widgets are used.', 'cec' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="cec_color_primary"><?php esc_html_e( 'Primary Color', 'cec' ); ?></label></th>
						<td><input type="text" class="cec-color-field" name="<?php echo esc_attr( self::OPTION ); ?>[color_primary]" id="cec_color_primary" value="<?php echo esc_attr( $opts['color_primary'] ); ?>" /> <span class="description"><?php esc_html_e( 'Headers, buttons', 'cec' ); ?></span></td>
					</tr>
					<tr>
						<th><label for="cec_color_secondary"><?php esc_html_e( 'Secondary Color', 'cec' ); ?></label></th>
						<td><input type="text" class="cec-color-field" name="<?php echo esc_attr( self::OPTION ); ?>[color_secondary]" id="cec_color_secondary" value="<?php echo esc_attr( $opts['color_secondary'] ); ?>" /> <span class="description"><?php esc_html_e( 'Highlights, today marker', 'cec' ); ?></span></td>
					</tr>
					<tr>
						<th><label for="cec_color_accent"><?php esc_html_e( 'Accent Color', 'cec' ); ?></label></th>
						<td><input type="text" class="cec-color-field" name="<?php echo esc_attr( self::OPTION ); ?>[color_accent]" id="cec_color_accent" value="<?php echo esc_attr( $opts['color_accent'] ); ?>" /> <span class="description"><?php esc_html_e( 'Event type badges, links', 'cec' ); ?></span></td>
					</tr>
					<tr>
						<th><label for="cec_color_free"><?php esc_html_e( 'Free Event Badge Color', 'cec' ); ?></label></th>
						<td><input type="text" class="cec-color-field" name="<?php echo esc_attr( self::OPTION ); ?>[color_free]" id="cec_color_free" value="<?php echo esc_attr( $opts['color_free'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="cec_color_text"><?php esc_html_e( 'Text Color', 'cec' ); ?></label></th>
						<td><input type="text" class="cec-color-field" name="<?php echo esc_attr( self::OPTION ); ?>[color_text]" id="cec_color_text" value="<?php echo esc_attr( $opts['color_text'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="cec_color_bg"><?php esc_html_e( 'Card Background', 'cec' ); ?></label></th>
						<td><input type="text" class="cec-color-field" name="<?php echo esc_attr( self::OPTION ); ?>[color_bg]" id="cec_color_bg" value="<?php echo esc_attr( $opts['color_bg'] ); ?>" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Calendar Display', 'cec' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="cec_first_weekday"><?php esc_html_e( 'First Day of Week', 'cec' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION ); ?>[first_weekday]" id="cec_first_weekday">
								<?php
								$weekday_labels = array( __( 'Sunday', 'cec' ), __( 'Monday', 'cec' ), __( 'Tuesday', 'cec' ), __( 'Wednesday', 'cec' ), __( 'Thursday', 'cec' ), __( 'Friday', 'cec' ), __( 'Saturday', 'cec' ) );
								foreach ( $weekday_labels as $i => $label ) :
									?>
									<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $opts['first_weekday'], (string) $i ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Which column the month calendar starts on, on both the public and member calendars.', 'cec' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Submissions', 'cec' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Require login to submit', 'cec' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[require_login]" value="1" <?php checked( $opts['require_login'], 1 ); ?> /> <?php esc_html_e( 'Only logged-in member accounts can submit events (recommended)', 'cec' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Auto-publish', 'cec' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[auto_publish]" value="1" <?php checked( $opts['auto_publish'], 1 ); ?> /> <?php esc_html_e( 'Skip admin approval and publish submissions immediately (not recommended)', 'cec' ); ?></label></td>
					</tr>
					<tr>
						<th><label for="cec_upcoming_count"><?php esc_html_e( 'Default "Upcoming Events" count', 'cec' ); ?></label></th>
						<td><input type="number" min="1" max="50" name="<?php echo esc_attr( self::OPTION ); ?>[upcoming_count]" id="cec_upcoming_count" value="<?php echo esc_attr( $opts['upcoming_count'] ); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Guest submissions', 'cec' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[allow_guest_submission]" value="1" <?php checked( $opts['allow_guest_submission'], 1 ); ?> /> <?php esc_html_e( 'Let visitors submit without creating an account, using just their email', 'cec' ); ?></label>
							<p class="description"><?php esc_html_e( 'When on, the submission form shows an email field for anyone not logged in. They can later request an edit link emailed to that address via the [cec_manage_submission] page instead of logging in.', 'cec' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="cec_submit_page_url"><?php esc_html_e( 'Submit Event Page URL', 'cec' ); ?></label></th>
						<td><input type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[submit_page_url]" id="cec_submit_page_url" placeholder="https://yoursite.com/submit-event/" value="<?php echo esc_attr( $opts['submit_page_url'] ); ?>" />
							<p class="description"><?php esc_html_e( 'The page with [cec_submit_event] on it. New members are sent straight here right after creating an account or logging in — auto-created and filled in for you if it was blank.', 'cec' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Accounts & Login', 'cec' ); ?></h2>
				<p class="description"><?php esc_html_e( 'These point "Log In" / "Create an Account" links at your own styled pages (using [cec_login] and [cec_register]) instead of default WordPress screens — auto-created and filled in below the first time this plugin ran, so this should already be set up. Registration still respects Settings > General > Membership.', 'cec' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="cec_login_page_url"><?php esc_html_e( 'Login Page URL', 'cec' ); ?></label></th>
						<td><input type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[login_page_url]" id="cec_login_page_url" placeholder="https://yoursite.com/login/" value="<?php echo esc_attr( $opts['login_page_url'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="cec_register_page_url"><?php esc_html_e( 'Registration Page URL', 'cec' ); ?></label></th>
						<td><input type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[register_page_url]" id="cec_register_page_url" placeholder="https://yoursite.com/register/" value="<?php echo esc_attr( $opts['register_page_url'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="cec_manage_submission_page_url"><?php esc_html_e( 'Manage Submission Page URL', 'cec' ); ?></label></th>
						<td><input type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[manage_submission_page_url]" id="cec_manage_submission_page_url" placeholder="https://yoursite.com/manage-my-event/" value="<?php echo esc_attr( $opts['manage_submission_page_url'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Create a page with [cec_manage_submission] on it and paste its URL here — required for guest edit-link emails to work.', 'cec' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Subscriptions', 'cec' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Create a page with [cec_subscribe] on it and paste its URL below. Visitors can subscribe by email to all events or to specific organizations (pulled live from your Partner Organizations list), with a confirm step and an unsubscribe link on every notification.', 'cec' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="cec_subscribe_page_url"><?php esc_html_e( 'Subscribe Page URL', 'cec' ); ?></label></th>
						<td><input type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[subscribe_page_url]" id="cec_subscribe_page_url" placeholder="https://yoursite.com/subscribe/" value="<?php echo esc_attr( $opts['subscribe_page_url'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Required for the "Get Email Updates" links shown on the calendar and organization pages, and for unsubscribe links to work.', 'cec' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="description"><?php esc_html_e( 'RSS is always available with no setup: the whole calendar feeds at [your site]/events/feed/, and each organization has its own feed from its archive page (shown as a "Subscribe (RSS)" link).', 'cec' ); ?></p>

				<h2><?php esc_html_e( 'Volunteer Inquiries', 'cec' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Add [cec_volunteer_form] to a page to collect volunteer sign-up interest. Submissions are private — reviewed under Events > Volunteer Inquiries, never published publicly.', 'cec' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="cec_volunteer_interest_areas"><?php esc_html_e( 'Interest Areas', 'cec' ); ?></label></th>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[volunteer_interest_areas]" id="cec_volunteer_interest_areas" value="<?php echo esc_attr( $opts['volunteer_interest_areas'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Comma-separated list shown as checkboxes on the form (e.g. Event Support, Outreach, Fundraising).', 'cec' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="cec_volunteer_notify_email"><?php esc_html_e( 'Notify Email', 'cec' ); ?></label></th>
						<td><input type="email" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[volunteer_notify_email]" id="cec_volunteer_notify_email" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" value="<?php echo esc_attr( $opts['volunteer_notify_email'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Who gets emailed for each new inquiry. Leave blank to use the site admin email.', 'cec' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Front-End Manager Dashboard', 'cec' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Create a page containing the [cec_admin_dashboard] shortcode, then paste its URL below. Anyone with the "Calendar Manager" role can use that page to approve, edit, and reject events — without ever logging into wp-admin.', 'cec' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="cec_dashboard_page_url"><?php esc_html_e( 'Dashboard Page URL', 'cec' ); ?></label></th>
						<td><input type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[dashboard_page_url]" id="cec_dashboard_page_url" placeholder="https://yoursite.com/calendar-admin/" value="<?php echo esc_attr( $opts['dashboard_page_url'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Used to send Calendar Managers here if they ever land in wp-admin, and as the login redirect target.', 'cec' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Delegating Approval Without Backend Access', 'cec' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Create a page (e.g. "Calendar Admin") with only the [cec_admin_dashboard] shortcode on it, and keep the link private.', 'cec' ); ?></li>
				<li><?php echo wp_kses_post( sprintf( __( 'Go to <a href="%s">Users > Add New</a> and create an account for the person you\'re delegating to, with the role set to <strong>Calendar Manager</strong>.', 'cec' ), esc_url( admin_url( 'user-new.php' ) ) ) ); ?></li>
				<li><?php esc_html_e( 'Paste the dashboard page URL into the field above and save.', 'cec' ); ?></li>
				<li><?php esc_html_e( 'Send that person the page link plus their login. They log in there, approve/reject/edit events, and never see wp-admin — if they ever try to visit it directly, they\'re redirected back to the dashboard page automatically.', 'cec' ); ?></li>
			</ol>
			<p class="description"><?php esc_html_e( 'You (as Administrator) can still do all of this from Events in wp-admin at any time — the front-end dashboard is an additional option, not a replacement.', 'cec' ); ?></p>

			<h2><?php esc_html_e( 'Manage Lists', 'cec' ); ?></h2>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=cec_partner_org&post_type=cec_event' ) ); ?>"><?php esc_html_e( 'Partner Organizations', 'cec' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=cec_venue&post_type=cec_event' ) ); ?>"><?php esc_html_e( 'Venues', 'cec' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=cec_event_type&post_type=cec_event' ) ); ?>"><?php esc_html_e( 'Event Types', 'cec' ); ?></a>
			</p>

			<h2><?php esc_html_e( 'Shortcodes', 'cec' ); ?></h2>
			<table class="widefat striped" style="max-width:800px;">
				<tr><td><code>[cec_calendar]</code></td><td><?php esc_html_e( 'Traditional month calendar view', 'cec' ); ?></td></tr>
				<tr><td><code>[cec_events view="grid"]</code></td><td><?php esc_html_e( 'Filterable grid view (or view="list")', 'cec' ); ?></td></tr>
				<tr><td><code>[cec_upcoming count="6"]</code></td><td><?php esc_html_e( 'Scrolling upcoming events widget', 'cec' ); ?></td></tr>
				<tr><td><code>[cec_submit_event]</code></td><td><?php esc_html_e( 'Front-end event submission form for members', 'cec' ); ?></td></tr>
				<tr><td><code>[cec_admin_dashboard]</code></td><td><?php esc_html_e( 'Front-end approval/management dashboard for Calendar Managers', 'cec' ); ?></td></tr>
				<tr><td><code>[cec_my_events]</code></td><td><?php esc_html_e( 'Lets a logged-in submitter view, edit, postpone/cancel, and withdraw their own submitted events', 'cec' ); ?></td></tr>
				<tr><td><code>[cec_login]</code></td><td><?php esc_html_e( 'Styled login form', 'cec' ); ?></td></tr>
				<tr><td><code>[cec_register]</code></td><td><?php esc_html_e( 'Styled account registration form (name, email, password)', 'cec' ); ?></td></tr>
				<tr><td><code>[cec_manage_submission]</code></td><td><?php esc_html_e( 'Lets a guest (no account) request an emailed edit link, or use one to edit/postpone/cancel/withdraw their event', 'cec' ); ?></td></tr>
				<tr><td><code>[cec_subscribe]</code></td><td><?php esc_html_e( 'Email subscription form — all events and/or specific organizations', 'cec' ); ?></td></tr>
				<tr><td><code>[cec_volunteer_form]</code></td><td><?php esc_html_e( 'Volunteer inquiry form — reviewed privately under Events > Volunteer Inquiries', 'cec' ); ?></td></tr>
			</table>
		</div>
		<?php
	}

	/**
	 * The front-end pages this plugin needs, keyed by the settings field
	 * that stores each one's URL. Shared by maybe_create_pages() and the
	 * duplicate-page cleanup tool so the two can never drift apart.
	 */
	private static function page_map() {
		return array(
			'submit_page_url'            => array( 'title' => __( 'Submit an Event', 'cec' ), 'shortcode' => '[cec_submit_event]' ),
			'login_page_url'              => array( 'title' => __( 'Log In', 'cec' ), 'shortcode' => '[cec_login]' ),
			'register_page_url'           => array( 'title' => __( 'Create an Account', 'cec' ), 'shortcode' => '[cec_register]' ),
			'dashboard_page_url'          => array( 'title' => __( 'Calendar Admin', 'cec' ), 'shortcode' => '[cec_admin_dashboard]' ),
			'manage_submission_page_url'  => array( 'title' => __( 'Manage My Submission', 'cec' ), 'shortcode' => '[cec_manage_submission]' ),
			'subscribe_page_url'          => array( 'title' => __( 'Subscribe to Events', 'cec' ), 'shortcode' => '[cec_subscribe]' ),
		);
	}

	/**
	 * Creates the front-end pages this plugin needs (Submit Event, Log In,
	 * Register, etc.) and fills in their URLs, but only for whichever of
	 * these settings are still blank — never touches one that's already
	 * configured. Without this, any blank URL here silently falls back to
	 * a default WordPress screen instead of this plugin's own styled page,
	 * which is confusing and, worse, can strand a member in wp-admin with
	 * no way back to the front end. Run on activation and on every version
	 * upgrade, so existing sites get this retroactively too.
	 */
	public static function maybe_create_pages() {
		$map = self::page_map();

		$opts    = self::get();
		$created = array();
		$changed = false;

		foreach ( $map as $key => $info ) {
			if ( ! empty( $opts[ $key ] ) ) {
				continue;
			}

			// A page with this exact title may already exist — from an
			// earlier run of this same method (e.g. a race between two
			// requests both hitting this on the same upgrade), or one
			// created by hand following the readme's old manual setup
			// steps — and should be linked to rather than duplicated.
			$existing = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => array( 'publish', 'draft', 'pending' ),
					'title'          => $info['title'],
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			if ( ! empty( $existing ) ) {
				$opts[ $key ] = get_permalink( $existing[0] );
				$changed      = true;
				continue;
			}

			$page_id = wp_insert_post(
				array(
					'post_title'   => $info['title'],
					'post_content' => $info['shortcode'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);
			if ( $page_id && ! is_wp_error( $page_id ) ) {
				$opts[ $key ] = get_permalink( $page_id );
				$created[]    = $info['title'];
				$changed      = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION, $opts );
		}
		if ( ! empty( $created ) ) {
			update_option( 'cec_pages_created_notice', $created );
		}
	}

	public static function maybe_show_pages_created_notice() {
		$created = get_option( 'cec_pages_created_notice' );
		if ( empty( $created ) ) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
			sprintf(
				/* translators: comma-separated list of page titles */
				__( 'Community Events Calendar created these pages automatically and linked them under Events > Settings: %s. Rename, move into a menu, or replace them with your own any time.', 'cec' ),
				implode( ', ', $created )
			)
		) . '</p></div>';
		delete_option( 'cec_pages_created_notice' );
	}

	/**
	 * How many extra same-titled pages exist for each of this plugin's
	 * settings fields, beyond the one that field's URL actually points to.
	 * Used to decide whether to show the cleanup tool at all, and how many
	 * pages it would trash if run.
	 */
	public static function duplicate_page_counts() {
		$opts   = self::get();
		$counts = array();

		foreach ( self::page_map() as $key => $info ) {
			$pages = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => array( 'publish', 'draft', 'pending' ),
					'title'          => $info['title'],
					'posts_per_page' => -1,
					'fields'         => 'ids',
					's'              => $info['shortcode'],
				)
			);
			if ( count( $pages ) > 1 ) {
				$counts[ $info['title'] ] = count( $pages ) - 1;
			}
		}
		return $counts;
	}

	/**
	 * Trashes (not permanently deletes) every extra same-titled,
	 * same-shortcode page beyond the one each setting's URL currently
	 * points to — or, if the saved URL doesn't match any of them, beyond
	 * the oldest one, which becomes the new keeper. Only ever acts on
	 * pages matching both an exact title AND containing the expected
	 * shortcode, so it can't sweep up an unrelated page that just happens
	 * to share a title.
	 */
	public static function handle_cleanup_duplicate_pages() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cec' ) );
		}
		check_admin_referer( self::CLEANUP_NONCE );

		$opts    = self::get();
		$trashed = 0;

		foreach ( self::page_map() as $key => $info ) {
			$pages = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => array( 'publish', 'draft', 'pending' ),
					'title'          => $info['title'],
					'posts_per_page' => -1,
					'fields'         => 'ids',
					's'              => $info['shortcode'],
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			if ( count( $pages ) < 2 ) {
				continue;
			}

			$keeper_id = 0;
			if ( ! empty( $opts[ $key ] ) ) {
				foreach ( $pages as $page_id ) {
					if ( untrailingslashit( get_permalink( $page_id ) ) === untrailingslashit( $opts[ $key ] ) ) {
						$keeper_id = $page_id;
						break;
					}
				}
			}
			if ( ! $keeper_id ) {
				$keeper_id    = $pages[0]; // oldest — 'orderby' => 'ID', 'order' => 'ASC'.
				$opts[ $key ] = get_permalink( $keeper_id );
			}

			foreach ( $pages as $page_id ) {
				if ( $page_id !== $keeper_id ) {
					wp_trash_post( $page_id );
					$trashed++;
				}
			}
		}

		update_option( self::OPTION, $opts );
		update_option( 'cec_pages_cleanup_notice', $trashed );

		wp_safe_redirect( admin_url( 'edit.php?post_type=cec_event&page=community-calendar-settings' ) );
		exit;
	}

	public static function maybe_show_pages_cleanup_notice() {
		$trashed = get_option( 'cec_pages_cleanup_notice' );
		if ( null === $trashed || false === $trashed ) {
			return;
		}
		if ( $trashed > 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of pages moved to Trash */
					_n( 'Moved %d duplicate page to the Trash.', 'Moved %d duplicate pages to the Trash.', $trashed, 'cec' ),
					$trashed
				)
			) . '</p></div>';
		} else {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'No duplicate pages found.', 'cec' ) . '</p></div>';
		}
		delete_option( 'cec_pages_cleanup_notice' );
	}
}
