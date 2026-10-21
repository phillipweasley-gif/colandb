<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 1.46.0: meeting notes (minutes).
 *
 * - The Secretary role (cec_secretary, capability cec_manage_meetings;
 *   administrators have it too) writes and approves notes in a front-end
 *   portal, [cec_meeting_admin] on the "Meeting Notes Admin" page, the same
 *   way Calendar Managers use Calendar Admin. No wp-admin access.
 * - A set of notes (a non-public `cec_meeting` post): meeting date, type,
 *   title, the notes text, organizations present and others present,
 *   motions (text, moved by, seconded by, result, votes) and file
 *   attachments (agenda, signed minutes).
 * - Draft or Approved. Only approved notes are public, on the "Meeting
 *   Notes" page ([cec_meeting_notes]), newest first, grouped by year.
 *
 * Meta: _cec_mt_date (Y-m-d), _cec_mt_type, _cec_mt_orgs (term ids),
 * _cec_mt_others, _cec_mt_motions (list of { text, moved, seconded, result,
 * for, against, abstain }), _cec_mt_files (attachment ids).
 */
class CEC_Meetings {

	const POST_TYPE = 'cec_meeting';
	const CAP       = 'cec_manage_meetings';
	const ROLE      = 'cec_secretary';
	const NONCE     = 'cec_meeting_save';
	const MAX_FILE  = 10485760; // 10 MB.

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'install_role' ) );
		add_action( 'admin_post_cec_meeting_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_cec_meeting_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_shortcode( 'cec_meeting_notes', array( __CLASS__, 'shortcode_public' ) );
		add_shortcode( 'cec_meeting_admin', array( __CLASS__, 'shortcode_admin' ) );
		add_filter( 'cmp_account_bar_items', array( __CLASS__, 'account_bar_item' ), 10, 2 );
		// The portal page is never cached: CEC_No_Cache (1.46.1) covers [cec_meeting_admin].
	}

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Meeting Notes', 'cec' ),
					'singular_name' => __( 'Meeting Notes', 'cec' ),
				),
				'public'       => false,
				'show_ui'      => false, // Managed in the Meeting Notes Admin portal.
				'supports'     => array( 'title', 'editor' ),
				'map_meta_cap' => true,
			)
		);
	}

	public static function install_role() {
		if ( ! get_role( self::ROLE ) ) {
			add_role(
				self::ROLE,
				__( 'Secretary', 'cec' ),
				array(
					'read'         => true,
					'upload_files' => true,
					self::CAP      => true,
				)
			);
		}
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAP ) ) {
			$admin->add_cap( self::CAP );
		}
	}

	public static function can_manage( $user = null ) {
		return $user ? user_can( $user, self::CAP ) : current_user_can( self::CAP );
	}

	public static function register_assets() {
		wp_register_style( 'cec-meetings', CEC_URL . 'assets/css/meetings.css', array(), CEC_VERSION );
	}

	/** "Meeting Notes Admin" in the signed-in menu (Community Member Planning), after Calendar Admin. */
	public static function account_bar_item( $items, $user ) {
		$url = CEC_Admin_Settings::get( 'meeting_admin_page_url' );
		if ( ! $url || ! self::can_manage( $user ) ) {
			return $items;
		}
		$item = array( __( 'Meeting Notes Admin', 'cec' ), $url, '', 0 );
		foreach ( $items as $i => $it ) {
			if ( isset( $it[1] ) && admin_url() === $it[1] ) {
				array_splice( $items, $i, 0, array( $item ) );
				return $items;
			}
		}
		$items[] = $item;
		return $items;
	}

	/* ------------------------------------------------------------------
	 * Data
	 * ---------------------------------------------------------------- */

	public static function types() {
		return array(
			'general'   => __( 'General Meeting', 'cec' ),
			'board'     => __( 'Board Meeting', 'cec' ),
			'special'   => __( 'Special Meeting', 'cec' ),
			'committee' => __( 'Committee Meeting', 'cec' ),
			'other'     => __( 'Meeting', 'cec' ),
		);
	}

	public static function results() {
		return array(
			'passed'    => __( 'Passed', 'cec' ),
			'failed'    => __( 'Failed', 'cec' ),
			'tabled'    => __( 'Tabled', 'cec' ),
			'withdrawn' => __( 'Withdrawn', 'cec' ),
		);
	}

	/** Organizations that can be marked present (organizations, not titleholders, when the kind is set). */
	public static function organizations() {
		$terms = get_terms( array( 'taxonomy' => 'cec_partner_org', 'hide_empty' => false, 'orderby' => 'name' ) );
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			if ( 'titleholder' !== get_term_meta( $t->term_id, 'cec_kind', true ) ) {
				$out[ (int) $t->term_id ] = $t->name;
			}
		}
		return $out;
	}

	public static function data( $id ) {
		$m       = function ( $k ) use ( $id ) {
			return get_post_meta( $id, $k, true );
		};
		$types   = self::types();
		$type    = (string) $m( '_cec_mt_type' );
		$motions = $m( '_cec_mt_motions' );
		$files   = $m( '_cec_mt_files' );
		$orgs    = $m( '_cec_mt_orgs' );
		return array(
			'id'      => (int) $id,
			'title'   => get_the_title( $id ),
			'content' => get_post_field( 'post_content', $id ),
			'status'  => get_post_status( $id ),
			'date'    => (string) $m( '_cec_mt_date' ),
			'type'    => isset( $types[ $type ] ) ? $type : 'general',
			'others'  => (string) $m( '_cec_mt_others' ),
			'orgs'    => is_array( $orgs ) ? array_map( 'intval', $orgs ) : array(),
			'motions' => is_array( $motions ) ? $motions : array(),
			'files'   => is_array( $files ) ? array_map( 'intval', $files ) : array(),
		);
	}

	private static function date_text( $ymd, $format = 'M j, Y' ) {
		return $ymd ? wp_date( $format, strtotime( $ymd . ' 12:00:00' ) ) : '';
	}

	/* ------------------------------------------------------------------
	 * The portal: [cec_meeting_admin]
	 * ---------------------------------------------------------------- */

	private static function portal_url( $args = array() ) {
		$base = CEC_Admin_Settings::get( 'meeting_admin_page_url' );
		$base = $base ? $base : home_url( '/' );
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	private static function notice() {
		$key  = isset( $_GET['cec_msg'] ) ? sanitize_key( wp_unslash( $_GET['cec_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$list = array(
			'mt_draft'    => array( 'success', __( 'Saved as a draft. It isn\'t public yet.', 'cec' ) ),
			'mt_approved' => array( 'success', __( 'Approved and published on the Meeting Notes page.', 'cec' ) ),
			'mt_deleted'  => array( 'success', __( 'Moved to the trash. A site administrator can restore it.', 'cec' ) ),
			'mt_invalid'  => array( 'error', __( 'Nothing was saved: a meeting date is required.', 'cec' ) ),
			'mt_gone'     => array( 'error', __( 'Those meeting notes aren\'t available.', 'cec' ) ),
		);
		if ( ! isset( $list[ $key ] ) ) {
			return '';
		}
		$html  = '<div class="cec-notice cec-notice-' . esc_attr( $list[ $key ][0] ) . '" role="status">' . esc_html( $list[ $key ][1] ) . '</div>';
		$skips = isset( $_GET['cec_files'] ) ? sanitize_text_field( wp_unslash( $_GET['cec_files'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $skips ) {
			/* translators: %s: file names */
			$html .= '<div class="cec-notice cec-notice-error" role="status">' . esc_html( sprintf( __( 'These files weren\'t attached (use PDF, Word, OpenDocument, text, JPEG or PNG, up to 10 MB): %s', 'cec' ), $skips ) ) . '</div>';
		}
		return $html;
	}

	public static function shortcode_admin() {
		wp_enqueue_style( 'cec-meetings' );
		if ( ! is_user_logged_in() ) {
			$login = CEC_Admin_Settings::get( 'login_page_url' );
			$login = $login ? add_query_arg( 'redirect_to', rawurlencode( self::portal_url() ), $login ) : wp_login_url( self::portal_url() );
			return '<div class="cec-mt-wrap cec-mt-portal"><p>' . esc_html__( 'Sign in to manage meeting notes.', 'cec' ) . '</p><p><a class="cec-btn" href="' . esc_url( $login ) . '">' . esc_html__( 'Sign in', 'cec' ) . '</a></p></div>';
		}
		if ( ! self::can_manage() ) {
			return '<div class="cec-mt-wrap cec-mt-portal"><p>' . esc_html__( 'Only the Secretary and site administrators can manage meeting notes.', 'cec' ) . '</p></div>';
		}
		$edit = isset( $_GET['cec_meeting'] ) ? sanitize_key( wp_unslash( $_GET['cec_meeting'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$html = '<div class="cec-mt-wrap cec-mt-portal">' . self::notice();
		if ( 'new' === $edit ) {
			return $html . self::form( 0 ) . '</div>';
		}
		if ( $edit && self::POST_TYPE === get_post_type( (int) $edit ) && 'trash' !== get_post_status( (int) $edit ) ) {
			return $html . self::form( (int) $edit ) . '</div>';
		}
		return $html . self::portal_list() . '</div>';
	}

	private static function portal_list() {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_key'       => '_cec_mt_date', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
			)
		);
		$h  = '<div class="cec-mt-portal-head"><h2>' . esc_html__( 'Meeting Notes', 'cec' ) . '</h2><a class="cec-btn" href="' . esc_url( self::portal_url( array( 'cec_meeting' => 'new' ) ) ) . '">' . esc_html__( 'Add meeting notes', 'cec' ) . '</a></div>';
		$pub = CEC_Admin_Settings::get( 'meeting_notes_page_url' );
		if ( $pub ) {
			/* translators: %s: link to the public page */
			$h .= '<p class="cec-mt-muted">' . sprintf( esc_html__( 'Approved notes are public on %s. Drafts are only seen here.', 'cec' ), '<a href="' . esc_url( $pub ) . '">' . esc_html__( 'the Meeting Notes page', 'cec' ) . '</a>' ) . '</p>';
		}
		if ( ! $ids ) {
			return $h . '<p class="cec-mt-empty">' . esc_html__( 'No meeting notes yet.', 'cec' ) . '</p>';
		}
		$types = self::types();
		$h    .= '<table class="cec-mt-table"><thead><tr><th scope="col">' . esc_html__( 'Date', 'cec' ) . '</th><th scope="col">' . esc_html__( 'Meeting', 'cec' ) . '</th><th scope="col">' . esc_html__( 'Status', 'cec' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'cec' ) . '</span></th></tr></thead><tbody>';
		foreach ( $ids as $id ) {
			$d  = self::data( $id );
			$h .= '<tr><td>' . esc_html( self::date_text( $d['date'] ) ) . '</td><td><strong>' . esc_html( $d['title'] ) . '</strong><br><span class="cec-mt-muted">' . esc_html( $types[ $d['type'] ] ) . '</span></td>';
			$h .= '<td><span class="cec-mt-status is-' . ( 'publish' === $d['status'] ? 'approved' : 'draft' ) . '">' . esc_html( 'publish' === $d['status'] ? __( 'Approved', 'cec' ) : __( 'Draft', 'cec' ) ) . '</span></td>';
			$h .= '<td class="cec-mt-actions"><a href="' . esc_url( self::portal_url( array( 'cec_meeting' => $id ) ) ) . '">' . esc_html__( 'Edit', 'cec' ) . '<span class="screen-reader-text"> ' . esc_html( $d['title'] ) . '</span></a>';
			$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Move these meeting notes to the trash?', 'cec' ) ) ) . ');"><input type="hidden" name="action" value="cec_meeting_delete"><input type="hidden" name="meeting" value="' . (int) $id . '">' . wp_nonce_field( 'cec_meeting_delete_' . $id, '_cec_nonce', true, false ) . '<button type="submit" class="cec-mt-link-btn">' . esc_html__( 'Delete', 'cec' ) . '<span class="screen-reader-text"> ' . esc_html( $d['title'] ) . '</span></button></form></td></tr>';
		}
		return $h . '</tbody></table>';
	}

	private static function form( $id ) {
		$d       = $id ? self::data( $id ) : array( 'id' => 0, 'title' => '', 'content' => '', 'status' => 'draft', 'date' => wp_date( 'Y-m-d' ), 'type' => 'general', 'others' => '', 'orgs' => array(), 'motions' => array(), 'files' => array() );
		$types   = self::types();
		$results = self::results();
		$orgs    = self::organizations();
		ob_start();
		?>
		<p><a href="<?php echo esc_url( self::portal_url() ); ?>">&larr; <?php esc_html_e( 'All meeting notes', 'cec' ); ?></a></p>
		<h2><?php echo esc_html( $id ? __( 'Edit meeting notes', 'cec' ) : __( 'Add meeting notes', 'cec' ) ); ?></h2>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cec-mt-form">
			<input type="hidden" name="action" value="cec_meeting_save">
			<input type="hidden" name="meeting" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( self::NONCE, '_cec_nonce' ); ?>
			<div class="cec-mt-grid">
				<p class="cec-field"><label for="cec_mt_date"><?php esc_html_e( 'Meeting date (required)', 'cec' ); ?></label><input type="date" id="cec_mt_date" name="date" value="<?php echo esc_attr( $d['date'] ); ?>" required></p>
				<p class="cec-field"><label for="cec_mt_type"><?php esc_html_e( 'Type', 'cec' ); ?></label><select id="cec_mt_type" name="type">
					<?php foreach ( $types as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $d['type'], $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></p>
			</div>
			<p class="cec-field"><label for="cec_mt_title"><?php esc_html_e( 'Title', 'cec' ); ?></label><input type="text" id="cec_mt_title" name="title" maxlength="150" value="<?php echo esc_attr( $d['title'] ); ?>" aria-describedby="cec_mt_title_hint"><span class="cec-mt-muted" id="cec_mt_title_hint"><?php esc_html_e( 'Optional. Left empty, it\'s the type and date, e.g. "General Meeting, Oct 14, 2026".', 'cec' ); ?></span></p>
			<div class="cec-field"><label for="cec_mt_notes"><?php esc_html_e( 'Notes', 'cec' ); ?></label>
			<?php
			wp_editor(
				$d['content'],
				'cec_mt_notes',
				array(
					'textarea_name' => 'notes',
					'media_buttons' => false,
					'teeny'         => true,
					'textarea_rows' => 14,
					'quicktags'     => true,
				)
			);
			?>
			</div>

			<fieldset class="cec-mt-fieldset"><legend><?php esc_html_e( 'Attendance', 'cec' ); ?></legend>
				<?php if ( $orgs ) : ?>
					<p class="cec-mt-muted"><?php esc_html_e( 'Organizations present:', 'cec' ); ?></p>
					<div class="cec-mt-checks">
					<?php foreach ( $orgs as $tid => $name ) : ?>
						<label><input type="checkbox" name="orgs[]" value="<?php echo (int) $tid; ?>" <?php checked( in_array( (int) $tid, $d['orgs'], true ) ); ?>> <?php echo esc_html( $name ); ?></label>
					<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<p class="cec-field"><label for="cec_mt_others"><?php esc_html_e( 'Others present (optional)', 'cec' ); ?></label><input type="text" id="cec_mt_others" name="others" maxlength="300" value="<?php echo esc_attr( $d['others'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. 3 community members, Mr. Ohio Leather 2027', 'cec' ); ?>"></p>
			</fieldset>

			<fieldset class="cec-mt-fieldset"><legend><?php esc_html_e( 'Motions', 'cec' ); ?></legend>
				<p class="cec-mt-muted"><?php esc_html_e( 'Two empty motions are added each time. Clear a motion\'s text to remove it.', 'cec' ); ?></p>
				<?php
				$rows   = $d['motions'];
				$rows[] = array();
				$rows[] = array();
				foreach ( $rows as $i => $mo ) :
					$v = function ( $k ) use ( $mo ) {
						return isset( $mo[ $k ] ) ? (string) $mo[ $k ] : '';
					};
					$n = (int) $i + 1;
					?>
					<div class="cec-mt-motion">
						<p class="cec-field"><label for="cec_mt_m<?php echo (int) $i; ?>"><?php echo esc_html( sprintf( /* translators: %d: motion number */ __( 'Motion %d', 'cec' ), $n ) ); ?></label><textarea id="cec_mt_m<?php echo (int) $i; ?>" name="motions[<?php echo (int) $i; ?>][text]" rows="2" maxlength="1000"><?php echo esc_textarea( $v( 'text' ) ); ?></textarea></p>
						<div class="cec-mt-grid cec-mt-grid-6">
							<p class="cec-field"><label for="cec_mt_m<?php echo (int) $i; ?>_by"><?php esc_html_e( 'Moved by', 'cec' ); ?></label><input type="text" id="cec_mt_m<?php echo (int) $i; ?>_by" name="motions[<?php echo (int) $i; ?>][moved]" maxlength="100" value="<?php echo esc_attr( $v( 'moved' ) ); ?>"></p>
							<p class="cec-field"><label for="cec_mt_m<?php echo (int) $i; ?>_2nd"><?php esc_html_e( 'Seconded by', 'cec' ); ?></label><input type="text" id="cec_mt_m<?php echo (int) $i; ?>_2nd" name="motions[<?php echo (int) $i; ?>][seconded]" maxlength="100" value="<?php echo esc_attr( $v( 'seconded' ) ); ?>"></p>
							<p class="cec-field"><label for="cec_mt_m<?php echo (int) $i; ?>_res"><?php esc_html_e( 'Result', 'cec' ); ?></label><select id="cec_mt_m<?php echo (int) $i; ?>_res" name="motions[<?php echo (int) $i; ?>][result]">
								<?php foreach ( $results as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $v( 'result' ) ? $v( 'result' ) : 'passed', $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></p>
							<?php foreach ( array( 'for' => __( 'For', 'cec' ), 'against' => __( 'Against', 'cec' ), 'abstain' => __( 'Abstain', 'cec' ) ) as $k => $label ) : ?>
								<p class="cec-field"><label for="cec_mt_m<?php echo (int) $i; ?>_<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label><input type="number" min="0" max="999" id="cec_mt_m<?php echo (int) $i; ?>_<?php echo esc_attr( $k ); ?>" name="motions[<?php echo (int) $i; ?>][<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $v( $k ) ); ?>"></p>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</fieldset>

			<fieldset class="cec-mt-fieldset"><legend><?php esc_html_e( 'Files', 'cec' ); ?></legend>
				<?php if ( $d['files'] ) : ?>
					<ul class="cec-mt-files">
					<?php foreach ( $d['files'] as $fid ) : ?>
						<li><a href="<?php echo esc_url( wp_get_attachment_url( $fid ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $fid ) ); ?></a> <label><input type="checkbox" name="remove_files[]" value="<?php echo (int) $fid; ?>"> <?php esc_html_e( 'Remove', 'cec' ); ?></label></li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p class="cec-field"><label for="cec_mt_files"><?php esc_html_e( 'Add files (optional)', 'cec' ); ?></label><input type="file" id="cec_mt_files" name="files[]" multiple accept=".pdf,.doc,.docx,.odt,.txt,.jpg,.jpeg,.png"><span class="cec-mt-muted"><?php esc_html_e( 'Agenda, signed minutes, reports: PDF, Word, OpenDocument, text, JPEG or PNG, up to 10 MB each. Files are public once the notes are approved.', 'cec' ); ?></span></p>
			</fieldset>

			<fieldset class="cec-mt-fieldset"><legend><?php esc_html_e( 'Status', 'cec' ); ?></legend>
				<label class="cec-mt-radio"><input type="radio" name="status" value="draft" <?php checked( 'publish' !== $d['status'] ); ?>> <?php esc_html_e( 'Draft: only seen here, in the portal', 'cec' ); ?></label>
				<label class="cec-mt-radio"><input type="radio" name="status" value="approved" <?php checked( 'publish' === $d['status'] ); ?>> <?php esc_html_e( 'Approved: published on the Meeting Notes page', 'cec' ); ?></label>
			</fieldset>
			<p><button type="submit" class="cec-btn"><?php esc_html_e( 'Save meeting notes', 'cec' ); ?></button></p>
		</form>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * Saving
	 * ---------------------------------------------------------------- */

	private static function guard( $nonce_action ) {
		if ( ! is_user_logged_in() || ! self::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cec' ), '', array( 'response' => 403 ) );
		}
		if ( ! isset( $_POST['_cec_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cec_nonce'] ) ), $nonce_action ) ) {
			wp_die( esc_html__( 'This page expired. Go back, reload and try again.', 'cec' ), '', array( 'response' => 403 ) );
		}
	}

	public static function handle_save() {
		self::guard( self::NONCE );
		$id = isset( $_POST['meeting'] ) ? absint( $_POST['meeting'] ) : 0;
		if ( $id && ( self::POST_TYPE !== get_post_type( $id ) || 'trash' === get_post_status( $id ) ) ) {
			wp_safe_redirect( self::portal_url( array( 'cec_msg' => 'mt_gone' ) ) );
			exit;
		}
		$text = function ( $k, $max ) {
			return isset( $_POST[ $k ] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) ), 0, $max ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked it.
		};
		$date = $text( 'date', 10 );
		$dt   = DateTime::createFromFormat( '!Y-m-d', $date );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
			wp_safe_redirect( self::portal_url( $id ? array( 'cec_meeting' => $id, 'cec_msg' => 'mt_invalid' ) : array( 'cec_meeting' => 'new', 'cec_msg' => 'mt_invalid' ) ) );
			exit;
		}
		$types  = self::types();
		$type   = isset( $types[ $text( 'type', 20 ) ] ) ? $text( 'type', 20 ) : 'general';
		$title  = $text( 'title', 150 );
		$title  = '' !== $title ? $title : sprintf( /* translators: 1: meeting type, 2: date */ __( '%1$s, %2$s', 'cec' ), $types[ $type ], self::date_text( $date ) );
		$notes  = isset( $_POST['notes'] ) ? wp_kses_post( wp_unslash( $_POST['notes'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$status = 'approved' === $text( 'status', 10 ) ? 'publish' : 'draft';

		$postarr = array(
			'post_type'    => self::POST_TYPE,
			'post_title'   => $title,
			'post_content' => $notes,
			'post_status'  => $status,
		);
		if ( $id ) {
			$postarr['ID'] = $id;
			$id            = wp_update_post( $postarr );
		} else {
			$id = wp_insert_post( $postarr );
		}
		if ( ! $id || is_wp_error( $id ) ) {
			wp_safe_redirect( self::portal_url( array( 'cec_msg' => 'mt_gone' ) ) );
			exit;
		}

		update_post_meta( $id, '_cec_mt_date', $date );
		update_post_meta( $id, '_cec_mt_type', $type );
		$known = self::organizations();
		$orgs  = isset( $_POST['orgs'] ) && is_array( $_POST['orgs'] ) ? array_map( 'absint', wp_unslash( $_POST['orgs'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $id, '_cec_mt_orgs', array_values( array_filter( $orgs, function ( $o ) use ( $known ) { return isset( $known[ $o ] ); } ) ) );
		update_post_meta( $id, '_cec_mt_others', $text( 'others', 300 ) );

		$results = self::results();
		$motions = array();
		$posted  = isset( $_POST['motions'] ) && is_array( $_POST['motions'] ) ? wp_unslash( $_POST['motions'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.
		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$mtext = mb_substr( trim( sanitize_textarea_field( (string) ( $row['text'] ?? '' ) ) ), 0, 1000 );
			if ( '' === $mtext ) {
				continue;
			}
			$num = function ( $k ) use ( $row ) {
				$v = trim( (string) ( $row[ $k ] ?? '' ) );
				return '' === $v ? '' : (string) min( 999, absint( $v ) );
			};
			$motions[] = array(
				'text'     => $mtext,
				'moved'    => mb_substr( trim( sanitize_text_field( (string) ( $row['moved'] ?? '' ) ) ), 0, 100 ),
				'seconded' => mb_substr( trim( sanitize_text_field( (string) ( $row['seconded'] ?? '' ) ) ), 0, 100 ),
				'result'   => isset( $results[ $row['result'] ?? '' ] ) ? $row['result'] : 'passed',
				'for'      => $num( 'for' ),
				'against'  => $num( 'against' ),
				'abstain'  => $num( 'abstain' ),
			);
		}
		update_post_meta( $id, '_cec_mt_motions', $motions );

		$files  = self::data( $id )['files'];
		$remove = isset( $_POST['remove_files'] ) && is_array( $_POST['remove_files'] ) ? array_map( 'absint', wp_unslash( $_POST['remove_files'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		foreach ( $remove as $fid ) {
			if ( in_array( $fid, $files, true ) && (int) get_post_field( 'post_parent', $fid ) === (int) $id ) {
				wp_delete_attachment( $fid, true );
			}
		}
		$files   = array_values( array_diff( $files, $remove ) );
		$skipped = array();
		foreach ( self::uploads() as $file ) {
			$fid = self::attach( $file, $id );
			if ( $fid ) {
				$files[] = $fid;
			} else {
				$skipped[] = sanitize_file_name( $file['name'] );
			}
		}
		update_post_meta( $id, '_cec_mt_files', $files );

		self::refresh_pages();
		$args = array( 'cec_msg' => 'publish' === $status ? 'mt_approved' : 'mt_draft' );
		if ( $skipped ) {
			$args['cec_files'] = implode( ', ', $skipped );
		}
		wp_safe_redirect( self::portal_url( $args ) );
		exit;
	}

	/** The files[] upload field as a list of single-file arrays. */
	private static function uploads() {
		if ( empty( $_FILES['files']['name'] ) || ! is_array( $_FILES['files']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return array();
		}
		$out = array();
		foreach ( $_FILES['files']['name'] as $i => $name ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
			if ( '' === (string) $name || UPLOAD_ERR_NO_FILE === (int) $_FILES['files']['error'][ $i ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
				continue;
			}
			$out[] = array(
				'name'     => (string) $name,
				'type'     => (string) $_FILES['files']['type'][ $i ], // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
				'tmp_name' => (string) $_FILES['files']['tmp_name'][ $i ], // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
				'error'    => (int) $_FILES['files']['error'][ $i ], // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
				'size'     => (int) $_FILES['files']['size'][ $i ], // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
			);
		}
		return $out;
	}

	/** Saves one upload to the media library, attached to the notes. Returns the attachment id, or 0 if it isn't allowed. */
	private static function attach( $file, $post_id ) {
		$allowed = array(
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'odt'  => 'application/vnd.oasis.opendocument.text',
			'txt'  => 'text/plain',
			'jpg|jpeg' => 'image/jpeg',
			'png'  => 'image/png',
		);
		if ( UPLOAD_ERR_OK !== $file['error'] || $file['size'] <= 0 || $file['size'] > self::MAX_FILE ) {
			return 0;
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$_FILES['cec_mt_one'] = $file;
		$fid = media_handle_upload( 'cec_mt_one', $post_id, array(), array( 'test_form' => false, 'mimes' => $allowed ) );
		unset( $_FILES['cec_mt_one'] );
		return is_wp_error( $fid ) ? 0 : (int) $fid;
	}

	public static function handle_delete() {
		$id = isset( $_POST['meeting'] ) ? absint( $_POST['meeting'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::guard( 'cec_meeting_delete_' . $id );
		if ( ! $id || self::POST_TYPE !== get_post_type( $id ) ) {
			wp_safe_redirect( self::portal_url( array( 'cec_msg' => 'mt_gone' ) ) );
			exit;
		}
		wp_trash_post( $id );
		self::refresh_pages();
		wp_safe_redirect( self::portal_url( array( 'cec_msg' => 'mt_deleted' ) ) );
		exit;
	}

	/** Re-saves pages showing [cec_meeting_notes] so the host's CDN serves the change (as CEC_Orgs::refresh_pages()). */
	public static function refresh_pages() {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data' WHERE p.post_status = 'publish' AND p.post_type IN ('page','post') AND ( p.post_content LIKE '%[cec_meeting_notes%' OR m.meta_value LIKE '%cec_meeting_notes%' )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array_map( 'intval', $ids ) as $pid ) {
			delete_post_meta( $pid, '_elementor_element_cache' );
			clean_post_cache( $pid );
			wp_update_post( array( 'ID' => $pid ) );
		}
		if ( did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	/* ------------------------------------------------------------------
	 * Public: [cec_meeting_notes]
	 * ---------------------------------------------------------------- */

	public static function shortcode_public( $atts ) {
		$atts = shortcode_atts( array( 'empty' => __( 'No meeting notes have been posted yet.', 'cec' ) ), $atts, 'cec_meeting_notes' );
		wp_enqueue_style( 'cec-meetings' );
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'meta_key'       => '_cec_mt_date', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
			)
		);
		if ( ! $ids ) {
			return '<div class="cec-mt-wrap"><p class="cec-mt-empty">' . esc_html( $atts['empty'] ) . '</p></div>';
		}
		$types   = self::types();
		$results = self::results();
		$orgs    = self::organizations();
		$h       = '<div class="cec-mt-wrap cec-mt-public">';
		$year    = '';
		foreach ( $ids as $n => $id ) {
			$d = self::data( $id );
			$y = substr( $d['date'], 0, 4 );
			if ( $y !== $year ) {
				$h   .= '<h2 class="cec-mt-year">' . esc_html( $y ) . '</h2>';
				$year = $y;
			}
			$h .= '<details class="cec-mt-item" id="meeting-' . (int) $id . '"' . ( 0 === $n ? ' open' : '' ) . '><summary><span class="cec-mt-date">' . esc_html( self::date_text( $d['date'], 'D, M j, Y' ) ) . '</span> <span class="cec-mt-title">' . esc_html( $d['title'] ) . '</span> <span class="cec-mt-type">' . esc_html( $types[ $d['type'] ] ) . '</span></summary><div class="cec-mt-body">';
			$present = array();
			foreach ( $d['orgs'] as $o ) {
				if ( isset( $orgs[ $o ] ) ) {
					$present[] = esc_html( $orgs[ $o ] );
				}
			}
			if ( $present || '' !== $d['others'] ) {
				$h .= '<h3>' . esc_html__( 'Present', 'cec' ) . '</h3><p>' . implode( ', ', $present ) . ( $present && '' !== $d['others'] ? '; ' : '' ) . esc_html( $d['others'] ) . '</p>';
			}
			if ( '' !== trim( wp_strip_all_tags( $d['content'] ) ) ) {
				$h .= '<h3>' . esc_html__( 'Notes', 'cec' ) . '</h3><div class="cec-mt-notes">' . wpautop( wp_kses_post( $d['content'] ) ) . '</div>';
			}
			if ( $d['motions'] ) {
				$h .= '<h3>' . esc_html__( 'Motions', 'cec' ) . '</h3><ol class="cec-mt-motions">';
				foreach ( $d['motions'] as $mo ) {
					$who = array();
					if ( ! empty( $mo['moved'] ) ) {
						/* translators: %s: person */
						$who[] = sprintf( __( 'Moved by %s', 'cec' ), $mo['moved'] );
					}
					if ( ! empty( $mo['seconded'] ) ) {
						/* translators: %s: person */
						$who[] = sprintf( __( 'seconded by %s', 'cec' ), $mo['seconded'] );
					}
					$votes = array();
					foreach ( array( 'for' => __( '%s for', 'cec' ), 'against' => __( '%s against', 'cec' ), 'abstain' => __( '%s abstaining', 'cec' ) ) as $k => $fmt ) {
						if ( isset( $mo[ $k ] ) && '' !== (string) $mo[ $k ] ) {
							$votes[] = sprintf( $fmt, (int) $mo[ $k ] );
						}
					}
					$res = $results[ $mo['result'] ?? 'passed' ] ?? $results['passed'];
					$h  .= '<li><p class="cec-mt-motion-text">' . nl2br( esc_html( $mo['text'] ) ) . '</p><p class="cec-mt-motion-meta">' . ( $who ? esc_html( implode( ', ', $who ) ) . '. ' : '' ) . '<strong class="cec-mt-result is-' . esc_attr( $mo['result'] ?? 'passed' ) . '">' . esc_html( $res ) . '</strong>' . ( $votes ? ' (' . esc_html( implode( ', ', $votes ) ) . ')' : '' ) . '</p></li>';
				}
				$h .= '</ol>';
			}
			if ( $d['files'] ) {
				$h .= '<h3>' . esc_html__( 'Files', 'cec' ) . '</h3><ul class="cec-mt-files">';
				foreach ( $d['files'] as $fid ) {
					$url = wp_get_attachment_url( $fid );
					if ( $url ) {
						$ext = strtoupper( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
						$h  .= '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $fid ) ) . '</a> <span class="cec-mt-muted">' . esc_html( $ext ) . '</span></li>';
					}
				}
				$h .= '</ul>';
			}
			$h .= '</div></details>';
		}
		return $h . '</div>';
	}
}
