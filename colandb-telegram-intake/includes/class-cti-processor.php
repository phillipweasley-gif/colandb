<?php
/**
 * Works through the intake queue (WP-Cron every 5 minutes, and a minute
 * after each new post; or `wp colandb-intake process`).
 *
 * Post statuses:  received → processing → done | not_event | retry | failed
 *                 (merged = an album photo processed with its first message)
 * Entry statuses: needs_info, possible_duplicate, dry_run, waiting (rate
 *                 limit), submitted (pending event created), updated (that
 *                 event changed from an edited post), update_review (edit to
 *                 a reviewed/published event, for an editor), linked,
 *                 dismissed, reviewed.
 *
 * Never publishes: events are created through cec_intake_create_event(),
 * which only makes "pending" events.
 */
defined( 'ABSPATH' ) || exit;

final class CTI_Retry extends Exception {}

final class CTI_Processor {

	const HOOK         = 'cti_process';
	const CLEANUP_HOOK = 'cti_cleanup';
	const BACKOFF      = array( 300, 900, 3600, 10800, 43200 );
	const DEBOUNCE     = 45; // Seconds to wait for the rest of an album.
	const REOPEN       = array( 'new', 'needs_info', 'possible_duplicate', 'dry_run', 'waiting' );

	/** Images read for the post being processed: [ bytes, mime ][] */
	private static $images = array();

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function cron_schedules( $schedules ) {
		$schedules['cti_five_minutes'] = array( 'interval' => 300, 'display' => 'Every 5 minutes (Telegram intake)' );
		return $schedules;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'cti_five_minutes', self::HOOK );
		}
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	public static function schedule_soon() {
		if ( ! wp_next_scheduled( self::HOOK, array( 'soon' ) ) ) {
			wp_schedule_single_event( time() + 60, self::HOOK, array( 'soon' ) );
		}
	}

	/** @return int Posts processed. */
	public static function run( $limit = 0 ) {
		global $wpdb;
		$limit = is_numeric( $limit ) && (int) $limit > 0 ? (int) $limit : (int) CTI_Config::get( 'max_posts_per_run' );
		if ( ! CTI_Install::lock( 'run', 900 ) ) {
			return 0;
		}
		$done = 0;
		try {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			$t    = CTI_Install::table( 'posts' );
			$now  = current_time( 'mysql', true );
			$ids  = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM $t WHERE ( status = 'received' AND updated_at <= %s ) OR ( status = 'retry' AND ( next_attempt IS NULL OR next_attempt <= %s ) ) ORDER BY id ASC LIMIT %d",
					gmdate( 'Y-m-d H:i:s', time() - (int) apply_filters( 'cti_album_wait', self::DEBOUNCE ) ),
					$now,
					$limit
				)
			);
			foreach ( $ids as $id ) {
				// Claim it: only one process can move it to "processing".
				$claimed = $wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'processing', updated_at = %s WHERE id = %d AND status IN ('received','retry')", $now, (int) $id ) );
				if ( 1 !== (int) $claimed ) {
					continue;
				}
				$post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", (int) $id ), ARRAY_A );
				self::process_post( $post );
				$done++;
			}
			// A post stuck in "processing" (a crashed run) goes back in the queue.
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'retry', reason = 'Interrupted; trying again.' WHERE status = 'processing' AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - 1800 ) ) );
		} finally {
			CTI_Install::unlock( 'run' );
			CTI_Notify::flush();
		}
		return $done;
	}

	public static function process_post( $post ) {
		self::$images = array();
		try {
			$channel = self::channel_for_post( $post );
			if ( ! $channel ) {
				self::set_post( $post['id'], array( 'status' => 'ignored', 'reason' => 'The channel is no longer on the allowlist.' ) );
				CTI_Log::add( $post['id'], 0, 'ignored', 'Channel removed from the allowlist.' );
				return;
			}
			if ( ! cti_calendar_ready() ) {
				throw new CTI_Retry( 'The Community Events Calendar (1.47.0 or later) is not active.' );
			}

			$notes_for_all = array();
			$image_hash    = '';
			$photos        = json_decode( (string) $post['photos'], true );
			foreach ( array_slice( is_array( $photos ) ? $photos : array(), 0, 2 ) as $photo ) {
				$img = self::read_image( $photo );
				if ( is_wp_error( $img ) ) {
					if ( 'cti_retry' === $img->get_error_code() ) {
						throw new CTI_Retry( $img->get_error_message() );
					}
					$notes_for_all[] = 'Flyer image not read: ' . $img->get_error_message();
					CTI_Log::add( $post['id'], 0, 'image_skipped', $img->get_error_message() );
					continue;
				}
				self::$images[] = $img;
				if ( ! $image_hash ) {
					$image_hash = hash( 'sha256', $img['bytes'] );
				}
			}
			$post['image_hash'] = $image_hash;
			self::set_post( $post['id'], array( 'image_hash' => $image_hash ) );

			$types = get_terms( array( 'taxonomy' => 'cec_event_type', 'hide_empty' => false, 'fields' => 'names' ) );
			$out   = CTI_Extractors::run(
				array(
					'text'          => (string) $post['body'],
					'links'         => (array) json_decode( (string) $post['links'], true ),
					'post_date'     => (string) $post['post_date'],
					'channel'       => (string) $post['channel'],
					'images'        => self::$images,
					'event_types'   => is_wp_error( $types ) ? array() : array_values( $types ),
					'site_timezone' => wp_timezone_string(),
				)
			);
			if ( is_wp_error( $out ) ) {
				$code = $out->get_error_code();
				if ( 'cti_retry' === $code ) {
					throw new CTI_Retry( $out->get_error_message() );
				}
				if ( 'cti_config' === $code ) {
					CTI_Notify::alert( 'The event reader is not set up correctly: ' . $out->get_error_message() );
					throw new CTI_Retry( $out->get_error_message() );
				}
				// This post can't be read automatically: an editor looks at it.
				self::set_post( $post['id'], array( 'extraction' => '', 'status' => 'done', 'reason' => $out->get_error_message(), 'processed_at' => current_time( 'mysql', true ) ) );
				self::manual_entry( $post, $out->get_error_message() );
				return;
			}
			self::set_post( $post['id'], array( 'extraction' => wp_json_encode( $out ) ) );
			CTI_Log::add( $post['id'], 0, 'extracted', ( $out['is_event'] ? count( $out['events'] ) . ' event(s)' : 'not an event: ' . $out['not_event_reason'] ) . ( self::$images ? '; ' . count( self::$images ) . ' image(s) read' : '' ) );

			$existing = self::entries_for_post( $post['id'] );
			if ( ! $out['is_event'] ) {
				foreach ( $existing as $entry ) {
					self::source_gone( $post, $entry, 'The edited post no longer looks like an event announcement.' );
				}
				self::set_post( $post['id'], array( 'status' => 'not_event', 'reason' => $out['not_event_reason'] ? $out['not_event_reason'] : 'Not an event announcement.', 'processed_at' => current_time( 'mysql', true ) ) );
				return;
			}

			$waiting = false;
			foreach ( $out['events'] as $idx => $ev ) {
				$norm          = CTI_Normalizer::entry( $ev, $post );
				$norm['notes'] = array_values( array_unique( array_merge( $notes_for_all, $norm['notes'] ) ) );
				$entry         = isset( $existing[ $idx ] ) ? $existing[ $idx ] : null;
				$status        = $entry && ! in_array( $entry['status'], self::REOPEN, true ) ? self::handle_edit( $post, $channel, $norm, $entry ) : self::handle_new( $post, $channel, $norm, $idx, $entry );
				$waiting       = $waiting || 'waiting' === $status;
			}
			foreach ( $existing as $idx => $entry ) {
				if ( $idx >= count( $out['events'] ) ) {
					self::source_gone( $post, $entry, 'The edited post no longer lists this event.' );
				}
			}

			if ( $waiting ) {
				self::set_post( $post['id'], array( 'status' => 'retry', 'reason' => 'Waiting: hourly submission limit reached.', 'next_attempt' => gmdate( 'Y-m-d H:i:s', time() + 900 ) ) );
			} else {
				self::set_post( $post['id'], array( 'status' => 'done', 'reason' => '', 'attempts' => 0, 'next_attempt' => null, 'processed_at' => current_time( 'mysql', true ) ) );
			}
		} catch ( CTI_Retry $e ) {
			self::retry_later( $post, $e->getMessage() );
		} catch ( Throwable $e ) {
			self::retry_later( $post, 'Unexpected error: ' . $e->getMessage() );
		}
	}

	/* ------------------------------------------------------------------
	 * New entries
	 * ---------------------------------------------------------------- */

	/** @return string The entry's new status. */
	private static function handle_new( $post, $channel, $norm, $idx, $entry ) {
		$entry_id = self::save_entry( $post['id'], $idx, $entry, array( 'status' => 'new', 'proposed' => $norm ) );

		if ( ! $norm['ok'] ) {
			self::set_entry( $entry_id, array( 'status' => 'needs_info', 'reason' => implode( ' ', $norm['missing'] ) ) );
			CTI_Log::add( $post['id'], $entry_id, 'needs_info', implode( ' ', $norm['missing'] ) );
			CTI_Notify::add( 'needs_info', self::label( $norm ), implode( ' ', $norm['missing'] ), CTI_Notify::queue_url( $entry_id ) );
			return 'needs_info';
		}

		// One submission at a time, so two copies of the same event arriving
		// together can't both pass the duplicate check.
		if ( ! CTI_Install::lock( 'submit', 120, 30 ) ) {
			throw new CTI_Retry( 'Another submission is in progress.' );
		}
		try {
			$candidates = CTI_Duplicates::find( $norm['match'], (int) $post['id'] );
			if ( $candidates ) {
				$best = $candidates[0];
				self::set_entry( $entry_id, array( 'status' => 'possible_duplicate', 'candidates' => $candidates, 'reason' => 'Looks like "' . $best['title'] . '" (' . $best['status'] . '): ' . implode( ', ', $best['reasons'] ) . '.' ) );
				CTI_Log::add( $post['id'], $entry_id, 'possible_duplicate', $candidates );
				CTI_Notify::add( 'duplicate', self::label( $norm ), 'may be "' . $best['title'] . '"', CTI_Notify::queue_url( $entry_id ) );
				return 'possible_duplicate';
			}

			if ( CTI_Config::dry_run() ) {
				self::set_entry( $entry_id, array( 'status' => 'dry_run', 'reason' => 'Dry run: this event would be submitted for approval.' ) );
				CTI_Log::add( $post['id'], $entry_id, 'dry_run', array( 'fields' => $norm['fields'], 'notes' => $norm['notes'] ) );
				return 'dry_run';
			}

			if ( self::submissions_last_hour() >= (int) CTI_Config::get( 'max_submissions_per_hour' ) ) {
				self::set_entry( $entry_id, array( 'status' => 'waiting', 'reason' => 'Hourly submission limit reached; will try again.' ) );
				return 'waiting';
			}

			$event_id = self::create_event( $post, $channel, $norm, $entry_id );
			if ( is_wp_error( $event_id ) ) {
				if ( 0 === strpos( $event_id->get_error_code(), 'cec_intake_' ) ) {
					self::set_entry( $entry_id, array( 'status' => 'needs_info', 'reason' => 'The calendar did not accept it: ' . $event_id->get_error_message() ) );
					CTI_Notify::add( 'needs_info', self::label( $norm ), $event_id->get_error_message(), CTI_Notify::queue_url( $entry_id ) );
					return 'needs_info';
				}
				throw new CTI_Retry( 'The calendar could not save the event: ' . $event_id->get_error_message() );
			}
			return 'submitted';
		} finally {
			CTI_Install::unlock( 'submit' );
		}
	}

	private static function create_event( $post, $channel, $norm, $entry_id, $by_user = 0 ) {
		$fields              = $norm['fields'];
		$fields['notes']     = $norm['notes'];
		$fields['author_id'] = (int) CTI_Config::get( 'author_id' );

		$photos = json_decode( (string) $post['photos'], true );
		if ( $photos ) {
			if ( $channel && ! empty( $channel['reuse_images'] ) ) {
				if ( ! self::$images ) {
					// An editor's decision from the queue: fetch the flyer now.
					$img = self::read_image( $photos[0] );
					if ( ! is_wp_error( $img ) ) {
						self::$images[] = $img;
					}
				}
				$image_id = self::$images ? self::copy_image( $post, $norm['title'] ) : 0;
				if ( $image_id ) {
					$fields['image_id'] = $image_id;
					$fields['notes'][]  = 'The flyer from the source post is the event image. Check it suits the calendar before approving.';
				} else {
					$fields['notes'][] = 'The flyer could not be copied; see the source post.';
				}
			} else {
				$fields['notes'][] = 'Flyer not used: this channel is set not to share its flyers. See the source post.';
			}
		}

		$event_id = cec_intake_create_event( $fields );
		if ( is_wp_error( $event_id ) ) {
			return $event_id;
		}
		self::set_entry(
			$entry_id,
			array(
				'status'     => 'submitted',
				'event_id'   => $event_id,
				'reason'     => '',
				'decided_by' => $by_user,
				'decided_at' => $by_user ? current_time( 'mysql', true ) : null,
			)
		);
		CTI_Log::add( $post['id'], $entry_id, 'submitted', 'Pending event #' . $event_id . ( $by_user ? ' (by user #' . $by_user . ')' : '' ) );
		CTI_Notify::add( 'submitted', self::label( $norm ), $norm['notes'] ? count( $norm['notes'] ) . ' note(s) for the editor' : '', admin_url( 'post.php?post=' . $event_id . '&action=edit' ) );
		return $event_id;
	}

	/* ------------------------------------------------------------------
	 * Edited source posts
	 * ---------------------------------------------------------------- */

	private static function handle_edit( $post, $channel, $norm, $entry ) {
		$old     = json_decode( (string) $entry['proposed'], true );
		$old_f   = isset( $old['fields'] ) ? $old['fields'] : array();
		$changed = array();
		foreach ( CTI_Normalizer::compared_keys() as $key ) {
			$a = isset( $old_f[ $key ] ) ? $old_f[ $key ] : ( 'event_type_ids' === $key ? array() : '' );
			$b = isset( $norm['fields'][ $key ] ) ? $norm['fields'][ $key ] : ( 'event_type_ids' === $key ? array() : '' );
			if ( $a != $b ) { // phpcs:ignore Universal.Operators.StrictComparisons -- arrays and strings.
				$changed[ $key ] = array( $a, $b );
			}
		}
		$entry_id = (int) $entry['id'];
		self::set_entry( $entry_id, array( 'previous' => $old, 'proposed' => $norm, 'title' => $norm['title'], 'start_date' => $norm['start_date'] ) );
		if ( ! $changed ) {
			CTI_Log::add( $post['id'], $entry_id, 'edit_no_change', 'The edit did not change any event details.' );
			return $entry['status'];
		}
		$labels = implode( ', ', array_map( array( 'CTI_Normalizer', 'field_label' ), array_keys( $changed ) ) );
		if ( 'dismissed' === $entry['status'] ) {
			CTI_Log::add( $post['id'], $entry_id, 'edit_ignored', 'Dismissed earlier; the edit changed: ' . $labels );
			return 'dismissed';
		}

		$event = (int) $entry['event_id'] ? cec_intake_event_fields( (int) $entry['event_id'] ) : null;
		if ( in_array( $entry['status'], array( 'submitted', 'updated' ), true ) && $event && in_array( $event['post_status'], array( 'pending', 'cec_in_review' ), true ) ) {
			$apply     = array();
			$conflicts = array();
			foreach ( $changed as $key => $pair ) {
				$current = isset( $event[ $key ] ) ? $event[ $key ] : ( 'event_type_ids' === $key ? array() : '' );
				if ( in_array( $key, array( 'cec_title' ), true ) ) {
					$current = $event['cec_title'];
				}
				if ( $current == $pair[0] ) { // phpcs:ignore Universal.Operators.StrictComparisons
					$apply[ $key ] = $pair[1];
				} else {
					$conflicts[] = 'The source post was edited and now says ' . CTI_Normalizer::field_label( $key ) . ': "' . CTI_Normalizer::value_text( $key, $pair[1] ) . '"; the editor\'s value was kept.';
				}
			}
			if ( isset( $apply['cec_start'] ) && ! isset( $apply['cec_end'] ) && isset( $norm['fields']['cec_end'] ) && $event['cec_end'] === ( isset( $old_f['cec_end'] ) ? $old_f['cec_end'] : '' ) ) {
				$apply['cec_end'] = $norm['fields']['cec_end'];
			}
			$notes   = array_merge( (array) $event['notes'], $conflicts );
			$notes[] = 'Source post edited ' . ( $post['edit_date'] ? $post['edit_date'] . ' UTC' : '' ) . ': changed ' . $labels . '.';
			$apply['notes'] = $notes;
			$result         = cec_intake_update_event( (int) $entry['event_id'], $apply );
			if ( ! is_wp_error( $result ) ) {
				self::set_entry( $entry_id, array( 'status' => 'updated', 'reason' => 'Changed: ' . $labels ) );
				CTI_Log::add( $post['id'], $entry_id, 'updated', array( 'applied' => array_keys( $apply ), 'conflicts' => $conflicts ) );
				CTI_Notify::add( 'updated', self::label( $norm ), 'changed ' . $labels, admin_url( 'post.php?post=' . (int) $entry['event_id'] . '&action=edit' ) );
				return 'updated';
			}
			if ( 'cec_intake_not_editable' !== $result->get_error_code() ) {
				throw new CTI_Retry( 'The calendar could not update event #' . (int) $entry['event_id'] . ': ' . $result->get_error_message() );
			}
			$event = cec_intake_event_fields( (int) $entry['event_id'] );
		}

		// Reviewed, published, linked or deleted: an editor decides.
		$diff = array();
		foreach ( $changed as $key => $pair ) {
			$diff[] = array(
				'field'  => CTI_Normalizer::field_label( $key ),
				'event'  => $event && isset( $event[ $key ] ) ? CTI_Normalizer::value_text( $key, $event[ $key ] ) : '',
				'before' => CTI_Normalizer::value_text( $key, $pair[0] ),
				'after'  => CTI_Normalizer::value_text( $key, $pair[1] ),
			);
		}
		$why = $event ? 'The source post was edited after the event was ' . ( 'publish' === $event['post_status'] ? 'published' : 'reviewed (' . $event['post_status'] . ')' ) . '. The calendar was not changed.' : 'The source post was edited, but its calendar event no longer exists.';
		self::set_entry( $entry_id, array( 'status' => 'update_review', 'reason' => $why . ' Changed: ' . $labels . '.', 'candidates' => array( 'diff' => $diff, 'previous_status' => $entry['status'] ) ) );
		CTI_Log::add( $post['id'], $entry_id, 'update_review', $diff );
		CTI_Notify::add( 'update_review', self::label( $norm ), 'changed ' . $labels, CTI_Notify::queue_url( $entry_id ) );
		return 'update_review';
	}

	private static function source_gone( $post, $entry, $why ) {
		if ( in_array( $entry['status'], array( 'dismissed' ), true ) ) {
			return;
		}
		if ( (int) $entry['event_id'] ) {
			self::set_entry( (int) $entry['id'], array( 'status' => 'update_review', 'reason' => $why . ' The calendar event was not changed.', 'candidates' => array( 'diff' => array(), 'previous_status' => $entry['status'] ) ) );
			CTI_Notify::add( 'update_review', $entry['title'], $why, CTI_Notify::queue_url( (int) $entry['id'] ) );
		} else {
			self::set_entry( (int) $entry['id'], array( 'status' => 'dismissed', 'reason' => $why ) );
		}
		CTI_Log::add( $post['id'], (int) $entry['id'], 'source_gone', $why );
	}

	/** An entry for a post the reader couldn't handle, so an editor sees it. */
	private static function manual_entry( $post, $why ) {
		$existing = self::entries_for_post( $post['id'] );
		if ( $existing ) {
			foreach ( $existing as $entry ) {
				if ( in_array( $entry['status'], self::REOPEN, true ) ) {
					self::set_entry( (int) $entry['id'], array( 'status' => 'needs_info', 'reason' => $why ) );
				}
			}
			return;
		}
		$first = trim( strtok( (string) $post['body'], "\n" ) );
		$norm  = array( 'ok' => false, 'missing' => array( $why ), 'fields' => array(), 'notes' => array(), 'display' => array(), 'title' => mb_substr( $first, 0, 120 ), 'start_date' => '', 'match' => array() );
		$id    = self::save_entry( $post['id'], 0, null, array( 'status' => 'needs_info', 'proposed' => $norm, 'reason' => $why ) );
		CTI_Log::add( $post['id'], $id, 'needs_info', $why );
		CTI_Notify::add( 'needs_info', $norm['title'] ? $norm['title'] : 'Post #' . $post['id'], $why, CTI_Notify::queue_url( $id ) );
	}

	/* ------------------------------------------------------------------
	 * Editor actions (from the review screen)
	 * ---------------------------------------------------------------- */

	/**
	 * Creates a pending event from an entry, after an editor's decision
	 * (for example "this is a separate event", or details filled in).
	 *
	 * @param array $overrides title, start_date, start_time, end_date, end_time.
	 */
	public static function editor_create( $entry_id, $overrides, $user_id ) {
		$entry = self::get_entry( $entry_id );
		if ( ! $entry || ! in_array( $entry['status'], self::REOPEN, true ) ) {
			return new WP_Error( 'cti_state', 'This item has already been handled.' );
		}
		if ( ! cti_calendar_ready() ) {
			return new WP_Error( 'cti_calendar', 'The Community Events Calendar (1.47.0 or later) is not active.' );
		}
		$post = self::get_post( (int) $entry['post_id'] );
		$norm = json_decode( (string) $entry['proposed'], true );
		$f    = isset( $norm['fields'] ) ? $norm['fields'] : array();
		if ( ! empty( $overrides['title'] ) ) {
			$f['cec_title'] = sanitize_text_field( $overrides['title'] );
		}
		if ( ! empty( $overrides['start_date'] ) ) {
			$st             = ! empty( $overrides['start_time'] ) ? $overrides['start_time'] : '';
			$f['cec_start'] = $overrides['start_date'] . 'T' . ( $st ? $st : '00:00' );
			$f['cec_time_mode'] = $st ? ( ! empty( $overrides['end_time'] ) ? 'exact' : 'start_only' ) : 'varies';
			$f['cec_end']   = ! empty( $overrides['end_time'] ) ? ( ! empty( $overrides['end_date'] ) ? $overrides['end_date'] : $overrides['start_date'] ) . 'T' . $overrides['end_time'] : ( ! empty( $overrides['end_date'] ) ? $overrides['end_date'] . 'T23:59' : '' );
		}
		if ( empty( $f['source_url'] ) && $post ) {
			$f['source_url'] = $post['post_url'];
		}
		$norm['fields']  = $f;
		$norm['title']   = isset( $f['cec_title'] ) ? $f['cec_title'] : '';
		$norm['notes']   = isset( $norm['notes'] ) ? (array) $norm['notes'] : array();
		$norm['notes'][] = 'Sent to the calendar by an editor from the intake queue.';
		self::$images    = array();
		$event_id        = self::create_event( $post, self::channel_for_post( $post ), $norm, (int) $entry_id, (int) $user_id );
		if ( ! is_wp_error( $event_id ) ) {
			self::set_entry( (int) $entry_id, array( 'proposed' => $norm, 'title' => $norm['title'], 'start_date' => substr( (string) $f['cec_start'], 0, 10 ) ) );
		}
		return $event_id;
	}

	/** Links the entry's source post to an existing event. Changes nothing else on the event. */
	public static function editor_link( $entry_id, $event_id, $user_id ) {
		$entry = self::get_entry( $entry_id );
		if ( ! $entry || ! in_array( $entry['status'], self::REOPEN, true ) ) {
			return new WP_Error( 'cti_state', 'This item has already been handled.' );
		}
		$event = cec_intake_event_fields( (int) $event_id );
		if ( ! $event ) {
			return new WP_Error( 'cti_missing', 'That event no longer exists.' );
		}
		$post  = self::get_post( (int) $entry['post_id'] );
		$norm  = json_decode( (string) $entry['proposed'], true );
		$diffs = array();
		foreach ( CTI_Normalizer::compared_keys() as $key ) {
			if ( ! isset( $norm['fields'][ $key ] ) || '' === $norm['fields'][ $key ] || array() === $norm['fields'][ $key ] ) {
				continue;
			}
			$mine = $norm['fields'][ $key ];
			$cur  = 'cec_title' === $key ? $event['cec_title'] : ( isset( $event[ $key ] ) ? $event[ $key ] : '' );
			if ( $cur != $mine ) { // phpcs:ignore Universal.Operators.StrictComparisons
				$diffs[] = CTI_Normalizer::field_label( $key ) . ': "' . CTI_Normalizer::value_text( $key, $mine ) . '"';
			}
		}
		$note = 'Also announced in ' . $post['post_url'] . ' (linked by an editor).' . ( $diffs ? ' That post differs on ' . implode( '; ', $diffs ) . '. Nothing was changed automatically.' : '' );
		$r    = cec_intake_add_source( (int) $event_id, $post['post_url'], $note );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		self::set_entry( (int) $entry_id, array( 'status' => 'linked', 'event_id' => (int) $event_id, 'reason' => $note, 'decided_by' => (int) $user_id, 'decided_at' => current_time( 'mysql', true ) ) );
		CTI_Log::add( $entry['post_id'], $entry_id, 'linked', 'Event #' . (int) $event_id . ' by user #' . (int) $user_id );
		return true;
	}

	public static function editor_set( $entry_id, $status, $reason, $user_id ) {
		$entry = self::get_entry( $entry_id );
		if ( ! $entry ) {
			return new WP_Error( 'cti_missing', 'Not found.' );
		}
		if ( 'reviewed' === $status && 'update_review' !== $entry['status'] ) {
			return new WP_Error( 'cti_state', 'Nothing to review.' );
		}
		if ( 'dismissed' === $status && ! in_array( $entry['status'], array_merge( self::REOPEN, array( 'update_review' ) ), true ) ) {
			return new WP_Error( 'cti_state', 'This item has already been handled.' );
		}
		self::set_entry( (int) $entry_id, array( 'status' => $status, 'reason' => sanitize_text_field( $reason ), 'decided_by' => (int) $user_id, 'decided_at' => current_time( 'mysql', true ) ) );
		CTI_Log::add( $entry['post_id'], $entry_id, $status, 'By user #' . (int) $user_id . ( $reason ? ': ' . $reason : '' ) );
		return true;
	}

	public static function editor_retry( $post_id ) {
		self::set_post( (int) $post_id, array( 'status' => 'retry', 'attempts' => 0, 'next_attempt' => null, 'reason' => 'Retry requested.' ) );
		CTI_Log::add( (int) $post_id, 0, 'retry_requested', '' );
		self::schedule_soon();
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	private static function read_image( $photo ) {
		$max = (int) CTI_Config::get( 'max_image_bytes' );
		if ( ! empty( $photo['size'] ) && (int) $photo['size'] > $max ) {
			return new WP_Error( 'cti_too_big', 'larger than the ' . size_format( $max ) . ' limit.' );
		}
		if ( ! empty( $photo['mime'] ) && ! in_array( $photo['mime'], (array) CTI_Config::get( 'image_types' ), true ) ) {
			return new WP_Error( 'cti_type', 'file type ' . sanitize_text_field( $photo['mime'] ) . ' is not accepted.' );
		}
		$bytes = CTI_Telegram::download( $photo['file_id'], $max );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}
		// Check what the bytes really are, not what the message said.
		$mime = '';
		if ( function_exists( 'finfo_open' ) ) {
			$fi   = finfo_open( FILEINFO_MIME_TYPE );
			$mime = (string) finfo_buffer( $fi, $bytes );
			finfo_close( $fi );
		}
		$size = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $size || ! in_array( $mime, (array) CTI_Config::get( 'image_types' ), true ) || $size['mime'] !== $mime ) {
			return new WP_Error( 'cti_type', 'not an accepted image (' . ( $mime ? $mime : 'unknown type' ) . ').' );
		}
		if ( $size[0] > 10000 || $size[1] > 10000 ) {
			return new WP_Error( 'cti_too_big', 'image dimensions too large.' );
		}
		return array( 'bytes' => $bytes, 'mime' => $mime );
	}

	private static function copy_image( $post, $title ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$img  = self::$images[0];
		$ext  = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );
		$tmp  = wp_tempnam( 'cti' );
		file_put_contents( $tmp, $img['bytes'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$file = array(
			'name'     => 'telegram-' . sanitize_file_name( $post['chat_id'] . '-' . $post['message_id'] ) . '.' . ( isset( $ext[ $img['mime'] ] ) ? $ext[ $img['mime'] ] : 'jpg' ),
			'tmp_name' => $tmp,
		);
		$id = media_handle_sideload( $file, 0, $title );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			CTI_Log::add( $post['id'], 0, 'image_copy_failed', $id->get_error_message() );
			return 0;
		}
		return (int) $id;
	}

	private static function retry_later( $post, $message ) {
		$attempts = (int) $post['attempts'] + 1;
		$max      = max( 1, (int) CTI_Config::get( 'max_attempts' ) );
		if ( $attempts >= $max ) {
			self::set_post( $post['id'], array( 'status' => 'failed', 'attempts' => $attempts, 'reason' => $message, 'next_attempt' => null ) );
			CTI_Log::add( $post['id'], 0, 'failed', $message );
			CTI_Notify::alert( 'A Telegram post (' . $post['post_url'] . ') could not be processed after ' . $attempts . ' tries: ' . $message );
			return;
		}
		$wait = self::BACKOFF[ min( $attempts - 1, count( self::BACKOFF ) - 1 ) ];
		self::set_post( $post['id'], array( 'status' => 'retry', 'attempts' => $attempts, 'reason' => $message, 'next_attempt' => gmdate( 'Y-m-d H:i:s', time() + $wait ) ) );
		CTI_Log::add( $post['id'], 0, 'retry', $message );
	}

	private static function submissions_last_hour() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CTI_Install::table( 'log' ) . " WHERE action = 'submitted' AND created_at > %s", gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
	}

	private static function channel_for_post( $post ) {
		$username = preg_match( '#^https://t\.me/([A-Za-z0-9_]+)/(\d+/)?\d+$#', (string) $post['post_url'], $m ) && 'c' !== $m[1] ? $m[1] : '';
		return CTI_Config::channel_for( array( 'id' => $post['chat_id'], 'username' => $username ) );
	}

	private static function label( $norm ) {
		$title = isset( $norm['title'] ) && $norm['title'] ? $norm['title'] : '(no title)';
		return '"' . $title . '"' . ( ! empty( $norm['start_date'] ) ? ' on ' . $norm['start_date'] : '' );
	}

	public static function entries_for_post( $post_id ) {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CTI_Install::table( 'entries' ) . ' WHERE post_id = %d ORDER BY idx ASC', (int) $post_id ), ARRAY_A ) as $row ) {
			$out[ (int) $row['idx'] ] = $row;
		}
		return $out;
	}

	public static function get_entry( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CTI_Install::table( 'entries' ) . ' WHERE id = %d', (int) $id ), ARRAY_A );
	}

	public static function get_post( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CTI_Install::table( 'posts' ) . ' WHERE id = %d', (int) $id ), ARRAY_A );
	}

	private static function save_entry( $post_id, $idx, $entry, $data ) {
		global $wpdb;
		$row = array(
			'status'     => $data['status'],
			'proposed'   => wp_json_encode( $data['proposed'] ),
			'title'      => mb_substr( (string) $data['proposed']['title'], 0, 255 ),
			'start_date' => (string) $data['proposed']['start_date'],
			'reason'     => isset( $data['reason'] ) ? $data['reason'] : '',
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( $entry ) {
			if ( $entry['proposed'] && $entry['proposed'] !== $row['proposed'] ) {
				$row['previous'] = $entry['proposed'];
			}
			$wpdb->update( CTI_Install::table( 'entries' ), $row, array( 'id' => (int) $entry['id'] ) );
			return (int) $entry['id'];
		}
		$wpdb->insert(
			CTI_Install::table( 'entries' ),
			array_merge(
				$row,
				array(
					'post_id'    => (int) $post_id,
					'idx'        => (int) $idx,
					'previous'   => '',
					'candidates' => '',
					'created_at' => current_time( 'mysql', true ),
				)
			)
		);
		return (int) $wpdb->insert_id;
	}

	private static function set_entry( $id, $data ) {
		global $wpdb;
		foreach ( array( 'proposed', 'previous', 'candidates' ) as $json ) {
			if ( isset( $data[ $json ] ) && ! is_string( $data[ $json ] ) ) {
				$data[ $json ] = wp_json_encode( $data[ $json ] );
			}
		}
		if ( isset( $data['title'] ) ) {
			$data['title'] = mb_substr( (string) $data['title'], 0, 255 );
		}
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( CTI_Install::table( 'entries' ), $data, array( 'id' => (int) $id ) );
	}

	private static function set_post( $id, $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( CTI_Install::table( 'posts' ), $data, array( 'id' => (int) $id ) );
	}

	/** Daily: forget old update IDs; drop old non-event and ignored posts. */
	public static function cleanup() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CTI_Install::table( 'updates' ) . ' WHERE received_at < %s', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		$old = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . CTI_Install::table( 'posts' ) . " WHERE status IN ('not_event','ignored') AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		foreach ( $old as $id ) {
			$wpdb->delete( CTI_Install::table( 'log' ), array( 'post_id' => (int) $id ) );
			$wpdb->delete( CTI_Install::table( 'entries' ), array( 'post_id' => (int) $id ) );
			$wpdb->delete( CTI_Install::table( 'posts' ), array( 'id' => (int) $id ) );
		}
	}
}
