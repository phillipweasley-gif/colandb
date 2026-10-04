<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Profile and cover photos (brief §2). Kept in the database, never in the
 * media library, and served only through serve(), which applies the same
 * visibility rule as the profile fields (CMP_Profiles::can_view()).
 *
 * Every upload is decoded and re-encoded as a JPEG at a fixed size: that
 * proves it really is an image (not just a file named like one) and drops
 * all camera metadata, including GPS. The browser crops it first (square
 * for the profile photo, 3:1 for the cover); without JavaScript the server
 * crops from the centre.
 */
class CMP_Profile_Images {

	const MAX_BYTES = 5242880; // 5 MB, per image.
	const NONCE     = 'cmp_profile_image';
	const QUERY     = 'cmp_photo';

	/** kind => array( width, height, minimum width ) */
	const SIZES = array(
		'avatar' => array( 512, 512, 200 ),
		'cover'  => array( 1500, 500, 600 ),
	);

	/** Accepted upload formats. HEIC needs either the browser (Safari) or the server to read it. */
	const INPUT_MIMES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' );

	public static function init() {
		add_action( 'admin_post_cmp_profile_image', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_cmp_profile_image', array( 'CMP_Member_Area', 'redirect_to_login' ) );
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 20 );
	}

	/* ------------------------------------------------------------------
	 * Storage
	 * ---------------------------------------------------------------- */

