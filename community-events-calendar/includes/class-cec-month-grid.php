<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The month-view layout engine (range bars, week-continuation markers,
 * "+N more", selected-day panel data, small-screen agenda), separated from
 * the query that feeds it as of 1.26.0.
 *
 * It renders whatever items it's given and runs no query of its own, so a
 * second plugin (the member-planning plugin's personal calendars and task
 * layer) can reuse the exact same component with its own permission-checked
 * data, per the project brief: "reuse the public calendar's range-bar,
 * continuation-marker, and date-detail components, but use a separate
 * private query and permission check." The public calendar's own query
 * stays in CEC_Shortcodes::render_month_html(), which builds items with
 * item_from_event_data() and calls render().
 *
 * An item is an array:
 *   id         int|string  Used only for comparison/markup.
 *   title      string
 *   url        string      Where the bar/pill/detail link goes.
 *   start_ts   int         Wall-clock timestamp, same convention as
 *                          CEC_Event_Helper::data()['start_ts'].
 *   end_ts     int         Same, or 0 for no end.
 *   status     string      'scheduled', or a suffix for a
 *                          cec-cal-chip-{status} class (e.g. 'cancelled').
 *   highlight  bool        Adds cec-cal-preview-highlight.
 *   detail     array       date_range, location, host, badge, badgeClass —
 *                          shown in the day-detail panel and the agenda.
 */
class CEC_Month_Grid {

	/**
	 * Converts one CEC_Event_Helper::data() array into a grid item.
	 */
	public static function item_from_event_data( $data, $highlight = false ) {
		$badge = CEC_Event_Helper::admission_badge( $data );
		$where = CEC_Event_Helper::location_display( $data );
		return array(
			'id'        => $data['id'],
			'title'     => $data['title'],
			'url'       => $data['permalink'],
			'start_ts'  => $data['start_ts'],
			'end_ts'    => $data['end_ts'],
			'status'    => $data['event_status'],
			'highlight' => (bool) $highlight,
			'detail'    => array(
				'date_range' => $data['date_range_display'],
				'location'   => $where['label'],
				'host'       => $data['host_org_name'],
				'badge'      => $badge['label'],
				'badgeClass' => $badge['class'],
			),
		);
	}

