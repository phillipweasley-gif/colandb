<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_Submission_Form {

	const NONCE_ACTION = 'cec_submit_event_action';

	public static function render_shortcode( $atts ) {
		$require_login = CEC_Admin_Settings::get( 'require_login' );
		$guest_allowed  = CEC_Admin_Settings::get( 'allow_guest_submission' );
		$logged_in      = is_user_logged_in();

		if ( ! $logged_in && $require_login && ! $guest_allowed ) {
			ob_start();
			?>
			<div class="cec-notice">
				<p><?php esc_html_e( 'You need a member account to submit an event to the community calendar.', 'cec' ); ?></p>
				<a class="cec-btn" href="<?php echo esc_url( CEC_Admin_Settings::login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Log In', 'cec' ); ?></a>
				<?php if ( get_option( 'users_can_register' ) ) : ?>
					<a class="cec-btn cec-btn-outline" href="<?php echo esc_url( CEC_Admin_Settings::register_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Create an Account', 'cec' ); ?></a>
				<?php endif; ?>
			</div>
			<?php
			return ob_get_clean();
		}

		$notice = '';
		if ( isset( $_GET['cec_submitted'] ) ) {
			if ( '1' === $_GET['cec_submitted'] ) {
				$notice = '<div class="cec-notice cec-notice-success">' . esc_html__( 'Thanks! Your event was submitted and is awaiting admin approval before it appears on the calendar.', 'cec' ) . '</div>';
			} elseif ( 'guest' === $_GET['cec_submitted'] ) {
				$notice = '<div class="cec-notice cec-notice-success">' . esc_html__( "Thanks! Your event was submitted and is awaiting admin approval. We've emailed you a link to edit or withdraw it later — no account needed.", 'cec' ) . '</div>';
			} else {
				$notice = '<div class="cec-notice cec-notice-error">' . esc_html__( 'There was a problem with your submission. Please check the form and try again.', 'cec' ) . '</div>';
			}
		}
		if ( ! empty( $_GET['cec_photo_err'] ) ) {
			$notice .= '<div class="cec-notice cec-notice-error">' . esc_html( CEC_Event_Helper::photo_error_message( sanitize_key( wp_unslash( $_GET['cec_photo_err'] ) ) ) ) . '</div>';
		}

		$event_types  = get_terms( array( 'taxonomy' => 'cec_event_type', 'hide_empty' => false ) );
		$partner_orgs = get_terms( array( 'taxonomy' => 'cec_partner_org', 'hide_empty' => false ) );
		$venues       = get_terms( array( 'taxonomy' => 'cec_venue', 'hide_empty' => false ) );

		ob_start();
		?>
		<?php echo $notice; // phpcs:ignore ?>

		<?php if ( ! $logged_in ) : ?>
			<div class="cec-notice">
				<p><?php esc_html_e( 'Have an account? Log in to manage everything you submit in one place.', 'cec' ); ?></p>
				<a class="cec-btn cec-btn-small" href="<?php echo esc_url( CEC_Admin_Settings::login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Log In', 'cec' ); ?></a>
				<?php if ( get_option( 'users_can_register' ) ) : ?>
					<a class="cec-btn cec-btn-small cec-btn-outline" href="<?php echo esc_url( CEC_Admin_Settings::register_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Create an Account', 'cec' ); ?></a>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( "Or just fill out the form below as a guest — we'll email you a link to edit it later.", 'cec' ); ?></p>
			</div>
		<?php endif; ?>

		<form class="cec-submit-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cec_submit_event" />
			<input type="hidden" name="cec_redirect" value="<?php echo esc_url( get_permalink() ); ?>" />
			<?php wp_nonce_field( self::NONCE_ACTION, 'cec_submit_nonce' ); ?>

			<?php if ( ! $logged_in ) : ?>
				<div class="cec-field"><label><?php esc_html_e( 'Your Email * (for admin questions and your edit link)', 'cec' ); ?></label><input type="email" name="cec_submitter_email" required></div>
			<?php endif; ?>

			<div class="cec-field"><label><?php esc_html_e( 'Event Title *', 'cec' ); ?></label><input type="text" name="cec_title" required></div>

			<div class="cec-field"><label><?php esc_html_e( 'Short Description * (shown collapsed on the calendar, up to 1,000 characters)', 'cec' ); ?></label><textarea name="cec_excerpt" maxlength="1000" rows="3" required></textarea></div>

			<div class="cec-field"><label><?php esc_html_e( 'Full Description *', 'cec' ); ?></label><textarea name="cec_description" rows="6" required></textarea></div>

			<div class="cec-field"><label><?php esc_html_e( 'Event Photo / Thumbnail', 'cec' ); ?></label><input type="file" name="cec_photo" accept="image/*"><p class="cec-field-hint"><?php esc_html_e( 'JPG, PNG, GIF, or WEBP, up to 5MB.', 'cec' ); ?></p></div>
			<div class="cec-field"><label><?php esc_html_e( 'Photo Alt Text (for screen readers)', 'cec' ); ?></label><input type="text" name="cec_photo_alt" maxlength="250" placeholder="<?php esc_attr_e( 'Briefly describe the photo', 'cec' ); ?>"></div>

			<div class="cec-field">
				<label><?php esc_html_e( 'Event Type *', 'cec' ); ?></label>
				<?php foreach ( $event_types as $term ) : ?>
					<label class="cec-checkbox"><input type="checkbox" name="cec_event_type[]" value="<?php echo esc_attr( $term->term_id ); ?>"> <?php echo esc_html( $term->name ); ?></label>
				<?php endforeach; ?>
			</div>

			<div class="cec-field-row">
				<div class="cec-field"><label><?php esc_html_e( 'Host Organization Name', 'cec' ); ?></label><input type="text" name="cec_host_org_name"></div>
				<div class="cec-field"><label><?php esc_html_e( 'Host Organization Website', 'cec' ); ?></label><input type="url" name="cec_host_org_url" placeholder="https://"></div>
			</div>

			<div class="cec-field-row">
				<div class="cec-field"><label><?php esc_html_e( 'Official Event Website', 'cec' ); ?></label><input type="url" name="cec_official_website_url" placeholder="https://"><p class="cec-field-hint"><?php esc_html_e( "Optional — this event's own website, if it has one separate from the host organization's.", 'cec' ); ?></p></div>
				<div class="cec-field"><label><?php esc_html_e( 'More Info Link', 'cec' ); ?></label><input type="url" name="cec_more_info_url" placeholder="https://"><p class="cec-field-hint"><?php esc_html_e( 'Optional — a link for anyone who wants more details, like a full event listing or flyer.', 'cec' ); ?></p></div>
			</div>

			<div class="cec-field"><label><?php esc_html_e( 'Event Host(s) / Organizer(s) *', 'cec' ); ?></label><input type="text" name="cec_organizers" required></div>

			<?php if ( ! empty( $partner_orgs ) && ! is_wp_error( $partner_orgs ) ) : ?>
			<div class="cec-field">
				<label><?php esc_html_e( 'Partner Organization(s)', 'cec' ); ?></label>
				<?php foreach ( $partner_orgs as $term ) : ?>
					<label class="cec-checkbox"><input type="checkbox" name="cec_partner_org[]" value="<?php echo esc_attr( $term->term_id ); ?>"> <?php echo esc_html( $term->name ); ?></label>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<div class="cec-field">
				<label><?php esc_html_e( "Don't see your organization? Add it", 'cec' ); ?></label>
				<input type="text" name="cec_new_partner_org" placeholder="<?php esc_attr_e( 'Organization name', 'cec' ); ?>">
			</div>

			<div class="cec-field">
				<label><?php esc_html_e( 'Location Type *', 'cec' ); ?></label>
				<select name="cec_location_mode" class="cec-location-mode-select" required>
					<option value="in_person"><?php esc_html_e( 'In Person', 'cec' ); ?></option>
					<option value="online"><?php esc_html_e( 'Online', 'cec' ); ?></option>
					<option value="hybrid"><?php esc_html_e( 'Hybrid (In Person & Online)', 'cec' ); ?></option>
					<option value="not_posted"><?php esc_html_e( 'Not decided yet', 'cec' ); ?></option>
				</select>
			</div>
			<div class="cec-field cec-location-online-fields">
				<label><?php esc_html_e( 'Online Access Link', 'cec' ); ?></label>
				<input type="url" name="cec_online_url" placeholder="https://">
			</div>
			<div class="cec-location-in-person-fields">
				<div class="cec-field">
					<label><?php esc_html_e( 'Venue', 'cec' ); ?></label>
					<select name="cec_venue_term">
						<option value=""><?php esc_html_e( '— Custom address below —', 'cec' ); ?></option>
						<?php foreach ( $venues as $term ) : ?>
							<option value="<?php echo esc_attr( $term->term_id ); ?>"><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="cec-field-row">
					<div class="cec-field"><label><?php esc_html_e( 'Custom Address', 'cec' ); ?></label><input type="text" name="cec_venue_custom_address"></div>
					<div class="cec-field"><label><?php esc_html_e( 'Google Maps Link (optional)', 'cec' ); ?></label><input type="url" name="cec_venue_custom_maps_url" placeholder="https://maps.google.com/..."></div>
				</div>
				<div class="cec-field-row">
					<div class="cec-field"><label><?php esc_html_e( 'City', 'cec' ); ?></label><input type="text" name="cec_venue_custom_city"></div>
					<div class="cec-field"><label><?php esc_html_e( 'State / Region', 'cec' ); ?></label><input type="text" name="cec_venue_custom_region"></div>
					<div class="cec-field"><label><?php esc_html_e( 'Country', 'cec' ); ?></label><input type="text" name="cec_venue_custom_country"></div>
				</div>
			</div>

			<div class="cec-field-row">
				<div class="cec-field"><label><?php esc_html_e( 'Start Date & Time *', 'cec' ); ?></label><input type="datetime-local" name="cec_start" required></div>
				<div class="cec-field"><label><?php esc_html_e( 'End Date & Time', 'cec' ); ?></label><input type="datetime-local" name="cec_end"></div>
			</div>
			<div class="cec-field-row">
				<div class="cec-field">
					<label><?php esc_html_e( 'Time Details', 'cec' ); ?></label>
					<select name="cec_time_mode">
						<option value="exact"><?php esc_html_e( 'Exact start/end time', 'cec' ); ?></option>
						<option value="start_only"><?php esc_html_e( 'Start time only (no set end)', 'cec' ); ?></option>
						<option value="all_day"><?php esc_html_e( 'All day (no specific time)', 'cec' ); ?></option>
						<option value="varies"><?php esc_html_e( 'Schedule varies', 'cec' ); ?></option>
					</select>
				</div>
				<div class="cec-field">
					<label><?php esc_html_e( 'Timezone', 'cec' ); ?></label>
					<?php echo CEC_Event_Helper::timezone_select_html( 'cec_timezone', '' ); // phpcs:ignore ?>
				</div>
			</div>

			<div class="cec-field">
				<label><?php esc_html_e( 'Admission *', 'cec' ); ?></label>
				<select name="cec_admission_status" class="cec-admission-status-select" required>
					<option value="not_posted"><?php esc_html_e( "Price not decided yet", 'cec' ); ?></option>
					<option value="free_confirmed"><?php esc_html_e( 'Free', 'cec' ); ?></option>
					<option value="paid"><?php esc_html_e( 'Paid', 'cec' ); ?></option>
					<option value="price_varies"><?php esc_html_e( 'Price varies', 'cec' ); ?></option>
				</select>
			</div>
			<div class="cec-field-row cec-admission-paid-fields">
				<div class="cec-field"><label><?php esc_html_e( 'Price', 'cec' ); ?></label><input type="number" step="0.01" min="0" name="cec_price_amount"></div>
				<div class="cec-field"><label><?php esc_html_e( 'Currency', 'cec' ); ?></label><input type="text" name="cec_price_currency" maxlength="3" value="USD"></div>
			</div>
			<div class="cec-field-row cec-admission-varies-fields">
				<div class="cec-field"><label><?php esc_html_e( 'Price From', 'cec' ); ?></label><input type="number" step="0.01" min="0" name="cec_price_min"></div>
				<div class="cec-field"><label><?php esc_html_e( 'Price To', 'cec' ); ?></label><input type="number" step="0.01" min="0" name="cec_price_max"></div>
			</div>
			<div class="cec-field-row">
				<div class="cec-field"><label><?php esc_html_e( 'Price Note (optional)', 'cec' ); ?></label><input type="text" name="cec_price_note" placeholder="<?php esc_attr_e( 'e.g. suggested donation, cash only at the door', 'cec' ); ?>"></div>
				<div class="cec-field">
					<label><?php esc_html_e( 'RSVP / Registration', 'cec' ); ?></label>
					<select name="cec_rsvp_mode">
						<option value="none"><?php esc_html_e( 'No RSVP needed', 'cec' ); ?></option>
						<option value="internal"><?php esc_html_e( 'Collect RSVPs on this site', 'cec' ); ?></option>
						<option value="external"><?php esc_html_e( 'Link to external registration', 'cec' ); ?></option>
					</select>
					<input type="url" name="cec_rsvp_url" placeholder="https://" style="margin-top:6px;">
				</div>
			</div>

			<div class="cec-field"><label><?php esc_html_e( 'Code of Conduct (text or link)', 'cec' ); ?></label><textarea name="cec_code_of_conduct" rows="2"></textarea></div>

			<div class="cec-field-row">
				<div class="cec-field">
					<label><?php esc_html_e( 'Repeats', 'cec' ); ?></label>
					<select name="cec_recurrence_rule" class="cec-recurrence-rule">
						<option value="none"><?php esc_html_e( 'Does not repeat', 'cec' ); ?></option>
						<option value="weekly"><?php esc_html_e( 'Weekly', 'cec' ); ?></option>
						<option value="monthly_date"><?php esc_html_e( 'Monthly (same date)', 'cec' ); ?></option>
						<option value="monthly_weekday"><?php esc_html_e( 'Monthly (specific weekday)', 'cec' ); ?></option>
					</select>
				</div>
				<div class="cec-field cec-recurrence-until-field" hidden>
					<label><?php esc_html_e( 'Repeat Until', 'cec' ); ?></label>
					<input type="date" name="cec_recurrence_until">
				</div>
			</div>
			<?php echo CEC_Recurrence::render_monthly_weekday_fields(); // phpcs:ignore ?>

			<button type="submit" class="cec-btn"><?php esc_html_e( 'Submit Event for Review', 'cec' ); ?></button>
			<p class="description"><?php esc_html_e( 'An admin will review your submission before it appears on the public calendar.', 'cec' ); ?></p>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function handle_submit_nopriv() {
		if ( CEC_Admin_Settings::get( 'allow_guest_submission' ) ) {
			self::process_submission( true );
			return;
		}
		wp_safe_redirect( add_query_arg( 'cec_submitted', '0', wp_get_referer() ) );
		exit;
	}

	public static function handle_submit() {
		self::process_submission( false );
	}

	private static function process_submission( $is_guest ) {
		$redirect = isset( $_POST['cec_redirect'] ) ? esc_url_raw( $_POST['cec_redirect'] ) : home_url();

		if ( ! isset( $_POST['cec_submit_nonce'] ) || ! wp_verify_nonce( $_POST['cec_submit_nonce'], self::NONCE_ACTION ) ) {
			wp_safe_redirect( add_query_arg( 'cec_submitted', '0', $redirect ) );
			exit;
		}

		if ( ! $is_guest && CEC_Admin_Settings::get( 'require_login' ) && ! is_user_logged_in() ) {
			wp_safe_redirect( add_query_arg( 'cec_submitted', '0', $redirect ) );
			exit;
		}

		$title       = isset( $_POST['cec_title'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_title'] ) ) : '';
		$excerpt     = isset( $_POST['cec_excerpt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cec_excerpt'] ) ) : '';
		$description = isset( $_POST['cec_description'] ) ? wp_kses_post( wp_unslash( $_POST['cec_description'] ) ) : '';
		$start       = isset( $_POST['cec_start'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_start'] ) ) : '';
		$organizers  = isset( $_POST['cec_organizers'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_organizers'] ) ) : '';

		if ( ! $title || ! $excerpt || ! $description || ! $start || ! $organizers ) {
			wp_safe_redirect( add_query_arg( 'cec_submitted', '0', $redirect ) );
			exit;
		}

		$submitter_email = '';
		if ( $is_guest ) {
			$submitter_email = isset( $_POST['cec_submitter_email'] ) ? sanitize_email( wp_unslash( $_POST['cec_submitter_email'] ) ) : '';
			if ( ! is_email( $submitter_email ) ) {
				wp_safe_redirect( add_query_arg( 'cec_submitted', '0', $redirect ) );
				exit;
			}
		}

		$status = CEC_Admin_Settings::get( 'auto_publish' ) ? 'publish' : 'pending';

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'cec_event',
				'post_title'   => $title,
				'post_excerpt' => $excerpt,
				'post_content' => $description,
				'post_status'  => $status,
				'post_author'  => $is_guest ? 0 : get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			wp_safe_redirect( add_query_arg( 'cec_submitted', '0', $redirect ) );
			exit;
		}

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
			'cec_code_of_conduct'       => 'sanitize_textarea_field',
		);
		foreach ( $meta_fields as $field => $sanitizer ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, '_' . $field, call_user_func( $sanitizer, wp_unslash( $_POST[ $field ] ) ) );
			}
		}
		update_post_meta( $post_id, '_cec_event_status', 'scheduled' );
		CEC_Event_Helper::save_phase1a_fields( $post_id );

		if ( $is_guest ) {
			update_post_meta( $post_id, '_cec_submitter_email', $submitter_email );
		}

		$recurrence_rule = isset( $_POST['cec_recurrence_rule'] ) ? sanitize_key( $_POST['cec_recurrence_rule'] ) : 'none';
		update_post_meta( $post_id, '_cec_recurrence_rule', $recurrence_rule );
		if ( 'none' !== $recurrence_rule && isset( $_POST['cec_recurrence_until'] ) ) {
			update_post_meta( $post_id, '_cec_recurrence_until', sanitize_text_field( wp_unslash( $_POST['cec_recurrence_until'] ) ) );
		}
		if ( 'monthly_weekday' === $recurrence_rule ) {
			list( $ordinals, $weekdays ) = CEC_Recurrence::sanitize_posted_ordinals_weekdays();
			update_post_meta( $post_id, '_cec_recurrence_ordinals', implode( ',', $ordinals ) );
			update_post_meta( $post_id, '_cec_recurrence_weekdays', implode( ',', $weekdays ) );
		}

		if ( ! empty( $_POST['cec_event_type'] ) ) {
			wp_set_post_terms( $post_id, array_map( 'absint', (array) $_POST['cec_event_type'] ), 'cec_event_type', false );
		}
		$partner_org_ids = ! empty( $_POST['cec_partner_org'] ) ? array_map( 'absint', (array) $_POST['cec_partner_org'] ) : array();
		if ( ! empty( $_POST['cec_new_partner_org'] ) ) {
			$new_org_id = CEC_Term_Meta::get_or_create_partner_org( sanitize_text_field( wp_unslash( $_POST['cec_new_partner_org'] ) ) );
			if ( $new_org_id ) {
				$partner_org_ids[] = $new_org_id;
			}
		}
		if ( ! empty( $partner_org_ids ) ) {
			wp_set_post_terms( $post_id, $partner_org_ids, 'cec_partner_org', false );
		}
		if ( ! empty( $_POST['cec_venue_term'] ) ) {
			wp_set_post_terms( $post_id, array( absint( $_POST['cec_venue_term'] ) ), 'cec_venue', false );
		}

		$photo_result = CEC_Event_Helper::handle_photo_upload( $post_id );

		if ( 'none' !== $recurrence_rule ) {
			CEC_Recurrence::update_series( $post_id );
		}

		if ( 'pending' === $status ) {
			wp_mail(
				get_option( 'admin_email' ),
				sprintf( __( '[%s] New event submitted for approval', 'cec' ), get_bloginfo( 'name' ) ),
				sprintf( __( "A new event \"%1\$s\" was submitted and is awaiting your approval:\n%2\$s", 'cec' ), $title, admin_url( 'post.php?post=' . $post_id . '&action=edit' ) )
			);
		}

		if ( $is_guest ) {
			self::send_guest_confirmation( $post_id, $submitter_email, $title );
		}

		$redirect_args = array( 'cec_submitted' => $is_guest ? 'guest' : '1' );
		if ( is_wp_error( $photo_result ) ) {
			$redirect_args['cec_photo_err'] = $photo_result->get_error_code();
		}
		wp_safe_redirect( add_query_arg( $redirect_args, $redirect ) );
		exit;
	}

	private static function send_guest_confirmation( $post_id, $email, $title ) {
		$base_url = CEC_Admin_Settings::get( 'manage_submission_page_url' );
		if ( ! $base_url ) {
			return; // no manage page configured — nothing to link them to.
		}

		$token = CEC_Tokens::issue_for_event( $post_id );
		$url   = CEC_Tokens::build_edit_url( $base_url, $post_id, $token );

		wp_mail(
			$email,
			sprintf( __( '[%s] Manage your event: %s', 'cec' ), get_bloginfo( 'name' ), $title ),
			sprintf( __( "Thanks for submitting \"%1\$s\"! It's awaiting admin approval.\n\nUse this link anytime to edit it, mark it postponed/cancelled, or withdraw it (valid for 7 days, request a fresh one from the page if it expires):\n%2\$s", 'cec' ), $title, $url )
		);
	}
}