	/**
	 * @param bool $with_data False skips the image bytes.
	 * @return object|null
	 */
	public static function get( $user_id, $kind, $with_data = false ) {
		global $wpdb;
		if ( ! isset( self::SIZES[ $kind ] ) ) {
			return null;
		}
		$cols = 'user_id, kind, mime, width, height, bytes, sha256, alt, decorative, created_at' . ( $with_data ? ', data' : '' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT $cols FROM " . CMP_Install::table( 'profile_images' ) . ' WHERE user_id = %d AND kind = %s', $user_id, $kind ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function delete( $user_id, $kind ) {
		global $wpdb;
		return (bool) $wpdb->delete( CMP_Install::table( 'profile_images' ), array( 'user_id' => $user_id, 'kind' => $kind ), array( '%d', '%s' ) );
	}

	public static function delete_all( $user_id ) {
		global $wpdb;
		return (int) $wpdb->delete( CMP_Install::table( 'profile_images' ), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/**
	 * Checks, crops/resizes and re-encodes an uploaded file.
	 *
	 * @return array|WP_Error array( data, width, height )
	 */
	public static function process( $path, $kind ) {
		if ( ! isset( self::SIZES[ $kind ] ) ) {
			return new WP_Error( 'cmp_kind', __( 'Unknown photo type.', 'cmp' ) );
		}
		list( $tw, $th, $min_w ) = self::SIZES[ $kind ];
		$size = is_readable( $path ) ? (int) filesize( $path ) : 0;
		if ( $size <= 0 ) {
			return new WP_Error( 'cmp_photo_missing', __( 'No photo arrived. Please choose it again.', 'cmp' ) );
		}
		if ( $size > self::MAX_BYTES ) {
			return new WP_Error( 'cmp_photo_big', __( 'That photo is larger than 5 MB. Please choose a smaller one.', 'cmp' ) );
		}
		// The file's own content decides its type, never its name.
		$mime = function_exists( 'finfo_open' ) ? (string) finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $path ) : '';
		if ( ! $mime ) {
			$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$mime = $info ? (string) $info['mime'] : '';
		}
		if ( ! in_array( $mime, self::INPUT_MIMES, true ) ) {
			return new WP_Error( 'cmp_photo_type', __( 'That file isn\'t a JPEG, PNG, WebP or HEIC photo.', 'cmp' ) );
		}
		if ( in_array( $mime, array( 'image/heic', 'image/heif' ), true ) && ! wp_image_editor_supports( array( 'mime_type' => $mime ) ) ) {
			return new WP_Error( 'cmp_photo_heic', __( 'This site can\'t convert HEIC photos itself. Open the page in Safari (which converts it for you), or save the photo as a JPEG first.', 'cmp' ) );
		}

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return new WP_Error( 'cmp_photo_read', __( 'That photo couldn\'t be opened. It may be damaged; please try another.', 'cmp' ) );
		}
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate();
		}
		$src = $editor->get_size();
		$sw  = (int) $src['width'];
		$sh  = (int) $src['height'];
		if ( $sw < $min_w || $sh < (int) round( $min_w * $th / $tw ) ) {
			return new WP_Error(
				'cmp_photo_small',
				sprintf(
					/* translators: 1: minimum width, 2: minimum height */
					__( 'That photo is too small. Please use one at least %1$d × %2$d pixels.', 'cmp' ),
					$min_w,
					(int) round( $min_w * $th / $tw )
				)
			);
		}
		// Largest centred area with the right shape (a browser-cropped
		// upload already has it), scaled to the stored size.
		$ratio = $tw / $th;
		if ( $sw / $sh > $ratio ) {
			$cw = (int) round( $sh * $ratio );
			$ch = $sh;
		} else {
			$cw = $sw;
			$ch = (int) round( $sw / $ratio );
		}
		// Exactly the stored shape (e.g. 3 × height = width for a cover).
		$dh = (int) floor( min( $tw, $cw ) / $ratio );
		$dw = (int) round( $dh * $ratio );
		$crop = $editor->crop( (int) floor( ( $sw - $cw ) / 2 ), (int) floor( ( $sh - $ch ) / 2 ), $cw, $ch, $dw, $dh );
		if ( is_wp_error( $crop ) ) {
			return new WP_Error( 'cmp_photo_read', __( 'That photo couldn\'t be opened. It may be damaged; please try another.', 'cmp' ) );
		}
		$editor->set_quality( 85 );
		$out   = wp_tempnam( 'cmp-photo.jpg' );
		$saved = $editor->save( $out . '.jpg', 'image/jpeg' );
		@unlink( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return new WP_Error( 'cmp_photo_save', __( 'Something went wrong saving your photo. Please try again.', 'cmp' ) );
		}
		$data = (string) file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		@unlink( $saved['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( "\xFF\xD8\xFF" !== substr( $data, 0, 3 ) ) {
			return new WP_Error( 'cmp_photo_save', __( 'Something went wrong saving your photo. Please try again.', 'cmp' ) );
		}
		return array( 'data' => $data, 'width' => (int) $saved['width'], 'height' => (int) $saved['height'] );
	}

	private static function store( $user_id, $kind, $img, $alt, $decorative ) {
		global $wpdb;
		return false !== $wpdb->replace(
			CMP_Install::table( 'profile_images' ),
			array(
				'user_id'    => $user_id,
				'kind'       => $kind,
				'mime'       => 'image/jpeg',
				'width'      => $img['width'],
				'height'     => $img['height'],
				'bytes'      => strlen( $img['data'] ),
				'sha256'     => hash( 'sha256', $img['data'] ),
				'data'       => $img['data'],
				'alt'        => $alt,
				'decorative' => $decorative ? 1 : 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/* ------------------------------------------------------------------
	 * Upload / replace / remove / describe (one form per photo)
	 * ---------------------------------------------------------------- */

	private static function respond( $ok, $notice, $message = '', $kind = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only decides the response format.
		if ( ! empty( $_POST['cmp_ajax'] ) ) {
			nocache_headers();
			wp_send_json( array( 'ok' => $ok, 'message' => $message, 'redirect' => CMP_Profiles::url( $notice, 'cmp-photo-' . $kind ) ), $ok ? 200 : 400 );
		}
		if ( ! $ok && $message ) {
			set_transient( 'cmp_photo_error_' . get_current_user_id(), array( 'kind' => $kind, 'message' => $message ), 10 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( CMP_Profiles::url( $notice, 'cmp-photo-' . $kind ) );
		exit;
	}

	public static function handle() {
		$user_id = get_current_user_id();
		$kind    = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked below.
		$kind    = isset( self::SIZES[ $kind ] ) ? $kind : 'avatar';
		if ( ! CMP_Access::is_member( $user_id ) ) {
			self::respond( false, 'expired', __( 'Please sign in again.', 'cmp' ), $kind );
		}
		if ( ! isset( $_POST['_cmp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cmp_nonce'] ) ), self::NONCE ) ) {
			self::respond( false, 'expired', __( 'Your session expired. Please reload the page and try again.', 'cmp' ), $kind );
		}
		$current = self::get( $user_id, $kind );
		$label   = CMP_Profile_Fields::field( $kind )['label'];

		if ( ! empty( $_POST['remove'] ) ) {
			if ( $current ) {
				self::delete( $user_id, $kind );
				CMP_Audit::log( 'profile_photo_removed', 'user', $user_id, array( 'kind' => $kind, 'sha256' => $current->sha256 ), null, 'Removed by the member' );
			}
			self::respond( true, 'photo_removed', '', $kind );
		}

		$decorative = ! empty( $_POST['decorative'] );
		$alt        = $decorative ? '' : trim( sanitize_text_field( wp_unslash( isset( $_POST['alt'] ) ? $_POST['alt'] : '' ) ) );
		$visibility = isset( $_POST['visibility'] ) ? sanitize_key( wp_unslash( $_POST['visibility'] ) ) : 'private';
		$visibility = in_array( $visibility, CMP_Profile_Fields::VISIBILITY, true ) ? $visibility : 'private';
		$file       = isset( $_FILES['photo'] ) && is_array( $_FILES['photo'] ) ? $_FILES['photo'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$uploaded   = $file && isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'];

		if ( mb_strlen( $alt ) > 150 ) {
			self::respond( false, 'photo_error', __( 'The description can be at most 150 characters.', 'cmp' ), $kind );
		}
		if ( ( $uploaded || $current ) && ! $decorative && '' === $alt ) {
			self::respond( false, 'photo_error', __( 'Describe the photo for people who can\'t see it, or tick "Decorative image".', 'cmp' ), $kind );
		}
		if ( ! $uploaded && ! $current ) {
			// Nothing to describe yet; just remember the visibility choice.
			CMP_Profiles::save_field( $user_id, $kind, null, $visibility );
			self::respond( false, 'photo_error', __( 'Choose a photo to upload.', 'cmp' ), $kind );
		}

		$old_vis = CMP_Profiles::rows( $user_id )[ $kind ]['visibility'];
		if ( $uploaded ) {
			if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
				$big = in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
				self::respond( false, 'photo_error', $big ? __( 'That photo is larger than 5 MB. Please choose a smaller one.', 'cmp' ) : __( 'The upload didn\'t finish. Please try again.', 'cmp' ), $kind );
			}
			$img = self::process( $file['tmp_name'], $kind );
			if ( is_wp_error( $img ) ) {
				self::respond( false, 'photo_error', $img->get_error_message(), $kind );
			}
			if ( ! self::store( $user_id, $kind, $img, $alt, $decorative ) ) {
				self::respond( false, 'photo_error', __( 'Something went wrong saving your photo. Please try again.', 'cmp' ), $kind );
			}
			CMP_Audit::log( $current ? 'profile_photo_replaced' : 'profile_photo_added', 'user', $user_id, $current ? array( 'kind' => $kind, 'sha256' => $current->sha256 ) : null, array( 'kind' => $kind, 'sha256' => hash( 'sha256', $img['data'] ), 'bytes' => strlen( $img['data'] ) ) );
		} else {
			global $wpdb;
			$wpdb->update( CMP_Install::table( 'profile_images' ), array( 'alt' => $alt, 'decorative' => $decorative ? 1 : 0 ), array( 'user_id' => $user_id, 'kind' => $kind ), array( '%s', '%d' ), array( '%d', '%s' ) );
		}
		CMP_Profiles::save_field( $user_id, $kind, null, $visibility );
		if ( $visibility !== $old_vis ) {
			CMP_Audit::log( 'profile_visibility_changed', 'user', $user_id, array( $kind => $old_vis ), array( $kind => $visibility ) );
		}
		unset( $label );
		self::respond( true, $uploaded ? 'photo_saved' : 'photo_updated', '', $kind );
	}

	/* ------------------------------------------------------------------
	 * Serving
	 * ---------------------------------------------------------------- */

	public static function url( $user_id, $kind, $img = null ) {
		$img = $img ? $img : self::get( $user_id, $kind );
		return add_query_arg(
			array(
				self::QUERY => (int) $user_id . '-' . $kind,
				'v'         => $img ? substr( $img->sha256, 0, 12 ) : '0',
			),
			home_url( '/' )
		);
	}

	public static function img_html( $user_id, $kind, $img ) {
		list( $w, $h ) = self::SIZES[ $kind ];
		return '<img src="' . esc_url( self::url( $user_id, $kind, $img ) ) . '" width="' . (int) $w . '" height="' . (int) $h . '" alt="' . esc_attr( $img->decorative ? '' : $img->alt ) . '" loading="lazy" decoding="async" />';
	}

	/**
	 * ?cmp_photo=<user ID>-<avatar|cover>. Anything the viewer may not see
	 * gets the same 404 as a photo that doesn't exist. Browsers must
	 * re-check with the server every time (no-cache + ETag), so hiding a
	 * photo takes effect immediately.
	 */
	public static function maybe_serve() {
		if ( ! isset( $_GET[ self::QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$ref = sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Vary: Cookie' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$img = null;
		if ( preg_match( '/^(\d+)-(avatar|cover)$/', $ref, $m ) ) {
			$owner = (int) $m[1];
			if ( CMP_Profiles::can_view( $m[2], $owner, get_current_user_id() ) ) {
				$img = self::get( $owner, $m[2], true );
			}
		}
		if ( ! $img ) {
			nocache_headers();
			header( 'Cache-Control: private, no-store, max-age=0' );
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Not found';
			exit;
		}
		$etag = '"' . $img->sha256 . '"';
		header( 'Cache-Control: private, no-cache, max-age=0' );
		header( 'ETag: ' . $etag );
		if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) === $etag ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			status_header( 304 );
			exit;
		}
		status_header( 200 );
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . strlen( $img->data ) );
		header( 'Content-Disposition: inline; filename="' . ( 'avatar' === $img->kind ? 'profile' : 'cover' ) . '.jpg"' );
		header( "Content-Security-Policy: default-src 'none'" );
		echo $img->data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JPEG bytes.
		exit;
	}

	/* ------------------------------------------------------------------
	 * Profile tab panels
	 * ---------------------------------------------------------------- */

	public static function notices() {
		return array(
			'photo_saved'   => array( 'success', __( 'Photo saved.', 'cmp' ) ),
			'photo_updated' => array( 'success', __( 'Photo settings saved.', 'cmp' ) ),
			'photo_removed' => array( 'success', __( 'Photo removed.', 'cmp' ) ),
			'photo_error'   => array( 'error', __( 'Your photo wasn\'t saved. See the message next to it.', 'cmp' ) ),
		);
	}

	public static function render_panels( $user_id, $rows ) {
		$error = get_transient( 'cmp_photo_error_' . $user_id );
		if ( $error ) {
			delete_transient( 'cmp_photo_error_' . $user_id );
		}
		$html = '<div class="cmp-photo-panels">';
		foreach ( self::SIZES as $kind => $size ) {
			$html .= self::panel( $user_id, $kind, $size, $rows[ $kind ]['visibility'], $error && $error['kind'] === $kind ? $error['message'] : '' );
		}
		return $html . '</div>';
	}

	private static function panel( $user_id, $kind, $size, $visibility, $error ) {
		$img   = self::get( $user_id, $kind );
		$label = CMP_Profile_Fields::field( $kind )['label'];
		$id    = 'cmp-photo-' . $kind;
		$shape = 'avatar' === $kind ? __( 'square', 'cmp' ) : __( 'wide (3 : 1)', 'cmp' );
		ob_start();
		?>
		<section class="cmp-panel cmp-photo cmp-photo-<?php echo esc_attr( $kind ); ?>" id="<?php echo esc_attr( $id ); ?>" data-cmp-photo="<?php echo esc_attr( $kind ); ?>" data-width="<?php echo (int) $size[0]; ?>" data-height="<?php echo (int) $size[1]; ?>" data-min="<?php echo (int) $size[2]; ?>">
			<h3 class="cmp-panel-title" id="<?php echo esc_attr( $id ); ?>-title"><?php echo esc_html( $label ); ?></h3>
			<div class="cmp-photo-current">
				<?php if ( $img ) : ?>
					<?php echo self::img_html( $user_id, $kind, $img ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php else : ?>
					<span class="cmp-photo-empty"><?php esc_html_e( 'No photo yet', 'cmp' ); ?></span>
				<?php endif; ?>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="cmp-form cmp-photo-form" aria-labelledby="<?php echo esc_attr( $id ); ?>-title" novalidate>
				<input type="hidden" name="action" value="cmp_profile_image" />
				<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>" />
				<input type="hidden" name="_cmp_nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>" />
				<div class="cmp-field-error" role="alert" data-cmp-photo-error<?php echo $error ? '' : ' hidden'; ?>><?php echo esc_html( $error ); ?></div>
				<p class="cmp-field">
					<label for="<?php echo esc_attr( $id ); ?>-file"><?php echo esc_html( $img ? __( 'Replace with a new photo', 'cmp' ) : __( 'Choose a photo', 'cmp' ) ); ?></label>
					<input type="file" id="<?php echo esc_attr( $id ); ?>-file" name="photo" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" aria-describedby="<?php echo esc_attr( $id ); ?>-rules" />
					<span class="cmp-muted" id="<?php echo esc_attr( $id ); ?>-rules">
						<?php
						printf(
							/* translators: 1: shape, 2: minimum width, 3: minimum height */
							esc_html__( 'JPEG, PNG, WebP or HEIC, up to 5 MB, at least %2$d × %3$d pixels. It is cropped %1$s; you can choose the area.', 'cmp' ),
							esc_html( $shape ),
							(int) $size[2],
							(int) round( $size[2] * $size[1] / $size[0] )
						);
						?>
					</span>
				</p>
				<div class="cmp-cropper" data-cmp-cropper hidden>
					<p class="cmp-muted" id="<?php echo esc_attr( $id ); ?>-crop-help"><?php esc_html_e( 'Drag the photo (or use the arrow keys) to choose what shows, and use the slider to zoom.', 'cmp' ); ?></p>
					<div class="cmp-crop-frame" tabindex="0" role="img" aria-roledescription="<?php esc_attr_e( 'crop area', 'cmp' ); ?>" aria-label="<?php esc_attr_e( 'Preview of the cropped photo', 'cmp' ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>-crop-help" style="aspect-ratio: <?php echo (int) $size[0]; ?> / <?php echo (int) $size[1]; ?>"><img alt="" data-cmp-crop-img /></div>
					<p class="cmp-field cmp-zoom"><label for="<?php echo esc_attr( $id ); ?>-zoom"><?php esc_html_e( 'Zoom', 'cmp' ); ?></label><input type="range" id="<?php echo esc_attr( $id ); ?>-zoom" min="1" max="4" step="0.01" value="1" data-cmp-zoom /></p>
				</div>
				<div class="cmp-progress" data-cmp-progress hidden><progress max="100" value="0"></progress> <span data-cmp-progress-text aria-live="polite"></span></div>
				<p class="cmp-check cmp-check-small">
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>-decorative" name="decorative" value="1" data-cmp-decorative <?php checked( $img && $img->decorative ); ?> />
					<label for="<?php echo esc_attr( $id ); ?>-decorative"><?php esc_html_e( 'Decorative image (nothing to describe)', 'cmp' ); ?></label>
				</p>
				<p class="cmp-field" data-cmp-alt-row>
					<label for="<?php echo esc_attr( $id ); ?>-alt"><?php esc_html_e( 'Describe the photo', 'cmp' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $id ); ?>-alt" name="alt" maxlength="150" value="<?php echo esc_attr( $img ? $img->alt : '' ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>-alt-help" />
					<span class="cmp-muted" id="<?php echo esc_attr( $id ); ?>-alt-help"><?php esc_html_e( 'Read out by screen readers. Up to 150 characters, for example "Me at the spring market".', 'cmp' ); ?></span>
				</p>
				<p class="cmp-field">
					<label for="<?php echo esc_attr( $id ); ?>-visibility"><?php esc_html_e( 'Who can see this', 'cmp' ); ?></label>
					<select id="<?php echo esc_attr( $id ); ?>-visibility" name="visibility">
						<?php foreach ( CMP_Profiles::visibility_labels() as $value => $text ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $visibility, $value ); ?>><?php echo esc_html( $text ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<div class="cmp-actions">
					<button type="submit" class="cmp-btn" data-cmp-photo-save><?php echo esc_html( $img ? __( 'Save', 'cmp' ) : __( 'Upload', 'cmp' ) ); ?></button>
					<?php if ( $img ) : ?>
						<button type="submit" class="cmp-btn cmp-btn-outline cmp-btn-danger" name="remove" value="1" formnovalidate data-cmp-confirm="<?php esc_attr_e( 'Remove this photo?', 'cmp' ); ?>"><?php esc_html_e( 'Remove', 'cmp' ); ?></button>
					<?php endif; ?>
				</div>
			</form>
		</section>
		<?php
		return ob_get_clean();
	}
}
