<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [cec_manage_submission] — lets a guest (no WordPress account) request an
 * emailed edit link for events they submitted with just their email, and
 * use that link to edit details, change postponed/cancelled status, or
 * withdraw the event. No login involved; the token in the URL is the
 * credential. Scoped to standalone events and recurring series roots —
 * postponing/cancelling one specific date of a series still requires the
 * logged-in My Events "Manage Dates" view.
 */
class CEC_Guest_Edit {

	const REQUEST_NONCE = 'cec_request_edit_link';
	const ACTION_NONCE  = 'cec_guest_event_action';

	public static function render_shortcode( $atts ) {
		$event_id = isset( $_GET['cec_event'] ) ? absint( $_GET['cec_event'] ) : 0;
		$token    = isset( $_GET['cec_token'] ) ? sanitize_text_field( wp_unslash( $_GET['cec_token'] ) ) : '';

		ob_start();
		echo '<div class="cec-dashboard cec-guest-edit">';
		if ( $event_id && $token ) {
			echo self::render_token_view( $event_id, $token );
		} else {
			echo self::render_notice();
			echo self::render_request_form();
		}
		echo '</div>';
		return ob_get_clean();
	}

	private static function render_notice() {
		$notice = '';
		if ( ! empty( $_GET['cec_msg'] ) ) {
			$messages = array(
				'requested' => __( "If that email has any submitted events, we've sent a link to manage them.", 'cec' ),
				'saved'     => __( 'Your changes were submitted and are awaiting admin approval before they go live.', 'cec' ),
				'status'    => __( 'Updated.', 'cec' ),
				'withdrawn' => __( 'Your event was withdrawn.', 'cec' ),
			);
			$key = sanitize_key( wp_unslash( $_GET['cec_msg'] ) );
			if ( ! empty( $messages[ $key ] ) ) {
				$notice .= '<div class="cec-notice cec-notice-success">' . esc_html( $messages[ $key ] ) . '</div>';
			}
		}
		if ( ! empty( $_GET['cec_photo_err'] ) ) {
			$notice .= '<div class="cec-notice cec-notice-error">' . esc_html( CEC_Event_Helper::photo_error_message( sanitize_key( wp_unslash( $_GET['cec_photo_err'] ) ) ) ) . '</div>';
		}
		return $notice;
	}

