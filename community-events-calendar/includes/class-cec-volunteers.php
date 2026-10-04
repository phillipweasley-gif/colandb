<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [cec_volunteer_form] — collects volunteer sign-up interest for the org.
 * Unlike events, these submissions are never public: they're private
 * contact records reviewed only in wp-admin (Events > Volunteer Inquiries),
 * gated on manage_options since they include email/phone.
 */
class CEC_Volunteers {

	const TABLE         = 'cec_volunteer_inquiries';
	const NONCE_ACTION   = 'cec_volunteer_submit';
	const STATUS_NONCE   = 'cec_volunteer_status';

	public static function create_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			email VARCHAR(190) NOT NULL,
			phone VARCHAR(50) NOT NULL DEFAULT '',
			interests TEXT NOT NULL,
			availability TEXT NOT NULL,
			message TEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private static function interest_options() {
		$raw = CEC_Admin_Settings::get( 'volunteer_interest_areas' );
		$list = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		return $list ? $list : array();
	}

	public static function render_shortcode( $atts ) {
		$notice = '';
		if ( isset( $_GET['cec_volunteer_submitted'] ) ) {
			$notice = '1' === $_GET['cec_volunteer_submitted']
				? '<div class="cec-notice cec-notice-success">' . esc_html__( "Thanks for your interest in volunteering! Someone will be in touch soon.", 'cec' ) . '</div>'
				: '<div class="cec-notice cec-notice-error">' . esc_html__( 'Please fill in your name and a valid email and try again.', 'cec' ) . '</div>';
		}

		$interests = self::interest_options();

		ob_start();
		?>
		<div class="cec-auth-form">
			<?php echo $notice; // phpcs:ignore ?>
			<form class="cec-submit-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cec_volunteer_submit" />
				<input type="hidden" name="cec_redirect" value="<?php echo esc_url( get_permalink() ); ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION, 'cec_volunteer_nonce' ); ?>

				<div class="cec-field"><label><?php esc_html_e( 'Name *', 'cec' ); ?></label><input type="text" name="cec_v_name" required></div>
				<div class="cec-field-row">
					<div class="cec-field"><label><?php esc_html_e( 'Email *', 'cec' ); ?></label><input type="email" name="cec_v_email" required></div>
					<div class="cec-field"><label><?php esc_html_e( 'Phone', 'cec' ); ?></label><input type="tel" name="cec_v_phone"></div>
				</div>

				<?php if ( ! empty( $interests ) ) : ?>
				<div class="cec-field">
					<label><?php esc_html_e( 'Areas of Interest', 'cec' ); ?></label>
					<?php foreach ( $interests as $area ) : ?>
						<label class="cec-checkbox"><input type="checkbox" name="cec_v_interests[]" value="<?php echo esc_attr( $area ); ?>"> <?php echo esc_html( $area ); ?></label>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<div class="cec-field"><label><?php esc_html_e( 'Availability', 'cec' ); ?></label><input type="text" name="cec_v_availability" placeholder="<?php esc_attr_e( 'e.g. weekends, Tuesday evenings', 'cec' ); ?>"></div>
				<div class="cec-field"><label><?php esc_html_e( 'Anything else you\'d like us to know?', 'cec' ); ?></label><textarea name="cec_v_message" rows="4"></textarea></div>

				<p class="cec-hp-field" aria-hidden="true"><label>Leave this field empty</label><input type="text" name="cec_website" tabindex="-1" autocomplete="off"></p>

				<button type="submit" class="cec-btn"><?php esc_html_e( 'Submit Interest', 'cec' ); ?></button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function handle_submit() {
		$redirect = isset( $_POST['cec_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['cec_redirect'] ) ) : home_url();

		if ( ! isset( $_POST['cec_volunteer_nonce'] ) || ! wp_verify_nonce( $_POST['cec_volunteer_nonce'], self::NONCE_ACTION ) || ! empty( $_POST['cec_website'] ) ) {
			wp_safe_redirect( add_query_arg( 'cec_volunteer_submitted', '0', $redirect ) );
			exit;
		}

		$name  = isset( $_POST['cec_v_name'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_v_name'] ) ) : '';
		$email = isset( $_POST['cec_v_email'] ) ? sanitize_email( wp_unslash( $_POST['cec_v_email'] ) ) : '';

		if ( ! $name || ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( 'cec_volunteer_submitted', '0', $redirect ) );
			exit;
		}

		$interests = ! empty( $_POST['cec_v_interests'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['cec_v_interests'] ) ) : array();

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'name'         => $name,
				'email'        => $email,
				'phone'        => isset( $_POST['cec_v_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_v_phone'] ) ) : '',
				'interests'    => implode( ', ', $interests ),
				'availability' => isset( $_POST['cec_v_availability'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_v_availability'] ) ) : '',
				'message'      => isset( $_POST['cec_v_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cec_v_message'] ) ) : '',
				'status'       => 'new',
				'created_at'   => current_time( 'mysql' ),
			)
		);

		$notify = CEC_Admin_Settings::get( 'volunteer_notify_email' );
		wp_mail(
			$notify ? $notify : get_option( 'admin_email' ),
			sprintf( __( '[%s] New volunteer inquiry: %s', 'cec' ), CEC_Event_Helper::site_name(), $name ),
			sprintf(
				__( "Name: %1\$s\nEmail: %2\$s\nPhone: %3\$s\nInterests: %4\$s\nAvailability: %5\$s\n\nMessage:\n%6\$s\n\nReview all inquiries: %7\$s", 'cec' ),
				$name,
				$email,
				sanitize_text_field( wp_unslash( $_POST['cec_v_phone'] ?? '' ) ),
				implode( ', ', $interests ),
				sanitize_text_field( wp_unslash( $_POST['cec_v_availability'] ?? '' ) ),
				sanitize_textarea_field( wp_unslash( $_POST['cec_v_message'] ?? '' ) ),
				admin_url( 'edit.php?post_type=cec_event&page=cec-volunteers' )
			)
		);

		wp_safe_redirect( add_query_arg( 'cec_volunteer_submitted', '1', $redirect ) );
		exit;
	}

	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=cec_event',
			__( 'Volunteer Inquiries', 'cec' ),
			__( 'Volunteer Inquiries', 'cec' ),
			'manage_options',
			'cec-volunteers',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function render_admin_page() {
		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE;
		$status = isset( $_GET['cec_status'] ) ? sanitize_key( wp_unslash( $_GET['cec_status'] ) ) : 'new';

		if ( isset( $_GET['cec_msg'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Updated.', 'cec' ) . '</p></div>';
		}

		$counts = $wpdb->get_results( "SELECT status, COUNT(*) as c FROM {$table} GROUP BY status", OBJECT_K ); // phpcs:ignore
		$count_for = function ( $key ) use ( $counts ) {
			return isset( $counts[ $key ] ) ? (int) $counts[ $key ]->c : 0;
		};

		$where = 'all' === $status ? '1=1' : $wpdb->prepare( 'status = %s', $status );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT 200" ); // phpcs:ignore

		$export_url = wp_nonce_url( add_query_arg( array( 'action' => 'cec_volunteer_export' ), admin_url( 'admin-post.php' ) ), self::STATUS_NONCE );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Volunteer Inquiries', 'cec' ); ?> <a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'cec' ); ?></a></h1>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( add_query_arg( 'cec_status', 'new' ) ); ?>" class="<?php echo 'new' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'New', 'cec' ); ?> <span class="count">(<?php echo (int) $count_for( 'new' ); ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'cec_status', 'contacted' ) ); ?>" class="<?php echo 'contacted' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Contacted', 'cec' ); ?> <span class="count">(<?php echo (int) $count_for( 'contacted' ); ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'cec_status', 'archived' ) ); ?>" class="<?php echo 'archived' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Archived', 'cec' ); ?> <span class="count">(<?php echo (int) $count_for( 'archived' ); ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'cec_status', 'all' ) ); ?>" class="<?php echo 'all' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'cec' ); ?></a></li>
			</ul>
			<br class="clear" />

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'cec' ); ?></th>
						<th><?php esc_html_e( 'Contact', 'cec' ); ?></th>
						<th><?php esc_html_e( 'Interests', 'cec' ); ?></th>
						<th><?php esc_html_e( 'Availability', 'cec' ); ?></th>
						<th><?php esc_html_e( 'Message', 'cec' ); ?></th>
						<th><?php esc_html_e( 'Submitted', 'cec' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'cec' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'Nothing here.', 'cec' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $row->name ); ?></strong></td>
							<td><a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a><?php echo $row->phone ? '<br />' . esc_html( $row->phone ) : ''; ?></td>
							<td><?php echo esc_html( $row->interests ); ?></td>
							<td><?php echo esc_html( $row->availability ); ?></td>
							<td><?php echo esc_html( wp_trim_words( $row->message, 15 ) ); ?></td>
							<td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $row->created_at ) ) ); ?></td>
							<td>
								<?php foreach ( array( 'new' => __( 'New', 'cec' ), 'contacted' => __( 'Contacted', 'cec' ), 'archived' => __( 'Archived', 'cec' ) ) as $s => $label ) : ?>
									<?php if ( $s !== $row->status ) : ?>
										<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'cec_volunteer_status', 'id' => $row->id, 'status' => $s ), admin_url( 'admin-post.php' ) ), self::STATUS_NONCE ) ); ?>"><?php echo esc_html( $label ); ?></a>
									<?php endif; ?>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function handle_status_update() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cec' ) );
		}
		check_admin_referer( self::STATUS_NONCE );

		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		if ( $id && in_array( $status, array( 'new', 'contacted', 'archived' ), true ) ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . self::TABLE, array( 'status' => $status ), array( 'id' => $id ) );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'cec-volunteers', 'cec_msg' => '1' ), admin_url( 'edit.php?post_type=cec_event' ) ) );
		exit;
	}

	/**
	 * Prevents Excel/Sheets from executing a cell as a formula when a
	 * submitted field starts with =, +, -, or @ — CSV formula injection.
	 */
	private static function csv_safe( $value ) {
		if ( is_string( $value ) && preg_match( '/^[=+\-@]/', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cec' ) );
		}
		check_admin_referer( self::STATUS_NONCE );

		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT name, email, phone, interests, availability, message, status, created_at FROM ' . $wpdb->prefix . self::TABLE . ' ORDER BY created_at DESC' ); // phpcs:ignore

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=volunteer-inquiries.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Name', 'Email', 'Phone', 'Interests', 'Availability', 'Message', 'Status', 'Submitted' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					self::csv_safe( $row->name ),
					self::csv_safe( $row->email ),
					self::csv_safe( $row->phone ),
					self::csv_safe( $row->interests ),
					self::csv_safe( $row->availability ),
					self::csv_safe( $row->message ),
					$row->status,
					$row->created_at,
				)
			);
		}
		fclose( $out );
		exit;
	}
}
