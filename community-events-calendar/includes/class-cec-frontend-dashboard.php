<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_Frontend_Dashboard {

	const NONCE_ACTION = 'cec_dashboard_save_event';
	const TERM_NONCE   = 'cec_dashboard_term_action';
	const IMPORT_NONCE = 'cec_dashboard_import_ics';

	public static function render_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			return self::render_login_prompt();
		}
		if ( ! CEC_Roles::can_manage() ) {
			return '<div class="cec-notice cec-notice-error">' . esc_html__( "You're logged in, but this account doesn't have permission to manage the calendar.", 'cec' ) . '</div>';
		}

		$view = isset( $_GET['cec_view'] ) ? sanitize_key( wp_unslash( $_GET['cec_view'] ) ) : 'list';

		ob_start();
		echo '<div class="cec-dashboard">';
		echo self::render_notice();
		echo self::render_nav();

		if ( 'edit' === $view && ! empty( $_GET['cec_edit'] ) ) {
			echo self::render_edit_form( absint( $_GET['cec_edit'] ) );
		} elseif ( 'series' === $view && ! empty( $_GET['cec_series'] ) ) {
			$series_id = absint( $_GET['cec_series'] );
			echo '<h2>' . esc_html( get_the_title( $series_id ) ) . '</h2>' . CEC_Recurrence::render_occurrences_table( $series_id, get_permalink() );
		} elseif ( 'partners' === $view ) {
			echo self::render_terms_manager( 'cec_partner_org', __( 'Partner Organizations & Titleholders', 'cec' ) );
		} elseif ( 'venues' === $view ) {
			echo self::render_terms_manager( 'cec_venue', __( 'Venues', 'cec' ) );
		} elseif ( 'import' === $view ) {
			echo self::render_import();
		} else {
			echo self::render_list();
		}

		echo '</div>';
		return ob_get_clean();
	}

	private static function render_login_prompt() {
		ob_start();
		?>
		<div class="cec-notice">
			<p><?php esc_html_e( 'Please log in to access the calendar management dashboard.', 'cec' ); ?></p>
			<a class="cec-btn" href="<?php echo esc_url( CEC_Admin_Settings::login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Log In', 'cec' ); ?></a>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_notice() {
		if ( empty( $_GET['cec_msg'] ) ) {
			return '';
		}
		$key = sanitize_key( wp_unslash( $_GET['cec_msg'] ) );

		if ( 'imported' === $key ) {
			return self::render_import_result_notice();
		}

		$messages = array(
			'approved' => __( 'Event approved and published.', 'cec' ),
			'rejected' => __( 'Event unpublished.', 'cec' ),
			'deleted'  => __( 'Event moved to trash.', 'cec' ),
			'saved'    => __( 'Event saved.', 'cec' ),
			'term_added'   => __( 'Added.', 'cec' ),
			'term_deleted' => __( 'Removed.', 'cec' ),
			'import_failed' => __( "That file couldn't be read — make sure it's a valid .ics calendar file and try again.", 'cec' ),
		);
		if ( ! empty( $_GET['cec_photo_err'] ) ) {
			$messages['photo_err_shown'] = CEC_Event_Helper::photo_error_message( sanitize_key( wp_unslash( $_GET['cec_photo_err'] ) ) );
			$key = 'photo_err_shown';
		}
		if ( empty( $messages[ $key ] ) ) {
			return '';
		}
		$class = ( 'import_failed' === $key || 'photo_err_shown' === $key ) ? 'cec-notice-error' : 'cec-notice-success';
		return '<div class="cec-notice ' . esc_attr( $class ) . '">' . esc_html( $messages[ $key ] ) . '</div>';
	}

	/**
	 * An import result is a list of per-event notes, not one short
	 * phrase, so it travels as a short-lived transient (keyed by a token
	 * in the redirect URL) rather than being crammed into query args.
	 */
	private static function render_import_result_notice() {
		$token = isset( $_GET['cec_import_token'] ) ? sanitize_key( wp_unslash( $_GET['cec_import_token'] ) ) : '';
		if ( ! $token ) {
			return '';
		}
		$result = get_transient( 'cec_ics_import_' . $token );
		if ( false === $result ) {
			return '';
		}
		delete_transient( 'cec_ics_import_' . $token );

		ob_start();
		?>
		<div class="cec-notice cec-notice-success">
			<p>
				<?php
				printf(
					/* translators: %d: number of events created */
					esc_html( _n( '%d event imported.', '%d events imported.', (int) $result['created'], 'cec' ) ),
					(int) $result['created']
				);
				if ( ! empty( $result['updated'] ) ) {
					echo ' ';
					printf(
						/* translators: %d: number of events updated */
						esc_html( _n( '%d existing event updated.', '%d existing events updated.', (int) $result['updated'], 'cec' ) ),
						(int) $result['updated']
					);
				}
				?>
			</p>
			<?php if ( ! empty( $result['errors'] ) ) : ?>
				<ul class="cec-import-notes">
					<?php foreach ( $result['errors'] as $note ) : ?>
						<li><?php echo esc_html( $note ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_nav() {
		$status = isset( $_GET['cec_status'] ) ? sanitize_key( wp_unslash( $_GET['cec_status'] ) ) : 'pending';
		$view   = isset( $_GET['cec_view'] ) ? sanitize_key( wp_unslash( $_GET['cec_view'] ) ) : 'list';

		$counts = wp_count_posts( 'cec_event' );
		ob_start();
		?>
		<nav class="cec-dash-nav">
			<a class="<?php echo ( 'list' === $view && 'pending' === $status ) ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'cec_view' => 'list', 'cec_status' => 'pending' ), get_permalink() ) ); ?>">
				<?php esc_html_e( 'Pending Approval', 'cec' ); ?> <span class="cec-count"><?php echo (int) $counts->pending; ?></span>
			</a>
			<a class="<?php echo ( 'list' === $view && 'cec_in_review' === $status ) ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'cec_view' => 'list', 'cec_status' => 'cec_in_review' ), get_permalink() ) ); ?>">
				<?php esc_html_e( 'In Review', 'cec' ); ?> <span class="cec-count"><?php echo isset( $counts->cec_in_review ) ? (int) $counts->cec_in_review : 0; ?></span>
			</a>
			<a class="<?php echo ( 'list' === $view && 'publish' === $status ) ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'cec_view' => 'list', 'cec_status' => 'publish' ), get_permalink() ) ); ?>">
				<?php esc_html_e( 'Published', 'cec' ); ?> <span class="cec-count"><?php echo (int) $counts->publish; ?></span>
			</a>
			<a class="<?php echo ( 'list' === $view && 'draft' === $status ) ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'cec_view' => 'list', 'cec_status' => 'draft' ), get_permalink() ) ); ?>">
				<?php esc_html_e( 'Rejected / Draft', 'cec' ); ?> <span class="cec-count"><?php echo (int) $counts->draft; ?></span>
			</a>
			<a class="<?php echo ( 'list' === $view && 'all' === $status ) ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'cec_view' => 'list', 'cec_status' => 'all' ), get_permalink() ) ); ?>">
				<?php esc_html_e( 'All Events', 'cec' ); ?>
			</a>
			<a class="<?php echo ( 'partners' === $view ) ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'cec_view' => 'partners' ), get_permalink() ) ); ?>">
				<?php esc_html_e( 'Partners & Titleholders', 'cec' ); ?>
			</a>
			<a class="<?php echo ( 'venues' === $view ) ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'cec_view' => 'venues' ), get_permalink() ) ); ?>">
				<?php esc_html_e( 'Venues', 'cec' ); ?>
			</a>
			<a class="<?php echo ( 'import' === $view ) ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'cec_view' => 'import' ), get_permalink() ) ); ?>">
				<?php esc_html_e( 'Import Events', 'cec' ); ?>
			</a>
		</nav>
		<?php
		return ob_get_clean();
	}

	private static function render_list() {
		$status = isset( $_GET['cec_status'] ) ? sanitize_key( wp_unslash( $_GET['cec_status'] ) ) : 'pending';
		$paged  = isset( $_GET['cec_paged'] ) ? max( 1, absint( $_GET['cec_paged'] ) ) : 1;

		$post_status = 'all' === $status ? array( 'publish', 'pending', 'draft', 'cec_in_review' ) : $status;

		$query = new WP_Query(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => $post_status,
				'posts_per_page' => 20,
				'paged'          => $paged,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					array( 'key' => '_cec_recurrence_parent_id', 'compare' => 'NOT EXISTS' ),
				),
			)
		);

		ob_start();
		if ( ! $query->have_posts() ) {
			echo '<p class="cec-no-events">' . esc_html__( 'Nothing here.', 'cec' ) . '</p>';
			return ob_get_clean();
		}
		?>
		<table class="cec-dash-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Event', 'cec' ); ?></th>
					<th><?php esc_html_e( 'When / Where', 'cec' ); ?></th>
					<th><?php esc_html_e( 'Submitted By', 'cec' ); ?></th>
					<th><?php esc_html_e( 'Status', 'cec' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'cec' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $query->posts as $p ) : ?>
					<?php $data = CEC_Event_Helper::data( $p->ID ); ?>
					<tr data-event-row="<?php echo esc_attr( $p->ID ); ?>">
						<td class="cec-dash-title-cell">
							<?php if ( $data['thumb_calendar'] ) : ?><img src="<?php echo esc_url( $data['thumb_calendar'] ); ?>" alt="" class="cec-dash-thumb" /><?php endif; ?>
							<div>
								<strong><?php echo esc_html( $data['title'] ); ?></strong>
								<div class="cec-dash-excerpt"><?php echo esc_html( wp_trim_words( $data['excerpt'], 16 ) ); ?></div>
								<?php if ( $data['is_recurring_root'] ) : ?>
									<div class="cec-dash-excerpt"><?php esc_html_e( 'Repeats', 'cec' ); ?> — <a href="<?php echo esc_url( add_query_arg( array( 'cec_view' => 'series', 'cec_series' => $p->ID ), get_permalink() ) ); ?>"><?php esc_html_e( 'Manage Dates', 'cec' ); ?></a></div>
								<?php endif; ?>
								<?php if ( 'scheduled' !== $data['event_status'] ) : ?>
									<span class="cec-status-badge cec-status-<?php echo esc_attr( $data['event_status'] ); ?>"><?php echo esc_html( ucfirst( $data['event_status'] ) ); ?></span>
								<?php endif; ?>
							</div>
						</td>
						<?php $where = CEC_Event_Helper::location_display( $data ); ?>
						<td>
							<?php echo esc_html( $data['start_display'] ); ?><br />
							<?php echo esc_html( $where['label'] ); ?>
						</td>
						<td><?php echo esc_html( get_the_author_meta( 'display_name', $p->post_author ) ); ?></td>
						<td><span class="cec-status-badge cec-status-<?php echo esc_attr( $p->post_status ); ?>"><?php echo esc_html( get_post_status_object( $p->post_status )->label ); ?></span></td>
						<td class="cec-dash-actions">
							<?php if ( 'publish' !== $p->post_status ) : ?>
								<button type="button" class="cec-btn cec-btn-small cec-dash-action" data-action="approve" data-id="<?php echo esc_attr( $p->ID ); ?>"><?php esc_html_e( 'Approve', 'cec' ); ?></button>
							<?php else : ?>
								<button type="button" class="cec-btn cec-btn-small cec-btn-outline cec-dash-action" data-action="reject" data-id="<?php echo esc_attr( $p->ID ); ?>"><?php esc_html_e( 'Unpublish', 'cec' ); ?></button>
							<?php endif; ?>
							<a class="cec-btn cec-btn-small cec-btn-outline" href="<?php echo esc_url( add_query_arg( array( 'cec_view' => 'edit', 'cec_edit' => $p->ID ), get_permalink() ) ); ?>"><?php esc_html_e( 'Edit', 'cec' ); ?></a>
							<button type="button" class="cec-btn cec-btn-small cec-btn-danger cec-dash-action" data-action="delete" data-id="<?php echo esc_attr( $p->ID ); ?>"><?php esc_html_e( 'Delete', 'cec' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $query->max_num_pages > 1 ) : ?>
			<div class="cec-dash-pagination">
				<?php for ( $i = 1; $i <= $query->max_num_pages; $i++ ) : ?>
					<a class="<?php echo $i === $paged ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'cec_paged', $i ) ); ?>"><?php echo (int) $i; ?></a>
				<?php endfor; ?>
			</div>
		<?php endif;
		return ob_get_clean();
	}

	private static function render_edit_form( $event_id ) {
		if ( 'cec_event' !== get_post_type( $event_id ) ) {
			return '<p>' . esc_html__( 'Event not found.', 'cec' ) . '</p>';
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
		<a class="cec-back-link" href="<?php echo esc_url( remove_query_arg( array( 'cec_view', 'cec_edit' ) ) ); ?>">&larr; <?php esc_html_e( 'Back to list', 'cec' ); ?></a>
		<form class="cec-submit-form cec-dash-edit-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cec_dashboard_save_event" />
			<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>" />
			<input type="hidden" name="cec_redirect" value="<?php echo esc_url( remove_query_arg( array( 'cec_view', 'cec_edit' ) ) ); ?>" />
			<?php wp_nonce_field( self::NONCE_ACTION, 'cec_dash_nonce' ); ?>

			<div class="cec-field-row">
				<div class="cec-field">
					<label><?php esc_html_e( 'Status', 'cec' ); ?></label>
					<select name="cec_status">
						<option value="pending" <?php selected( $post->post_status, 'pending' ); ?>><?php esc_html_e( 'Pending Review (just submitted)', 'cec' ); ?></option>
						<option value="cec_in_review" <?php selected( $post->post_status, 'cec_in_review' ); ?>><?php esc_html_e( 'In Review (an editor is checking it)', 'cec' ); ?></option>
						<option value="publish" <?php selected( $post->post_status, 'publish' ); ?>><?php esc_html_e( 'Published (approved)', 'cec' ); ?></option>
						<option value="draft" <?php selected( $post->post_status, 'draft' ); ?>><?php esc_html_e( 'Draft (rejected / hidden)', 'cec' ); ?></option>
					</select>
				</div>
				<div class="cec-field">
					<label><?php esc_html_e( 'Event Status', 'cec' ); ?></label>
					<select name="cec_event_status">
						<option value="scheduled" <?php selected( $data['event_status'], 'scheduled' ); ?>><?php esc_html_e( 'Scheduled', 'cec' ); ?></option>
						<option value="postponed" <?php selected( $data['event_status'], 'postponed' ); ?>><?php esc_html_e( 'Postponed', 'cec' ); ?></option>
						<option value="cancelled" <?php selected( $data['event_status'], 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'cec' ); ?></option>
					</select>
					<input type="text" name="cec_event_status_note" value="<?php echo esc_attr( $data['event_status_note'] ); ?>" placeholder="<?php esc_attr_e( 'Optional note (new date, reason)', 'cec' ); ?>" style="margin-top:6px;">
				</div>
			</div>

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
			<div class="cec-field"><label><?php esc_html_e( 'Source Link (optional, editorial use)', 'cec' ); ?></label><input type="url" name="cec_source_url" value="<?php echo esc_attr( $data['source_url'] ); ?>"><p class="cec-field-hint"><?php esc_html_e( "Where this listing's details came from, if copied in from elsewhere — not shown to the public.", 'cec' ); ?></p></div>

			<?php if ( ! empty( $partner_orgs ) && ! is_wp_error( $partner_orgs ) ) : ?>
			<div class="cec-field">
				<label><?php esc_html_e( 'Partner Organizations & Titleholders', 'cec' ); ?></label>
				<?php foreach ( $partner_orgs as $term ) : $defaults = CEC_Term_Meta::partner_org_defaults( $term->term_id ); ?>
					<label class="cec-checkbox"><input type="checkbox" class="cec-org-autofill" name="cec_partner_org[]" value="<?php echo esc_attr( $term->term_id ); ?>" data-coc="<?php echo esc_attr( $defaults['coc'] ); ?>" data-rsvp-mode="<?php echo esc_attr( $defaults['rsvpMode'] ); ?>" data-rsvp-url="<?php echo esc_attr( $defaults['rsvpUrl'] ); ?>" <?php checked( in_array( $term->term_id, $my_partners, true ) ); ?>> <?php echo esc_html( $term->name ); ?></label>
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
				<div class="cec-field">
					<label><?php esc_html_e( "Don't see your venue? Save the custom address above as a reusable venue", 'cec' ); ?></label>
					<input type="text" name="cec_new_venue" placeholder="<?php esc_attr_e( 'Venue name', 'cec' ); ?>">
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
				<?php if ( get_post_meta( $event_id, '_cec_price_needs_review', true ) ) : ?>
					<p class="cec-field-hint" style="color:#d63638;"><?php esc_html_e( 'Flagged for price review — previously marked Free under the old checkbox. Confirm the price (or re-select "Free — confirmed") to clear this flag.', 'cec' ); ?></p>
				<?php endif; ?>
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
				<div class="cec-field"><label><?php esc_html_e( 'Price Source Link (optional)', 'cec' ); ?></label><input type="url" name="cec_price_source_url" value="<?php echo esc_attr( $data['price_source_url'] ); ?>"></div>
			</div>
			<div class="cec-field-row">
				<div class="cec-field">
					<label><?php esc_html_e( 'RSVP / Registration', 'cec' ); ?></label>
					<select name="cec_rsvp_mode" id="cec-dash-rsvp-mode">
						<option value="none" <?php selected( $data['rsvp_mode'], 'none' ); ?>><?php esc_html_e( 'No RSVP needed', 'cec' ); ?></option>
						<option value="internal" <?php selected( $data['rsvp_mode'], 'internal' ); ?>><?php esc_html_e( 'Collect RSVPs on this site', 'cec' ); ?></option>
						<option value="external" <?php selected( $data['rsvp_mode'], 'external' ); ?>><?php esc_html_e( 'Link to external registration', 'cec' ); ?></option>
					</select>
					<input type="url" name="cec_rsvp_url" id="cec-dash-rsvp-url" value="<?php echo esc_attr( $data['rsvp_url'] ); ?>" style="margin-top:6px;">
					<input type="number" name="cec_rsvp_capacity" min="0" placeholder="<?php esc_attr_e( 'Capacity (optional)', 'cec' ); ?>" value="<?php echo esc_attr( $data['rsvp_capacity'] ); ?>" style="margin-top:6px;">
				</div>
			</div>

			<div class="cec-field"><label><?php esc_html_e( 'Code of Conduct', 'cec' ); ?></label><textarea name="cec_code_of_conduct" id="cec-dash-coc" rows="2"><?php echo esc_textarea( $data['code_of_conduct'] ); ?></textarea></div>
			<p class="description"><?php esc_html_e( 'Checking a Partner Organization or Titleholder above fills in Code of Conduct / RSVP fields from that organization\'s saved defaults, but only the ones still empty — manage those defaults under Events > Partner Organizations.', 'cec' ); ?></p>

			<?php if ( ! CEC_Recurrence::is_occurrence( $event_id ) ) : ?>
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
				<p class="description"><?php esc_html_e( 'Changing repeat settings regenerates all upcoming dates and clears any individual postpone/cancel changes made via "Manage Dates".', 'cec' ); ?></p>
			<?php else : ?>
				<p class="description"><?php echo wp_kses_post( sprintf( __( 'This is one date in a recurring series. <a href="%s">Edit the series root</a> to change repeat settings for all dates.', 'cec' ), esc_url( add_query_arg( array( 'cec_view' => 'edit', 'cec_edit' => CEC_Recurrence::get_parent_id( $event_id ) ), get_permalink() ) ) ) ); ?></p>
			<?php endif; ?>

			<button type="submit" class="cec-btn"><?php esc_html_e( 'Save Event', 'cec' ); ?></button>
		</form>

		<h2><?php esc_html_e( 'Preview', 'cec' ); ?></h2>
		<?php echo CEC_Event_Helper::render_editor_preview( $event_id, 'dashboard', remove_query_arg( array( 'cec_view', 'cec_edit' ) ) ); // phpcs:ignore ?>
		<?php
		return ob_get_clean();
	}

	public static function handle_save_event() {
		if ( ! is_user_logged_in() || ! CEC_Roles::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cec' ) );
		}
		if ( ! isset( $_POST['cec_dash_nonce'] ) || ! wp_verify_nonce( $_POST['cec_dash_nonce'], self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'cec' ) );
		}

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$redirect = isset( $_POST['cec_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['cec_redirect'] ) ) : home_url();

		if ( ! $event_id || 'cec_event' !== get_post_type( $event_id ) ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		$status_map = array( 'pending' => 'pending', 'cec_in_review' => 'cec_in_review', 'publish' => 'publish', 'draft' => 'draft' );
		$new_status = isset( $_POST['cec_status'] ) && isset( $status_map[ $_POST['cec_status'] ] ) ? $status_map[ $_POST['cec_status'] ] : 'pending';

		wp_update_post(
			array(
				'ID'           => $event_id,
				'post_title'   => sanitize_text_field( wp_unslash( $_POST['cec_title'] ?? '' ) ),
				'post_excerpt' => sanitize_textarea_field( wp_unslash( $_POST['cec_excerpt'] ?? '' ) ),
				'post_content' => wp_kses_post( wp_unslash( $_POST['cec_description'] ?? '' ) ),
				'post_status'  => $new_status,
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

		$event_status = isset( $_POST['cec_event_status'] ) ? sanitize_key( $_POST['cec_event_status'] ) : 'scheduled';
		$event_status = in_array( $event_status, array( 'postponed', 'cancelled' ), true ) ? $event_status : 'scheduled';
		$old_status   = CEC_Event_Helper::event_status( $event_id );
		update_post_meta( $event_id, '_cec_event_status', $event_status );
		if ( $old_status !== $event_status ) {
			CEC_Audit_Log::log( $event_id, 'event_' . $event_status );
		}
		CEC_Subscribers::maybe_notify_status_change( $event_id, $old_status, $event_status );
		if ( isset( $_POST['cec_event_status_note'] ) ) {
			update_post_meta( $event_id, '_cec_event_status_note', sanitize_text_field( wp_unslash( $_POST['cec_event_status_note'] ) ) );
		}

		wp_set_post_terms( $event_id, ! empty( $_POST['cec_event_type'] ) ? array_map( 'absint', (array) $_POST['cec_event_type'] ) : array(), 'cec_event_type', false );

		$partner_org_ids = ! empty( $_POST['cec_partner_org'] ) ? array_map( 'absint', (array) $_POST['cec_partner_org'] ) : array();
		if ( ! empty( $_POST['cec_new_partner_org'] ) ) {
			$new_org_id = CEC_Term_Meta::get_or_create_partner_org( sanitize_text_field( wp_unslash( $_POST['cec_new_partner_org'] ) ) );
			if ( $new_org_id ) {
				$partner_org_ids[] = $new_org_id;
			}
		}
		wp_set_post_terms( $event_id, $partner_org_ids, 'cec_partner_org', false );

		$venue_id = ! empty( $_POST['cec_venue_term'] ) ? absint( $_POST['cec_venue_term'] ) : 0;
		if ( ! $venue_id && ! empty( $_POST['cec_new_venue'] ) ) {
			$venue_id = CEC_Term_Meta::get_or_create_venue(
				sanitize_text_field( wp_unslash( $_POST['cec_new_venue'] ) ),
				isset( $_POST['cec_venue_custom_address'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_venue_custom_address'] ) ) : '',
				isset( $_POST['cec_venue_custom_city'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_venue_custom_city'] ) ) : '',
				isset( $_POST['cec_venue_custom_region'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_venue_custom_region'] ) ) : '',
				isset( $_POST['cec_venue_custom_country'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_venue_custom_country'] ) ) : ''
			);
		}
		wp_set_post_terms( $event_id, $venue_id ? array( $venue_id ) : array(), 'cec_venue', false );

		$photo_result = CEC_Event_Helper::handle_photo_upload( $event_id );
		if ( is_wp_error( $photo_result ) ) {
			$redirect = add_query_arg( 'cec_photo_err', $photo_result->get_error_code(), $redirect );
		}

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

		wp_safe_redirect( add_query_arg( 'cec_msg', 'saved', $redirect ) );
		exit;
	}

	private static function render_terms_manager( $taxonomy, $label ) {
		$terms   = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		$is_venue = 'cec_venue' === $taxonomy;

		ob_start();
		?>
		<h2><?php echo esc_html( $label ); ?></h2>
		<table class="cec-dash-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'cec' ); ?></th>
					<th><?php echo $is_venue ? esc_html__( 'Address / Maps Link', 'cec' ) : esc_html__( 'Website', 'cec' ); ?></th>
					<?php if ( $is_venue ) : ?><th><?php esc_html_e( 'City / Region / Country', 'cec' ); ?></th><?php endif; ?>
					<th><?php esc_html_e( 'Events', 'cec' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'cec' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $terms as $term ) : ?>
					<tr>
						<td><?php echo esc_html( $term->name ); ?></td>
						<td><?php echo esc_html( $is_venue ? get_term_meta( $term->term_id, 'cec_address', true ) : get_term_meta( $term->term_id, 'cec_url', true ) ); ?></td>
						<?php if ( $is_venue ) : ?>
							<td><?php echo esc_html( implode( ', ', array_filter( array( get_term_meta( $term->term_id, 'cec_city', true ), get_term_meta( $term->term_id, 'cec_region', true ), get_term_meta( $term->term_id, 'cec_country', true ) ) ) ) ); ?></td>
						<?php endif; ?>
						<td><?php echo (int) $term->count; ?></td>
						<td>
							<a class="cec-btn cec-btn-small cec-btn-danger" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'cec_dashboard_term_delete', 'taxonomy' => $taxonomy, 'term_id' => $term->term_id, 'cec_redirect' => rawurlencode( remove_query_arg( array() ) ) ), admin_url( 'admin-post.php' ) ), self::TERM_NONCE ) ); ?>" onclick="return confirm('<?php esc_attr_e( 'Remove this? Existing events keep their history but will lose this tag.', 'cec' ); ?>');"><?php esc_html_e( 'Remove', 'cec' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Add New', 'cec' ); ?></h3>
		<form class="cec-submit-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cec_dashboard_term_add" />
			<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>" />
			<input type="hidden" name="cec_redirect" value="<?php echo esc_url( remove_query_arg( array() ) ); ?>" />
			<?php wp_nonce_field( self::TERM_NONCE, 'cec_term_nonce' ); ?>
			<div class="cec-field-row">
				<div class="cec-field"><label><?php esc_html_e( 'Name *', 'cec' ); ?></label><input type="text" name="term_name" required></div>
				<?php if ( $is_venue ) : ?>
					<div class="cec-field"><label><?php esc_html_e( 'Address', 'cec' ); ?></label><input type="text" name="term_address"></div>
				<?php else : ?>
					<div class="cec-field"><label><?php esc_html_e( 'Website', 'cec' ); ?></label><input type="url" name="term_url" placeholder="https://"></div>
				<?php endif; ?>
			</div>
			<?php if ( $is_venue ) : ?>
			<div class="cec-field-row">
				<div class="cec-field"><label><?php esc_html_e( 'City', 'cec' ); ?></label><input type="text" name="term_city"></div>
				<div class="cec-field"><label><?php esc_html_e( 'State / Region', 'cec' ); ?></label><input type="text" name="term_region"></div>
				<div class="cec-field"><label><?php esc_html_e( 'Country', 'cec' ); ?></label><input type="text" name="term_country"></div>
			</div>
			<p class="cec-field-hint"><?php esc_html_e( 'Timezone can be set afterward from the wp-admin Venues screen.', 'cec' ); ?></p>
			<?php endif; ?>
			<button type="submit" class="cec-btn"><?php esc_html_e( 'Add', 'cec' ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function handle_term_add() {
		if ( ! is_user_logged_in() || ! CEC_Roles::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cec' ) );
		}
		if ( ! isset( $_POST['cec_term_nonce'] ) || ! wp_verify_nonce( $_POST['cec_term_nonce'], self::TERM_NONCE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'cec' ) );
		}

		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$redirect = isset( $_POST['cec_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['cec_redirect'] ) ) : home_url();

		if ( ! in_array( $taxonomy, array( 'cec_partner_org', 'cec_venue' ), true ) ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		$name = isset( $_POST['term_name'] ) ? sanitize_text_field( wp_unslash( $_POST['term_name'] ) ) : '';
		if ( empty( $name ) ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		$result = wp_insert_term( $name, $taxonomy );
		if ( ! is_wp_error( $result ) ) {
			if ( 'cec_venue' === $taxonomy ) {
				foreach ( array( 'term_address' => 'cec_address', 'term_city' => 'cec_city', 'term_region' => 'cec_region', 'term_country' => 'cec_country' ) as $post_key => $meta_key ) {
					if ( isset( $_POST[ $post_key ] ) ) {
						update_term_meta( $result['term_id'], $meta_key, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
					}
				}
			}
			if ( 'cec_partner_org' === $taxonomy && isset( $_POST['term_url'] ) ) {
				update_term_meta( $result['term_id'], 'cec_url', esc_url_raw( wp_unslash( $_POST['term_url'] ) ) );
			}
		}

		wp_safe_redirect( add_query_arg( 'cec_msg', 'term_added', $redirect ) );
		exit;
	}

	public static function handle_term_delete() {
		if ( ! is_user_logged_in() || ! CEC_Roles::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cec' ) );
		}
		check_admin_referer( self::TERM_NONCE );

		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
		$term_id  = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;
		$redirect = isset( $_GET['cec_redirect'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['cec_redirect'] ) ) ) : home_url();

		if ( in_array( $taxonomy, array( 'cec_partner_org', 'cec_venue' ), true ) && $term_id ) {
			wp_delete_term( $term_id, $taxonomy );
		}

		wp_safe_redirect( add_query_arg( 'cec_msg', 'term_deleted', $redirect ) );
		exit;
	}

	private static function render_import() {
		ob_start();
		?>
		<h2><?php esc_html_e( 'Import Events from a Calendar File', 'cec' ); ?></h2>
		<p><?php esc_html_e( 'Upload a .ics calendar file (exported from Google Calendar, Outlook, Apple Calendar, Eventbrite, or similar) to create events here automatically. Every imported event is published immediately and fully editable afterward, exactly like one entered by hand.', 'cec' ); ?></p>
		<p>
			<?php esc_html_e( "A couple of things to know before uploading:", 'cec' ); ?>
		</p>
		<ul class="cec-import-notes">
			<li><?php esc_html_e( "The event's location is copied in as plain text, not matched to one of your existing Venues — open the event afterward if you'd like to link it to a real Venue.", 'cec' ); ?></li>
			<li><?php esc_html_e( "If the source file includes a website link for the event, it's picked up automatically as the event's More Info link.", 'cec' ); ?></li>
			<li><?php esc_html_e( "Calendar files don't carry pricing — every imported event is marked Price Not Posted rather than guessed as Free or Paid. Update the Cost field on each one afterward.", 'cec' ); ?></li>
			<li><?php esc_html_e( "If the source file marks an event as repeating, only its first date is imported — set recurrence here manually if it should repeat.", 'cec' ); ?></li>
		</ul>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cec-submit-form">
			<input type="hidden" name="action" value="cec_dashboard_import_ics">
			<?php wp_nonce_field( self::IMPORT_NONCE, 'cec_dash_nonce' ); ?>
			<input type="hidden" name="cec_redirect" value="<?php echo esc_url( get_permalink() ); ?>">
			<div class="cec-field">
				<label for="cec_ics_file"><?php esc_html_e( '.ics file', 'cec' ); ?></label>
				<input type="file" id="cec_ics_file" name="cec_ics_file" accept=".ics,text/calendar" required>
			</div>
			<div class="cec-field">
				<label class="cec-checkbox">
					<input type="checkbox" name="cec_ics_update_existing" value="1">
					<?php esc_html_e( 'Update previously-imported events instead of creating duplicates', 'cec' ); ?>
				</label>
				<p class="cec-field-hint"><?php esc_html_e( "Matches an event in this file against one already imported before, using the file's own event ID — useful for re-uploading the same subscribed calendar after it's changed. Only each event's date/time, location, organizer, and More Info link are refreshed; anything you've set manually since (Cost, a linked Venue, postponed/cancelled status) is left alone. Leave this unchecked to always create fresh events instead.", 'cec' ); ?></p>
			</div>
			<button type="submit" class="cec-btn"><?php esc_html_e( 'Import Events', 'cec' ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function handle_import_ics() {
		if ( ! is_user_logged_in() || ! CEC_Roles::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cec' ) );
		}
		if ( ! isset( $_POST['cec_dash_nonce'] ) || ! wp_verify_nonce( $_POST['cec_dash_nonce'], self::IMPORT_NONCE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'cec' ) );
		}

		$redirect = isset( $_POST['cec_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['cec_redirect'] ) ) : home_url();

		if ( empty( $_FILES['cec_ics_file']['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES['cec_ics_file']['error'] ) {
			wp_safe_redirect( add_query_arg( 'cec_msg', 'import_failed', $redirect ) );
			exit;
		}

		// Not routed through media_handle_upload() on purpose — this is a
		// transient data file to parse and discard, not a real media
		// library asset anyone should be able to browse to later.
		$original_name = isset( $_FILES['cec_ics_file']['name'] ) ? wp_unslash( $_FILES['cec_ics_file']['name'] ) : '';
		$ext           = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( 'ics' !== $ext ) {
			wp_safe_redirect( add_query_arg( 'cec_msg', 'import_failed', $redirect ) );
			exit;
		}

		$raw = file_get_contents( $_FILES['cec_ics_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			wp_safe_redirect( add_query_arg( 'cec_msg', 'import_failed', $redirect ) );
			exit;
		}

		$update_existing = ! empty( $_POST['cec_ics_update_existing'] );
		$result          = CEC_Ics_Import::import( $raw, get_current_user_id(), $update_existing );

		$token = wp_generate_password( 12, false, false );
		set_transient( 'cec_ics_import_' . $token, $result, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'cec_msg'          => 'imported',
					'cec_import_token' => $token,
				),
				$redirect
			)
		);
		exit;
	}
}
