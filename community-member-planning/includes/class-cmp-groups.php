<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Member ↔ group links (0.20.0): a member can say they belong to a Partner
 * Organization or are a Titleholder listed by the events plugin, so their
 * member profile shows on that group's page (/partner/<slug>/).
 *
 * - Members add or remove their own links on the Profile tab. A link a
 *   member adds themselves is always "Member"; the Organizer and Titleholder
 *   roles come only from a submission the site owner approved (the events
 *   plugin fires cec_partner_org_member_requested), and the member is told
 *   and can remove it.
 * - Linked members are shown on the group page only to signed-in full
 *   members (never the public, never signed-out visitors), and never to
 *   anyone they blocked or were blocked by. Member profiles stay private.
 * - Groups are read only through the events plugin's public API
 *   (CEC_Orgs::listed()/profile(), get_term_link()); nothing here writes to
 *   the events plugin's data.
 */
class CMP_Groups {

	const NONCE = 'cmp_groups';
	const ROLES = array( 'member', 'organizer', 'titleholder' );

	public static function init() {
		add_action( 'admin_post_cmp_group', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_cmp_group', array( 'CMP_Member_Area', 'redirect_to_login' ) );
		add_filter( 'cec_partner_org_page_extra', array( __CLASS__, 'page_html' ), 10, 2 );
		add_action( 'cec_partner_org_member_requested', array( __CLASS__, 'on_requested' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'on_delete_term' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache_signed_in' ) );
		add_filter( 'cec_partner_org_can_edit', array( __CLASS__, 'can_edit' ), 10, 3 );
		add_filter( 'cec_partner_org_event_ids', array( __CLASS__, 'titleholder_event_ids' ), 10, 2 );
		add_action( 'cec_partner_org_edit_form_fields', array( __CLASS__, 'admin_fields' ), 30 );
		add_action( 'edited_cec_partner_org', array( __CLASS__, 'admin_save' ), 30 );
	}

	/**
	 * 0.21.0: a full member linked to the group as Titleholder or Organizer
	 * may edit its public page (events plugin 1.35.0, "Edit this page").
	 */
	public static function can_edit( $allowed, $user_id, $term_id ) {
		if ( $allowed ) {
			return true;
		}
		$links = self::links( $user_id );
		return isset( $links[ $term_id ] ) && in_array( $links[ $term_id ]->role, array( 'organizer', 'titleholder' ), true ) && CMP_Access::is_member( $user_id );
	}

	/**
	 * Set a member's role directly, including a downgrade (site admin only).
	 */
	public static function set_role( $user_id, $term_id, $role, $source = 'admin' ) {
		global $wpdb;
		$role = in_array( $role, self::ROLES, true ) ? $role : 'member';
		if ( self::is_titleholder_listing( $term_id ) ) {
			$role = 'titleholder';
			self::only( $user_id, $term_id );
		}
		$have = self::links( $user_id );
		if ( isset( $have[ $term_id ] ) ) {
			return false !== $wpdb->update( self::t(), array( 'role' => $role, 'source' => $source ), array( 'user_id' => (int) $user_id, 'term_id' => (int) $term_id ) );
		}
		return self::link( $user_id, $term_id, $role, $source );
	}

	/* ------------------------------------------------------------------
	 * wp-admin: Events → Partners & Titleholders → Edit (0.21.0)
	 * ---------------------------------------------------------------- */

	public static function admin_fields( $term ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_nonce_field( 'cmp_group_admin_' . $term->term_id, 'cmp_group_admin_nonce' );
		$rows = self::members_of( $term->term_id );
		?>
		<tr class="form-field"><th scope="row"><?php esc_html_e( 'Linked members', 'cmp' ); ?></th><td>
			<p class="description"><?php esc_html_e( 'Members linked to this group (Community Member Planning). Titleholders and Organizers can edit the group\'s page with "Edit this page"; Members can\'t. Members can remove their own link at any time.', 'cmp' ); ?></p>
			<?php if ( $rows ) : ?>
				<table class="widefat striped" style="max-width:40rem"><tbody>
				<?php foreach ( $rows as $r ) : ?>
					<?php $u = get_userdata( (int) $r->user_id ); ?>
					<tr><td><?php echo esc_html( $u ? $u->display_name . ' (' . $u->user_login . ')' : '#' . $r->user_id ); ?></td>
						<td><select name="cmp_group_role[<?php echo (int) $r->user_id; ?>]" aria-label="<?php esc_attr_e( 'Role', 'cmp' ); ?>">
							<?php foreach ( self::ROLES as $role ) : ?><option value="<?php echo esc_attr( $role ); ?>" <?php selected( $r->role, $role ); ?>><?php echo esc_html( self::role_label( $role ) ); ?></option><?php endforeach; ?>
							<option value="remove"><?php esc_html_e( 'Remove link', 'cmp' ); ?></option>
						</select></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
			<p><label><?php esc_html_e( 'Link a member (username or email)', 'cmp' ); ?> <input type="text" name="cmp_group_add" style="width:16rem" /></label>
				<select name="cmp_group_add_role" aria-label="<?php esc_attr_e( 'Role', 'cmp' ); ?>"><?php foreach ( array_reverse( self::ROLES ) as $role ) : ?><option value="<?php echo esc_attr( $role ); ?>"><?php echo esc_html( self::role_label( $role ) ); ?></option><?php endforeach; ?></select></p>
			<p class="description"><?php esc_html_e( 'The member is told when you link them.', 'cmp' ); ?></p>
		</td></tr>
		<?php
	}

	public static function admin_save( $term_id ) {
		if ( ! isset( $_POST['cmp_group_admin_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cmp_group_admin_nonce'] ) ), 'cmp_group_admin_' . $term_id ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$roles = isset( $_POST['cmp_group_role'] ) && is_array( $_POST['cmp_group_role'] ) ? wp_unslash( $_POST['cmp_group_role'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked below.
		foreach ( $roles as $uid => $role ) {
			$uid  = absint( $uid );
			$role = sanitize_key( $role );
			if ( 'remove' === $role ) {
				self::unlink( $uid, $term_id );
			} elseif ( in_array( $role, self::ROLES, true ) ) {
				self::set_role( $uid, $term_id, $role );
			}
		}
		$ref = isset( $_POST['cmp_group_add'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['cmp_group_add'] ) ) ) : '';
		if ( '' !== $ref ) {
			$user = is_email( $ref ) ? get_user_by( 'email', $ref ) : get_user_by( 'login', $ref );
			$role = isset( $_POST['cmp_group_add_role'] ) ? sanitize_key( wp_unslash( $_POST['cmp_group_add_role'] ) ) : 'member';
			$term = get_term( $term_id, 'cec_partner_org' );
			if ( $user && $term && ! is_wp_error( $term ) && self::set_role( $user->ID, $term_id, $role ) ) {
				CMP_Notifications::add(
					$user->ID,
					'account',
					/* translators: 1: group name, 2: role */
					sprintf( __( 'Your member profile is now linked to %1$s as %2$s. Members can see it on that page; you can remove the link on your Profile tab.', 'cmp' ), $term->name, self::role_label( in_array( $role, self::ROLES, true ) ? $role : 'member' ) ),
					CMP_Profiles::url( '', 'cmp-groups' )
				);
			}
		}
	}

	/**
	 * 0.20.1: a group page seen signed in shows the member list, so it must
	 * never be kept by the browser. Signed-out copies say "public,
	 * max-age=300", so a page opened before signing in could be shown again
	 * without the list.
	 */
	public static function no_cache_signed_in() {
		if ( is_user_logged_in() && is_tax( 'cec_partner_org' ) ) {
			nocache_headers();
		}
	}

	public static function notices() {
		return array(
			'gr_added'   => array( 'success', __( 'Linked. Members can now see your profile on that group\'s page.', 'cmp' ) ),
			'gr_removed' => array( 'success', __( 'Link removed.', 'cmp' ) ),
			'gr_gone'    => array( 'error', __( 'That group isn\'t available.', 'cmp' ) ),
		);
	}

	/** The events plugin is active and new enough to have groups. */
	public static function available() {
		return class_exists( 'CEC_Orgs' ) && taxonomy_exists( 'cec_partner_org' );
	}

	public static function role_label( $role ) {
		$labels = array(
			'member'      => __( 'Member', 'cmp' ),
			'organizer'   => __( 'Organizer', 'cmp' ),
			'titleholder' => __( 'Titleholder', 'cmp' ),
		);
		return isset( $labels[ $role ] ) ? $labels[ $role ] : $labels['member'];
	}

	/* ------------------------------------------------------------------
	 * Data
	 * ---------------------------------------------------------------- */

	private static function t() {
		return CMP_Install::table( 'group_links' );
	}

	/** term_id => row for one member. */
	public static function links( $user_id ) {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE user_id = %d ORDER BY created_at ASC', $user_id ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out[ (int) $row->term_id ] = $row;
		}
		return $out;
	}

	public static function members_of( $term_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . self::t() . " WHERE term_id = %d ORDER BY FIELD(role,'titleholder','organizer','member'), created_at ASC", $term_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Add or update a link. A stronger role (organizer, titleholder) replaces
	 * "member"; a member's own "add" never downgrades an approved role.
	 */
	public static function link( $user_id, $term_id, $role = 'member', $source = 'member' ) {
		global $wpdb;
		$role = in_array( $role, self::ROLES, true ) ? $role : 'member';
		if ( self::is_titleholder_listing( $term_id ) ) {
			// One person per titleholder listing, and never by self-linking.
			if ( 'member' === $source ) {
				return false;
			}
			$role = 'titleholder';
			self::only( $user_id, $term_id );
		}
		$have = self::links( $user_id );
		if ( isset( $have[ $term_id ] ) ) {
			if ( 'member' === $role || $have[ $term_id ]->role === $role ) {
				return false;
			}
			return (bool) $wpdb->update( self::t(), array( 'role' => $role, 'source' => $source ), array( 'user_id' => $user_id, 'term_id' => $term_id ) );
		}
		return (bool) $wpdb->insert(
			self::t(),
			array(
				'user_id'    => (int) $user_id,
				'term_id'    => (int) $term_id,
				'role'       => $role,
				'source'     => $source,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	public static function unlink( $user_id, $term_id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::t(), array( 'user_id' => (int) $user_id, 'term_id' => (int) $term_id ), array( '%d', '%d' ) );
	}

	/**
	 * A titleholder listing is one person (0.22.0): it has exactly one
	 * linked member, the Titleholder.
	 */
	public static function is_titleholder_listing( $term_id ) {
		if ( ! self::available() ) {
			return false;
		}
		$p = CEC_Orgs::profile( (int) $term_id );
		return $p && 'titleholder' === $p['kind'];
	}

	/** The titleholder listing this member is the Titleholder of, or 0. */
	public static function titleholder_term( $user_id ) {
		foreach ( self::links( $user_id ) as $term_id => $row ) {
			if ( 'titleholder' === $row->role && self::is_titleholder_listing( $term_id ) ) {
				return (int) $term_id;
			}
		}
		return 0;
	}

	/**
	 * Events the titleholder is going to or interested in, as this visitor
	 * may see them (0.23.0): "Public" ones for everyone, "All members" ones
	 * for signed-in members too. The events plugin adds them to the page's
	 * Upcoming Events.
	 */
	public static function titleholder_event_ids( $ids, $profile ) {
		if ( empty( $profile['id'] ) || 'titleholder' !== ( $profile['kind'] ?? '' ) ) {
			return $ids;
		}
		$rows = self::members_of( (int) $profile['id'] );
		if ( ! $rows || ! CMP_Access::is_member( (int) $rows[0]->user_id ) ) {
			return $ids;
		}
		$viewer = get_current_user_id();
		$member = $viewer && CMP_Access::is_member( $viewer );
		// Only what visitors may see, even for the titleholder themselves:
		// the page shows the public view, never their private plans.
		foreach ( CMP_Calendar::entries( (int) $rows[0]->user_id, $viewer, true, 50 ) as $e ) {
			if ( 'public' === $e->audience || ( 'members' === $e->audience && $member ) ) {
				$ids[] = (int) $e->event_id;
			}
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/** Removes everyone else from a titleholder listing. */
	private static function only( $user_id, $term_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t() . ' WHERE term_id = %d AND user_id <> %d', $term_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** A group that may be linked: exists in the events plugin. */
	private static function group( $term_id ) {
		if ( ! self::available() || ! $term_id ) {
			return null;
		}
		$term = get_term( (int) $term_id, 'cec_partner_org' );
		return ( $term && ! is_wp_error( $term ) ) ? $term : null;
	}

	/** Groups a member can pick: the ones listed on the site, by name. */
	public static function choices() {
		if ( ! self::available() ) {
			return array();
		}
		$out = array();
		foreach ( CEC_Orgs::listed( 'organization' ) as $p ) { // Titleholder listings are one person (0.22.0).
			$out[ (int) $p['id'] ] = $p['name'];
		}
		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ---------------------------------------------------------------- */

	public static function handle() {
		$user_id = get_current_user_id();
		if ( ! CMP_Access::is_member( $user_id ) ) {
			wp_safe_redirect( CMP_Settings::member_page_url() );
			exit;
		}
		if ( ! isset( $_POST['_cmp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cmp_nonce'] ) ), self::NONCE ) ) {
			wp_safe_redirect( CMP_Profiles::url( 'expired', 'cmp-groups' ) );
			exit;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$term = isset( $_POST['group'] ) ? absint( $_POST['group'] ) : 0;
		// phpcs:enable
		if ( 'remove' === $do ) {
			self::unlink( $user_id, $term );
			wp_safe_redirect( CMP_Profiles::url( 'gr_removed', 'cmp-groups' ) );
			exit;
		}
		if ( 'add' === $do && self::group( $term ) && isset( self::choices()[ $term ] ) ) {
			self::link( $user_id, $term, 'member', 'member' );
			wp_safe_redirect( CMP_Profiles::url( 'gr_added', 'cmp-groups' ) );
			exit;
		}
		wp_safe_redirect( CMP_Profiles::url( 'gr_gone', 'cmp-groups' ) );
		exit;
	}

	/**
	 * An approved organization or titleholder submission named this member
	 * (events plugin 1.34.0). Link them with that role and tell them.
	 */
	public static function on_requested( $user_id, $term_id, $role ) {
		$user_id = (int) $user_id;
		$term    = self::group( $term_id );
		if ( ! $term || ! get_userdata( $user_id ) ) {
			return;
		}
		$role = in_array( $role, array( 'organizer', 'titleholder' ), true ) ? $role : 'member';
		if ( self::link( $user_id, (int) $term->term_id, $role, 'submission' ) ) {
			CMP_Notifications::add(
				$user_id,
				'account',
				/* translators: 1: group name, 2: role */
				sprintf( __( 'Your member profile is now linked to %1$s as %2$s. Members can see it on that page; you can remove the link on your Profile tab.', 'cmp' ), $term->name, self::role_label( $role ) ),
				CMP_Profiles::url( '', 'cmp-groups' )
			);
		}
	}

	public static function on_delete_term( $term_id, $tt_id, $taxonomy ) {
		global $wpdb;
		if ( 'cec_partner_org' === $taxonomy ) {
			$wpdb->delete( self::t(), array( 'term_id' => (int) $term_id ), array( '%d' ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	private static function form_open( $do, $class = 'cmp-form cmp-inline-form' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '"><input type="hidden" name="action" value="cmp_group" /><input type="hidden" name="do" value="' . esc_attr( $do ) . '" /><input type="hidden" name="_cmp_nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" />';
	}

	/** Profile tab panel: your groups, add one, remove one. */
	public static function settings_html( $user_id ) {
		if ( ! self::available() ) {
			return '';
		}
		$links   = self::links( $user_id );
		$choices = self::choices();
		$html    = '<section class="cmp-panel" id="cmp-groups"><h3 class="cmp-panel-title">' . esc_html__( 'Groups and titles', 'cmp' ) . '</h3>';
		$html   .= '<p>' . esc_html__( 'Link your profile to an organization or titleholder listed on the site. Signed-in members then see your profile on that group\'s page. The public never does.', 'cmp' ) . '</p>';
		if ( $links ) {
			$html .= '<ul class="cmp-group-list">';
			foreach ( $links as $term_id => $row ) {
				$term = self::group( $term_id );
				if ( ! $term ) {
					continue;
				}
				$link  = get_term_link( $term );
				$html .= '<li><a href="' . esc_url( is_wp_error( $link ) ? '' : $link ) . '">' . esc_html( $term->name ) . '</a> <span class="cmp-muted">' . esc_html( self::role_label( $row->role ) ) . '</span> ';
				if ( class_exists( 'CEC_Org_Editor' ) && in_array( $row->role, array( 'organizer', 'titleholder' ), true ) ) {
					$html .= '<a class="cmp-btn cmp-btn-small" href="' . esc_url( CEC_Org_Editor::edit_url( $term ) ) . '">' . esc_html__( 'Edit page & card', 'cmp' ) . '<span class="screen-reader-text"> ' . esc_html( $term->name ) . '</span></a> ';
				}
				$html .= self::form_open( 'remove' ) . '<input type="hidden" name="group" value="' . (int) $term_id . '" /><button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Remove', 'cmp' ) . '<span class="screen-reader-text"> ' . esc_html( $term->name ) . '</span></button></form></li>';
			}
			$html .= '</ul>';
		}
		$open = array_diff_key( $choices, $links );
		if ( $open ) {
			$html .= self::form_open( 'add', 'cmp-form cmp-group-add' ) . '<label for="cmp-group-pick">' . esc_html__( 'Add a group', 'cmp' ) . '</label> <select id="cmp-group-pick" name="group">';
			foreach ( $open as $id => $name ) {
				$html .= '<option value="' . (int) $id . '">' . esc_html( $name ) . '</option>';
			}
			$html .= '</select> <button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Link my profile', 'cmp' ) . '</button></form>';
		}
		$html .= '<p class="cmp-muted">' . esc_html__( 'Organizer and Titleholder are added when the site approves a group\'s submission that names you.', 'cmp' ) . '</p>';
		// 0.23.1: where to send a new organization or title.
		$submit = get_page_by_path( 'submit-organization' );
		if ( $submit && 'publish' === $submit->post_status ) {
			$html .= '<p>' . esc_html__( 'Run a group or hold a title?', 'cmp' ) . ' <a class="cmp-btn cmp-btn-small" href="' . esc_url( get_permalink( $submit ) ) . '">' . esc_html__( 'Submit it to be listed', 'cmp' ) . '</a></p>';
		}
		return $html . '</section>';
	}

	/** "Groups" line on a member profile card, for viewers who can see the profile. */
	public static function profile_html( $owner_id ) {
		if ( ! self::available() ) {
			return '';
		}
		$items  = array();
		$titles = '';
		foreach ( self::links( $owner_id ) as $term_id => $row ) {
			$term = self::group( $term_id );
			if ( ! $term ) {
				continue;
			}
			$link = get_term_link( $term );
			// 0.22.0: the titleholder's own listing gets a badge of its own.
			if ( 'titleholder' === $row->role && self::is_titleholder_listing( $term_id ) ) {
				$p       = CEC_Orgs::profile( $term );
				$line    = CEC_Orgs::title_line( $p );
				$titles .= '<div class="cmp-pv-title"><span class="cmp-pv-title-label">' . esc_html__( 'Titleholder', 'cmp' ) . '</span> <strong>' . esc_html( $line ? $line : $term->name ) . '</strong> <a href="' . esc_url( is_wp_error( $link ) ? '' : $link ) . '">' . esc_html__( 'Titleholder page', 'cmp' ) . '<span class="screen-reader-text">: ' . esc_html( $term->name ) . '</span></a></div>';
				continue;
			}
			$label   = 'member' === $row->role ? $term->name : sprintf( '%1$s (%2$s)', $term->name, self::role_label( $row->role ) );
			$items[] = '<a href="' . esc_url( is_wp_error( $link ) ? '' : $link ) . '">' . esc_html( $label ) . '</a>';
		}
		return $titles . ( $items ? '<p class="cmp-pv-groups"><strong>' . esc_html__( 'Groups:', 'cmp' ) . '</strong> ' . implode( ', ', $items ) . '</p>' : '' );
	}

	/**
	 * Linked members on the group's page (filter cec_partner_org_page_extra).
	 * Signed-in full members only.
	 */
	public static function page_html( $html, $profile ) {
		$viewer = get_current_user_id();
		if ( ! $viewer || ! CMP_Access::is_member( $viewer ) || empty( $profile['id'] ) ) {
			return $html;
		}
		if ( 'titleholder' === ( $profile['kind'] ?? '' ) ) {
			return $html . self::titleholder_profile_html( $profile, $viewer );
		}
		$cards = '';
		$mine  = null;
		foreach ( self::members_of( (int) $profile['id'] ) as $row ) {
			$uid = (int) $row->user_id;
			if ( ! CMP_Access::is_member( $uid ) || ( $uid !== $viewer && CMP_Messages::is_blocked( $viewer, $uid ) ) ) {
				continue;
			}
			$user = get_userdata( $uid );
			if ( ! $user ) {
				continue;
			}
			$img    = CMP_Profiles::can_view( 'avatar', $uid, $viewer ) ? CMP_Profile_Images::get( $uid, 'avatar', false ) : null;
			$av     = $img ? CMP_Profile_Images::img_html( $uid, 'avatar', $img ) : esc_html( mb_strtoupper( mb_substr( $user->display_name, 0, 1 ) ) );
			$you  = $uid === $viewer;
			$card = '<li class="cec-org-member' . ( $you ? ' is-you' : '' ) . '"><a href="' . esc_url( CMP_Profiles::member_url( $uid ) ) . '"><span class="cec-org-member-av" aria-hidden="true">' . $av . '</span><span class="cec-org-member-name">' . esc_html( $user->display_name ) . ( $you ? ' ' . esc_html__( '(you)', 'cmp' ) : '' ) . '</span><span class="cec-org-member-role">' . esc_html( self::role_label( $row->role ) ) . '</span></a></li>';
			// Your own card comes first (0.20.1).
			if ( $you ) {
				$mine  = $row;
				$cards = $card . $cards;
			} else {
				$cards .= $card;
			}
		}
		$join = CMP_Profiles::url( '', 'cmp-groups' );
		$out  = '<section class="cec-org-card cec-org-members" aria-labelledby="cec-org-members-title"><h2 id="cec-org-members-title">' . esc_html__( 'Members on COL&B', 'cmp' ) . '</h2>';
		$out .= '<p>' . esc_html__( 'Only signed-in members see this list.', 'cmp' ) . ' ';
		/* translators: %s: role */
		$out .= esc_html( $mine ? sprintf( __( 'Your profile is listed here as %s.', 'cmp' ), self::role_label( $mine->role ) ) : __( 'Your profile isn\'t linked to this group.', 'cmp' ) ) . '</p>';
		$out .= $cards ? '<ul class="cec-org-member-list">' . $cards . '</ul>' : '<p>' . esc_html__( 'No members have linked their profile yet.', 'cmp' ) . '</p>';
		$out .= '<p><a class="cec-org-btn" href="' . esc_url( $join ) . '">' . esc_html( $mine ? __( 'Manage my groups', 'cmp' ) : __( 'Link my profile', 'cmp' ) ) . '</a></p></section>';
		return $html . $out;
	}

	/**
	 * "My COL&B profile" on a titleholder's page (0.22.0): the one member
	 * who is that titleholder, for signed-in members only.
	 */
	private static function titleholder_profile_html( $profile, $viewer ) {
		$rows = self::members_of( (int) $profile['id'] );
		$row  = $rows ? $rows[0] : null;
		$uid  = $row ? (int) $row->user_id : 0;
		if ( ! $uid || ! CMP_Access::is_member( $uid ) || ( $uid !== $viewer && CMP_Messages::is_blocked( $viewer, $uid ) ) ) {
			return '';
		}
		$user = get_userdata( $uid );
		if ( ! $user ) {
			return '';
		}
		$img  = CMP_Profiles::can_view( 'avatar', $uid, $viewer ) ? CMP_Profile_Images::get( $uid, 'avatar', false ) : null;
		$av   = $img ? CMP_Profile_Images::img_html( $uid, 'avatar', $img ) : esc_html( mb_strtoupper( mb_substr( $user->display_name, 0, 1 ) ) );
		$you  = $uid === $viewer;
		$out  = '<section class="cec-org-card cec-org-members is-titleholder" aria-labelledby="cec-org-members-title"><h2 id="cec-org-members-title">' . esc_html__( 'My COL&B profile', 'cmp' ) . '</h2>';
		$out .= '<p>' . esc_html( $you ? __( 'This is your member profile. Only signed-in members see this.', 'cmp' ) : __( 'Only signed-in members see this.', 'cmp' ) ) . '</p>';
		$out .= '<ul class="cec-org-member-list"><li class="cec-org-member' . ( $you ? ' is-you' : '' ) . '"><a href="' . esc_url( CMP_Profiles::member_url( $uid ) ) . '"><span class="cec-org-member-av" aria-hidden="true">' . $av . '</span><span class="cec-org-member-name">' . esc_html( $user->display_name ) . '</span><span class="cec-org-member-role">' . esc_html__( 'View profile', 'cmp' ) . '</span></a></li></ul>';
		return $out . '</section>';
	}

	/* ------------------------------------------------------------------
	 * Privacy
	 * ---------------------------------------------------------------- */

	public static function export_rows( $user_id ) {
		$rows = array();
		foreach ( self::links( $user_id ) as $term_id => $row ) {
			$term   = self::available() ? get_term( $term_id, 'cec_partner_org' ) : null;
			$rows[] = array(
				'name'  => __( 'Linked group', 'cmp' ),
				'value' => ( $term && ! is_wp_error( $term ) ? $term->name : '#' . $term_id ) . ' (' . self::role_label( $row->role ) . ')',
			);
		}
		return $rows;
	}

	public static function erase( $user_id ) {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t() . ' WHERE user_id = %d', $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
