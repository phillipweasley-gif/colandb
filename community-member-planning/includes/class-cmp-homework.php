<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Homework programs (0.7.0), modeled on the owner's example weekly homework
 * tracker: the leading side of an active directed dynamic (CMP_Dynamics)
 * builds a weekly program for the other member; the member logs each task
 * day by day with the proof it asks for; weekly quotas, "Met / Needs work"
 * and "Quotas met X/N" are calculated.
 *
 * - Weeks run Monday–Sunday. Day statuses: done, missed, moved
 *   (rescheduled and communicated), na. The member logs done / moved / na;
 *   only the lead marks missed. A done day the lead marked "not good
 *   enough" doesn't count toward the quota.
 * - Proof: none, text, photo, photo + text, written report. Photos are
 *   re-encoded JPEGs (no camera or location metadata) kept in the database,
 *   served only through maybe_serve_photo() to the member and their lead.
 * - When the dynamic ends (and no other directed dynamic remains between
 *   them) the program is archived: the lead loses access at once, including
 *   to photos; the member keeps their own history.
 */
class CMP_Homework {

	const TAB         = 'homework';
	const NONCE       = 'cmp_homework';
	const PHOTO_QUERY = 'cmp_proof';
	const MAX_BYTES   = 5242880;
	const PHOTO_MAX   = 1600;
	const MAX_TASKS   = 30;
	const STATUSES    = array( 'done', 'missed', 'moved', 'na' );

