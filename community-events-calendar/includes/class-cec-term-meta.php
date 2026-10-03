<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds "website URL" and "logo" meta to Partner Organization and Venue terms,
 * so partner org links and venue map links can be managed centrally and
 * amended at any time from Events > Partner Organizations / Venues.
 */
class CEC_Term_Meta {

	public static function init() {
		foreach ( array( 'cec_partner_org', 'cec_venue' ) as $tax ) {
			add_action( "{$tax}_add_form_fields", array( __CLASS__, 'add_fields' ) );
			add_action( "{$tax}_edit_form_fields", array( __CLASS__, 'edit_fields' ) );
			add_action( "created_{$tax}", array( __CLASS__, 'save_fields' ) );
			add_action( "edited_{$tax}", array( __CLASS__, 'save_fields' ) );
		}
	}

	public static function add_fields( $taxonomy ) {
		$is_venue = 'cec_venue' === $taxonomy;
		?>
		<div class="form-field">
			<label for="cec_term_url"><?php echo $is_venue ? esc_html__( 'Google Maps Link', 'cec' ) : esc_html__( 'Organization Website', 'cec' ); ?></label>
			<input type="url" name="cec_term_url" id="cec_term_url" value="" placeholder="https://" />
			<?php if ( $is_venue ) : ?>
				<p><?php esc_html_e( 'Paste a Google Maps share link, or fill in the address below and leave this blank to auto-generate one.', 'cec' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Used to make this organization\'s name a clickable link wherever it appears.', 'cec' ); ?></p>
			<?php endif; ?>
		</div>
		<?php if ( $is_venue ) : ?>
		<div class="form-field">
			<label for="cec_term_address"><?php esc_html_e( 'Street Address', 'cec' ); ?></label>
			<input type="text" name="cec_term_address" id="cec_term_address" value="" />
		</div>
		<div class="form-field">
			<label for="cec_term_city"><?php esc_html_e( 'City', 'cec' ); ?></label>
			<input type="text" name="cec_term_city" id="cec_term_city" value="" />
		</div>
		<div class="form-field">
			<label for="cec_term_region"><?php esc_html_e( 'State / Region', 'cec' ); ?></label>
			<input type="text" name="cec_term_region" id="cec_term_region" value="" />
		</div>
		<div class="form-field">
			<label for="cec_term_country"><?php esc_html_e( 'Country', 'cec' ); ?></label>
			<input type="text" name="cec_term_country" id="cec_term_country" value="" />
		</div>
		<div class="form-field">
			<label for="cec_term_timezone"><?php esc_html_e( 'Timezone', 'cec' ); ?></label>
			<?php echo CEC_Event_Helper::timezone_select_html( 'cec_term_timezone', '' ); // phpcs:ignore ?>
			<p><?php esc_html_e( 'Used as the default timezone for events at this venue. Leave as Site Default unless this venue is in a different timezone than the site.', 'cec' ); ?></p>
		</div>
		<?php else : ?>
		<div class="form-field">
			<label for="cec_term_logo"><?php esc_html_e( 'Logo Image URL', 'cec' ); ?></label>
			<input type="text" name="cec_term_logo" id="cec_term_logo" value="" class="cec-media-field" />
			<button type="button" class="button cec-media-button"><?php esc_html_e( 'Choose Image', 'cec' ); ?></button>
		</div>
		<div class="form-field">
			<label for="cec_term_coc"><?php esc_html_e( 'Default Code of Conduct', 'cec' ); ?></label>
			<textarea name="cec_term_coc" id="cec_term_coc" rows="3"></textarea>
			<p><?php esc_html_e( 'Auto-fills the Code of Conduct field when this organization is selected on an event (only if that field is still empty).', 'cec' ); ?></p>
		</div>
		<div class="form-field">
			<label for="cec_term_rsvp_mode"><?php esc_html_e( 'Default RSVP / Registration', 'cec' ); ?></label>
			<select name="cec_term_rsvp_mode" id="cec_term_rsvp_mode">
				<option value=""><?php esc_html_e( '— No default —', 'cec' ); ?></option>
				<option value="none"><?php esc_html_e( 'No RSVP needed', 'cec' ); ?></option>
				<option value="internal"><?php esc_html_e( 'Collect RSVPs on this site', 'cec' ); ?></option>
				<option value="external"><?php esc_html_e( 'Link to external registration', 'cec' ); ?></option>
			</select>
		</div>
		<div class="form-field">
			<label for="cec_term_rsvp_url"><?php esc_html_e( 'Default RSVP Link', 'cec' ); ?></label>
			<input type="url" name="cec_term_rsvp_url" id="cec_term_rsvp_url" value="" placeholder="https://" />
			<p><?php esc_html_e( 'Used when this organization always registers people the same way, e.g. the same Eventbrite/form link.', 'cec' ); ?></p>
		</div>
		<?php endif;
	}

