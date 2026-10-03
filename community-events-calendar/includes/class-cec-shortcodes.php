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

	/**
	 * The public month view: queries published events overlapping the
	 * month and hands them to CEC_Month_Grid, which owns all layout. Kept
	 * as the public entry point (shortcode, AJAX navigation, editor
	 * preview all call this) so nothing outside this class changed when
	 * the layout was split out in 1.26.0.
	 */
	public static function render_month_html( $month, $year, $preview_post_id = 0 ) {
		$first_of_month = mktime( 0, 0, 0, $month, 1, $year );

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

		$items = array();
		foreach ( $all_posts as $p ) {
			$data = CEC_Event_Helper::data( $p->ID );
			if ( $p->ID === $preview_post_id ) {
				// A pending/draft post's real permalink wouldn't resolve
				// for anyone yet — WP's own preview link (capability- and
				// nonce-checked) is what actually works here.
				$data['permalink'] = get_preview_post_link( $preview_post_id );
			}
			$items[] = CEC_Month_Grid::item_from_event_data( $data, $preview_post_id && $data['id'] === $preview_post_id );
		}

		return CEC_Month_Grid::render( $month, $year, $items );
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
