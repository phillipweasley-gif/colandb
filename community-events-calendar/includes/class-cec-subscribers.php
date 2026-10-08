<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [cec_subscribe] — email subscriptions to "all events" and/or specific
 * partner organizations, pulled live from the cec_partner_org taxonomy so
 * newly added orgs (including ones submitters propose inline) are
 * subscribable immediately. Double opt-in (confirm link required before any
 * notification goes out) and a one-click unsubscribe link on every email.
 *
 * Notifications fire for: a new event's series root going live (publish),
 * and a live event's status changing to/from postponed/cancelled. Both are
 * scoped to subscribers whose target is 'all' or matches one of the event's
 * partner orgs.
 */
class CEC_Subscribers {

	const TABLE = 'cec_subscribers';

	public static function create_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL,
			target VARCHAR(20) NOT NULL,
			confirmed TINYINT(1) NOT NULL DEFAULT 0,
			token VARCHAR(64) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY email_target (email, target),
			KEY token (token)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function render_shortcode( $atts ) {
		$action = isset( $_GET['cec_sub_action'] ) ? sanitize_key( wp_unslash( $_GET['cec_sub_action'] ) ) : '';
		$token  = isset( $_GET['cec_sub_token'] ) ? sanitize_text_field( wp_unslash( $_GET['cec_sub_token'] ) ) : '';

		ob_start();
		echo '<div class="cec-dashboard cec-subscribe-form">';

		if ( 'confirm' === $action && $token ) {
			echo self::render_confirm_result( $token );
		} elseif ( 'unsubscribe' === $action && $token ) {
			echo self::render_unsubscribe_result( $token );
		} else {
			echo self::render_form();
		}

		echo '</div>';
		return ob_get_clean();
	}

	private static function render_confirm_result( $token ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", $token ) );

		if ( ! $row ) {
			return '<div class="cec-notice cec-notice-error">' . esc_html__( 'That confirmation link is invalid.', 'cec' ) . '</div>';
		}
		$wpdb->update( $table, array( 'confirmed' => 1 ), array( 'id' => $row->id ) );

		return '<div class="cec-notice cec-notice-success">' . esc_html( sprintf( __( "Confirmed! You're subscribed to %s.", 'cec' ), self::target_label( $row->target ) ) ) . '</div>' . self::render_form();
	}

	private static function render_unsubscribe_result( $token ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", $token ) );

		if ( ! $row ) {
			return '<div class="cec-notice cec-notice-error">' . esc_html__( 'That unsubscribe link is invalid — you may already be unsubscribed.', 'cec' ) . '</div>';
		}
		$label = self::target_label( $row->target );
		$wpdb->delete( $table, array( 'id' => $row->id ) );

		return '<div class="cec-notice cec-notice-success">' . esc_html( sprintf( __( "You're unsubscribed from %s.", 'cec' ), $label ) ) . '</div>' . self::render_form();
	}

	private static function target_label( $target ) {
		if ( 'all' === $target ) {
			return __( 'all community events', 'cec' );
		}
		$term = get_term( (int) $target, 'cec_partner_org' );
		return $term && ! is_wp_error( $term ) ? $term->name : __( 'that organization or titleholder', 'cec' );
	}

