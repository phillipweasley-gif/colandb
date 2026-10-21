<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports of intimate images shared without consent (0.32.0). See
 * docs/privacy-security-plan.md §4–5.
 *
 * The TAKE IT DOWN Act (US, 2025) asks a site that hosts what users post to
 * offer a clear way to report a non-consensual intimate image, and to take it
 * down within 48 hours of a valid request: a signature (a typed name counts),
 * enough to find the image, a good-faith statement and a way to reply.
 *
 * - [cmp_image_report]: a public form, no account needed. Honeypot, a
 *   minimum time on the page, and 5 reports a day per address and per email.
 * - Saved in {prefix}cmp_image_reports (kept 3 years, CMP_Retention), the
 *   site's admin email gets an urgent message with the deadline, and the
 *   reporter gets a confirmation. A report that the image may show someone
 *   under 18 is flagged: the team must not open, download or forward it,
 *   and reports it to the NCMEC CyberTipline.
 * - Users → Image reports: the list with time left, Removed / Not actioned
 *   with a note (the reporter is emailed), and "Remove a private photo":
 *   paste a photo's link and the stored photo is deleted without being
 *   shown. Every removal is in the audit log.
 */
class CMP_Image_Reports {

	const SHORTCODE = 'cmp_image_report';
	const ACTION    = 'cmp_image_report';
	const PAGE      = 'cmp-image-reports';
	const NONCE     = 'cmp_image_reports_admin';
	const DAY_LIMIT = 5;
	const MIN_SECS  = 3;
	const HOURS     = 48;

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'form_html' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_cmp_image_report_admin', array( __CLASS__, 'handle_admin' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	public static function t() {
		return CMP_Install::table( 'image_reports' );
	}

	public static function relations() {
		return array(
			'self'   => __( 'It shows me', 'cmp' ),
			'behalf' => __( 'I\'m reporting for the person it shows', 'cmp' ),
			'minor'  => __( 'It may show someone under 18', 'cmp' ),
		);
	}

	private static function stamp( $t ) {
		return $t . '.' . substr( hash_hmac( 'sha256', 'cmp-ir|' . $t, wp_salt( 'nonce' ) ), 0, 16 );
	}

	private static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return hash_hmac( 'sha256', 'ip|' . $ip, wp_salt( 'auth' ) );
	}

	/* Public form */

	public static function form_html() {
		$state = isset( $_GET['cmp_ir'] ) ? sanitize_key( wp_unslash( $_GET['cmp_ir'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msgs  = array(
			'sent'    => array( 'success', __( 'Thank you. Your report was sent to the COL&B team, and we\'ve emailed you a copy. We act on valid reports within 48 hours and will email you when it\'s done.', 'cmp' ) ),
			'invalid' => array( 'error', __( 'The report wasn\'t sent. Please fill in every required field, tick the statement and type your full name as your signature.', 'cmp' ) ),
			'limit'   => array( 'error', __( 'You\'ve sent several reports today. Please email us instead so nothing gets missed.', 'cmp' ) ),
		);
		$html = '<style>.cmp-image-report{max-width:760px;margin:0 auto}.cmp-image-report label{display:block;font-weight:600;margin-bottom:4px}.cmp-image-report .cmp-ir-radio,.cmp-image-report .cmp-ir-check label{display:inline;font-weight:400}.cmp-image-report .cmp-ir-radio{display:block;margin:6px 0}.cmp-image-report fieldset{border:1px solid rgba(255,255,255,.25);border-radius:8px;padding:10px 14px;margin:0 0 14px}.cmp-image-report textarea,.cmp-image-report input[type=text],.cmp-image-report input[type=email]{width:100%;box-sizing:border-box}.cmp-image-report .cmp-ir-notice{padding:12px 14px;border-radius:8px;border:2px solid #E1F577}.cmp-image-report .cmp-ir-error{border-color:#FF50D7}.cmp-image-report .cmp-ir-minor-note,.cmp-image-report .cmp-ir-small{font-size:14px;opacity:.85}</style>'
			. '<div class="cmp-image-report" id="cmp-image-report">';
		if ( isset( $msgs[ $state ] ) ) {
			$html .= '<p class="cmp-ir-notice cmp-ir-' . esc_attr( $msgs[ $state ][0] ) . '" role="' . ( 'success' === $msgs[ $state ][0] ? 'status' : 'alert' ) . '">' . esc_html( $msgs[ $state ][1] ) . '</p>';
			if ( 'sent' === $state ) {
				return $html . '</div>';
			}
		}
		$rel = '';
		foreach ( self::relations() as $k => $label ) {
			$rel .= '<label class="cmp-ir-radio"><input type="radio" name="relation" value="' . esc_attr( $k ) . '" required /> ' . esc_html( $label ) . '</label>';
		}
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cmp-ir-form">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />'
			. '<input type="hidden" name="t" value="' . esc_attr( self::stamp( time() ) ) . '" />'
			. '<input type="hidden" name="back" value="' . esc_url( get_permalink() ) . '" />'
			. '<p class="cmp-ir-hp" aria-hidden="true" style="position:absolute;left:-9999px"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off" /></label></p>'
			. '<fieldset><legend>' . esc_html__( 'Who does the image show?', 'cmp' ) . ' *</legend>' . $rel . '</fieldset>'
			. '<p class="cmp-ir-minor-note">' . esc_html__( 'If it may show someone under 18, don\'t send us the image or a copy. Tell us where it is, and you can also report it to the NCMEC CyberTipline (report.cybertip.org) or the police.', 'cmp' ) . '</p>'
			. '<p><label for="cmp_ir_loc">' . esc_html__( 'Where is the image?', 'cmp' ) . ' *</label><textarea id="cmp_ir_loc" name="location" rows="3" maxlength="2000" required placeholder="' . esc_attr__( 'A link on colandb.com, the member\'s name, or where on the site you saw it', 'cmp' ) . '"></textarea></p>'
			. '<p><label for="cmp_ir_details">' . esc_html__( 'Anything else we should know (optional)', 'cmp' ) . '</label><textarea id="cmp_ir_details" name="details" rows="4" maxlength="5000"></textarea></p>'
			. '<p><label for="cmp_ir_email">' . esc_html__( 'Your email, so we can reply', 'cmp' ) . ' *</label><input type="email" id="cmp_ir_email" name="email" maxlength="190" required autocomplete="email" /></p>'
			. '<p><label for="cmp_ir_name">' . esc_html__( 'Your name (optional)', 'cmp' ) . '</label><input type="text" id="cmp_ir_name" name="name" maxlength="100" autocomplete="name" /></p>'
			. '<p class="cmp-ir-check"><input type="checkbox" id="cmp_ir_statement" name="statement" value="1" required /> <label for="cmp_ir_statement">' . esc_html__( 'I believe in good faith that this intimate image was shared without the consent of the person it shows, and the information in this report is accurate.', 'cmp' ) . ' *</label></p>'
			. '<p><label for="cmp_ir_sig">' . esc_html__( 'Signature: type your full name', 'cmp' ) . ' *</label><input type="text" id="cmp_ir_sig" name="signature" maxlength="100" required autocomplete="name" /></p>'
			. '<p><button type="submit" class="cmp-btn">' . esc_html__( 'Send report', 'cmp' ) . '</button></p>'
			. '<p class="cmp-ir-small">' . esc_html__( 'Only the COL&B site team sees this report. We keep it for 3 years for safety and legal reasons.', 'cmp' ) . '</p>'
			. '</form></div>';
		return $html;
	}

	public static function handle() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- a public form for people without accounts; spam is limited by the honeypot, timing and daily limits.
		$back = isset( $_POST['back'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['back'] ) ), home_url( '/' ) ) : home_url( '/' );
		$go   = function ( $state ) use ( $back ) {
			wp_safe_redirect( add_query_arg( 'cmp_ir', $state, $back ) . '#cmp-image-report' );
			exit;
		};
		$p = function ( $k, $max, $multi = false ) {
			$v = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$v = $multi ? sanitize_textarea_field( (string) $v ) : sanitize_text_field( (string) $v );
			return mb_substr( trim( $v ), 0, $max );
		};
		$stamp = $p( 't', 64 );
		$t     = (int) strtok( $stamp, '.' );
		if ( '' !== $p( 'website', 200 ) || ! hash_equals( self::stamp( $t ), $stamp ) || time() - $t < self::MIN_SECS || time() - $t > DAY_IN_SECONDS ) {
			$go( 'invalid' ); // Bots: no details on purpose.
		}
		$row = array(
			'relation'  => $p( 'relation', 12 ),
			'location'  => $p( 'location', 2000, true ),
			'details'   => $p( 'details', 5000, true ),
			'email'     => sanitize_email( $p( 'email', 190 ) ),
			'name'      => $p( 'name', 100 ),
			'signature' => $p( 'signature', 100 ),
		);
		if ( ! isset( self::relations()[ $row['relation'] ] ) || '' === $row['location'] || ! is_email( $row['email'] ) || mb_strlen( $row['signature'] ) < 3 || empty( $_POST['statement'] ) ) {
			$go( 'invalid' );
		}
		// phpcs:enable
		$ip    = self::ip_hash();
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t() . ' WHERE ( ip_hash = %s OR email = %s ) AND created_at > %s', $ip, $row['email'], $since ) ) >= self::DAY_LIMIT ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$go( 'limit' );
		}
		$row += array( 'ip_hash' => $ip, 'status' => 'new', 'created_at' => gmdate( 'Y-m-d H:i:s' ) );
		$wpdb->insert( self::t(), $row );
		$id = (int) $wpdb->insert_id;
		CMP_Audit::log( 'image_report_received', 'image_report', $id, null, array( 'relation' => $row['relation'] ), '', 0 );
		self::email_team( $id, $row );
		/* translators: %s: site name */
		wp_mail( $row['email'], sprintf( __( '[%s] We received your image report', 'cmp' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), sprintf( /* translators: 1: report number, 2: where */ __( "Thank you for your report (number %1\$d). The COL&B team will review it and act on valid reports within 48 hours. We'll email you when it's done.\n\nWhere you said the image is:\n%2\$s\n\nIf someone is in immediate danger, contact the police.", 'cmp' ), $id, $row['location'] ) );
		$go( 'sent' );
	}

	private static function email_team( $id, $row ) {
		$minor   = 'minor' === $row['relation'];
		$due     = wp_date( 'D M j, g:i A T', time() + self::HOURS * HOUR_IN_SECONDS );
		$subject = $minor
			/* translators: %d: report number */
			? sprintf( __( 'URGENT: report %d says an image may show someone under 18', 'cmp' ), $id )
			/* translators: 1: report number, 2: deadline */
			: sprintf( __( 'Urgent: intimate image report %1$d. Act by %2$s', 'cmp' ), $id, $due );
		$body = sprintf( /* translators: 1: relation, 2: where, 3: details, 4: deadline, 5: admin link */ __( "Someone reported an intimate image shared without consent.\n\nWho it shows: %1\$s\nWhere: %2\$s\nDetails: %3\$s\n\nThe law (TAKE IT DOWN Act) expects valid reports to be acted on within 48 hours: by %4\$s.\n\nOpen the report: %5\$s", 'cmp' ), self::relations()[ $row['relation'] ], $row['location'], '' !== $row['details'] ? $row['details'] : '-', $due, admin_url( 'users.php?page=' . self::PAGE ) );
		if ( $minor ) {
			$body .= "\n\n" . __( "This report says the image may show someone under 18. DON'T open, download, forward or screenshot it: that is itself a crime. Follow docs/privacy-security-plan.md section 5: report it to the NCMEC CyberTipline (report.cybertip.org) without the image, stop access (suspend the account, use Remove a private photo only after NCMEC or the police say so, because the law asks us to keep it for a year), and record what you did in the report's note.", 'cmp' );
		}
		wp_mail( get_option( 'admin_email' ), $subject, $body );
	}

	/* Admin */

	public static function open_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t() . " WHERE status = 'new'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function menu() {
		$open = self::open_count();
		/* translators: %d: open reports */
		add_users_page( __( 'Image reports', 'cmp' ), $open ? sprintf( __( 'Image reports (%d)', 'cmp' ), $open ) : __( 'Image reports', 'cmp' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$n = self::open_count();
		if ( $n ) {
			/* translators: %d: open reports */
			echo '<div class="notice notice-error"><p><strong>' . esc_html( sprintf( _n( '%d intimate image report is waiting.', '%d intimate image reports are waiting.', $n, 'cmp' ), $n ) ) . '</strong> ' . esc_html__( 'Valid reports must be acted on within 48 hours.', 'cmp' ) . ' <a href="' . esc_url( admin_url( 'users.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Image reports', 'cmp' ) . '</a></p></div>';
		}
	}

	public static function render() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::t() . " ORDER BY status = 'new' DESC, id DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$msg  = isset( $_GET['cmp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['cmp_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap"><h1>' . esc_html__( 'Image reports', 'cmp' ) . '</h1>';
		if ( '' !== $msg ) {
			echo '<div class="notice notice-info"><p>' . esc_html( $msg ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Reports of intimate images shared without consent, from the public form. Act on valid reports within 48 hours. If a report says the image may show someone under 18, don\'t open or download it: report it to the NCMEC CyberTipline first (see the privacy and security plan).', 'cmp' ) . '</p>';

		// Remove a private photo without seeing it.
		echo '<h2>' . esc_html__( 'Remove a private photo', 'cmp' ) . '</h2><p>' . esc_html__( 'Paste the link to a chastity photo, homework photo, private share link, feed photo or profile photo. The stored photo is deleted without being shown to you, and it\'s recorded in the audit log.', 'cmp' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cmp_image_report_admin" /><input type="hidden" name="do" value="remove" />' . wp_nonce_field( self::NONCE, '_wpnonce', true, false );
		echo '<p><input type="url" name="photo_url" class="large-text" required placeholder="https://colandb.com/?cmp_lockpic=123" aria-label="' . esc_attr__( 'Photo link', 'cmp' ) . '" /></p><p><label>' . esc_html__( 'Report number (optional)', 'cmp' ) . ' <input type="number" name="report" min="1" class="small-text" /></label></p>';
		submit_button( __( 'Delete this photo', 'cmp' ), 'delete', 'submit', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Delete this photo permanently?', 'cmp' ) ) . "');" ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Reports', 'cmp' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No reports.', 'cmp' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Received', 'cmp' ) . '</th><th>' . esc_html__( 'Report', 'cmp' ) . '</th><th>' . esc_html__( 'Status', 'cmp' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$due  = strtotime( $r->created_at . ' UTC' ) + self::HOURS * HOUR_IN_SECONDS;
			$left = $due - time();
			$when = esc_html( wp_date( 'M j, g:i A', strtotime( $r->created_at . ' UTC' ) ) );
			if ( 'new' === $r->status ) {
				$when .= '<br /><strong style="color:' . ( $left < 12 * HOUR_IN_SECONDS ? '#b32d2e' : '#996800' ) . '">' . esc_html( $left > 0 ? sprintf( /* translators: %s: time */ __( '%s left', 'cmp' ), human_time_diff( time(), $due ) ) : sprintf( /* translators: %s: time */ __( 'overdue by %s', 'cmp' ), human_time_diff( $due, time() ) ) ) . '</strong>';
			}
			$rel = isset( self::relations()[ $r->relation ] ) ? self::relations()[ $r->relation ] : $r->relation;
			echo '<tr><td>' . (int) $r->id . '</td><td>' . $when . '</td><td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				. ( 'minor' === $r->relation ? '<p><strong style="color:#b32d2e">' . esc_html__( 'May show someone under 18: do not open or download. Report to the CyberTipline.', 'cmp' ) . '</strong></p>' : '' )
				. '<p><strong>' . esc_html( $rel ) . '</strong></p><p><strong>' . esc_html__( 'Where:', 'cmp' ) . '</strong> ' . nl2br( esc_html( $r->location ) ) . '</p>'
				. ( '' !== (string) $r->details ? '<p><strong>' . esc_html__( 'Details:', 'cmp' ) . '</strong> ' . nl2br( esc_html( $r->details ) ) . '</p>' : '' )
				. '<p>' . esc_html( sprintf( /* translators: 1: email, 2: name, 3: signature */ __( 'From %1$s %2$s · signed "%3$s"', 'cmp' ), $r->email, '' !== (string) $r->name ? '(' . $r->name . ')' : '', $r->signature ) ) . '</p></td><td>';
			if ( 'new' === $r->status ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cmp_image_report_admin" /><input type="hidden" name="do" value="close" /><input type="hidden" name="report" value="' . (int) $r->id . '" />' . wp_nonce_field( self::NONCE, '_wpnonce', true, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. '<p><label>' . esc_html__( 'Note (what you did; sent to the reporter)', 'cmp' ) . '<br /><textarea name="note" rows="3" class="large-text" maxlength="2000"></textarea></label></p>'
					. '<p><label><input type="checkbox" name="tell" value="1" checked /> ' . esc_html__( 'Email the reporter', 'cmp' ) . '</label></p>'
					. '<p><button type="submit" name="status" value="removed" class="button button-primary">' . esc_html__( 'Removed', 'cmp' ) . '</button> <button type="submit" name="status" value="declined" class="button">' . esc_html__( 'Not actioned', 'cmp' ) . '</button></p></form>';
			} else {
				echo esc_html( 'removed' === $r->status ? __( 'Removed', 'cmp' ) : __( 'Not actioned', 'cmp' ) ) . '<br /><span class="description">' . esc_html( wp_date( 'M j, g:i A', strtotime( $r->handled_at . ' UTC' ) ) ) . '</span>' . ( '' !== (string) $r->note ? '<p>' . nl2br( esc_html( $r->note ) ) . '</p>' : '' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function handle_admin() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cmp' ) );
		}
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$report = isset( $_POST['report'] ) ? absint( $_POST['report'] ) : 0;
		$back   = function ( $msg ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'cmp_msg' => rawurlencode( $msg ) ), admin_url( 'users.php' ) ) );
			exit;
		};
		if ( 'remove' === $do ) {
			$url = isset( $_POST['photo_url'] ) ? esc_url_raw( wp_unslash( $_POST['photo_url'] ) ) : '';
			$back( self::remove_by_url( $url, $report ) );
		}
		$r      = $report ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', $report ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( 'close' !== $do || ! $r || 'new' !== $r->status || ! in_array( $status, array( 'removed', 'declined' ), true ) ) {
			$back( __( 'Nothing changed.', 'cmp' ) );
		}
		$note = isset( $_POST['note'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['note'] ) ), 0, 2000 ) : '';
		$wpdb->update( self::t(), array( 'status' => $status, 'note' => $note, 'handled_at' => gmdate( 'Y-m-d H:i:s' ), 'handled_by' => get_current_user_id() ), array( 'id' => $r->id ) );
		CMP_Audit::log( 'image_report_closed', 'image_report', (int) $r->id, array( 'status' => 'new' ), array( 'status' => $status ) );
		if ( ! empty( $_POST['tell'] ) ) {
			$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			/* translators: 1: site, 2: report number */
			wp_mail( $r->email, sprintf( __( '[%1$s] Your image report %2$d', 'cmp' ), $site, $r->id ), ( 'removed' === $status ? __( 'Thank you for your report. We have removed the image you reported.', 'cmp' ) : __( 'Thank you for your report. We reviewed it and didn\'t remove anything.', 'cmp' ) ) . ( '' !== $note ? "\n\n" . $note : '' ) . "\n\n" . __( 'If you have questions, reply to this email.', 'cmp' ) );
		}
		/* translators: %d: report number */
		$back( sprintf( __( 'Report %d closed.', 'cmp' ), $r->id ) );
	}

	/**
	 * Delete the stored photo a site link points to, without reading it.
	 *
	 * @return string What happened, for the admin notice.
	 */
	public static function remove_by_url( $url, $report = 0 ) {
		global $wpdb;
		$q = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$actor = get_current_user_id();
		$done  = '';
		if ( ! empty( $q['cmp_share'] ) ) {
			$sid   = (int) strtok( (string) $q['cmp_share'], '.' );
			$share = $sid ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CMP_Photo_Shares::t() . ' WHERE id = %d', $sid ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $share ) {
				$q['cmp_lockpic'] = (int) $share->event_id;
			}
		}
		if ( ! empty( $q['cmp_lockpic'] ) ) {
			$e = CMP_Chastity::event( absint( $q['cmp_lockpic'] ) );
			if ( $e && null !== $e->photo ) {
				CMP_Chastity::delete_photo( $e, 'site_team', $actor );
				$done = __( 'Chastity photo deleted. Its share links stopped working.', 'cmp' );
			}
		} elseif ( ! empty( $q['cmp_proof'] ) ) {
			$e = CMP_Homework::entry( absint( $q['cmp_proof'] ) );
			if ( $e && null !== $e->photo ) {
				CMP_Homework::delete_photo( $e, 'site_team', $actor );
				$done = __( 'Homework photo deleted.', 'cmp' );
			}
		} elseif ( ! empty( $q['cmp_postpic'] ) ) {
			$id = absint( $q['cmp_postpic'] );
			if ( $wpdb->delete( CMP_Install::table( 'post_photos' ), array( 'id' => $id ), array( '%d' ) ) ) {
				CMP_Audit::log( 'feed_photo_deleted', 'post_photo', $id, null, array( 'by' => 'site_team' ), '', $actor );
				$done = __( 'Feed photo deleted.', 'cmp' );
			}
		} elseif ( ! empty( $q['cmp_photo'] ) && preg_match( '/^(\d+)-(avatar|cover)$/', (string) $q['cmp_photo'], $m ) ) {
			if ( CMP_Profile_Images::get( (int) $m[1], $m[2] ) ) {
				CMP_Profile_Images::delete( (int) $m[1], $m[2] );
				CMP_Audit::log( 'profile_photo_deleted', 'user', (int) $m[1], null, array( 'kind' => $m[2], 'by' => 'site_team' ), '', $actor );
				$done = __( 'Profile photo deleted.', 'cmp' );
			}
		}
		if ( '' === $done ) {
			return __( 'No stored photo found at that link (it may already be gone). Nothing was deleted.', 'cmp' );
		}
		if ( $report ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET note = CONCAT(COALESCE(note, \'\'), %s) WHERE id = %d', "\n" . gmdate( 'Y-m-d H:i' ) . ' UTC: ' . $done, $report ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $done;
	}

	/* Retention and privacy */

	public static function purge( $days ) {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Reports someone made, by their email (kept, not erased: legal and safety record). */
	public static function export_rows( $email ) {
		global $wpdb;
		$rows = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, relation, location, status FROM ' . self::t() . ' WHERE email = %s ORDER BY id', $email ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows[] = array( 'name' => __( 'Image report you made', 'cmp' ), 'value' => sprintf( '%d · %s UTC · %s · %s', $r->id, $r->created_at, $r->location, $r->status ) );
		}
		return $rows;
	}
}
