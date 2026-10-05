<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_RSVP {

	const TABLE_WAITLIST = 'cec_rsvp_waitlist';
	const ADMIN_NONCE    = 'cec_rsvp_admin';

	public static function create_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . CEC_TABLE_RSVP;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			name VARCHAR(190) NOT NULL,
			email VARCHAR(190) NOT NULL,
			guests SMALLINT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY event_id (event_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function create_waitlist_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . self::TABLE_WAITLIST;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			name VARCHAR(190) NOT NULL,
			email VARCHAR(190) NOT NULL,
			guests SMALLINT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY event_id (event_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function count_for_event( $event_id ) {
		global $wpdb;
		$table = $wpdb->prefix . CEC_TABLE_RSVP;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(guests + 1), 0) FROM {$table} WHERE event_id = %d", $event_id ) );
	}

	public static function handle_ajax() {
		check_ajax_referer( 'cec_frontend', 'nonce' );

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$guests   = isset( $_POST['guests'] ) ? absint( $_POST['guests'] ) : 0;

		if ( ! $event_id || 'cec_event' !== get_post_type( $event_id ) || 'publish' !== get_post_status( $event_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This event is not available for RSVPs.', 'cec' ) ) );
		}
		if ( empty( $name ) || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a valid name and email.', 'cec' ) ) );
		}

		global $wpdb;

		// A capacity limit needs the check-then-insert to be atomic: without
		// a lock, two people RSVPing for the last spot at the same moment can
		// both pass the capacity check before either has actually inserted,
		// overbooking the event. Uncapped events skip the lock entirely.
		$capacity  = (int) get_post_meta( $event_id, '_cec_rsvp_capacity', true );
		$lock_name = 'cec_rsvp_' . $event_id;
		$has_lock  = $capacity > 0 ? (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) ) : true;

		if ( ! $has_lock ) {
			wp_send_json_error( array( 'message' => __( 'This event is getting a lot of RSVPs right now — please try again in a moment.', 'cec' ) ) );
		}

		if ( $capacity > 0 && self::count_for_event( $event_id ) + $guests + 1 > $capacity ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			wp_send_json_error( array( 'message' => __( 'Sorry, this event is at capacity.', 'cec' ) ) );
		}

		$wpdb->insert(
			$wpdb->prefix . CEC_TABLE_RSVP,
			array(
				'event_id'   => $event_id,
				'user_id'    => get_current_user_id(),
				'name'       => $name,
				'email'      => $email,
				'guests'     => $guests,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s' )
		);

		if ( $capacity > 0 ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}

		/**
		 * Someone RSVP'd (1.28.0). Community Member Planning uses it to tell
		 * a member's followers, if that member chose to share their events.
		 *
		 * @param int $event_id
		 * @param int $user_id  0 for a guest.
		 */
		do_action( 'cec_rsvp_created', $event_id, get_current_user_id() );

		wp_send_json_success( array( 'message' => __( "You're on the list! See you there.", 'cec' ) ) );
	}

	/**
	 * Whether a signed-in member can RSVP from outside the form (1.30.0):
	 * the event takes RSVPs here, is published and scheduled.
	 */
	public static function accepts_member_rsvp( $event_id ) {
		$data = CEC_Event_Helper::data( $event_id );
		return 'cec_event' === get_post_type( $event_id ) && 'publish' === get_post_status( $event_id ) && 'internal' === $data['rsvp_mode'] && 'scheduled' === $data['event_status'];
	}

	public static function has_member_rsvp( $event_id, $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . CEC_TABLE_RSVP;
		return $user_id && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND user_id = %d", $event_id, $user_id ) );
	}

	/**
	 * RSVP a signed-in member (1.30.0), used by Community Member Planning's
	 * "Add to my calendar". Same capacity rule as the form, no guests.
	 * Doesn't fire cec_rsvp_created: the caller handles its own follow-up.
	 *
	 * @return string 'ok', 'exists', 'full' or 'closed'.
	 */
	public static function add_member_rsvp( $event_id, $user_id ) {
		global $wpdb;
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::accepts_member_rsvp( $event_id ) ) {
			return 'closed';
		}
		if ( self::has_member_rsvp( $event_id, $user_id ) ) {
			return 'exists';
		}
		$capacity  = (int) get_post_meta( $event_id, '_cec_rsvp_capacity', true );
		$lock_name = 'cec_rsvp_' . $event_id;
		if ( $capacity > 0 && ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) ) ) {
			return 'full';
		}
		$result = 'ok';
		if ( $capacity > 0 && self::count_for_event( $event_id ) + 1 > $capacity ) {
			$result = 'full';
		} else {
			$wpdb->insert(
				$wpdb->prefix . CEC_TABLE_RSVP,
				array(
					'event_id'   => $event_id,
					'user_id'    => $user_id,
					'name'       => $user->display_name,
					'email'      => $user->user_email,
					'guests'     => 0,
					'created_at' => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s', '%s', '%d', '%s' )
			);
		}
		if ( $capacity > 0 ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
		return $result;
	}

	/** Remove a signed-in member's RSVP(s) for an event (1.30.0). */
	public static function remove_member_rsvp( $event_id, $user_id ) {
		global $wpdb;
		if ( ! $user_id ) {
			return 0;
		}
		return (int) $wpdb->delete( $wpdb->prefix . CEC_TABLE_RSVP, array( 'event_id' => $event_id, 'user_id' => $user_id ), array( '%d', '%d' ) );
	}

	public static function waitlist_count_for_event( $event_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_WAITLIST;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d", $event_id ) );
	}

	public static function handle_waitlist_ajax() {
		check_ajax_referer( 'cec_frontend', 'nonce' );

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$guests   = isset( $_POST['guests'] ) ? absint( $_POST['guests'] ) : 0;

		if ( ! $event_id || 'cec_event' !== get_post_type( $event_id ) || 'publish' !== get_post_status( $event_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This event is not available for a waitlist.', 'cec' ) ) );
		}
		if ( empty( $name ) || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a valid name and email.', 'cec' ) ) );
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . self::TABLE_WAITLIST,
			array(
				'event_id'   => $event_id,
				'user_id'    => get_current_user_id(),
				'name'       => $name,
				'email'      => $email,
				'guests'     => $guests,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s' )
		);

		wp_send_json_success( array( 'message' => __( "You're on the waitlist — we'll reach out by email if a spot opens up.", 'cec' ) ) );
	}

	/**
	 * Hourly WP-Cron check (registered on activation as 'cec_rsvp_reminder_check')
	 * for events starting in roughly 24 hours that have RSVPs and haven't
	 * been reminded yet. Marks the event so it's never reminded twice, even
	 * across overlapping cron runs.
	 */
	public static function send_reminders() {
		$window_start = current_time( 'timestamp' ) + ( 23 * HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$window_end   = current_time( 'timestamp' ) + ( 25 * HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp

		$query = new WP_Query(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_cec_start',
						'value'   => date( 'Y-m-d\TH:i', $window_start ),
						'compare' => '>=',
					),
					array(
						'key'     => '_cec_start',
						'value'   => date( 'Y-m-d\TH:i', $window_end ),
						'compare' => '<=',
					),
					array(
						'key'     => '_cec_rsvp_reminder_sent',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $query->posts as $post ) {
			if ( 'scheduled' !== CEC_Event_Helper::event_status( $post->ID ) ) {
				continue;
			}
			self::send_reminder_for_event( $post->ID );
			update_post_meta( $post->ID, '_cec_rsvp_reminder_sent', '1' );
		}
	}

	private static function send_reminder_for_event( $event_id ) {
		global $wpdb;
		$table = $wpdb->prefix . CEC_TABLE_RSVP;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT name, email FROM {$table} WHERE event_id = %d", $event_id ) ); // phpcs:ignore

		if ( empty( $rows ) ) {
			return;
		}

		$data  = CEC_Event_Helper::data( $event_id );
		$where = CEC_Event_Helper::location_display( $data );
		$body = sprintf(
			__( "Just a reminder — %1\$s is happening soon!\n\nWhen: %2\$s\nWhere: %3\$s\n\n%4\$s", 'cec' ),
			$data['title_plain'],
			CEC_Event_Helper::when_text( $data ),
			CEC_Event_Helper::plain_text( $where['label'] ),
			$data['permalink']
		);
		$subject = sprintf( __( '[%s] Reminder: %s is coming up', 'cec' ), CEC_Event_Helper::site_name(), $data['title_plain'] );

		foreach ( $rows as $row ) {
			wp_mail( $row->email, $subject, $body );
		}
	}

	/**
	 * Clears the reminder-sent flag when an event's start time actually
	 * changes (e.g. rescheduled after a postpone), so a rescheduled event
	 * still gets its own 24-hours-before reminder instead of being silently
	 * skipped because the old date was already reminded.
	 */
	public static function on_start_meta_updated( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( '_cec_start' !== $meta_key || 'cec_event' !== get_post_type( $post_id ) ) {
			return;
		}
		delete_post_meta( $post_id, '_cec_rsvp_reminder_sent' );
	}

	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=cec_event',
			__( 'RSVP Attendees', 'cec' ),
			__( 'RSVP Attendees', 'cec' ),
			'manage_options',
			'cec-rsvps',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Prevents Excel/Sheets from executing a cell as a formula when a
	 * submitted name/email starts with =, +, -, or @ — CSV formula
	 * injection. (See the CEC_Volunteers export for the same gap, not
	 * fixed here to keep this change scoped to the new RSVP export.)
	 */
	private static function csv_safe( $value ) {
		if ( is_string( $value ) && preg_match( '/^[=+\-@]/', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	public static function render_admin_page() {
		global $wpdb;
		$table  = $wpdb->prefix . CEC_TABLE_RSVP;
		$scope  = isset( $_GET['cec_scope'] ) ? sanitize_key( wp_unslash( $_GET['cec_scope'] ) ) : 'upcoming';
		$now    = current_time( 'mysql' );

		if ( isset( $_GET['cec_msg'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Updated.', 'cec' ) . '</p></div>';
		}

		$where = "p.post_type = 'cec_event' AND p.post_status = 'publish'";
		if ( 'upcoming' === $scope ) {
			$where .= $wpdb->prepare( " AND pm.meta_value >= %s", str_replace( ' ', 'T', substr( $now, 0, 16 ) ) ); // phpcs:ignore
		} elseif ( 'past' === $scope ) {
			$where .= $wpdb->prepare( " AND pm.meta_value < %s", str_replace( ' ', 'T', substr( $now, 0, 16 ) ) ); // phpcs:ignore
		}

		$rows = $wpdb->get_results( // phpcs:ignore
			"SELECT r.*, p.post_title AS event_title
			FROM {$table} r
			INNER JOIN {$wpdb->posts} p ON p.ID = r.event_id
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_cec_start'
			WHERE {$where}
			ORDER BY pm.meta_value ASC, r.created_at ASC
			LIMIT 500"
		);

		$waitlist_table = $wpdb->prefix . self::TABLE_WAITLIST;
		$waitlist_rows  = $wpdb->get_results( // phpcs:ignore
			"SELECT w.*, p.post_title AS event_title
			FROM {$waitlist_table} w
			INNER JOIN {$wpdb->posts} p ON p.ID = w.event_id
			WHERE p.post_type = 'cec_event' AND p.post_status = 'publish'
			ORDER BY w.created_at ASC
			LIMIT 500"
		);

		$export_url = wp_nonce_url( add_query_arg( array( 'action' => 'cec_rsvp_export', 'cec_scope' => $scope ), admin_url( 'admin-post.php' ) ), self::ADMIN_NONCE );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RSVP Attendees', 'cec' ); ?> <a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'cec' ); ?></a></h1>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( add_query_arg( 'cec_scope', 'upcoming' ) ); ?>" class="<?php echo 'upcoming' === $scope ? 'current' : ''; ?>"><?php esc_html_e( 'Upcoming', 'cec' ); ?></a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'cec_scope', 'past' ) ); ?>" class="<?php echo 'past' === $scope ? 'current' : ''; ?>"><?php esc_html_e( 'Past', 'cec' ); ?></a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'cec_scope', 'all' ) ); ?>" class="<?php echo 'all' === $scope ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'cec' ); ?></a></li>
			</ul>
			<br class="clear" />

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Event', 'cec' ); ?></th>
						<th><?php esc_html_e( 'Name', 'cec' ); ?></th>
						<th><?php esc_html_e( 'Email', 'cec' ); ?></th>
						<th><?php esc_html_e( 'Guests', 'cec' ); ?></th>
						<th><?php esc_html_e( 'RSVP\'d', 'cec' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No RSVPs in this range.', 'cec' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $row->event_id ) ); ?>"><?php echo esc_html( $row->event_title ); ?></a></td>
							<td><?php echo esc_html( $row->name ); ?></td>
							<td><a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a></td>
							<td><?php echo (int) $row->guests; ?></td>
							<td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $row->created_at ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Waitlist', 'cec' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Collected when an event is at capacity. There is no automatic notification yet — reach out manually if a spot opens up.', 'cec' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Event', 'cec' ); ?></th>
						<th><?php esc_html_e( 'Name', 'cec' ); ?></th>
						<th><?php esc_html_e( 'Email', 'cec' ); ?></th>
						<th><?php esc_html_e( 'Guests', 'cec' ); ?></th>
						<th><?php esc_html_e( 'Joined', 'cec' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $waitlist_rows ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'Nobody on a waitlist right now.', 'cec' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $waitlist_rows as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $row->event_id ) ); ?>"><?php echo esc_html( $row->event_title ); ?></a></td>
							<td><?php echo esc_html( $row->name ); ?></td>
							<td><a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a></td>
							<td><?php echo (int) $row->guests; ?></td>
							<td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $row->created_at ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cec' ) );
		}
		check_admin_referer( self::ADMIN_NONCE );

		global $wpdb;
		$table = $wpdb->prefix . CEC_TABLE_RSVP;
		$scope = isset( $_GET['cec_scope'] ) ? sanitize_key( wp_unslash( $_GET['cec_scope'] ) ) : 'upcoming';
		$now   = current_time( 'mysql' );

		$where = "p.post_type = 'cec_event' AND p.post_status = 'publish'";
		if ( 'upcoming' === $scope ) {
			$where .= $wpdb->prepare( " AND pm.meta_value >= %s", str_replace( ' ', 'T', substr( $now, 0, 16 ) ) ); // phpcs:ignore
		} elseif ( 'past' === $scope ) {
			$where .= $wpdb->prepare( " AND pm.meta_value < %s", str_replace( ' ', 'T', substr( $now, 0, 16 ) ) ); // phpcs:ignore
		}

		$rows = $wpdb->get_results( // phpcs:ignore
			"SELECT r.name, r.email, r.guests, r.created_at, p.post_title AS event_title
			FROM {$table} r
			INNER JOIN {$wpdb->posts} p ON p.ID = r.event_id
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_cec_start'
			WHERE {$where}
			ORDER BY pm.meta_value ASC, r.created_at ASC"
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=rsvp-attendees.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Event', 'Name', 'Email', 'Guests', 'RSVP\'d' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					self::csv_safe( $row->event_title ),
					self::csv_safe( $row->name ),
					self::csv_safe( $row->email ),
					$row->guests,
					$row->created_at,
				)
			);
		}
		fclose( $out );
		exit;
	}
}
