<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Submit an Organization or Titleholder" (1.34.0).
 *
 * [cec_submit_org] is a public form (no account needed). A submission is
 * stored as a private cec_org_submission post, the site administrator is
 * emailed, and nothing is shown publicly until it's approved under
 * Events → Org Submissions. Approving creates (or updates) the Partner
 * Organization or Titleholder with its full public listing (CEC_Orgs), so
 * its page at /partner/<slug>/, its homepage card and its calendar group all
 * appear at once; the contact is emailed either way.
 *
 * The submitter's contact details are private (administrators only) and are
 * covered by Tools → Export / Erase Personal Data.
 *
 * Spam: a hidden honeypot field, a per-visitor limit (5 a day, by hashed IP)
 * and the approval queue itself. No nonce for signed-out visitors, because
 * the host's CDN caches the form page for days and a cached nonce expires.
 */
class CEC_Org_Submissions {

	const CPT       = 'cec_org_submission';
	const ACTION    = 'cec_submit_org';
	const MAX_BYTES = 5242880;
	const DAILY_MAX = 5;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_shortcode( 'cec_submit_org', array( __CLASS__, 'render_form' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'add_meta_boxes_' . self::CPT, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'admin_post_cec_org_decide', array( __CLASS__, 'handle_decision' ) );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_logo_with_submission' ) );
		add_action( 'admin_footer-post.php', array( __CLASS__, 'print_review_form' ) );
	}

