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
		add_filter( 'wp_get_nav_menu_items', array( __CLASS__, 'menu_children' ), 20, 3 );
	}

	/**
	 * Automatic menu dropdowns (1.39.0). A menu item with the CSS class
	 * cec-partner-orgs-organization (or -titleholder) gets every listed
	 * organization (or titleholder) as its sub-items, in display order, so
	 * newly approved listings appear in the menu with no menu editing.
	 * (Appearance → Menus → Screen Options → CSS Classes to set the class.)
	 */
	public static function menu_children( $items, $menu, $args ) {
		if ( is_admin() || ! $items ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $item ) {
			$out[]   = $item;
			$classes = is_array( $item->classes ) ? $item->classes : array();
			foreach ( self::KINDS as $kind ) {
				if ( ! in_array( 'cec-partner-orgs-' . $kind, $classes, true ) ) {
					continue;
				}
				foreach ( self::listed( $kind ) as $p ) {
					$id    = 1900000000 + 2 * (int) $p['id'] + ( 'titleholder' === $kind ? 1 : 0 ); // Far above real post IDs.
					$out[] = new WP_Post(
						(object) array(
							'ID'               => $id,
							'db_id'            => $id,
							'menu_item_parent' => (string) $item->db_id,
							'object_id'        => (string) $p['id'],
							'object'           => 'cec_partner_org',
							'type'             => 'taxonomy',
							'type_label'       => '',
							'title'            => $p['name'],
							'post_title'       => $p['name'],
							'url'              => $p['url'],
							'target'           => '',
							'attr_title'       => '',
							'description'      => '',
							'classes'          => array( 'menu-item', 'cec-partner-org-menu-item' ),
							'xfn'              => '',
							'status'           => 'publish',
							'post_status'      => 'publish',
							'post_type'        => 'nav_menu_item',
							'post_parent'      => 0,
							'menu_order'       => 0,
							'filter'           => 'raw',
						)
					);
				}
			}
		}
		foreach ( $out as $i => $item ) {
			$item->menu_order = $i + 1;
		}
		return $out;
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
			'summary'    => (string) get_term_meta( $id, 'cec_summary', true ),
			'region'     => (string) get_term_meta( $id, 'cec_region', true ),
			'city'       => (string) get_term_meta( $id, 'cec_loc_city', true ),
			'state'      => (string) get_term_meta( $id, 'cec_loc_state', true ),
			'zip'        => (string) get_term_meta( $id, 'cec_loc_zip', true ), // Private: never shown on the site.
			'shape'      => 'rect' === get_term_meta( $id, 'cec_photo_shape', true ) ? 'rect' : 'circle',
			'ask'        => array_values( array_intersect( array_keys( self::socials() ), (array) get_term_meta( $id, 'cec_social_ask', true ) ) ),
			'title'      => (string) get_term_meta( $id, 'cec_title', true ),
			'year'       => (string) get_term_meta( $id, 'cec_title_year', true ),
			'producer'   => (string) get_term_meta( $id, 'cec_title_producer', true ),
			'titles_more' => self::clean_titles( get_term_meta( $id, 'cec_titles_more', true ) )[0],
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
			'summary'    => 'cec_summary',
			'region'     => 'cec_region',
			'city'       => 'cec_loc_city',
			'state'      => 'cec_loc_state',
			'zip'        => 'cec_loc_zip',
			'shape'      => 'cec_photo_shape',
			'ask'        => 'cec_social_ask',
			'title'      => 'cec_title',
			'year'       => 'cec_title_year',
			'producer'   => 'cec_title_producer',
			'titles_more' => 'cec_titles_more',
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
		// 1.43.0: city + state are the location; cec_region is kept as the
		// display text. A bare region (Restore of a version saved before
		// 1.43.0) is split back into city and state.
		if ( array_key_exists( 'region', $fields ) && ! array_key_exists( 'city', $fields ) && ! array_key_exists( 'state', $fields ) ) {
			$loc = self::parse_location( $fields['region'] );
			if ( '' !== $loc['state'] ) {
				update_term_meta( $term_id, 'cec_loc_city', $loc['city'] );
				update_term_meta( $term_id, 'cec_loc_state', $loc['state'] );
			}
		}
		if ( array_key_exists( 'city', $fields ) || array_key_exists( 'state', $fields ) ) {
			update_term_meta( $term_id, 'cec_region', self::format_location( (string) get_term_meta( $term_id, 'cec_loc_city', true ), (string) get_term_meta( $term_id, 'cec_loc_state', true ) ) );
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

	/**
	 * 1.36.0: after a group's listing changes, re-save the pages that show
	 * the [cec_partner_orgs] grid (e.g. the homepage) and clear Elementor's
	 * cache, so the host's CDN serves the new cards instead of a stored copy.
	 */
	public static function refresh_pages() {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data' WHERE p.post_status = 'publish' AND p.post_type IN ('page','post') AND ( p.post_content LIKE '%[cec_partner_orgs%' OR m.meta_value LIKE '%cec_partner_orgs%' )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array_map( 'intval', $ids ) as $id ) {
			delete_post_meta( $id, '_elementor_element_cache' );
			clean_post_cache( $id );
			wp_update_post( array( 'ID' => $id ) );
		}
		if ( did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		/**
		 * A group's public listing changed; purge any other caches here.
		 */
		do_action( 'cec_partner_orgs_changed' );
	}

	/* ------------------------------------------------------------------
	 * 1.43.0: location = city + state (required) + ZIP (optional, private)
	 * ---------------------------------------------------------------- */

	/** US states, DC and Puerto Rico by postal code, in name order. */
	public static function states() {
		return array(
			'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
			'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida',
			'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana',
			'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
			'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
			'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire',
			'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota',
			'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'PR' => 'Puerto Rico',
			'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
			'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
			'WI' => 'Wisconsin', 'WY' => 'Wyoming',
		);
	}

	/** Cities already in use, so the same spelling is reused. */
	public static function cities() {
		global $wpdb;
		$vals = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->termmeta} WHERE meta_key = 'cec_loc_city' AND meta_value <> ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		natcasesort( $vals );
		return array_values( $vals );
	}

	/** "  columbus " → "Columbus"; mixed case ("McAllen") is kept as typed. */
	public static function clean_city( $city ) {
		$city = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $city ) ) );
		if ( '' !== $city && ( mb_strtolower( $city ) === $city || mb_strtoupper( $city ) === $city ) ) {
			$city = mb_convert_case( mb_strtolower( $city ), MB_CASE_TITLE );
		}
		foreach ( self::cities() as $known ) {
			if ( 0 === strcasecmp( $known, $city ) ) {
				return $known;
			}
		}
		return mb_substr( $city, 0, 60 );
	}

	/**
	 * Checks a posted location.
	 *
	 * @return array|WP_Error array( city, state, zip, region ).
	 */
	public static function clean_location( $city, $state, $zip ) {
		$city  = self::clean_city( $city );
		$state = strtoupper( trim( (string) $state ) );
		$zip   = preg_replace( '/\s+/', '', (string) $zip );
		if ( '' === $city || ! isset( self::states()[ $state ] ) ) {
			return new WP_Error( 'cec_location', __( 'Please enter a city and choose a state.', 'cec' ) );
		}
		if ( '' !== $zip && ! preg_match( '/^\d{5}(-\d{4})?$/', $zip ) ) {
			return new WP_Error( 'cec_zip', __( 'The ZIP code should be 5 digits, e.g. 43215.', 'cec' ) );
		}
		return array(
			'city'   => $city,
			'state'  => $state,
			'zip'    => substr( $zip, 0, 5 ),
			'region' => self::format_location( $city, $state ),
		);
	}

	/** "Columbus, OH"; the state's name when there is no city. */
	public static function format_location( $city, $state ) {
		$states = self::states();
		if ( '' !== $city && '' !== $state ) {
			return $city . ', ' . $state;
		}
		return '' !== $state ? ( $states[ $state ] ?? $state ) : $city;
	}

	/** Best guess at city and state from free text ("Indianapolis, Indiana", "Ohio"). */
	public static function parse_location( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		$out  = array( 'city' => '', 'state' => '' );
		if ( '' === $text ) {
			return $out;
		}
		$states = self::states();
		$names  = array_flip( array_map( 'strtolower', $states ) );
		$parts  = array_map( 'trim', explode( ',', $text ) );
		$last   = strtolower( (string) end( $parts ) );
		$code   = isset( $states[ strtoupper( $last ) ] ) ? strtoupper( $last ) : ( $names[ $last ] ?? '' );
		if ( '' === $code ) {
			$out['city'] = self::clean_city( $text );
			return $out;
		}
		array_pop( $parts );
		$out['state'] = $code;
		$out['city']  = $parts ? self::clean_city( implode( ', ', $parts ) ) : '';
		return $out;
	}

	/** <option>s for the state <select>. */
	public static function state_options( $selected ) {
		$html = '<option value="">' . esc_html__( 'Choose a state', 'cec' ) . '</option>';
		foreach ( self::states() as $code => $name ) {
			$html .= '<option value="' . esc_attr( $code ) . '"' . selected( $selected, $code, false ) . '>' . esc_html( $name ) . '</option>';
		}
		return $html;
	}

	/** <datalist> of cities already in use. */
	public static function cities_datalist( $id ) {
		$html = '<datalist id="' . esc_attr( $id ) . '">';
		foreach ( self::cities() as $c ) {
			$html .= '<option value="' . esc_attr( $c ) . '"></option>';
		}
		return $html . '</datalist>';
	}

	/** Groups "Columbus", "columbus " and "COLUMBUS" together. */
	public static function region_key( $region ) {
		return sanitize_title( trim( (string) $region ) );
	}

	/** Every city/region in use, for suggestions. */
	public static function regions() {
		global $wpdb;
		$vals = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->termmeta} WHERE meta_key = 'cec_region' AND meta_value <> ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( $vals as $v ) {
			$out[ self::region_key( $v ) ] = $v;
		}
		uasort( $out, 'strcasecmp' );
		return array_values( $out );
	}

	/** <datalist> of regions already in use, so people pick the same spelling. */
	public static function regions_datalist( $id ) {
		$html = '<datalist id="' . esc_attr( $id ) . '">';
		foreach ( self::regions() as $r ) {
			$html .= '<option value="' . esc_attr( $r ) . '"></option>';
		}
		return $html . '</datalist>';
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
			return wp_get_attachment_image( $p['logo_id'], 'medium_large', false, array( 'class' => $class . ( 'rect' === ( $p['shape'] ?? '' ) ? ' is-rect' : '' ), 'alt' => $alt, 'loading' => 'lazy' ) );
		}
		$class .= 'rect' === ( $p['shape'] ?? '' ) ? ' is-rect' : '';
		return '<img class="' . esc_attr( $class ) . '" src="' . esc_url( $p['logo'] ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" />';
	}

	/**
	 * More titles beyond the first (1.42.0): rows of title / year / producer.
	 * Returns array( clean rows, notes about years that weren't kept ).
	 */
	public static function clean_titles( $rows, $max = 7 ) {
		$out   = array();
		$notes = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$t = mb_substr( trim( sanitize_text_field( (string) ( $r['title'] ?? '' ) ) ), 0, 120 );
			$y = mb_substr( trim( sanitize_text_field( (string) ( $r['year'] ?? '' ) ) ), 0, 4 );
			$p = mb_substr( trim( sanitize_text_field( (string) ( $r['producer'] ?? '' ) ) ), 0, 120 );
			if ( '' === $t ) {
				continue;
			}
			if ( '' !== $y && ! preg_match( '/^(19|20|21)\d{2}$/', $y ) ) {
				/* translators: 1: year typed, 2: title */
				$notes[] = sprintf( __( 'The year "%1$s" for %2$s should be four digits, e.g. 2026; that title was saved without a year.', 'cec' ), $y, $t );
				$y       = '';
			}
			$out[] = array( 'title' => $t, 'year' => $y, 'producer' => $p );
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return array( $out, $notes );
	}

	/** Every title line for a titleholder, first title first. */
	public static function title_lines( $p ) {
		$lines = array();
		foreach ( array_merge( array( array( 'title' => $p['title'], 'year' => $p['year'], 'producer' => $p['producer'] ) ), (array) ( $p['titles_more'] ?? array() ) ) as $t ) {
			$line = self::title_line( $t );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return $lines;
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
		// A titleholder is one person: "Message me"; a group: "Join our" (1.38.0).
		$button = 'titleholder' === $p['kind']
			? array(
				'telegram' => __( 'Message me on Telegram', 'cec' ),
				'website'  => __( 'Visit my website', 'cec' ),
			)
			: array(
				'telegram' => __( 'Join our Telegram', 'cec' ),
				'website'  => __( 'Visit Website', 'cec' ),
			);
		$html  = class_exists( 'CEC_Org_Editor' ) ? CEC_Org_Editor::edit_button_html( $p ) : '';
		$html .= '<section class="cec-org-hero' . ( $p['logo'] || $p['logo_id'] ? ' has-image' : '' ) . ' is-' . esc_attr( $p['kind'] ) . '">';
		$html .= '<div class="cec-org-main">';
		if ( 'titleholder' === $p['kind'] ) {
			foreach ( self::title_lines( $p ) as $line ) {
				$html .= '<p class="cec-org-eyebrow">' . esc_html( $line ) . '</p>';
			}
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
		// 1.40.0: a shortcut down to the events listed on this page.
		$html .= '<p class="cec-org-jump"><a class="cec-org-btn" href="#cec-org-events">' . esc_html( 'titleholder' === $p['kind'] ? __( 'See my events', 'cec' ) : __( 'See our events', 'cec' ) ) . ' &darr;</a></p>';
		// 1.38.0: networks they have but share only on request.
		if ( $p['ask'] ) {
			$html .= '<ul class="cec-org-ask">';
			foreach ( $p['ask'] as $k ) {
				$html .= '<li>' . esc_html(
					'titleholder' === $p['kind']
						/* translators: %s: network, e.g. FetLife */
						? sprintf( __( 'Ask for my %s', 'cec' ), $labels[ $k ] )
						/* translators: %s: network, e.g. FetLife */
						: sprintf( __( 'Ask for our %s', 'cec' ), $labels[ $k ] )
				) . '</li>';
			}
			$html .= '</ul>';
		}
		$html .= '</div>';
		$image = self::image_html( $p, 'cec-org-image' );
		if ( $image ) {
			$html .= '<div class="cec-org-media' . ( 'rect' === $p['shape'] ? ' is-rect' : '' ) . '">' . $image . '</div>';
		}
		$html .= '</section>';
		if ( $p['mission'] ) {
			$heading = 'titleholder' === $p['kind'] ? __( 'Areas of Focus', 'cec' ) : __( 'Our Mission', 'cec' );
			$html   .= '<section class="cec-org-card"><h2>' . esc_html( $heading ) . '</h2>' . wpautop( esc_html( $p['mission'] ) ) . '</section>';
		}
		$html .= class_exists( 'CEC_Org_Editor' ) ? CEC_Org_Editor::gallery_html( $p ) : '';
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
	 *
	 * 1.44.0: limit="6" shuffle="yes" more="/organizations/" shows 6 cards,
	 * a different random 6 on every visit, then a "See More" button
	 * (more_label="…") to the full list. The shuffle runs in the browser, so
	 * it keeps changing even when the page is served from the CDN cache; the
	 * server-side order is shuffled too for uncached pages. limit is ignored
	 * with filter="yes", which needs every card.
	 */
	public static function shortcode_grid( $atts ) {
		$atts  = shortcode_atts(
			array(
				'kind'       => 'all',
				'button'     => __( 'Learn More', 'cec' ),
				'empty'      => '',
				'filter'     => 'no',
				'limit'      => 0,
				'shuffle'    => 'no',
				'more'       => '',
				'more_label' => __( 'See More', 'cec' ),
			),
			$atts,
			'cec_partner_orgs'
		);
		$kind  = in_array( $atts['kind'], self::KINDS, true ) ? $atts['kind'] : ( 'all' === $atts['kind'] ? '' : 'organization' );
		$list  = self::listed( $kind );
		$limit = 'yes' === $atts['filter'] ? 0 : max( 0, (int) $atts['limit'] );
		if ( 'yes' === $atts['shuffle'] && $list ) {
			shuffle( $list );
		}
		wp_enqueue_style( 'cec-orgs' );
		if ( ! $list ) {
			return $atts['empty'] ? '<p class="cec-org-empty">' . esc_html( $atts['empty'] ) . '</p>' : '';
		}
		// 1.43.0: filter="yes" filters by state, then city. Choosing a state
		// shows every listing in it, including state-wide ones without a city.
		$by_state = array();
		$missing  = false;
		foreach ( $list as $p ) {
			if ( '' === $p['state'] ) {
				$missing = true;
				continue;
			}
			if ( ! isset( $by_state[ $p['state'] ] ) ) {
				$by_state[ $p['state'] ] = array();
			}
			if ( '' !== $p['city'] ) {
				$by_state[ $p['state'] ][ self::region_key( $p['city'] ) ] = $p['city'];
			}
		}
		$names = self::states();
		uksort(
			$by_state,
			function ( $a, $b ) use ( $names ) {
				return strcasecmp( $names[ $a ] ?? $a, $names[ $b ] ?? $b );
			}
		);
		$cities = array();
		foreach ( $by_state as $code => $in_state ) {
			asort( $in_state, SORT_NATURAL | SORT_FLAG_CASE );
			$cities[ $code ] = array();
			foreach ( $in_state as $k => $label ) {
				$cities[ $code ][] = array( (string) $k, $label );
			}
		}
		$html = '';
		$uid  = 'cec-org-grid-' . wp_unique_id();
		if ( 'yes' === $atts['filter'] && $by_state ) {
			$html .= '<div class="cec-org-filter" hidden data-grid="' . esc_attr( $uid ) . '" data-cities="' . esc_attr( wp_json_encode( $cities ) ) . '"><label for="' . esc_attr( $uid ) . '-s">' . esc_html__( 'Filter by state', 'cec' ) . '</label> <select id="' . esc_attr( $uid ) . '-s"><option value="">' . esc_html__( 'All states', 'cec' ) . '</option>';
			foreach ( array_keys( $by_state ) as $code ) {
				$html .= '<option value="' . esc_attr( $code ) . '">' . esc_html( $names[ $code ] ?? $code ) . '</option>';
			}
			if ( $missing ) {
				$html .= '<option value="-">' . esc_html__( 'Location not listed', 'cec' ) . '</option>';
			}
			$html .= '</select> <label for="' . esc_attr( $uid ) . '-c" hidden>' . esc_html__( 'City', 'cec' ) . '</label> <select id="' . esc_attr( $uid ) . '-c" hidden><option value="">' . esc_html__( 'All cities', 'cec' ) . '</option></select> <span class="cec-org-filter-count" aria-live="polite"></span></div>';
			$html .= '<p class="cec-org-empty" hidden>' . esc_html__( 'Nothing listed there yet.', 'cec' ) . '</p>';
			$html .= "<script>( function () { var f = document.querySelector( '[data-grid=\"" . esc_js( $uid ) . "\"]' ); if ( ! f ) { return; } var sel = f.querySelectorAll( 'select' ), ss = sel[0], cs = sel[1], cl = f.querySelectorAll( 'label' )[1], c = f.querySelector( '.cec-org-filter-count' ), e = f.nextElementSibling, map = JSON.parse( f.getAttribute( 'data-cities' ) || '{}' ), g = null, all = " . wp_json_encode( __( 'All cities', 'cec' ) ) . ", shown = " . wp_json_encode( __( 'shown', 'cec' ) ) . "; f.hidden = false; function apply() { var st = ss.value, ci = cs.value, n = 0; g = g || document.getElementById( f.getAttribute( 'data-grid' ) ); g.querySelectorAll( '.cec-org-tile' ).forEach( function ( t ) { var show = ! st || ( '-' === st ? ! t.dataset.state : t.dataset.state === st && ( ! ci || t.dataset.city === ci ) ); t.hidden = ! show; if ( show ) { n++; } } ); c.textContent = st ? n + ' ' + shown : ''; e.hidden = n > 0; } ss.addEventListener( 'change', function () { var list = map[ ss.value ] || []; cs.innerHTML = ''; [ [ '', all ] ].concat( list ).forEach( function ( x ) { var o = document.createElement( 'option' ); o.value = x[0]; o.textContent = x[1]; cs.appendChild( o ); } ); cs.hidden = cl.hidden = list.length < 2; apply(); } ); cs.addEventListener( 'change', apply ); } )();</script>";
		}
		$more  = '' === trim( $atts['more'] ) ? '' : esc_url( $atts['more'] );
		$html .= '<div class="cec-org-grid' . ( $more ? ' has-more' : '' ) . '" id="' . esc_attr( $uid ) . '">';
		foreach ( array_values( $list ) as $i => $p ) {
			$html .= '<article class="cec-org-tile is-' . esc_attr( $p['kind'] ) . '" data-region="' . esc_attr( '' === $p['region'] ? '' : self::region_key( $p['region'] ) ) . '" data-state="' . esc_attr( $p['state'] ) . '" data-city="' . esc_attr( '' === $p['city'] ? '' : self::region_key( $p['city'] ) ) . '"' . ( $limit && $i >= $limit ? ' hidden' : '' ) . '>';
			$image = self::image_html( $p, 'cec-org-tile-image' );
			$html .= '<div class="cec-org-tile-head">' . ( $image ? '<span class="cec-org-tile-media">' . $image . '</span>' : '' ) . '<h3>' . esc_html( $p['name'] ) . '</h3></div>';
			if ( 'titleholder' === $p['kind'] ) {
				foreach ( self::title_lines( $p ) as $line ) {
					$html .= '<p class="cec-org-tile-title">' . esc_html( $line ) . '</p>';
				}
			}
			if ( '' !== $p['region'] ) {
				$html .= '<p class="cec-org-tile-region">' . esc_html( $p['region'] ) . '</p>';
			}
			// 1.36.0: the card text the group wrote for the card, then its
			// bullet points; without either, the start of the description.
			if ( '' !== $p['summary'] ) {
				$html .= '<p class="cec-org-tile-desc">' . esc_html( $p['summary'] ) . '</p>';
			}
			if ( $p['highlights'] ) {
				$html .= '<ul class="cec-org-tile-list">';
				foreach ( $p['highlights'] as $h ) {
					$html .= '<li>' . esc_html( $h ) . '</li>';
				}
				$html .= '</ul>';
			} elseif ( '' === $p['summary'] && $p['description'] ) {
				$html .= '<p class="cec-org-tile-desc">' . esc_html( wp_trim_words( $p['description'], 28 ) ) . '</p>';
			}
			$html .= '<a class="cec-org-tile-btn" href="' . esc_url( $p['url'] ) . '">' . esc_html( $atts['button'] ) . '<span class="screen-reader-text">: ' . esc_html( $p['name'] ) . '</span></a>';
			$html .= '</article>';
		}
		$html .= '</div>';
		// 1.44.0: reshuffle in the browser so a cached page still rotates,
		// then show the first `limit` cards.
		if ( $limit && 'yes' === $atts['shuffle'] && count( $list ) > 1 ) {
			$html .= "<script>( function () { var g = document.getElementById( '" . esc_js( $uid ) . "' ); if ( ! g ) { return; } var t = Array.prototype.slice.call( g.children ), i, j, x; for ( i = t.length - 1; i > 0; i-- ) { j = Math.floor( Math.random() * ( i + 1 ) ); x = t[ i ]; t[ i ] = t[ j ]; t[ j ] = x; } t.forEach( function ( el, n ) { el.hidden = n >= " . (int) $limit . '; g.appendChild( el ); } ); } )();</script>';
		}
		if ( $more ) {
			$what  = array(
				'organization' => __( 'organizations', 'cec' ),
				'titleholder'  => __( 'titleholders', 'cec' ),
			);
			$html .= '<p class="cec-org-more"><a class="cec-org-more-btn" href="' . $more . '">' . esc_html( $atts['more_label'] ) . '<span class="screen-reader-text"> ' . esc_html( $what[ $kind ] ?? __( 'listings', 'cec' ) ) . '</span></a></p>';
		}
		return $html;
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
			<th scope="row"><label for="cec_org_city"><?php esc_html_e( 'Location', 'cec' ); ?></label></th>
			<td>
				<input type="text" name="cec_org_city" id="cec_org_city" maxlength="60" list="cec_org_cities" placeholder="<?php esc_attr_e( 'City', 'cec' ); ?>" aria-label="<?php esc_attr_e( 'City', 'cec' ); ?>" value="<?php echo esc_attr( $p['city'] ); ?>" style="width:14em" /><?php echo self::cities_datalist( 'cec_org_cities' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<select name="cec_org_state" aria-label="<?php esc_attr_e( 'State', 'cec' ); ?>"><?php echo self::state_options( $p['state'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select>
				<input type="text" name="cec_org_zip" maxlength="10" inputmode="numeric" placeholder="<?php esc_attr_e( 'ZIP (optional)', 'cec' ); ?>" aria-label="<?php esc_attr_e( 'ZIP code (optional)', 'cec' ); ?>" value="<?php echo esc_attr( $p['zip'] ); ?>" style="width:7em" />
				<p class="description"><?php esc_html_e( 'City and state are required. They show on the card and drive the state and city filter on the listing pages. ZIP is private and never shown on the site.', 'cec' ); ?><?php if ( '' === $p['city'] && '' !== $p['region'] ) : ?> <strong><?php /* translators: %s: area entered before 1.43.0 */ echo esc_html( sprintf( __( 'Previously entered: "%s". Please add a city.', 'cec' ), $p['region'] ) ); ?></strong><?php endif; ?></p>
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
		// 1.43.0: city + state (+ private ZIP). Left as it was if incomplete.
		$loc = self::clean_location(
			isset( $_POST['cec_org_city'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_org_city'] ) ) : '',
			isset( $_POST['cec_org_state'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_org_state'] ) ) : '',
			isset( $_POST['cec_org_zip'] ) ? sanitize_text_field( wp_unslash( $_POST['cec_org_zip'] ) ) : ''
		);
		if ( ! is_wp_error( $loc ) ) {
			self::save_profile( $term_id, $loc );
		}
		// A logo picked from the media library by URL: remember its ID too.
		$logo = (string) get_term_meta( $term_id, 'cec_logo', true );
		update_term_meta( $term_id, 'cec_logo_id', $logo ? (int) attachment_url_to_postid( $logo ) : 0 );
		self::refresh_pages();
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