	public static function init() {
		foreach ( array( 'program', 'task', 'retire', 'log', 'review' ) as $a ) {
			add_action( 'admin_post_cmp_hw_' . $a, array( __CLASS__, 'handle_' . $a ) );
			add_action( 'admin_post_nopriv_cmp_hw_' . $a, array( 'CMP_Member_Area', 'redirect_to_login' ) );
		}
		add_action( 'cmp_dynamic_ended', array( __CLASS__, 'on_dynamic_ended' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_photo' ), 1 );
	}

	public static function categories() {
		return array(
			'ritual'    => __( 'Daily ritual', 'cmp' ),
			'body'      => __( 'Body prep', 'cmp' ),
			'service'   => __( 'Domestic service', 'cmp' ),
			'education' => __( 'Education', 'cmp' ),
			'attention' => __( 'Attention', 'cmp' ),
			'reflection' => __( 'Reflection', 'cmp' ),
			'chastity'  => __( 'Chastity', 'cmp' ),
			'fitness'   => __( 'Fitness', 'cmp' ),
			'other'     => __( 'Other', 'cmp' ),
		);
	}

	public static function proofs() {
		return array(
			'none'       => __( 'No proof', 'cmp' ),
			'text'       => __( 'Text', 'cmp' ),
			'photo'      => __( 'Photo', 'cmp' ),
			'photo_text' => __( 'Photo + text', 'cmp' ),
			'report'     => __( 'Written report', 'cmp' ),
		);
	}

	public static function status_labels() {
		return array( 'done' => __( 'Done', 'cmp' ), 'missed' => __( 'Missed', 'cmp' ), 'moved' => __( 'Moved', 'cmp' ), 'na' => __( 'N/A', 'cmp' ) );
	}

	public static function url( $args = array(), $notice = '' ) {
		$url = add_query_arg( array_merge( array( 'cmp_tab' => self::TAB ), $args ), CMP_Settings::member_page_url() );
		return $notice ? add_query_arg( 'cmp_notice', $notice, $url ) : $url;
	}

	public static function notices() {
		return array(
			'hw_saved'    => array( 'success', __( 'Saved.', 'cmp' ) ),
			'hw_created'  => array( 'success', __( 'Program created. Add its tasks below.', 'cmp' ) ),
			'hw_logged'   => array( 'success', __( 'Logged. Thank you.', 'cmp' ) ),
			'hw_reviewed' => array( 'success', __( 'Review saved; they have been notified.', 'cmp' ) ),
			'hw_invalid'  => array( 'error', __( 'That couldn\'t be saved. Check the highlighted answers and try again.', 'cmp' ) ),
			'hw_proof'    => array( 'error', __( 'This task needs proof. Add what it asks for and log it again.', 'cmp' ) ),
			'hw_photo'    => array( 'error', __( 'That photo couldn\'t be used. Use a JPEG, PNG or WebP photo under 5 MB.', 'cmp' ) ),
			'hw_gone'     => array( 'error', __( 'That program isn\'t available.', 'cmp' ) ),
		);
	}

	/* ------------------------------------------------------------------
	 * Data
	 * ---------------------------------------------------------------- */

	private static function t( $name ) {
		return CMP_Install::table( $name );
	}

	public static function program( $id ) {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'programs' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $p ) {
			$p->consequences = $p->consequences ? (array) json_decode( $p->consequences, true ) : array();
		}
		return $p;
	}

	public static function programs_for( $user_id, $role ) {
		global $wpdb;
		$col = 'lead' === $role ? 'lead_id' : 'member_id';
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'programs' ) . " WHERE $col = %d ORDER BY status = 'active' DESC, id DESC", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function tasks( $program_id, $active_only = true ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'tasks' ) . ' WHERE program_id = %d' . ( $active_only ? ' AND active = 1' : '' ) . ' ORDER BY position, id', $program_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function task( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'tasks' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Entries of a program between two dates: [task_id][Y-m-d] => row. */
	public static function entries( $program_id, $from, $to ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, task_id, day, status, note, photo_sha, review, review_note, created_at, updated_at FROM ' . self::t( 'task_entries' ) . ' WHERE program_id = %d AND day BETWEEN %s AND %s', $program_id, $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->task_id ][ $r->day ] = $r;
		}
		return $out;
	}

	public static function entry( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'task_entries' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ------------------------------------------------------------------
	 * Rules
	 * ---------------------------------------------------------------- */

	/** The lead, while the program is active and the dynamic still allows it. */
	public static function can_lead( $program, $user_id ) {
		return $program && 'active' === $program->status && (int) $program->lead_id === (int) $user_id && CMP_Dynamics::lead_can_direct( $user_id, $program->member_id );
	}

	/** The member, always (their own history), but logging only while active. */
	public static function can_member( $program, $user_id ) {
		return $program && (int) $program->member_id === (int) $user_id;
	}

	public static function can_view( $program, $user_id ) {
		return self::can_member( $program, $user_id ) || self::can_lead( $program, $user_id );
	}

	/** Monday of the week containing $day (Y-m-d, site time). */
	public static function week_start( $day = '' ) {
		$day = $day && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ? $day : wp_date( 'Y-m-d' );
		$ts  = strtotime( $day . ' 12:00:00' );
		$dow = (int) gmdate( 'N', $ts ); // 1 = Monday.
		return gmdate( 'Y-m-d', $ts - ( $dow - 1 ) * DAY_IN_SECONDS );
	}

	public static function week_days( $monday ) {
		$days = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$days[] = gmdate( 'Y-m-d', strtotime( $monday . ' 12:00:00' ) + $i * DAY_IN_SECONDS );
		}
		return $days;
	}

	/** Whether a logged day counts toward the quota. */
	public static function counts( $entry ) {
		return $entry && 'done' === $entry->status && 'rejected' !== $entry->review;
	}

	/**
	 * @return array( tasks => [task_id => [done, min, met]], met, total, overall, to_review, logged, moved )
	 */
	public static function summary( $program_id, $monday ) {
		$days    = self::week_days( $monday );
		$tasks   = self::tasks( $program_id );
		$entries = self::entries( $program_id, $days[0], $days[6] );
		$out     = array( 'tasks' => array(), 'met' => 0, 'total' => count( $tasks ), 'to_review' => 0, 'logged' => 0, 'moved' => 0 );
		foreach ( $tasks as $task ) {
			$done = 0;
			foreach ( $days as $d ) {
				$e = isset( $entries[ $task->id ][ $d ] ) ? $entries[ $task->id ][ $d ] : null;
				if ( $e ) {
					$out['logged']++;
					$done += self::counts( $e ) ? 1 : 0;
					$out['moved'] += 'moved' === $e->status ? 1 : 0;
					$out['to_review'] += ( 'done' === $e->status && '' === $e->review && 'none' !== $task->proof ) ? 1 : 0;
				}
			}
			$met                         = $done >= (int) $task->weekly_min;
			$out['met']                 += $met ? 1 : 0;
			$out['tasks'][ (int) $task->id ] = array( 'done' => $done, 'min' => (int) $task->weekly_min, 'met' => $met );
		}
		$out['overall'] = $out['total'] && $out['met'] === $out['total'];
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Photos
	 * ---------------------------------------------------------------- */

	/**
	 * Resizes (longest side at most PHOTO_MAX, no cropping) and re-encodes
	 * as JPEG, which drops camera and location metadata.
	 *
	 * @return string|WP_Error JPEG bytes.
	 */
	public static function process_photo( $path ) {
		$size = is_readable( $path ) ? (int) filesize( $path ) : 0;
		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return new WP_Error( 'hw_photo', 'size' );
		}
		$mime = function_exists( 'finfo_open' ) ? (string) finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $path ) : '';
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return new WP_Error( 'hw_photo', 'type' );
		}
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return new WP_Error( 'hw_photo', 'read' );
		}
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate();
		}
		$editor->resize( self::PHOTO_MAX, self::PHOTO_MAX, false );
		$editor->set_quality( 82 );
		$out   = wp_tempnam( 'cmp-proof.jpg' );
		$saved = $editor->save( $out . '.jpg', 'image/jpeg' );
		@unlink( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return new WP_Error( 'hw_photo', 'save' );
		}
		$data = (string) file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		@unlink( $saved['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return "\xFF\xD8\xFF" === substr( $data, 0, 3 ) ? $data : new WP_Error( 'hw_photo', 'save' );
	}

	public static function photo_url( $entry ) {
		return add_query_arg( array( self::PHOTO_QUERY => (int) $entry->id, 'v' => substr( (string) $entry->photo_sha, 0, 12 ) ), home_url( '/' ) );
	}

	/** ?cmp_proof=<entry id>: only the member and their current lead; everyone else gets 404. */
	public static function maybe_serve_photo() {
		if ( ! isset( $_GET[ self::PHOTO_QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Vary: Cookie' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$entry   = self::entry( absint( $_GET[ self::PHOTO_QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$program = $entry ? self::program( $entry->program_id ) : null;
		$viewer  = get_current_user_id();
		if ( ! $entry || ! $entry->photo || ! $program || ! $viewer || ! self::can_view( $program, $viewer ) ) {
			nocache_headers();
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Not found';
			exit;
		}
		header( 'Cache-Control: private, no-cache, max-age=0' );
		header( 'ETag: "' . $entry->photo_sha . '"' );
		status_header( 200 );
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . strlen( $entry->photo ) );
		header( 'Content-Disposition: inline; filename="proof.jpg"' );
		header( "Content-Security-Policy: default-src 'none'" );
		echo $entry->photo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JPEG bytes.
		exit;
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ---------------------------------------------------------------- */

	private static function guard() {
		$user_id = get_current_user_id();
		if ( ! CMP_Access::is_member( $user_id ) ) {
			wp_safe_redirect( CMP_Settings::member_page_url() );
			exit;
		}
		if ( ! isset( $_POST['_cmp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cmp_nonce'] ) ), self::NONCE ) ) {
			wp_safe_redirect( self::url( array(), 'expired' ) );
			exit;
		}
		return $user_id;
	}

	private static function go( $args, $notice ) {
		wp_safe_redirect( self::url( $args, $notice ) );
		exit;
	}

	private static function posted_program_for_lead( $user_id ) {
		$program = self::program( isset( $_POST['program'] ) ? absint( $_POST['program'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		if ( ! self::can_lead( $program, $user_id ) ) {
			self::go( array(), 'hw_gone' );
		}
		return $program;
	}

	private static function text( $key, $max, $multiline = false ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers checked the nonce.
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		$v   = trim( $multiline ? sanitize_textarea_field( (string) $raw ) : sanitize_text_field( (string) $raw ) );
		return mb_substr( $v, 0, $max );
	}

	/** Create (for a member you lead) or update a program's title, notes and consequence ladder. */
	public static function handle_program() {
		global $wpdb;
		$user_id = self::guard();
		$title   = self::text( 'title', 120 );
		$notes   = self::text( 'notes', 2000, true );
		$ladder  = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce in guard(); each part sanitized below.
		foreach ( isset( $_POST['ladder'] ) && is_array( $_POST['ladder'] ) ? wp_unslash( $_POST['ladder'] ) : array() as $row ) {
			$level = is_array( $row ) && isset( $row['level'] ) ? mb_substr( trim( sanitize_text_field( (string) $row['level'] ) ), 0, 60 ) : '';
			if ( '' === $level ) {
				continue;
			}
			$ladder[] = array(
				'level'      => $level,
				'examples'   => mb_substr( trim( sanitize_text_field( isset( $row['examples'] ) ? (string) $row['examples'] : '' ) ), 0, 300 ),
				'correction' => mb_substr( trim( sanitize_text_field( isset( $row['correction'] ) ? (string) $row['correction'] : '' ) ), 0, 300 ),
			);
		}
		$ladder = array_slice( $ladder, 0, 8 );
		$id     = isset( $_POST['program'] ) ? absint( $_POST['program'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $title ) {
			self::go( $id ? array( 'program' => $id ) : array(), 'hw_invalid' );
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( $id ) {
			$program = self::posted_program_for_lead( $user_id );
			$wpdb->update( self::t( 'programs' ), array( 'title' => $title, 'notes' => $notes, 'consequences' => wp_json_encode( $ladder ), 'updated_at' => $now ), array( 'id' => $program->id ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
			CMP_Audit::log( 'program_updated', 'program', $program->id );
			self::go( array( 'program' => $program->id ), 'hw_saved' );
		}
		$member = isset( $_POST['member'] ) ? absint( $_POST['member'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $member || ! CMP_Dynamics::lead_can_direct( $user_id, $member ) ) {
			self::go( array(), 'hw_gone' );
		}
		$wpdb->insert(
			self::t( 'programs' ),
			array( 'lead_id' => $user_id, 'member_id' => $member, 'title' => $title, 'notes' => $notes, 'consequences' => wp_json_encode( $ladder ), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$pid = (int) $wpdb->insert_id;
		CMP_Audit::log( 'program_created', 'program', $pid, null, array( 'lead' => $user_id, 'member' => $member ) );
		/* translators: 1: lead's name, 2: program title */
		CMP_Notifications::add( $member, 'assignment', sprintf( __( '%1$s set up homework for you: "%2$s".', 'cmp' ), wp_get_current_user()->display_name, $title ), self::url( array( 'program' => $pid ) ) );
		self::go( array( 'program' => $pid ), 'hw_created' );
	}

	/** Add or edit a task. */
	public static function handle_task() {
		global $wpdb;
		$user_id  = self::guard();
		$program  = self::posted_program_for_lead( $user_id );
		$task_id  = isset( $_POST['task'] ) ? absint( $_POST['task'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$title    = self::text( 'title', 120 );
		$category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$proof    = isset( $_POST['proof'] ) ? sanitize_key( wp_unslash( $_POST['proof'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$min      = isset( $_POST['weekly_min'] ) ? absint( $_POST['weekly_min'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $title || ! isset( self::categories()[ $category ] ) || ! isset( self::proofs()[ $proof ] ) || $min < 1 || $min > 7 ) {
			self::go( array( 'program' => $program->id ), 'hw_invalid' );
		}
		$row = array(
			'title'       => $title,
			'category'    => $category,
			'weekly_min'  => $min,
			'what_counts' => self::text( 'what_counts', 500, true ),
			'proof'       => $proof,
			'standard'    => self::text( 'standard', 300 ),
		);
		$task = $task_id ? self::task( $task_id ) : null;
		if ( $task_id && ( ! $task || (int) $task->program_id !== (int) $program->id ) ) {
			self::go( array( 'program' => $program->id ), 'hw_gone' );
		}
		if ( $task ) {
			$wpdb->update( self::t( 'tasks' ), $row, array( 'id' => $task->id ), array( '%s', '%s', '%d', '%s', '%s', '%s' ), array( '%d' ) );
			CMP_Audit::log( 'task_updated', 'program', $program->id, null, array( 'task' => (int) $task->id ) );
		} else {
			if ( count( self::tasks( $program->id ) ) >= self::MAX_TASKS ) {
				self::go( array( 'program' => $program->id ), 'hw_invalid' );
			}
			$row += array( 'program_id' => $program->id, 'position' => count( self::tasks( $program->id, false ) ), 'active' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s' ) );
			$wpdb->insert( self::t( 'tasks' ), $row, array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ) );
			CMP_Audit::log( 'task_added', 'program', $program->id, null, array( 'task' => (int) $wpdb->insert_id ) );
			/* translators: 1: lead's name, 2: task */
			CMP_Notifications::add( $program->member_id, 'assignment', sprintf( __( '%1$s added a task to your homework: "%2$s".', 'cmp' ), wp_get_current_user()->display_name, $title ), self::url( array( 'program' => $program->id ) ) );
		}
		self::go( array( 'program' => $program->id ), 'hw_saved' );
	}

	/** Retire a task: it stops appearing; its history is kept. */
	public static function handle_retire() {
		global $wpdb;
		$user_id = self::guard();
		$program = self::posted_program_for_lead( $user_id );
		$task    = self::task( isset( $_POST['task'] ) ? absint( $_POST['task'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $task && (int) $task->program_id === (int) $program->id ) {
			$wpdb->update( self::t( 'tasks' ), array( 'active' => 0 ), array( 'id' => $task->id ), array( '%d' ), array( '%d' ) );
			CMP_Audit::log( 'task_retired', 'program', $program->id, null, array( 'task' => (int) $task->id ) );
		}
		self::go( array( 'program' => $program->id ), 'hw_saved' );
	}

	/** The member logs a day: done (with proof), moved or n/a. */
	public static function handle_log() {
		global $wpdb;
		$user_id = self::guard();
		$task    = self::task( isset( $_POST['task'] ) ? absint( $_POST['task'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$program = $task ? self::program( $task->program_id ) : null;
		if ( ! $program || ! self::can_member( $program, $user_id ) || 'active' !== $program->status || ! $task->active ) {
			self::go( array(), 'hw_gone' );
		}
		$day    = isset( $_POST['day'] ) ? sanitize_text_field( wp_unslash( $_POST['day'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$back   = array( 'program' => $program->id, 'cmp_week' => self::week_start( $day ), 'cmp_day' => $day );
		// Only today and earlier, within the last 8 days; "missed" is the lead's call.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || $day > wp_date( 'Y-m-d' ) || $day < wp_date( 'Y-m-d', time() - 8 * DAY_IN_SECONDS ) || ! in_array( $status, array( 'done', 'moved', 'na' ), true ) ) {
			self::go( $back, 'hw_invalid' );
		}
		$note  = self::text( 'note', 'report' === $task->proof ? 5000 : 1000, true );
		$photo = null;
		$file  = isset( $_FILES['photo'] ) && is_array( $_FILES['photo'] ) ? $_FILES['photo'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( $file && isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'] ) {
			if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
				self::go( $back, 'hw_photo' );
			}
			$photo = self::process_photo( $file['tmp_name'] );
			if ( is_wp_error( $photo ) ) {
				self::go( $back, 'hw_photo' );
			}
		}
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, photo_sha FROM ' . self::t( 'task_entries' ) . ' WHERE task_id = %d AND day = %s', $task->id, $day ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( 'done' === $status ) {
			$needs_photo = in_array( $task->proof, array( 'photo', 'photo_text' ), true );
			$needs_text  = in_array( $task->proof, array( 'text', 'photo_text', 'report' ), true );
			$has_photo   = $photo || ( $existing && $existing->photo_sha );
			if ( ( $needs_photo && ! $has_photo ) || ( $needs_text && '' === $note ) ) {
				self::go( $back, 'hw_proof' );
			}
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array( 'status' => $status, 'note' => $note, 'review' => '', 'review_note' => '', 'updated_at' => $now );
		if ( $photo ) {
			$row['photo']     = $photo;
			$row['photo_sha'] = hash( 'sha256', $photo );
		}
		if ( $existing ) {
			$wpdb->update( self::t( 'task_entries' ), $row, array( 'id' => $existing->id ) );
		} else {
			$wpdb->insert( self::t( 'task_entries' ), $row + array( 'task_id' => $task->id, 'program_id' => $program->id, 'member_id' => $user_id, 'day' => $day, 'created_at' => $now ) );
		}
		CMP_Audit::log( 'homework_logged', 'program', $program->id, null, array( 'task' => (int) $task->id, 'day' => $day, 'status' => $status, 'photo' => (bool) $photo ) );
		if ( 'done' === $status && 'none' !== $task->proof ) {
			/* translators: 1: member's name, 2: task, 3: date */
			CMP_Notifications::add( $program->lead_id, 'submission', sprintf( __( '%1$s logged "%2$s" for %3$s. It\'s ready for review.', 'cmp' ), wp_get_current_user()->display_name, $task->title, wp_date( 'D M j', strtotime( $day . ' 12:00' ) ) ), self::url( array( 'program' => $program->id, 'cmp_week' => self::week_start( $day ) ) ) );
		}
		self::go( $back, 'hw_logged' );
	}

	/** The lead reviews a logged day (accept / not good enough) or marks a day missed. */
	public static function handle_review() {
		global $wpdb;
		$user_id = self::guard();
		$program = self::posted_program_for_lead( $user_id );
		$task    = self::task( isset( $_POST['task'] ) ? absint( $_POST['task'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$day     = isset( $_POST['day'] ) ? sanitize_text_field( wp_unslash( $_POST['day'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$verdict = isset( $_POST['verdict'] ) ? sanitize_key( wp_unslash( $_POST['verdict'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$back    = array( 'program' => $program->id, 'cmp_week' => self::week_start( $day ) );
		if ( ! $task || (int) $task->program_id !== (int) $program->id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || ! in_array( $verdict, array( 'accepted', 'rejected', 'missed' ), true ) ) {
			self::go( $back, 'hw_invalid' );
		}
		$note     = self::text( 'review_note', 500, true );
		$now      = gmdate( 'Y-m-d H:i:s' );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'task_entries' ) . ' WHERE task_id = %d AND day = %s', $task->id, $day ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( 'missed' === $verdict ) {
			$row = array( 'status' => 'missed', 'review' => '', 'review_note' => $note, 'updated_at' => $now );
		} else {
			if ( ! $existing ) {
				self::go( $back, 'hw_invalid' );
			}
			$row = array( 'review' => $verdict, 'review_note' => $note, 'updated_at' => $now );
		}
		if ( $existing ) {
			$wpdb->update( self::t( 'task_entries' ), $row, array( 'id' => $existing->id ) );
		} else {
			$wpdb->insert( self::t( 'task_entries' ), $row + array( 'task_id' => $task->id, 'program_id' => $program->id, 'member_id' => $program->member_id, 'day' => $day, 'note' => '', 'created_at' => $now ) );
		}
		CMP_Audit::log( 'homework_reviewed', 'program', $program->id, null, array( 'task' => (int) $task->id, 'day' => $day, 'verdict' => $verdict ) );
		$labels = array( 'accepted' => __( 'accepted', 'cmp' ), 'rejected' => __( 'marked "not good enough"', 'cmp' ), 'missed' => __( 'marked missed', 'cmp' ) );
		/* translators: 1: lead's name, 2: verdict, 3: task, 4: date */
		CMP_Notifications::add( $program->member_id, 'review_decision', sprintf( __( '%1$s %2$s "%3$s" for %4$s.', 'cmp' ), wp_get_current_user()->display_name, $labels[ $verdict ], $task->title, wp_date( 'D M j', strtotime( $day . ' 12:00' ) ) ) . ( $note ? ' ' . $note : '' ), self::url( array( 'program' => $program->id, 'cmp_week' => self::week_start( $day ) ) ) );
		self::go( $back, 'hw_reviewed' );
	}

	/** Archive programs once no directed dynamic remains between the two members. */
	public static function on_dynamic_ended( $dyn ) {
		global $wpdb;
		foreach ( array( array( $dyn->proposer_id, $dyn->partner_id ), array( $dyn->partner_id, $dyn->proposer_id ) ) as list( $lead, $member ) ) {
			// The ended dynamic is already 'ended' in the table when this runs from handle_end().
			if ( CMP_Dynamics::lead_can_direct( $lead, $member ) ) {
				continue;
			}
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'programs' ) . " WHERE lead_id = %d AND member_id = %d AND status = 'active'", $lead, $member ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $ids as $id ) {
				$wpdb->update( self::t( 'programs' ), array( 'status' => 'archived', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
				CMP_Audit::log( 'program_archived', 'program', (int) $id, null, null, 'Dynamic ended' );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	private static function nonce_field() {
		return '<input type="hidden" name="_cmp_nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" />';
	}

	private static function name( $user_id ) {
		$u = get_userdata( $user_id );
		return $u ? $u->display_name : __( 'Former member', 'cmp' );
	}

	public static function render( $user_id ) {
		$program_id = isset( $_GET['program'] ) ? absint( $_GET['program'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_enqueue_script( 'cmp-member' );
		if ( $program_id ) {
			$program = self::program( $program_id );
			if ( ! self::can_view( $program, $user_id ) ) {
				return '<section class="cmp-step"><p class="cmp-empty">' . esc_html__( 'That program isn\'t available.', 'cmp' ) . '</p></section>';
			}
			return self::render_program( $program, $user_id );
		}
		$mine = self::programs_for( $user_id, 'member' );
		$led  = CMP_Dynamics::led_by( $user_id );
		ob_start();
		?>
		<section class="cmp-step" aria-labelledby="cmp-hw-title">
			<h2 id="cmp-hw-title" class="cmp-title"><?php esc_html_e( 'Homework', 'cmp' ); ?></h2>
			<p><?php esc_html_e( 'Weekly programs within a dynamic: the leading side sets tasks, the other logs them day by day with the proof each asks for.', 'cmp' ); ?></p>
		</section>
		<section class="cmp-panel">
			<h3 class="cmp-panel-title"><?php esc_html_e( 'Your homework', 'cmp' ); ?></h3>
			<?php if ( ! $mine ) : ?>
				<p class="cmp-empty"><?php esc_html_e( 'Nobody has set homework for you.', 'cmp' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $mine as $p ) : ?>
				<?php $s = self::summary( $p->id, self::week_start() ); ?>
				<p class="cmp-hw-item"><a href="<?php echo esc_url( self::url( array( 'program' => $p->id ) ) ); ?>"><b><?php echo esc_html( $p->title ); ?></b></a> · <?php echo esc_html( sprintf( /* translators: %s: lead's name */ __( 'from %s', 'cmp' ), self::name( $p->lead_id ) ) ); ?>
					<?php if ( 'active' === $p->status ) : ?>
						· <?php echo esc_html( sprintf( /* translators: 1: met, 2: total */ __( 'this week %1$d/%2$d quotas met', 'cmp' ), $s['met'], $s['total'] ) ); ?>
					<?php else : ?>
						· <em><?php esc_html_e( 'archived', 'cmp' ); ?></em>
					<?php endif; ?>
				</p>
			<?php endforeach; ?>
		</section>
		<section class="cmp-panel">
			<h3 class="cmp-panel-title"><?php esc_html_e( 'Homework you set', 'cmp' ); ?></h3>
			<?php if ( ! $led ) : ?>
				<p class="cmp-empty"><?php esc_html_e( 'You can set homework for members you lead in an active dynamic (for example as their Keyholder or Dominant). See the Dynamics tab.', 'cmp' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $led as $member_id => $labels ) : ?>
				<div class="cmp-hw-led">
					<p><b><?php echo esc_html( self::name( $member_id ) ); ?></b> <span class="cmp-muted">· <?php echo esc_html( implode( ', ', $labels ) ); ?></span></p>
					<?php foreach ( self::programs_for( $user_id, 'lead' ) as $p ) : ?>
						<?php if ( (int) $p->member_id === (int) $member_id && 'active' === $p->status ) : ?>
							<?php $s = self::summary( $p->id, self::week_start() ); ?>
							<p class="cmp-hw-item"><a href="<?php echo esc_url( self::url( array( 'program' => $p->id ) ) ); ?>"><b><?php echo esc_html( $p->title ); ?></b></a> · <?php echo esc_html( sprintf( /* translators: 1: met, 2: total, 3: to review */ __( '%1$d/%2$d quotas met · %3$d to review', 'cmp' ), $s['met'], $s['total'], $s['to_review'] ) ); ?></p>
						<?php endif; ?>
					<?php endforeach; ?>
					<details class="cmp-hw-new">
						<summary class="cmp-btn cmp-btn-small cmp-btn-outline"><?php esc_html_e( 'New program', 'cmp' ); ?></summary>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmp-form">
							<input type="hidden" name="action" value="cmp_hw_program" /><input type="hidden" name="member" value="<?php echo (int) $member_id; ?>" /><?php echo self::nonce_field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<p class="cmp-field"><label for="cmp_hw_title_<?php echo (int) $member_id; ?>"><?php esc_html_e( 'Program name', 'cmp' ); ?></label><input type="text" id="cmp_hw_title_<?php echo (int) $member_id; ?>" name="title" maxlength="120" required placeholder="<?php esc_attr_e( 'e.g. Fall routine', 'cmp' ); ?>" /></p>
							<button type="submit" class="cmp-btn"><?php esc_html_e( 'Create', 'cmp' ); ?></button>
						</form>
					</details>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	private static function render_program( $program, $user_id ) {
		$is_lead = self::can_lead( $program, $user_id );
		$active  = 'active' === $program->status;
		$week    = self::week_start( isset( $_GET['cmp_week'] ) ? sanitize_text_field( wp_unslash( $_GET['cmp_week'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$days    = self::week_days( $week );
		$today   = wp_date( 'Y-m-d' );
		$sel     = isset( $_GET['cmp_day'] ) ? sanitize_text_field( wp_unslash( $_GET['cmp_day'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sel     = in_array( $sel, $days, true ) && $sel <= $today ? $sel : ( in_array( $today, $days, true ) ? $today : $days[6] );
		$tasks   = self::tasks( $program->id );
		$entries = self::entries( $program->id, $days[0], $days[6] );
		$sum     = self::summary( $program->id, $week );
		$labels  = self::status_labels();
		$cats    = self::categories();
		$prev    = gmdate( 'Y-m-d', strtotime( $week . ' 12:00' ) - 7 * DAY_IN_SECONDS );
		$next    = gmdate( 'Y-m-d', strtotime( $week . ' 12:00' ) + 7 * DAY_IN_SECONDS );
		$other   = $is_lead ? $program->member_id : $program->lead_id;
		ob_start();
		?>
		<section class="cmp-step cmp-hw" aria-labelledby="cmp-hw-title">
			<p><a href="<?php echo esc_url( self::url() ); ?>">← <?php esc_html_e( 'All homework', 'cmp' ); ?></a></p>
			<h2 id="cmp-hw-title" class="cmp-title"><?php echo esc_html( $program->title ); ?></h2>
			<p class="cmp-muted">
				<?php
				echo esc_html( $is_lead ? sprintf( /* translators: %s: member */ __( 'Homework you set for %s', 'cmp' ), self::name( $other ) ) : sprintf( /* translators: %s: lead */ __( 'Homework from %s', 'cmp' ), self::name( $other ) ) );
				if ( ! $active ) {
					echo ' · ' . esc_html__( 'Archived: the dynamic ended. Your history stays here; nothing new can be logged.', 'cmp' );
				}
				?>
			</p>
			<div class="cmp-hw-week">
				<a class="cmp-hw-arrow" href="<?php echo esc_url( self::url( array( 'program' => $program->id, 'cmp_week' => $prev ) ) ); ?>" aria-label="<?php esc_attr_e( 'Previous week', 'cmp' ); ?>">←</a>
				<b><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Week of %s', 'cmp' ), wp_date( 'M j, Y', strtotime( $week . ' 12:00' ) ) ) ); ?></b>
				<a class="cmp-hw-arrow" href="<?php echo esc_url( self::url( array( 'program' => $program->id, 'cmp_week' => $next ) ) ); ?>" aria-label="<?php esc_attr_e( 'Next week', 'cmp' ); ?>">→</a>
			</div>
			<div class="cmp-hw-sum">
				<div><b data-cmp-hw-met><?php echo (int) $sum['met']; ?>/<?php echo (int) $sum['total']; ?></b><small><?php esc_html_e( 'Quotas met', 'cmp' ); ?></small></div>
				<div><b class="<?php echo $sum['overall'] ? 'is-met' : 'is-need'; ?>"><?php echo esc_html( $sum['overall'] ? __( 'All met', 'cmp' ) : __( 'Needs work', 'cmp' ) ); ?></b><small><?php esc_html_e( 'Overall', 'cmp' ); ?></small></div>
				<div><b><?php echo (int) ( $is_lead ? $sum['to_review'] : $sum['logged'] ); ?></b><small><?php echo esc_html( $is_lead ? __( 'To review', 'cmp' ) : __( 'Logged', 'cmp' ) ); ?></small></div>
			</div>
			<?php if ( $program->notes ) : ?>
				<div class="cmp-hw-notes"><b><?php echo esc_html( $is_lead ? __( 'Your notes to them', 'cmp' ) : __( 'Notes', 'cmp' ) ); ?></b><p><?php echo nl2br( esc_html( $program->notes ) ); ?></p></div>
			<?php endif; ?>
		</section>

		<?php if ( ! $tasks ) : ?>
			<section class="cmp-panel"><p class="cmp-empty"><?php echo esc_html( $is_lead ? __( 'No tasks yet. Add the first one below.', 'cmp' ) : __( 'No tasks yet.', 'cmp' ) ); ?></p></section>
		<?php endif; ?>

		<?php foreach ( $tasks as $task ) : ?>
			<?php $ts = $sum['tasks'][ (int) $task->id ]; ?>
			<section class="cmp-panel cmp-hw-task" id="cmp-task-<?php echo (int) $task->id; ?>">
				<div class="cmp-hw-task-h">
					<h3 class="cmp-panel-title"><?php echo esc_html( $task->title ); ?></h3>
					<span class="cmp-hw-prog <?php echo $ts['met'] ? 'is-met' : ''; ?>"><?php echo (int) $ts['done']; ?>/<?php echo (int) $ts['min']; ?><?php echo $ts['met'] ? ' ✓' : ''; ?></span>
				</div>
				<p class="cmp-hw-cat"><?php echo esc_html( isset( $cats[ $task->category ] ) ? $cats[ $task->category ] : '' ); ?> · <?php echo esc_html( self::proofs()[ $task->proof ] ); ?></p>
				<?php if ( $task->what_counts ) : ?>
					<p class="cmp-muted"><?php echo esc_html( $task->what_counts ); ?></p>
				<?php endif; ?>
				<?php if ( $task->standard ) : ?>
					<p class="cmp-hw-std"><?php echo esc_html( sprintf( /* translators: %s: phrase */ __( 'Say: "%s"', 'cmp' ), $task->standard ) ); ?></p>
				<?php endif; ?>
				<ol class="cmp-hw-days">
					<?php foreach ( $days as $d ) : ?>
						<?php
						$e     = isset( $entries[ $task->id ][ $d ] ) ? $entries[ $task->id ][ $d ] : null;
						$class = $e ? 'is-' . $e->status . ( 'rejected' === $e->review ? ' is-rejected' : '' ) . ( 'done' === $e->status && '' === $e->review && 'none' !== $task->proof ? ' is-review' : '' ) : '';
						$class .= $d === $sel ? ' is-selected' : '';
						$text  = $e ? $labels[ $e->status ] : '—';
						$link  = ! $is_lead && $active && $d <= $today ? self::url( array( 'program' => $program->id, 'cmp_week' => $week, 'cmp_day' => $d ) ) . '#cmp-task-' . (int) $task->id : '';
						?>
						<li class="<?php echo esc_attr( trim( $class ) ); ?>"><span class="cmp-hw-dow"><?php echo esc_html( wp_date( 'D', strtotime( $d . ' 12:00' ) ) ); ?></span>
							<?php if ( $link ) : ?>
								<a href="<?php echo esc_url( $link ); ?>" aria-label="<?php echo esc_attr( wp_date( 'l M j', strtotime( $d . ' 12:00' ) ) . ': ' . $text ); ?>"><?php echo esc_html( $text ); ?></a>
							<?php else : ?>
								<span><?php echo esc_html( $text ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
				<?php
				if ( ! $is_lead && $active ) {
					echo self::log_form( $program, $task, $sel, isset( $entries[ $task->id ][ $sel ] ) ? $entries[ $task->id ][ $sel ] : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				}
				if ( $is_lead ) {
					echo self::review_html( $program, $task, $days, isset( $entries[ $task->id ] ) ? $entries[ $task->id ] : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				}
				?>
			</section>
		<?php endforeach; ?>

		<?php if ( $program->consequences ) : ?>
			<section class="cmp-panel cmp-hw-ladder">
				<h3 class="cmp-panel-title"><?php esc_html_e( 'If a task is missed', 'cmp' ); ?></h3>
				<?php foreach ( $program->consequences as $row ) : ?>
					<p><b><?php echo esc_html( $row['level'] ); ?></b><?php echo $row['examples'] ? ' · ' . esc_html( $row['examples'] ) : ''; ?><?php echo $row['correction'] ? ' → ' . esc_html( $row['correction'] ) : ''; ?></p>
				<?php endforeach; ?>
			</section>
		<?php endif; ?>

		<?php echo self::month_html( $program ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

		<?php if ( $is_lead ) : ?>
			<?php echo self::lead_forms( $program, $tasks ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	private static function log_form( $program, $task, $day, $entry ) {
		$needs_photo = in_array( $task->proof, array( 'photo', 'photo_text' ), true );
		$needs_text  = in_array( $task->proof, array( 'text', 'photo_text', 'report' ), true );
		$id          = 'cmp_hw_' . (int) $task->id;
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="cmp-form cmp-hw-log">
			<input type="hidden" name="action" value="cmp_hw_log" /><input type="hidden" name="task" value="<?php echo (int) $task->id; ?>" /><input type="hidden" name="day" value="<?php echo esc_attr( $day ); ?>" /><?php echo self::nonce_field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p class="cmp-hw-logday"><?php echo esc_html( sprintf( /* translators: %s: day */ __( 'Log %s', 'cmp' ), wp_date( 'l, M j', strtotime( $day . ' 12:00' ) ) ) ); ?>
				<?php if ( $entry ) : ?>
					<span class="cmp-muted"> · <?php echo esc_html( sprintf( /* translators: %s: status */ __( 'currently %s', 'cmp' ), self::status_labels()[ $entry->status ] ) ); ?><?php echo 'rejected' === $entry->review ? ' · ' . esc_html__( 'not good enough', 'cmp' ) : ( 'accepted' === $entry->review ? ' · ' . esc_html__( 'accepted', 'cmp' ) : '' ); ?></span>
				<?php endif; ?>
			</p>
			<?php if ( $entry && $entry->review_note ) : ?>
				<p class="cmp-hw-review-note"><?php echo esc_html( $entry->review_note ); ?></p>
			<?php endif; ?>
			<?php if ( $needs_photo ) : ?>
				<p class="cmp-field"><label for="<?php echo esc_attr( $id ); ?>_photo"><?php echo esc_html( $entry && $entry->photo_sha ? __( 'Replace photo (one is already attached)', 'cmp' ) : __( 'Photo (needed to log it done)', 'cmp' ) ); ?></label><input type="file" id="<?php echo esc_attr( $id ); ?>_photo" name="photo" accept="image/jpeg,image/png,image/webp" /></p>
			<?php endif; ?>
			<?php if ( 'none' !== $task->proof ) : ?>
				<p class="cmp-field"><label for="<?php echo esc_attr( $id ); ?>_note"><?php echo esc_html( 'report' === $task->proof ? __( 'Your report', 'cmp' ) : ( $needs_text ? __( 'Text (needed to log it done)', 'cmp' ) : __( 'Note (optional)', 'cmp' ) ) ); ?></label><textarea id="<?php echo esc_attr( $id ); ?>_note" name="note" rows="<?php echo 'report' === $task->proof ? 8 : 3; ?>" maxlength="<?php echo 'report' === $task->proof ? 5000 : 1000; ?>"><?php echo esc_textarea( $entry ? $entry->note : '' ); ?></textarea></p>
			<?php endif; ?>
			<div class="cmp-actions cmp-hw-acts">
				<button type="submit" name="status" value="done" class="cmp-btn"><?php esc_html_e( 'Log as done', 'cmp' ); ?></button>
				<button type="submit" name="status" value="moved" class="cmp-btn cmp-btn-outline" formnovalidate><?php esc_html_e( 'Moved', 'cmp' ); ?></button>
				<button type="submit" name="status" value="na" class="cmp-btn cmp-btn-outline" formnovalidate><?php esc_html_e( 'N/A', 'cmp' ); ?></button>
			</div>
		</form>
		<?php
		return ob_get_clean();
	}

	private static function review_html( $program, $task, $days, $entries ) {
		$html = '<div class="cmp-hw-review">';
		foreach ( $days as $d ) {
			$e = isset( $entries[ $d ] ) ? $entries[ $d ] : null;
			if ( ! $e || 'done' !== $e->status ) {
				continue;
			}
			$html .= '<div class="cmp-hw-proof"><p><b>' . esc_html( wp_date( 'D M j', strtotime( $d . ' 12:00' ) ) ) . '</b>' . ( $e->review ? ' · ' . esc_html( 'accepted' === $e->review ? __( 'accepted', 'cmp' ) : __( 'not good enough', 'cmp' ) ) : '' ) . '</p>';
			if ( $e->photo_sha ) {
				$html .= '<a href="' . esc_url( self::photo_url( $e ) ) . '" target="_blank" rel="noopener"><img src="' . esc_url( self::photo_url( $e ) ) . '" alt="' . esc_attr__( 'Proof photo', 'cmp' ) . '" loading="lazy" /></a>';
			}
			if ( $e->note ) {
				$html .= '<p class="cmp-hw-note">' . nl2br( esc_html( $e->note ) ) . '</p>';
			}
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cmp-form"><input type="hidden" name="action" value="cmp_hw_review" /><input type="hidden" name="program" value="' . (int) $program->id . '" /><input type="hidden" name="task" value="' . (int) $task->id . '" /><input type="hidden" name="day" value="' . esc_attr( $d ) . '" />' . self::nonce_field()
				. '<p class="cmp-field"><label for="cmp_rv_' . (int) $e->id . '">' . esc_html__( 'Note to them (optional)', 'cmp' ) . '</label><input type="text" id="cmp_rv_' . (int) $e->id . '" name="review_note" maxlength="500" /></p>'
				. '<div class="cmp-actions"><button type="submit" name="verdict" value="accepted" class="cmp-btn cmp-btn-small">' . esc_html__( 'Accept', 'cmp' ) . '</button><button type="submit" name="verdict" value="rejected" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Not good enough', 'cmp' ) . '</button></div></form></div>';
		}
		// Mark a past day missed.
		$past = array_filter(
			$days,
			function ( $d ) use ( $entries ) {
				return $d < wp_date( 'Y-m-d' ) && ! isset( $entries[ $d ] );
			}
		);
		if ( $past ) {
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cmp-form cmp-hw-missed"><input type="hidden" name="action" value="cmp_hw_review" /><input type="hidden" name="program" value="' . (int) $program->id . '" /><input type="hidden" name="task" value="' . (int) $task->id . '" /><input type="hidden" name="verdict" value="missed" />' . self::nonce_field()
				. '<label for="cmp_ms_' . (int) $task->id . '">' . esc_html__( 'Mark a day missed', 'cmp' ) . '</label> <select id="cmp_ms_' . (int) $task->id . '" name="day">';
			foreach ( $past as $d ) {
				$html .= '<option value="' . esc_attr( $d ) . '">' . esc_html( wp_date( 'D M j', strtotime( $d . ' 12:00' ) ) ) . '</option>';
			}
			$html .= '</select> <button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Mark missed', 'cmp' ) . '</button></form>';
		}
		return $html . '</div>';
	}

	/** This month at a glance: share of tasks done each day. */
	private static function month_html( $program ) {
		$first   = wp_date( 'Y-m-01' );
		$last    = wp_date( 'Y-m-t' );
		$tasks   = count( self::tasks( $program->id ) );
		$entries = self::entries( $program->id, $first, $last );
		$per_day = array();
		foreach ( $entries as $by_day ) {
			foreach ( $by_day as $d => $e ) {
				$per_day[ $d ] = ( isset( $per_day[ $d ] ) ? $per_day[ $d ] : 0 ) + ( self::counts( $e ) ? 1 : 0 );
			}
		}
		$lead = (int) gmdate( 'N', strtotime( $first . ' 12:00' ) ) - 1;
		$html = '<section class="cmp-panel cmp-hw-month"><h3 class="cmp-panel-title">' . esc_html( wp_date( 'F Y' ) ) . '</h3><div class="cmp-hw-heat" role="img" aria-label="' . esc_attr__( 'Days with tasks done this month', 'cmp' ) . '">';
		for ( $i = 0; $i < $lead; $i++ ) {
			$html .= '<span class="is-blank"></span>';
		}
		for ( $d = 1, $n = (int) wp_date( 't' ); $d <= $n; $d++ ) {
			$day   = wp_date( 'Y-m-' ) . sprintf( '%02d', $d );
			$share = $tasks && isset( $per_day[ $day ] ) ? min( 1, $per_day[ $day ] / $tasks ) : 0;
			$level = $share >= 1 ? 4 : ( $share >= .66 ? 3 : ( $share >= .33 ? 2 : ( $share > 0 ? 1 : 0 ) ) );
			$html .= '<span class="lv' . $level . ( wp_date( 'Y-m-d' ) === $day ? ' is-today' : '' ) . '" title="' . esc_attr( $day ) . '">' . (int) $d . '</span>';
		}
		return $html . '</div></section>';
	}

	private static function lead_forms( $program, $tasks ) {
		$cats   = self::categories();
		$proofs = self::proofs();
		$task_form = function ( $task ) use ( $program, $cats, $proofs ) {
			$id   = 'cmp_t_' . ( $task ? (int) $task->id : 'new' );
			$html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cmp-form cmp-hw-taskform"><input type="hidden" name="action" value="cmp_hw_task" /><input type="hidden" name="program" value="' . (int) $program->id . '" />' . ( $task ? '<input type="hidden" name="task" value="' . (int) $task->id . '" />' : '' ) . self::nonce_field();
			$html .= '<p class="cmp-field"><label for="' . $id . '_title">' . esc_html__( 'Task', 'cmp' ) . '</label><input type="text" id="' . $id . '_title" name="title" maxlength="120" required value="' . esc_attr( $task ? $task->title : '' ) . '" /></p>';
			$html .= '<div class="cmp-grid cmp-grid-3"><p class="cmp-field"><label for="' . $id . '_cat">' . esc_html__( 'Category', 'cmp' ) . '</label><select id="' . $id . '_cat" name="category">';
			foreach ( $cats as $k => $l ) {
				$html .= '<option value="' . esc_attr( $k ) . '"' . selected( $task ? $task->category : 'ritual', $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			$html .= '</select></p><p class="cmp-field"><label for="' . $id . '_min">' . esc_html__( 'Days a week', 'cmp' ) . '</label><select id="' . $id . '_min" name="weekly_min">';
			for ( $i = 1; $i <= 7; $i++ ) {
				$html .= '<option value="' . $i . '"' . selected( $task ? (int) $task->weekly_min : 7, $i, false ) . '>' . $i . '</option>';
			}
			$html .= '</select></p><p class="cmp-field"><label for="' . $id . '_proof">' . esc_html__( 'Proof', 'cmp' ) . '</label><select id="' . $id . '_proof" name="proof">';
			foreach ( $proofs as $k => $l ) {
				$html .= '<option value="' . esc_attr( $k ) . '"' . selected( $task ? $task->proof : 'text', $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			$html .= '</select></p></div>';
			$html .= '<p class="cmp-field"><label for="' . $id . '_what">' . esc_html__( 'What counts', 'cmp' ) . '</label><textarea id="' . $id . '_what" name="what_counts" rows="2" maxlength="500">' . esc_textarea( $task ? $task->what_counts : '' ) . '</textarea></p>';
			$html .= '<p class="cmp-field"><label for="' . $id . '_std">' . esc_html__( 'Your standard phrase (optional)', 'cmp' ) . '</label><input type="text" id="' . $id . '_std" name="standard" maxlength="300" value="' . esc_attr( $task ? $task->standard : '' ) . '" placeholder="' . esc_attr__( 'e.g. Good morning, Sir.', 'cmp' ) . '" /></p>';
			return $html . '<button type="submit" class="cmp-btn cmp-btn-small">' . esc_html( $task ? __( 'Save task', 'cmp' ) : __( 'Add task', 'cmp' ) ) . '</button></form>';
		};
		ob_start();
		?>
		<section class="cmp-panel cmp-hw-edit">
			<h3 class="cmp-panel-title"><?php esc_html_e( 'Tasks', 'cmp' ); ?></h3>
			<?php foreach ( $tasks as $task ) : ?>
				<details class="cmp-hw-taskedit"><summary><?php echo esc_html( $task->title ); ?></summary>
					<?php echo $task_form( $task ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmp-inline-form"><input type="hidden" name="action" value="cmp_hw_retire" /><input type="hidden" name="program" value="<?php echo (int) $program->id; ?>" /><input type="hidden" name="task" value="<?php echo (int) $task->id; ?>" /><?php echo self::nonce_field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline cmp-btn-danger" data-cmp-confirm="<?php esc_attr_e( 'Remove this task? Its history is kept.', 'cmp' ); ?>"><?php esc_html_e( 'Remove task', 'cmp' ); ?></button></form>
				</details>
			<?php endforeach; ?>
			<details class="cmp-hw-taskedit" <?php echo $tasks ? '' : 'open'; ?>><summary><?php esc_html_e( 'Add a task', 'cmp' ); ?></summary><?php echo $task_form( null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></details>
		</section>
		<section class="cmp-panel cmp-hw-edit">
			<h3 class="cmp-panel-title"><?php esc_html_e( 'Program notes and consequences', 'cmp' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmp-form">
				<input type="hidden" name="action" value="cmp_hw_program" /><input type="hidden" name="program" value="<?php echo (int) $program->id; ?>" /><?php echo self::nonce_field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="cmp-field"><label for="cmp_pg_title"><?php esc_html_e( 'Program name', 'cmp' ); ?></label><input type="text" id="cmp_pg_title" name="title" maxlength="120" required value="<?php echo esc_attr( $program->title ); ?>" /></p>
				<p class="cmp-field"><label for="cmp_pg_notes"><?php esc_html_e( 'Notes to them', 'cmp' ); ?></label><textarea id="cmp_pg_notes" name="notes" rows="3" maxlength="2000"><?php echo esc_textarea( $program->notes ); ?></textarea></p>
				<fieldset class="cmp-fieldset"><legend><?php esc_html_e( 'Consequence ladder (optional)', 'cmp' ); ?></legend>
					<?php
					$rows = array_pad( $program->consequences, max( 4, count( $program->consequences ) + 1 ), array( 'level' => '', 'examples' => '', 'correction' => '' ) );
					foreach ( array_slice( $rows, 0, 8 ) as $i => $row ) :
						?>
						<div class="cmp-grid cmp-grid-3 cmp-hw-ladrow">
							<p class="cmp-field"><label for="cmp_ld_<?php echo (int) $i; ?>_l"><?php esc_html_e( 'Level', 'cmp' ); ?></label><input type="text" id="cmp_ld_<?php echo (int) $i; ?>_l" name="ladder[<?php echo (int) $i; ?>][level]" maxlength="60" value="<?php echo esc_attr( $row['level'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Minor miss', 'cmp' ); ?>" /></p>
							<p class="cmp-field"><label for="cmp_ld_<?php echo (int) $i; ?>_e"><?php esc_html_e( 'Examples', 'cmp' ); ?></label><input type="text" id="cmp_ld_<?php echo (int) $i; ?>_e" name="ladder[<?php echo (int) $i; ?>][examples]" maxlength="300" value="<?php echo esc_attr( $row['examples'] ); ?>" /></p>
							<p class="cmp-field"><label for="cmp_ld_<?php echo (int) $i; ?>_c"><?php esc_html_e( 'Correction', 'cmp' ); ?></label><input type="text" id="cmp_ld_<?php echo (int) $i; ?>_c" name="ladder[<?php echo (int) $i; ?>][correction]" maxlength="300" value="<?php echo esc_attr( $row['correction'] ); ?>" /></p>
						</div>
					<?php endforeach; ?>
				</fieldset>
				<button type="submit" class="cmp-btn"><?php esc_html_e( 'Save', 'cmp' ); ?></button>
			</form>
		</section>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * Privacy
	 * ---------------------------------------------------------------- */

	public static function export_rows( $user_id ) {
		global $wpdb;
		$rows = array();
		foreach ( array_merge( self::programs_for( $user_id, 'member' ), self::programs_for( $user_id, 'lead' ) ) as $p ) {
			$rows[] = array( 'name' => __( 'Homework program', 'cmp' ), 'value' => sprintf( '%s (%s, %s)', $p->title, (int) $p->member_id === (int) $user_id ? __( 'for you', 'cmp' ) : __( 'set by you', 'cmp' ), $p->status ) );
		}
		$entries = $wpdb->get_results( $wpdb->prepare( 'SELECT e.day, e.status, e.note, e.photo_sha, t.title FROM ' . self::t( 'task_entries' ) . ' e JOIN ' . self::t( 'tasks' ) . ' t ON t.id = e.task_id WHERE e.member_id = %d ORDER BY e.day', $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $entries as $e ) {
			$rows[] = array( 'name' => __( 'Homework log', 'cmp' ), 'value' => sprintf( '%s · %s · %s%s%s', $e->day, $e->title, $e->status, $e->note ? ' · ' . $e->note : '', $e->photo_sha ? ' · ' . __( '(photo kept)', 'cmp' ) : '' ) );
		}
		return $rows;
	}

	public static function erase( $user_id ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'programs' ) . ' WHERE member_id = %d OR lead_id = %d', $user_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$n   = 0;
		foreach ( $ids as $id ) {
			$n += (int) $wpdb->delete( self::t( 'task_entries' ), array( 'program_id' => $id ), array( '%d' ) );
			$n += (int) $wpdb->delete( self::t( 'tasks' ), array( 'program_id' => $id ), array( '%d' ) );
			$n += (int) $wpdb->delete( self::t( 'programs' ), array( 'id' => $id ), array( '%d' ) );
		}
		return $n;
	}
}