	public static function edit_fields( $term ) {
		$is_venue  = 'cec_venue' === $term->taxonomy;
		$url       = get_term_meta( $term->term_id, 'cec_url', true );
		$address   = get_term_meta( $term->term_id, 'cec_address', true );
		$city      = get_term_meta( $term->term_id, 'cec_city', true );
		$region    = get_term_meta( $term->term_id, 'cec_region', true );
		$country   = get_term_meta( $term->term_id, 'cec_country', true );
		$timezone  = get_term_meta( $term->term_id, 'cec_timezone', true );
		$logo      = get_term_meta( $term->term_id, 'cec_logo', true );
		$coc       = get_term_meta( $term->term_id, 'cec_default_coc', true );
		$rsvp_mode = get_term_meta( $term->term_id, 'cec_default_rsvp_mode', true );
		$rsvp_url  = get_term_meta( $term->term_id, 'cec_default_rsvp_url', true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="cec_term_url"><?php echo $is_venue ? esc_html__( 'Google Maps Link', 'cec' ) : esc_html__( 'Organization Website', 'cec' ); ?></label></th>
			<td><input type="url" name="cec_term_url" id="cec_term_url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://" /></td>
		</tr>
		<?php if ( $is_venue ) : ?>
		<tr class="form-field">
			<th scope="row"><label for="cec_term_address"><?php esc_html_e( 'Street Address', 'cec' ); ?></label></th>
			<td><input type="text" name="cec_term_address" id="cec_term_address" value="<?php echo esc_attr( $address ); ?>" /></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="cec_term_city"><?php esc_html_e( 'City', 'cec' ); ?></label></th>
			<td><input type="text" name="cec_term_city" id="cec_term_city" value="<?php echo esc_attr( $city ); ?>" /></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="cec_term_region"><?php esc_html_e( 'State / Region', 'cec' ); ?></label></th>
			<td><input type="text" name="cec_term_region" id="cec_term_region" value="<?php echo esc_attr( $region ); ?>" /></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="cec_term_country"><?php esc_html_e( 'Country', 'cec' ); ?></label></th>
			<td><input type="text" name="cec_term_country" id="cec_term_country" value="<?php echo esc_attr( $country ); ?>" /></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="cec_term_timezone"><?php esc_html_e( 'Timezone', 'cec' ); ?></label></th>
			<td>
				<?php echo CEC_Event_Helper::timezone_select_html( 'cec_term_timezone', $timezone ); // phpcs:ignore ?>
				<p class="description"><?php esc_html_e( 'Used as the default timezone for events at this venue. Leave as Site Default unless this venue is in a different timezone than the site.', 'cec' ); ?></p>
			</td>
		</tr>
		<?php else : ?>
		<tr class="form-field">
			<th scope="row"><label for="cec_term_logo"><?php esc_html_e( 'Logo Image URL', 'cec' ); ?></label></th>
			<td>
				<input type="text" name="cec_term_logo" id="cec_term_logo" value="<?php echo esc_attr( $logo ); ?>" class="cec-media-field" />
				<button type="button" class="button cec-media-button"><?php esc_html_e( 'Choose Image', 'cec' ); ?></button>
				<?php if ( $logo ) : ?><p><img src="<?php echo esc_url( $logo ); ?>" style="max-height:60px;" /></p><?php endif; ?>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="cec_term_coc"><?php esc_html_e( 'Default Code of Conduct', 'cec' ); ?></label></th>
			<td>
				<textarea name="cec_term_coc" id="cec_term_coc" rows="3"><?php echo esc_textarea( $coc ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Auto-fills the Code of Conduct field when this organization is selected on an event (only if that field is still empty).', 'cec' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="cec_term_rsvp_mode"><?php esc_html_e( 'Default RSVP / Registration', 'cec' ); ?></label></th>
			<td>
				<select name="cec_term_rsvp_mode" id="cec_term_rsvp_mode">
					<option value="" <?php selected( $rsvp_mode, '' ); ?>><?php esc_html_e( '— No default —', 'cec' ); ?></option>
					<option value="none" <?php selected( $rsvp_mode, 'none' ); ?>><?php esc_html_e( 'No RSVP needed', 'cec' ); ?></option>
					<option value="internal" <?php selected( $rsvp_mode, 'internal' ); ?>><?php esc_html_e( 'Collect RSVPs on this site', 'cec' ); ?></option>
					<option value="external" <?php selected( $rsvp_mode, 'external' ); ?>><?php esc_html_e( 'Link to external registration', 'cec' ); ?></option>
				</select>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="cec_term_rsvp_url"><?php esc_html_e( 'Default RSVP Link', 'cec' ); ?></label></th>
			<td><input type="url" name="cec_term_rsvp_url" id="cec_term_rsvp_url" value="<?php echo esc_attr( $rsvp_url ); ?>" placeholder="https://" /></td>
		</tr>
		<?php endif;
	}

	public static function save_fields( $term_id ) {
		if ( isset( $_POST['cec_term_url'] ) ) {
			update_term_meta( $term_id, 'cec_url', esc_url_raw( wp_unslash( $_POST['cec_term_url'] ) ) );
		}
		if ( isset( $_POST['cec_term_address'] ) ) {
			update_term_meta( $term_id, 'cec_address', sanitize_text_field( wp_unslash( $_POST['cec_term_address'] ) ) );
		}
		if ( isset( $_POST['cec_term_city'] ) ) {
			update_term_meta( $term_id, 'cec_city', sanitize_text_field( wp_unslash( $_POST['cec_term_city'] ) ) );
		}
		if ( isset( $_POST['cec_term_region'] ) ) {
			update_term_meta( $term_id, 'cec_region', sanitize_text_field( wp_unslash( $_POST['cec_term_region'] ) ) );
		}
		if ( isset( $_POST['cec_term_country'] ) ) {
			update_term_meta( $term_id, 'cec_country', sanitize_text_field( wp_unslash( $_POST['cec_term_country'] ) ) );
		}
		if ( isset( $_POST['cec_term_timezone'] ) ) {
			$tz = sanitize_text_field( wp_unslash( $_POST['cec_term_timezone'] ) );
			update_term_meta( $term_id, 'cec_timezone', CEC_Event_Helper::is_valid_timezone( $tz ) ? $tz : '' );
		}
		if ( isset( $_POST['cec_term_logo'] ) ) {
			update_term_meta( $term_id, 'cec_logo', esc_url_raw( wp_unslash( $_POST['cec_term_logo'] ) ) );
		}
		if ( isset( $_POST['cec_term_coc'] ) ) {
			update_term_meta( $term_id, 'cec_default_coc', sanitize_textarea_field( wp_unslash( $_POST['cec_term_coc'] ) ) );
		}
		if ( isset( $_POST['cec_term_rsvp_mode'] ) ) {
			$mode = sanitize_key( wp_unslash( $_POST['cec_term_rsvp_mode'] ) );
			update_term_meta( $term_id, 'cec_default_rsvp_mode', in_array( $mode, array( 'none', 'internal', 'external' ), true ) ? $mode : '' );
		}
		if ( isset( $_POST['cec_term_rsvp_url'] ) ) {
			update_term_meta( $term_id, 'cec_default_rsvp_url', esc_url_raw( wp_unslash( $_POST['cec_term_rsvp_url'] ) ) );
		}
	}

	/**
	 * All the org-level defaults for one Partner Organization term, keyed
	 * the way the front-end/admin autofill JS expects. Empty means "no
	 * default set for this field" — the JS never overwrites a field the
	 * organizer already typed into, only fills blanks.
	 */
	public static function partner_org_defaults( $term_id ) {
		return array(
			'coc'       => get_term_meta( $term_id, 'cec_default_coc', true ),
			'rsvpMode'  => get_term_meta( $term_id, 'cec_default_rsvp_mode', true ),
			'rsvpUrl'   => get_term_meta( $term_id, 'cec_default_rsvp_url', true ),
		);
	}

	/**
	 * Lets a submitter add an organization that isn't in the checklist yet,
	 * without needing wp-admin. The new term is immediately real — it shows
	 * up in filters, gets its own archive/feed, and is subscribable — the
	 * submitted event still goes through normal approval regardless.
	 */
	public static function get_or_create_partner_org( $name ) {
		$name = trim( $name );
		if ( '' === $name ) {
			return 0;
		}
		$existing = get_term_by( 'name', $name, 'cec_partner_org' );
		if ( $existing ) {
			return $existing->term_id;
		}
		$result = wp_insert_term( $name, 'cec_partner_org' );
		return is_wp_error( $result ) ? 0 : $result['term_id'];
	}

	/**
	 * Same idea as get_or_create_partner_org(), for venues: lets a
	 * one-off address typed into "Custom Address" instead be saved as a
	 * real, reusable Venue term so it shows up in the dropdown next time
	 * instead of being retyped. The address itself is stored as this new
	 * term's cec_address meta, exactly like a venue created in wp-admin.
	 */
	public static function get_or_create_venue( $name, $address = '', $city = '', $region = '', $country = '' ) {
		$name = trim( $name );
		if ( '' === $name ) {
			return 0;
		}
		$existing = get_term_by( 'name', $name, 'cec_venue' );
		if ( $existing ) {
			return $existing->term_id;
		}
		$result = wp_insert_term( $name, 'cec_venue' );
		if ( is_wp_error( $result ) ) {
			return 0;
		}
		if ( $address ) {
			update_term_meta( $result['term_id'], 'cec_address', $address );
		}
		if ( $city ) {
			update_term_meta( $result['term_id'], 'cec_city', $city );
		}
		if ( $region ) {
			update_term_meta( $result['term_id'], 'cec_region', $region );
		}
		if ( $country ) {
			update_term_meta( $result['term_id'], 'cec_country', $country );
		}
		return $result['term_id'];
	}

	/**
	 * Every Partner Organization's saved defaults, keyed by term_id — small
	 * enough for a community calendar's org list to localize into admin.js
	 * wholesale, so the wp-admin autofill can look one up by the term_id on
	 * WP's own native Partner Organizations checklist checkbox without
	 * needing to hook into how that checklist itself is rendered.
	 */
	public static function all_partner_org_defaults() {
		$terms = get_terms( array( 'taxonomy' => 'cec_partner_org', 'hide_empty' => false ) );
		$out   = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$out[ $term->term_id ] = self::partner_org_defaults( $term->term_id );
			}
		}
		return $out;
	}

	public static function venue_map_url( $term_id ) {
		$url     = get_term_meta( $term_id, 'cec_url', true );
		if ( $url ) {
			return $url;
		}
		$address = get_term_meta( $term_id, 'cec_address', true );
		if ( $address ) {
			return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $address );
		}
		return '';
	}
}
