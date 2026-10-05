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
				// Start time alone ("7:00 pm EDT"), for the phone day list's
				// chip; empty when the event shows no time (all-day, varies,
				// midnight-to-11:59 pm). date_range carries the full date.
				'time'       => in_array( $data['display_time_mode'], array( 'exact', 'start_only' ), true ) && $data['start_ts']
					? trim( date_i18n( 'g:i a', $data['start_ts'] ) . ' ' . $data['timezone_abbr'] )
					: '',
				'date_range' => $data['date_range_display'],
				'location'   => $where['label'],
				'host'       => $data['host_org_name'],
				'badge'      => $badge['label'],
				'badgeClass' => $badge['class'],
				/**
				 * A short social label for this event for the current
				 * viewer (1.28.0), e.g. "2 people you follow are going"
				 * from Community Member Planning. Empty by default.
				 *
				 * @param string $label
				 * @param int    $event_id
				 */
				'social'     => (string) apply_filters( 'cec_event_social_label', '', (int) $data['id'] ),
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
				'id'         => $item['id'],
				'title'      => $item['title'],
				'permalink'  => $item['url'],
				'date_range' => $item['detail']['date_range'],
				'location'   => $item['detail']['location'],
				'host'       => $item['detail']['host'],
				'badge'      => $item['detail']['badge'],
				'badgeClass' => $item['detail']['badgeClass'],
				'social'     => isset( $item['detail']['social'] ) ? $item['detail']['social'] : '',
				'startDate'  => $true_start_date,
				'endDate'    => $item['end_ts'] ? date_i18n( 'Y-m-d', max( $item['end_ts'], $item['start_ts'] ) ) : $true_start_date,
				'time'       => isset( $item['detail']['time'] ) ? $item['detail']['time'] : '',
				'status'     => $item['status'],
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
					<div class="cec-cal-cell<?php echo ! $day_num ? ' cec-cal-empty' : ''; ?><?php echo $is_today ? ' cec-cal-today' : ''; ?>"<?php echo $date_str ? ' data-date="' . esc_attr( $date_str ) . '"' : ''; ?> style="grid-column:<?php echo (int) ( $col + 1 ); ?>;grid-row:1 / <?php echo (int) $row_end; ?>;"></div>
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
					<div class="cec-cal-daycontent" data-date="<?php echo esc_attr( $date_str ); ?>" style="grid-column:<?php echo (int) ( $col + 1 ); ?>;grid-row:1;">
						<button type="button" class="cec-cal-daynum" data-date="<?php echo esc_attr( $date_str ); ?>"><?php echo esc_html( $day_num ); ?></button>
						<div class="cec-cal-events">
							<?php foreach ( array_slice( $events, 0, 2 ) as $ev ) : ?>
								<a class="cec-cal-pill<?php echo 'scheduled' !== $ev['status'] ? ' cec-cal-chip-' . esc_attr( $ev['status'] ) : ''; ?><?php echo $ev['highlight'] ? ' cec-cal-preview-highlight' : ''; ?>" href="<?php echo esc_url( $ev['url'] ); ?>" title="<?php echo esc_attr( $ev['title'] . ( ! empty( $ev['detail']['social'] ) ? ' · ' . $ev['detail']['social'] : '' ) ); ?>"><?php echo ! empty( $ev['detail']['social'] ) ? '<span class="cec-cal-social-dot" aria-hidden="true"></span>' : ''; ?><?php echo esc_html( $ev['title'] ); ?><?php echo ! empty( $ev['detail']['social'] ) ? '<span class="screen-reader-text"> · ' . esc_html( $ev['detail']['social'] ) . '</span>' : ''; ?></a>
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
							<span class="cec-cal-event-title"><?php echo ! empty( $ev['detail']['social'] ) ? '<span class="cec-cal-social-dot" aria-hidden="true"></span>' : ''; ?><?php echo esc_html( $ev['title'] ); ?><?php echo ! empty( $ev['detail']['social'] ) ? '<span class="screen-reader-text"> · ' . esc_html( $ev['detail']['social'] ) . '</span>' : ''; ?></span>
						</a>
						<?php
					endforeach;
				endforeach;
				?>
			</div>
		<?php endforeach; ?>
		</div>
		<?php
		// Small screens (below 700px, CSS only) get a compact month grid
		// with a tapped-day list instead of the grid above: the whole month
		// on one screen, rather than the 1.24.0 day-by-day agenda, which ran
		// to ~22 phone screens for October 2026's overlapping weekends.
		ksort( $events_by_date );
		$selected_date = $is_current_month ? date_i18n( 'Y-m-d' ) : ( $events_by_date ? key( $events_by_date ) : date_i18n( 'Y-m-d', $first_of_month ) );
		echo self::render_phone( $weeks, $month, $year, $multi_day_events, $events_by_date, $selected_date, $is_current_month ? date_i18n( 'Y-m-d' ) : '', $first_weekday ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		?>
		<script type="application/json" class="cec-cal-data"><?php echo wp_json_encode( self::plain_payload( $events_by_date ) ); // phpcs:ignore ?></script>
		<div class="cec-cal-day-detail" hidden>
			<h3 class="cec-cal-day-detail-heading"></h3>
			<ul class="cec-cal-day-detail-list"></ul>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * The click-a-date panel (frontend.js) inserts these strings with
	 * jQuery .text(), so they must be plain characters: WordPress's display
	 * title ("Kinks &amp; Drinks &#8211; …") would otherwise show its codes
	 * literally. Only the JSON copy is converted; the server-rendered agenda
	 * above keeps escaping the original strings exactly as before.
	 */
	private static function plain_payload( $events_by_date ) {
		foreach ( $events_by_date as $date => $day_events ) {
			foreach ( $day_events as $i => $ev ) {
				foreach ( array( 'title', 'date_range', 'location', 'host', 'badge', 'social' ) as $key ) {
					if ( isset( $ev[ $key ] ) && is_string( $ev[ $key ] ) ) {
						$events_by_date[ $date ][ $i ][ $key ] = CEC_Event_Helper::plain_text( $ev[ $key ] );
					}
				}
			}
		}
		return $events_by_date;
	}

	/**
	 * Phone month grid: day buttons with up to 3 dots for single-day
	 * items, thin bars for multi-day items (max PHONE_MAX_LANES per week
	 * row), "+N" for the rest, and the selected day's list below. Shown
	 * below 700px by CSS. frontend.js re-renders the list on tap from the
	 * same .cec-cal-data payload; this server copy is the no-JS state.
	 */
	const PHONE_MAX_LANES = 2;
	const PHONE_MAX_DOTS  = 3;

	private static function render_phone( $weeks, $month, $year, $multi_day_events, $events_by_date, $selected_date, $today_date, $first_weekday ) {
		$ref_sunday = strtotime( '2023-01-01' ); // a known Sunday, as in render().
		ob_start();
		?>
		<div class="cec-cal-phone">
			<div class="cec-cal-pdow" aria-hidden="true">
				<?php for ( $i = 0; $i < 7; $i++ ) : ?>
					<span><?php echo esc_html( date_i18n( 'D', $ref_sunday + ( ( $first_weekday + $i ) % 7 ) * DAY_IN_SECONDS ) ); ?></span>
				<?php endfor; ?>
			</div>
			<?php
			foreach ( $weeks as $week_slots ) :
				$week_dates = array();
				foreach ( $week_slots as $col => $day_num ) {
					$week_dates[ $col ] = $day_num ? date_i18n( 'Y-m-d', mktime( 0, 0, 0, $month, $day_num, $year ) ) : null;
				}

				// Same greedy lane packing as the desktop grid, capped lower.
				$bars       = array();
				$lane_ends  = array();
				$barred_ids = array();
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
					if ( $lane >= self::PHONE_MAX_LANES ) {
						continue;
					}
					$lane_ends[ $lane ] = $col_end;
					$barred_ids[]       = $ev['id'];
					$bars[]             = array(
						'ev'        => $ev,
						'lane'      => $lane,
						'col_start' => $col_start,
						'col_end'   => $col_end,
						'cont_from' => $week_dates[ $col_start ] !== $ev['span_start_date'],
						'cont_to'   => $week_dates[ $col_end ] !== $ev['span_end_date'],
					);
				}
				?>
				<div class="cec-cal-pweek">
					<?php
					foreach ( $week_slots as $col => $day_num ) :
						if ( ! $day_num ) :
							?>
							<span class="cec-cal-pday cec-cal-pday-empty" aria-hidden="true"></span>
							<?php
							continue;
						endif;
						$date    = $week_dates[ $col ];
						$day_evs = isset( $events_by_date[ $date ] ) ? $events_by_date[ $date ] : array();
						$singles = array();
						$hidden  = 0;
						foreach ( $day_evs as $dev ) {
							if ( $dev['startDate'] === $dev['endDate'] ) {
								$singles[] = $dev;
							} elseif ( ! in_array( $dev['id'], $barred_ids, true ) ) {
								$hidden++;
							}
						}
						$more  = $hidden + max( 0, count( $singles ) - self::PHONE_MAX_DOTS );
						$class = 'cec-cal-pday';
						$class .= $date === $today_date ? ' cec-cal-pday-today' : '';
						$class .= $date === $selected_date ? ' cec-cal-pday-selected' : '';
						/* translators: 1: date, e.g. "Friday, October 9", 2: number of events */
						$label = sprintf( _n( '%1$s, %2$d event', '%1$s, %2$d events', count( $day_evs ), 'cec' ), date_i18n( 'l, F j', strtotime( $date ) ), count( $day_evs ) );
						?>
						<button type="button" class="<?php echo esc_attr( $class ); ?>" data-date="<?php echo esc_attr( $date ); ?>" aria-label="<?php echo esc_attr( $label ); ?>" aria-pressed="<?php echo $date === $selected_date ? 'true' : 'false'; ?>">
							<span class="cec-cal-pday-num"><?php echo esc_html( $day_num ); ?></span>
							<span class="cec-cal-pday-dots">
								<?php foreach ( array_slice( $singles, 0, self::PHONE_MAX_DOTS ) as $dev ) : ?>
									<span class="cec-cal-pdot<?php echo 'scheduled' !== $dev['status'] ? ' cec-cal-pdot-' . esc_attr( $dev['status'] ) : ''; ?>"></span>
								<?php endforeach; ?>
							</span>
							<span class="cec-cal-pday-more"><?php echo $more ? esc_html( '+' . $more ) : ''; ?></span>
						</button>
					<?php endforeach; ?>
					<span class="cec-cal-pbars" aria-hidden="true">
						<?php
						foreach ( $bars as $bar ) :
							$class  = 'cec-cal-pbar cec-cal-pbar-lane' . (int) $bar['lane'];
							$class .= $bar['cont_from'] ? ' cec-cal-pbar-cont-from' : '';
							$class .= $bar['cont_to'] ? ' cec-cal-pbar-cont-to' : '';
							$class .= 'scheduled' !== $bar['ev']['status'] ? ' cec-cal-pbar-' . $bar['ev']['status'] : '';
							$class .= $bar['ev']['highlight'] ? ' cec-cal-preview-highlight' : '';
							?>
							<span class="<?php echo esc_attr( $class ); ?>" style="--cec-col-start:<?php echo (int) $bar['col_start']; ?>;--cec-col-span:<?php echo (int) ( $bar['col_end'] - $bar['col_start'] + 1 ); ?>;"></span>
						<?php endforeach; ?>
					</span>
				</div>
			<?php endforeach; ?>
			<div class="cec-cal-pday-list" aria-live="polite">
				<?php echo self::render_phone_day_list( $events_by_date, $selected_date ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * The tapped day's events: what starts that day first, then what's
	 * still running ("day 3 of 12"), so a one-night event isn't buried
	 * under a long festival. Markup mirrored by phoneDayList() in
	 * frontend.js; keep the two in step.
	 */
	private static function render_phone_day_list( $events_by_date, $date ) {
		$day_evs = isset( $events_by_date[ $date ] ) ? $events_by_date[ $date ] : array();
		$starts  = array();
		$running = array();
		foreach ( $day_evs as $ev ) {
			if ( $ev['startDate'] < $date ) {
				$running[] = $ev;
			} else {
				$starts[] = $ev;
			}
		}
		ob_start();
		?>
		<h4 class="cec-cal-pday-heading"><?php echo esc_html( date_i18n( 'l, F j', strtotime( $date ) ) ); ?></h4>
		<?php if ( ! $day_evs ) : ?>
			<p class="cec-cal-pday-empty-note"><?php esc_html_e( 'Nothing on this day. Tap another day, or use the arrows for other months.', 'cec' ); ?></p>
		<?php else : ?>
			<p class="cec-cal-pday-count"><?php echo esc_html( sprintf( _n( '%d event', '%d events', count( $day_evs ), 'cec' ), count( $day_evs ) ) ); ?></p>
			<?php if ( $starts && $running ) : ?>
				<p class="cec-cal-pday-sub"><?php esc_html_e( 'Starting this day', 'cec' ); ?></p>
			<?php endif; ?>
			<?php
			foreach ( $starts as $ev ) {
				echo self::render_phone_card( $ev, $date ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
			<?php if ( $running ) : ?>
				<p class="cec-cal-pday-sub"><?php esc_html_e( 'Still running', 'cec' ); ?></p>
				<?php
				foreach ( $running as $ev ) {
					echo self::render_phone_card( $ev, $date ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			<?php endif; ?>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	private static function render_phone_card( $ev, $date ) {
		$single = $ev['startDate'] === $ev['endDate'];
		$chip   = $single ? ( $ev['time'] ? $ev['time'] : __( 'All day', 'cec' ) ) : $ev['date_range'];
		$of     = '';
		if ( ! $single && $ev['startDate'] < $date ) {
			$day_n = (int) round( ( strtotime( $date ) - strtotime( $ev['startDate'] ) ) / DAY_IN_SECONDS ) + 1;
			$total = (int) round( ( strtotime( $ev['endDate'] ) - strtotime( $ev['startDate'] ) ) / DAY_IN_SECONDS ) + 1;
			/* translators: 1: day number within the event, 2: total days */
			$of = sprintf( __( 'day %1$d of %2$d', 'cec' ), $day_n, $total );
		}
		$where = array_filter( array( $ev['location'], $ev['badge'] ) );
		ob_start();
		?>
		<a class="cec-cal-pcard<?php echo $single ? ' cec-cal-pcard-single' : ''; ?><?php echo 'scheduled' !== $ev['status'] ? ' cec-cal-pcard-' . esc_attr( $ev['status'] ) : ''; ?>" href="<?php echo esc_url( $ev['permalink'] ); ?>">
			<span class="cec-cal-pcard-bar"></span>
			<span class="cec-cal-pcard-body">
				<span class="cec-cal-pcard-title"><?php echo esc_html( $ev['title'] ); ?></span>
				<span class="cec-cal-pcard-when"><span class="cec-cal-pcard-chip"><?php echo esc_html( $chip ); ?></span><?php echo $of ? '<span class="cec-cal-pcard-of">' . esc_html( $of ) . '</span>' : ''; ?></span>
				<?php if ( $where ) : ?><span class="cec-cal-pcard-where"><?php echo esc_html( implode( ' · ', $where ) ); ?></span><?php endif; ?>
				<?php if ( ! empty( $ev['social'] ) ) : ?><span class="cec-cal-pcard-social"><?php echo esc_html( $ev['social'] ); ?></span><?php endif; ?>
			</span>
			<span class="cec-cal-pcard-chev" aria-hidden="true">&rsaquo;</span>
		</a>
		<?php
		return ob_get_clean();
	}
}