	private static function render_form() {
		$orgs           = get_terms( array( 'taxonomy' => 'cec_partner_org', 'hide_empty' => false ) );
		$preselect_slug = isset( $_GET['cec_org'] ) ? sanitize_title( wp_unslash( $_GET['cec_org'] ) ) : '';

		$notice = '';
		if ( isset( $_GET['cec_subscribed'] ) ) {
			$notice = '1' === $_GET['cec_subscribed']
				? '<div class="cec-notice cec-notice-success">' . esc_html__( "Almost done — check your email and click the confirm link(s) to start receiving updates.", 'cec' ) . '</div>'
				: '<div class="cec-notice cec-notice-error">' . esc_html__( 'Please enter a valid email and pick at least one thing to subscribe to.', 'cec' ) . '</div>';
		}

		ob_start();
		?>
		<?php echo $notice; // phpcs:ignore ?>
		<h2><?php esc_html_e( 'Subscribe to Community Events', 'cec' ); ?></h2>
		<p><?php esc_html_e( "Get an email when a new event is added, or when one you're watching is postponed or cancelled.", 'cec' ); ?></p>
		<form class="cec-submit-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cec_subscribe" />
			<input type="hidden" name="cec_redirect" value="<?php echo esc_url( get_permalink() ); ?>" />
			<?php wp_nonce_field( 'cec_subscribe_action', 'cec_subscribe_nonce' ); ?>
			<div class="cec-field"><label><?php esc_html_e( 'Email', 'cec' ); ?></label><input type="email" name="cec_email" required></div>
			<div class="cec-field">
				<label class="cec-checkbox"><input type="checkbox" name="cec_sub_all" value="1"> <?php esc_html_e( 'All community events', 'cec' ); ?></label>
			</div>
			<?php if ( ! empty( $orgs ) && ! is_wp_error( $orgs ) ) : ?>
			<div class="cec-field">
				<label><?php esc_html_e( 'Or just specific organizations or titleholders', 'cec' ); ?></label>
				<?php foreach ( $orgs as $term ) : ?>
					<label class="cec-checkbox"><input type="checkbox" name="cec_sub_org[]" value="<?php echo esc_attr( $term->term_id ); ?>" <?php checked( $preselect_slug === $term->slug ); ?>> <?php echo esc_html( $term->name ); ?></label>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<p class="cec-hp-field" aria-hidden="true"><label>Leave this field empty</label><input type="text" name="cec_website" tabindex="-1" autocomplete="off"></p>
			<button type="submit" class="cec-btn"><?php esc_html_e( 'Subscribe', 'cec' ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function handle_subscribe() {
		$redirect = isset( $_POST['cec_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['cec_redirect'] ) ) : home_url();

		if ( ! isset( $_POST['cec_subscribe_nonce'] ) || ! wp_verify_nonce( $_POST['cec_subscribe_nonce'], 'cec_subscribe_action' ) || ! empty( $_POST['cec_website'] ) ) {
			wp_safe_redirect( add_query_arg( 'cec_subscribed', '0', $redirect ) );
			exit;
		}

		$email = isset( $_POST['cec_email'] ) ? sanitize_email( wp_unslash( $_POST['cec_email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( 'cec_subscribed', '0', $redirect ) );
			exit;
		}

		$targets = array();
		if ( ! empty( $_POST['cec_sub_all'] ) ) {
			$targets[] = 'all';
		}
		if ( ! empty( $_POST['cec_sub_org'] ) ) {
			foreach ( (array) $_POST['cec_sub_org'] as $org_id ) {
				$targets[] = (string) absint( $org_id );
			}
		}
		if ( empty( $targets ) ) {
			wp_safe_redirect( add_query_arg( 'cec_subscribed', '0', $redirect ) );
			exit;
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$links = array();

		foreach ( array_unique( $targets ) as $target ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s AND target = %s", $email, $target ) );
			if ( $existing && $existing->confirmed ) {
				continue; // already an active subscriber, nothing to confirm.
			}

			$token = bin2hex( random_bytes( 20 ) );
			if ( $existing ) {
				$wpdb->update( $table, array( 'token' => $token ), array( 'id' => $existing->id ) );
			} else {
				$wpdb->insert(
					$table,
					array(
						'email'      => $email,
						'target'     => $target,
						'confirmed'  => 0,
						'token'      => $token,
						'created_at' => current_time( 'mysql' ),
					)
				);
			}

			$links[] = sprintf(
				/* translators: 1: what they're confirming, 2: confirm link */
				__( 'Confirm "%1$s": %2$s', 'cec' ),
				self::target_label( $target ),
				add_query_arg(
					array( 'cec_sub_action' => 'confirm', 'cec_sub_token' => $token ),
					$redirect
				)
			);
		}

		if ( ! empty( $links ) ) {
			wp_mail(
				$email,
				sprintf( __( '[%s] Confirm your subscription', 'cec' ), CEC_Event_Helper::site_name() ),
				__( "One more step — click each link below to confirm:\n\n", 'cec' ) . implode( "\n\n", $links )
			);
		}

		wp_safe_redirect( add_query_arg( 'cec_subscribed', '1', $redirect ) );
		exit;
	}

	private static function matching_emails( $event_id ) {
		global $wpdb;
		$table    = $wpdb->prefix . self::TABLE;
		$org_ids  = wp_get_post_terms( $event_id, 'cec_partner_org', array( 'fields' => 'ids' ) );
		$targets  = array( 'all' );
		if ( ! is_wp_error( $org_ids ) ) {
			foreach ( $org_ids as $id ) {
				$targets[] = (string) $id;
			}
		}
		$placeholders = implode( ',', array_fill( 0, count( $targets ), '%s' ) );
		// GROUP BY email (not a plain DISTINCT) so someone subscribed both to
		// "all" and to one of this event's orgs gets exactly one notification,
		// not one per matching subscription row.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT email, MIN(token) AS token FROM {$table} WHERE confirmed = 1 AND target IN ({$placeholders}) GROUP BY email", // phpcs:ignore
				$targets
			)
		);
		return $rows ? $rows : array();
	}

	private static function send_to_matches( $event_id, $subject, $body_intro ) {
		$subscribers = self::matching_emails( $event_id );
		if ( empty( $subscribers ) ) {
			return;
		}

		$data  = CEC_Event_Helper::data( $event_id );
		$where = CEC_Event_Helper::location_display( $data );
		$details = sprintf(
			"%s\n%s: %s\n%s: %s\n%s\n\n%s",
			$data['title_plain'],
			__( 'When', 'cec' ),
			CEC_Event_Helper::when_text( $data ),
			__( 'Where', 'cec' ),
			CEC_Event_Helper::plain_text( $where['label'] ),
			$data['permalink'],
			$body_intro
		);

		$manage_url = CEC_Admin_Settings::get( 'subscribe_page_url' );

		foreach ( $subscribers as $sub ) {
			$footer = '';
			if ( $manage_url ) {
				$footer = "\n\n" . __( 'Unsubscribe:', 'cec' ) . ' ' . add_query_arg(
					array( 'cec_sub_action' => 'unsubscribe', 'cec_sub_token' => $sub->token ),
					$manage_url
				);
			}
			wp_mail( $sub->email, $subject, $details . $footer );
		}
	}

	/**
	 * Hooked to transition_post_status. Notifies only when a series root (or
	 * standalone event) newly goes live — not on every generated occurrence,
	 * which would otherwise fire one email per date for a recurring series.
	 */
	public static function on_status_transition( $new_status, $old_status, $post ) {
		if ( ! is_object( $post ) || 'cec_event' !== $post->post_type || $new_status === $old_status ) {
			return;
		}
		if ( 'publish' !== $new_status || CEC_Recurrence::is_occurrence( $post->ID ) ) {
			return;
		}
		self::send_to_matches(
			$post->ID,
			sprintf( __( '[%s] New event: %s', 'cec' ), CEC_Event_Helper::site_name(), CEC_Event_Helper::plain_text( $post->post_title ) ),
			__( 'A new event was just added to the community calendar.', 'cec' )
		);
	}

	/**
	 * Called explicitly by the dedicated "set event status" actions (not the
	 * general edit-detail saves, which also happen to write this same meta
	 * key but don't represent a deliberate status change) so notifications
	 * only fire when a human actually flips Scheduled/Postponed/Cancelled.
	 */
	public static function maybe_notify_status_change( $event_id, $old_status, $new_status ) {
		if ( $old_status === $new_status || 'publish' !== get_post_status( $event_id ) ) {
			return;
		}
		$labels = array(
			'scheduled' => __( 'back on as Scheduled', 'cec' ),
			'postponed' => __( 'Postponed', 'cec' ),
			'cancelled' => __( 'Cancelled', 'cec' ),
		);
		$label = isset( $labels[ $new_status ] ) ? $labels[ $new_status ] : $new_status;

		self::send_to_matches(
			$event_id,
			sprintf( __( '[%s] Event update: %s is now %s', 'cec' ), CEC_Event_Helper::site_name(), CEC_Event_Helper::plain_text( get_post_field( 'post_title', $event_id, 'raw' ) ), $label ),
			sprintf( __( 'This event is now marked: %s.', 'cec' ), $label )
		);
	}
}
