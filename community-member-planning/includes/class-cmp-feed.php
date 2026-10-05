<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Member feed (0.9.0): text and photo posts, optionally tagging a calendar
 * event (Community Events Calendar's cec_event), inside the member area.
 *
 * - Members only, never public. Each post is "All members" or "My
 *   connections" (members in an active dynamic with the author).
 * - Up to 4 photos, re-encoded like homework proof (no location data),
 *   kept in the database and served through maybe_serve_photo() to whoever
 *   may see the post.
 * - Likes; no comments yet. Any member can report a post (the site admin
 *   is emailed); administrators can hide any post; authors delete their own.
 */
class CMP_Feed {

	const TAB         = 'feed';
	const NONCE       = 'cmp_feed';
	const PHOTO_QUERY = 'cmp_postpic';
	const MAX_PHOTOS  = 4;
	const MAX_BODY    = 2000;
	const PER_PAGE    = 20;

	public static function init() {
		add_action( 'admin_post_cmp_feed', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_cmp_feed', array( 'CMP_Member_Area', 'redirect_to_login' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_photo' ), 1 );
	}

	public static function visibilities() {
		return array(
			'members'     => __( 'All members', 'cmp' ),
			'connections' => __( 'My connections', 'cmp' ),
		);
	}

	public static function url( $args = array(), $notice = '' ) {
		$url = add_query_arg( array_merge( array( 'cmp_tab' => self::TAB ), $args ), CMP_Settings::member_page_url() );
		return $notice ? add_query_arg( 'cmp_notice', $notice, $url ) : $url;
	}

	public static function notices() {
		return array(
			'fd_posted'   => array( 'success', __( 'Posted.', 'cmp' ) ),
			'fd_deleted'  => array( 'success', __( 'Post deleted.', 'cmp' ) ),
			'fd_reported' => array( 'success', __( 'Thank you. The site team has been told and will take a look.', 'cmp' ) ),
			'fd_hidden'   => array( 'success', __( 'Post hidden from the feed.', 'cmp' ) ),
			'fd_empty'    => array( 'error', __( 'Write something or add a photo first.', 'cmp' ) ),
			'fd_invalid'  => array( 'error', __( 'That couldn\'t be posted. Check your answers and try again.', 'cmp' ) ),
			'fd_photo'    => array( 'error', __( 'A photo couldn\'t be used. Use JPEG, PNG or WebP photos under 5 MB, at most 4.', 'cmp' ) ),
			'fd_gone'     => array( 'error', __( 'That post isn\'t available.', 'cmp' ) ),
		);
	}

	/* ------------------------------------------------------------------
	 * Data and access
	 * ---------------------------------------------------------------- */

	private static function t( $name ) {
		return CMP_Install::table( $name );
	}

	public static function post( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'posts' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Members in an active dynamic with this member: the "My connections" audience. */
	public static function connected_ids( $user_id ) {
		$ids = array();
		foreach ( CMP_Dynamics::for_user( $user_id, array( 'active' ) ) as $dyn ) {
			$ids[] = (int) CMP_Dynamics::other( $dyn, $user_id );
		}
		return array_values( array_unique( $ids ) );
	}

	public static function can_see( $post, $viewer_id ) {
		if ( ! $post || ! $viewer_id || ! CMP_Access::is_member( $viewer_id ) ) {
			return false;
		}
		if ( (int) $post->author_id === (int) $viewer_id ) {
			return true;
		}
		if ( 'published' !== $post->status || ! CMP_Access::is_member( $post->author_id ) ) {
			return false;
		}
		return 'members' === $post->visibility || ( 'connections' === $post->visibility && in_array( (int) $post->author_id, self::connected_ids( $viewer_id ), true ) );
	}

	/**
	 * Posts the viewer may see, newest first.
	 *
	 * @param array $args author (int), event (int), page (int).
	 */
	public static function visible_posts( $viewer_id, $args = array() ) {
		global $wpdb;
		$args      = wp_parse_args( $args, array( 'author' => 0, 'event' => 0, 'page' => 1 ) );
		$connected = self::connected_ids( $viewer_id );
		$where     = $wpdb->prepare( "( p.author_id = %d OR ( p.status = 'published' AND ( p.visibility = 'members'", $viewer_id );
		if ( $connected ) {
			$where .= " OR ( p.visibility = 'connections' AND p.author_id IN (" . implode( ',', array_map( 'intval', $connected ) ) . ') )';
		}
		$where .= ' ) ) )';
		if ( $args['author'] ) {
			$where .= $wpdb->prepare( ' AND p.author_id = %d', $args['author'] );
		}
		if ( $args['event'] ) {
			$where .= $wpdb->prepare( ' AND p.event_id = %d', $args['event'] );
		}
		$offset = ( max( 1, (int) $args['page'] ) - 1 ) * self::PER_PAGE;
		$rows   = $wpdb->get_results( 'SELECT p.* FROM ' . self::t( 'posts' ) . " p WHERE $where ORDER BY p.id DESC LIMIT " . ( self::PER_PAGE + 1 ) . ' OFFSET ' . (int) $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- parts prepared above.
		// Authors who are no longer full members drop out of everyone else's feed.
		return array_values(
			array_filter(
				$rows,
				function ( $p ) use ( $viewer_id ) {
					return (int) $p->author_id === (int) $viewer_id || CMP_Access::is_member( $p->author_id );
				}
			)
		);
	}

	public static function photos( $post_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, post_id, photo_sha, width, height FROM ' . self::t( 'post_photos' ) . ' WHERE post_id = %d ORDER BY position, id', $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function likes( $post_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'post_likes' ) . ' WHERE post_id = %d', $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function liked( $post_id, $user_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::t( 'post_likes' ) . ' WHERE post_id = %d AND user_id = %d', $post_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Events members may tag: published, from 60 days ago to 30 days ahead. */
	public static function taggable_events() {
		if ( ! post_type_exists( 'cec_event' ) ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'        => 'cec_event',
				'post_status'      => 'publish',
				'posts_per_page'   => 200,
				'suppress_filters' => true,
				'meta_key'         => '_cec_start', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'          => 'meta_value',
				'order'            => 'DESC',
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_cec_start',
						'value'   => array( wp_date( 'Y-m-d', time() - 60 * DAY_IN_SECONDS ), wp_date( 'Y-m-d', time() + 31 * DAY_IN_SECONDS ) ),
						'compare' => 'BETWEEN',
						'type'    => 'CHAR',
					),
				),
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			$out[ (int) $p->ID ] = self::event_label( $p->ID );
		}
		return $out;
	}

	public static function event_label( $event_id ) {
		$start = (string) get_post_meta( $event_id, '_cec_start', true );
		$ts    = $start ? strtotime( str_replace( 'T', ' ', $start ) ) : 0;
		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $event_id ) ), ENT_QUOTES, 'UTF-8' );
		return $title . ( $ts ? ' · ' . gmdate( 'D M j', $ts ) : '' );
	}

	/* ------------------------------------------------------------------
	 * Photos
	 * ---------------------------------------------------------------- */

	public static function photo_url( $photo ) {
		return add_query_arg( array( self::PHOTO_QUERY => (int) $photo->id, 'v' => substr( (string) $photo->photo_sha, 0, 12 ) ), home_url( '/' ) );
	}

	/** ?cmp_postpic=<photo id>: whoever may see the post; everyone else gets 404. */
	public static function maybe_serve_photo() {
		global $wpdb;
		if ( ! isset( $_GET[ self::PHOTO_QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Vary: Cookie' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$photo = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'post_photos' ) . ' WHERE id = %d', absint( $_GET[ self::PHOTO_QUERY ] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.Security.NonceVerification.Recommended
		$post  = $photo ? self::post( $photo->post_id ) : null;
		if ( ! $photo || ! self::can_see( $post, get_current_user_id() ) ) {
			nocache_headers();
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Not found';
			exit;
		}
		$etag = '"' . $photo->photo_sha . '"';
		header( 'Cache-Control: private, no-cache, max-age=0' );
		header( 'ETag: ' . $etag );
		if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) === $etag ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			status_header( 304 );
			exit;
		}
		status_header( 200 );
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . strlen( $photo->photo ) );
		header( 'Content-Disposition: inline; filename="photo.jpg"' );
		header( "Content-Security-Policy: default-src 'none'" );
		echo $photo->photo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JPEG bytes.
		exit;
	}

	/** Uploaded photos[]: a list of JPEG byte strings, or a redirect on any error. */
	private static function posted_photos() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- nonce checked in handle(); files validated below.
		$files = isset( $_FILES['photos'] ) && is_array( $_FILES['photos'] ) && isset( $_FILES['photos']['error'] ) ? $_FILES['photos'] : null;
		if ( ! $files ) {
			return array();
		}
		$out = array();
		foreach ( (array) $files['error'] as $i => $error ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $error ) {
				continue;
			}
			if ( count( $out ) >= self::MAX_PHOTOS || UPLOAD_ERR_OK !== (int) $error || ! is_uploaded_file( $files['tmp_name'][ $i ] ) ) {
				self::go( array(), 'fd_photo' );
			}
			$jpeg = CMP_Homework::process_photo( $files['tmp_name'][ $i ] );
			if ( is_wp_error( $jpeg ) ) {
				self::go( array(), 'fd_photo' );
			}
			$out[] = $jpeg;
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ---------------------------------------------------------------- */

	private static function go( $args, $notice ) {
		wp_safe_redirect( self::url( $args, $notice ) );
		exit;
	}

	/** Back to the page the form came from (feed, an event filter, or a profile), if it's ours. */
	private static function back( $notice ) {
		$to = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$to = $to && 0 === strpos( $to, CMP_Settings::member_page_url() ) ? $to : self::url();
		wp_safe_redirect( add_query_arg( 'cmp_notice', $notice, remove_query_arg( 'cmp_notice', $to ) ) );
		exit;
	}

	public static function handle() {
		$user_id = get_current_user_id();
		if ( ! CMP_Access::is_member( $user_id ) ) {
			wp_safe_redirect( CMP_Settings::member_page_url() );
			exit;
		}
		if ( ! isset( $_POST['_cmp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cmp_nonce'] ) ), self::NONCE ) ) {
			wp_safe_redirect( self::url( array(), 'expired' ) );
			exit;
		}
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'post' === $do ) {
			self::create( $user_id );
		}
		$post = self::post( isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0 );
		if ( ! self::can_see( $post, $user_id ) ) {
			self::go( array(), 'fd_gone' );
		}
		switch ( $do ) {
			case 'like':
				self::like( $post, $user_id );
				break;
			case 'delete':
				self::delete( $post, $user_id );
				break;
			case 'report':
				self::report( $post, $user_id );
				break;
			case 'hide':
				self::hide( $post, $user_id );
				break;
		}
		self::go( array(), 'fd_gone' );
	}

	private static function create( $user_id ) {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in handle().
		$body       = isset( $_POST['body'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) ) : '';
		$visibility = isset( $_POST['visibility'] ) ? sanitize_key( wp_unslash( $_POST['visibility'] ) ) : '';
		$event      = isset( $_POST['event'] ) ? absint( $_POST['event'] ) : 0;
		// phpcs:enable
		if ( ! isset( self::visibilities()[ $visibility ] ) || mb_strlen( $body ) > self::MAX_BODY || ( $event && ! isset( self::taggable_events()[ $event ] ) ) ) {
			self::go( array(), 'fd_invalid' );
		}
		$photos = self::posted_photos();
		if ( '' === $body && ! $photos ) {
			self::go( array(), 'fd_empty' );
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( self::t( 'posts' ), array( 'author_id' => $user_id, 'body' => $body, 'event_id' => $event, 'visibility' => $visibility, 'status' => 'published', 'reports' => 0, 'created_at' => $now, 'updated_at' => $now ) );
		$post_id = (int) $wpdb->insert_id;
		foreach ( $photos as $i => $jpeg ) {
			$size = function_exists( 'getimagesizefromstring' ) ? getimagesizefromstring( $jpeg ) : array( 0, 0 );
			$wpdb->insert( self::t( 'post_photos' ), array( 'post_id' => $post_id, 'position' => $i, 'photo' => $jpeg, 'photo_sha' => hash( 'sha256', $jpeg ), 'width' => (int) $size[0], 'height' => (int) $size[1] ) );
		}
		CMP_Audit::log( 'post_created', 'post', $post_id, null, array( 'photos' => count( $photos ), 'event' => $event, 'visibility' => $visibility ) );
		self::back( 'fd_posted' );
	}

	private static function like( $post, $user_id ) {
		global $wpdb;
		if ( self::liked( $post->id, $user_id ) ) {
			$wpdb->delete( self::t( 'post_likes' ), array( 'post_id' => $post->id, 'user_id' => $user_id ), array( '%d', '%d' ) );
		} else {
			$wpdb->insert( self::t( 'post_likes' ), array( 'post_id' => $post->id, 'user_id' => $user_id, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		}
		$to = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_safe_redirect( ( $to && 0 === strpos( $to, CMP_Settings::member_page_url() ) ? remove_query_arg( 'cmp_notice', $to ) : self::url() ) . '#cmp-post-' . (int) $post->id );
		exit;
	}

	private static function delete( $post, $user_id ) {
		global $wpdb;
		if ( (int) $post->author_id !== (int) $user_id ) {
			self::go( array(), 'fd_gone' );
		}
		self::delete_post( $post->id );
		CMP_Audit::log( 'post_deleted', 'post', $post->id );
		self::back( 'fd_deleted' );
	}

	private static function delete_post( $post_id ) {
		global $wpdb;
		$n  = (int) $wpdb->delete( self::t( 'post_photos' ), array( 'post_id' => $post_id ), array( '%d' ) );
		$n += (int) $wpdb->delete( self::t( 'post_likes' ), array( 'post_id' => $post_id ), array( '%d' ) );
		$n += (int) $wpdb->delete( self::t( 'posts' ), array( 'id' => $post_id ), array( '%d' ) );
		return $n;
	}

	/** One report per member per post (kept in the audit log); the site admin is emailed. */
	private static function report( $post, $user_id ) {
		global $wpdb;
		if ( (int) $post->author_id === (int) $user_id ) {
			self::go( array(), 'fd_gone' );
		}
		$already = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CMP_Install::table( 'audit_log' ) . " WHERE action = 'post_reported' AND object_type = 'post' AND object_id = %d AND actor_id = %d", $post->id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $already ) {
			$reason = isset( $_POST['reason'] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( $_POST['reason'] ) ) ), 0, 300 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'posts' ) . ' SET reports = reports + 1 WHERE id = %d', $post->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			CMP_Audit::log( 'post_reported', 'post', $post->id, null, null, $reason );
			wp_mail(
				get_option( 'admin_email' ),
				/* translators: %s: site name */
				sprintf( __( '[%s] A member post was reported', 'cmp' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				/* translators: 1: post ID, 2: reason, 3: link */
				sprintf( __( "A member reported post #%1\$d in the member feed.\n\nReason: %2\$s\n\nSign in as an administrator to review it (and hide it if needed):\n%3\$s", 'cmp' ), (int) $post->id, '' !== $reason ? $reason : __( '(none given)', 'cmp' ), self::url( array( 'cmp_post' => (int) $post->id ) ) )
			);
		}
		self::back( 'fd_reported' );
	}

	private static function hide( $post, $user_id ) {
		global $wpdb;
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			self::go( array(), 'fd_gone' );
		}
		$wpdb->update( self::t( 'posts' ), array( 'status' => 'hidden', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $post->id ) );
		CMP_Audit::log( 'post_hidden', 'post', $post->id );
		self::back( 'fd_hidden' );
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	private static function form_open( $do, $post_id = 0, $back = '', $multipart = false, $class = 'cmp-form' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . ' class="' . esc_attr( $class ) . '"><input type="hidden" name="action" value="cmp_feed" /><input type="hidden" name="do" value="' . esc_attr( $do ) . '" />' . ( $post_id ? '<input type="hidden" name="post" value="' . (int) $post_id . '" />' : '' ) . ( $back ? '<input type="hidden" name="back" value="' . esc_url( $back ) . '" />' : '' ) . '<input type="hidden" name="_cmp_nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" />';
	}

	private static function current_url() {
		$args = array();
		foreach ( array( 'cmp_tab', 'cmp_member', 'cmp_event', 'cmp_post', 'cmp_page' ) as $k ) {
			if ( isset( $_GET[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$args[ $k ] = sanitize_text_field( wp_unslash( $_GET[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		return add_query_arg( $args, CMP_Settings::member_page_url() );
	}

	public static function render( $user_id ) {
		wp_enqueue_script( 'cmp-member' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$event = isset( $_GET['cmp_event'] ) ? absint( $_GET['cmp_event'] ) : 0;
		$one   = isset( $_GET['cmp_post'] ) ? absint( $_GET['cmp_post'] ) : 0;
		$page  = isset( $_GET['cmp_page'] ) ? max( 1, absint( $_GET['cmp_page'] ) ) : 1;
		// phpcs:enable
		ob_start();
		?>
		<section class="cmp-step" aria-labelledby="cmp-fd-title">
			<h2 id="cmp-fd-title" class="cmp-title"><?php esc_html_e( 'Feed', 'cmp' ); ?></h2>
			<?php if ( $event && post_type_exists( 'cec_event' ) && 'cec_event' === get_post_type( $event ) ) : ?>
				<p><?php echo esc_html( sprintf( /* translators: %s: event */ __( 'Posts tagged with %s', 'cmp' ), self::event_label( $event ) ) ); ?> · <a href="<?php echo esc_url( get_permalink( $event ) ); ?>"><?php esc_html_e( 'Event page', 'cmp' ); ?></a> · <a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Whole feed', 'cmp' ); ?></a></p>
			<?php else : ?>
				<p class="cmp-muted"><?php esc_html_e( 'Posts from members, visible only to signed-in members.', 'cmp' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
		if ( ! $one ) {
			echo self::compose_html( $event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		if ( $one ) {
			$post  = self::post( $one );
			$posts = self::can_see( $post, $user_id ) || ( $post && user_can( $user_id, 'manage_options' ) ) ? array( $post ) : array();
			$more  = false;
		} else {
			$posts = self::visible_posts( $user_id, array( 'event' => $event, 'page' => $page ) );
			$more  = count( $posts ) > self::PER_PAGE;
			$posts = array_slice( $posts, 0, self::PER_PAGE );
		}
		echo self::list_html( $posts, $user_id, $one ? __( 'That post isn\'t available.', 'cmp' ) : __( 'No posts yet. Be the first to share something.', 'cmp' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		if ( $more || $page > 1 ) {
			echo '<nav class="cmp-fd-pages">' . ( $page > 1 ? '<a class="cmp-btn cmp-btn-small cmp-btn-outline" href="' . esc_url( self::url( array_filter( array( 'cmp_event' => $event, 'cmp_page' => $page - 1 ) ) ) ) . '">' . esc_html__( 'Newer', 'cmp' ) . '</a>' : '' ) . ( $more ? '<a class="cmp-btn cmp-btn-small cmp-btn-outline" href="' . esc_url( self::url( array_filter( array( 'cmp_event' => $event, 'cmp_page' => $page + 1 ) ) ) ) . '">' . esc_html__( 'Older', 'cmp' ) . '</a>' : '' ) . '</nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return ob_get_clean();
	}

	private static function compose_html( $event = 0 ) {
		$events = self::taggable_events();
		$html   = '<section class="cmp-panel cmp-fd-compose"><h3 class="cmp-panel-title">' . esc_html__( 'Share something', 'cmp' ) . '</h3>' . self::form_open( 'post', 0, self::current_url(), true );
		$html  .= '<p class="cmp-field"><label for="cmp_fd_body">' . esc_html__( 'Post', 'cmp' ) . '</label><textarea id="cmp_fd_body" name="body" rows="3" maxlength="' . (int) self::MAX_BODY . '" placeholder="' . esc_attr__( 'Share something with members…', 'cmp' ) . '"></textarea></p>';
		$html  .= '<p class="cmp-field"><label for="cmp_fd_photos">' . esc_html__( 'Photos (up to 4, optional)', 'cmp' ) . '</label><input type="file" id="cmp_fd_photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple /></p>';
		$html  .= '<div class="cmp-grid cmp-grid-2">';
		if ( $events ) {
			$html .= '<p class="cmp-field"><label for="cmp_fd_event">' . esc_html__( 'Tag an event (optional)', 'cmp' ) . '</label><select id="cmp_fd_event" name="event"><option value="0">' . esc_html__( 'No event', 'cmp' ) . '</option>';
			foreach ( $events as $id => $label ) {
				$html .= '<option value="' . (int) $id . '"' . selected( $event, $id, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$html .= '</select></p>';
		}
		$html .= '<p class="cmp-field"><label for="cmp_fd_vis">' . esc_html__( 'Who can see it', 'cmp' ) . '</label><select id="cmp_fd_vis" name="visibility">';
		foreach ( self::visibilities() as $k => $l ) {
			$html .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
		}
		$html .= '</select></p></div><button type="submit" class="cmp-btn">' . esc_html__( 'Post', 'cmp' ) . '</button></form>';
		$html .= '<p class="cmp-muted">' . esc_html__( 'Only signed-in members can see posts. Photos have location data removed. Don\'t share anyone\'s face or body without their OK.', 'cmp' ) . '</p></section>';
		return $html;
	}

	/** @param object[] $posts */
	public static function list_html( $posts, $viewer_id, $empty ) {
		if ( ! $posts ) {
			return '<section class="cmp-panel"><p class="cmp-empty">' . esc_html( $empty ) . '</p></section>';
		}
		$html = '';
		foreach ( $posts as $p ) {
			$html .= self::post_html( $p, $viewer_id );
		}
		return $html;
	}

	private static function post_html( $p, $viewer_id ) {
		$author = get_userdata( $p->author_id );
		$name   = $author ? $author->display_name : __( 'Former member', 'cmp' );
		$mine   = (int) $p->author_id === (int) $viewer_id;
		$back   = self::current_url();
		$avatar = ( $mine || CMP_Profiles::can_view( 'avatar', $p->author_id, $viewer_id ) ) ? CMP_Profile_Images::get( $p->author_id, 'avatar', false ) : null;
		$link   = $mine ? CMP_Profiles::url() : add_query_arg( 'cmp_member', (int) $p->author_id, CMP_Settings::member_page_url() );
		$html   = '<article class="cmp-panel cmp-fd-post' . ( 'hidden' === $p->status ? ' is-hidden' : '' ) . '" id="cmp-post-' . (int) $p->id . '">';
		$html  .= '<header class="cmp-fd-who"><a class="cmp-fd-av" href="' . esc_url( $link ) . '" aria-hidden="true" tabindex="-1">' . ( $avatar ? CMP_Profile_Images::img_html( $p->author_id, 'avatar', $avatar ) : '<span>' . esc_html( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ) . '</span>' ) . '</a>';
		$html  .= '<div><a class="cmp-fd-name" href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a><small>' . esc_html( sprintf( /* translators: %s: time ago */ __( '%s ago', 'cmp' ), human_time_diff( strtotime( $p->created_at . ' UTC' ) ) ) ) . ' · ' . esc_html( self::visibilities()[ $p->visibility ] ) . ( 'hidden' === $p->status ? ' · ' . esc_html__( 'hidden by the site team', 'cmp' ) : '' ) . '</small></div></header>';
		if ( $p->event_id && post_type_exists( 'cec_event' ) && 'publish' === get_post_status( $p->event_id ) ) {
			$html .= '<p class="cmp-fd-tag"><a href="' . esc_url( self::url( array( 'cmp_event' => (int) $p->event_id ) ) ) . '"><span aria-hidden="true">📅</span> ' . esc_html( self::event_label( $p->event_id ) ) . '</a></p>';
		}
		if ( '' !== $p->body ) {
			$html .= '<p class="cmp-fd-body">' . nl2br( esc_html( $p->body ) ) . '</p>';
		}
		$photos = self::photos( $p->id );
		if ( $photos ) {
			$html .= '<div class="cmp-fd-photos cmp-fd-n' . count( $photos ) . '">';
			foreach ( $photos as $ph ) {
				$html .= '<a href="' . esc_url( self::photo_url( $ph ) ) . '" target="_blank" rel="noopener"><img src="' . esc_url( self::photo_url( $ph ) ) . '" width="' . (int) $ph->width . '" height="' . (int) $ph->height . '" alt="' . esc_attr( sprintf( /* translators: %s: member */ __( 'Photo shared by %s', 'cmp' ), $name ) ) . '" loading="lazy" decoding="async" /></a>';
			}
			$html .= '</div>';
		}
		$likes  = self::likes( $p->id );
		$liked  = self::liked( $p->id, $viewer_id );
		$html  .= '<footer class="cmp-fd-acts">';
		if ( 'published' === $p->status ) {
			$html .= self::form_open( 'like', $p->id, $back, false, 'cmp-form cmp-fd-like' ) . '<button type="submit" class="cmp-fd-likebtn' . ( $liked ? ' is-liked' : '' ) . '" aria-pressed="' . ( $liked ? 'true' : 'false' ) . '"><span aria-hidden="true">' . ( $liked ? '♥' : '♡' ) . '</span> ' . (int) $likes . '<span class="screen-reader-text"> ' . esc_html__( 'likes', 'cmp' ) . '</span></button></form>';
		}
		if ( $mine ) {
			$html .= self::form_open( 'delete', $p->id, $back ) . '<button type="submit" class="cmp-fd-link" data-cmp-confirm="' . esc_attr__( 'Delete this post and its photos?', 'cmp' ) . '">' . esc_html__( 'Delete', 'cmp' ) . '</button></form>';
		} else {
			$html .= '<details class="cmp-fd-report"><summary>' . esc_html__( 'Report', 'cmp' ) . '</summary>' . self::form_open( 'report', $p->id, $back ) . '<p class="cmp-field"><label for="cmp_fd_r' . (int) $p->id . '">' . esc_html__( 'What\'s wrong? (optional)', 'cmp' ) . '</label><input type="text" id="cmp_fd_r' . (int) $p->id . '" name="reason" maxlength="300" /></p><button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Send report', 'cmp' ) . '</button></form></details>';
		}
		if ( user_can( $viewer_id, 'manage_options' ) && 'published' === $p->status ) {
			$html .= self::form_open( 'hide', $p->id, $back ) . '<button type="submit" class="cmp-fd-link" data-cmp-confirm="' . esc_attr__( 'Hide this post from everyone but its author?', 'cmp' ) . '">' . esc_html( sprintf( /* translators: %d: reports */ __( 'Hide (admin) · %d reports', 'cmp' ), (int) $p->reports ) ) . '</button></form>';
		}
		return $html . '</footer></article>';
	}

	/** Recent posts on a member's profile, as the viewer may see them. */
	public static function profile_html( $owner_id, $viewer_id ) {
		$posts = array_slice( self::visible_posts( $viewer_id, array( 'author' => $owner_id ) ), 0, 5 );
		if ( ! $posts ) {
			return '';
		}
		return '<section class="cmp-fd-profile"><h3 class="cmp-panel-title">' . esc_html__( 'Posts', 'cmp' ) . '</h3>' . self::list_html( $posts, $viewer_id, '' ) . '</section>';
	}

	/* ------------------------------------------------------------------
	 * Privacy
	 * ---------------------------------------------------------------- */

	public static function export_rows( $user_id ) {
		global $wpdb;
		$rows = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'posts' ) . ' WHERE author_id = %d ORDER BY id', $user_id ) ) as $p ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$n      = count( self::photos( $p->id ) );
			$rows[] = array( 'name' => __( 'Feed post', 'cmp' ), 'value' => sprintf( '%s · %s · %s%s%s', $p->created_at, self::visibilities()[ $p->visibility ], $p->body, $p->event_id ? ' · ' . self::event_label( $p->event_id ) : '', $n ? ' · ' . sprintf( /* translators: %d: photos */ _n( '(%d photo kept)', '(%d photos kept)', $n, 'cmp' ), $n ) : '' ) );
		}
		return $rows;
	}

	/** The member's posts, photos and likes. */
	public static function erase( $user_id ) {
		global $wpdb;
		$n = 0;
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'posts' ) . ' WHERE author_id = %d', $user_id ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$n += self::delete_post( (int) $id );
		}
		$n += (int) $wpdb->delete( self::t( 'post_likes' ), array( 'user_id' => $user_id ), array( '%d' ) );
		return $n;
	}
}