	public static function register() {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => __( 'Org Submissions', 'cec' ),
					'singular_name' => __( 'Org Submission', 'cec' ),
					'menu_name'     => __( 'Org Submissions', 'cec' ),
					'edit_item'     => __( 'Review Submission', 'cec' ),
					'all_items'     => __( 'Org Submissions', 'cec' ),
					'not_found'     => __( 'No submissions yet.', 'cec' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=cec_event',
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * The public form
	 * ---------------------------------------------------------------- */

	private static function form_fields() {
		return array(
			'name'        => array( __( 'Name', 'cec' ), 120 ),
			'title'       => array( __( 'Title', 'cec' ), 120 ),
			'year'        => array( __( 'Year', 'cec' ), 4 ),
			'producer'    => array( __( 'Producer', 'cec' ), 120 ),
			'description' => array( __( 'Short description / bio', 'cec' ), 600 ),
			'highlights'  => array( __( 'Highlights', 'cec' ), 600 ),
			'mission'     => array( __( 'Mission / areas of focus', 'cec' ), 2000 ),
			'contact_name'  => array( __( 'Contact name', 'cec' ), 120 ),
			'contact_email' => array( __( 'Contact email', 'cec' ), 200 ),
			'contact_phone' => array( __( 'Contact phone', 'cec' ), 40 ),
			'member'      => array( __( 'Member account', 'cec' ), 100 ),
		);
	}

	public static function render_form() {
		wp_enqueue_style( 'cec-frontend' );
		wp_enqueue_style( 'cec-orgs' );
		$state  = isset( $_GET['cec_org'] ) ? sanitize_key( wp_unslash( $_GET['cec_org'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$errors = array();
		$old    = array();
		$key    = isset( $_GET['cec_org_key'] ) ? sanitize_key( wp_unslash( $_GET['cec_org_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'error' === $state && $key ) {
			$saved  = get_transient( 'cec_org_form_' . $key );
			$errors = $saved ? $saved['errors'] : array( __( 'Your session expired. Please fill in the form again.', 'cec' ) );
			$old    = $saved ? $saved['old'] : array();
		}
		$v = function ( $k ) use ( $old ) {
			return isset( $old[ $k ] ) && is_string( $old[ $k ] ) ? $old[ $k ] : '';
		};
		$kind = 'titleholder' === $v( 'kind' ) ? 'titleholder' : 'organization';
		ob_start();
		?>
		<div class="cec-org-form-wrap">
			<?php if ( 'thanks' === $state ) : ?>
				<div class="cec-org-notice is-success" role="status">
					<h2><?php esc_html_e( 'Thank you! Your submission is in.', 'cec' ); ?></h2>
					<p><?php esc_html_e( 'The COL&B team reviews every listing before it appears on the site. We\'ll email the contact address when it\'s approved, or if we have questions.', 'cec' ); ?></p>
				</div>
			<?php else : ?>
			<?php if ( $errors ) : ?>
				<div class="cec-org-notice is-error" role="alert" tabindex="-1" id="cec-org-errors">
					<p><strong><?php esc_html_e( 'Please fix the following and send it again:', 'cec' ); ?></strong></p>
					<ul><?php foreach ( $errors as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?></ul>
				</div>
			<?php endif; ?>
			<form class="cec-org-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<input type="hidden" name="cec_back" value="<?php echo esc_url( get_permalink() ); ?>" />
				<p class="cec-org-hp" aria-hidden="true"><label>Leave this empty <input type="text" name="cec_org_website_confirm" tabindex="-1" autocomplete="off" /></label></p>

				<fieldset class="cec-org-kind">
					<legend><?php esc_html_e( 'What are you listing?', 'cec' ); ?></legend>
					<label><input type="radio" name="kind" value="organization" <?php checked( 'organization', $kind ); ?> /> <?php esc_html_e( 'An organization or group', 'cec' ); ?></label>
					<label><input type="radio" name="kind" value="titleholder" <?php checked( 'titleholder', $kind ); ?> /> <?php esc_html_e( 'A titleholder', 'cec' ); ?></label>
				</fieldset>

				<p class="cec-org-field"><label for="cec_org_name"><?php esc_html_e( 'Name (required)', 'cec' ); ?></label>
					<input type="text" id="cec_org_name" name="name" maxlength="120" required value="<?php echo esc_attr( $v( 'name' ) ); ?>" />
					<span class="cec-org-hint"><?php esc_html_e( 'The organization\'s name, or the titleholder\'s name as they want it shown.', 'cec' ); ?></span></p>

				<div class="cec-org-only-titleholder" data-cec-kind="titleholder">
					<div class="cec-org-row">
						<p class="cec-org-field"><label for="cec_org_title"><?php esc_html_e( 'Title', 'cec' ); ?></label><input type="text" id="cec_org_title" name="title" maxlength="120" placeholder="<?php esc_attr_e( 'e.g. Great Lakes Handler', 'cec' ); ?>" value="<?php echo esc_attr( $v( 'title' ) ); ?>" /></p>
						<p class="cec-org-field cec-org-year"><label for="cec_org_year"><?php esc_html_e( 'Year', 'cec' ); ?></label><input type="number" id="cec_org_year" name="year" min="1970" max="2100" value="<?php echo esc_attr( $v( 'year' ) ); ?>" /></p>
					</div>
					<p class="cec-org-field"><label for="cec_org_producer"><?php esc_html_e( 'Producer', 'cec' ); ?></label><input type="text" id="cec_org_producer" name="producer" maxlength="120" value="<?php echo esc_attr( $v( 'producer' ) ); ?>" />
						<span class="cec-org-hint"><?php esc_html_e( 'The contest or organization that awards the title.', 'cec' ); ?></span></p>
				</div>

				<p class="cec-org-field"><label for="cec_org_logo"><span data-cec-kind="organization"><?php esc_html_e( 'Logo', 'cec' ); ?></span><span data-cec-kind="titleholder"><?php esc_html_e( 'Photo', 'cec' ); ?></span> <?php esc_html_e( '(optional)', 'cec' ); ?></label>
					<input type="file" id="cec_org_logo" name="logo" accept="image/jpeg,image/png,image/webp" />
					<span class="cec-org-hint"><?php esc_html_e( 'JPEG, PNG or WebP, up to 5 MB. A square image works best.', 'cec' ); ?></span></p>

				<p class="cec-org-field"><label for="cec_org_description"><span data-cec-kind="organization"><?php esc_html_e( 'Short description (required)', 'cec' ); ?></span><span data-cec-kind="titleholder"><?php esc_html_e( 'Bio (required)', 'cec' ); ?></span></label>
					<textarea id="cec_org_description" name="description" rows="4" maxlength="600" required><?php echo esc_textarea( $v( 'description' ) ); ?></textarea>
					<span class="cec-org-hint"><?php esc_html_e( 'Up to 600 characters. Shown on your page and the homepage card.', 'cec' ); ?></span></p>

				<p class="cec-org-field" data-cec-kind="organization"><label for="cec_org_highlights"><?php esc_html_e( 'What you offer (optional)', 'cec' ); ?></label>
					<textarea id="cec_org_highlights" name="highlights" rows="3" maxlength="600" placeholder="<?php esc_attr_e( "One per line, e.g.\nPeer Education\nSocial Nights", 'cec' ); ?>"><?php echo esc_textarea( $v( 'highlights' ) ); ?></textarea></p>

				<p class="cec-org-field"><label for="cec_org_mission"><span data-cec-kind="organization"><?php esc_html_e( 'Mission statement (optional)', 'cec' ); ?></span><span data-cec-kind="titleholder"><?php esc_html_e( 'Areas of focus / mission (optional)', 'cec' ); ?></span></label>
					<textarea id="cec_org_mission" name="mission" rows="5" maxlength="2000"><?php echo esc_textarea( $v( 'mission' ) ); ?></textarea></p>

				<fieldset class="cec-org-links">
					<legend><?php esc_html_e( 'Links (all optional)', 'cec' ); ?></legend>
					<?php foreach ( CEC_Orgs::socials() as $k => $label ) : ?>
						<p class="cec-org-field"><label for="cec_org_s_<?php echo esc_attr( $k ); ?>"><?php echo esc_html( 'telegram' === $k ? __( 'Telegram chat invite link', 'cec' ) : $label ); ?></label>
							<input type="url" id="cec_org_s_<?php echo esc_attr( $k ); ?>" name="social[<?php echo esc_attr( $k ); ?>]" placeholder="<?php echo esc_attr( 'telegram' === $k ? 'https://t.me/+…' : 'https://' ); ?>" value="<?php echo esc_attr( isset( $old['social'][ $k ] ) && is_string( $old['social'][ $k ] ) ? $old['social'][ $k ] : '' ); ?>" />
							<?php if ( 'telegram' === $k ) : ?><span class="cec-org-hint"><?php esc_html_e( 'Use an invite link (https://t.me/+…) so new people can join. In Telegram: tap the group name → Invite Links.', 'cec' ); ?></span><?php endif; ?>
						</p>
					<?php endforeach; ?>
				</fieldset>

				<fieldset class="cec-org-contact">
					<legend><?php esc_html_e( 'Contact for the COL&B team (private, never shown)', 'cec' ); ?></legend>
					<div class="cec-org-row">
						<p class="cec-org-field"><label for="cec_org_cname"><?php esc_html_e( 'Your name (required)', 'cec' ); ?></label><input type="text" id="cec_org_cname" name="contact_name" maxlength="120" required autocomplete="name" value="<?php echo esc_attr( $v( 'contact_name' ) ); ?>" /></p>
						<p class="cec-org-field"><label for="cec_org_cemail"><?php esc_html_e( 'Email (required)', 'cec' ); ?></label><input type="email" id="cec_org_cemail" name="contact_email" maxlength="200" required autocomplete="email" value="<?php echo esc_attr( $v( 'contact_email' ) ); ?>" /></p>
						<p class="cec-org-field"><label for="cec_org_cphone"><?php esc_html_e( 'Phone (optional)', 'cec' ); ?></label><input type="tel" id="cec_org_cphone" name="contact_phone" maxlength="40" autocomplete="tel" value="<?php echo esc_attr( $v( 'contact_phone' ) ); ?>" /></p>
					</div>
				</fieldset>

				<p class="cec-org-field"><label for="cec_org_member"><?php esc_html_e( 'Your COL&B member username or email (optional)', 'cec' ); ?></label>
					<input type="text" id="cec_org_member" name="member" maxlength="100" value="<?php echo esc_attr( $v( 'member' ) ); ?>" />
					<span class="cec-org-hint"><?php esc_html_e( 'If you have a member account, we\'ll link it to this listing when it\'s approved, so other members can find your profile from it. You can remove the link any time from your profile.', 'cec' ); ?></span></p>

				<p class="cec-org-check"><label><input type="checkbox" name="authorized" value="1" required <?php checked( '1', $v( 'authorized' ) ); ?> /> <?php esc_html_e( 'I am this titleholder, or I\'m allowed to list this organization, and the information is accurate.', 'cec' ); ?></label></p>
				<p><button type="submit" class="cec-org-submit"><?php esc_html_e( 'Send for review', 'cec' ); ?></button></p>
			</form>
			<script>
			( function () {
				var form = document.currentScript.previousElementSibling;
				var sync = function () {
					var kind = ( form.querySelector( 'input[name="kind"]:checked' ) || {} ).value || 'organization';
					form.querySelectorAll( '[data-cec-kind]' ).forEach( function ( el ) { el.hidden = el.getAttribute( 'data-cec-kind' ) !== kind; } );
				};
				form.addEventListener( 'change', sync );
				sync();
				var err = document.getElementById( 'cec-org-errors' );
				if ( err ) { err.focus(); }
			}() );
			</script>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function client_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 20 );
	}

	private static function back( $url, $state, $key = '' ) {
		$url = wp_validate_redirect( $url, home_url( '/' ) );
		$url = add_query_arg( array_filter( array( 'cec_org' => $state, 'cec_org_key' => $key ) ), $url );
		wp_safe_redirect( $url . ( 'thanks' === $state ? '' : '#cec-org-errors' ) );
		exit;
	}

	/**
	 * Validates a posted form. Returns array( clean values, errors ).
	 */
	public static function validate( $post ) {
		$e = array();
		$s = function ( $k, $max, $multi = false ) use ( $post ) {
			$raw = isset( $post[ $k ] ) && is_scalar( $post[ $k ] ) ? (string) $post[ $k ] : '';
			$v   = trim( $multi ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw ) );
			return mb_substr( $v, 0, $max );
		};
		$c = array(
			'kind'          => isset( $post['kind'] ) && 'titleholder' === $post['kind'] ? 'titleholder' : 'organization',
			'name'          => $s( 'name', 120 ),
			'title'         => $s( 'title', 120 ),
			'year'          => $s( 'year', 4 ),
			'producer'      => $s( 'producer', 120 ),
			'description'   => $s( 'description', 600, true ),
			'highlights'    => array_slice( array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $s( 'highlights', 600, true ) ) ) ) ), 0, 8 ),
			'mission'       => $s( 'mission', 2000, true ),
			'contact_name'  => $s( 'contact_name', 120 ),
			'contact_email' => sanitize_email( $s( 'contact_email', 200 ) ),
			'contact_phone' => preg_replace( '/[^0-9+().\- ]/', '', $s( 'contact_phone', 40 ) ),
			'member'        => $s( 'member', 100 ),
			'socials'       => array(),
		);
		if ( '' === $c['name'] ) {
			$e[] = __( 'Please enter a name.', 'cec' );
		}
		if ( '' === $c['description'] ) {
			$e[] = 'titleholder' === $c['kind'] ? __( 'Please write a short bio.', 'cec' ) : __( 'Please write a short description.', 'cec' );
		}
		if ( '' === $c['contact_name'] ) {
			$e[] = __( 'Please enter your name as the contact.', 'cec' );
		}
		if ( ! is_email( $c['contact_email'] ) ) {
			$e[] = __( 'Please enter a contact email address we can reach you at.', 'cec' );
		}
		if ( '' !== $c['year'] && ! preg_match( '/^(19|20|21)\d{2}$/', $c['year'] ) ) {
			$e[] = __( 'The year should be four digits, e.g. 2026.', 'cec' );
		}
		if ( 'organization' === $c['kind'] ) {
			$c['title'] = $c['year'] = $c['producer'] = '';
		} else {
			$c['highlights'] = array();
		}
		$social = isset( $post['social'] ) && is_array( $post['social'] ) ? $post['social'] : array();
		foreach ( array_keys( CEC_Orgs::socials() ) as $k ) {
			$clean = CEC_Orgs::clean_social( $k, isset( $social[ $k ] ) && is_scalar( $social[ $k ] ) ? $social[ $k ] : '' );
			if ( is_wp_error( $clean ) ) {
				$e[] = $clean->get_error_message();
			} elseif ( '' !== $clean ) {
				$c['socials'][ $k ] = $clean;
			}
		}
		if ( empty( $post['authorized'] ) ) {
			$e[] = __( 'Please confirm you\'re allowed to list this.', 'cec' );
		}
		return array( $c, $e );
	}

	public static function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form; see the class comment.
		$back = isset( $_POST['cec_back'] ) ? esc_url_raw( wp_unslash( $_POST['cec_back'] ) ) : home_url( '/' );
		if ( ! empty( $_POST['cec_org_website_confirm'] ) ) {
			self::back( $back, 'thanks' ); // A bot filled the hidden field: pretend it worked.
		}
		$post = wp_unslash( $_POST );
		// phpcs:enable
		list( $c, $errors ) = self::validate( $post );

		$hash  = self::client_hash();
		$count = (int) get_transient( 'cec_org_rate_' . $hash );
		if ( $count >= self::DAILY_MAX ) {
			$errors[] = __( 'Too many submissions from here today. Please try again tomorrow, or email the COL&B team.', 'cec' );
		}

		$file    = isset( $_FILES['logo'] ) && is_array( $_FILES['logo'] ) ? $_FILES['logo'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$has_img = $file && isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'];
		if ( $has_img ) {
			$check = self::check_image( $file );
			if ( is_wp_error( $check ) ) {
				$errors[] = $check->get_error_message();
			}
		}

		if ( $errors ) {
			$key = wp_generate_password( 16, false );
			$old = $c + array( 'social' => isset( $post['social'] ) && is_array( $post['social'] ) ? $post['social'] : array(), 'authorized' => empty( $post['authorized'] ) ? '' : '1', 'highlights' => isset( $post['highlights'] ) ? (string) $post['highlights'] : '' );
			unset( $old['socials'] );
			set_transient( 'cec_org_form_' . strtolower( $key ), array( 'errors' => $errors, 'old' => $old ), 30 * MINUTE_IN_SECONDS );
			self::back( $back, 'error', strtolower( $key ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_title'  => $c['name'],
				'post_status' => 'pending',
				'post_author' => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			self::back( $back, 'error' );
		}
		set_transient( 'cec_org_rate_' . $hash, $count + 1, DAY_IN_SECONDS );
		update_post_meta( $post_id, '_cec_org', $c );
		update_post_meta( $post_id, '_cec_org_review', 'pending' );
		update_post_meta( $post_id, '_cec_org_contact_email', $c['contact_email'] ); // For privacy export/erase lookups.
		if ( $has_img ) {
			$att = self::store_image( $file, $post_id, $c['name'] );
			if ( $att ) {
				update_post_meta( $post_id, '_cec_org_logo_id', $att );
			}
		}
		self::notify_admin( $post_id, $c );
		self::mail_contact(
			$c,
			/* translators: %s: site name */
			sprintf( __( '[%s] We received your listing', 'cec' ), CEC_Event_Helper::site_name() ),
			/* translators: 1: contact name, 2: listing name, 3: site name */
			sprintf( __( "Hi %1\$s,\n\nThanks for sending \"%2\$s\" to %3\$s. The team reviews every listing before it appears on the site, and we'll email you when it's approved or if we have questions.\n\nIf you didn't send this, you can ignore this email.", 'cec' ), $c['contact_name'], $c['name'], CEC_Event_Helper::site_name() )
		);
		self::back( $back, 'thanks' );
	}

	/** @return true|WP_Error */
	public static function check_image( $file ) {
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'cec_img', in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? __( 'That image is larger than 5 MB.', 'cec' ) : __( 'The image didn\'t upload. Please try again.', 'cec' ) );
		}
		if ( (int) $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'cec_img', __( 'That image is larger than 5 MB.', 'cec' ) );
		}
		$info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return new WP_Error( 'cec_img', __( 'The image needs to be a JPEG, PNG or WebP file.', 'cec' ) );
		}
		return true;
	}

