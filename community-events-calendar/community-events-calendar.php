<?php
/**
 * Plugin Name: Community Events Calendar
 * Description: Member and admin-managed community events calendar with submissions, approval workflow, partner organizations & titleholders, recurring events, postponed/cancelled status, RSS/email subscriptions, volunteer inquiries, RSVP, and Elementor widgets. Shortcodes: [cec_calendar] month view, [cec_submit_org] organization/titleholder listing form, [cec_partner_orgs kind="organization|titleholder"] listed groups grid, [cec_events view="grid|list"] filterable grid/list, [cec_upcoming count="8"] scrolling upcoming events, [cec_submit_event] front-end submission form, [cec_admin_dashboard] front-end approval dashboard for delegated Calendar Managers, [cec_my_events] lets a submitter manage their own events, [cec_login]/[cec_register] styled account forms, [cec_manage_submission] guest email-link editing, [cec_subscribe] email subscriptions to all events or specific organizations, [cec_volunteer_form] volunteer inquiry form. Full docs on the Events > Settings page.
 * Version: 1.35.0
 * Author: RA Marketing
 * Text Domain: cec
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CEC_VERSION', '1.35.0' );
define( 'CEC_DIR', plugin_dir_path( __FILE__ ) );
define( 'CEC_URL', plugin_dir_url( __FILE__ ) );
define( 'CEC_TABLE_RSVP', 'cec_rsvps' );

require_once CEC_DIR . 'includes/class-cec-post-types.php';
require_once CEC_DIR . 'includes/class-cec-roles.php';
require_once CEC_DIR . 'includes/class-cec-term-meta.php';
require_once CEC_DIR . 'includes/class-cec-rsvp.php';
require_once CEC_DIR . 'includes/class-cec-audit-log.php';
require_once CEC_DIR . 'includes/class-cec-retention.php';
require_once CEC_DIR . 'includes/class-cec-recurrence.php';
require_once CEC_DIR . 'includes/class-cec-event-helper.php';
require_once CEC_DIR . 'includes/class-cec-month-grid.php';
require_once CEC_DIR . 'includes/class-cec-public-api.php';
require_once CEC_DIR . 'includes/class-cec-meta-boxes.php';
require_once CEC_DIR . 'includes/class-cec-admin-list.php';
require_once CEC_DIR . 'includes/class-cec-admin-settings.php';
require_once CEC_DIR . 'includes/class-cec-auth.php';
require_once CEC_DIR . 'includes/class-cec-tokens.php';
require_once CEC_DIR . 'includes/class-cec-submission-form.php';
require_once CEC_DIR . 'includes/class-cec-frontend-dashboard.php';
require_once CEC_DIR . 'includes/class-cec-my-events.php';
require_once CEC_DIR . 'includes/class-cec-guest-edit.php';
require_once CEC_DIR . 'includes/class-cec-dashboard-ajax.php';
require_once CEC_DIR . 'includes/class-cec-templates.php';
require_once CEC_DIR . 'includes/class-cec-feeds.php';
require_once CEC_DIR . 'includes/class-cec-subscribers.php';
require_once CEC_DIR . 'includes/class-cec-volunteers.php';
require_once CEC_DIR . 'includes/class-cec-privacy.php';
require_once CEC_DIR . 'includes/class-cec-shortcodes.php';
require_once CEC_DIR . 'includes/class-cec-ical.php';
require_once CEC_DIR . 'includes/class-cec-next-event.php';
require_once CEC_DIR . 'includes/class-cec-orgs.php';
require_once CEC_DIR . 'includes/class-cec-org-submissions.php';
require_once CEC_DIR . 'includes/class-cec-org-editor.php';
require_once CEC_DIR . 'includes/class-cec-ics-import.php';
require_once CEC_DIR . 'includes/class-cec-ajax.php';
require_once CEC_DIR . 'includes/class-cec-elementor.php';
require_once CEC_DIR . 'includes/class-cec-migrations.php';

final class CEC_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		add_action( 'init', array( 'CEC_Post_Types', 'register' ) );
		add_action( 'init', array( 'CEC_Roles', 'install' ) );
		add_action( 'init', array( $this, 'maybe_upgrade' ) );
		add_action( 'init', array( 'CEC_Term_Meta', 'init' ) );
		add_action( 'add_meta_boxes', array( 'CEC_Meta_Boxes', 'add' ) );
		add_action( 'save_post_cec_event', array( 'CEC_Meta_Boxes', 'save' ), 10, 2 );
		add_action( 'admin_footer-post.php', array( 'CEC_Post_Types', 'inject_in_review_status_js' ) );
		add_action( 'admin_footer-post-new.php', array( 'CEC_Post_Types', 'inject_in_review_status_js' ) );

		add_action( 'admin_menu', array( 'CEC_Admin_Settings', 'add_menu' ) );
		add_action( 'admin_init', array( 'CEC_Admin_Settings', 'register_settings' ) );
		add_action( 'admin_post_cec_send_test_email', array( 'CEC_Admin_Settings', 'handle_send_test_email' ) );
		add_action( 'admin_post_cec_cleanup_duplicate_pages', array( 'CEC_Admin_Settings', 'handle_cleanup_duplicate_pages' ) );

		add_filter( 'manage_cec_event_posts_columns', array( 'CEC_Admin_List', 'columns' ) );
		add_action( 'manage_cec_event_posts_custom_column', array( 'CEC_Admin_List', 'column_content' ), 10, 2 );
		add_filter( 'views_edit-cec_event', array( 'CEC_Admin_List', 'status_views' ) );
		add_action( 'pre_get_posts', array( 'CEC_Admin_List', 'filter_needs_price_review' ) );

		add_shortcode( 'cec_submit_event', array( 'CEC_Submission_Form', 'render_shortcode' ) );
		add_action( 'admin_post_cec_submit_event', array( 'CEC_Submission_Form', 'handle_submit' ) );
		add_action( 'admin_post_nopriv_cec_submit_event', array( 'CEC_Submission_Form', 'handle_submit_nopriv' ) );

		add_shortcode( 'cec_admin_dashboard', array( 'CEC_Frontend_Dashboard', 'render_shortcode' ) );
		add_action( 'admin_post_cec_dashboard_save_event', array( 'CEC_Frontend_Dashboard', 'handle_save_event' ) );
		add_action( 'admin_post_cec_dashboard_term_add', array( 'CEC_Frontend_Dashboard', 'handle_term_add' ) );
		add_action( 'admin_post_cec_dashboard_term_delete', array( 'CEC_Frontend_Dashboard', 'handle_term_delete' ) );
		add_action( 'admin_post_cec_dashboard_import_ics', array( 'CEC_Frontend_Dashboard', 'handle_import_ics' ) );
		add_action( 'wp_ajax_cec_dashboard_action', array( 'CEC_Dashboard_Ajax', 'handle' ) );

		add_shortcode( 'cec_my_events', array( 'CEC_My_Events', 'render_shortcode' ) );
		add_action( 'admin_post_cec_my_events_save', array( 'CEC_My_Events', 'handle_save_event' ) );

		add_shortcode( 'cec_login', array( 'CEC_Auth', 'render_login_shortcode' ) );
		add_shortcode( 'cec_register', array( 'CEC_Auth', 'render_register_shortcode' ) );
		add_action( 'admin_post_cec_login', array( 'CEC_Auth', 'handle_login' ) );
		add_action( 'admin_post_nopriv_cec_login', array( 'CEC_Auth', 'handle_login' ) );
		add_action( 'admin_post_cec_register', array( 'CEC_Auth', 'handle_register' ) );
		add_action( 'admin_post_nopriv_cec_register', array( 'CEC_Auth', 'handle_register' ) );

		add_shortcode( 'cec_manage_submission', array( 'CEC_Guest_Edit', 'render_shortcode' ) );
		add_action( 'admin_post_cec_request_edit_link', array( 'CEC_Guest_Edit', 'handle_request_link' ) );
		add_action( 'admin_post_nopriv_cec_request_edit_link', array( 'CEC_Guest_Edit', 'handle_request_link' ) );
		add_action( 'admin_post_cec_guest_save_event', array( 'CEC_Guest_Edit', 'handle_save_event' ) );
		add_action( 'admin_post_nopriv_cec_guest_save_event', array( 'CEC_Guest_Edit', 'handle_save_event' ) );
		add_action( 'admin_post_cec_guest_set_status', array( 'CEC_Guest_Edit', 'handle_set_status' ) );
		add_action( 'admin_post_nopriv_cec_guest_set_status', array( 'CEC_Guest_Edit', 'handle_set_status' ) );
		add_action( 'admin_post_cec_guest_withdraw', array( 'CEC_Guest_Edit', 'handle_withdraw' ) );
		add_action( 'admin_post_nopriv_cec_guest_withdraw', array( 'CEC_Guest_Edit', 'handle_withdraw' ) );

		add_action( 'transition_post_status', array( 'CEC_Recurrence', 'on_status_transition' ), 10, 3 );
		add_action( 'transition_post_status', array( 'CEC_Subscribers', 'on_status_transition' ), 10, 3 );
		add_action( 'transition_post_status', array( 'CEC_Audit_Log', 'on_status_transition' ), 10, 3 );
		add_action( 'pre_get_posts', array( 'CEC_Recurrence', 'exclude_occurrences_from_admin_list' ) );
		add_action( 'pre_get_posts', array( 'CEC_Feeds', 'adjust_feed_query' ) );
		add_filter( 'the_excerpt_rss', array( 'CEC_Feeds', 'event_description' ) );
		add_filter( 'the_content_feed', array( 'CEC_Feeds', 'event_description' ) );

		add_shortcode( 'cec_subscribe', array( 'CEC_Subscribers', 'render_shortcode' ) );
		add_action( 'admin_post_cec_subscribe', array( 'CEC_Subscribers', 'handle_subscribe' ) );
		add_action( 'admin_post_nopriv_cec_subscribe', array( 'CEC_Subscribers', 'handle_subscribe' ) );

		add_shortcode( 'cec_volunteer_form', array( 'CEC_Volunteers', 'render_shortcode' ) );
		add_action( 'admin_post_cec_volunteer_submit', array( 'CEC_Volunteers', 'handle_submit' ) );
		add_action( 'admin_post_nopriv_cec_volunteer_submit', array( 'CEC_Volunteers', 'handle_submit' ) );
		add_action( 'admin_menu', array( 'CEC_Volunteers', 'add_menu' ) );
		add_action( 'admin_post_cec_volunteer_status', array( 'CEC_Volunteers', 'handle_status_update' ) );
		add_action( 'admin_post_cec_volunteer_export', array( 'CEC_Volunteers', 'handle_export' ) );

		add_filter( 'wp_privacy_personal_data_exporters', array( 'CEC_Privacy', 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( 'CEC_Privacy', 'register_erasers' ) );

		add_action( 'admin_init', array( 'CEC_Roles', 'maybe_redirect_from_wp_admin' ) );
		add_filter( 'show_admin_bar', array( 'CEC_Roles', 'hide_admin_bar' ) );
		add_filter( 'login_redirect', array( 'CEC_Roles', 'login_redirect_url' ), 10, 3 );

		add_action( 'admin_notices', array( 'CEC_Admin_Settings', 'maybe_show_pages_created_notice' ) );
		add_action( 'admin_notices', array( 'CEC_Admin_Settings', 'maybe_show_pages_cleanup_notice' ) );

		add_shortcode( 'cec_calendar', array( 'CEC_Shortcodes', 'calendar' ) );
		add_shortcode( 'cec_events', array( 'CEC_Shortcodes', 'list_grid' ) );
		add_shortcode( 'cec_upcoming', array( 'CEC_Shortcodes', 'upcoming' ) );

		add_action( 'wp_ajax_cec_calendar_month', array( 'CEC_Ajax', 'calendar_month' ) );
		add_action( 'wp_ajax_nopriv_cec_calendar_month', array( 'CEC_Ajax', 'calendar_month' ) );
		add_action( 'wp_ajax_cec_filter_events', array( 'CEC_Ajax', 'filter_events' ) );
		add_action( 'wp_ajax_nopriv_cec_filter_events', array( 'CEC_Ajax', 'filter_events' ) );
		add_action( 'wp_ajax_cec_rsvp', array( 'CEC_RSVP', 'handle_ajax' ) );
		add_action( 'wp_ajax_nopriv_cec_rsvp', array( 'CEC_RSVP', 'handle_ajax' ) );
		add_action( 'wp_ajax_cec_rsvp_waitlist', array( 'CEC_RSVP', 'handle_waitlist_ajax' ) );
		add_action( 'wp_ajax_nopriv_cec_rsvp_waitlist', array( 'CEC_RSVP', 'handle_waitlist_ajax' ) );
		add_action( 'admin_menu', array( 'CEC_RSVP', 'add_menu' ) );
		add_action( 'admin_post_cec_rsvp_export', array( 'CEC_RSVP', 'handle_export' ) );
		add_action( 'cec_rsvp_reminder_check', array( 'CEC_RSVP', 'send_reminders' ) );
		CEC_Retention::init();
		CEC_Next_Event::init();
		CEC_Orgs::init();
		CEC_Org_Submissions::init();
		CEC_Org_Editor::init();
		add_action( 'updated_post_meta', array( 'CEC_RSVP', 'on_start_meta_updated' ), 10, 4 );

		add_filter( 'query_vars', array( 'CEC_Ical', 'add_query_vars' ) );
		add_action( 'template_redirect', array( 'CEC_Ical', 'maybe_output' ) );
		// 1.32.1: .ics files are served from admin-post.php, which the host's CDN never caches.
		add_action( 'admin_post_nopriv_cec_ical', array( 'CEC_Ical', 'serve' ) );
		add_action( 'admin_post_cec_ical', array( 'CEC_Ical', 'serve' ) );

		add_filter( 'template_include', array( 'CEC_Templates', 'template_include' ) );
		add_filter( 'body_class', array( 'CEC_Templates', 'body_class' ) );
		add_action( 'wp_head', array( 'CEC_Templates', 'output_event_schema' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );

		add_action( 'elementor/widgets/register', array( 'CEC_Elementor', 'register_widgets' ) );
		add_action( 'elementor/elements/categories_registered', array( 'CEC_Elementor', 'register_category' ) );
	}

	public function activate() {
		CEC_Post_Types::register();
		CEC_RSVP::create_table();
		CEC_RSVP::create_waitlist_table();
		CEC_Audit_Log::create_table();
		CEC_Subscribers::create_table();
		CEC_Volunteers::create_table();
		CEC_Post_Types::seed_default_terms();
		CEC_Roles::install();
		CEC_Admin_Settings::maybe_create_pages();
		if ( ! wp_next_scheduled( 'cec_rsvp_reminder_check' ) ) {
			wp_schedule_event( time(), 'hourly', 'cec_rsvp_reminder_check' );
		}
		CEC_Retention::schedule();
		update_option( 'cec_db_version', CEC_VERSION );
		CEC_Migrations::maybe_run_all();
		flush_rewrite_rules();
	}

	/**
	 * Re-uploading the plugin's files doesn't re-run register_activation_hook,
	 * so DB tables added in a later version need this self-heal path too —
	 * gated on a version marker since dbDelta is too heavy to run every request.
	 */
	public function maybe_upgrade() {
		if ( get_option( 'cec_db_version' ) === CEC_VERSION ) {
			return;
		}
		// Marked upgraded FIRST, before doing the actual work below — several
		// requests can hit 'init' nearly simultaneously right after a file
		// update (browser tabs, crawlers, WP's own background requests), and
		// if this were check-then-act in the other order, every one of them
		// could pass the guard above before any had finished, each one
		// independently creating its own copy of whatever maybe_create_pages()
		// below adds. This closes that window to the much smaller gap between
		// the two lines here.
		update_option( 'cec_db_version', CEC_VERSION );
		CEC_RSVP::create_table();
		CEC_RSVP::create_waitlist_table();
		CEC_Audit_Log::create_table();
		CEC_Subscribers::create_table();
		CEC_Volunteers::create_table();
		CEC_Admin_Settings::maybe_create_pages();
		if ( ! wp_next_scheduled( 'cec_rsvp_reminder_check' ) ) {
			wp_schedule_event( time(), 'hourly', 'cec_rsvp_reminder_check' );
		}
		CEC_Retention::schedule();
		CEC_Migrations::maybe_run_all();
	}

	public function deactivate() {
		wp_clear_scheduled_hook( 'cec_rsvp_reminder_check' );
		CEC_Retention::unschedule();
		flush_rewrite_rules();
	}

	public function enqueue_frontend() {
		wp_enqueue_style( 'cec-frontend', CEC_URL . 'assets/css/frontend.css', array(), CEC_VERSION );
		wp_enqueue_script( 'cec-frontend', CEC_URL . 'assets/js/frontend.js', array( 'jquery' ), CEC_VERSION, true );
		wp_localize_script(
			'cec-frontend',
			'CEC',
			array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'cec_frontend' ),
				'is_logged' => is_user_logged_in() ? 1 : 0,
				'i18n'      => array(
					'soonestFirst' => __( 'Soonest First', 'cec' ),
					'latestFirst'  => __( 'Latest First', 'cec' ),
					// Phone month grid's tapped-day list (mirrors
					// CEC_Month_Grid::render_phone_day_list()).
					'oneEvent'     => __( '%d event', 'cec' ),
					'manyEvents'   => __( '%d events', 'cec' ),
					'startingDay'  => __( 'Starting this day', 'cec' ),
					'stillRunning' => __( 'Still running', 'cec' ),
					'allDay'       => __( 'All day', 'cec' ),
					/* translators: 1: day number within the event, 2: total days */
					'dayOf'        => __( 'day %1$d of %2$d', 'cec' ),
					'nothingDay'   => __( 'Nothing on this day. Tap another day, or use the arrows for other months.', 'cec' ),
				),
			)
		);

		wp_enqueue_style( 'cec-dashboard', CEC_URL . 'assets/css/dashboard.css', array( 'cec-frontend' ), CEC_VERSION );
		wp_enqueue_script( 'cec-dashboard', CEC_URL . 'assets/js/dashboard.js', array( 'jquery', 'cec-frontend' ), CEC_VERSION, true );

		$colors = CEC_Admin_Settings::get_colors();
		$css    = ":root{--cec-primary:{$colors['primary']};--cec-secondary:{$colors['secondary']};--cec-accent:{$colors['accent']};--cec-free-badge:{$colors['free']};--cec-text:{$colors['text']};--cec-bg:{$colors['bg']};}";
		wp_add_inline_style( 'cec-frontend', $css );
	}

	public function enqueue_admin( $hook ) {
		global $post_type;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'cec_event' === $post_type || 'community-calendar-settings' === $page ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );
			wp_enqueue_media();
			wp_enqueue_style( 'cec-admin', CEC_URL . 'assets/css/admin.css', array(), CEC_VERSION );
			wp_enqueue_script( 'cec-admin', CEC_URL . 'assets/js/admin.js', array( 'jquery', 'wp-color-picker' ), CEC_VERSION, true );
			if ( 'cec_event' === $post_type ) {
				wp_localize_script( 'cec-admin', 'CEC_ADMIN', array( 'orgDefaults' => CEC_Term_Meta::all_partner_org_defaults() ) );

				// The Phase 1d editor preview box embeds the real
				// front-end card/badge markup and a live, click-to-browse
				// month calendar — both need the public styles and the
				// calendar's own nav/day-detail JS (frontend.js, bound via
				// event delegation, so it works on markup injected after
				// page load too). The only overlap with admin.js's own
				// admission/location toggle handlers is calling the same
				// idempotent toggle twice on change — harmless — so this
				// is simpler and more maintainable than hand-duplicating
				// the calendar's ~90 lines of nav/click-to-select JS a
				// second time into admin.js.
				wp_enqueue_style( 'cec-frontend', CEC_URL . 'assets/css/frontend.css', array(), CEC_VERSION );
				wp_enqueue_script( 'cec-frontend', CEC_URL . 'assets/js/frontend.js', array( 'jquery' ), CEC_VERSION, true );
				wp_localize_script(
					'cec-frontend',
					'CEC',
					array(
						'ajax_url'  => admin_url( 'admin-ajax.php' ),
						'nonce'     => wp_create_nonce( 'cec_frontend' ),
						'is_logged' => 1,
						'i18n'      => array(
							'soonestFirst' => __( 'Soonest First', 'cec' ),
							'latestFirst'  => __( 'Latest First', 'cec' ),
						),
					)
				);
				$colors = CEC_Admin_Settings::get_colors();
				$css    = ":root{--cec-primary:{$colors['primary']};--cec-secondary:{$colors['secondary']};--cec-accent:{$colors['accent']};--cec-free-badge:{$colors['free']};--cec-text:{$colors['text']};--cec-bg:{$colors['bg']};}";
				wp_add_inline_style( 'cec-frontend', $css );
			}
		}
	}
}

CEC_Plugin::instance();
