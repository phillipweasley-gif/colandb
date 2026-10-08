<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_Meta_Boxes {

	const NONCE_ACTION = 'cec_save_event_meta';
	const NONCE_NAME   = 'cec_event_meta_nonce';

	public static function add() {
		add_meta_box( 'cec_event_details', __( 'Event Details', 'cec' ), array( __CLASS__, 'render' ), 'cec_event', 'normal', 'high' );
		add_meta_box( 'cec_event_approval', __( 'Approval', 'cec' ), array( __CLASS__, 'render_approval' ), 'cec_event', 'side', 'high' );
		add_meta_box( 'cec_event_preview', __( 'Event Preview', 'cec' ), array( __CLASS__, 'render_preview' ), 'cec_event', 'normal', 'low' );
	}

	/**
	 * Phase 1d: lets an approver see exactly how this event will look (list
	 * card, full detail page, month calendar) — and whether it might be a
	 * duplicate of something already entered — before publishing it, not
	 * just its raw field values. Skipped on a brand-new, unsaved post:
	 * there's nothing real to preview yet, and $post->ID would be the
	 * "auto-draft" placeholder, not a real event.
	 */
	public static function render_preview( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save this event once to see its preview here.', 'cec' ) . '</p>';
			return;
		}
		echo CEC_Event_Helper::render_editor_preview( $post->ID, 'admin' ); // phpcs:ignore
	}

	private static function meta( $post_id, $key, $default = '' ) {
		$val = get_post_meta( $post_id, $key, true );
		return '' === $val ? $default : $val;
	}

	public static function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$m = function ( $key, $default = '' ) use ( $post ) {
			return self::meta( $post->ID, $key, $default );
		};
		$rsvp_mode     = $m( '_cec_rsvp_mode', 'external' );
		$venue_custom  = $m( '_cec_venue_custom_address' );
		$admission     = $m( '_cec_admission_status', 'not_posted' );
		$location_mode = $m( '_cec_location_mode', 'in_person' );
		?>
		<style>.cec-mb-row{margin-bottom:16px;}.cec-mb-row label{display:block;font-weight:600;margin-bottom:4px;}.cec-mb-row input[type=text],.cec-mb-row input[type=url],.cec-mb-row input[type=number],.cec-mb-row input[type=datetime-local],.cec-mb-row select,.cec-mb-row textarea{width:100%;}.cec-mb-cols{display:flex;gap:16px;}.cec-mb-cols>div{flex:1;}</style>

		<h3><?php esc_html_e( 'Host Organization', 'cec' ); ?></h3>
		<div class="cec-mb-cols">
			<div class="cec-mb-row">
				<label><?php esc_html_e( 'Host Organization Name', 'cec' ); ?></label>
				<input type="text" name="cec_host_org_name" value="<?php echo esc_attr( $m( '_cec_host_org_name' ) ); ?>" />
			</div>
			<div class="cec-mb-row">
				<label><?php esc_html_e( 'Host Organization Website', 'cec' ); ?></label>
				<input type="url" name="cec_host_org_url" placeholder="https://" value="<?php echo esc_attr( $m( '_cec_host_org_url' ) ); ?>" />
			</div>
		</div>
		<div class="cec-mb-cols">
			<div class="cec-mb-row">
				<label><?php esc_html_e( 'Official Event Website', 'cec' ); ?></label>
				<input type="url" name="cec_official_website_url" placeholder="https://" value="<?php echo esc_attr( $m( '_cec_official_website_url' ) ); ?>" />
				<p class="description"><?php esc_html_e( "This event's own permanent website, if it has one distinct from the host organization's site — e.g. a yearly festival's own domain.", 'cec' ); ?></p>
			</div>
			<div class="cec-mb-row">
				<label><?php esc_html_e( 'More Info Link', 'cec' ); ?></label>
				<input type="url" name="cec_more_info_url" placeholder="https://" value="<?php echo esc_attr( $m( '_cec_more_info_url' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'A link shown on the event page for anyone who wants more details — a dedicated event listing, flyer, or news article, for instance.', 'cec' ); ?></p>
			</div>
		</div>
		<div class="cec-mb-row">
			<label><?php esc_html_e( 'Event Host(s) / Organizer(s)', 'cec' ); ?></label>
			<input type="text" name="cec_organizers" placeholder="<?php esc_attr_e( 'e.g. Jane Doe, Parks & Rec Volunteer Team', 'cec' ); ?>" value="<?php echo esc_attr( $m( '_cec_organizers' ) ); ?>" />
			<p class="description"><?php esc_html_e( 'The individual(s) actually running this event.', 'cec' ); ?></p>
		</div>
		<div class="cec-mb-row">
			<label><?php esc_html_e( 'Source Link (optional, editorial use)', 'cec' ); ?></label>
			<input type="url" name="cec_source_url" placeholder="https://" value="<?php echo esc_attr( $m( '_cec_source_url' ) ); ?>" />
			<p class="description"><?php esc_html_e( "Where this listing's details were originally found, if copied in from elsewhere (a partner's site, a press release) — not shown to the public.", 'cec' ); ?></p>
		</div>

		<h3><?php esc_html_e( 'Where', 'cec' ); ?></h3>
		<div class="cec-mb-row">
			<label><?php esc_html_e( 'Location Type', 'cec' ); ?></label>
			<select name="cec_location_mode" class="cec-location-mode-select">
				<option value="in_person" <?php selected( $location_mode, 'in_person' ); ?>><?php esc_html_e( 'In Person', 'cec' ); ?></option>
				<option value="online" <?php selected( $location_mode, 'online' ); ?>><?php esc_html_e( 'Online', 'cec' ); ?></option>
				<option value="hybrid" <?php selected( $location_mode, 'hybrid' ); ?>><?php esc_html_e( 'Hybrid (In Person & Online)', 'cec' ); ?></option>
				<option value="not_posted" <?php selected( $location_mode, 'not_posted' ); ?>><?php esc_html_e( 'Not posted yet', 'cec' ); ?></option>
			</select>
		</div>
		<div class="cec-mb-row cec-location-online-fields">
			<label><?php esc_html_e( 'Online Access Link', 'cec' ); ?></label>
			<input type="url" name="cec_online_url" placeholder="https://" value="<?php echo esc_attr( $m( '_cec_online_url' ) ); ?>" />
		</div>
		<div class="cec-location-in-person-fields">
			<div class="cec-mb-row">
				<label><?php esc_html_e( 'Venue', 'cec' ); ?></label>
				<?php
				wp_dropdown_categories(
					array(
						'taxonomy'         => 'cec_venue',
						'name'             => 'cec_venue_term',
						'selected'         => wp_get_post_terms( $post->ID, 'cec_venue', array( 'fields' => 'ids' ) )[0] ?? 0,
						'show_option_none' => __( '— Custom address below —', 'cec' ),
						'hide_empty'       => false,
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Pick a saved venue (managed under Events > Venues) or leave as Custom and enter a one-off address.', 'cec' ); ?></p>
			</div>
			<div class="cec-mb-cols">
				<div class="cec-mb-row">
					<label><?php esc_html_e( 'Custom Address (if not using a saved venue)', 'cec' ); ?></label>
					<input type="text" name="cec_venue_custom_address" value="<?php echo esc_attr( $venue_custom ); ?>" />
				</div>
				<div class="cec-mb-row">
					<label><?php esc_html_e( 'Custom Google Maps Link (optional)', 'cec' ); ?></label>
					<input type="url" name="cec_venue_custom_maps_url" placeholder="https://maps.google.com/..." value="<?php echo esc_attr( $m( '_cec_venue_custom_maps_url' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Leave blank to auto-generate a map link from the address above.', 'cec' ); ?></p>
				</div>
			</div>
			<div class="cec-mb-cols">
				<div class="cec-mb-row">
					<label><?php esc_html_e( 'Custom City', 'cec' ); ?></label>
					<input type="text" name="cec_venue_custom_city" value="<?php echo esc_attr( $m( '_cec_venue_custom_city' ) ); ?>" />
				</div>
				<div class="cec-mb-row">
					<label><?php esc_html_e( 'Custom State / Region', 'cec' ); ?></label>
					<input type="text" name="cec_venue_custom_region" value="<?php echo esc_attr( $m( '_cec_venue_custom_region' ) ); ?>" />
				</div>
				<div class="cec-mb-row">
					<label><?php esc_html_e( 'Custom Country', 'cec' ); ?></label>
					<input type="text" name="cec_venue_custom_country" value="<?php echo esc_attr( $m( '_cec_venue_custom_country' ) ); ?>" />
				</div>
			</div>
			<div class="cec-mb-row">
				<label><?php esc_html_e( "Save the custom address above as a reusable venue", 'cec' ); ?></label>
				<input type="text" name="cec_new_venue" placeholder="<?php esc_attr_e( 'Venue name (leave blank to skip)', 'cec' ); ?>" />
			</div>
		</div>

		<h3><?php esc_html_e( 'Partner Organizations & Titleholders', 'cec' ); ?></h3>
		<div class="cec-mb-row">
			<p class="description"><?php esc_html_e( 'Checked in the Partner Organizations & Titleholders box in the sidebar. Checking one fills in Code of Conduct / RSVP fields below from that organization\'s saved defaults, but only the ones still empty — manage defaults under Events > Partner Organizations.', 'cec' ); ?></p>
			<label><?php esc_html_e( "Don't see your organization or titleholder? Add it", 'cec' ); ?></label>
			<input type="text" name="cec_new_partner_org" placeholder="<?php esc_attr_e( 'Organization or titleholder name (leave blank to skip)', 'cec' ); ?>" />
		</div>

		<h3><?php esc_html_e( 'When', 'cec' ); ?></h3>
		<div class="cec-mb-cols">
			<div class="cec-mb-row">
				<label><?php esc_html_e( 'Start', 'cec' ); ?></label>
				<input type="datetime-local" name="cec_start" value="<?php echo esc_attr( $m( '_cec_start' ) ); ?>" required />
			</div>
			<div class="cec-mb-row">
				<label><?php esc_html_e( 'End', 'cec' ); ?></label>
				<input type="datetime-local" name="cec_end" value="<?php echo esc_attr( $m( '_cec_end' ) ); ?>" />
			</div>
		</div>
		<div class="cec-mb-cols">
			<div class="cec-mb-row">
				<label><?php esc_html_e( 'Time Details', 'cec' ); ?></label>
				<select name="cec_time_mode">
					<option value="exact" <?php selected( $m( '_cec_time_mode', 'exact' ), 'exact' ); ?>><?php esc_html_e( 'Exact start/end time', 'cec' ); ?></option>
					<option value="start_only" <?php selected( $m( '_cec_time_mode', 'exact' ), 'start_only' ); ?>><?php esc_html_e( 'Start time only (no set end)', 'cec' ); ?></option>
					<option value="all_day" <?php selected( $m( '_cec_time_mode', 'exact' ), 'all_day' ); ?>><?php esc_html_e( 'All day (no specific time)', 'cec' ); ?></option>
					<option value="varies" <?php selected( $m( '_cec_time_mode', 'exact' ), 'varies' ); ?>><?php esc_html_e( 'Schedule varies', 'cec' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Controls how the time is shown publicly — the Start/End fields above are still used for sorting and calendar placement either way.', 'cec' ); ?></p>
			</div>
			<div class="cec-mb-row">
				<label><?php esc_html_e( 'Timezone', 'cec' ); ?></label>
				<?php echo CEC_Event_Helper::timezone_select_html( 'cec_timezone', $m( '_cec_timezone' ) ); // phpcs:ignore ?>
			</div>
		</div>

		<h3><?php esc_html_e( 'Cost & Registration', 'cec' ); ?></h3>
		<div class="cec-mb-row">
			<label><?php esc_html_e( 'Admission', 'cec' ); ?></label>
			<select name="cec_admission_status" class="cec-admission-status-select">
				<option value="not_posted" <?php selected( $admission, 'not_posted' ); ?>><?php esc_html_e( 'Price not posted', 'cec' ); ?></option>
				<option value="free_confirmed" <?php selected( $admission, 'free_confirmed' ); ?>><?php esc_html_e( 'Free — confirmed', 'cec' ); ?></option>
				<option value="paid" <?php selected( $admission, 'paid' ); ?>><?php esc_html_e( 'Paid', 'cec' ); ?></option>
				<option value="price_varies" <?php selected( $admission, 'price_varies' ); ?>><?php esc_html_e( 'Price varies', 'cec' ); ?></option>
			</select>
		</div>
		<div class="cec-mb-cols cec-admission-paid-fields">
			<div class="cec-mb-row">
				<label><?php esc_html_e( 'Price', 'cec' ); ?></label>
				<input type="number" step="0.01" min="0" name="cec_price_amount" value="<?php echo esc_attr( $m( '_cec_price_amount' ) ); ?>" />
			</div>
			<div class="cec-mb-row">
				<label><?php esc_html_e( 'Currency', 'cec' ); ?></label>
				<input type="text" name="cec_price_currency" maxlength="3" value="<?php echo esc_attr( $m( '_cec_price_currency', 'USD' ) ); ?>" />
			</div>
		</div>
		<div class="cec-mb-cols cec-admission-varies-fields">
			<div class="cec-mb-row">
				<label><?php esc_html_e( 'Price From', 'cec' ); ?></label>
				<input type="number" step="0.01" min="0" name="cec_price_min" value="<?php echo esc_attr( $m( '_cec_price_min' ) ); ?>" />
			</div>
			<div class="cec-mb-row">
				<label><?php esc_html_e( 'Price To', 'cec' ); ?></label>
				<input type="number" step="0.01" min="0" name="cec_price_max" value="<?php echo esc_attr( $m( '_cec_price_max' ) ); ?>" />
			</div>
		</div>
		<div class="cec-mb-cols">
			<div class="cec-mb-row">
				<label><?php esc_html_e( 'Price Note (optional)', 'cec' ); ?></label>
				<input type="text" name="cec_price_note" placeholder="<?php esc_attr_e( 'e.g. suggested donation, cash only at the door', 'cec' ); ?>" value="<?php echo esc_attr( $m( '_cec_price_note' ) ); ?>" />
			</div>
			<div class="cec-mb-row">
				<label><?php esc_html_e( 'Price Source Link (optional)', 'cec' ); ?></label>
				<input type="url" name="cec_price_source_url" placeholder="https://" value="<?php echo esc_attr( $m( '_cec_price_source_url' ) ); ?>" />
			</div>
		</div>
		<?php if ( $m( '_cec_price_needs_review' ) ) : ?>
			<p class="description" style="color:#d63638;"><?php esc_html_e( 'Flagged for price review: this event was previously marked Free under the old checkbox, which could default to checked by mistake. Confirm the price above (or re-select "Free — confirmed") to clear this flag.', 'cec' ); ?></p>
		<?php endif; ?>
		<div class="cec-mb-row">
			<label><?php esc_html_e( 'RSVP / Registration', 'cec' ); ?></label>
			<select name="cec_rsvp_mode" id="cec_rsvp_mode">
				<option value="none" <?php selected( $rsvp_mode, 'none' ); ?>><?php esc_html_e( 'No RSVP needed', 'cec' ); ?></option>
				<option value="internal" <?php selected( $rsvp_mode, 'internal' ); ?>><?php esc_html_e( 'Collect RSVPs on this site', 'cec' ); ?></option>
				<option value="external" <?php selected( $rsvp_mode, 'external' ); ?>><?php esc_html_e( 'Link to external registration', 'cec' ); ?></option>
			</select>
			<input type="url" name="cec_rsvp_url" id="cec_rsvp_url" placeholder="https://" value="<?php echo esc_attr( $m( '_cec_rsvp_url' ) ); ?>" style="margin-top:6px;" />
			<input type="number" name="cec_rsvp_capacity" min="0" placeholder="<?php esc_attr_e( 'Capacity (optional, internal RSVP only)', 'cec' ); ?>" value="<?php echo esc_attr( $m( '_cec_rsvp_capacity' ) ); ?>" style="margin-top:6px;" />
		</div>

		<h3><?php esc_html_e( 'Code of Conduct', 'cec' ); ?></h3>
		<div class="cec-mb-row">
			<textarea name="cec_code_of_conduct" id="cec_code_of_conduct" rows="3" placeholder="<?php esc_attr_e( 'Paste code of conduct text, or a link to it', 'cec' ); ?>"><?php echo esc_textarea( $m( '_cec_code_of_conduct' ) ); ?></textarea>
		</div>

		<h3><?php esc_html_e( 'Repeats', 'cec' ); ?></h3>
		<?php if ( CEC_Recurrence::is_occurrence( $post->ID ) ) : ?>
			<p class="description"><?php echo wp_kses_post( sprintf( __( 'This is one date in a recurring series. To change how it repeats, edit <a href="%s">the series root event</a> — that regenerates every upcoming date.', 'cec' ), esc_url( get_edit_post_link( CEC_Recurrence::get_parent_id( $post->ID ) ) ) ) ); ?></p>
		<?php else : ?>
			<div class="cec-mb-cols">
				<div class="cec-mb-row">
					<label><?php esc_html_e( 'Repeats', 'cec' ); ?></label>
					<select name="cec_recurrence_rule">
						<option value="none" <?php selected( $m( '_cec_recurrence_rule', 'none' ), 'none' ); ?>><?php esc_html_e( 'Does not repeat', 'cec' ); ?></option>
						<option value="weekly" <?php selected( $m( '_cec_recurrence_rule' ), 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'cec' ); ?></option>
						<option value="monthly_date" <?php selected( $m( '_cec_recurrence_rule' ), 'monthly_date' ); ?>><?php esc_html_e( 'Monthly (same date)', 'cec' ); ?></option>
						<option value="monthly_weekday" <?php selected( $m( '_cec_recurrence_rule' ), 'monthly_weekday' ); ?>><?php esc_html_e( 'Monthly (same weekday, e.g. 2nd Tuesday)', 'cec' ); ?></option>
					</select>
				</div>
				<div class="cec-mb-row">
					<label><?php esc_html_e( 'Repeat Until', 'cec' ); ?></label>
					<input type="date" name="cec_recurrence_until" value="<?php echo esc_attr( $m( '_cec_recurrence_until' ) ); ?>" />
				</div>
			</div>
			<?php
			$start_ts    = $m( '_cec_start' ) ? strtotime( $m( '_cec_start' ) ) : time();
			$my_ordinals = CEC_Recurrence::get_ordinals( $post->ID, $start_ts );
			$my_weekdays = CEC_Recurrence::get_weekdays( $post->ID, $start_ts );
			echo CEC_Recurrence::render_monthly_weekday_fields( $my_ordinals, $my_weekdays ); // phpcs:ignore
			?>
			<p class="description"><?php esc_html_e( 'Saving with a repeat rule generates each future date as its own event (capped at ~2 years out). Editing this event again regenerates all upcoming dates — use "Manage Dates" from the front-end dashboard to postpone/cancel a single date instead.', 'cec' ); ?></p>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Event Status', 'cec' ); ?></h3>
		<div class="cec-mb-row">
			<select name="cec_event_status">
				<option value="scheduled" <?php selected( $m( '_cec_event_status', 'scheduled' ), 'scheduled' ); ?>><?php esc_html_e( 'Scheduled', 'cec' ); ?></option>
				<option value="postponed" <?php selected( $m( '_cec_event_status' ), 'postponed' ); ?>><?php esc_html_e( 'Postponed', 'cec' ); ?></option>
				<option value="cancelled" <?php selected( $m( '_cec_event_status' ), 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'cec' ); ?></option>
			</select>
			<input type="text" name="cec_event_status_note" placeholder="<?php esc_attr_e( 'Optional note, e.g. new date TBD or reason', 'cec' ); ?>" value="<?php echo esc_attr( $m( '_cec_event_status_note' ) ); ?>" style="margin-top:6px;" />
		</div>

		<p class="description"><?php esc_html_e( 'Short description: use the Excerpt box below (shown collapsed in calendar/list views). Full description: use the main content editor above (shown when a visitor expands the event).', 'cec' ); ?></p>
		<?php
	}

	public static function render_approval( $post ) {
		$status = $post->post_status;
		echo '<p><strong>' . esc_html__( 'Status:', 'cec' ) . '</strong> ' . esc_html( get_post_status_object( $status )->label ) . '</p>';
		if ( 'pending' === $status ) {
			echo '<p>' . esc_html__( 'This event was submitted and is awaiting review. Publish it to make it live on the calendar.', 'cec' ) . '</p>';
		}
		$submitter = get_the_author_meta( 'display_name', $post->post_author );
		echo '<p><strong>' . esc_html__( 'Submitted by:', 'cec' ) . '</strong> ' . esc_html( $submitter ) . '</p>';

		$entries = CEC_Audit_Log::entries_for_event( $post->ID );
		if ( ! empty( $entries ) ) {
			echo '<p><strong>' . esc_html__( 'Recent Activity:', 'cec' ) . '</strong></p><ul style="margin-top:0;">';
			foreach ( $entries as $entry ) {
				printf(
					'<li>%1$s%2$s — %3$s <span style="color:#777;">(%4$s)</span></li>',
					esc_html( CEC_Audit_Log::action_label( $entry->action ) ),
					$entry->detail ? ': ' . esc_html( $entry->detail ) : '',
					esc_html( CEC_Audit_Log::actor_label( $entry->user_id ) ),
					esc_html( date_i18n( 'M j, Y g:i a', strtotime( $entry->created_at ) ) )
				);
			}
			echo '</ul>';
		}
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( $_POST[ self::NONCE_NAME ], self::NONCE_ACTION ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			'cec_host_org_name'          => 'sanitize_text_field',
			'cec_host_org_url'           => 'esc_url_raw',
			'cec_more_info_url'          => 'esc_url_raw',
			'cec_organizers'             => 'sanitize_text_field',
			'cec_venue_custom_address'   => 'sanitize_text_field',
			'cec_venue_custom_maps_url'  => 'esc_url_raw',
			'cec_start'                  => 'sanitize_text_field',
			'cec_end'                    => 'sanitize_text_field',
			'cec_price_note'             => 'sanitize_text_field',
			'cec_rsvp_mode'              => 'sanitize_text_field',
			'cec_rsvp_url'               => 'esc_url_raw',
			'cec_rsvp_capacity'          => 'absint',
			'cec_code_of_conduct'        => 'sanitize_textarea_field',
			'cec_event_status_note'      => 'sanitize_text_field',
		);

		foreach ( $fields as $field => $sanitizer ) {
			if ( isset( $_POST[ $field ] ) ) {
				$value = call_user_func( $sanitizer, wp_unslash( $_POST[ $field ] ) );
				update_post_meta( $post_id, '_' . $field, $value );
			}
		}

		CEC_Event_Helper::save_phase1a_fields( $post_id );

		$event_status = isset( $_POST['cec_event_status'] ) ? sanitize_key( $_POST['cec_event_status'] ) : 'scheduled';
		$event_status = in_array( $event_status, array( 'postponed', 'cancelled' ), true ) ? $event_status : 'scheduled';
		$old_status   = CEC_Event_Helper::event_status( $post_id );
		update_post_meta( $post_id, '_cec_event_status', $event_status );
		if ( $old_status !== $event_status ) {
			CEC_Audit_Log::log( $post_id, 'event_' . $event_status );
		}
		CEC_Subscribers::maybe_notify_status_change( $post_id, $old_status, $event_status );

		if ( ! empty( $_POST['cec_new_partner_org'] ) ) {
			$new_org_id = CEC_Term_Meta::get_or_create_partner_org( sanitize_text_field( wp_unslash( $_POST['cec_new_partner_org'] ) ) );
			if ( $new_org_id ) {
				wp_set_post_terms( $post_id, array( $new_org_id ), 'cec_partner_org', true ); // append — WP's native taxonomy box already saved whatever was checked.
			}
		}

		$venue_id = isset( $_POST['cec_venue_term'] ) ? absint( $_POST['cec_venue_term'] ) : 0;
		if ( ! $venue_id && ! empty( $_POST['cec_new_venue'] ) ) {
			$venue_id = CEC_Term_Meta::get_or_create_venue(
				sanitize_text_field( wp_unslash( $_POST['cec_new_venue'] ) ),
				isset( $_POST['cec_venue_custom_address'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_venue_custom_address'] ) ) : '',
				isset( $_POST['cec_venue_custom_city'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_venue_custom_city'] ) ) : '',
				isset( $_POST['cec_venue_custom_region'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_venue_custom_region'] ) ) : '',
				isset( $_POST['cec_venue_custom_country'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_venue_custom_country'] ) ) : ''
			);
		}
		if ( $venue_id ) {
			wp_set_post_terms( $post_id, array( $venue_id ), 'cec_venue', false );
		} else {
			wp_set_post_terms( $post_id, array(), 'cec_venue', false );
		}

		if ( ! CEC_Recurrence::is_occurrence( $post_id ) ) {
			$rule = isset( $_POST['cec_recurrence_rule'] ) ? sanitize_key( $_POST['cec_recurrence_rule'] ) : 'none';
			update_post_meta( $post_id, '_cec_recurrence_rule', $rule );
			if ( isset( $_POST['cec_recurrence_until'] ) ) {
				update_post_meta( $post_id, '_cec_recurrence_until', sanitize_text_field( wp_unslash( $_POST['cec_recurrence_until'] ) ) );
			}
			if ( 'monthly_weekday' === $rule ) {
				list( $ordinals, $weekdays ) = CEC_Recurrence::sanitize_posted_ordinals_weekdays();
				update_post_meta( $post_id, '_cec_recurrence_ordinals', implode( ',', $ordinals ) );
				update_post_meta( $post_id, '_cec_recurrence_weekdays', implode( ',', $weekdays ) );
			}
			CEC_Recurrence::update_series( $post_id );
		}
	}
}