	/**
	 * Saves the image to the media library, attached to the submission. The
	 * file's content decides its type; its name is replaced.
	 */
	public static function store_image( $file, $post_id, $name ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$info         = getimagesize( $file['tmp_name'] );
		$ext          = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' )[ $info['mime'] ];
		$file['name'] = sanitize_title( $name ) . '-' . wp_generate_password( 6, false, false ) . '.' . $ext;
		$file['type'] = $info['mime'];
		$_FILES['cec_org_logo_upload'] = $file;
		$att = media_handle_upload( 'cec_org_logo_upload', $post_id, array( 'post_title' => $name ), array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) ) );
		unset( $_FILES['cec_org_logo_upload'] );
		return is_wp_error( $att ) ? 0 : (int) $att;
	}

	private static function review_url( $post_id ) {
		return admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' );
	}

	private static function notify_admin( $post_id, $c ) {
		$kind = 'titleholder' === $c['kind'] ? __( 'titleholder', 'cec' ) : __( 'organization', 'cec' );
		wp_mail(
			get_option( 'admin_email' ),
			/* translators: 1: site name, 2: kind, 3: name */
			sprintf( __( '[%1$s] New %2$s listing to review: %3$s', 'cec' ), CEC_Event_Helper::site_name(), $kind, $c['name'] ),
			/* translators: 1: kind, 2: name, 3: contact, 4: review link */
			sprintf( __( "A new %1\$s listing was submitted: %2\$s\nContact: %3\$s\n\nReview, edit, approve or decline it here:\n%4\$s", 'cec' ), $kind, $c['name'], $c['contact_name'] . ' <' . $c['contact_email'] . '>', self::review_url( $post_id ) )
		);
	}

	private static function mail_contact( $c, $subject, $body ) {
		if ( is_email( $c['contact_email'] ) ) {
			wp_mail( $c['contact_email'], $subject, $body );
		}
	}

	/* ------------------------------------------------------------------
	 * Review (wp-admin: Events → Org Submissions)
	 * ---------------------------------------------------------------- */

	public static function columns( $cols ) {
		return array(
			'cb'          => $cols['cb'],
			'title'       => __( 'Name', 'cec' ),
			'cec_kind'    => __( 'Kind', 'cec' ),
			'cec_contact' => __( 'Contact', 'cec' ),
			'cec_review'  => __( 'Status', 'cec' ),
			'date'        => $cols['date'],
		);
	}

	public static function column( $col, $post_id ) {
		$c = (array) get_post_meta( $post_id, '_cec_org', true );
		if ( 'cec_kind' === $col ) {
			echo esc_html( isset( $c['kind'] ) && 'titleholder' === $c['kind'] ? __( 'Titleholder', 'cec' ) : __( 'Organization', 'cec' ) );
		} elseif ( 'cec_contact' === $col ) {
			echo esc_html( ( $c['contact_name'] ?? '' ) . ' · ' . ( $c['contact_email'] ?? '' ) );
		} elseif ( 'cec_review' === $col ) {
			$r = get_post_meta( $post_id, '_cec_org_review', true );
			echo esc_html( 'approved' === $r ? __( 'Approved', 'cec' ) : ( 'declined' === $r ? __( 'Declined', 'cec' ) : __( 'Waiting for review', 'cec' ) ) );
		}
	}

	public static function admin_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! current_user_can( 'manage_options' ) || ! $screen || ! in_array( $screen->id, array( 'dashboard', 'edit-cec_event', 'edit-cec_partner_org' ), true ) ) {
			return;
		}
		$n = (int) wp_count_posts( self::CPT )->pending;
		if ( $n ) {
			printf(
				'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
				/* translators: %d: number of submissions */
				esc_html( sprintf( _n( '%d organization/titleholder listing is waiting for review.', '%d organization/titleholder listings are waiting for review.', $n, 'cec' ), $n ) ),
				esc_url( admin_url( 'edit.php?post_type=' . self::CPT . '&post_status=pending' ) ),
				esc_html__( 'Review now', 'cec' )
			);
		}
	}

	public static function meta_boxes( $post ) {
		remove_meta_box( 'submitdiv', self::CPT, 'side' );
		add_meta_box( 'cec_org_review', __( 'Review this listing', 'cec' ), array( __CLASS__, 'review_box' ), self::CPT, 'normal', 'high' );
	}

	public static function review_box( $post ) {
		$c      = wp_parse_args( (array) get_post_meta( $post->ID, '_cec_org', true ), array( 'kind' => 'organization', 'name' => '', 'title' => '', 'year' => '', 'producer' => '', 'description' => '', 'highlights' => array(), 'mission' => '', 'contact_name' => '', 'contact_email' => '', 'contact_phone' => '', 'member' => '', 'socials' => array() ) );
		$review = get_post_meta( $post->ID, '_cec_org_review', true );
		$logo   = (int) get_post_meta( $post->ID, '_cec_org_logo_id', true );
		$term   = (int) get_post_meta( $post->ID, '_cec_org_term_id', true );
		$same   = get_term_by( 'name', $c['name'], 'cec_partner_org' );
		?>
		<p><strong><?php esc_html_e( 'Status:', 'cec' ); ?></strong> <?php echo esc_html( 'approved' === $review ? __( 'Approved', 'cec' ) : ( 'declined' === $review ? __( 'Declined', 'cec' ) : __( 'Waiting for review', 'cec' ) ) ); ?>
			<?php if ( $term && ! is_wp_error( get_term_link( $term, 'cec_partner_org' ) ) ) : ?> · <a href="<?php echo esc_url( get_term_link( $term, 'cec_partner_org' ) ); ?>" target="_blank"><?php esc_html_e( 'View the page', 'cec' ); ?></a><?php endif; ?></p>
		<p class="description"><?php esc_html_e( 'You can correct anything below before approving. Approving creates the public page, the homepage card and the calendar group, and emails the contact.', 'cec' ); ?>
			<?php if ( $same && ! $term ) : ?><br /><strong><?php echo esc_html( sprintf( /* translators: %s: name */ __( '"%s" already exists as a calendar group: approving fills in and lists that group instead of creating a second one.', 'cec' ), $c['name'] ) ); ?></strong><?php endif; ?></p>
		<?php // The inputs below belong to a form printed in the footer (print_review_form): this box is already inside WordPress's own post form. ?>
		<input type="hidden" name="action" value="cec_org_decide" form="cec-org-review-form" />
		<input type="hidden" name="submission" value="<?php echo (int) $post->ID; ?>" form="cec-org-review-form" />
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'cec_org_decide_' . $post->ID ) ); ?>" form="cec-org-review-form" />
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Kind', 'cec' ); ?></th><td>
				<label><input form="cec-org-review-form" type="radio" name="kind" value="organization" <?php checked( 'organization', $c['kind'] ); ?> /> <?php esc_html_e( 'Organization', 'cec' ); ?></label>&nbsp;
				<label><input form="cec-org-review-form" type="radio" name="kind" value="titleholder" <?php checked( 'titleholder', $c['kind'] ); ?> /> <?php esc_html_e( 'Titleholder', 'cec' ); ?></label></td></tr>
			<tr><th><label for="r_name"><?php esc_html_e( 'Name', 'cec' ); ?></label></th><td><input form="cec-org-review-form" class="regular-text" id="r_name" name="name" value="<?php echo esc_attr( $c['name'] ); ?>" /></td></tr>
			<tr><th><?php esc_html_e( 'Title / year / producer', 'cec' ); ?></th><td>
				<input form="cec-org-review-form" name="title" value="<?php echo esc_attr( $c['title'] ); ?>" placeholder="<?php esc_attr_e( 'Title', 'cec' ); ?>" />
				<input form="cec-org-review-form" name="year" value="<?php echo esc_attr( $c['year'] ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Year', 'cec' ); ?>" />
				<input form="cec-org-review-form" name="producer" value="<?php echo esc_attr( $c['producer'] ); ?>" placeholder="<?php esc_attr_e( 'Producer', 'cec' ); ?>" />
				<p class="description"><?php esc_html_e( 'Titleholders only.', 'cec' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Logo / photo', 'cec' ); ?></th><td><?php echo $logo ? wp_get_attachment_image( $logo, 'thumbnail' ) : esc_html__( 'None', 'cec' ); // phpcs:ignore ?></td></tr>
			<tr><th><label for="r_desc"><?php esc_html_e( 'Description / bio', 'cec' ); ?></label></th><td><textarea form="cec-org-review-form" class="large-text" rows="4" id="r_desc" name="description"><?php echo esc_textarea( $c['description'] ); ?></textarea></td></tr>
			<tr><th><label for="r_hl"><?php esc_html_e( 'Highlights', 'cec' ); ?></label></th><td><textarea form="cec-org-review-form" class="large-text" rows="3" id="r_hl" name="highlights"><?php echo esc_textarea( implode( "\n", (array) $c['highlights'] ) ); ?></textarea></td></tr>
			<tr><th><label for="r_mission"><?php esc_html_e( 'Mission / areas of focus', 'cec' ); ?></label></th><td><textarea form="cec-org-review-form" class="large-text" rows="5" id="r_mission" name="mission"><?php echo esc_textarea( $c['mission'] ); ?></textarea></td></tr>
			<?php foreach ( CEC_Orgs::socials() as $k => $label ) : ?>
				<tr><th><label for="r_s_<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input form="cec-org-review-form" type="url" class="regular-text" id="r_s_<?php echo esc_attr( $k ); ?>" name="social[<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $c['socials'][ $k ] ?? '' ); ?>" /></td></tr>
			<?php endforeach; ?>
			<tr><th><?php esc_html_e( 'Contact (private)', 'cec' ); ?></th><td>
				<input form="cec-org-review-form" name="contact_name" value="<?php echo esc_attr( $c['contact_name'] ); ?>" />
				<input form="cec-org-review-form" type="email" name="contact_email" value="<?php echo esc_attr( $c['contact_email'] ); ?>" />
				<input form="cec-org-review-form" name="contact_phone" value="<?php echo esc_attr( $c['contact_phone'] ); ?>" /></td></tr>
			<tr><th><label for="r_member"><?php esc_html_e( 'Member account to link', 'cec' ); ?></label></th><td><input form="cec-org-review-form" id="r_member" name="member" value="<?php echo esc_attr( $c['member'] ); ?>" />
				<?php
				$u = self::find_member( $c['member'] );
				if ( $c['member'] ) {
					echo ' <span class="description">' . esc_html( $u ? sprintf( /* translators: %s: display name */ __( 'Matches member: %s', 'cec' ), $u->display_name ) : __( 'No account with that username or email.', 'cec' ) ) . '</span>';
				}
				?>
				<p class="description"><?php esc_html_e( 'Linked when approved (the member is told and can remove it). Needs the Community Member Planning plugin.', 'cec' ); ?></p></td></tr>
		</table>
		<input form="cec-org-review-form" type="hidden" name="authorized" value="1" />
		<p>
			<button form="cec-org-review-form" type="submit" name="decision" value="approve" class="button button-primary"><?php echo esc_html( 'approved' === $review ? __( 'Save changes to the listing', 'cec' ) : __( 'Approve and publish', 'cec' ) ); ?></button>
			<?php if ( 'approved' !== $review ) : ?>
				<button form="cec-org-review-form" type="submit" name="decision" value="decline" class="button" onclick="return confirm('<?php echo esc_js( __( 'Decline this listing? The contact will be emailed.', 'cec' ) ); ?>');"><?php esc_html_e( 'Decline', 'cec' ); ?></button>
			<?php endif; ?>
		</p>
		<?php
	}

	/** The (empty) form the review box's fields and buttons submit, outside WordPress's post form. */
	public static function print_review_form() {
		if ( self::CPT === get_post_type() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="cec-org-review-form"></form>';
		}
	}

	public static function find_member( $ref ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return null;
		}
		$u = is_email( $ref ) ? get_user_by( 'email', $ref ) : get_user_by( 'login', $ref );
		return $u ? $u : null;
	}

	public static function handle_decision() {
		$id = isset( $_POST['submission'] ) ? absint( $_POST['submission'] ) : 0;
		if ( ! $id || ! current_user_can( 'manage_options' ) || self::CPT !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cec' ), 403 );
		}
		check_admin_referer( 'cec_org_decide_' . $id );
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		list( $c, $errors ) = self::validate( wp_unslash( $_POST ) );
		$stored = (array) get_post_meta( $id, '_cec_org', true );
		if ( 'decline' === $decision ) {
			update_post_meta( $id, '_cec_org_review', 'declined' );
			wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
			self::mail_contact(
				$stored + array( 'contact_email' => '' ),
				/* translators: %s: site name */
				sprintf( __( '[%s] About your listing', 'cec' ), CEC_Event_Helper::site_name() ),
				/* translators: 1: contact name, 2: listing name */
				sprintf( __( "Hi %1\$s,\n\nThank you for sending \"%2\$s\". We aren't able to list it on the site right now. If you have questions, reply to this email.", 'cec' ), $stored['contact_name'] ?? '', $stored['name'] ?? '' )
			);
			wp_safe_redirect( add_query_arg( 'cec_org_done', 'declined', self::review_url( $id ) ) );
			exit;
		}
		if ( $errors ) {
			wp_die( '<p>' . implode( '</p><p>', array_map( 'esc_html', $errors ) ) . '</p><p><a href="javascript:history.back()">' . esc_html__( 'Go back', 'cec' ) . '</a></p>' );
		}
		$term_id = self::approve( $id, $c );
		if ( is_wp_error( $term_id ) ) {
			wp_die( esc_html( $term_id->get_error_message() ) );
		}
		wp_safe_redirect( add_query_arg( 'cec_org_done', 'approved', self::review_url( $id ) ) );
		exit;
	}

	/**
	 * Creates or updates the group from a (reviewed) submission and lists it.
	 *
	 * @return int|WP_Error Term ID.
	 */
	public static function approve( $submission_id, $c ) {
		$first   = 'approved' !== get_post_meta( $submission_id, '_cec_org_review', true );
		$term_id = (int) get_post_meta( $submission_id, '_cec_org_term_id', true );
		if ( ! $term_id || ! get_term( $term_id, 'cec_partner_org' ) ) {
			$existing = get_term_by( 'name', $c['name'], 'cec_partner_org' );
			if ( $existing ) {
				$term_id = (int) $existing->term_id;
			} else {
				$r = wp_insert_term( $c['name'], 'cec_partner_org' );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				$term_id = (int) $r['term_id'];
			}
		} elseif ( get_term( $term_id )->name !== $c['name'] ) {
			wp_update_term( $term_id, 'cec_partner_org', array( 'name' => $c['name'] ) );
		}
		$logo_id = (int) get_post_meta( $submission_id, '_cec_org_logo_id', true );
		$current = CEC_Orgs::profile( $term_id );
		$fields  = array(
			'kind'        => $c['kind'],
			'description' => $c['description'],
			'highlights'  => $c['highlights'],
			'mission'     => $c['mission'],
			'title'       => $c['title'],
			'year'        => $c['year'],
			'producer'    => $c['producer'],
			'socials'     => $c['socials'],
			'listed'      => '1',
			'order'       => $current && $current['listed'] ? $current['order'] : CEC_Orgs::next_order(),
			'contact'     => array( 'name' => $c['contact_name'], 'email' => $c['contact_email'], 'phone' => $c['contact_phone'] ),
		);
		if ( $logo_id ) {
			$fields['logo_id'] = $logo_id;
			$fields['logo']    = (string) wp_get_attachment_url( $logo_id );
		}
		CEC_Orgs::save_profile( $term_id, $fields );
		update_post_meta( $submission_id, '_cec_org', $c );
		update_post_meta( $submission_id, '_cec_org_term_id', $term_id );
		update_post_meta( $submission_id, '_cec_org_review', 'approved' );
		update_post_meta( $submission_id, '_cec_org_contact_email', $c['contact_email'] );
		wp_update_post( array( 'ID' => $submission_id, 'post_status' => 'publish', 'post_title' => $c['name'] ) );

		$member = self::find_member( $c['member'] );
		if ( $member ) {
			/**
			 * A member account named on an approved submission. The member
			 * plugin links it to the group (and tells the member).
			 *
			 * @param int    $user_id
			 * @param int    $term_id
			 * @param string $role 'titleholder' or 'organizer'.
			 */
			do_action( 'cec_partner_org_member_requested', (int) $member->ID, $term_id, 'titleholder' === $c['kind'] ? 'titleholder' : 'organizer' );
		}
		if ( $first ) {
			$link = get_term_link( $term_id, 'cec_partner_org' );
			self::mail_contact(
				$c,
				/* translators: 1: site name, 2: listing name */
				sprintf( __( '[%1$s] "%2$s" is now listed', 'cec' ), CEC_Event_Helper::site_name(), $c['name'] ),
				/* translators: 1: contact name, 2: listing name, 3: page link */
				sprintf( __( "Hi %1\$s,\n\n\"%2\$s\" is approved and now on the site:\n%3\$s\n\nEvents tagged with it on the community calendar show on that page. To change anything, reply to this email.", 'cec' ), $c['contact_name'], $c['name'], is_wp_error( $link ) ? home_url( '/' ) : $link )
			);
		}
		return $term_id;
	}

	/** A declined or deleted submission's image goes with it (unless a listing uses it). */
	public static function delete_logo_with_submission( $post_id ) {
		if ( self::CPT !== get_post_type( $post_id ) ) {
			return;
		}
		$logo = (int) get_post_meta( $post_id, '_cec_org_logo_id', true );
		if ( $logo && ! get_term_meta( (int) get_post_meta( $post_id, '_cec_org_term_id', true ), 'cec_logo_id', true ) ) {
			wp_delete_attachment( $logo, true );
		}
	}

	/* ------------------------------------------------------------------
	 * Personal data (called from CEC_Privacy)
	 * ---------------------------------------------------------------- */

	public static function find_by_email( $email ) {
		return get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'numberposts' => 50, 'meta_key' => '_cec_org_contact_email', 'meta_value' => $email ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	public static function export_items( $email ) {
		$items = array();
		foreach ( self::find_by_email( $email ) as $p ) {
			$c       = (array) get_post_meta( $p->ID, '_cec_org', true );
			$items[] = array(
				'group_id'    => 'cec-org-submissions',
				'group_label' => __( 'Organization / titleholder listings you submitted', 'cec' ),
				'item_id'     => 'cec-org-submission-' . $p->ID,
				'data'        => array(
					array( 'name' => __( 'Listing', 'cec' ), 'value' => $c['name'] ?? '' ),
					array( 'name' => __( 'Contact name', 'cec' ), 'value' => $c['contact_name'] ?? '' ),
					array( 'name' => __( 'Contact email', 'cec' ), 'value' => $c['contact_email'] ?? '' ),
					array( 'name' => __( 'Contact phone', 'cec' ), 'value' => $c['contact_phone'] ?? '' ),
					array( 'name' => __( 'Submitted', 'cec' ), 'value' => $p->post_date ),
				),
			);
		}
		return $items;
	}

	/** Removes the contact details; the public listing itself stays. */
	public static function erase( $email ) {
		$n = 0;
		foreach ( self::find_by_email( $email ) as $p ) {
			$c = (array) get_post_meta( $p->ID, '_cec_org', true );
			$c['contact_name'] = $c['contact_email'] = $c['contact_phone'] = '';
			update_post_meta( $p->ID, '_cec_org', $c );
			delete_post_meta( $p->ID, '_cec_org_contact_email' );
			$term = (int) get_post_meta( $p->ID, '_cec_org_term_id', true );
			if ( $term ) {
				delete_term_meta( $term, 'cec_contact' );
			}
			++$n;
		}
		return $n;
	}
}
