<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recurring events are implemented as real child posts ("occurrences"), one
 * per date, each tagged with _cec_recurrence_parent_id. This keeps every
 * existing calendar/list/RSVP query working unmodified — occurrences are
 * just normal cec_event posts. Editing the series root regenerates all
 * future occurrences; approving/rejecting/deleting the root cascades to
 * them via the transition_post_status hook below.
 */
class CEC_Recurrence {

	const MAX_OCCURRENCES = 104; // safety cap regardless of rule/until (e.g. ~2 years weekly).

	public static function is_occurrence( $post_id ) {
		return (bool) get_post_meta( $post_id, '_cec_recurrence_parent_id', true );
	}

	/**
	 * Shared "which week(s) / which weekday(s)" checkbox UI for the Monthly
	 * (specific weekday) rule, used identically by the submission form, My
	 * Events, the manager dashboard, and guest editing — one place to keep
	 * these five forms consistent instead of duplicating the markup.
	 */
	public static function render_monthly_weekday_fields( $selected_ordinals = array(), $selected_weekdays = array() ) {
		$ordinals = array(
			1 => __( '1st', 'cec' ),
			2 => __( '2nd', 'cec' ),
			3 => __( '3rd', 'cec' ),
			4 => __( '4th', 'cec' ),
			0 => __( 'Last', 'cec' ),
		);
		$weekdays = array(
			0 => __( 'Sun', 'cec' ),
			1 => __( 'Mon', 'cec' ),
			2 => __( 'Tue', 'cec' ),
			3 => __( 'Wed', 'cec' ),
			4 => __( 'Thu', 'cec' ),
			5 => __( 'Fri', 'cec' ),
			6 => __( 'Sat', 'cec' ),
		);
		ob_start();
		?>
		<div class="cec-field-row cec-recurrence-monthly-weekday-fields">
			<div class="cec-field">
				<label><?php esc_html_e( 'Which week(s)', 'cec' ); ?></label>
				<?php foreach ( $ordinals as $value => $label ) : ?>
					<label class="cec-checkbox"><input type="checkbox" name="cec_recurrence_ordinals[]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $selected_ordinals, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
			</div>
			<div class="cec-field">
				<label><?php esc_html_e( 'Which day(s) of the week', 'cec' ); ?></label>
				<?php foreach ( $weekdays as $value => $label ) : ?>
					<label class="cec-checkbox"><input type="checkbox" name="cec_recurrence_weekdays[]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $selected_weekdays, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
			</div>
		</div>
		<p class="description"><?php esc_html_e( 'Pick one or more of each — e.g. check "2nd" + "4th" and "Wednesday" to repeat on the 2nd and 4th Wednesday of every month.', 'cec' ); ?></p>
		<?php
		return ob_get_clean();
	}

	/**
	 * Reads the ordinals/weekdays a form submitted, sanitized to valid
	 * ranges. Returns empty arrays if the rule isn't monthly_weekday or
	 * nothing was checked (callers fall back to auto-derive-from-start-date).
	 */
	public static function sanitize_posted_ordinals_weekdays() {
		$ordinals = isset( $_POST['cec_recurrence_ordinals'] ) ? array_map( 'intval', (array) $_POST['cec_recurrence_ordinals'] ) : array();
		$weekdays = isset( $_POST['cec_recurrence_weekdays'] ) ? array_map( 'intval', (array) $_POST['cec_recurrence_weekdays'] ) : array();
		$ordinals = array_values( array_intersect( $ordinals, array( 0, 1, 2, 3, 4 ) ) );
		$weekdays = array_values( array_intersect( $weekdays, array( 0, 1, 2, 3, 4, 5, 6 ) ) );
		return array( $ordinals, $weekdays );
	}

	public static function get_parent_id( $post_id ) {
		return (int) get_post_meta( $post_id, '_cec_recurrence_parent_id', true );
	}

