<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Titleholders and organizers edit their own page (1.35.0).
 *
 * Owner's choices (2026-10-10): edits by the group's own titleholder or
 * organizer go live right away, the site admin is emailed each change so
 * anything can be undone, and the event gallery is public like the rest of
 * the page (the uploader confirms everyone pictured agreed).
 *
 * - Who may edit: anyone who can manage partner groups in wp-admin, plus
 *   whoever the filter cec_partner_org_can_edit allows. The member plugin
 *   answers it for members linked to the group as Titleholder or Organizer.
 * - What they edit, at /partner/<slug>/?cec_org_edit=1: description or bio,
 *   highlights, mission / areas of focus, title / year / producer, links,
 *   the photo or logo, and a gallery of photos with captions. The name,
 *   kind, listing and private contact stay with the site admin.
 * - Every save keeps the previous version (last 10) in cec_profile_history;
 *   the group's wp-admin edit screen lists them with a Restore button.
 * - Gallery: term meta cec_gallery = list of array( id, caption ). Each
 *   image is a media-library attachment marked _cec_org_gallery_term, and
 *   is deleted when removed from the gallery.
 */
class CEC_Org_Editor {

	const ACTION      = 'cec_org_edit';
	const RESTORE     = 'cec_org_restore';
	const MAX_GALLERY = 30;
	const NEW_SLOTS   = 6;
	const HISTORY     = 10;

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'to_login' ) );
		add_action( 'admin_post_' . self::RESTORE, array( __CLASS__, 'handle_restore' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ) );
		add_action( 'cec_partner_org_edit_form_fields', array( __CLASS__, 'history_fields' ), 40 );
		add_action( 'pre_delete_term', array( __CLASS__, 'on_delete_term' ), 10, 2 );
	}

	public static function can_edit( $term_id, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id || ! $term_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_categories' ) ) {
			return true;
		}
		/**
		 * May this signed-in user edit this group's public page?
		 *
		 * @param bool $allowed
		 * @param int  $user_id
		 * @param int  $term_id
		 */
		return (bool) apply_filters( 'cec_partner_org_can_edit', false, $user_id, (int) $term_id );
	}

	public static function edit_url( $term ) {
		$link = get_term_link( $term, 'cec_partner_org' );
		return is_wp_error( $link ) ? '' : add_query_arg( 'cec_org_edit', '1', $link );
	}

	public static function is_editing( $term ) {
		return isset( $_GET['cec_org_edit'] ) && self::can_edit( $term->term_id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** Signed-in views of a group page (and the editor) are never cached. */
	public static function no_cache() {
		if ( is_user_logged_in() && is_tax( 'cec_partner_org' ) ) {
			nocache_headers();
		}
	}

	public static function to_login() {
		$back = isset( $_POST['term'] ) ? self::edit_url( absint( $_POST['term'] ) ) : home_url( '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_safe_redirect( wp_login_url( $back ? $back : home_url( '/' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Gallery
	 * ---------------------------------------------------------------- */

	public static function gallery( $term_id ) {
		$g   = get_term_meta( $term_id, 'cec_gallery', true );
		$out = array();
		foreach ( is_array( $g ) ? $g : array() as $item ) {
			if ( ! empty( $item['id'] ) && wp_attachment_is_image( (int) $item['id'] ) ) {
				$out[] = array( 'id' => (int) $item['id'], 'caption' => (string) ( $item['caption'] ?? '' ) );
			}
		}
		return $out;
	}

	public static function gallery_html( $p ) {
		$items = self::gallery( $p['id'] );
		if ( ! $items ) {
			return '';
		}
		$heading = 'titleholder' === $p['kind'] ? __( 'Events & Appearances', 'cec' ) : __( 'Gallery', 'cec' );
		$html    = '<section class="cec-org-gallery" aria-labelledby="cec-org-gallery-title"><h2 id="cec-org-gallery-title">' . esc_html( $heading ) . '</h2><ul class="cec-org-gallery-grid">';
		foreach ( $items as $i => $item ) {
			/* translators: 1: photo number, 2: group name */
			$alt   = '' !== $item['caption'] ? $item['caption'] : sprintf( __( 'Photo %1$d from %2$s', 'cec' ), $i + 1, $p['name'] );
			$full  = wp_get_attachment_image_url( $item['id'], 'full' );
			$html .= '<li><figure><a href="' . esc_url( $full ) . '" target="_blank" rel="noopener">' . wp_get_attachment_image( $item['id'], 'medium_large', false, array( 'alt' => $alt, 'loading' => 'lazy', 'class' => 'cec-org-gallery-img' ) ) . '</a>';
			$html .= '' !== $item['caption'] ? '<figcaption>' . esc_html( $item['caption'] ) . '</figcaption>' : '';
			$html .= '</figure></li>';
		}
		return $html . '</ul></section>';
	}

	/* ------------------------------------------------------------------
	 * The editor
	 * ---------------------------------------------------------------- */

	private static function err_key( $term_id ) {
		return 'cec_org_edit_' . get_current_user_id() . '_' . (int) $term_id;
	}

	/** "Edit this page" for people who may edit it (on the page itself). */
	public static function edit_button_html( $p ) {
		if ( ! self::can_edit( $p['id'] ) ) {
			return '';
		}
		$html = '';
		if ( isset( $_GET['cec_org_msg'] ) && 'saved' === $_GET['cec_org_msg'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$html .= '<div class="cec-org-notice is-success cec-org-saved" role="status"><p>' . esc_html__( 'Saved. Your changes are live.', 'cec' ) . '</p></div>';
		}
		return $html . '<p class="cec-org-edit-bar"><a class="cec-org-btn is-primary" href="' . esc_url( self::edit_url( $p['id'] ) ) . '">' . esc_html__( 'Edit this page', 'cec' ) . '</a></p>';
	}

	public static function form_html( $term ) {
		$p = CEC_Orgs::profile( $term );
		if ( ! $p ) {
			return '';
		}
		wp_enqueue_style( 'cec-orgs' );
		$saved  = get_transient( self::err_key( $p['id'] ) );
		$errors = $saved ? (array) $saved['errors'] : array();
		$old    = $saved ? (array) $saved['old'] : array();
		if ( $saved ) {
			delete_transient( self::err_key( $p['id'] ) );
		}
		$v = function ( $k, $current ) use ( $old ) {
			return array_key_exists( $k, $old ) && is_string( $old[ $k ] ) ? $old[ $k ] : $current;
		};
		$is_title = 'titleholder' === $p['kind'];
		$gallery  = self::gallery( $p['id'] );
		ob_start();
		?>
		<section class="cec-org-form-wrap cec-org-editor" aria-labelledby="cec-org-edit-title">
			<h1 id="cec-org-edit-title" class="cec-org-edit-title"><?php echo esc_html( sprintf( /* translators: %s: group name */ __( 'Edit: %s', 'cec' ), $p['name'] ) ); ?></h1>
			<p><?php esc_html_e( 'Changes go live as soon as you save. The COL&B team is told about each change. To change the name or ask for anything else, contact the team.', 'cec' ); ?> <a href="<?php echo esc_url( $p['url'] ); ?>"><?php esc_html_e( 'Back to the page', 'cec' ); ?></a></p>
			<?php if ( $errors ) : ?>
				<div class="cec-org-notice is-error" role="alert" tabindex="-1" id="cec-org-errors">
					<p><strong><?php esc_html_e( 'Nothing was saved. Please fix the following:', 'cec' ); ?></strong></p>
					<ul><?php foreach ( $errors as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?></ul>
				</div>
			<?php endif; ?>
			<form class="cec-org-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<input type="hidden" name="term" value="<?php echo (int) $p['id']; ?>" />
				<?php wp_nonce_field( 'cec_org_edit_' . $p['id'], '_cec_nonce' ); ?>

				<?php if ( $is_title ) : ?>
					<fieldset>
						<legend><?php esc_html_e( 'Title', 'cec' ); ?></legend>
						<div class="cec-org-row">
							<p class="cec-org-field"><label for="e_title"><?php esc_html_e( 'Title', 'cec' ); ?></label><input type="text" id="e_title" name="title" maxlength="120" value="<?php echo esc_attr( $v( 'title', $p['title'] ) ); ?>" /></p>
							<p class="cec-org-field cec-org-year"><label for="e_year"><?php esc_html_e( 'Year', 'cec' ); ?></label><input type="number" id="e_year" name="year" min="1970" max="2100" value="<?php echo esc_attr( $v( 'year', $p['year'] ) ); ?>" /></p>
						</div>
						<p class="cec-org-field"><label for="e_producer"><?php esc_html_e( 'Producer', 'cec' ); ?></label><input type="text" id="e_producer" name="producer" maxlength="120" value="<?php echo esc_attr( $v( 'producer', $p['producer'] ) ); ?>" /><span class="cec-org-hint"><?php esc_html_e( 'The contest or organization that awards the title.', 'cec' ); ?></span></p>
					</fieldset>
				<?php endif; ?>

				<fieldset>
					<legend><?php esc_html_e( 'About', 'cec' ); ?></legend>
					<p class="cec-org-field"><label for="e_desc"><?php echo esc_html( $is_title ? __( 'Bio (required)', 'cec' ) : __( 'Short description (required)', 'cec' ) ); ?></label>
						<textarea id="e_desc" name="description" rows="5" maxlength="600" required><?php echo esc_textarea( $v( 'description', $p['description'] ) ); ?></textarea><span class="cec-org-hint"><?php esc_html_e( 'Up to 600 characters.', 'cec' ); ?></span></p>
					<?php if ( ! $is_title ) : ?>
						<p class="cec-org-field"><label for="e_hl"><?php esc_html_e( 'Highlights (optional)', 'cec' ); ?></label>
							<textarea id="e_hl" name="highlights" rows="3" maxlength="600"><?php echo esc_textarea( $v( 'highlights', implode( "\n", $p['highlights'] ) ) ); ?></textarea><span class="cec-org-hint"><?php esc_html_e( 'One per line, up to 8. Shown as bullet points on the homepage card.', 'cec' ); ?></span></p>
					<?php endif; ?>
					<p class="cec-org-field"><label for="e_mission"><?php echo esc_html( $is_title ? __( 'Areas of focus / mission (optional)', 'cec' ) : __( 'Mission (optional)', 'cec' ) ); ?></label>
						<textarea id="e_mission" name="mission" rows="6" maxlength="2000"><?php echo esc_textarea( $v( 'mission', $p['mission'] ) ); ?></textarea></p>
				</fieldset>

				<fieldset>
					<legend><?php esc_html_e( 'Links', 'cec' ); ?></legend>
					<?php foreach ( CEC_Orgs::socials() as $k => $label ) : ?>
						<?php $val = isset( $old['social'][ $k ] ) && is_string( $old['social'][ $k ] ) ? $old['social'][ $k ] : ( $p['socials'][ $k ] ?? '' ); ?>
						<p class="cec-org-field"><label for="e_s_<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label>
							<input type="url" id="e_s_<?php echo esc_attr( $k ); ?>" name="social[<?php echo esc_attr( $k ); ?>]" placeholder="<?php echo esc_attr( 'telegram' === $k ? 'https://t.me/+…' : 'https://' ); ?>" value="<?php echo esc_attr( $val ); ?>" /></p>
					<?php endforeach; ?>
					<p class="cec-org-hint"><?php esc_html_e( 'The first link is the big pink button. Leave a box empty to remove that link.', 'cec' ); ?></p>
				</fieldset>

				<fieldset>
					<legend><?php echo esc_html( $is_title ? __( 'Photo', 'cec' ) : __( 'Logo', 'cec' ) ); ?></legend>
					<?php $img = CEC_Orgs::image_html( $p, 'cec-org-edit-thumb' ); ?>
					<?php if ( $img ) : ?>
						<div class="cec-org-edit-current"><?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image HTML. ?>
							<p class="cec-org-check"><label><input type="checkbox" name="remove_photo" value="1" /> <?php esc_html_e( 'Remove it', 'cec' ); ?></label></p></div>
					<?php endif; ?>
					<p class="cec-org-field"><label for="e_photo"><?php echo esc_html( $img ? __( 'Replace with a new image (optional)', 'cec' ) : __( 'Add an image (optional)', 'cec' ) ); ?></label>
						<input type="file" id="e_photo" name="photo" accept="image/jpeg,image/png,image/webp" /><span class="cec-org-hint"><?php esc_html_e( 'JPEG, PNG or WebP, up to 5 MB. Shown in a circle, so a square image works best.', 'cec' ); ?></span></p>
				</fieldset>

				<fieldset>
					<legend><?php echo esc_html( $is_title ? __( 'Events & Appearances gallery', 'cec' ) : __( 'Gallery', 'cec' ) ); ?></legend>
					<p class="cec-org-hint"><?php echo esc_html( sprintf( /* translators: %d: maximum photos */ __( 'Photos from events, shown on your page with their captions, in this order. Up to %d photos.', 'cec' ), self::MAX_GALLERY ) ); ?></p>
					<?php if ( $gallery ) : ?>
						<ul class="cec-org-edit-gallery">
							<?php foreach ( $gallery as $i => $item ) : ?>
								<li>
									<?php echo wp_get_attachment_image( $item['id'], 'thumbnail', false, array( 'alt' => '' ) ); ?>
									<div>
										<p class="cec-org-field"><label for="g_cap_<?php echo (int) $item['id']; ?>"><?php echo esc_html( sprintf( /* translators: %d: photo number */ __( 'Caption for photo %d', 'cec' ), $i + 1 ) ); ?></label>
											<input type="text" id="g_cap_<?php echo (int) $item['id']; ?>" name="gallery[<?php echo (int) $item['id']; ?>][caption]" maxlength="200" value="<?php echo esc_attr( $item['caption'] ); ?>" /></p>
										<div class="cec-org-row">
											<p class="cec-org-field cec-org-year"><label for="g_ord_<?php echo (int) $item['id']; ?>"><?php esc_html_e( 'Position', 'cec' ); ?></label>
												<input type="number" id="g_ord_<?php echo (int) $item['id']; ?>" name="gallery[<?php echo (int) $item['id']; ?>][order]" min="1" max="<?php echo (int) self::MAX_GALLERY; ?>" value="<?php echo (int) ( $i + 1 ); ?>" /></p>
											<p class="cec-org-check"><label><input type="checkbox" name="gallery[<?php echo (int) $item['id']; ?>][remove]" value="1" /> <?php esc_html_e( 'Remove this photo', 'cec' ); ?></label></p>
										</div>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( count( $gallery ) < self::MAX_GALLERY ) : ?>
						<p><strong><?php esc_html_e( 'Add photos', 'cec' ); ?></strong> <span class="cec-org-hint"><?php echo esc_html( sprintf( /* translators: %d: photos per save */ __( 'Up to %d at a time; JPEG, PNG or WebP, up to 5 MB each.', 'cec' ), self::NEW_SLOTS ) ); ?></span></p>
						<?php for ( $n = 1; $n <= self::NEW_SLOTS; $n++ ) : ?>
							<div class="cec-org-row cec-org-new-photo">
								<p class="cec-org-field"><label for="g_new_<?php echo (int) $n; ?>"><?php echo esc_html( sprintf( /* translators: %d: slot number */ __( 'New photo %d', 'cec' ), $n ) ); ?></label>
									<input type="file" id="g_new_<?php echo (int) $n; ?>" name="new_photo_<?php echo (int) $n; ?>" accept="image/jpeg,image/png,image/webp" /></p>
								<p class="cec-org-field"><label for="g_newcap_<?php echo (int) $n; ?>"><?php esc_html_e( 'Caption', 'cec' ); ?></label>
									<input type="text" id="g_newcap_<?php echo (int) $n; ?>" name="new_caption_<?php echo (int) $n; ?>" maxlength="200" placeholder="<?php esc_attr_e( 'e.g. Judging at Great Lakes Leather 2026', 'cec' ); ?>" /></p>
							</div>
						<?php endfor; ?>
						<p class="cec-org-check"><label><input type="checkbox" name="consent" value="1" /> <?php esc_html_e( 'Everyone who can be recognised in the photos I\'m adding agreed to be shown publicly on this site.', 'cec' ); ?></label></p>
					<?php endif; ?>
				</fieldset>

				<p><button type="submit" class="cec-org-submit"><?php esc_html_e( 'Save changes', 'cec' ); ?></button></p>
			</form>
		</section>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * Saving
	 * ---------------------------------------------------------------- */

	private static function file( $field ) {
		$f = isset( $_FILES[ $field ] ) && is_array( $_FILES[ $field ] ) ? $_FILES[ $field ] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing
		return ( $f && isset( $f['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $f['error'] ) ? $f : null;
	}

	/** The fields history keeps and Restore puts back. */
	private static function snapshot( $term_id ) {
		$p = CEC_Orgs::profile( $term_id );
		return array(
			'description' => $p['description'],
			'highlights'  => $p['highlights'],
			'mission'     => $p['mission'],
			'title'       => $p['title'],
			'year'        => $p['year'],
			'producer'    => $p['producer'],
			'socials'     => $p['socials'],
			'logo'        => $p['logo'],
			'logo_id'     => $p['logo_id'],
			'gallery'     => self::gallery( $term_id ),
		);
	}

	private static function remember( $term_id, $snapshot ) {
		$h = get_term_meta( $term_id, 'cec_profile_history', true );
		$h = is_array( $h ) ? $h : array();
		array_unshift( $h, array( 'at' => time(), 'by' => get_current_user_id(), 'data' => $snapshot ) );
		update_term_meta( $term_id, 'cec_profile_history', array_slice( $h, 0, self::HISTORY ) );
	}

	public static function handle() {
		$term_id = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$term    = $term_id ? get_term( $term_id, 'cec_partner_org' ) : null;
		if ( ! $term || is_wp_error( $term ) || ! self::can_edit( $term_id ) ) {
			wp_die( esc_html__( 'You can\'t edit this page.', 'cec' ), 403 );
		}
		check_admin_referer( 'cec_org_edit_' . $term_id, '_cec_nonce' );
		$post = wp_unslash( $_POST );
		$p    = CEC_Orgs::profile( $term );
		$e    = array();
		$s    = function ( $k, $max, $multi = false ) use ( $post ) {
			$raw = isset( $post[ $k ] ) && is_scalar( $post[ $k ] ) ? (string) $post[ $k ] : '';
			return mb_substr( trim( $multi ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw ) ), 0, $max );
		};
		$is_title = 'titleholder' === $p['kind'];
		$new      = array(
			'description' => $s( 'description', 600, true ),
			'mission'     => $s( 'mission', 2000, true ),
			'highlights'  => $is_title ? $p['highlights'] : array_slice( array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $s( 'highlights', 600, true ) ) ) ) ), 0, 8 ),
			'title'       => $is_title ? $s( 'title', 120 ) : $p['title'],
			'year'        => $is_title ? $s( 'year', 4 ) : $p['year'],
			'producer'    => $is_title ? $s( 'producer', 120 ) : $p['producer'],
			'socials'     => array(),
		);
		if ( '' === $new['description'] ) {
			$e[] = $is_title ? __( 'Please write a short bio.', 'cec' ) : __( 'Please write a short description.', 'cec' );
		}
		if ( '' !== $new['year'] && ! preg_match( '/^(19|20|21)\d{2}$/', $new['year'] ) ) {
			$e[] = __( 'The year should be four digits, e.g. 2026.', 'cec' );
		}
		$social = isset( $post['social'] ) && is_array( $post['social'] ) ? $post['social'] : array();
		foreach ( array_keys( CEC_Orgs::socials() ) as $k ) {
			$clean = CEC_Orgs::clean_social( $k, isset( $social[ $k ] ) && is_scalar( $social[ $k ] ) ? $social[ $k ] : '' );
			if ( is_wp_error( $clean ) ) {
				$e[] = $clean->get_error_message();
			} elseif ( '' !== $clean ) {
				$new['socials'][ $k ] = $clean;
			}
		}

		// Images: check everything before storing anything.
		$photo = self::file( 'photo' );
		if ( $photo ) {
			$c = CEC_Org_Submissions::check_image( $photo );
			if ( is_wp_error( $c ) ) {
				/* translators: %s: problem */
				$e[] = sprintf( __( 'Photo: %s', 'cec' ), $c->get_error_message() );
			}
		}
		$current = self::gallery( $term_id );
		$posted  = isset( $post['gallery'] ) && is_array( $post['gallery'] ) ? $post['gallery'] : array();
		$keep    = array();
		$removed = array();
		foreach ( $current as $i => $item ) {
			$row = isset( $posted[ $item['id'] ] ) && is_array( $posted[ $item['id'] ] ) ? $posted[ $item['id'] ] : array();
			if ( ! empty( $row['remove'] ) ) {
				$removed[] = $item['id'];
				continue;
			}
			$keep[] = array(
				'id'      => $item['id'],
				'caption' => isset( $row['caption'] ) && is_scalar( $row['caption'] ) ? mb_substr( trim( sanitize_text_field( (string) $row['caption'] ) ), 0, 200 ) : $item['caption'],
				'order'   => isset( $row['order'] ) && is_numeric( $row['order'] ) ? (float) $row['order'] : $i + 1,
				'i'       => $i,
			);
		}
		usort(
			$keep,
			function ( $a, $b ) {
				return ( $a['order'] <=> $b['order'] ) ?: ( $a['i'] <=> $b['i'] );
			}
		);
		$adds = array();
		for ( $n = 1; $n <= self::NEW_SLOTS; $n++ ) {
			$f = self::file( 'new_photo_' . $n );
			if ( ! $f ) {
				continue;
			}
			$c = CEC_Org_Submissions::check_image( $f );
			if ( is_wp_error( $c ) ) {
				/* translators: 1: slot number, 2: problem */
				$e[] = sprintf( __( 'New photo %1$d: %2$s', 'cec' ), $n, $c->get_error_message() );
			}
			$adds[] = array( 'file' => $f, 'caption' => $s( 'new_caption_' . $n, 200 ) );
		}
		if ( $adds && empty( $post['consent'] ) ) {
			$e[] = __( 'Please confirm that everyone recognisable in the new photos agreed to be shown publicly.', 'cec' );
		}
		if ( count( $keep ) + count( $adds ) > self::MAX_GALLERY ) {
			/* translators: %d: maximum photos */
			$e[] = sprintf( __( 'The gallery holds up to %d photos. Remove some before adding more.', 'cec' ), self::MAX_GALLERY );
		}

		if ( $e ) {
			$old = array_merge( $new, array( 'highlights' => isset( $post['highlights'] ) && is_scalar( $post['highlights'] ) ? (string) $post['highlights'] : '', 'social' => $social ) );
			unset( $old['socials'] );
			set_transient( self::err_key( $term_id ), array( 'errors' => $e, 'old' => $old ), 30 * MINUTE_IN_SECONDS );
			wp_safe_redirect( self::edit_url( $term ) . '#cec-org-errors' );
			exit;
		}

		$before = self::snapshot( $term_id );
		self::remember( $term_id, $before );

		if ( $photo ) {
			$att = CEC_Org_Submissions::store_image( $photo, 0, $p['name'] );
			if ( $att ) {
				$new['logo_id'] = $att;
				$new['logo']    = (string) wp_get_attachment_url( $att );
				update_post_meta( $att, '_cec_org_photo_term', $term_id );
			}
		} elseif ( ! empty( $post['remove_photo'] ) ) {
			$new['logo_id'] = 0;
			$new['logo']    = '';
		}
		$gallery = array();
		foreach ( $keep as $k ) {
			$gallery[] = array( 'id' => $k['id'], 'caption' => $k['caption'] );
		}
		foreach ( $adds as $a ) {
			$att = CEC_Org_Submissions::store_image( $a['file'], 0, $p['name'] );
			if ( $att ) {
				update_post_meta( $att, '_cec_org_gallery_term', $term_id );
				if ( '' !== $a['caption'] ) {
					update_post_meta( $att, '_wp_attachment_image_alt', $a['caption'] );
				}
				$gallery[] = array( 'id' => $att, 'caption' => $a['caption'] );
			}
		}
		CEC_Orgs::save_profile( $term_id, $new );
		update_term_meta( $term_id, 'cec_gallery', $gallery );
		// Removed gallery photos stay in the history snapshot until it rolls
		// over, so Restore can bring them back; deleted then (see prune()).
		self::prune( $term_id );

		self::notify( $term, $before, self::snapshot( $term_id ), count( $adds ), count( $removed ) );
		wp_safe_redirect( add_query_arg( 'cec_org_msg', 'saved', get_term_link( $term ) ) );
		exit;
	}

	/**
	 * Deletes gallery/photo attachments of this group that neither the
	 * current page nor any kept version uses any more.
	 */
	private static function prune( $term_id ) {
		$used = array();
		$all  = array( self::snapshot( $term_id ) );
		$h    = get_term_meta( $term_id, 'cec_profile_history', true );
		foreach ( is_array( $h ) ? $h : array() as $v ) {
			$all[] = $v['data'];
		}
		foreach ( $all as $snap ) {
			$used[] = (int) ( $snap['logo_id'] ?? 0 );
			foreach ( (array) ( $snap['gallery'] ?? array() ) as $g ) {
				$used[] = (int) $g['id'];
			}
		}
		$mine = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array( 'key' => '_cec_org_gallery_term', 'value' => (int) $term_id ),
					array( 'key' => '_cec_org_photo_term', 'value' => (int) $term_id ),
				),
			)
		);
		foreach ( $mine as $id ) {
			if ( ! in_array( (int) $id, $used, true ) ) {
				wp_delete_attachment( $id, true );
			}
		}
	}

	private static function notify( $term, $before, $after, $added, $removed ) {
		if ( current_user_can( 'manage_options' ) ) {
			return; // The site admin doesn't need to be told about their own edits.
		}
		$user    = wp_get_current_user();
		$labels  = array(
			'description' => __( 'Description / bio', 'cec' ),
			'highlights'  => __( 'Highlights', 'cec' ),
			'mission'     => __( 'Mission / areas of focus', 'cec' ),
			'title'       => __( 'Title', 'cec' ),
			'year'        => __( 'Year', 'cec' ),
			'producer'    => __( 'Producer', 'cec' ),
			'socials'     => __( 'Links', 'cec' ),
			'logo_id'     => __( 'Photo / logo', 'cec' ),
		);
		$changes = array();
		foreach ( $labels as $k => $label ) {
			$a = $before[ $k ];
			$b = $after[ $k ];
			if ( $a === $b ) {
				continue;
			}
			if ( 'logo_id' === $k ) {
				$changes[] = '- ' . $label . ': ' . ( $b ? __( 'new image', 'cec' ) : __( 'removed', 'cec' ) );
				continue;
			}
			$fmt       = function ( $x ) {
				if ( is_array( $x ) ) {
					$parts = array();
					foreach ( $x as $kk => $vv ) {
						$parts[] = is_string( $kk ) ? $kk . ': ' . $vv : $vv;
					}
					return implode( '; ', $parts );
				}
				return (string) $x;
			};
			$changes[] = '- ' . $label . "\n    was: " . ( '' === $fmt( $a ) ? '(empty)' : $fmt( $a ) ) . "\n    now: " . ( '' === $fmt( $b ) ? '(empty)' : $fmt( $b ) );
		}
		if ( $added || $removed || wp_list_pluck( $before['gallery'], 'caption' ) !== wp_list_pluck( $after['gallery'], 'caption' ) ) {
			/* translators: 1: added, 2: removed, 3: total */
			$changes[] = '- ' . sprintf( __( 'Gallery: %1$d added, %2$d removed, %3$d in total (captions or order may have changed)', 'cec' ), $added, $removed, count( $after['gallery'] ) );
		}
		if ( ! $changes ) {
			return;
		}
		$link = get_term_link( $term );
		wp_mail(
			get_option( 'admin_email' ),
			/* translators: 1: site name, 2: group name */
			sprintf( __( '[%1$s] %2$s page was edited', 'cec' ), CEC_Event_Helper::site_name(), $term->name ),
			/* translators: 1: who, 2: group name, 3: changes, 4: page link, 5: admin link */
			sprintf( __( "%1\$s edited the page for %2\$s. The changes are live.\n\n%3\$s\n\nSee the page: %4\$s\nUndo (Restore an earlier version, at the bottom of the edit screen): %5\$s", 'cec' ), $user->display_name . ' (' . $user->user_login . ')', $term->name, implode( "\n", $changes ), is_wp_error( $link ) ? '' : $link, admin_url( 'term.php?taxonomy=cec_partner_org&tag_ID=' . (int) $term->term_id ) )
		);
	}

	/* ------------------------------------------------------------------
	 * Undo (wp-admin)
	 * ---------------------------------------------------------------- */

	public static function history_fields( $term ) {
		$h = get_term_meta( $term->term_id, 'cec_profile_history', true );
		?>
		<tr class="form-field"><th scope="row"><?php esc_html_e( 'Page edits', 'cec' ); ?></th><td>
			<p class="description"><?php esc_html_e( 'Edits made with "Edit this page" on the site (by you, or by the group\'s titleholder or organizer). Restore puts the page back as it was before that edit: text, links, photo and gallery.', 'cec' ); ?>
				<a href="<?php echo esc_url( self::edit_url( $term ) ); ?>"><?php esc_html_e( 'Open the page editor', 'cec' ); ?></a></p>
			<?php if ( is_array( $h ) && $h ) : ?>
				<ul>
					<?php foreach ( $h as $i => $v ) : ?>
						<?php $u = get_userdata( (int) $v['by'] ); ?>
						<li><?php echo esc_html( sprintf( /* translators: 1: date, 2: who */ __( '%1$s by %2$s', 'cec' ), wp_date( 'M j, Y g:i A', (int) $v['at'] ), $u ? $u->display_name : __( 'a former user', 'cec' ) ) ); ?>
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::RESTORE . '&term=' . (int) $term->term_id . '&v=' . (int) $i ), 'cec_org_restore_' . $term->term_id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Put the page back as it was before this edit?', 'cec' ) ); ?>');"><?php esc_html_e( 'Restore the version before this edit', 'cec' ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No edits yet.', 'cec' ); ?></p>
			<?php endif; ?>
		</td></tr>
		<?php
	}

	public static function handle_restore() {
		$term_id = isset( $_GET['term'] ) ? absint( $_GET['term'] ) : 0;
		$i       = isset( $_GET['v'] ) ? absint( $_GET['v'] ) : -1;
		if ( ! $term_id || ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cec' ), 403 );
		}
		check_admin_referer( 'cec_org_restore_' . $term_id );
		$h = get_term_meta( $term_id, 'cec_profile_history', true );
		if ( ! is_array( $h ) || ! isset( $h[ $i ] ) ) {
			wp_die( esc_html__( 'That version is no longer kept.', 'cec' ) );
		}
		$data = $h[ $i ]['data'];
		self::remember( $term_id, self::snapshot( $term_id ) );
		$gallery = array();
		foreach ( (array) $data['gallery'] as $g ) {
			if ( wp_attachment_is_image( (int) $g['id'] ) ) {
				$gallery[] = array( 'id' => (int) $g['id'], 'caption' => (string) $g['caption'] );
			}
		}
		$fields = array_intersect_key( $data, array_flip( array( 'description', 'highlights', 'mission', 'title', 'year', 'producer', 'socials', 'logo', 'logo_id' ) ) );
		CEC_Orgs::save_profile( $term_id, $fields );
		update_term_meta( $term_id, 'cec_gallery', $gallery );
		self::prune( $term_id );
		wp_safe_redirect( add_query_arg( 'message', 3, admin_url( 'term.php?taxonomy=cec_partner_org&tag_ID=' . $term_id ) ) );
		exit;
	}

	/** Deleting a group deletes its gallery and editor-uploaded photos. */
	public static function on_delete_term( $term_id, $taxonomy ) {
		if ( 'cec_partner_org' !== $taxonomy ) {
			return;
		}
		delete_term_meta( $term_id, 'cec_profile_history' );
		delete_term_meta( $term_id, 'cec_gallery' );
		self::prune( $term_id );
	}
}
