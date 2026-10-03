<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_Shortcodes {

	// How many multi-day bars can stack in one calendar week before the
	// rest collapse into a "+N more" note — a real community calendar
	// can have far more simultaneous multi-day events than this view has
	// vertical room for (confirmed directly: a site with 9+ overlapping
	// multi-day events pushed every day cell below the fold, making the
	// calendar unusable).
	const MAX_BAR_LANES = 3;

	public static function calendar( $atts ) {
		$atts = shortcode_atts( array( 'month' => '', 'year' => '' ), $atts );
		$month = $atts['month'] ? absint( $atts['month'] ) : (int) date_i18n( 'n' );
		$year  = $atts['year'] ? absint( $atts['year'] ) : (int) date_i18n( 'Y' );

		ob_start();
		echo '<div class="cec-calendar-wrap" data-month="' . esc_attr( $month ) . '" data-year="' . esc_attr( $year ) . '" aria-live="polite" aria-atomic="true">';
		echo CEC_Feeds::subscribe_links_html(); // phpcs:ignore
		echo self::render_month_html( $month, $year ); // phpcs:ignore
		echo '</div>';
		return ob_get_clean();
	}

	public static function render_month_html( $month, $year, $preview_post_id = 0 ) {
		$first_of_month = mktime( 0, 0, 0, $month, 1, $year );
		$days_in_month  = (int) date( 't', $first_of_month );
		$month_label    = date_i18n( 'F Y', $first_of_month );

		// Which weekday (0=Sun..6=Sat) the grid's FIRST COLUMN represents —
		// admin-configurable, defaulting to Sunday — versus $start_weekday,
		// the real day-of-week day 1 of this month falls on. $lead_blanks
		// below is their offset: how many empty leading slots day 1 needs
		// so it lands in the right column under either convention.
		$first_weekday = (int) CEC_Admin_Settings::get( 'first_weekday' );
		$start_weekday = (int) date( 'w', $first_of_month );
		$lead_blanks   = ( $start_weekday - $first_weekday + 7 ) % 7;

		$range_start = date( 'Y-m-01 00:00:00', $first_of_month );
		$range_end   = date( 'Y-m-t 23:59:59', $first_of_month );
		$range_start_val = str_replace( ' ', 'T', substr( $range_start, 0, 16 ) );
		$range_end_val   = str_replace( ' ', 'T', substr( $range_end, 0, 16 ) );

		// Catches events that overlap this month even when they don't start in it
		// (e.g. a multi-day event that started last month but runs into this one),
		// not just ones whose start falls inside the range.
		$query = new WP_Query(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_cec_start',
						'value'   => $range_end_val,
						'compare' => '<=',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_cec_end',
							'value'   => $range_start_val,
							'compare' => '>=',
							'type'    => 'DATETIME',
						),
						array(
							'relation' => 'AND',
							array(
								'key'     => '_cec_end',
								'compare' => 'NOT EXISTS',
							),
							array(
								'key'     => '_cec_start',
								'value'   => $range_start_val,
								'compare' => '>=',
								'type'    => 'DATETIME',
							),
						),
					),
				),
			)
		);

		// Editor preview support (Phase 1d: "a preview of ... the month
		// view" before publishing) — splices one specific not-yet-published
		// post into this month's real results, regardless of its own
		// status, so an editor reviewing a pending submission can see
		// exactly how it will sit alongside the real published events for
		// its month. Never exposed publicly: every caller of this method
		// that can pass $preview_post_id is itself already capability-gated
		// (see CEC_Meta_Boxes/CEC_Frontend_Dashboard), and the spliced-in
		// post's own data uses a WP preview link rather than its real
		// permalink, since the latter wouldn't resolve for anyone yet.
		$preview_posts = array();
		if ( $preview_post_id && ! wp_list_filter( $query->posts, array( 'ID' => $preview_post_id ) ) ) {
			$preview_post = get_post( $preview_post_id );
			if ( $preview_post && 'cec_event' === $preview_post->post_type ) {
				$preview_posts = array( $preview_post );
			}
		}
		$all_posts = array_merge( $query->posts, $preview_posts );

		$month_start_ts = $first_of_month;
		$month_end_ts   = mktime( 23, 59, 59, $month, $days_in_month, $year );

		// Single-day events keep a small per-day preview pill; a
		// multi-day event instead becomes one bar spanning the days it
		// covers, so it no longer also repeats on every one of those
		// days. Either way, every event is also added to $events_by_date
		// — a complete, uncapped day => events map used for the
		// click-a-date detail panel below the grid, so nothing is ever
		// really hidden, just not all shown as its own bar/pill at once.
		$by_day           = array();
		$multi_day_events = array();
		$events_by_date   = array();

		$add_to_date = function ( $date, $data, $true_start_date ) use ( &$events_by_date ) {
			$badge                    = CEC_Event_Helper::admission_badge( $data );
			$where                    = CEC_Event_Helper::location_display( $data );
			$events_by_date[ $date ][] = array(
				'title'      => $data['title'],
				'permalink'  => $data['permalink'],
				'date_range' => $data['date_range_display'],
				'location'   => $where['label'],
				'host'       => $data['host_org_name'],
				'badge'      => $badge['label'],
				'badgeClass' => $badge['class'],
				'startDate'  => $true_start_date,
			);
		};

		foreach ( $all_posts as $p ) {
			$data = CEC_Event_Helper::data( $p->ID );
			if ( ! $data['start_ts'] ) {
				continue;
			}
			if ( $p->ID === $preview_post_id ) {
				// A pending/draft post's real permalink wouldn't resolve
				// for anyone yet — WP's own preview link (capability- and
				// nonce-checked) is what actually works here.
				$data['permalink'] = get_preview_post_link( $preview_post_id );
			}

			$true_start_date = date_i18n( 'Y-m-d', $data['start_ts'] );

			$span_start = max( $data['start_ts'], $month_start_ts );
			$span_end   = $data['end_ts'] ? min( $data['end_ts'], $month_end_ts ) : $span_start;
			$span_end   = max( $span_end, $span_start );

			$start_date = date_i18n( 'Y-m-d', $span_start );
			$end_date   = date_i18n( 'Y-m-d', $span_end );

			if ( $start_date === $end_date ) {
				$by_day[ (int) date_i18n( 'j', $span_start ) ][] = $data;
				$add_to_date( $start_date, $data, $true_start_date );
			} else {
				$data['span_start_date'] = $start_date;
				$data['span_end_date']   = $end_date;
				$multi_day_events[]      = $data;

				$cursor = strtotime( $start_date );
				$end_ts = strtotime( $end_date );
				while ( $cursor <= $end_ts ) {
					$add_to_date( date( 'Y-m-d', $cursor ), $data, $true_start_date ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions
					$cursor = strtotime( '+1 day', $cursor );
				}
			}
		}
		// Sorted so lane assignment below packs events starting earlier
		// into the lowest lane first, same greedy-interval-scheduling
		// convention as every other calendar view in this project.
		usort(
			$multi_day_events,
			function ( $a, $b ) {
				return $a['span_start_date'] <=> $b['span_start_date'];
			}
		);

		// Weeks are built as their own row (a list of up to 7 day numbers,
		// null for a blank leading/trailing slot) so a multi-day event's
		// bar can span grid-column X/Y within one real calendar week,
		// rather than trying to bridge across 42 independently-boxed day
		// cells laid out in one flat grid.
		$slots = array();
		for ( $i = 0; $i < $lead_blanks; $i++ ) {
			$slots[] = null;
		}
		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$slots[] = $day;
		}
		while ( 0 !== count( $slots ) % 7 ) {
			$slots[] = null;
		}
		$weeks = array_chunk( $slots, 7 );

		$prev_ts = mktime( 0, 0, 0, $month - 1, 1, $year );
		$next_ts = mktime( 0, 0, 0, $month + 1, 1, $year );

		$today             = (int) date_i18n( 'j' );
		$is_current_month  = ( (int) date_i18n( 'n' ) === $month && (int) date_i18n( 'Y' ) === $year );
		$week_index        = 0;

		ob_start();
		?>
		<div class="cec-cal-legend">
			<span class="cec-cal-legend-item"><span class="cec-cal-legend-swatch cec-cal-legend-bar"></span><?php esc_html_e( 'Multiday event — bar shows full date span', 'cec' ); ?></span>
			<span class="cec-cal-legend-item"><span class="cec-cal-legend-swatch cec-cal-legend-pill"></span><?php esc_html_e( 'Single-day event', 'cec' ); ?></span>
			<span class="cec-cal-legend-item cec-cal-legend-note"><?php esc_html_e( 'Arrows mark events continuing into the next week.', 'cec' ); ?></span>
		</div>
		<div class="cec-cal-header">
			<button type="button" class="cec-cal-nav cec-cal-prev" data-month="<?php echo esc_attr( date( 'n', $prev_ts ) ); ?>" data-year="<?php echo esc_attr( date( 'Y', $prev_ts ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Previous month, %s', 'cec' ), date_i18n( 'F Y', $prev_ts ) ) ); ?>">&larr;</button>
			<h3><?php echo esc_html( $month_label ); ?></h3>
			<button type="button" class="cec-cal-nav cec-cal-next" data-month="<?php echo esc_attr( date( 'n', $next_ts ) ); ?>" data-year="<?php echo esc_attr( date( 'Y', $next_ts ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Next month, %s', 'cec' ), date_i18n( 'F Y', $next_ts ) ) ); ?>">&rarr;</button>
			<button type="button" class="cec-cal-today-btn" data-month="<?php echo esc_attr( date_i18n( 'n' ) ); ?>" data-year="<?php echo esc_attr( date_i18n( 'Y' ) ); ?>" data-date="<?php echo esc_attr( date_i18n( 'Y-m-d' ) ); ?>"<?php echo $is_current_month ? '' : ' data-jump="1"'; ?>><?php esc_html_e( 'Today', 'cec' ); ?></button>
		</div>
		<div class="cec-cal-grid-wrap">
		<div class="cec-cal-grid cec-cal-dow">
			<?php
			// 2023-01-01 is a known Sunday — used only as a stable reference
			// point to get a real, locale-aware weekday abbreviation for
			// each column, rotated to start at the configured first weekday,
			// rather than hardcoding untranslated English day names.
			$ref_sunday = strtotime( '2023-01-01' );
			for ( $i = 0; $i < 7; $i++ ) :
				$weekday_index = ( $first_weekday + $i ) % 7;
				?>
				<div class="cec-cal-dow-cell"><?php echo esc_html( date_i18n( 'D', $ref_sunday + $weekday_index * DAY_IN_SECONDS ) ); ?></div>
			<?php endfor; ?>
		</div>
		<?php

		foreach ( $weeks as $week_slots ) :
			$week_index++;
			// Which column (0-6) each multi-day event occupies in THIS
			// week specifically, clipped to the real calendar dates this
			// week's non-empty slots actually represent.
			$week_dates = array();
			foreach ( $week_slots as $col => $day_num ) {
				$week_dates[ $col ] = $day_num ? date_i18n( 'Y-m-d', mktime( 0, 0, 0, $month, $day_num, $year ) ) : null;
			}

			$bars_by_lane = array();
			$lane_ends    = array();
			$day_overflow = array();
			foreach ( $multi_day_events as $ev ) {
				$col_start = null;
				$col_end   = null;
				foreach ( $week_dates as $col => $date ) {
					if ( null === $date || $date < $ev['span_start_date'] || $date > $ev['span_end_date'] ) {
						continue;
					}
					if ( null === $col_start ) {
						$col_start = $col;
					}
					$col_end = $col;
				}
				if ( null === $col_start ) {
					continue;
				}
				$lane = 0;
				while ( isset( $lane_ends[ $lane ] ) && $lane_ends[ $lane ] >= $col_start ) {
					$lane++;
				}
				if ( $lane >= self::MAX_BAR_LANES ) {
					// Still fully listed in $events_by_date above — capped
					// here only means it doesn't get its own bar/lane this
					// week. Each day it touches gets an overflow count so
					// that day's "+N more" opens the complete detail panel.
					for ( $c = $col_start; $c <= $col_end; $c++ ) {
						if ( $week_dates[ $c ] ) {
							$day_overflow[ $week_dates[ $c ] ] = ( isset( $day_overflow[ $week_dates[ $c ] ] ) ? $day_overflow[ $week_dates[ $c ] ] : 0 ) + 1;
						}
					}
					continue;
				}
				$lane_ends[ $lane ]      = $col_end;
				$bars_by_lane[ $lane ][] = array(
					'ev'            => $ev,
					'col_start'     => $col_start,
					'col_end'       => $col_end,
					'is_true_start' => $week_dates[ $col_start ] === $ev['span_start_date'],
					'is_true_end'   => $week_dates[ $col_end ] === $ev['span_end_date'],
				);
			}
			ksort( $bars_by_lane );
			// The explicit end line for "spans every row this week,"
			// instead of grid-row's -1 shorthand — this grid's rows are
			// all implicit (sized by content via grid-auto-rows, never
			// declared through grid-template-rows), and a negative line
			// index is only reliably anchored to the *explicit* grid.
			// Confirmed directly: -1 left the background/border box
			// covering only row 1, with the bars below rendering in
			// uncovered implicit rows — exactly the "events floating
			// outside the day boxes" bug this replaces.
			$row_end = count( $bars_by_lane ) + 2;
			?>
			<div class="cec-cal-week">
				<?php
				// Three layers share the same grid cells, in deliberate
				// paint order (later = on top): (1) a full-height
				// background/border box per day, so the week reads as a
				// continuous bordered grid; (2) the day number + any
				// single-day pills, pinned to row 1 (the top); (3) bars,
				// starting at row 2 and stacking downward — directly
				// below the day number, inside the same box, the way a
				// real month-view calendar reads, rather than floating
				// above the day grid in their own unboxed strip.
				foreach ( $week_slots as $col => $day_num ) :
					$date_str = $day_num ? $week_dates[ $col ] : null;
					$is_today = $date_str && $is_current_month && $day_num === $today;
					?>
					<div class="cec-cal-cell<?php echo ! $day_num ? ' cec-cal-empty' : ''; ?><?php echo $is_today ? ' cec-cal-today' : ''; ?>" style="grid-column:<?php echo (int) ( $col + 1 ); ?>;grid-row:1 / <?php echo (int) $row_end; ?>;"></div>
				<?php endforeach; ?>

				<?php
				foreach ( $week_slots as $col => $day_num ) :
					if ( ! $day_num ) {
						continue;
					}
					$date_str = $week_dates[ $col ];
					$events   = isset( $by_day[ $day_num ] ) ? $by_day[ $day_num ] : array();
					$overflow = ( isset( $day_overflow[ $date_str ] ) ? $day_overflow[ $date_str ] : 0 ) + max( 0, count( $events ) - 2 );
					?>
					<div class="cec-cal-daycontent" style="grid-column:<?php echo (int) ( $col + 1 ); ?>;grid-row:1;">
						<button type="button" class="cec-cal-daynum" data-date="<?php echo esc_attr( $date_str ); ?>"><?php echo esc_html( $day_num ); ?></button>
						<div class="cec-cal-events">
							<?php foreach ( array_slice( $events, 0, 2 ) as $ev ) : ?>
								<a class="cec-cal-pill<?php echo 'scheduled' !== $ev['event_status'] ? ' cec-cal-chip-' . esc_attr( $ev['event_status'] ) : ''; ?><?php echo $preview_post_id && $ev['id'] === $preview_post_id ? ' cec-cal-preview-highlight' : ''; ?>" href="<?php echo esc_url( $ev['permalink'] ); ?>" title="<?php echo esc_attr( $ev['title'] ); ?>"><?php echo esc_html( $ev['title'] ); ?></a>
							<?php endforeach; ?>
							<?php if ( $overflow > 0 ) : ?>
								<button type="button" class="cec-cal-more cec-cal-daymore" data-date="<?php echo esc_attr( $date_str ); ?>">
									<?php
									printf(
										/* translators: %d: number of additional events this day */
										esc_html( _n( '+%d more', '+%d more', $overflow, 'cec' ) ),
										(int) $overflow
									);
									?>
								</button>
							<?php endif; ?>
						</div>
					</div>
					<?php
				endforeach;

				foreach ( $bars_by_lane as $lane => $bars ) :
					foreach ( $bars as $bar ) :
						$ev    = $bar['ev'];
						$class = 'cec-cal-bar';
						if ( 'scheduled' !== $ev['event_status'] ) {
							$class .= ' cec-cal-chip-' . $ev['event_status'];
						}
						// Rounded only at a TRUE edge; an edge that's
						// actually a mid-event clip (continuing from the
						// previous week, or into the next) instead gets a
						// pointed "keeps going" notch — real title text is
						// shown on every segment either way, not just at
						// the true start, matching how a real calendar
						// renders a multi-week event.
						$class .= $bar['is_true_start'] ? ' cec-cal-bar-start' : ' cec-cal-bar-continues-from';
						$class .= $bar['is_true_end'] ? ' cec-cal-bar-end' : ' cec-cal-bar-continues-to';
						if ( $preview_post_id && $ev['id'] === $preview_post_id ) {
							$class .= ' cec-cal-preview-highlight';
						}
						?>
						<a class="<?php echo esc_attr( $class ); ?>"
							href="<?php echo esc_url( $ev['permalink'] ); ?>"
							title="<?php echo esc_attr( $ev['title'] ); ?>"
							style="grid-column:<?php echo (int) ( $bar['col_start'] + 1 ); ?> / <?php echo (int) ( $bar['col_end'] + 2 ); ?>;grid-row:<?php echo (int) ( $lane + 2 ); ?>;">
							<span class="cec-cal-event-title"><?php echo esc_html( $ev['title'] ); ?></span>
						</a>
						<?php
					endforeach;
				endforeach;
				?>
			</div>
		<?php endforeach; ?>
		</div>
		<?php
		// Small screens get a chronological agenda instead of the grid above
		// (hidden/shown purely via CSS at the same breakpoint the rest of
		// this plugin already uses) — every date in the month that has at
		// least one event, each showing its complete date range (never just
		// "today's slice" of a multi-day span), reusing the exact same
		// day-detail item markup/classes the click-a-date panel already
		// builds in JS, so no new visual language is needed.
		ksort( $events_by_date );
		?>
		<div class="cec-cal-agenda">
			<?php
			$agenda_has_events = false;
			foreach ( $events_by_date as $date => $day_events ) :
				if ( empty( $day_events ) ) {
					continue;
				}
				$agenda_has_events = true;
				?>
				<div class="cec-cal-agenda-day">
					<h4 class="cec-cal-agenda-date"><?php echo esc_html( date_i18n( 'l, F j', strtotime( $date ) ) ); ?></h4>
					<ul class="cec-cal-day-detail-list">
						<?php foreach ( $day_events as $ev ) : ?>
							<li class="cec-cal-day-detail-item">
								<span class="cec-cal-day-detail-bar"></span>
								<div class="cec-cal-day-detail-body">
									<a class="cec-cal-day-detail-title" href="<?php echo esc_url( $ev['permalink'] ); ?>"><?php echo esc_html( $ev['title'] ); ?></a>
									<span class="cec-cal-day-detail-meta">
										<?php
										$meta = array( $ev['date_range'] );
										if ( $ev['startDate'] < $date ) {
											/* translators: %s: a date range, e.g. "Oct 9–13" */
											$meta[0] = sprintf( __( '%s (in progress)', 'cec' ), $ev['date_range'] );
										}
										if ( $ev['location'] ) {
											$meta[] = $ev['location'];
										}
										if ( $ev['host'] ) {
											/* translators: %s: host organization name */
											$meta[] = sprintf( __( 'Hosted by %s', 'cec' ), $ev['host'] );
										}
										echo esc_html( implode( ' · ', $meta ) );
										?>
									</span>
								</div>
								<?php if ( $ev['badge'] ) : ?><span class="cec-badge <?php echo esc_attr( $ev['badgeClass'] ); ?>"><?php echo esc_html( $ev['badge'] ); ?></span><?php endif; ?>
								<a class="cec-btn cec-btn-small" href="<?php echo esc_url( $ev['permalink'] ); ?>"><?php esc_html_e( 'Event details', 'cec' ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
			<?php if ( ! $agenda_has_events ) : ?>
				<p class="cec-no-events"><?php esc_html_e( 'No events this month.', 'cec' ); ?></p>
			<?php endif; ?>
		</div>
		<script type="application/json" class="cec-cal-data"><?php echo wp_json_encode( $events_by_date ); // phpcs:ignore ?></script>
		<div class="cec-cal-day-detail" hidden>
			<h3 class="cec-cal-day-detail-heading"></h3>
			<ul class="cec-cal-day-detail-list"></ul>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function list_grid( $atts ) {
		$atts = shortcode_atts(
			array(
				'view'  => 'list',
				'count' => 12,
			),
			$atts
		);

		// Reading the filter/sort/view state from the URL's own query
		// params (when present) — not just the shortcode's own defaults —
		// is what makes a copied/bookmarked/shared link reproduce the same
		// result set, per the brief. Falls back to each control's existing
		// default the same way the AJAX handler already does, so a plain
		// URL with no params behaves exactly as before.
		$view         = isset( $_GET['cec_view'] ) && 'list' === $_GET['cec_view'] ? 'list' : ( isset( $_GET['cec_view'] ) ? 'grid' : $atts['view'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event_type   = isset( $_GET['cec_event_type'] ) ? sanitize_title( wp_unslash( $_GET['cec_event_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$venue        = isset( $_GET['cec_venue'] ) ? sanitize_title( wp_unslash( $_GET['cec_venue'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_range   = isset( $_GET['cec_date_range'] ) ? sanitize_key( wp_unslash( $_GET['cec_date_range'] ) ) : 'all_upcoming'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $date_range, array( 'this_week', 'this_month', 'next_3_months', 'all_upcoming' ), true ) ) {
			$date_range = 'all_upcoming';
		}
		$sort = isset( $_GET['cec_sort'] ) && 'date_desc' === $_GET['cec_sort'] ? 'date_desc' : 'date_asc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$initial_filters = array(
			'cec_event_type' => $event_type,
			'cec_venue'      => $venue,
			'cec_date_range' => $date_range,
			'cec_sort'       => $sort,
		);

		$event_types  = get_terms( array( 'taxonomy' => 'cec_event_type', 'hide_empty' => true ) );
		$venues       = get_terms( array( 'taxonomy' => 'cec_venue', 'hide_empty' => true ) );

		ob_start();
		?>
		<div class="cec-events-wrap" data-view="<?php echo esc_attr( $view ); ?>" data-count="<?php echo esc_attr( $atts['count'] ); ?>" data-sort="<?php echo esc_attr( $sort ); ?>">
			<?php echo CEC_Feeds::subscribe_links_html(); // phpcs:ignore ?>
			<div class="cec-filters">
				<select class="cec-filter" data-filter="cec_event_type" aria-label="<?php esc_attr_e( 'Filter by event type', 'cec' ); ?>">
					<option value=""><?php esc_html_e( 'All Event Types', 'cec' ); ?></option>
					<?php foreach ( $event_types as $t ) : ?>
						<option value="<?php echo esc_attr( $t->slug ); ?>" <?php selected( $event_type, $t->slug ); ?>><?php echo esc_html( $t->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<select class="cec-filter" data-filter="cec_venue" aria-label="<?php esc_attr_e( 'Filter by venue', 'cec' ); ?>">
					<option value=""><?php esc_html_e( 'All Venues', 'cec' ); ?></option>
					<?php foreach ( $venues as $t ) : ?>
						<option value="<?php echo esc_attr( $t->slug ); ?>" <?php selected( $venue, $t->slug ); ?>><?php echo esc_html( $t->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<select class="cec-filter" data-filter="cec_date_range" aria-label="<?php esc_attr_e( 'Filter by date range', 'cec' ); ?>">
					<option value="this_week" <?php selected( $date_range, 'this_week' ); ?>><?php esc_html_e( 'This Week', 'cec' ); ?></option>
					<option value="this_month" <?php selected( $date_range, 'this_month' ); ?>><?php esc_html_e( 'This Month', 'cec' ); ?></option>
					<option value="next_3_months" <?php selected( $date_range, 'next_3_months' ); ?>><?php esc_html_e( 'Next 3 Months', 'cec' ); ?></option>
					<option value="all_upcoming" <?php selected( $date_range, 'all_upcoming' ); ?>><?php esc_html_e( 'All Upcoming', 'cec' ); ?></option>
				</select>
				<button type="button" class="cec-filter-submit"><?php esc_html_e( 'Filter', 'cec' ); ?></button>
				<button type="button" class="cec-sort-toggle" data-sort="<?php echo esc_attr( $sort ); ?>" aria-label="<?php esc_attr_e( 'Toggle sort order', 'cec' ); ?>">
					<span class="cec-sort-toggle-icon"><?php echo 'date_desc' === $sort ? '&darr;' : '&uarr;'; ?></span> <span class="cec-sort-toggle-label"><?php echo 'date_desc' === $sort ? esc_html__( 'Latest First', 'cec' ) : esc_html__( 'Soonest First', 'cec' ); ?></span>
				</button>
				<div class="cec-view-toggle">
					<button type="button" class="cec-view-btn<?php echo 'grid' === $view ? ' active' : ''; ?>" data-view="grid" aria-pressed="<?php echo 'grid' === $view ? 'true' : 'false'; ?>"><?php esc_html_e( 'Grid', 'cec' ); ?></button>
					<button type="button" class="cec-view-btn<?php echo 'list' === $view ? ' active' : ''; ?>" data-view="list" aria-pressed="<?php echo 'list' === $view ? 'true' : 'false'; ?>"><?php esc_html_e( 'List', 'cec' ); ?></button>
				</div>
			</div>
			<div class="cec-events-results" aria-live="polite" aria-atomic="true">
				<?php echo self::render_events_html( $initial_filters, $view, (int) $atts['count'] ); // phpcs:ignore ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function render_events_html( $filters, $view, $count = 12, $paged = 1 ) {
		$now_val = date( 'Y-m-d\TH:i', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp

		$args = array(
			'post_type'      => 'cec_event',
			'post_status'    => 'publish',
			'posts_per_page' => $count,
			'paged'          => $paged,
			'meta_key'       => '_cec_start',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => array(
				// "Past" is end-date-aware (falls back to start when there's
				// no end), per the brief — not the cruder "started within the
				// last day" approximation this used before. An event with no
				// end is "not past" as long as it hasn't started yet.
				array(
					'relation' => 'OR',
					array(
						'key'     => '_cec_end',
						'value'   => $now_val,
						'compare' => '>=',
					),
					array(
						'relation' => 'AND',
						array(
							'key'     => '_cec_end',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_cec_start',
							'value'   => $now_val,
							'compare' => '>=',
						),
					),
				),
			),
			'tax_query'      => array( 'relation' => 'AND' ),
		);

		if ( ! empty( $filters['cec_sort'] ) && 'date_desc' === $filters['cec_sort'] ) {
			$args['order'] = 'DESC';
			unset( $args['meta_query'][0] );
		}

		// The quick-filter bar only ever offers a small set of date-range
		// presets (not an open picker) — "All Upcoming" is the one that
		// matches today's existing unbounded behavior, so it adds no
		// upper-bound clause at all.
		if ( ! empty( $filters['cec_date_range'] ) ) {
			$range_end = CEC_Event_Helper::date_range_preset_end( $filters['cec_date_range'] );
			if ( $range_end ) {
				$args['meta_query'][] = array(
					'key'     => '_cec_start',
					'value'   => date( 'Y-m-d\TH:i', $range_end ),
					'compare' => '<=',
				);
			}
		}

		foreach ( array( 'cec_event_type', 'cec_partner_org', 'cec_venue' ) as $tax ) {
			if ( ! empty( $filters[ $tax ] ) ) {
				$args['tax_query'][] = array(
					'taxonomy' => $tax,
					'field'    => 'slug',
					'terms'    => sanitize_title( $filters[ $tax ] ),
				);
			}
		}

		$query = new WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return '<p class="cec-no-events">' . esc_html__( 'No upcoming events found.', 'cec' ) . '</p>';
		}

		ob_start();
		echo '<div class="cec-events-list cec-view-' . esc_attr( $view ) . '">';
		if ( 'list' === $view ) {
			self::render_month_grouped_rows( $query->posts );
		} else {
			foreach ( $query->posts as $p ) {
				$data = CEC_Event_Helper::data( $p->ID );
				include CEC_DIR . 'templates/parts/event-card.php';
			}
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * List view groups events under a "October 2026"-style heading per the
	 * Upcoming list's own default sort (start date) — grouping follows
	 * whatever order the query already returned rather than re-sorting, so
	 * it still makes sense with the sort toggle flipped to Latest First.
	 */
	private static function render_month_grouped_rows( $posts ) {
		$current_group = null;
		foreach ( $posts as $p ) {
			$data  = CEC_Event_Helper::data( $p->ID );
			$group = $data['start_ts'] ? date_i18n( 'F Y', $data['start_ts'] ) : '';
			if ( $group !== $current_group ) {
				echo '<h2 class="cec-month-heading">' . esc_html( $group ) . '</h2>';
				$current_group = $group;
			}
			include CEC_DIR . 'templates/parts/event-row.php';
		}
	}

	public static function upcoming( $atts ) {
		$atts = shortcode_atts(
			array( 'count' => CEC_Admin_Settings::get( 'upcoming_count' ) ),
			$atts
		);

		$query = new WP_Query(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => 'publish',
				'posts_per_page' => (int) $atts['count'],
				'meta_key'       => '_cec_start',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => '_cec_start',
						'value'   => date( 'Y-m-d\TH:i', current_time( 'timestamp' ) ),
						'compare' => '>=',
					),
				),
			)
		);

		if ( ! $query->have_posts() ) {
			return '<p class="cec-no-events">' . esc_html__( 'No upcoming events.', 'cec' ) . '</p>';
		}

		ob_start();
		?>
		<div class="cec-upcoming-wrap">
			<button type="button" class="cec-scroll-btn cec-scroll-left" aria-label="<?php esc_attr_e( 'Scroll left', 'cec' ); ?>">&larr;</button>
			<div class="cec-upcoming-scroll">
				<?php foreach ( $query->posts as $p ) :
					$data = CEC_Event_Helper::data( $p->ID );
					?>
					<a class="cec-upcoming-card" href="<?php echo esc_url( $data['permalink'] ); ?>">
						<?php if ( $data['thumb_card'] ) : ?>
							<img src="<?php echo esc_url( $data['thumb_card'] ); ?>" alt="<?php echo esc_attr( $data['photo_alt'] ? $data['photo_alt'] : $data['title'] ); ?>" />
						<?php endif; ?>
						<?php $badge = CEC_Event_Helper::admission_badge( $data ); ?>
						<div class="cec-upcoming-body">
							<span class="cec-upcoming-date"><?php echo esc_html( $data['date_range_display'] ); ?></span>
							<span class="cec-upcoming-title"><?php echo esc_html( $data['title'] ); ?></span>
							<span class="cec-badge <?php echo esc_attr( $badge['class'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></span>
						</div>
					</a>
				<?php endforeach; ?>
			</div>
			<button type="button" class="cec-scroll-btn cec-scroll-right" aria-label="<?php esc_attr_e( 'Scroll right', 'cec' ); ?>">&rarr;</button>
		</div>
		<?php
		return ob_get_clean();
	}
}