	private static function render_request_form() {
		ob_start();
		?>
		<h2><?php esc_html_e( 'Manage a Submitted Event', 'cec' ); ?></h2>
		<p><?php esc_html_e( "Enter the email you used when submitting an event, and we'll send you a link to manage it — no account needed.", 'cec' ); ?></p>
		<form class="cec-submit-form cec-auth-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cec_request_edit_link" />
			<input type="hidden" name="cec_redirect" value="<?php echo esc_url( get_permalink() ); ?>" />
			<?php wp_nonce_field( self::REQUEST_NONCE, 'cec_request_nonce' ); ?>
			<div class="cec-field"><label><?php esc_html_e( 'Email', 'cec' ); ?></label><input type="email" name="cec_email" required></div>
			<p class="cec-hp-field" aria-hidden="true"><label>Leave this field empty</label><input type="text" name="cec_website" tabindex="-1" autocomplete="off"></p>
			<button type="submit" class="cec-btn"><?php esc_html_e( 'Send Me an Edit Link', 'cec' ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function handle_request_link() {
		$redirect = isset( $_POST['cec_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['cec_redirect'] ) ) : home_url();

		if ( ! isset( $_POST['cec_request_nonce'] ) || ! wp_verify_nonce( $_POST['cec_request_nonce'], self::REQUEST_NONCE ) ) {
			wp_safe_redirect( add_query_arg( 'cec_msg', 'requested', $redirect ) );
			exit;
		}
		if ( ! empty( $_POST['cec_website'] ) ) {
			wp_safe_redirect( add_query_arg( 'cec_msg', 'requested', $redirect ) );
			exit;
		}

		$email = isset( $_POST['cec_email'] ) ? sanitize_email( wp_unslash( $_POST['cec_email'] ) ) : '';

		if ( is_email( $email ) ) {
			$rate_key = 'cec_edit_req_' . md5( $email );
			if ( ! get_transient( $rate_key ) ) {
				set_transient( $rate_key, 1, 5 * MINUTE_IN_SECONDS );
				self::send_edit_links( $email );
			}
		}

		wp_safe_redirect( add_query_arg( 'cec_msg', 'requested', $redirect ) );
		exit;
	}

	private static function send_edit_links( $email ) {
		$base_url = CEC_Admin_Settings::get( 'manage_submission_page_url' );
		if ( ! $base_url ) {
			return;
		}

		$events = get_posts(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => array( 'publish', 'pending', 'draft', 'cec_in_review' ),
				'posts_per_page' => -1,
				'meta_query'     => array(
					array( 'key' => '_cec_submitter_email', 'value' => $email ),
					array( 'key' => '_cec_recurrence_parent_id', 'compare' => 'NOT EXISTS' ),
				),
			)
		);

		if ( empty( $events ) ) {
			return;
		}

		$lines = array();
		foreach ( $events as $event ) {
			$token = CEC_Tokens::issue_for_event( $event->ID );
			$url   = CEC_Tokens::build_edit_url( $base_url, $event->ID, $token );
			$lines[] = $event->post_title . ' — ' . $url;
		}

		$body = __( "Here are the links to manage your submitted events. Each link works for 7 days.\n\n", 'cec' ) . implode( "\n\n", $lines );

		wp_mail(
			$email,
			sprintf( __( '[%s] Manage your submitted events', 'cec' ), CEC_Event_Helper::site_name() ),
			$body
		);
	}

	private static function render_token_view( $event_id, $token ) {
		if ( ! CEC_Tokens::verify( $event_id, $token ) ) {
			ob_start();
			?>
			<div class="cec-notice cec-notice-error"><?php esc_html_e( 'This link is invalid or has expired. Request a new one below.', 'cec' ); ?></div>
			<?php echo self::render_request_form(); // phpcs:ignore ?>
			<?php
			return ob_get_clean();
		}

		$post         = get_post( $event_id );
		$data         = CEC_Event_Helper::data( $event_id );
		$event_types  = get_terms( array( 'taxonomy' => 'cec_event_type', 'hide_empty' => false ) );
		$partner_orgs = get_terms( array( 'taxonomy' => 'cec_partner_org', 'hide_empty' => false ) );
		$venues       = get_terms( array( 'taxonomy' => 'cec_venue', 'hide_empty' => false ) );
		$my_types     = wp_get_post_terms( $event_id, 'cec_event_type', array( 'fields' => 'ids' ) );
		$my_partners  = wp_get_post_terms( $event_id, 'cec_partner_org', array( 'fields' => 'ids' ) );
		$my_venue     = wp_get_post_terms( $event_id, 'cec_venue', array( 'fields' => 'ids' ) );
		$my_venue_id  = ! empty( $my_venue ) ? $my_venue[0] : 0;

		ob_start();
		echo self::render_notice(); // phpcs:ignore
		?>
		<h2><?php echo esc_html( $post->post_title ); ?></h2>
		<p class="cec-series-note"><?php esc_html_e( 'This link is yours alone — don\'t share it. It stays valid for 7 days from when it was sent; request a fresh one anytime from the page above.', 'cec' ); ?></p>

		<h3><?php esc_html_e( 'Event Status', 'cec' ); ?></h3>
		<form class="cec-submit-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cec_guest_set_status" />
			<input type="hidden" name="cec_event" value="<?php echo esc_attr( $event_id ); ?>" />
			<input type="hidden" name="cec_token" value="<?php echo esc_attr( $token ); ?>" />
			<?php wp_nonce_field( self::ACTION_NONCE, 'cec_guest_nonce' ); ?>
			<div class="cec-field-row">
				<div class="cec-field">
					<select name="cec_event_status">
						<option value="scheduled" <?php selected( $data['event_status'], 'scheduled' ); ?>><?php esc_html_e( 'Scheduled', 'cec' ); ?></option>
						<option value="postponed" <?php selected( $data['event_status'], 'postponed' ); ?>><?php esc_html_e( 'Postponed', 'cec' ); ?></option>
						<option value="cancelled" <?php selected( $data['event_status'], 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'cec' ); ?></option>
					</select>
				</div>
				<div class="cec-field"><input type="text" name="cec_event_status_note" value="<?php echo esc_attr( $data['event_status_note'] ); ?>" placeholder="<?php esc_attr_e( 'Optional note (new date, reason)', 'cec' ); ?>"></div>
			</div>
			<p class="description"><?php esc_html_e( "This applies immediately and doesn't need admin approval.", 'cec' ); ?></p>
			<button type="submit" class="cec-btn cec-btn-small"><?php esc_html_e( 'Update Status', 'cec' ); ?></button>
		</form>

		<h3><?php esc_html_e( 'Edit Details', 'cec' ); ?></h3>
		<?php if ( 'publish' === $post->post_status ) : ?>
			<div class="cec-notice"><?php esc_html_e( 'This event is already live. Saving changes below sends it back for admin approval before it stays visible.', 'cec' ); ?></div>
		<?php endif; ?>
		<form class="cec-submit-form cec-dash-edit-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cec_guest_save_event" />
			<input type="hidden" name="cec_event" value="<?php echo esc_attr( $event_id ); ?>" />
			<input type="hidden" name="cec_token" value="<?php echo esc_attr( $token ); ?>" />
			<?php wp_nonce_field( self::ACTION_NONCE, 'cec_guest_nonce' ); ?>

			<div class="cec-field"><label><?php esc_html_e( 'Event Title *', 'cec' ); ?></label><input type="text" name="cec_title" value="<?php echo esc_attr( $post->post_title ); ?>" required></div>
			<div class="cec-field"><label><?php esc_html_e( 'Short Description * (up to 1,000 characters)', 'cec' ); ?></label><textarea name="cec_excerpt" maxlength="1000" rows="3" required><?php echo esc_textarea( $post->post_excerpt ); ?></textarea></div>
			<div class="cec-field"><label><?php esc_html_e( 'Full Description *', 'cec' ); ?></label><textarea name="cec_description" rows="6" required><?php echo esc_textarea( $post->post_content ); ?></textarea></div>
			<div class="cec-field">
				<label><?php esc_html_e( 'Event Photo', 'cec' ); ?></label>
				<?php if ( $data['thumb_card'] ) : ?><img src="<?php echo esc_url( $data['thumb_card'] ); ?>" style="max-width:200px;display:block;margin-bottom:8px;border-radius:8px;" /><?php endif; ?>
				<input type="file" name="cec_photo" accept="image/*">
				<p class="cec-field-hint"><?php esc_html_e( 'JPG, PNG, GIF, or WEBP, up to 5MB.', 'cec' ); ?></p>
			</div>
			<div class="cec-field"><label><?php esc_html_e( 'Photo Alt Text (for screen readers)', 'cec' ); ?></label><input type="text" name="cec_photo_alt" maxlength="250" value="<?php echo esc_attr( $data['photo_alt'] ); ?>"></div>

			<div class="cec-field">
				<label><?php esc_html_e( 'Event Type *', 'cec' ); ?></label>
				<?php foreach ( $event_types as $term ) : ?>
					<label class="cec-checkbox"><input type="checkbox" name="cec_event_type[]" value="<?php echo esc_attr( $term->term_id ); ?>" <?php checked( in_array( $term->term_id, $my_types, true ) ); ?>> <?php echo esc_html( $term->name ); ?></label>
				<?php endforeach; ?>
			</div>

			<div class="cec-field-row">
				<div class="cec-field"><label><?php esc_html_e( 'Host Organization Name', 'cec' ); ?></label><input type="text" name="cec_host_org_name" value="<?php echo esc_attr( $data['host_org_name'] ); ?>"></div>
				<div class="cec-field"><label><?php esc_html_e( 'Host Organization Website', 'cec' ); ?></label><input type="url" name="cec_host_org_url" value="<?php echo esc_attr( $data['host_org_url'] ); ?>"></div>
			</div>
			<div class="cec-field-row">
				<div class="cec-field"><label><?php esc_html_e( 'Official Event Website', 'cec' ); ?></label><input type="url" name="cec_official_website_url" value="<?php echo esc_attr( $data['official_website_url'] ); ?>"></div>
				<div class="cec-field"><label><?php esc_html_e( 'More Info Link', 'cec' ); ?></label><input type="url" name="cec_more_info_url" value="<?php echo esc_attr( $data['more_info_url'] ); ?>"><p class="cec-field-hint"><?php esc_html_e( 'A link for anyone who wants more details, like a full event listing or flyer.', 'cec' ); ?></p></div>
			</div>
			<div class="cec-field"><label><?php esc_html_e( 'Event Host(s) / Organizer(s) *', 'cec' ); ?></label><input type="text" name="cec_organizers" value="<?php echo esc_attr( $data['organizers'] ); ?>" required></div>

			<?php if ( ! empty( $partner_orgs ) && ! is_wp_error( $partner_orgs ) ) : ?>
			<div class="cec-field">
				<label><?php esc_html_e( 'Partner Organizations & Titleholders', 'cec' ); ?></label>
				<?php foreach ( $partner_orgs as $term ) : ?>
					<label class="cec-checkbox"><input type="checkbox" name="cec_partner_org[]" value="<?php echo esc_attr( $term->term_id ); ?>" <?php checked( in_array( $term->term_id, $my_partners, true ) ); ?>> <?php echo esc_html( $term->name ); ?></label>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<div class="cec-field">
				<label><?php esc_html_e( "Don't see your organization or titleholder? Add it", 'cec' ); ?></label>
				<input type="text" name="cec_new_partner_org" placeholder="<?php esc_attr_e( 'Organization or titleholder name', 'cec' ); ?>">
			</div>

			<div class="cec-field">
				<label><?php esc_html_e( 'Location Type', 'cec' ); ?></label>
				<select name="cec_location_mode" class="cec-location-mode-select">
					<option value="in_person" <?php selected( $data['location_mode'], 'in_person' ); ?>><?php esc_html_e( 'In Person', 'cec' ); ?></option>
					<option value="online" <?php selected( $data['location_mode'], 'online' ); ?>><?php esc_html_e( 'Online', 'cec' ); ?></option>
					<option value="hybrid" <?php selected( $data['location_mode'], 'hybrid' ); ?>><?php esc_html_e( 'Hybrid (In Person & Online)', 'cec' ); ?></option>
					<option value="not_posted" <?php selected( $data['location_mode'], 'not_posted' ); ?>><?php esc_html_e( 'Not posted yet', 'cec' ); ?></option>
				</select>
			</div>
			<div class="cec-field cec-location-online-fields">
				<label><?php esc_html_e( 'Online Access Link', 'cec' ); ?></label>
				<input type="url" name="cec_online_url" value="<?php echo esc_attr( $data['online_url'] ); ?>">
			</div>
			<div class="cec-location-in-person-fields">
				<div class="cec-field">
					<label><?php esc_html_e( 'Venue', 'cec' ); ?></label>
					<select name="cec_venue_term">
						<option value=""><?php esc_html_e( '— Custom address below —', 'cec' ); ?></option>
						<?php foreach ( $venues as $term ) : ?>
							<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $my_venue_id, $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="cec-field-row">
					<div class="cec-field"><label><?php esc_html_e( 'Custom Address', 'cec' ); ?></label><input type="text" name="cec_venue_custom_address" value="<?php echo esc_attr( get_post_meta( $event_id, '_cec_venue_custom_address', true ) ); ?>"></div>
					<div class="cec-field"><label><?php esc_html_e( 'Google Maps Link (optional)', 'cec' ); ?></label><input type="url" name="cec_venue_custom_maps_url" value="<?php echo esc_attr( get_post_meta( $event_id, '_cec_venue_custom_maps_url', true ) ); ?>"></div>
				</div>
				<div class="cec-field-row">
					<div class="cec-field"><label><?php esc_html_e( 'City', 'cec' ); ?></label><input type="text" name="cec_venue_custom_city" value="<?php echo esc_attr( get_post_meta( $event_id, '_cec_venue_custom_city', true ) ); ?>"></div>
					<div class="cec-field"><label><?php esc_html_e( 'State / Region', 'cec' ); ?></label><input type="text" name="cec_venue_custom_region" value="<?php echo esc_attr( get_post_meta( $event_id, '_cec_venue_custom_region', true ) ); ?>"></div>
					<div class="cec-field"><label><?php esc_html_e( 'Country', 'cec' ); ?></label><input type="text" name="cec_venue_custom_country" value="<?php echo esc_attr( get_post_meta( $event_id, '_cec_venue_custom_country', true ) ); ?>"></div>
				</div>
			</div>

			<div class="cec-field-row">
				<div class="cec-field"><label><?php esc_html_e( 'Start Date & Time *', 'cec' ); ?></label><input type="datetime-local" name="cec_start" value="<?php echo esc_attr( get_post_meta( $event_id, '_cec_start', true ) ); ?>" required></div>
				<div class="cec-field"><label><?php esc_html_e( 'End Date & Time', 'cec' ); ?></label><input type="datetime-local" name="cec_end" value="<?php echo esc_attr( get_post_meta( $event_id, '_cec_end', true ) ); ?>"></div>
			</div>
			<div class="cec-field-row">
				<div class="cec-field">
					<label><?php esc_html_e( 'Time Details', 'cec' ); ?></label>
					<select name="cec_time_mode">
						<option value="exact" <?php selected( $data['time_mode'], 'exact' ); ?>><?php esc_html_e( 'Exact start/end time', 'cec' ); ?></option>
						<option value="start_only" <?php selected( $data['time_mode'], 'start_only' ); ?>><?php esc_html_e( 'Start time only (no set end)', 'cec' ); ?></option>
						<option value="all_day" <?php selected( $data['time_mode'], 'all_day' ); ?>><?php esc_html_e( 'All day (no specific time)', 'cec' ); ?></option>
						<option value="varies" <?php selected( $data['time_mode'], 'varies' ); ?>><?php esc_html_e( 'Schedule varies', 'cec' ); ?></option>
					</select>
				</div>
				<div class="cec-field">
					<label><?php esc_html_e( 'Timezone', 'cec' ); ?></label>
					<?php echo CEC_Event_Helper::timezone_select_html( 'cec_timezone', get_post_meta( $event_id, '_cec_timezone', true ) ); // phpcs:ignore ?>
				</div>
			</div>

			<div class="cec-field">
				<label><?php esc_html_e( 'Admission', 'cec' ); ?></label>
				<select name="cec_admission_status" class="cec-admission-status-select">
					<option value="not_posted" <?php selected( $data['admission_status'], 'not_posted' ); ?>><?php esc_html_e( 'Price not posted', 'cec' ); ?></option>
					<option value="free_confirmed" <?php selected( $data['admission_status'], 'free_confirmed' ); ?>><?php esc_html_e( 'Free — confirmed', 'cec' ); ?></option>
					<option value="paid" <?php selected( $data['admission_status'], 'paid' ); ?>><?php esc_html_e( 'Paid', 'cec' ); ?></option>
					<option value="price_varies" <?php selected( $data['admission_status'], 'price_varies' ); ?>><?php esc_html_e( 'Price varies', 'cec' ); ?></option>
				</select>
			</div>
			<div class="cec-field-row cec-admission-paid-fields">
				<div class="cec-field"><label><?php esc_html_e( 'Price', 'cec' ); ?></label><input type="number" step="0.01" min="0" name="cec_price_amount" value="<?php echo esc_attr( $data['price_amount'] ); ?>"></div>
				<div class="cec-field"><label><?php esc_html_e( 'Currency', 'cec' ); ?></label><input type="text" name="cec_price_currency" maxlength="3" value="<?php echo esc_attr( $data['price_currency'] ); ?>"></div>
			</div>
			<div class="cec-field-row cec-admission-varies-fields">
				<div class="cec-field"><label><?php esc_html_e( 'Price From', 'cec' ); ?></label><input type="number" step="0.01" min="0" name="cec_price_min" value="<?php echo esc_attr( $data['price_min'] ); ?>"></div>
				<div class="cec-field"><label><?php esc_html_e( 'Price To', 'cec' ); ?></label><input type="number" step="0.01" min="0" name="cec_price_max" value="<?php echo esc_attr( $data['price_max'] ); ?>"></div>
			</div>
			<div class="cec-field-row">
				<div class="cec-field"><label><?php esc_html_e( 'Price Note (optional)', 'cec' ); ?></label><input type="text" name="cec_price_note" value="<?php echo esc_attr( $data['price_note'] ); ?>"></div>
				<div class="cec-field">
					<label><?php esc_html_e( 'RSVP / Registration', 'cec' ); ?></label>
					<select name="cec_rsvp_mode">
						<option value="none" <?php selected( $data['rsvp_mode'], 'none' ); ?>><?php esc_html_e( 'No RSVP needed', 'cec' ); ?></option>
						<option value="internal" <?php selected( $data['rsvp_mode'], 'internal' ); ?>><?php esc_html_e( 'Collect RSVPs on this site', 'cec' ); ?></option>
						<option value="external" <?php selected( $data['rsvp_mode'], 'external' ); ?>><?php esc_html_e( 'Link to external registration', 'cec' ); ?></option>
					</select>
					<input type="url" name="cec_rsvp_url" value="<?php echo esc_attr( $data['rsvp_url'] ); ?>" style="margin-top:6px;">
					<input type="number" name="cec_rsvp_capacity" min="0" value="<?php echo esc_attr( $data['rsvp_capacity'] ); ?>" placeholder="<?php esc_attr_e( 'Capacity (optional)', 'cec' ); ?>" style="margin-top:6px;">
				</div>
			</div>

			<div class="cec-field"><label><?php esc_html_e( 'Code of Conduct', 'cec' ); ?></label><textarea name="cec_code_of_conduct" rows="2"><?php echo esc_textarea( $data['code_of_conduct'] ); ?></textarea></div>

			<?php if ( $data['is_recurring_root'] ) : ?>
				<div class="cec-field-row">
					<div class="cec-field">
						<label><?php esc_html_e( 'Repeats', 'cec' ); ?></label>
						<select name="cec_recurrence_rule" class="cec-recurrence-rule">
							<option value="none" <?php selected( get_post_meta( $event_id, '_cec_recurrence_rule', true ), 'none' ); ?>><?php esc_html_e( 'Does not repeat', 'cec' ); ?></option>
							<option value="weekly" <?php selected( get_post_meta( $event_id, '_cec_recurrence_rule', true ), 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'cec' ); ?></option>
							<option value="monthly_date" <?php selected( get_post_meta( $event_id, '_cec_recurrence_rule', true ), 'monthly_date' ); ?>><?php esc_html_e( 'Monthly (same date)', 'cec' ); ?></option>
							<option value="monthly_weekday" <?php selected( get_post_meta( $event_id, '_cec_recurrence_rule', true ), 'monthly_weekday' ); ?>><?php esc_html_e( 'Monthly (specific weekday)', 'cec' ); ?></option>
						</select>
					</div>
					<div class="cec-field cec-recurrence-until-field">
						<label><?php esc_html_e( 'Repeat Until', 'cec' ); ?></label>
						<input type="date" name="cec_recurrence_until" value="<?php echo esc_attr( get_post_meta( $event_id, '_cec_recurrence_until', true ) ); ?>">
					</div>
				</div>
				<?php
				$start_ts = get_post_meta( $event_id, '_cec_start', true ) ? strtotime( get_post_meta( $event_id, '_cec_start', true ) ) : time();
				echo CEC_Recurrence::render_monthly_weekday_fields( CEC_Recurrence::get_ordinals( $event_id, $start_ts ), CEC_Recurrence::get_weekdays( $event_id, $start_ts ) ); // phpcs:ignore
				?>
				<p class="description"><?php esc_html_e( 'This is a recurring series. Changing repeat settings regenerates all upcoming dates. To postpone/cancel just one date, or if you need finer per-date control, ask the site admin — guest editing manages the whole series at once.', 'cec' ); ?></p>
			<?php endif; ?>

			<button type="submit" class="cec-btn"><?php esc_html_e( 'Save Changes', 'cec' ); ?></button>
		</form>

		<h3><?php esc_html_e( 'Withdraw', 'cec' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Withdraw this event entirely? This cannot be undone from here.', 'cec' ) ); ?>');">
			<input type="hidden" name="action" value="cec_guest_withdraw" />
			<input type="hidden" name="cec_event" value="<?php echo esc_attr( $event_id ); ?>" />
			<input type="hidden" name="cec_token" value="<?php echo esc_attr( $token ); ?>" />
			<?php wp_nonce_field( self::ACTION_NONCE, 'cec_guest_nonce' ); ?>
			<button type="submit" class="cec-btn cec-btn-small cec-btn-danger"><?php esc_html_e( 'Withdraw This Event', 'cec' ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	private static function verify_request( $event_id, $token ) {
		if ( ! isset( $_POST['cec_guest_nonce'] ) || ! wp_verify_nonce( $_POST['cec_guest_nonce'], self::ACTION_NONCE ) ) {
			return false;
		}
		return CEC_Tokens::verify( $event_id, $token );
	}

	private static function redirect_to_token_view( $event_id, $token, $msg, $extra_args = array() ) {
		$base = CEC_Admin_Settings::get( 'manage_submission_page_url' );
		if ( ! $base ) {
			$base = home_url( '/' );
		}
		$args = array_merge( array( 'cec_msg' => $msg ), $extra_args );
		wp_safe_redirect( add_query_arg( $args, CEC_Tokens::build_edit_url( $base, $event_id, $token ) ) );
		exit;
	}

	public static function handle_save_event() {
		$event_id = isset( $_POST['cec_event'] ) ? absint( $_POST['cec_event'] ) : 0;
		$token    = isset( $_POST['cec_token'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_token'] ) ) : '';

		if ( ! self::verify_request( $event_id, $token ) ) {
			wp_die( esc_html__( 'This link is invalid or has expired.', 'cec' ) );
		}

		wp_update_post(
			array(
				'ID'           => $event_id,
				'post_title'   => sanitize_text_field( wp_unslash( $_POST['cec_title'] ?? '' ) ),
				'post_excerpt' => sanitize_textarea_field( wp_unslash( $_POST['cec_excerpt'] ?? '' ) ),
				'post_content' => wp_kses_post( wp_unslash( $_POST['cec_description'] ?? '' ) ),
				'post_status'  => 'pending',
			)
		);

		$meta_fields = array(
			'cec_host_org_name'         => 'sanitize_text_field',
			'cec_host_org_url'          => 'esc_url_raw',
			'cec_more_info_url'         => 'esc_url_raw',
			'cec_organizers'            => 'sanitize_text_field',
			'cec_venue_custom_address'  => 'sanitize_text_field',
			'cec_venue_custom_maps_url' => 'esc_url_raw',
			'cec_start'                 => 'sanitize_text_field',
			'cec_end'                   => 'sanitize_text_field',
			'cec_price_note'            => 'sanitize_text_field',
			'cec_rsvp_mode'             => 'sanitize_text_field',
			'cec_rsvp_url'              => 'esc_url_raw',
			'cec_rsvp_capacity'         => 'absint',
			'cec_code_of_conduct'       => 'sanitize_textarea_field',
		);
		foreach ( $meta_fields as $field => $sanitizer ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $event_id, '_' . $field, call_user_func( $sanitizer, wp_unslash( $_POST[ $field ] ) ) );
			}
		}
		CEC_Event_Helper::save_phase1a_fields( $event_id );

		wp_set_post_terms( $event_id, ! empty( $_POST['cec_event_type'] ) ? array_map( 'absint', (array) $_POST['cec_event_type'] ) : array(), 'cec_event_type', false );
		$partner_org_ids = ! empty( $_POST['cec_partner_org'] ) ? array_map( 'absint', (array) $_POST['cec_partner_org'] ) : array();
		if ( ! empty( $_POST['cec_new_partner_org'] ) ) {
			$new_org_id = CEC_Term_Meta::get_or_create_partner_org( sanitize_text_field( wp_unslash( $_POST['cec_new_partner_org'] ) ) );
			if ( $new_org_id ) {
				$partner_org_ids[] = $new_org_id;
			}
		}
		wp_set_post_terms( $event_id, $partner_org_ids, 'cec_partner_org', false );
		wp_set_post_terms( $event_id, ! empty( $_POST['cec_venue_term'] ) ? array( absint( $_POST['cec_venue_term'] ) ) : array(), 'cec_venue', false );

		$photo_result = CEC_Event_Helper::handle_photo_upload( $event_id );

		if ( ! CEC_Recurrence::is_occurrence( $event_id ) ) {
			$rule = isset( $_POST['cec_recurrence_rule'] ) ? sanitize_key( $_POST['cec_recurrence_rule'] ) : 'none';
			update_post_meta( $event_id, '_cec_recurrence_rule', $rule );
			if ( isset( $_POST['cec_recurrence_until'] ) ) {
				update_post_meta( $event_id, '_cec_recurrence_until', sanitize_text_field( wp_unslash( $_POST['cec_recurrence_until'] ) ) );
			}
			if ( 'monthly_weekday' === $rule ) {
				list( $ordinals, $weekdays ) = CEC_Recurrence::sanitize_posted_ordinals_weekdays();
				update_post_meta( $event_id, '_cec_recurrence_ordinals', implode( ',', $ordinals ) );
				update_post_meta( $event_id, '_cec_recurrence_weekdays', implode( ',', $weekdays ) );
			}
			CEC_Recurrence::update_series( $event_id );
		}

		$extra_args = is_wp_error( $photo_result ) ? array( 'cec_photo_err' => $photo_result->get_error_code() ) : array();
		self::redirect_to_token_view( $event_id, $token, 'saved', $extra_args );
	}

	public static function handle_set_status() {
		$event_id = isset( $_POST['cec_event'] ) ? absint( $_POST['cec_event'] ) : 0;
		$token    = isset( $_POST['cec_token'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_token'] ) ) : '';

		if ( ! self::verify_request( $event_id, $token ) ) {
			wp_die( esc_html__( 'This link is invalid or has expired.', 'cec' ) );
		}

		$status     = isset( $_POST['cec_event_status'] ) ? sanitize_key( $_POST['cec_event_status'] ) : 'scheduled';
		$status     = in_array( $status, array( 'postponed', 'cancelled' ), true ) ? $status : 'scheduled';
		$old_status = CEC_Event_Helper::event_status( $event_id );
		update_post_meta( $event_id, '_cec_event_status', $status );
		if ( $old_status !== $status ) {
			CEC_Audit_Log::log( $event_id, 'event_' . $status );
		}
		CEC_Subscribers::maybe_notify_status_change( $event_id, $old_status, $status );
		if ( isset( $_POST['cec_event_status_note'] ) ) {
			update_post_meta( $event_id, '_cec_event_status_note', sanitize_text_field( wp_unslash( $_POST['cec_event_status_note'] ) ) );
		}

		self::redirect_to_token_view( $event_id, $token, 'status' );
	}

	public static function handle_withdraw() {
		$event_id = isset( $_POST['cec_event'] ) ? absint( $_POST['cec_event'] ) : 0;
		$token    = isset( $_POST['cec_token'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_token'] ) ) : '';

		if ( ! self::verify_request( $event_id, $token ) ) {
			wp_die( esc_html__( 'This link is invalid or has expired.', 'cec' ) );
		}

		wp_trash_post( $event_id );

		$base = CEC_Admin_Settings::get( 'manage_submission_page_url' );
		wp_safe_redirect( add_query_arg( 'cec_msg', 'withdrawn', $base ? $base : home_url( '/' ) ) );
		exit;
	}
}
