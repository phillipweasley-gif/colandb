<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intake API (1.47.0): lets another plugin (the Telegram intake,
 * colandb-telegram-intake) put events into this calendar's review queue
 * without copying this plugin's rules.
 *
 * - Events are created as "pending" only. Nothing here can publish,
 *   whatever the auto-publish setting says: a person approves them in the
 *   usual places (wp-admin, the Calendar Manager dashboard).
 * - Fields go through the same checks as the five forms
 *   (CEC_Event_Helper::save_phase1a_fields(), the allowed admission and
 *   location values, real Event Type terms, http(s) links only).
 * - An update is refused once the event is public (published, scheduled
 *   or private) or rejected (draft): the caller must send it to an editor.
 * - One event can carry several source posts (_cec_intake_source_url,
 *   one meta row each); _cec_source_url keeps the first.
 * - Notes for the editor (_cec_intake_notes) show above the event preview
 *   in wp-admin and on the dashboard edit screen.
 *
 * Public functions: cec_intake_create_event(), cec_intake_update_event(),
 * cec_intake_add_source(), cec_intake_event_fields(), cec_intake_candidates().
 */
class CEC_Intake_API {

	const VERSION = 1;

	/** Statuses an intake update may still change. */
	const EDITABLE_STATUSES = array( 'pending', 'cec_in_review' );

	/** Every status a duplicate check looks at (everything but trash). */
	const ALL_STATUSES = array( 'publish', 'future', 'private', 'pending', 'cec_in_review', 'draft' );

	/**
	 * Accepted keys. Values are plain (unslashed) strings unless noted.
	 * Names match the forms' field names, so the same save code applies.
	 */
	const TEXT_FIELDS = array(
		'cec_host_org_name'         => 'sanitize_text_field',
		'cec_organizers'            => 'sanitize_text_field',
		'cec_venue_custom_address'  => 'sanitize_text_field',
		'cec_price_note'            => 'sanitize_text_field',
		'cec_more_info_url'         => 'url',
		'cec_rsvp_url'              => 'url',
	);

	const PHASE1A_FIELDS = array(
		'cec_time_mode', 'cec_timezone', 'cec_admission_status', 'cec_price_amount', 'cec_price_min', 'cec_price_max',
		'cec_price_currency', 'cec_price_source_url', 'cec_location_mode', 'cec_online_url', 'cec_venue_custom_city',
		'cec_venue_custom_region', 'cec_venue_custom_country', 'cec_official_website_url',
	);

	public static function init() {
		add_filter( 'cec_audit_action_labels', array( __CLASS__, 'labels' ) );
	}

	public static function labels( $labels ) {
		$labels['event_intake_created']      = __( 'Submitted by the Telegram intake', 'cec' );
		$labels['event_intake_updated']      = __( 'Updated from an edited source post', 'cec' );
		$labels['event_intake_source_added'] = __( 'Another source post linked', 'cec' );
		return $labels;
	}

