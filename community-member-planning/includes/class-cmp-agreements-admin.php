<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Users → Event agreements (0.40.0), administrators only:
 *
 * - each agreement with its page (or a button that creates one), the
 *   permanent QR link, and how many people agreed today / in 30 days;
 * - edit the title, intro, rules, full terms, how long an agreement lasts,
 *   and switch it on or off (a wording change starts a new version);
 * - who agreed, by date, with each person's account status, and a CSV
 *   download for the door list.
 */
class CMP_Agreements_Admin {

	const PAGE  = 'cmp-agreements';
	const NONCE = 'cmp_agreements_admin';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_cmp_agreement_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_cmp_agreement_page', array( __CLASS__, 'handle_page' ) );
		add_action( 'admin_post_cmp_agreement_csv', array( __CLASS__, 'handle_csv' ) );
	}

	public static function menu() {
		add_users_page( __( 'Event agreements', 'cmp' ), __( 'Event agreements', 'cmp' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	private static function url( $args = array() ) {
		return add_query_arg( array( 'page' => self::PAGE ) + $args, admin_url( 'users.php' ) );
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cmp' ) );
		}
	}

	private static function back( $msg, $args = array() ) {
		wp_safe_redirect( self::url( $args + array( 'cmp_msg' => rawurlencode( $msg ) ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Screens
	 * ---------------------------------------------------------------- */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen choices.
		$msg   = isset( $_GET['cmp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['cmp_msg'] ) ) : '';
		$edit  = isset( $_GET['edit'] ) ? CMP_Agreements::clean_key( wp_unslash( $_GET['edit'] ) ) : '';
		$signs = isset( $_GET['signs'] ) ? CMP_Agreements::clean_key( wp_unslash( $_GET['signs'] ) ) : '';
		$new   = ! empty( $_GET['new'] );
		// phpcs:enable
		echo '<div class="wrap"><h1>' . esc_html__( 'Event agreements', 'cmp' ) . '</h1>';
		if ( '' !== $msg ) {
			echo '<div class="notice notice-info"><p>' . esc_html( $msg ) . '</p></div>';
		}
		if ( $new || '' !== $edit ) {
			self::render_edit( $edit );
		} elseif ( '' !== $signs ) {
			self::render_signs( $signs );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_list() {
		echo '<p>' . esc_html__( 'Rules people agree to on their phone before joining an activity, such as the mosh pit. Print the QR link on posters: it always opens the agreement\'s page, even if the page is renamed. People sign in, or join free, and confirm.', 'cmp' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( self::url( array( 'new' => 1 ) ) ) . '">' . esc_html__( 'Add an agreement', 'cmp' ) . '</a></p>';
		$all = CMP_Agreements::all();
		if ( ! $all ) {
			echo '<p>' . esc_html__( 'No agreements yet.', 'cmp' ) . '</p>';
			return;
		}
		$today = get_gmt_from_date( wp_date( 'Y-m-d' ) . ' 00:00:00' );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Agreement', 'cmp' ) . '</th><th>' . esc_html__( 'Page and QR link', 'cmp' ) . '</th><th>' . esc_html__( 'Agreed', 'cmp' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $all as $key => $a ) {
			$page = CMP_Agreements::page_id( $key );
			echo '<tr><td><strong>' . esc_html( $a['title'] ) . '</strong> <code>' . esc_html( $key ) . '</code><br /><span class="description">'
				. esc_html( sprintf( /* translators: 1: version, 2: on/off */ __( 'Version %1$d · %2$s', 'cmp' ), $a['version'], $a['active'] ? __( 'On', 'cmp' ) : __( 'Off', 'cmp' ) ) )
				. ' · ' . esc_html( $a['renew_hours'] ? sprintf( /* translators: %d: hours */ __( 'asks again after %d hours', 'cmp' ), $a['renew_hours'] ) : __( 'asks again only when the rules change', 'cmp' ) ) . '</span></td><td>';
			if ( $page ) {
				echo '<a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank">' . esc_html( get_the_title( $page ) ) . '</a>';
			} else {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"><input type="hidden" name="action" value="cmp_agreement_page" /><input type="hidden" name="key" value="' . esc_attr( $key ) . '" />'
					. wp_nonce_field( self::NONCE, '_wpnonce', true, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. '<span class="description">' . esc_html__( 'No page yet.', 'cmp' ) . '</span> <button class="button button-small">' . esc_html__( 'Create the page', 'cmp' ) . '</button></form>'
					. '<br /><span class="description">' . esc_html( sprintf( /* translators: %s: shortcode */ __( 'Or add %s to any page.', 'cmp' ), '[cmp_agreement key="' . $key . '"]' ) ) . '</span>';
			}
			echo '<br /><code>' . esc_html( CMP_Agreements::qr_url( $key ) ) . '</code></td><td>'
				. esc_html( sprintf( /* translators: 1: today, 2: total */ __( '%1$d today · %2$d in all', 'cmp' ), CMP_Agreements::count( $key, $today ), CMP_Agreements::count( $key ) ) )
				. '</td><td><a href="' . esc_url( self::url( array( 'signs' => $key ) ) ) . '">' . esc_html__( 'Who agreed', 'cmp' ) . '</a> · <a href="' . esc_url( self::url( array( 'edit' => $key ) ) ) . '">' . esc_html__( 'Edit', 'cmp' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_edit( $key ) {
		$a = '' !== $key ? CMP_Agreements::get( $key ) : null;
		if ( '' !== $key && ! $a ) {
			echo '<p>' . esc_html__( 'Not found.', 'cmp' ) . '</p>';
			return;
		}
		$a = $a ? $a : array( 'key' => '', 'title' => '', 'intro' => '', 'rules' => array(), 'body' => '', 'renew_hours' => 12, 'active' => true, 'version' => 0 );
		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'All agreements', 'cmp' ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cmp_agreement_save" />' . wp_nonce_field( self::NONCE, '_wpnonce', true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<table class="form-table">';
		if ( $a['key'] ) {
			echo '<tr><th>' . esc_html__( 'Key', 'cmp' ) . '</th><td><code>' . esc_html( $a['key'] ) . '</code><input type="hidden" name="key" value="' . esc_attr( $a['key'] ) . '" /> <span class="description">' . esc_html( sprintf( /* translators: %d: version */ __( 'Version %d. Changing the title, intro, rules or full terms starts a new version, and everyone is asked to agree again.', 'cmp' ), $a['version'] ) ) . '</span></td></tr>';
		} else {
			echo '<tr><th><label for="ag_key">' . esc_html__( 'Key', 'cmp' ) . '</label></th><td><input id="ag_key" name="key" class="regular-text" required pattern="[a-z0-9_-]+" placeholder="mosh-pit" /><p class="description">' . esc_html__( 'Letters, numbers and dashes. Used in the QR link, so it can\'t be changed later.', 'cmp' ) . '</p></td></tr>';
		}
		echo '<tr><th><label for="ag_title">' . esc_html__( 'Title', 'cmp' ) . '</label></th><td><input id="ag_title" name="title" class="regular-text" required maxlength="120" value="' . esc_attr( $a['title'] ) . '" /></td></tr>'
			. '<tr><th><label for="ag_intro">' . esc_html__( 'Intro', 'cmp' ) . '</label></th><td><textarea id="ag_intro" name="intro" rows="2" class="large-text" maxlength="600">' . esc_textarea( $a['intro'] ) . '</textarea></td></tr>'
			. '<tr><th><label for="ag_rules">' . esc_html__( 'Rules', 'cmp' ) . '</label></th><td><textarea id="ag_rules" name="rules" rows="10" class="large-text" required>' . esc_textarea( implode( "\n", $a['rules'] ) ) . '</textarea><p class="description">' . esc_html__( 'One per line, shown numbered. Keep them the same as the poster.', 'cmp' ) . '</p></td></tr>'
			. '<tr><th><label for="ag_body">' . esc_html__( 'Full terms', 'cmp' ) . '</label></th><td><textarea id="ag_body" name="body" rows="16" class="large-text code">' . esc_textarea( $a['body'] ) . '</textarea><p class="description">' . esc_html__( 'Code of conduct, assumption of risk and release, shown under "Read the full …". Basic HTML (headings, lists, bold) is allowed. Have the organization\'s attorney review the release wording before relying on it.', 'cmp' ) . '</p></td></tr>'
			. '<tr><th><label for="ag_renew">' . esc_html__( 'Ask again after', 'cmp' ) . '</label></th><td><input id="ag_renew" name="renew_hours" type="number" min="0" max="8760" class="small-text" value="' . (int) $a['renew_hours'] . '" /> ' . esc_html__( 'hours', 'cmp' ) . '<p class="description">' . esc_html__( '12 means once per event night. 0 means only when the rules change.', 'cmp' ) . '</p></td></tr>'
			. '<tr><th>' . esc_html__( 'On', 'cmp' ) . '</th><td><label><input type="checkbox" name="active" value="1"' . checked( ! empty( $a['active'] ), true, false ) . ' /> ' . esc_html__( 'People can agree on the page', 'cmp' ) . '</label></td></tr>'
			. '</table><p><button class="button button-primary">' . esc_html__( 'Save', 'cmp' ) . '</button></p></form>';
		if ( ! empty( $a['history'] ) ) {
			echo '<h2>' . esc_html__( 'Earlier versions', 'cmp' ) . '</h2>';
			foreach ( array_reverse( $a['history'], true ) as $v => $h ) {
				echo '<details><summary>' . esc_html( sprintf( /* translators: 1: version, 2: from, 3: to */ __( 'Version %1$d (%2$s to %3$s UTC)', 'cmp' ), $v, $h['from'], $h['to'] ) ) . '</summary><ol>';
				foreach ( $h['rules'] as $r ) {
					echo '<li>' . esc_html( $r ) . '</li>';
				}
				echo '</ol><div>' . wp_kses_post( $h['body'] ) . '</div></details>';
			}
		}
	}

	private static function status( $r ) {
		$user = get_userdata( (int) $r->user_id );
		if ( ! $user ) {
			return __( 'account removed', 'cmp' );
		}
		return CMP_Email_Verification::is_verified( $user->ID ) ? __( 'email confirmed', 'cmp' ) : __( 'email not confirmed yet', 'cmp' );
	}

	private static function via( $via ) {
		$labels = array( 'member' => __( 'already signed in', 'cmp' ), 'signin' => __( 'signed in', 'cmp' ), 'signup' => __( 'joined', 'cmp' ) );
		return $labels[ $via ] ?? $via;
	}

	private static function dates() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$ok   = static function ( $d ) {
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '';
		};
		$from = isset( $_GET['from'] ) ? $ok( sanitize_text_field( wp_unslash( $_GET['from'] ) ) ) : wp_date( 'Y-m-d', time() - 6 * DAY_IN_SECONDS );
		$to   = isset( $_GET['to'] ) ? $ok( sanitize_text_field( wp_unslash( $_GET['to'] ) ) ) : wp_date( 'Y-m-d' );
		// phpcs:enable
		return array( $from, $to );
	}

	private static function render_signs( $key ) {
		$a = CMP_Agreements::get( $key );
		if ( ! $a ) {
			echo '<p>' . esc_html__( 'Not found.', 'cmp' ) . '</p>';
			return;
		}
		list( $from, $to ) = self::dates();
		$rows = CMP_Agreements::rows( $key, $from, $to );
		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'All agreements', 'cmp' ) . '</a></p><h2>' . esc_html( sprintf( /* translators: %s: title */ __( 'Who agreed: %s', 'cmp' ), $a['title'] ) ) . '</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'users.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><input type="hidden" name="signs" value="' . esc_attr( $key ) . '" />'
			. '<label>' . esc_html__( 'From', 'cmp' ) . ' <input type="date" name="from" value="' . esc_attr( $from ) . '" /></label> <label>' . esc_html__( 'to', 'cmp' ) . ' <input type="date" name="to" value="' . esc_attr( $to ) . '" /></label> <button class="button">' . esc_html__( 'Show', 'cmp' ) . '</button> '
			. '<a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'cmp_agreement_csv', 'key' => $key, 'from' => $from, 'to' => $to ), admin_url( 'admin-post.php' ) ), self::NONCE ) ) . '">' . esc_html__( 'Download CSV', 'cmp' ) . '</a></form>';
		echo '<p>' . esc_html( sprintf( /* translators: %d: count */ _n( '%d agreement in these dates.', '%d agreements in these dates.', count( $rows ), 'cmp' ), count( $rows ) ) ) . '</p>';
		if ( ! $rows ) {
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'cmp' ) . '</th><th>' . esc_html__( 'Name', 'cmp' ) . '</th><th>' . esc_html__( 'Email', 'cmp' ) . '</th><th>' . esc_html__( 'Account', 'cmp' ) . '</th><th>' . esc_html__( 'How', 'cmp' ) . '</th><th>' . esc_html__( 'Version', 'cmp' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( wp_date( 'M j, Y g:i A', strtotime( $r->created_at . ' UTC' ) ) ) . '</td><td>' . esc_html( $r->name ) . '</td><td>' . esc_html( $r->email ) . '</td><td>' . esc_html( self::status( $r ) ) . '</td><td>' . esc_html( self::via( $r->via ) ) . '</td><td>' . (int) $r->version . ( (int) $r->version !== (int) $a['version'] ? ' ' . esc_html__( '(earlier rules)', 'cmp' ) : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ---------------------------------------------------------------- */

	public static function handle_save() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() checked it.
		$key   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$input = array(
			'title'       => isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- CMP_Agreements::normalize() cleans each field.
			'intro'       => isset( $_POST['intro'] ) ? wp_unslash( $_POST['intro'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'rules'       => isset( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'body'        => isset( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'renew_hours' => isset( $_POST['renew_hours'] ) ? absint( $_POST['renew_hours'] ) : 0,
		);
		$active = ! empty( $_POST['active'] );
		// phpcs:enable
		$saved = CMP_Agreements::save( $key, $input, $active );
		if ( is_wp_error( $saved ) ) {
			self::back( $saved->get_error_message(), CMP_Agreements::get( CMP_Agreements::clean_key( $key ) ) ? array( 'edit' => CMP_Agreements::clean_key( $key ) ) : array( 'new' => 1 ) );
		}
		/* translators: 1: title, 2: version */
		self::back( sprintf( __( 'Saved "%1$s" (version %2$d).', 'cmp' ), $saved['title'], $saved['version'] ) );
	}

	public static function handle_page() {
		self::guard();
		$key = isset( $_POST['key'] ) ? CMP_Agreements::clean_key( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$a   = CMP_Agreements::get( $key );
		if ( ! $a ) {
			self::back( __( 'Nothing changed.', 'cmp' ) );
		}
		if ( CMP_Agreements::page_id( $key ) ) {
			self::back( __( 'That agreement already has a page.', 'cmp' ) );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $a['title'],
				'post_name'    => $key,
				'post_content' => '[' . CMP_Agreement_Form::SHORTCODE . ' key="' . $key . '"]',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			self::back( $id->get_error_message() );
		}
		CMP_Audit::log( 'agreement_page_created', 'agreement', (int) $id, null, array( 'key' => $key ) );
		/* translators: %s: page address */
		self::back( sprintf( __( 'Page created: %s', 'cmp' ), get_permalink( $id ) ) );
	}

	/** Spreadsheet-safe cell: stops a value starting with = + - @ running as a formula. */
	private static function cell( $v ) {
		$v = (string) $v;
		return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
	}

	public static function handle_csv() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cmp' ) );
		}
		$key = isset( $_GET['key'] ) ? CMP_Agreements::clean_key( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$a   = CMP_Agreements::get( $key );
		if ( ! $a ) {
			wp_die( esc_html__( 'Not found.', 'cmp' ) );
		}
		list( $from, $to ) = self::dates();
		CMP_Audit::log( 'agreement_list_downloaded', 'agreement', 0, null, array( 'key' => $key, 'from' => $from, 'to' => $to ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $key . '-' . $from . '-to-' . $to . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 marker so Excel shows names correctly.
		fputcsv( $out, array( 'Agreed at', 'Name', 'Email', 'Account', 'How', 'Version' ) );
		foreach ( CMP_Agreements::rows( $key, $from, $to, 100000 ) as $r ) {
			fputcsv( $out, array_map( array( __CLASS__, 'cell' ), array( wp_date( 'Y-m-d H:i', strtotime( $r->created_at . ' UTC' ) ), $r->name, $r->email, self::status( $r ), self::via( $r->via ), $r->version ) ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
