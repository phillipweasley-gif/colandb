<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets the person who submitted an event manage it themselves afterward —
 * edit details (which sends it back for re-approval), postpone/cancel a
 * date, or manage individual dates of a recurring series — without needing
 * the Calendar Manager role or wp-admin access.
 */
class CEC_My_Events {

	const NONCE_ACTION = 'cec_my_events_save';

	public static function render_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			ob_start();
			?>
			<div class="cec-notice">
				<p><?php esc_html_e( "Log in to view and manage the events you've submitted with an account.", 'cec' ); ?></p>
				<a class="cec-btn" href="<?php echo esc_url( CEC_Admin_Settings::login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Log In', 'cec' ); ?></a>
				<?php if ( get_option( 'users_can_register' ) ) : ?>
					<a class="cec-btn cec-btn-outline" href="<?php echo esc_url( CEC_Admin_Settings::register_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Create an Account', 'cec' ); ?></a>
				<?php endif; ?>
				<?php if ( CEC_Admin_Settings::get( 'allow_guest_submission' ) && CEC_Admin_Settings::get( 'manage_submission_page_url' ) ) : ?>
					<p class="description"><?php echo wp_kses_post( sprintf( __( 'Submitted as a guest without an account? <a href="%s">Manage your event here instead</a> using the edit link we emailed you.', 'cec' ), esc_url( CEC_Admin_Settings::get( 'manage_submission_page_url' ) ) ) ); ?></p>
				<?php endif; ?>
			</div>
			<?php
			return ob_get_clean();
		}

		$view = isset( $_GET['cec_view'] ) ? sanitize_key( wp_unslash( $_GET['cec_view'] ) ) : 'list';

		ob_start();
		echo '<div class="cec-dashboard cec-my-events">';
		echo self::render_notice();

		if ( 'edit' === $view && ! empty( $_GET['cec_edit'] ) ) {
			echo self::render_edit_form( absint( $_GET['cec_edit'] ) );
		} elseif ( 'series' === $view && ! empty( $_GET['cec_series'] ) ) {
			echo self::render_series( absint( $_GET['cec_series'] ) );
		} else {
			echo self::render_list();
		}

		echo '</div>';
		return ob_get_clean();
	}

	private static function render_notice() {
		$notice = '';
		if ( ! empty( $_GET['cec_msg'] ) ) {
			$messages = array(
				'saved' => __( 'Your changes were submitted and are awaiting admin approval before they go live.', 'cec' ),
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

	private static function render_list() {
		$query = new WP_Query(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => array( 'publish', 'pending', 'draft', 'cec_in_review' ),
				'author'         => get_current_user_id(),
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					array( 'key' => '_cec_recurrence_parent_id', 'compare' => 'NOT EXISTS' ),
				),
			)
		);

		ob_start();
		?>
		<h2><?php esc_html_e( 'My Submitted Events', 'cec' ); ?></h2>
		<?php if ( ! $query->have_posts() ) : ?>
			<p class="cec-no-events"><?php esc_html_e( "You haven't submitted any events yet.", 'cec' ); ?></p>
			<?php return ob_get_clean(); ?>
		<?php endif; ?>
		<table class="cec-dash-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Event', 'cec' ); ?></th>
					<th><?php esc_html_e( 'When', 'cec' ); ?></th>
					<th><?php esc_html_e( 'Status', 'cec' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'cec' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $query->posts as $p ) :
					$data       = CEC_Event_Helper::data( $p->ID );
					$is_series  = $data['is_recurring_root'];
					$series_count = $is_series ? count( get_posts( array( 'post_type' => 'cec_event', 'post_status' => array( 'publish', 'pending', 'draft', 'cec_in_review' ), 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_cec_recurrence_parent_id', 'meta_value' => $p->ID ) ) ) : 0;
					?>
					<tr data-event-row="<?php echo esc_attr( $p->ID ); ?>">
						<td class="cec-dash-title-cell">
							<?php if ( $data['thumb_calendar'] ) : ?><img src="<?php echo esc_url( $data['thumb_calendar'] ); ?>" alt="" class="cec-dash-thumb" /><?php endif; ?>
							<div>
								<strong><?php echo esc_html( $data['title'] ); ?></strong>
								<?php if ( $is_series ) : ?>
									<div class="cec-dash-excerpt"><?php echo esc_html( sprintf( __( 'Repeats · %d upcoming dates', 'cec' ), $series_count ) ); ?> — <a href="<?php echo esc_url( add_query_arg( array( 'cec_view' => 'series', 'cec_series' => $p->ID ), get_permalink() ) ); ?>"><?php esc_html_e( 'Manage Dates', 'cec' ); ?></a></div>
								<?php endif; ?>
								<?php if ( 'scheduled' !== $data['event_status'] ) : ?>
									<span class="cec-status-badge cec-status-<?php echo esc_attr( $data['event_status'] ); ?>"><?php echo esc_html( ucfirst( $data['event_status'] ) ); ?></span>
								<?php endif; ?>
							</div>
						</td>
						<td><?php echo esc_html( $data['start_display'] ); ?></td>
						<td><span class="cec-status-badge cec-status-<?php echo esc_attr( $p->post_status ); ?>"><?php echo esc_html( get_post_status_object( $p->post_status )->label ); ?></span></td>
						<td class="cec-dash-actions">
							<a class="cec-btn cec-btn-small cec-btn-outline" href="<?php echo esc_url( add_query_arg( array( 'cec_view' => 'edit', 'cec_edit' => $p->ID ), get_permalink() ) ); ?>"><?php esc_html_e( 'Edit', 'cec' ); ?></a>
							<?php if ( 'scheduled' !== $data['event_status'] ) : ?>
								<button type="button" class="cec-btn cec-btn-small cec-dash-status" data-status="scheduled" data-id="<?php echo esc_attr( $p->ID ); ?>"><?php esc_html_e( 'Reset', 'cec' ); ?></button>
							<?php else : ?>
								<button type="button" class="cec-btn cec-btn-small cec-btn-outline cec-dash-status" data-status="postponed" data-id="<?php echo esc_attr( $p->ID ); ?>"><?php esc_html_e( 'Postpone', 'cec' ); ?></button>
								<button type="button" class="cec-btn cec-btn-small cec-btn-outline cec-dash-status" data-status="cancelled" data-id="<?php echo esc_attr( $p->ID ); ?>"><?php esc_html_e( 'Cancel', 'cec' ); ?></button>
							<?php endif; ?>
							<button type="button" class="cec-btn cec-btn-small cec-btn-danger cec-dash-action" data-action="delete" data-id="<?php echo esc_attr( $p->ID ); ?>"><?php esc_html_e( 'Withdraw', 'cec' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return ob_get_clean();
	}

	private static function owns( $event_id ) {
		return 'cec_event' === get_post_type( $event_id ) && (int) get_post_field( 'post_author', $event_id ) === get_current_user_id();
	}

	private static function render_series( $parent_id ) {
		if ( ! self::owns( $parent_id ) ) {
			return '<p>' . esc_html__( 'Event not found.', 'cec' ) . '</p>';
		}
		return '<h2>' . esc_html( get_the_title( $parent_id ) ) . '</h2>' . CEC_Recurrence::render_occurrences_table( $parent_id, get_permalink() );
	}

	private static function render_edit_form( $event_id ) {
		if ( ! self::owns( $event_id ) ) {
			return '<p>' . esc_html__( "That event isn't yours to edit.", 'cec' ) . '</p>';
		}
		if ( CEC_Recurrence::is_occurrence( $event_id ) ) {
			$parent_id = CEC_Recurrence::get_parent_id( $event_id );
			return '<p>' . wp_kses_post( sprintf( __( 'This date is part of a recurring series. Use <a href="%1$s">Manage Dates</a> to postpone or cancel it, or <a href="%2$s">edit the series</a> to change details for every date.', 'cec' ), esc_url( add_query_arg( array( 'cec_view' => 'series', 'cec_series' => $parent_id ), get_permalink() ) ), esc_url( add_query_arg( array( 'cec_view' => 'edit', 'cec_edit' => $parent_id ), get_permalink() ) ) ) ) . '</p>';
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
		?>
		<a class="cec-back-link" href="<?php echo esc_url( remove_query_arg( array( 'cec_view', 'cec_edit' ) ) ); ?>">&larr; <?php esc_html_e( 'Back to my events', 'cec' ); ?></a>
		<?php if ( 'publish' === $post->post_status ) : ?>
			<div class="cec-notice"><?php esc_html_e( 'This event is already live. Saving changes here sends it back for admin approval before it stays visible.', 'cec' ); ?></div>
		<?php endif; ?>
		<form class="cec-submit-form cec-dash-edit-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cec_my_events_save" />
			<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>" />
			<input type="hidden" name="cec_redirect" value="<?php echo esc_url( remove_query_arg( array( 'cec_view', 'cec_edit' ) ) ); ?>" />
			<?php wp_nonce_field( self::NONCE_ACTION, 'cec_my_events_nonce' ); ?>

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
			<p class="description"><?php esc_html_e( 'Changing repeat settings regenerates all upcoming dates and clears any individual postpone/cancel changes made on them.', 'cec' ); ?></p>

			<button type="submit" class="cec-btn"><?php esc_html_e( 'Save Changes', 'cec' ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function handle_save_event() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to do that.', 'cec' ) );
		}
		if ( ! isset( $_POST['cec_my_events_nonce'] ) || ! wp_verify_nonce( $_POST['cec_my_events_nonce'], self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'cec' ) );
		}

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$redirect = isset( $_POST['cec_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['cec_redirect'] ) ) : home_url();

		if ( ! self::owns( $event_id ) ) {
			wp_die( esc_html__( "That event isn't yours to edit.", 'cec' ) );
		}
		if ( CEC_Recurrence::is_occurrence( $event_id ) ) {
			wp_die( esc_html__( 'Individual dates in a series can only be postponed, cancelled, or removed — edit the series root to change its details.', 'cec' ) );
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
		if ( is_wp_error( $photo_result ) ) {
			$redirect = add_query_arg( 'cec_photo_err', $photo_result->get_error_code(), $redirect );
		}

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

		wp_safe_redirect( add_query_arg( 'cec_msg', 'saved', $redirect ) );
		exit;
	}
}
