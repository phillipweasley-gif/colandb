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
 * - What they edit, at /partner/<slug>/?cec_org_edit=1 (1.36.0: everything
 *   on their homepage card too: name, card text, bullet points):
 *   description or bio,
 *   highlights, mission / areas of focus, title / year / producer, links,
 *   the photo or logo, and a gallery of photos with captions. Kind,
 *   listing (approval) and the private contact stay with the site admin.
 * - Every save keeps the previous version (last 10) in cec_profile_history;
 *   the group's wp-admin edit screen lists them with a Restore button.
 * - Gallery: term meta cec_gallery = list of array( id, caption ). Each
 *   image is a media-library attachment marked _cec_org_gallery_term, and
 *   is deleted when removed from the gallery.
 * - 1.37.1: typed changes are kept as a browser draft (localStorage) and
 *   restored after a refresh; leaving with unsaved changes asks first.
 * - 1.37.1: photos upload one by one as they're picked (cec_org_upload),
 *   with no per-save limit, and each can be removed before saving.
 * - 1.37.0: a save keeps every valid change and lists what it couldn't use
 *   (nothing is thrown away); photos are added many at a time (photos[]);
 *   photos added without the consent box wait, marked _cec_org_held_term /
 *   _cec_org_held_by, until the uploader confirms.
 */
class CEC_Org_Editor {

