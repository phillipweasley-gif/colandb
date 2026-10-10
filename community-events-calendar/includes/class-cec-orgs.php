<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Partner Organizations & Titleholders as public listings (1.34.0).
 *
 * Every cec_partner_org term can carry a full public profile:
 * - kind: organization | titleholder
 * - logo/photo (cec_logo URL + cec_logo_id), short description (the term
 *   description), highlights (one per line), mission / areas of focus
 * - titleholders: title, year, producer
 * - social links (website mirrors the older cec_url meta)
 * - listed (shown in [cec_partner_orgs] grids) and display order
 * - a private contact (never shown publicly)
 *
 * Its page is the term archive, /partner/<slug>/ (templates/
 * taxonomy-cec_events.php), which shows this profile above the group's
 * upcoming events. Single-segment addresses that match a group's slug
 * (the hand-built pages from before 1.34.0, e.g. /kink-101/) redirect there.
 *
 * Listings come from approved submissions (CEC_Org_Submissions) or from the
 * term edit screen. Groups created on the fly from event forms ("Don't see
 * your organization? Add it") work as calendar groups but stay unlisted.
 */
class CEC_Orgs {

	const KINDS = array( 'organization', 'titleholder' );

	public static function init() {
		add_shortcode( 'cec_partner_orgs', array( __CLASS__, 'shortcode_grid' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_old_pages' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'cec_partner_org_edit_form_fields', array( __CLASS__, 'edit_fields' ), 20 );
		add_action( 'edited_cec_partner_org', array( __CLASS__, 'save_edit_fields' ), 20 );
		add_filter( 'manage_edit-cec_partner_org_columns', array( __CLASS__, 'admin_columns' ) );
		add_filter( 'manage_cec_partner_org_custom_column', array( __CLASS__, 'admin_column' ), 10, 3 );
	}

	public static function register_assets() {
		wp_register_style( 'cec-orgs', CEC_URL . 'assets/css/orgs.css', array(), CEC_VERSION );
		if ( is_tax( 'cec_partner_org' ) ) {
			wp_enqueue_style( 'cec-orgs' );
		}
	}

	/** Social link fields, in display order: key => label. */
	public static function socials() {
		return array(
			'telegram'  => __( 'Telegram', 'cec' ),
			'website'   => __( 'Website', 'cec' ),
			'facebook'  => __( 'Facebook', 'cec' ),
			'instagram' => __( 'Instagram', 'cec' ),
			'fetlife'   => __( 'FetLife', 'cec' ),
			'x'         => __( 'X (Twitter)', 'cec' ),
			'bluesky'   => __( 'Bluesky', 'cec' ),
			'other'     => __( 'Other link', 'cec' ),
		);
	}

	/**
	 * Cleans one social link. Returns '' for empty, a WP_Error for a link
	 * that can't work (wrong site, or a Telegram link only existing members
	 * can open), or the clean https URL.
	 *
	 * @return string|WP_Error
	 */
	public static function clean_social( $key, $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			$raw = 'https://' . ltrim( $raw, '/' );
		}
		$url  = esc_url_raw( $raw, array( 'http', 'https' ) );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $url || ! $host || false === strpos( $host, '.' ) ) {
			/* translators: %s: link name, e.g. Instagram */
			return new WP_Error( 'cec_link', sprintf( __( 'The %s link doesn\'t look like a web address.', 'cec' ), self::socials()[ $key ] ) );
		}
		$hosts = array(
			'telegram'  => array( 't.me', 'telegram.me' ),
			'facebook'  => array( 'facebook.com', 'fb.com', 'fb.me' ),
			'instagram' => array( 'instagram.com' ),
			'fetlife'   => array( 'fetlife.com' ),
			'x'         => array( 'x.com', 'twitter.com' ),
			'bluesky'   => array( 'bsky.app' ),
		);
		if ( isset( $hosts[ $key ] ) ) {
			$ok = false;
			foreach ( $hosts[ $key ] as $h ) {
				$ok = $ok || $host === $h || substr( $host, -strlen( '.' . $h ) ) === '.' . $h;
			}
			if ( ! $ok ) {
				/* translators: 1: link name, 2: expected site */
				return new WP_Error( 'cec_link', sprintf( __( 'The %1$s link should be a %2$s address.', 'cec' ), self::socials()[ $key ], $hosts[ $key ][0] ) );
			}
		}
		// t.me/c/… opens a message inside a private chat: it only works for
		// people already in it. A public listing needs an invite link.
		if ( 'telegram' === $key && preg_match( '#^/c/#', $path ) ) {
			return new WP_Error( 'cec_link', __( 'That Telegram link only works for people already in the chat. Please use an invite link (it starts https://t.me/+…). In Telegram: tap the group name → Invite Links.', 'cec' ) );
		}
		return 'http://' === substr( $url, 0, 7 ) ? 'https://' . substr( $url, 7 ) : $url;
	}

	/**
	 * Everything public about one group, plus the private contact.
	 */
	public static function profile( $term ) {
		$term = $term instanceof WP_Term ? $term : get_term( $term, 'cec_partner_org' );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		$id      = $term->term_id;
		$kind    = get_term_meta( $id, 'cec_kind', true );
		$socials = get_term_meta( $id, 'cec_socials', true );
		$socials = is_array( $socials ) ? $socials : array();
		$website = (string) get_term_meta( $id, 'cec_url', true );
		if ( $website && empty( $socials['website'] ) ) {
			$socials['website'] = $website; // Groups set up before 1.34.0.
		}
		$ordered = array();
		foreach ( array_keys( self::socials() ) as $k ) {
			if ( ! empty( $socials[ $k ] ) ) {
				$ordered[ $k ] = $socials[ $k ];
			}
		}
		$highlights = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) get_term_meta( $id, 'cec_highlights', true ) ) ) ) );
		$contact    = get_term_meta( $id, 'cec_contact', true );
		$link       = get_term_link( $term );
		return array(
			'id'         => $id,
			'name'       => $term->name,
			'slug'       => $term->slug,
			'kind'       => in_array( $kind, self::KINDS, true ) ? $kind : 'organization',
			'description' => $term->description,
			'highlights' => $highlights,
			'mission'    => (string) get_term_meta( $id, 'cec_mission', true ),
			'title'      => (string) get_term_meta( $id, 'cec_title', true ),
			'year'       => (string) get_term_meta( $id, 'cec_title_year', true ),
			'producer'   => (string) get_term_meta( $id, 'cec_title_producer', true ),
			'logo'       => (string) get_term_meta( $id, 'cec_logo', true ),
			'logo_id'    => (int) get_term_meta( $id, 'cec_logo_id', true ),
			'socials'    => $ordered,
			'listed'     => (bool) get_term_meta( $id, 'cec_listed', true ),
			'order'      => (int) get_term_meta( $id, 'cec_order', true ),
			'contact'    => is_array( $contact ) ? $contact : array(),
			'url'        => is_wp_error( $link ) ? '' : $link,
		);
	}

	/**
	 * Writes a profile's fields (any subset) to a term. Website is kept in
	 * cec_url as well, which the rest of the plugin uses for links.
	 */
	public static function save_profile( $term_id, $fields ) {
		$map = array(
			'kind'       => 'cec_kind',
			'mission'    => 'cec_mission',
			'title'      => 'cec_title',
			'year'       => 'cec_title_year',
			'producer'   => 'cec_title_producer',
			'logo'       => 'cec_logo',
			'logo_id'    => 'cec_logo_id',
			'socials'    => 'cec_socials',
			'listed'     => 'cec_listed',
			'order'      => 'cec_order',
			'contact'    => 'cec_contact',
		);
		foreach ( $map as $field => $meta ) {
			if ( array_key_exists( $field, $fields ) ) {
				update_term_meta( $term_id, $meta, $fields[ $field ] );
			}
		}
		if ( array_key_exists( 'highlights', $fields ) ) {
			update_term_meta( $term_id, 'cec_highlights', implode( "\n", (array) $fields['highlights'] ) );
		}
		if ( array_key_exists( 'socials', $fields ) ) {
			update_term_meta( $term_id, 'cec_url', isset( $fields['socials']['website'] ) ? $fields['socials']['website'] : '' );
		}
		if ( array_key_exists( 'description', $fields ) ) {
			wp_update_term( $term_id, 'cec_partner_org', array( 'description' => $fields['description'] ) );
		}
	}

	public static function next_order() {
		global $wpdb;
		return 1 + (int) $wpdb->get_var( "SELECT MAX(CAST(meta_value AS SIGNED)) FROM {$wpdb->termmeta} WHERE meta_key = 'cec_order'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Listed groups of one kind (or all), in display order.
	 */
	public static function listed( $kind = '' ) {
		$terms = get_terms( array( 'taxonomy' => 'cec_partner_org', 'hide_empty' => false, 'meta_key' => 'cec_listed', 'meta_value' => '1' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$out   = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$p = self::profile( $t );
			if ( $p && ( '' === $kind || $kind === $p['kind'] ) ) {
				$out[] = $p;
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return ( $a['order'] <=> $b['order'] ) ?: strcasecmp( $a['name'], $b['name'] );
			}
		);
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Old hand-built pages → /partner/<slug>/
	 * ---------------------------------------------------------------- */

	public static function redirect_old_pages() {
		if ( ! is_404() ) {
			return;
		}
		$path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $home && 0 === strpos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) + 1 );
		}
		if ( '' === $path || false !== strpos( $path, '/' ) ) {
			return;
		}
		$term = get_term_by( 'slug', sanitize_title( $path ), 'cec_partner_org' );
		if ( $term && ! is_wp_error( $term ) ) {
			$link = get_term_link( $term );
			if ( ! is_wp_error( $link ) ) {
				wp_safe_redirect( $link, 301 );
				exit;
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	public static function image_html( $p, $class ) {
		if ( ! $p['logo'] && ! $p['logo_id'] ) {
			return '';
		}
		$alt = 'titleholder' === $p['kind']
			/* translators: %s: titleholder name */
			? sprintf( __( 'Photo of %s', 'cec' ), $p['name'] )
			/* translators: %s: organization name */
			: sprintf( __( '%s logo', 'cec' ), $p['name'] );
		if ( $p['logo_id'] && wp_attachment_is_image( $p['logo_id'] ) ) {
			return wp_get_attachment_image( $p['logo_id'], 'medium_large', false, array( 'class' => $class, 'alt' => $alt, 'loading' => 'lazy' ) );
		}
		return '<img class="' . esc_attr( $class ) . '" src="' . esc_url( $p['logo'] ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" />';
	}

	/** "Great Lakes Handler 2026 · Produced by …" for a titleholder. */
	public static function title_line( $p ) {
		$title = trim( $p['title'] . ' ' . $p['year'] );
		$line  = $title;
		if ( $p['producer'] ) {
			/* translators: %s: who produces the title contest */
			$line .= ( $line ? ' · ' : '' ) . sprintf( __( 'Produced by %s', 'cec' ), $p['producer'] );
		}
		return $line;
	}

	/**
	 * The profile block on /partner/<slug>/ (above the group's events).
	 */
	public static function header_html( $term ) {
		$p = self::profile( $term );
		if ( ! $p ) {
			return '';
		}
		wp_enqueue_style( 'cec-orgs' );
		$labels = self::socials();
		$button = array(
			/* translators: %s: Telegram */
			'telegram' => __( 'Join our Telegram', 'cec' ),
			'website'  => __( 'Visit Website', 'cec' ),
		);
		$html  = '<section class="cec-org-hero' . ( $p['logo'] || $p['logo_id'] ? ' has-image' : '' ) . ' is-' . esc_attr( $p['kind'] ) . '">';
		$html .= '<div class="cec-org-main">';
		if ( 'titleholder' === $p['kind'] && self::title_line( $p ) ) {
			$html .= '<p class="cec-org-eyebrow">' . esc_html( self::title_line( $p ) ) . '</p>';
		}
		$html .= '<h1 class="cec-org-name">' . esc_html( $p['name'] ) . '</h1><hr class="cec-org-rule" />';
		if ( $p['description'] ) {
			$html .= '<div class="cec-org-desc">' . wpautop( esc_html( $p['description'] ) ) . '</div>';
		}
		if ( $p['highlights'] ) {
			$html .= '<ul class="cec-org-highlights">';
			foreach ( $p['highlights'] as $h ) {
				$html .= '<li>' . esc_html( $h ) . '</li>';
			}
			$html .= '</ul>';
		}
		if ( $p['socials'] ) {
			$html .= '<div class="cec-org-links">';
			$first = true;
			foreach ( $p['socials'] as $k => $url ) {
				$text  = isset( $button[ $k ] ) ? $button[ $k ] : $labels[ $k ];
				$html .= '<a class="cec-org-btn' . ( $first ? ' is-primary' : '' ) . '" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . '</a>';
				$first = false;
			}
			$html .= '</div>';
		}
		$html .= '</div>';
		$image = self::image_html( $p, 'cec-org-image' );
		if ( $image ) {
			$html .= '<div class="cec-org-media">' . $image . '</div>';
		}
		$html .= '</section>';
		if ( $p['mission'] ) {
			$heading = 'titleholder' === $p['kind'] ? __( 'Areas of Focus', 'cec' ) : __( 'Our Mission', 'cec' );
			$html   .= '<section class="cec-org-card"><h2>' . esc_html( $heading ) . '</h2>' . wpautop( esc_html( $p['mission'] ) ) . '</section>';
		}
		/**
		 * Extra content for a group's page, e.g. linked member profiles from
		 * the member plugin. Printed below the profile, above the events.
		 *
		 * @param string $html
		 * @param array  $profile CEC_Orgs::profile()
		 */
		$html .= apply_filters( 'cec_partner_org_page_extra', '', $p );
		return $html;
	}

	/**
	 * [cec_partner_orgs kind="organization|titleholder" button="Learn More"]
	 * The homepage grid of listed groups.
	 */
	public static function shortcode_grid( $atts ) {
		$atts = shortcode_atts( array( 'kind' => 'all', 'button' => __( 'Learn More', 'cec' ), 'empty' => '' ), $atts, 'cec_partner_orgs' );
		$kind = in_array( $atts['kind'], self::KINDS, true ) ? $atts['kind'] : ( 'all' === $atts['kind'] ? '' : 'organization' );
		$list = self::listed( $kind );
		wp_enqueue_style( 'cec-orgs' );
		if ( ! $list ) {
			return $atts['empty'] ? '<p class="cec-org-empty">' . esc_html( $atts['empty'] ) . '</p>' : '';
		}
		$html = '<div class="cec-org-grid">';
		foreach ( $list as $p ) {
			$html .= '<article class="cec-org-tile is-' . esc_attr( $p['kind'] ) . '">';
			$image = self::image_html( $p, 'cec-org-tile-image' );
			$html .= '<div class="cec-org-tile-head">' . ( $image ? '<span class="cec-org-tile-media">' . $image . '</span>' : '' ) . '<h3>' . esc_html( $p['name'] ) . '</h3></div>';
			if ( 'titleholder' === $p['kind'] && self::title_line( $p ) ) {
				$html .= '<p class="cec-org-tile-title">' . esc_html( self::title_line( $p ) ) . '</p>';
			}
			if ( $p['highlights'] ) {
				$html .= '<ul class="cec-org-tile-list">';
				foreach ( $p['highlights'] as $h ) {
					$html .= '<li>' . esc_html( $h ) . '</li>';
				}
				$html .= '</ul>';
			} elseif ( $p['description'] ) {
				$html .= '<p class="cec-org-tile-desc">' . esc_html( wp_trim_words( $p['description'], 28 ) ) . '</p>';
			}
			$html .= '<a class="cec-org-tile-btn" href="' . esc_url( $p['url'] ) . '">' . esc_html( $atts['button'] ) . '<span class="screen-reader-text">: ' . esc_html( $p['name'] ) . '</span></a>';
			$html .= '</article>';
		}
		return $html . '</div>';
	}

	/* ------------------------------------------------------------------
	 * wp-admin: Events → Partners & Titleholders → Edit
	 * ---------------------------------------------------------------- */

	public static function edit_fields( $term ) {
		$p = self::profile( $term );
		wp_nonce_field( 'cec_org_profile_' . $term->term_id, 'cec_org_profile_nonce' );
		?>
		<tr class="form-field"><th colspan="2"><h2 style="margin:1em 0 0"><?php esc_html_e( 'Public listing', 'cec' ); ?></h2></th></tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Kind', 'cec' ); ?></th>
			<td>
				<label><input type="radio" name="cec_org_kind" value="organization" <?php checked( 'organization', $p['kind'] ); ?> /> <?php esc_html_e( 'Organization', 'cec' ); ?></label>&nbsp;&nbsp;
				<label><input type="radio" name="cec_org_kind" value="titleholder" <?php checked( 'titleholder', $p['kind'] ); ?> /> <?php esc_html_e( 'Titleholder', 'cec' ); ?></label>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Show on the site', 'cec' ); ?></th>
			<td>
				<label><input type="checkbox" name="cec_org_listed" value="1" <?php checked( $p['listed'] ); ?> /> <?php esc_html_e( 'Listed: show in the [cec_partner_orgs] grids (e.g. the homepage)', 'cec' ); ?></label>
				<p><label><?php esc_html_e( 'Order', 'cec' ); ?> <input type="number" name="cec_org_order" value="<?php echo (int) $p['order']; ?>" class="small-text" /></label> <span class="description"><?php esc_html_e( 'Lower numbers come first.', 'cec' ); ?></span></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="cec_org_highlights"><?php esc_html_e( 'Highlights', 'cec' ); ?></label></th>
			<td><textarea name="cec_org_highlights" id="cec_org_highlights" rows="4"><?php echo esc_textarea( implode( "\n", $p['highlights'] ) ); ?></textarea><p class="description"><?php esc_html_e( 'One per line, e.g. "Peer Education". Shown as bullet points on the homepage card and the page.', 'cec' ); ?></p></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="cec_org_mission"><?php esc_html_e( 'Mission / areas of focus', 'cec' ); ?></label></th>
			<td><textarea name="cec_org_mission" id="cec_org_mission" rows="5"><?php echo esc_textarea( $p['mission'] ); ?></textarea></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Titleholders only', 'cec' ); ?></th>
			<td>
				<p><label><?php esc_html_e( 'Title', 'cec' ); ?><br /><input type="text" name="cec_org_title" value="<?php echo esc_attr( $p['title'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Great Lakes Handler', 'cec' ); ?>" /></label></p>
				<p><label><?php esc_html_e( 'Year', 'cec' ); ?><br /><input type="number" name="cec_org_year" value="<?php echo esc_attr( $p['year'] ); ?>" min="1970" max="2100" class="small-text" /></label></p>
				<p><label><?php esc_html_e( 'Producer (the contest or organization that awards the title)', 'cec' ); ?><br /><input type="text" name="cec_org_producer" value="<?php echo esc_attr( $p['producer'] ); ?>" /></label></p>
			</td>
		</tr>
		<?php foreach ( self::socials() as $k => $label ) : ?>
			<?php
			if ( 'website' === $k ) {
				continue; // The existing "Organization Website" field above.
			}
			?>
			<tr class="form-field">
				<th scope="row"><label for="cec_org_social_<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label></th>
				<td><input type="url" name="cec_org_social[<?php echo esc_attr( $k ); ?>]" id="cec_org_social_<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( isset( $p['socials'][ $k ] ) ? $p['socials'][ $k ] : '' ); ?>" placeholder="https://" /></td>
			</tr>
		<?php endforeach; ?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Private contact', 'cec' ); ?></th>
			<td>
				<?php if ( $p['contact'] ) : ?>
					<p><?php echo esc_html( implode( ' · ', array_filter( array( $p['contact']['name'] ?? '', $p['contact']['email'] ?? '', $p['contact']['phone'] ?? '' ) ) ) ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'None on file.', 'cec' ); ?></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'From the submission form. Only administrators see this; it is never shown on the site.', 'cec' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public static function save_edit_fields( $term_id ) {
		if ( ! isset( $_POST['cec_org_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cec_org_profile_nonce'] ) ), 'cec_org_profile_' . $term_id ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$p       = self::profile( $term_id );
		$socials = array( 'website' => isset( $p['socials']['website'] ) ? $p['socials']['website'] : '' );
		// The core website field (cec_url) was just saved by CEC_Term_Meta.
		$socials['website'] = (string) get_term_meta( $term_id, 'cec_url', true );
		$posted             = isset( $_POST['cec_org_social'] ) && is_array( $_POST['cec_org_social'] ) ? wp_unslash( $_POST['cec_org_social'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned by clean_social().
		foreach ( array_keys( self::socials() ) as $k ) {
			if ( 'website' === $k ) {
				continue;
			}
			$clean = self::clean_social( $k, isset( $posted[ $k ] ) ? $posted[ $k ] : '' );
			$socials[ $k ] = is_wp_error( $clean ) ? ( isset( $p['socials'][ $k ] ) ? $p['socials'][ $k ] : '' ) : $clean;
		}
		$kind = isset( $_POST['cec_org_kind'] ) ? sanitize_key( wp_unslash( $_POST['cec_org_kind'] ) ) : 'organization';
		self::save_profile(
			$term_id,
			array(
				'kind'       => in_array( $kind, self::KINDS, true ) ? $kind : 'organization',
				'listed'     => empty( $_POST['cec_org_listed'] ) ? '' : '1',
				'order'      => isset( $_POST['cec_org_order'] ) ? (int) $_POST['cec_org_order'] : 0,
				'highlights' => array_slice( array_filter( array_map( 'sanitize_text_field', preg_split( '/\r\n|\r|\n/', isset( $_POST['cec_org_highlights'] ) ? wp_unslash( $_POST['cec_org_highlights'] ) : '' ) ) ), 0, 8 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'mission'    => isset( $_POST['cec_org_mission'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['cec_org_mission'] ) ), 0, 2000 ) : '',
				'title'      => isset( $_POST['cec_org_title'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_org_title'] ) ) : '',
				'year'       => isset( $_POST['cec_org_year'] ) && preg_match( '/^\d{4}$/', (string) $_POST['cec_org_year'] ) ? (string) $_POST['cec_org_year'] : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'producer'   => isset( $_POST['cec_org_producer'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_org_producer'] ) ) : '',
				'socials'    => array_filter( $socials ),
			)
		);
		// A logo picked from the media library by URL: remember its ID too.
		$logo = (string) get_term_meta( $term_id, 'cec_logo', true );
		update_term_meta( $term_id, 'cec_logo_id', $logo ? (int) attachment_url_to_postid( $logo ) : 0 );
	}

	public static function admin_columns( $cols ) {
		$cols['cec_org_kind'] = __( 'Kind', 'cec' );
		$cols['cec_listed']   = __( 'Listed', 'cec' );
		return $cols;
	}

	public static function admin_column( $out, $column, $term_id ) {
		if ( 'cec_org_kind' === $column ) {
			return 'titleholder' === get_term_meta( $term_id, 'cec_kind', true ) ? esc_html__( 'Titleholder', 'cec' ) : esc_html__( 'Organization', 'cec' );
		}
		if ( 'cec_listed' === $column ) {
			return get_term_meta( $term_id, 'cec_listed', true ) ? '✓ ' . (int) get_term_meta( $term_id, 'cec_order', true ) : '—';
		}
		return $out;
	}
}