	/**
	 * Call this after saving a series-root event, instead of calling
	 * generate_occurrences() directly. It only does the destructive
	 * delete-and-recreate of future dates when the rule/until/start actually
	 * changed; a plain content edit (fixing a typo, swapping the photo)
	 * instead syncs in place so any postpone/cancel flags set via "Manage
	 * Dates" on upcoming occurrences survive.
	 */
	public static function update_series( $parent_id ) {
		if ( self::is_occurrence( $parent_id ) ) {
			return;
		}

		$rule = get_post_meta( $parent_id, '_cec_recurrence_rule', true );
		if ( ! $rule || 'none' === $rule ) {
			self::delete_future_occurrences( $parent_id );
			delete_post_meta( $parent_id, '_cec_recurrence_signature' );
			return;
		}

		$signature = md5(
			$rule . '|' .
			get_post_meta( $parent_id, '_cec_start', true ) . '|' .
			get_post_meta( $parent_id, '_cec_recurrence_until', true ) . '|' .
			get_post_meta( $parent_id, '_cec_recurrence_ordinals', true ) . '|' .
			get_post_meta( $parent_id, '_cec_recurrence_weekdays', true )
		);
		$previous  = get_post_meta( $parent_id, '_cec_recurrence_signature', true );

		if ( $signature !== $previous || ! self::has_occurrences( $parent_id ) ) {
			self::generate_occurrences( $parent_id );
			update_post_meta( $parent_id, '_cec_recurrence_signature', $signature );
		} else {
			self::sync_content_to_occurrences( $parent_id );
		}
	}