	/**
	 * @param array $f Fields: cec_title (required), cec_start (required,
	 *                 Y-m-d\TH:i), cec_end, cec_excerpt, cec_description,
	 *                 the TEXT_FIELDS and PHASE1A_FIELDS keys, event_type_ids
	 *                 (int[]), source_url, notes (string[]), image_id (int),
	 *                 author_id (int).
	 * @return int|WP_Error The new pending event's ID.
	 */
	public static function create_event( $f ) {
		$f     = is_array( $f ) ? $f : array();
		$check = self::validate( $f, true );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'cec_event',
				'post_status'  => 'pending',
				'post_title'   => sanitize_text_field( $f['cec_title'] ),
				'post_excerpt' => isset( $f['cec_excerpt'] ) ? sanitize_textarea_field( $f['cec_excerpt'] ) : '',
				'post_content' => isset( $f['cec_description'] ) ? wp_kses_post( $f['cec_description'] ) : '',
				'post_author'  => isset( $f['author_id'] ) ? (int) $f['author_id'] : 0,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_cec_start', $f['cec_start'] );
		update_post_meta( $post_id, '_cec_end', isset( $f['cec_end'] ) ? (string) $f['cec_end'] : '' );
		self::save_fields( $post_id, $f, self::defaults() );
		update_post_meta( $post_id, '_cec_event_status', 'scheduled' );
		update_post_meta( $post_id, '_cec_recurrence_rule', 'none' );
		update_post_meta( $post_id, '_cec_rsvp_mode', ! empty( $f['cec_rsvp_url'] ) ? 'external' : 'none' );

		if ( ! empty( $f['source_url'] ) ) {
			update_post_meta( $post_id, '_cec_source_url', esc_url_raw( $f['source_url'] ) );
			add_post_meta( $post_id, '_cec_intake_source_url', esc_url_raw( $f['source_url'] ) );
		}
		if ( isset( $f['notes'] ) ) {
			self::set_notes( $post_id, $f['notes'] );
		}
		if ( ! empty( $f['image_id'] ) && 'attachment' === get_post_type( (int) $f['image_id'] ) ) {
			set_post_thumbnail( $post_id, (int) $f['image_id'] );
		}

		// Belt and braces: whatever happened above (another plugin's hook,
		// a future setting), an intake event leaves here unpublished.
		if ( 'pending' !== get_post_status( $post_id ) ) {
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'pending' ) );
		}
		CEC_Audit_Log::log( $post_id, 'event_intake_created' );
		return (int) $post_id;
	}

	/**
	 * Changes only the keys given, and only while the event is pending or
	 * in review.
	 *
	 * @return true|WP_Error
	 */
	public static function update_event( $post_id, $f ) {
		$post_id = (int) $post_id;
		if ( 'cec_event' !== get_post_type( $post_id ) ) {
			return new WP_Error( 'cec_intake_missing', __( 'That event no longer exists.', 'cec' ) );
		}
		if ( ! in_array( get_post_status( $post_id ), self::EDITABLE_STATUSES, true ) ) {
			return new WP_Error( 'cec_intake_not_editable', __( 'The event has already been reviewed; changes must go to an editor.', 'cec' ) );
		}
		$f     = is_array( $f ) ? $f : array();
		$check = self::validate( $f, false );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$post = array( 'ID' => $post_id );
		if ( isset( $f['cec_title'] ) ) {
			$post['post_title'] = sanitize_text_field( $f['cec_title'] );
		}
		if ( isset( $f['cec_excerpt'] ) ) {
			$post['post_excerpt'] = sanitize_textarea_field( $f['cec_excerpt'] );
		}
		if ( isset( $f['cec_description'] ) ) {
			$post['post_content'] = wp_kses_post( $f['cec_description'] );
		}
		if ( count( $post ) > 1 ) {
			$r = wp_update_post( $post, true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		if ( isset( $f['cec_start'] ) ) {
			update_post_meta( $post_id, '_cec_start', $f['cec_start'] );
		}
		if ( isset( $f['cec_end'] ) ) {
			update_post_meta( $post_id, '_cec_end', (string) $f['cec_end'] );
		}
		self::save_fields( $post_id, $f, self::current( $post_id ) );
		if ( isset( $f['cec_rsvp_url'] ) ) {
			update_post_meta( $post_id, '_cec_rsvp_mode', $f['cec_rsvp_url'] ? 'external' : 'none' );
		}
		if ( isset( $f['notes'] ) ) {
			self::set_notes( $post_id, $f['notes'] );
		}
		CEC_Audit_Log::log( $post_id, 'event_intake_updated' );
		return true;
	}

	/** Links another source post to an event, whatever its status. */
	public static function add_source( $post_id, $url, $note = '' ) {
		$post_id = (int) $post_id;
		$url     = esc_url_raw( (string) $url, array( 'http', 'https' ) );
		if ( 'cec_event' !== get_post_type( $post_id ) || ! $url ) {
			return new WP_Error( 'cec_intake_missing', __( 'That event no longer exists.', 'cec' ) );
		}
		if ( ! in_array( $url, (array) get_post_meta( $post_id, '_cec_intake_source_url' ), true ) ) {
			add_post_meta( $post_id, '_cec_intake_source_url', $url );
		}
		if ( ! get_post_meta( $post_id, '_cec_source_url', true ) ) {
			update_post_meta( $post_id, '_cec_source_url', $url );
		}
		if ( '' !== $note ) {
			$notes   = (array) get_post_meta( $post_id, '_cec_intake_notes', true );
			$notes[] = $note;
			self::set_notes( $post_id, $notes );
		}
		CEC_Audit_Log::log( $post_id, 'event_intake_source_added', $url );
		return true;
	}

	/**
	 * One event's fields in the intake's terms (any status), or null.
	 */
	public static function event_fields( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post || 'cec_event' !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}
		$out = self::current( $post_id );
		$out['id']           = $post_id;
		$out['post_status']  = $post->post_status;
		$out['event_status'] = CEC_Event_Helper::event_status( $post_id );
		$out['cec_title']    = CEC_Event_Helper::plain_text( $post->post_title );
		$out['cec_excerpt']  = $post->post_excerpt;
		$out['cec_start']    = (string) get_post_meta( $post_id, '_cec_start', true );
		$out['cec_end']      = (string) get_post_meta( $post_id, '_cec_end', true );
		$out['venue_name']   = '';
		$venues              = wp_get_post_terms( $post_id, 'cec_venue', array( 'fields' => 'names' ) );
		if ( $venues && ! is_wp_error( $venues ) ) {
			$out['venue_name'] = (string) $venues[0];
		}
		$orgs              = wp_get_post_terms( $post_id, 'cec_partner_org', array( 'fields' => 'names' ) );
		$out['org_names']  = $orgs && ! is_wp_error( $orgs ) ? array_values( $orgs ) : array();
		$out['source_urls'] = array_values( array_unique( array_filter( array_merge( array( (string) get_post_meta( $post_id, '_cec_source_url', true ) ), (array) get_post_meta( $post_id, '_cec_intake_source_url' ) ) ) ) );
		$out['parent_id']   = CEC_Recurrence::is_occurrence( $post_id ) ? (int) CEC_Recurrence::get_parent_id( $post_id ) : 0;
		$out['edit_link']   = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		$out['notes']       = (array) get_post_meta( $post_id, '_cec_intake_notes', true );
		return $out;
	}

	/**
	 * Events a new submission might duplicate: every status except trash,
	 * past and cancelled included. Two ways in: a start date within
	 * $days of the given range, or any of the given links on file
	 * (official site, more info, tickets, online, source, intake sources).
	 * Matching is the caller's job; this only finds candidates.
	 *
	 * @param array $args start (Y-m-d), end (Y-m-d), urls (string[]), days (int), limit (int).
	 * @return array[] event_fields() rows.
	 */
	public static function candidates( $args ) {
		$args  = wp_parse_args( $args, array( 'start' => '', 'end' => '', 'urls' => array(), 'days' => 3, 'limit' => 100 ) );
		$ids   = array();
		$limit = max( 1, min( 300, (int) $args['limit'] ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['start'] ) ) {
			$end  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['end'] ) && $args['end'] >= $args['start'] ? $args['end'] : $args['start'];
			$from = gmdate( 'Y-m-d', strtotime( $args['start'] . ' -' . (int) $args['days'] . ' days' ) ) . 'T00:00';
			$to   = gmdate( 'Y-m-d', strtotime( $end . ' +' . (int) $args['days'] . ' days' ) ) . 'T23:59';
			$ids  = get_posts(
				array(
					'post_type'      => 'cec_event',
					'post_status'    => self::ALL_STATUSES,
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'     => '_cec_start',
							'value'   => array( $from, $to ),
							'compare' => 'BETWEEN',
						),
					),
				)
			);
		}
		$urls = array_values( array_filter( array_map( 'strval', (array) $args['urls'] ) ) );
		if ( $urls ) {
			$or = array( 'relation' => 'OR' );
			foreach ( array( '_cec_official_website_url', '_cec_more_info_url', '_cec_rsvp_url', '_cec_online_url', '_cec_source_url', '_cec_intake_source_url' ) as $key ) {
				$or[] = array(
					'key'     => $key,
					'value'   => array_slice( $urls, 0, 20 ),
					'compare' => 'IN',
				);
			}
			$ids = array_merge(
				$ids,
				get_posts(
					array(
						'post_type'      => 'cec_event',
						'post_status'    => self::ALL_STATUSES,
						'posts_per_page' => $limit,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_query'     => $or, // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				)
			);
		}
		$out = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$row = self::event_fields( $id );
			if ( $row ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */

	private static function defaults() {
		return array(
			'cec_time_mode'        => 'exact',
			'cec_timezone'         => '',
			'cec_admission_status' => 'not_posted',
			'cec_location_mode'    => 'not_posted',
			'cec_price_currency'   => 'USD',
		);
	}

	/** The event's current Phase 1a and text values, keyed like the forms. */
	private static function current( $post_id ) {
		$out = array();
		foreach ( self::PHASE1A_FIELDS as $key ) {
			$out[ $key ] = (string) get_post_meta( $post_id, '_' . $key, true );
		}
		foreach ( array_keys( self::TEXT_FIELDS ) as $key ) {
			$out[ $key ] = (string) get_post_meta( $post_id, '_' . $key, true );
		}
		$out['cec_admission_status'] = CEC_Event_Helper::admission_status( $post_id );
		$types                       = wp_get_post_terms( $post_id, 'cec_event_type', array( 'fields' => 'ids' ) );
		$out['event_type_ids']       = $types && ! is_wp_error( $types ) ? array_map( 'intval', $types ) : array();
		return $out;
	}

	private static function save_fields( $post_id, $f, $base ) {
		foreach ( self::TEXT_FIELDS as $key => $how ) {
			if ( ! array_key_exists( $key, $f ) ) {
				continue;
			}
			$value = 'url' === $how ? esc_url_raw( (string) $f[ $key ], array( 'http', 'https' ) ) : sanitize_text_field( (string) $f[ $key ] );
			update_post_meta( $post_id, '_' . $key, $value );
		}

		// The Phase 1a group is always saved whole (the shared save code
		// resets what it isn't given), so fill the gaps from $base.
		$phase = array();
		foreach ( self::PHASE1A_FIELDS as $key ) {
			if ( array_key_exists( $key, $f ) ) {
				$phase[ $key ] = (string) $f[ $key ];
			} elseif ( isset( $base[ $key ] ) ) {
				$phase[ $key ] = (string) $base[ $key ];
			}
		}
		$touches_phase = array_intersect( array_keys( $f ), self::PHASE1A_FIELDS );
		if ( $touches_phase || ! get_post_meta( $post_id, '_cec_admission_status', true ) ) {
			$keep_review = get_post_meta( $post_id, '_cec_price_needs_review', true );
			CEC_Event_Helper::save_phase1a_fields( $post_id, wp_slash( $phase ) );
			if ( $keep_review && ! array_intersect( $touches_phase, array( 'cec_admission_status', 'cec_price_amount', 'cec_price_min', 'cec_price_max' ) ) ) {
				update_post_meta( $post_id, '_cec_price_needs_review', $keep_review );
			}
		}

		if ( isset( $f['event_type_ids'] ) ) {
			$ids = array();
			foreach ( (array) $f['event_type_ids'] as $id ) {
				$term = get_term( (int) $id, 'cec_event_type' );
				if ( $term && ! is_wp_error( $term ) ) {
					$ids[] = (int) $term->term_id;
				}
			}
			wp_set_object_terms( $post_id, $ids, 'cec_event_type', false );
		}
	}

	private static function set_notes( $post_id, $notes ) {
		$clean = array();
		foreach ( (array) $notes as $note ) {
			$note = sanitize_text_field( (string) $note );
			if ( '' !== $note ) {
				$clean[] = $note;
			}
		}
		$clean = array_slice( array_values( array_unique( $clean ) ), -40 );
		if ( $clean ) {
			update_post_meta( $post_id, '_cec_intake_notes', $clean );
		} else {
			delete_post_meta( $post_id, '_cec_intake_notes' );
		}
	}

	/** @return true|WP_Error */
	private static function validate( $f, $creating ) {
		if ( $creating || isset( $f['cec_title'] ) ) {
			$title = isset( $f['cec_title'] ) ? trim( sanitize_text_field( (string) $f['cec_title'] ) ) : '';
			if ( '' === $title ) {
				return new WP_Error( 'cec_intake_title', __( 'An event needs a title.', 'cec' ) );
			}
		}
		if ( $creating || isset( $f['cec_start'] ) ) {
			if ( ! isset( $f['cec_start'] ) || ! self::valid_datetime( $f['cec_start'] ) ) {
				return new WP_Error( 'cec_intake_start', __( 'An event needs a start date (Y-m-dTH:i).', 'cec' ) );
			}
		}
		if ( ! empty( $f['cec_end'] ) && ! self::valid_datetime( $f['cec_end'] ) ) {
			return new WP_Error( 'cec_intake_end', __( 'The end date is not a valid date (Y-m-dTH:i).', 'cec' ) );
		}
		$enums = array(
			'cec_time_mode'        => CEC_Event_Helper::TIME_MODES,
			'cec_admission_status' => CEC_Event_Helper::ADMISSION_STATES,
			'cec_location_mode'    => CEC_Event_Helper::LOCATION_MODES,
		);
		foreach ( $enums as $key => $allowed ) {
			if ( isset( $f[ $key ] ) && ! in_array( $f[ $key ], $allowed, true ) ) {
				/* translators: 1: field name, 2: the value given */
				return new WP_Error( 'cec_intake_value', sprintf( __( '%1$s cannot be "%2$s".', 'cec' ), $key, (string) $f[ $key ] ) );
			}
		}
		if ( ! empty( $f['cec_timezone'] ) && ! CEC_Event_Helper::is_valid_timezone( $f['cec_timezone'] ) ) {
			return new WP_Error( 'cec_intake_timezone', __( 'The timezone is not a valid IANA name.', 'cec' ) );
		}
		foreach ( array( 'cec_price_amount', 'cec_price_min', 'cec_price_max' ) as $key ) {
			if ( isset( $f[ $key ] ) && '' !== (string) $f[ $key ] && ! preg_match( '/^\d+(\.\d{1,2})?$/', (string) $f[ $key ] ) ) {
				/* translators: %s: field name */
				return new WP_Error( 'cec_intake_price', sprintf( __( '%s must be a plain amount like 10 or 12.50.', 'cec' ), $key ) );
			}
		}
		if ( isset( $f['cec_admission_status'] ) && 'paid' === $f['cec_admission_status'] && isset( $f['cec_price_amount'] ) && '' !== $f['cec_price_amount'] && 0.0 === (float) $f['cec_price_amount'] ) {
			return new WP_Error( 'cec_intake_price', __( 'A paid event cannot cost 0. Use "Price not posted" or confirm it is free.', 'cec' ) );
		}
		foreach ( array( 'cec_online_url', 'cec_official_website_url', 'cec_more_info_url', 'cec_rsvp_url', 'cec_price_source_url', 'source_url' ) as $key ) {
			if ( ! empty( $f[ $key ] ) && ! self::valid_url( (string) $f[ $key ] ) ) {
				/* translators: %s: field name */
				return new WP_Error( 'cec_intake_url', sprintf( __( '%s is not a valid web address.', 'cec' ), $key ) );
			}
		}
		return true;
	}

	/** http(s), a host with a dot, no spaces. No DNS lookup: an event link may be checked before its site is live. */
	public static function valid_url( $url ) {
		if ( ! preg_match( '#^https?://#i', $url ) || preg_match( '/\s/', $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return false !== strpos( $host, '.' ) && ! preg_match( '/^(localhost|[\d.]+)$/i', $host );
	}

	private static function valid_datetime( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) && (int) $m[4] < 24 && (int) $m[5] < 60;
	}
}

function cec_intake_create_event( $fields ) {
	return CEC_Intake_API::create_event( $fields );
}

function cec_intake_update_event( $event_id, $fields ) {
	return CEC_Intake_API::update_event( $event_id, $fields );
}

function cec_intake_add_source( $event_id, $url, $note = '' ) {
	return CEC_Intake_API::add_source( $event_id, $url, $note );
}

function cec_intake_event_fields( $event_id ) {
	return CEC_Intake_API::event_fields( $event_id );
}

function cec_intake_candidates( $args ) {
	return CEC_Intake_API::candidates( $args );
}