	/**
	 * @param int   $month
	 * @param int   $year
	 * @param array $items   Grid items (see class comment).
	 * @param array $options first_weekday (0=Sun..6=Sat; defaults to the
	 *                       Events → Settings value), max_lanes (defaults
	 *                       to CEC_Shortcodes::MAX_BAR_LANES).
	 */
	public static function render( $month, $year, $items, $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'first_weekday' => (int) CEC_Admin_Settings::get( 'first_weekday' ),
				'max_lanes'     => CEC_Shortcodes::MAX_BAR_LANES,
			)
		);

		$first_of_month = mktime( 0, 0, 0, $month, 1, $year );
		$days_in_month  = (int) date( 't', $first_of_month );
		$month_label    = date_i18n( 'F Y', $first_of_month );

		// Which weekday (0=Sun..6=Sat) the grid's FIRST COLUMN represents —
		// admin-configurable, defaulting to Sunday — versus $start_weekday,
		// the real day-of-week day 1 of this month falls on. $lead_blanks
		// below is their offset: how many empty leading slots day 1 needs
		// so it lands in the right column under either convention.
		$first_weekday = (int) $options['first_weekday'];
		$start_weekday = (int) date( 'w', $first_of_month );
		$lead_blanks   = ( $start_weekday - $first_weekday + 7 ) % 7;

		$month_start_ts = $first_of_month;
		$month_end_ts   = mktime( 23, 59, 59, $month, $days_in_month, $year );

		// Single-day items keep a small per-day preview pill; a multi-day
		// item instead becomes one bar spanning the days it covers, so it
		// no longer also repeats on every one of those days. Either way,
		// every item is also added to $events_by_date — a complete,
		// uncapped day => items map used for the click-a-date detail panel
		// below the grid, so nothing is ever really hidden, just not all
		// shown as its own bar/pill at once.
		$by_day           = array();
		$multi_day_events = array();
		$events_by_date   = array();

		$add_to_date = function ( $date, $item, $true_start_date ) use ( &$events_by_date ) {
			$events_by_date[ $date ][] = array(
				'title'      => $item['title'],
				'permalink'  => $item['url'],
				'date_range' => $item['detail']['date_range'],
				'location'   => $item['detail']['location'],
				'host'       => $item['detail']['host'],
				'badge'      => $item['detail']['badge'],
				'badgeClass' => $item['detail']['badgeClass'],
				'startDate'  => $true_start_date,
			);
		};

		foreach ( $items as $item ) {
			if ( ! $item['start_ts'] ) {
				continue;
			}

			$true_start_date = date_i18n( 'Y-m-d', $item['start_ts'] );

			$span_start = max( $item['start_ts'], $month_start_ts );
			$span_end   = $item['end_ts'] ? min( $item['end_ts'], $month_end_ts ) : $span_start;
			$span_end   = max( $span_end, $span_start );

			$start_date = date_i18n( 'Y-m-d', $span_start );
			$end_date   = date_i18n( 'Y-m-d', $span_end );

			if ( $start_date === $end_date ) {
				$by_day[ (int) date_i18n( 'j', $span_start ) ][] = $item;
				$add_to_date( $start_date, $item, $true_start_date );
			} else {
				$item['span_start_date'] = $start_date;
				$item['span_end_date']   = $end_date;
				$multi_day_events[]      = $item;

				$cursor = strtotime( $start_date );
				$end_ts = strtotime( $end_date );
				while ( $cursor <= $end_ts ) {
					$add_to_date( date( 'Y-m-d', $cursor ), $item, $true_start_date ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions
					$cursor = strtotime( '+1 day', $cursor );
				}
			}
		}
		// Sorted so lane assignment below packs items starting earlier
		// into the lowest lane first, same greedy-interval-scheduling
		// convention as every other calendar view in this project.
		usort(
			$multi_day_events,
			function ( $a, $b ) {
				return $a['span_start_date'] <=> $b['span_start_date'];
			}
		);

		// Weeks are built as their own row (a list of up to 7 day numbers,
		// null for a blank leading/trailing slot) so a multi-day item's
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
			// Which column (0-6) each multi-day item occupies in THIS week
			// specifically, clipped to the real calendar dates this week's
			// non-empty slots actually represent.
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
				if ( $lane >= $options['max_lanes'] ) {
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
								<a class="cec-cal-pill<?php echo 'scheduled' !== $ev['status'] ? ' cec-cal-chip-' . esc_attr( $ev['status'] ) : ''; ?><?php echo $ev['highlight'] ? ' cec-cal-preview-highlight' : ''; ?>" href="<?php echo esc_url( $ev['url'] ); ?>" title="<?php echo esc_attr( $ev['title'] ); ?>"><?php echo esc_html( $ev['title'] ); ?></a>
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
						if ( 'scheduled' !== $ev['status'] ) {
							$class .= ' cec-cal-chip-' . $ev['status'];
						}
						// Rounded only at a TRUE edge; an edge that's
						// actually a mid-item clip (continuing from the
						// previous week, or into the next) instead gets a
						// pointed "keeps going" notch — real title text is
						// shown on every segment either way, not just at
						// the true start, matching how a real calendar
						// renders a multi-week event.
						$class .= $bar['is_true_start'] ? ' cec-cal-bar-start' : ' cec-cal-bar-continues-from';
						$class .= $bar['is_true_end'] ? ' cec-cal-bar-end' : ' cec-cal-bar-continues-to';
						if ( $ev['highlight'] ) {
							$class .= ' cec-cal-preview-highlight';
						}
						?>
						<a class="<?php echo esc_attr( $class ); ?>"
							href="<?php echo esc_url( $ev['url'] ); ?>"
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
		// least one item, each showing its complete date range (never just
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
}