	private static function has_occurrences( $parent_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => array( 'publish', 'pending', 'draft', 'cec_in_review' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_cec_recurrence_parent_id',
				'meta_value'     => $parent_id,
			)
		);
		return ! empty( $ids );
	}

	/**
	 * Refreshes title/description/terms/photo/status on existing occurrence
	 * posts without touching their dates or their individual event status —
	 * used when the series root's content changed but its schedule didn't.
	 */
	private static function sync_content_to_occurrences( $parent_id ) {
		$parent   = get_post( $parent_id );
		$children = get_posts(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => array( 'publish', 'pending', 'draft', 'cec_in_review' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_cec_recurrence_parent_id',
				'meta_value'     => $parent_id,
			)
		);
		$skip_meta = array(
			'_cec_recurrence_rule',
			'_cec_recurrence_until',
			'_cec_recurrence_parent_id',
			'_cec_recurrence_signature',
			'_cec_event_status',
			'_cec_event_status_note',
			'_cec_start',
			'_cec_end',
		);

		foreach ( $children as $child_id ) {
			wp_update_post(
				array(
					'ID'           => $child_id,
					'post_title'   => $parent->post_title,
					'post_excerpt' => $parent->post_excerpt,
					'post_content' => $parent->post_content,
					'post_status'  => $parent->post_status,
				)
			);

			foreach ( get_post_meta( $parent_id ) as $key => $values ) {
				if ( in_array( $key, $skip_meta, true ) ) {
					continue;
				}
				update_post_meta( $child_id, $key, maybe_unserialize( $values[0] ) );
			}

			foreach ( array( 'cec_event_type', 'cec_partner_org', 'cec_venue' ) as $tax ) {
				$term_ids = wp_get_post_terms( $parent_id, $tax, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $term_ids ) ) {
					wp_set_post_terms( $child_id, $term_ids, $tax, false );
				}
			}

			$thumb_id = get_post_thumbnail_id( $parent_id );
			if ( $thumb_id ) {
				set_post_thumbnail( $child_id, $thumb_id );
			} else {
				delete_post_thumbnail( $child_id );
			}
		}
	}

	/**
	 * Regenerates all future occurrence posts for a series root based on its
	 * current _cec_recurrence_rule / _cec_recurrence_until / _cec_start meta.
	 * Past occurrences (start already elapsed) are left untouched so RSVP
	 * history and any completed-event state survives an edit to the series.
	 * Called by update_series() — call that instead unless you specifically
	 * need the destructive delete-and-recreate every time.
	 */
	public static function generate_occurrences( $parent_id ) {
		if ( self::is_occurrence( $parent_id ) ) {
			return; // occurrences don't have their own series.
		}

		self::delete_future_occurrences( $parent_id );

		$rule  = get_post_meta( $parent_id, '_cec_recurrence_rule', true );
		$until = get_post_meta( $parent_id, '_cec_recurrence_until', true );
		$start = get_post_meta( $parent_id, '_cec_start', true );

		if ( ! $rule || 'none' === $rule || ! $until || ! $start ) {
			return;
		}

		$until_ts = strtotime( $until . ' 23:59:59' );
		$start_ts = strtotime( $start );
		if ( ! $until_ts || ! $start_ts || $until_ts <= $start_ts ) {
			return;
		}

		$end       = get_post_meta( $parent_id, '_cec_end', true );
		$duration  = $end ? ( strtotime( $end ) - $start_ts ) : 0;
		$dates     = self::compute_dates( $rule, $start_ts, $until_ts, self::get_ordinals( $parent_id, $start_ts ), self::get_weekdays( $parent_id, $start_ts ) );
		$parent    = get_post( $parent_id );

		foreach ( $dates as $occurrence_start_ts ) {
			$new_id = wp_insert_post(
				array(
					'post_type'    => 'cec_event',
					'post_title'   => $parent->post_title,
					'post_excerpt' => $parent->post_excerpt,
					'post_content' => $parent->post_content,
					'post_status'  => $parent->post_status,
					'post_author'  => $parent->post_author,
				)
			);
			if ( is_wp_error( $new_id ) || ! $new_id ) {
				continue;
			}

			$skip_meta = array( '_cec_recurrence_rule', '_cec_recurrence_until', '_cec_recurrence_ordinals', '_cec_recurrence_weekdays', '_cec_recurrence_signature', '_cec_recurrence_parent_id', '_cec_event_status', '_cec_event_status_note' );
			foreach ( get_post_meta( $parent_id ) as $key => $values ) {
				if ( in_array( $key, $skip_meta, true ) ) {
					continue;
				}
				update_post_meta( $new_id, $key, maybe_unserialize( $values[0] ) );
			}

			update_post_meta( $new_id, '_cec_start', date( 'Y-m-d\TH:i', $occurrence_start_ts ) );
			if ( $duration > 0 ) {
				update_post_meta( $new_id, '_cec_end', date( 'Y-m-d\TH:i', $occurrence_start_ts + $duration ) );
			}
			update_post_meta( $new_id, '_cec_recurrence_parent_id', $parent_id );
			update_post_meta( $new_id, '_cec_event_status', 'scheduled' );
			delete_post_meta( $new_id, '_cec_recurrence_rule' );
			delete_post_meta( $new_id, '_cec_recurrence_until' );

			foreach ( array( 'cec_event_type', 'cec_partner_org', 'cec_venue' ) as $tax ) {
				$term_ids = wp_get_post_terms( $parent_id, $tax, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $term_ids ) ) {
					wp_set_post_terms( $new_id, $term_ids, $tax, false );
				}
			}

			$thumb_id = get_post_thumbnail_id( $parent_id );
			if ( $thumb_id ) {
				set_post_thumbnail( $new_id, $thumb_id );
			}
		}
	}

	private static function delete_future_occurrences( $parent_id ) {
		$children = get_posts(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => array( 'publish', 'pending', 'draft', 'cec_in_review' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => '_cec_recurrence_parent_id', 'value' => $parent_id ),
					array( 'key' => '_cec_start', 'value' => date( 'Y-m-d\TH:i', current_time( 'timestamp' ) ), 'compare' => '>=' ),
				),
			)
		);
		foreach ( $children as $child_id ) {
			wp_delete_post( $child_id, true );
		}
	}

	/**
	 * Ordinal 1-4 = 1st/2nd/3rd/4th occurrence of that weekday in the month;
	 * 0 = "last" (whichever week that falls in). Falls back to whatever the
	 * start date itself implies if nothing was explicitly selected, so a
	 * series created before this option existed keeps behaving the same way.
	 */
	public static function get_ordinals( $parent_id, $start_ts ) {
		$raw = get_post_meta( $parent_id, '_cec_recurrence_ordinals', true );
		if ( '' === $raw ) {
			return array( (int) ceil( date( 'j', $start_ts ) / 7 ) );
		}
		return array_map( 'intval', explode( ',', $raw ) );
	}

	public static function get_weekdays( $parent_id, $start_ts ) {
		$raw = get_post_meta( $parent_id, '_cec_recurrence_weekdays', true );
		if ( '' === $raw ) {
			return array( (int) date( 'w', $start_ts ) );
		}
		return array_map( 'intval', explode( ',', $raw ) );
	}

	private static function compute_dates( $rule, $start_ts, $until_ts, $ordinals = array(), $weekdays = array() ) {
		if ( 'monthly_weekday' === $rule ) {
			return self::compute_monthly_weekday_dates( $start_ts, $until_ts, $ordinals, $weekdays );
		}

		$dates   = array();
		$current = $start_ts;
		$count   = 0;

		while ( $count < self::MAX_OCCURRENCES ) {
			$current = self::advance( $rule, $current, $start_ts, $count + 1 );
			if ( ! $current || $current > $until_ts ) {
				break;
			}
			$dates[] = $current;
			$count++;
		}

		return $dates;
	}

	private static function advance( $rule, $current_ts, $start_ts, $step ) {
		switch ( $rule ) {
			case 'weekly':
				return strtotime( '+' . $step . ' weeks', $start_ts );

			case 'monthly_date':
				return strtotime( '+' . $step . ' months', $start_ts );

			default:
				return false;
		}
	}

	/**
	 * Every (ordinal, weekday) pair selected is generated for every month in
	 * range and merged into one chronological list — this is what makes
	 * "2nd AND 4th Wednesday" a single series instead of needing two.
	 */
	private static function compute_monthly_weekday_dates( $start_ts, $until_ts, $ordinals, $weekdays ) {
		if ( empty( $ordinals ) ) {
			$ordinals = array( (int) ceil( date( 'j', $start_ts ) / 7 ) );
		}
		if ( empty( $weekdays ) ) {
			$weekdays = array( (int) date( 'w', $start_ts ) );
		}

		$dates = array();
		$hour  = (int) date( 'H', $start_ts );
		$min   = (int) date( 'i', $start_ts );
		$month = (int) date( 'n', $start_ts );
		$year  = (int) date( 'Y', $start_ts );

		// Loop by calendar month rather than by occurrence count, since a
		// month can easily produce zero matches (e.g. no "5th Friday").
		$months_checked = 0;
		while ( count( $dates ) < self::MAX_OCCURRENCES && $months_checked < ( self::MAX_OCCURRENCES * 2 ) ) {
			$month_start = mktime( 0, 0, 0, $month, 1, $year );
			if ( $month_start > $until_ts ) {
				break;
			}

			foreach ( $ordinals as $ordinal ) {
				foreach ( $weekdays as $weekday ) {
					$ts = self::nth_weekday_of_month( $year, $month, $weekday, $ordinal, $hour, $min );
					if ( $ts && $ts > $start_ts && $ts <= $until_ts ) {
						$dates[] = $ts;
					}
				}
			}

			$month++;
			if ( $month > 12 ) {
				$month = 1;
				$year++;
			}
			$months_checked++;
		}

		sort( $dates );
		return array_slice( array_unique( $dates ), 0, self::MAX_OCCURRENCES );
	}

	/**
	 * $ordinal: 1-4 for 1st..4th, or 0 for "last".
	 */
	private static function nth_weekday_of_month( $year, $month, $weekday, $ordinal, $hour, $minute ) {
		$days_in_month = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );

		if ( 0 === $ordinal ) {
			for ( $day = $days_in_month; $day >= 1; $day-- ) {
				if ( (int) date( 'w', mktime( 0, 0, 0, $month, $day, $year ) ) === $weekday ) {
					return mktime( $hour, $minute, 0, $month, $day, $year );
				}
			}
			return false;
		}

		$first_weekday = (int) date( 'w', mktime( 0, 0, 0, $month, 1, $year ) );
		$offset        = ( $weekday - $first_weekday + 7 ) % 7;
		$day           = 1 + $offset + ( ( $ordinal - 1 ) * 7 );
		if ( $day > $days_in_month ) {
			return false; // e.g. "5th Tuesday" doesn't exist this month — skip it.
		}
		return mktime( $hour, $minute, 0, $month, $day, $year );
	}

	/**
	 * Keeps occurrence posts in lockstep with their series root: approving,
	 * rejecting, or trashing the root does the same to every occurrence
	 * instead of making an admin process each date individually.
	 */
	public static function on_status_transition( $new_status, $old_status, $post ) {
		if ( ! is_object( $post ) || 'cec_event' !== $post->post_type || $new_status === $old_status ) {
			return;
		}
		if ( self::is_occurrence( $post->ID ) ) {
			return;
		}
		self::cascade_status( $post->ID, $new_status );
	}

	public static function cascade_status( $parent_id, $new_status ) {
		$children = get_posts(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => array( 'publish', 'pending', 'draft', 'cec_in_review', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_cec_recurrence_parent_id',
				'meta_value'     => $parent_id,
			)
		);
		foreach ( $children as $child_id ) {
			if ( get_post_status( $child_id ) === $new_status ) {
				continue;
			}
			if ( 'trash' === $new_status ) {
				wp_trash_post( $child_id );
			} else {
				wp_update_post( array( 'ID' => $child_id, 'post_status' => $new_status ) );
			}
		}
	}

	/**
	 * Keeps generated occurrence posts out of the wp-admin Events list table
	 * — they're managed through their series root, not individually.
	 */
	public static function exclude_occurrences_from_admin_list( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'cec_event' !== $query->get( 'post_type' ) ) {
			return;
		}
		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = array( 'key' => '_cec_recurrence_parent_id', 'compare' => 'NOT EXISTS' );
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Shared "manage individual dates" table used by both the manager
	 * dashboard and the submitter's own My Events page.
	 */
	public static function render_occurrences_table( $parent_id, $base_url ) {
		$children = get_posts(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => array( 'publish', 'pending', 'draft', 'cec_in_review' ),
				'posts_per_page' => -1,
				'meta_query'     => array(
					'relation'      => 'AND',
					'parent_clause' => array( 'key' => '_cec_recurrence_parent_id', 'value' => $parent_id ),
					'start_clause'  => array( 'key' => '_cec_start' ),
				),
				'orderby'        => array( 'start_clause' => 'ASC' ),
			)
		);

		ob_start();
		?>
		<a class="cec-back-link" href="<?php echo esc_url( remove_query_arg( array( 'cec_view', 'cec_series' ), $base_url ) ); ?>">&larr; <?php esc_html_e( 'Back to list', 'cec' ); ?></a>
		<p class="cec-series-note"><?php esc_html_e( 'Postpone, cancel, or remove a single date here without affecting the rest of the series. Editing the series itself (from the main list) regenerates all upcoming dates and clears any per-date changes made here.', 'cec' ); ?></p>
		<?php if ( empty( $children ) ) : ?>
			<p><?php esc_html_e( 'No upcoming dates.', 'cec' ); ?></p>
		<?php else : ?>
		<table class="cec-dash-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'cec' ); ?></th>
					<th><?php esc_html_e( 'Status', 'cec' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'cec' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $children as $child ) :
					$event_status = get_post_meta( $child->ID, '_cec_event_status', true );
					$event_status = $event_status ? $event_status : 'scheduled';
					$start        = get_post_meta( $child->ID, '_cec_start', true );
					?>
					<tr data-event-row="<?php echo esc_attr( $child->ID ); ?>">
						<td><?php echo esc_html( $start ? date_i18n( 'D, M j, Y g:i a', strtotime( $start ) ) : '' ); ?></td>
						<td><span class="cec-status-badge cec-status-<?php echo esc_attr( $event_status ); ?>"><?php echo esc_html( ucfirst( $event_status ) ); ?></span></td>
						<td class="cec-dash-actions">
							<?php if ( 'scheduled' !== $event_status ) : ?>
								<button type="button" class="cec-btn cec-btn-small cec-dash-status" data-status="scheduled" data-id="<?php echo esc_attr( $child->ID ); ?>"><?php esc_html_e( 'Reset', 'cec' ); ?></button>
							<?php else : ?>
								<button type="button" class="cec-btn cec-btn-small cec-btn-outline cec-dash-status" data-status="postponed" data-id="<?php echo esc_attr( $child->ID ); ?>"><?php esc_html_e( 'Postpone', 'cec' ); ?></button>
								<button type="button" class="cec-btn cec-btn-small cec-btn-outline cec-dash-status" data-status="cancelled" data-id="<?php echo esc_attr( $child->ID ); ?>"><?php esc_html_e( 'Cancel', 'cec' ); ?></button>
							<?php endif; ?>
							<button type="button" class="cec-btn cec-btn-small cec-btn-danger cec-dash-action" data-action="delete" data-id="<?php echo esc_attr( $child->ID ); ?>"><?php esc_html_e( 'Remove Date', 'cec' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif;
		return ob_get_clean();
	}
}