	const ACTION      = 'cec_org_edit';
	const RESTORE     = 'cec_org_restore';
	const UPLOAD      = 'cec_org_upload';
	const MAX_GALLERY = 60;
	const BULK_MAX    = 15;          // Photos per save (the server takes 20 files per request).
	const PHOTO_BYTES = 10485760;    // 10 MB each.
	const SAVE_BYTES  = 94371840;    // 90 MB per save (the CDN refuses requests over 100 MB).
	const HISTORY     = 10;

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'to_login' ) );
		add_action( 'admin_post_' . self::RESTORE, array( __CLASS__, 'handle_restore' ) );
		add_action( 'admin_post_' . self::UPLOAD, array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_nopriv_' . self::UPLOAD, array( __CLASS__, 'upload_signed_out' ) );
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
		$notes = (array) get_transient( self::err_key( $p['id'] ) );
		$notes = array_values( array_filter( $notes, 'is_string' ) );
		delete_transient( self::err_key( $p['id'] ) );
		$old = array();
		$v   = function ( $k, $current ) {
			return $current;
		};
		$held = self::held( $p['id'] );
		$is_title = 'titleholder' === $p['kind'];
		$gallery  = self::gallery( $p['id'] );
		ob_start();
		?>
		<section class="cec-org-form-wrap cec-org-editor" aria-labelledby="cec-org-edit-title">
			<h1 id="cec-org-edit-title" class="cec-org-edit-title"><?php echo esc_html( sprintf( /* translators: %s: group name */ __( 'Edit: %s', 'cec' ), $p['name'] ) ); ?></h1>
			<p><?php esc_html_e( 'Changes go live as soon as you save. The COL&B team is told about each change. To change the name or ask for anything else, contact the team.', 'cec' ); ?> <a href="<?php echo esc_url( $p['url'] ); ?>"><?php esc_html_e( 'Back to the page', 'cec' ); ?></a></p>
			<?php if ( $notes ) : ?>
				<div class="cec-org-notice is-error" role="alert" tabindex="-1" id="cec-org-errors">
					<p><strong><?php esc_html_e( 'Your other changes were saved and are live. These weren\'t changed:', 'cec' ); ?></strong></p>
					<ul><?php foreach ( $notes as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?></ul>
				</div>
			<?php endif; ?>
			<form class="cec-org-form" id="cec-org-edit-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<input type="hidden" name="term" value="<?php echo (int) $p['id']; ?>" />
				<?php wp_nonce_field( 'cec_org_edit_' . $p['id'], '_cec_nonce' ); ?>

				<fieldset>
					<legend><?php esc_html_e( 'Your homepage card', 'cec' ); ?></legend>
					<p class="cec-org-hint"><?php esc_html_e( 'Your card on the homepage shows your photo, name, title line (titleholders), the card text and bullet points below. Changes show there as soon as you save.', 'cec' ); ?></p>
					<p class="cec-org-field"><label for="e_name"><?php esc_html_e( 'Name (required)', 'cec' ); ?></label>
						<input type="text" id="e_name" name="name" maxlength="120" required value="<?php echo esc_attr( $v( 'name', $p['name'] ) ); ?>" /><span class="cec-org-hint"><?php esc_html_e( 'Your page address stays the same if you change it.', 'cec' ); ?></span></p>
					<p class="cec-org-field"><label for="e_summary"><?php esc_html_e( 'Card text (optional)', 'cec' ); ?></label>
						<textarea id="e_summary" name="summary" rows="2" maxlength="200"><?php echo esc_textarea( $v( 'summary', $p['summary'] ) ); ?></textarea><span class="cec-org-hint"><?php esc_html_e( 'One or two sentences, up to 200 characters. Leave empty to show the start of your bio instead.', 'cec' ); ?></span></p>
					<p class="cec-org-field"><label for="e_hl"><?php esc_html_e( 'Bullet points (optional)', 'cec' ); ?></label>
						<textarea id="e_hl" name="highlights" rows="3" maxlength="600"><?php echo esc_textarea( $v( 'highlights', implode( "\n", $p['highlights'] ) ) ); ?></textarea><span class="cec-org-hint"><?php esc_html_e( 'One per line, up to 8, e.g. "Pup and handler education". Shown on the card and your page.', 'cec' ); ?></span></p>
				</fieldset>

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
					<p class="cec-org-field"><label for="e_mission"><?php echo esc_html( $is_title ? __( 'Areas of focus / mission (optional)', 'cec' ) : __( 'Mission (optional)', 'cec' ) ); ?></label>
						<textarea id="e_mission" name="mission" rows="6" maxlength="2000"><?php echo esc_textarea( $v( 'mission', $p['mission'] ) ); ?></textarea></p>
				</fieldset>

				<fieldset>
					<legend><?php esc_html_e( 'Links', 'cec' ); ?></legend>
					<?php foreach ( CEC_Orgs::socials() as $k => $label ) : ?>
						<?php $val = $p['socials'][ $k ] ?? ''; ?>
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
						<input type="file" id="e_photo" name="photo" accept="image/jpeg,image/png,image/webp" /><span class="cec-org-hint"><?php esc_html_e( 'JPEG, PNG or WebP, up to 10 MB. Shown in a circle, so a square image works best.', 'cec' ); ?></span></p>
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
					<div class="cec-org-held"<?php echo $held ? '' : ' hidden'; ?> id="cec-org-held">
						<p><strong><?php esc_html_e( 'New photos', 'cec' ); ?></strong> <span class="cec-org-hint"><?php esc_html_e( 'Uploaded but not on your page yet. Add captions, remove any you don\'t want, then tick the box below and save.', 'cec' ); ?></span></p>
						<ul class="cec-org-edit-gallery" id="cec-org-uploads">
							<?php foreach ( $held as $h ) : ?>
								<?php echo self::held_item_html( $h['id'], $h['caption'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
							<?php endforeach; ?>
						</ul>
					</div>
					<?php if ( count( $gallery ) < self::MAX_GALLERY ) : ?>
						<p class="cec-org-field"><label for="g_bulk"><?php esc_html_e( 'Add photos', 'cec' ); ?></label>
							<input type="file" id="g_bulk" name="photos[]" multiple accept="image/jpeg,image/png,image/webp" data-term="<?php echo (int) $p['id']; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'cec_org_upload_' . $p['id'] ) ); ?>" data-url="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" />
							<span class="cec-org-hint"><?php echo esc_html( sprintf( /* translators: %d: total photos */ __( 'Select as many as you like at once (hold Ctrl or ⌘, or pick several on your phone). Each one uploads on its own and shows up above, where you can add a caption or remove it. JPEG, PNG or WebP, up to 10 MB each; the gallery holds %d.', 'cec' ), self::MAX_GALLERY ) ); ?></span>
							<span class="cec-org-hint" id="g_bulk_status" aria-live="polite"></span></p>
					<?php endif; ?>
					<p class="cec-org-check"><label><input type="checkbox" name="consent" value="1" /> <?php esc_html_e( 'Everyone who can be recognised in the photos I\'m adding agreed to be shown publicly on this site.', 'cec' ); ?></label></p>
					<p class="cec-org-hint"><?php esc_html_e( 'Without this box ticked, new photos stay here (not on your page) until you confirm.', 'cec' ); ?></p>
				</fieldset>

				<p><button type="submit" class="cec-org-submit"><?php esc_html_e( 'Save changes', 'cec' ); ?></button></p>
			</form>
			<script>
			( function () {
				// Photos upload one at a time as soon as they're picked (1.37.1),
				// so any number can be added and each can be removed before saving.
				var form = document.getElementById( 'cec-org-edit-form' );
				if ( ! form ) { return; }
				form.classList.add( 'is-js' );
				var input = document.getElementById( 'g_bulk' ), list = document.getElementById( 'cec-org-uploads' ), box = document.getElementById( 'cec-org-held' ), status = document.getElementById( 'g_bulk_status' );
				var each = <?php echo (int) self::PHOTO_BYTES; ?>, queue = [], busy = 0;
				var T = <?php echo wp_json_encode( array( 'uploading' => __( 'Uploading…', 'cec' ), 'waiting' => __( 'Waiting…', 'cec' ), 'big' => __( 'Larger than 10 MB: not uploaded.', 'cec' ), 'failed' => __( 'Couldn\'t upload:', 'cec' ), 'dismiss' => __( 'Dismiss', 'cec' ), 'left' => __( 'photo(s) still uploading…', 'cec' ), 'done' => __( 'All photos uploaded. Add captions, then tick the box and save.', 'cec' ), 'wait' => __( 'Please wait until the photos finish uploading, then save.', 'cec' ), 'removed' => __( 'Removed.', 'cec' ) ) ); ?>;
				function bindRemove( li ) {
					var b = li.querySelector( '.cec-org-remove' ), c = li.querySelector( 'input[type=checkbox]' );
					if ( ! b ) { return; }
					b.addEventListener( 'click', function () {
						if ( c ) { c.checked = true; }
						li.hidden = true;
						status.textContent = T.removed;
						form.dispatchEvent( new Event( 'change' ) );
					} );
				}
				Array.prototype.forEach.call( list ? list.children : [], bindRemove );
				function pending( file ) {
					var li = document.createElement( 'li' );
					li.className = 'cec-org-upload-pending';
					var img = document.createElement( 'img' );
					img.alt = '';
					try { img.src = URL.createObjectURL( file ); } catch ( e ) {}
					var info = document.createElement( 'div' );
					var name = document.createElement( 'p' );
					name.textContent = file.name;
					var st = document.createElement( 'p' );
					st.className = 'cec-org-hint';
					info.appendChild( name ); info.appendChild( st );
					li.appendChild( img ); li.appendChild( info );
					list.appendChild( li );
					box.hidden = false;
					return { li: li, st: st };
				}
				function failed( row, msg ) {
					row.li.classList.add( 'is-failed' );
					row.st.textContent = msg;
					var d = document.createElement( 'button' );
					d.type = 'button'; d.className = 'cec-org-remove'; d.textContent = T.dismiss;
					d.addEventListener( 'click', function () { row.li.remove(); } );
					row.st.parentNode.appendChild( d );
				}
				function progress() {
					var left = queue.length + busy;
					status.textContent = left ? left + ' ' + T.left : T.done;
				}
				function next() {
					if ( busy || ! queue.length ) { progress(); return; }
					var job = queue.shift();
					busy = 1;
					job.row.st.textContent = T.uploading;
					progress();
					var fd = new FormData();
					fd.append( 'action', 'cec_org_upload' );
					fd.append( 'term', input.dataset.term );
					fd.append( '_cec_up', input.dataset.nonce );
					fd.append( 'photo', job.file );
					fetch( input.dataset.url, { method: 'POST', body: fd, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							if ( res && res.success && res.data && res.data.html ) {
								var tmp = document.createElement( 'ul' );
								tmp.innerHTML = res.data.html;
								var li = tmp.firstElementChild;
								job.row.li.replaceWith( li );
								bindRemove( li );
							} else {
								failed( job.row, T.failed + ' ' + ( res && res.data && res.data.message ? res.data.message : '' ) );
							}
						} )
						.catch( function () { failed( job.row, T.failed + ' ' + job.file.name ); } )
						.then( function () { busy = 0; next(); } );
				}
				if ( input && list ) {
					input.addEventListener( 'change', function () {
						Array.prototype.forEach.call( input.files, function ( f ) {
							var row = pending( f );
							if ( f.size > each ) { failed( row, T.big ); return; }
							row.st.textContent = T.waiting;
							queue.push( { file: f, row: row } );
						} );
						input.value = ''; // Uploaded separately: never sent again with Save.
						next();
					} );
				}
				// Draft (1.37.1): everything typed is kept in this browser as you go
				// and comes back after a refresh, until you save or discard it.
				var key = 'cec_org_draft_<?php echo (int) $p['id']; ?>_<?php echo (int) get_current_user_id(); ?>', dirty = false, saving = false, timer = null;
				var D = <?php echo wp_json_encode( array( 'restored' => __( 'Your unsaved changes from earlier were brought back. They aren\'t live until you save.', 'cec' ), 'discard' => __( 'Discard them', 'cec' ), 'leave' => __( 'You have unsaved changes.', 'cec' ) ) ); ?>;
				function fields() {
					return Array.prototype.filter.call( form.elements, function ( el ) {
						return el.name && el.type !== 'file' && el.type !== 'hidden' && el.type !== 'submit' && el.type !== 'button';
					} );
				}
				function snapshot() {
					var d = {};
					fields().forEach( function ( el ) { d[ el.name ] = ( el.type === 'checkbox' || el.type === 'radio' ) ? el.checked : el.value; } );
					return d;
				}
				function store() {
					try { localStorage.setItem( key, JSON.stringify( { at: Date.now(), v: snapshot() } ) ); } catch ( e ) {}
				}
				var original = JSON.stringify( snapshot() );
				try {
					var saved = JSON.parse( localStorage.getItem( key ) || 'null' );
					if ( saved && saved.v && Date.now() - saved.at < 14 * 864e5 && JSON.stringify( saved.v ) !== original ) {
						fields().forEach( function ( el ) {
							if ( ! Object.prototype.hasOwnProperty.call( saved.v, el.name ) ) { return; }
							if ( el.type === 'checkbox' || el.type === 'radio' ) { el.checked = !! saved.v[ el.name ]; } else { el.value = saved.v[ el.name ]; }
							if ( el.type === 'checkbox' && el.checked && /^held\[\d+\]\[remove\]$/.test( el.name ) ) { var li = el.closest( 'li' ); if ( li ) { li.hidden = true; } }
						} );
						dirty = true;
						var n = document.createElement( 'div' );
						n.className = 'cec-org-notice cec-org-draft'; n.setAttribute( 'role', 'status' );
						var t = document.createElement( 'p' ); t.textContent = D.restored + ' ';
						var x = document.createElement( 'button' ); x.type = 'button'; x.className = 'cec-org-remove'; x.textContent = D.discard;
						x.addEventListener( 'click', function () { try { localStorage.removeItem( key ); } catch ( e ) {} dirty = false; location.reload(); } );
						t.appendChild( x ); n.appendChild( t );
						form.parentNode.insertBefore( n, form );
					} else if ( saved ) {
						localStorage.removeItem( key );
					}
				} catch ( e ) {}
				function changed() {
					dirty = true;
					clearTimeout( timer );
					timer = setTimeout( store, 400 );
				}
				form.addEventListener( 'input', changed );
				form.addEventListener( 'change', changed );
				window.addEventListener( 'beforeunload', function ( ev ) {
					if ( dirty && ! saving ) { store(); ev.preventDefault(); ev.returnValue = D.leave; return D.leave; }
				} );
				form.addEventListener( 'submit', function ( ev ) {
					if ( busy || queue.length ) { ev.preventDefault(); window.alert( T.wait ); return; }
					saving = true;
					try { localStorage.removeItem( key ); } catch ( e ) {}
				} );
			} )();
			</script>
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
			'name'        => $p['name'],
			'summary'     => $p['summary'],
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

	/** Uploaded files of a multiple <input name="photos[]">, one array per file. */
	private static function files( $field ) {
		$out = array();
		$f   = isset( $_FILES[ $field ] ) && is_array( $_FILES[ $field ] ) ? $_FILES[ $field ] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing
		if ( ! $f || ! is_array( $f['name'] ?? null ) ) {
			return $out;
		}
		foreach ( array_keys( $f['name'] ) as $i ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $f['error'][ $i ] ) {
				continue;
			}
			$out[] = array(
				'name'     => (string) $f['name'][ $i ],
				'type'     => (string) $f['type'][ $i ],
				'tmp_name' => (string) $f['tmp_name'][ $i ],
				'error'    => (int) $f['error'][ $i ],
				'size'     => (int) $f['size'][ $i ],
			);
		}
		return $out;
	}

	/** One "new photo" row in the editor (also returned by the upload endpoint). */
	public static function held_item_html( $id, $caption ) {
		$id   = (int) $id;
		$html = '<li>' . wp_get_attachment_image( $id, 'thumbnail', false, array( 'alt' => '' ) ) . '<div>';
		$html .= '<p class="cec-org-field"><label for="h_cap_' . $id . '">' . esc_html__( 'Caption', 'cec' ) . '</label><input type="text" id="h_cap_' . $id . '" name="held[' . $id . '][caption]" maxlength="200" value="' . esc_attr( $caption ) . '" /></p>';
		$html .= '<p class="cec-org-check cec-org-nojs"><label><input type="checkbox" name="held[' . $id . '][remove]" value="1" /> ' . esc_html__( 'Delete this photo', 'cec' ) . '</label></p>';
		$html .= '<button type="button" class="cec-org-remove cec-org-js-only">' . esc_html__( 'Remove', 'cec' ) . '</button>';
		return $html . '</div></li>';
	}

	public static function upload_signed_out() {
		wp_send_json_error( array( 'message' => __( 'Please sign in again, then reload this page.', 'cec' ) ), 401 );
	}

	/**
	 * One photo, uploaded on its own from the editor (1.37.1). It is kept as
	 * a "new photo" (not on the page) until the uploader saves with the
	 * consent box ticked.
	 */
	public static function handle_upload() {
		$term_id = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$nonce   = isset( $_POST['_cec_up'] ) ? sanitize_text_field( wp_unslash( $_POST['_cec_up'] ) ) : '';
		$term    = $term_id ? get_term( $term_id, 'cec_partner_org' ) : null;
		if ( ! $term || is_wp_error( $term ) || ! self::can_edit( $term_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You can\'t edit this page.', 'cec' ) ), 403 );
		}
		if ( ! wp_verify_nonce( $nonce, 'cec_org_upload_' . $term_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This page has expired. Save your changes, then reload it.', 'cec' ) ), 403 );
		}
		if ( count( self::held( $term_id ) ) + count( self::gallery( $term_id ) ) >= self::MAX_GALLERY ) {
			/* translators: %d: maximum photos */
			wp_send_json_error( array( 'message' => sprintf( __( 'The gallery holds up to %d photos. Remove some first.', 'cec' ), self::MAX_GALLERY ) ) );
		}
		$f = self::file( 'photo' );
		if ( ! $f ) {
			wp_send_json_error( array( 'message' => __( 'No photo was received.', 'cec' ) ) );
		}
		$c = CEC_Org_Submissions::check_image( $f, self::PHOTO_BYTES );
		if ( is_wp_error( $c ) ) {
			wp_send_json_error( array( 'message' => $c->get_error_message() ) );
		}
		$att = CEC_Org_Submissions::store_image( $f, 0, $term->name );
		if ( ! $att ) {
			wp_send_json_error( array( 'message' => __( 'It couldn\'t be saved. Please try again.', 'cec' ) ) );
		}
		update_post_meta( $att, '_cec_org_held_term', $term_id );
		update_post_meta( $att, '_cec_org_held_by', get_current_user_id() );
		update_post_meta( $att, '_cec_org_held_caption', '' );
		wp_send_json_success( array( 'id' => $att, 'html' => self::held_item_html( $att, '' ) ) );
	}

	/**
	 * Photos this user uploaded for this group without confirming consent
	 * (1.37.0): kept out of the gallery until they tick the box.
	 */
	public static function held( $term_id, $user_id = 0 ) {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => '_cec_org_held_term', 'value' => (int) $term_id ),
					array( 'key' => '_cec_org_held_by', 'value' => (int) ( $user_id ? $user_id : get_current_user_id() ) ),
				),
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$out[] = array( 'id' => (int) $id, 'caption' => (string) get_post_meta( $id, '_cec_org_held_caption', true ) );
		}
		return $out;
	}

	/**
	 * Saves what is valid and keeps the current value of anything that
	 * isn't, then lists what wasn't changed (1.37.0). Nothing typed or
	 * uploaded is thrown away because of one bad field.
	 */
	public static function handle() {
		$term_id = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$term    = $term_id ? get_term( $term_id, 'cec_partner_org' ) : null;
		if ( ! $term || is_wp_error( $term ) || ! self::can_edit( $term_id ) ) {
			wp_die( esc_html__( 'You can\'t edit this page.', 'cec' ), 403 );
		}
		check_admin_referer( 'cec_org_edit_' . $term_id, '_cec_nonce' );
		$post  = wp_unslash( $_POST );
		$p     = CEC_Orgs::profile( $term );
		$notes = array();
		$s     = function ( $k, $max, $multi = false ) use ( $post ) {
			$raw = isset( $post[ $k ] ) && is_scalar( $post[ $k ] ) ? (string) $post[ $k ] : '';
			return mb_substr( trim( $multi ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw ) ), 0, $max );
		};
		$is_title = 'titleholder' === $p['kind'];
		$new      = array(
			'name'        => $s( 'name', 120 ),
			'summary'     => $s( 'summary', 200 ),
			'description' => $s( 'description', 600, true ),
			'mission'     => $s( 'mission', 2000, true ),
			'highlights'  => array_slice( array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $s( 'highlights', 600, true ) ) ) ) ), 0, 8 ),
			'title'       => $is_title ? $s( 'title', 120 ) : $p['title'],
			'year'        => $is_title ? $s( 'year', 4 ) : $p['year'],
			'producer'    => $is_title ? $s( 'producer', 120 ) : $p['producer'],
			'socials'     => array(),
		);
		if ( '' === $new['name'] ) {
			/* translators: %s: current name */
			$notes[]     = sprintf( __( 'Name: it can\'t be empty, so it stays "%s".', 'cec' ), $p['name'] );
			$new['name'] = $p['name'];
		} else {
			$same = get_term_by( 'name', $new['name'], 'cec_partner_org' );
			if ( $same && (int) $same->term_id !== (int) $term_id ) {
				/* translators: 1: wanted name, 2: current name */
				$notes[]     = sprintf( __( 'Name: "%1$s" is already the name of another group on the site, so it stays "%2$s".', 'cec' ), $new['name'], $p['name'] );
				$new['name'] = $p['name'];
			}
		}
		if ( '' === $new['description'] ) {
			$notes[]            = $is_title ? __( 'Bio: it can\'t be empty, so the previous bio was kept.', 'cec' ) : __( 'Description: it can\'t be empty, so the previous description was kept.', 'cec' );
			$new['description'] = $p['description'];
		}
		if ( '' !== $new['year'] && ! preg_match( '/^(19|20|21)\d{2}$/', $new['year'] ) ) {
			/* translators: %s: what was typed */
			$notes[]     = sprintf( __( 'Year: "%s" isn\'t a four-digit year, so the previous year was kept.', 'cec' ), $new['year'] );
			$new['year'] = $p['year'];
		}
		$social = isset( $post['social'] ) && is_array( $post['social'] ) ? $post['social'] : array();
		$labels = CEC_Orgs::socials();
		foreach ( array_keys( $labels ) as $k ) {
			$raw   = isset( $social[ $k ] ) && is_scalar( $social[ $k ] ) ? (string) $social[ $k ] : '';
			$clean = CEC_Orgs::clean_social( $k, $raw );
			if ( is_wp_error( $clean ) ) {
				/* translators: 1: link name, 2: what was typed, 3: problem */
				$notes[] = sprintf( __( '%1$s link "%2$s": %3$s The previous link was kept.', 'cec' ), $labels[ $k ], sanitize_text_field( $raw ), $clean->get_error_message() );
				if ( ! empty( $p['socials'][ $k ] ) ) {
					$new['socials'][ $k ] = $p['socials'][ $k ];
				}
			} elseif ( '' !== $clean ) {
				$new['socials'][ $k ] = $clean;
			}
		}

		// Existing gallery: captions, order, removals.
		$current = self::gallery( $term_id );
		$posted  = isset( $post['gallery'] ) && is_array( $post['gallery'] ) ? $post['gallery'] : array();
		$keep    = array();
		$removed = 0;
		foreach ( $current as $i => $item ) {
			$row = isset( $posted[ $item['id'] ] ) && is_array( $posted[ $item['id'] ] ) ? $posted[ $item['id'] ] : array();
			if ( ! empty( $row['remove'] ) ) {
				++$removed;
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
		$gallery = array();
		foreach ( $keep as $k ) {
			$gallery[] = array( 'id' => $k['id'], 'caption' => $k['caption'] );
		}

		// Photos waiting for consent: caption edits and deletions.
		$held_post = isset( $post['held'] ) && is_array( $post['held'] ) ? $post['held'] : array();
		$held      = array();
		foreach ( self::held( $term_id ) as $h ) {
			$row = isset( $held_post[ $h['id'] ] ) && is_array( $held_post[ $h['id'] ] ) ? $held_post[ $h['id'] ] : array();
			if ( ! empty( $row['remove'] ) ) {
				wp_delete_attachment( $h['id'], true );
				continue;
			}
			if ( isset( $row['caption'] ) && is_scalar( $row['caption'] ) ) {
				$h['caption'] = mb_substr( trim( sanitize_text_field( (string) $row['caption'] ) ), 0, 200 );
				update_post_meta( $h['id'], '_cec_org_held_caption', $h['caption'] );
			}
			$held[] = $h;
		}

		// New photos: store every good one; a bad one is skipped and named.
		$uploads = self::files( 'photos' );
		if ( count( $uploads ) > self::BULK_MAX ) {
			/* translators: 1: photos per save, 2: skipped */
			$notes[] = sprintf( __( 'Photos: up to %1$d can be added per save, so the last %2$d weren\'t uploaded. Please add them again.', 'cec' ), self::BULK_MAX, count( $uploads ) - self::BULK_MAX );
			$uploads = array_slice( $uploads, 0, self::BULK_MAX );
		}
		$stored = array();
		foreach ( $uploads as $f ) {
			$c = CEC_Org_Submissions::check_image( $f, self::PHOTO_BYTES );
			if ( is_wp_error( $c ) ) {
				/* translators: 1: file name, 2: problem */
				$notes[] = sprintf( __( 'Photo "%1$s" wasn\'t added: %2$s', 'cec' ), sanitize_file_name( $f['name'] ), $c->get_error_message() );
				continue;
			}
			$att = CEC_Org_Submissions::store_image( $f, 0, $p['name'] );
			if ( $att ) {
				$stored[] = $att;
			} else {
				/* translators: %s: file name */
				$notes[] = sprintf( __( 'Photo "%s" couldn\'t be saved. Please try it again.', 'cec' ), sanitize_file_name( $f['name'] ) );
			}
		}
		$consent = ! empty( $post['consent'] );
		$waiting = array_merge( $held, array_map( function ( $id ) { return array( 'id' => $id, 'caption' => '' ); }, $stored ) );
		$added   = 0;
		if ( $consent ) {
			foreach ( $waiting as $w ) {
				if ( count( $gallery ) >= self::MAX_GALLERY ) {
					break;
				}
				delete_post_meta( $w['id'], '_cec_org_held_term' );
				delete_post_meta( $w['id'], '_cec_org_held_by' );
				delete_post_meta( $w['id'], '_cec_org_held_caption' );
				update_post_meta( $w['id'], '_cec_org_gallery_term', $term_id );
				$gallery[] = array( 'id' => $w['id'], 'caption' => $w['caption'] );
				$waiting   = array_slice( $waiting, 1 );
				++$added;
			}
			if ( $waiting ) {
				/* translators: 1: maximum, 2: number waiting */
				$notes[] = sprintf( __( 'Gallery: it holds up to %1$d photos, so %2$d are kept below, not on your page. Remove some gallery photos to make room.', 'cec' ), self::MAX_GALLERY, count( $waiting ) );
			}
		} elseif ( $waiting ) {
			/* translators: %d: number of photos */
			$notes[] = sprintf( _n( '%d new photo is uploaded but not on your page yet: tick "Everyone who can be recognised… agreed" and save to add it.', '%d new photos are uploaded but not on your page yet: tick "Everyone who can be recognised… agreed" and save to add them.', count( $waiting ), 'cec' ), count( $waiting ) );
		}
		foreach ( $waiting as $w ) {
			if ( in_array( $w['id'], $stored, true ) ) {
				update_post_meta( $w['id'], '_cec_org_held_term', $term_id );
				update_post_meta( $w['id'], '_cec_org_held_by', get_current_user_id() );
				update_post_meta( $w['id'], '_cec_org_held_caption', '' );
			}
		}

		// Page photo / logo.
		$photo = self::file( 'photo' );
		if ( $photo ) {
			$c = CEC_Org_Submissions::check_image( $photo, self::PHOTO_BYTES );
			$att = is_wp_error( $c ) ? 0 : CEC_Org_Submissions::store_image( $photo, 0, $p['name'] );
			if ( $att ) {
				$new['logo_id'] = $att;
				$new['logo']    = (string) wp_get_attachment_url( $att );
				update_post_meta( $att, '_cec_org_photo_term', $term_id );
			} else {
				/* translators: %s: problem */
				$notes[] = sprintf( __( 'Photo / logo wasn\'t changed: %s', 'cec' ), is_wp_error( $c ) ? $c->get_error_message() : __( 'it couldn\'t be saved. Please try again.', 'cec' ) );
			}
		} elseif ( ! empty( $post['remove_photo'] ) ) {
			$new['logo_id'] = 0;
			$new['logo']    = '';
		}

		$before = self::snapshot( $term_id );
		self::remember( $term_id, $before );
		if ( $new['name'] !== $term->name ) {
			wp_update_term( $term_id, 'cec_partner_org', array( 'name' => $new['name'] ) );
		}
		unset( $new['name'] );
		CEC_Orgs::save_profile( $term_id, $new );
		update_term_meta( $term_id, 'cec_gallery', $gallery );
		CEC_Orgs::refresh_pages();
		// Removed gallery photos stay in the history snapshot until it rolls
		// over, so Restore can bring them back; deleted then (see prune()).
		self::prune( $term_id );

		$after = self::snapshot( $term_id );
		if ( $after === $before ) {
			// Nothing changed: don't keep an identical version.
			$h = get_term_meta( $term_id, 'cec_profile_history', true );
			update_term_meta( $term_id, 'cec_profile_history', array_slice( (array) $h, 1 ) );
		} else {
			self::notify( get_term( $term_id, 'cec_partner_org' ), $before, $after, $added, $removed );
		}
		if ( $notes ) {
			set_transient( self::err_key( $term_id ), $notes, HOUR_IN_SECONDS );
			wp_safe_redirect( self::edit_url( $term_id ) . '#cec-org-errors' );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'cec_org_msg', 'saved', get_term_link( $term_id, 'cec_partner_org' ) ) );
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
			'name'        => __( 'Name', 'cec' ),
			'summary'     => __( 'Card text', 'cec' ),
			'description' => __( 'Description / bio', 'cec' ),
			'highlights'  => __( 'Bullet points', 'cec' ),
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
		if ( ! empty( $data['name'] ) && get_term( $term_id )->name !== $data['name'] && ! get_term_by( 'name', $data['name'], 'cec_partner_org' ) ) {
			wp_update_term( $term_id, 'cec_partner_org', array( 'name' => $data['name'] ) );
		}
		$fields = array_intersect_key( $data, array_flip( array( 'summary', 'description', 'highlights', 'mission', 'title', 'year', 'producer', 'socials', 'logo', 'logo_id' ) ) );
		CEC_Orgs::save_profile( $term_id, $fields );
		update_term_meta( $term_id, 'cec_gallery', $gallery );
		self::prune( $term_id );
		CEC_Orgs::refresh_pages();
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
		$held = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_cec_org_held_term', 'meta_value' => (int) $term_id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $held as $id ) {
			wp_delete_attachment( $id, true );
		}
	}
}
