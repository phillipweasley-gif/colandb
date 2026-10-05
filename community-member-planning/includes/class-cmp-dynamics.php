<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dynamics between two members (0.6.0; owner, 2026-10-04: "The
 * relationships and power dynamics that FetLife offers will be very
 * important for the homework and chastity tasks"). Modeled on FetLife's
 * Relationships / D/s Relationships and Chaster's keyholder.
 *
 * A dynamic is a type (a pair of sides, e.g. Dominant / submissive, or an
 * equal type such as Partners) between a proposer and a partner.
 * - Nothing happens until the partner accepts (status pending → active).
 * - Either member can end an active dynamic at any time, with no approval;
 *   it takes effect immediately.
 * - Shown on both profiles only while active and both members leave "Show
 *   on my profile" on. Active partners count as connections
 *   (cmp_are_connected), so "My connections" visibility now means something.
 *
 * 0.16.0 (owner, 2026-10-05: "I should be able to select multiple. I should
 * also be able to have the chastity option regardless of what the dynamic
 * is providing the other party agrees to it. Same with Homework."):
 * - One invitation can hold several types (rows sharing a group_id) and is
 *   accepted or declined as a whole.
 * - Chastity and homework are add-ons between the pair (dynamic_addons),
 *   each with its own leading member chosen by whoever asks for it, and
 *   each agreed to by the other. They need at least one active dynamic
 *   between the two; when the last one ends, the add-ons end with it.
 * - Either member can ask to add one later (the other accepts) and either
 *   can turn one off at any time.
 * - Keyholder / chastity wearer always comes with chastity, the Keyholder
 *   holding the key.
 * - lead_can_direct( $lead, $member, 'homework'|'chastity' ) is what
 *   homework and chastity check.
 */
class CMP_Dynamics {

	const TAB          = 'dynamics';
	const NONCE        = 'cmp_dynamics';
	const MAX_PENDING  = 10;
	const MAX_MESSAGE  = 500;
	const STATUSES     = array( 'pending', 'active', 'declined', 'withdrawn', 'ended' );
	const ADDONS       = array( 'chastity', 'homework' );
	const MIGRATED     = 'cmp_dyn_addons_migrated';

	public static function init() {
		foreach ( array( 'propose', 'respond', 'withdraw', 'end', 'show', 'addon' ) as $a ) {
			add_action( 'admin_post_cmp_dyn_' . $a, array( __CLASS__, 'handle_' . $a ) );
			add_action( 'admin_post_nopriv_cmp_dyn_' . $a, array( 'CMP_Member_Area', 'redirect_to_login' ) );
		}
		add_filter( 'cmp_are_connected', array( __CLASS__, 'connected' ), 10, 3 );
	}

	/**
	 * key => array( label, a, b, lead ). a = the leading side for directed
	 * types; b = null for equal types (both members are "a").
	 */
	public static function types() {
		$t = function ( $label, $a, $b = null ) {
			return array( 'label' => $label, 'a' => $a, 'b' => $b, 'lead' => null !== $b );
		};
		return array(
			'keyholder' => $t( __( 'Keyholder / chastity wearer', 'cmp' ), __( 'Keyholder', 'cmp' ), __( 'chastity wearer', 'cmp' ) ),
			'dom_sub'   => $t( __( 'Dominant / submissive', 'cmp' ), __( 'Dominant', 'cmp' ), __( 'submissive', 'cmp' ) ),
			'daddy_boy' => $t( __( 'Daddy / boy', 'cmp' ), __( 'Daddy', 'cmp' ), __( 'boy', 'cmp' ) ),
			'daddy_girl' => $t( __( 'Daddy / girl', 'cmp' ), __( 'Daddy', 'cmp' ), __( 'girl', 'cmp' ) ),
			'mommy_boy' => $t( __( 'Mommy / boy', 'cmp' ), __( 'Mommy', 'cmp' ), __( 'boy', 'cmp' ) ),
			'owner'     => $t( __( 'Owner / property', 'cmp' ), __( 'Owner', 'cmp' ), __( 'property', 'cmp' ) ),
			'master'    => $t( __( 'Master / slave', 'cmp' ), __( 'Master', 'cmp' ), __( 'slave', 'cmp' ) ),
			'handler'   => $t( __( 'Handler / pup', 'cmp' ), __( 'Handler', 'cmp' ), __( 'pup', 'cmp' ) ),
			'trainer'   => $t( __( 'Trainer / trainee', 'cmp' ), __( 'Trainer', 'cmp' ), __( 'trainee', 'cmp' ) ),
			'mentor'    => $t( __( 'Mentor / mentee', 'cmp' ), __( 'Mentor', 'cmp' ), __( 'mentee', 'cmp' ) ),
			'top_bottom' => $t( __( 'Top / bottom', 'cmp' ), __( 'Top', 'cmp' ), __( 'bottom', 'cmp' ) ),
			'caregiver' => $t( __( 'Caregiver / little', 'cmp' ), __( 'Caregiver', 'cmp' ), __( 'little', 'cmp' ) ),
			'partners'  => $t( __( 'Partners', 'cmp' ), __( 'Partner', 'cmp' ) ),
			'married'   => $t( __( 'Married', 'cmp' ), __( 'Spouse', 'cmp' ) ),
			'play'      => $t( __( 'Play partners', 'cmp' ), __( 'Play partner', 'cmp' ) ),
			'family'    => $t( __( 'Leather family', 'cmp' ), __( 'Leather family', 'cmp' ) ),
			'friends'   => $t( __( 'Friends', 'cmp' ), __( 'Friend', 'cmp' ) ),
		);
	}

	public static function url( $notice = '' ) {
		$url = add_query_arg( 'cmp_tab', self::TAB, CMP_Settings::member_page_url() );
		return $notice ? add_query_arg( 'cmp_notice', $notice, $url ) : $url;
	}

	public static function notices() {
		return array(
			'dyn_sent'        => array( 'success', __( 'Invitation sent. You\'ll be notified when they answer.', 'cmp' ) ),
			'dyn_accepted'    => array( 'success', __( 'Accepted. It\'s now active for both of you.', 'cmp' ) ),
			'dyn_declined'    => array( 'success', __( 'Invitation declined.', 'cmp' ) ),
			'dyn_withdrawn'   => array( 'success', __( 'Invitation withdrawn.', 'cmp' ) ),
			'dyn_ended'       => array( 'success', __( 'Dynamic ended. It no longer gives either of you any permissions.', 'cmp' ) ),
			'dyn_saved'       => array( 'success', __( 'Saved.', 'cmp' ) ),
			'dyn_invalid'     => array( 'error', __( 'That invitation couldn\'t be sent. Choose at least one type and your side in each, and keep the message under 500 characters.', 'cmp' ) ),
			'dyn_duplicate'   => array( 'error', __( 'You already have one of these dynamics (or an invitation for it) with this member.', 'cmp' ) ),
			'dyn_too_many'    => array( 'error', __( 'You have 10 invitations waiting for an answer. Withdraw one before sending another.', 'cmp' ) ),
			'dyn_gone'        => array( 'error', __( 'That dynamic has changed or is no longer available.', 'cmp' ) ),
			'dyn_addon_sent'  => array( 'success', __( 'Request sent. It starts once they accept.', 'cmp' ) ),
			'dyn_addon_on'    => array( 'success', __( 'Accepted. It\'s now on for both of you.', 'cmp' ) ),
			'dyn_addon_no'    => array( 'success', __( 'Request declined.', 'cmp' ) ),
			'dyn_addon_off'   => array( 'success', __( 'Turned off. It no longer gives either of you any permissions.', 'cmp' ) ),
			'dyn_addon_taken' => array( 'error', __( 'That\'s already on, or already asked for.', 'cmp' ) ),
		);
	}

	/** kind => array( name, "I lead" choice, "they lead" choice ). */
	public static function addon_labels() {
		return array(
			'chastity' => array( __( 'Chastity', 'cmp' ), __( 'I hold the key', 'cmp' ), __( 'They hold the key', 'cmp' ) ),
			'homework' => array( __( 'Homework', 'cmp' ), __( 'I set it', 'cmp' ), __( 'They set it', 'cmp' ) ),
		);
	}

	/* ------------------------------------------------------------------
	 * Reading
	 * ---------------------------------------------------------------- */

	public static function get( $id ) {
		global $wpdb;
		$t = CMP_Install::table( 'dynamics' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** All of a member's dynamics (both directions), newest first. */
	public static function for_user( $user_id, $statuses = null ) {
		global $wpdb;
		$t    = CMP_Install::table( 'dynamics' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE proposer_id = %d OR partner_id = %d ORDER BY id DESC", $user_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null === $statuses ? $rows : self::only( $rows, $statuses );
	}

	private static function only( $rows, $statuses ) {
		return array_values(
			array_filter(
				$rows,
				function ( $r ) use ( $statuses ) {
					return in_array( $r->status, (array) $statuses, true );
				}
			)
		);
	}

	/** The invitation a dynamic belongs to (its own id for single ones). */
	public static function group_key( $dyn ) {
		return (int) $dyn->group_id ? (int) $dyn->group_id : (int) $dyn->id;
	}

	/** The dynamics sent together in one invitation, oldest first. */
	public static function group_rows( $key ) {
		global $wpdb;
		$t = CMP_Install::table( 'dynamics' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d OR group_id = %d ORDER BY id", $key, $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function other( $dyn, $user_id ) {
		return (int) $dyn->proposer_id === (int) $user_id ? (int) $dyn->partner_id : (int) $dyn->proposer_id;
	}

	/** The member on the leading side, or 0 for an equal type. */
	public static function lead_id( $dyn ) {
		$types = self::types();
		if ( empty( $types[ $dyn->type ]['lead'] ) ) {
			return 0;
		}
		return 'a' === $dyn->proposer_side ? (int) $dyn->proposer_id : (int) $dyn->partner_id;
	}

	/** "Keyholder", "chastity wearer", "Partner" … for this member in this dynamic. */
	public static function side_label( $dyn, $user_id ) {
		$type = self::types()[ $dyn->type ];
		if ( ! $type['lead'] ) {
			return $type['a'];
		}
		return self::lead_id( $dyn ) === (int) $user_id ? $type['a'] : $type['b'];
	}

	public static function active_between( $a, $b ) {
		global $wpdb;
		$t = CMP_Install::table( 'dynamics' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = 'active' AND ( ( proposer_id = %d AND partner_id = %d ) OR ( proposer_id = %d AND partner_id = %d ) ) ORDER BY id", $a, $b, $b, $a ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ------------------------------------------------------------------
	 * Add-ons: chastity and homework (0.16.0)
	 * ---------------------------------------------------------------- */

	private static function pair( $a, $b ) {
		$a = (int) $a;
		$b = (int) $b;
		return $a < $b ? array( $a, $b ) : array( $b, $a );
	}

	public static function get_addon( $id ) {
		global $wpdb;
		$t = CMP_Install::table( 'dynamic_addons' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function addons_between( $a, $b, $statuses = array( 'active' ) ) {
		global $wpdb;
		list( $lo, $hi ) = self::pair( $a, $b );
		$t               = CMP_Install::table( 'dynamic_addons' );
		$rows            = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE user_a = %d AND user_b = %d ORDER BY id", $lo, $hi ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::only( $rows, $statuses );
	}

	public static function addons_for_user( $user_id, $statuses = null ) {
		global $wpdb;
		$t    = CMP_Install::table( 'dynamic_addons' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE user_a = %d OR user_b = %d ORDER BY id", $user_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null === $statuses ? $rows : self::only( $rows, $statuses );
	}

	public static function addon_other( $addon, $user_id ) {
		return (int) $addon->user_a === (int) $user_id ? (int) $addon->user_b : (int) $addon->user_a;
	}

	/** An add-on of this kind and direction that's on or asked for, if any. */
	private static function addon_taken( $kind, $lead_id, $member_id ) {
		foreach ( self::addons_between( $lead_id, $member_id, array( 'pending', 'active' ) ) as $ad ) {
			if ( $kind === $ad->kind && (int) $ad->lead_id === (int) $lead_id ) {
				return $ad;
			}
		}
		return null;
	}

	/**
	 * Records an add-on. Used by invitations, requests, the 0.16.0 upgrade
	 * and the tests; returns its id (or the existing one's).
	 */
	public static function grant( $kind, $lead_id, $member_id, $status = 'active', $group_id = 0, $requested_by = 0 ) {
		global $wpdb;
		$have = self::addon_taken( $kind, $lead_id, $member_id );
		if ( $have ) {
			return (int) $have->id;
		}
		list( $lo, $hi ) = self::pair( $lead_id, $member_id );
		$now             = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			CMP_Install::table( 'dynamic_addons' ),
			array(
				'kind'         => $kind,
				'user_a'       => $lo,
				'user_b'       => $hi,
				'lead_id'      => (int) $lead_id,
				'status'       => $status,
				'group_id'     => (int) $group_id,
				'requested_by' => (int) $requested_by,
				'created_at'   => $now,
				'responded_at' => 'active' === $status ? $now : null,
			),
			array( '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s' )
		);
		$id = (int) $wpdb->insert_id;
		CMP_Audit::log( 'addon_' . ( 'active' === $status ? 'granted' : 'requested' ), 'dynamic_addon', $id, null, array( 'kind' => $kind, 'lead' => (int) $lead_id, 'member' => (int) $member_id ) );
		return $id;
	}

	private static function set_addon_status( $addon, $status, $by = 0 ) {
		global $wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array( 'status' => $status );
		if ( 'ended' === $status ) {
			$data['ended_at'] = $now;
			$data['ended_by'] = (int) $by;
		} else {
			$data['responded_at'] = $now;
		}
		$wpdb->update( CMP_Install::table( 'dynamic_addons' ), $data, array( 'id' => $addon->id ) );
		CMP_Audit::log( 'addon_' . $status, 'dynamic_addon', (int) $addon->id, array( 'status' => $addon->status ), array( 'status' => $status ) );
	}

	/**
	 * Ends every add-on between two members and withdraws any request, e.g.
	 * when their last dynamic ends or one blocks the other. Fires
	 * cmp_dynamic_ended so homework and chastity let go at once.
	 */
	public static function close_addons( $a, $b, $by ) {
		$changed = false;
		foreach ( self::addons_between( $a, $b, array( 'pending', 'active' ) ) as $ad ) {
			self::set_addon_status( $ad, 'active' === $ad->status ? 'ended' : 'withdrawn', $by );
			$changed = $changed || 'active' === $ad->status;
		}
		if ( $changed ) {
			do_action( 'cmp_dynamic_ended', self::pair_stub( $a, $b ), $by );
		}
	}

	/** Enough of a dynamic for cmp_dynamic_ended listeners (they check both directions). */
	private static function pair_stub( $a, $b ) {
		return (object) array( 'id' => 0, 'type' => '', 'proposer_id' => (int) $a, 'partner_id' => (int) $b, 'proposer_side' => '', 'status' => 'ended' );
	}

	/**
	 * Whether $lead_id may direct $member_id: an add-on they lead between
	 * them ('homework' to set homework, 'chastity' to manage a lock; null
	 * for either), while at least one dynamic between them is active.
	 * Turning the add-on off or ending the last dynamic removes this at once.
	 */
	public static function lead_can_direct( $lead_id, $member_id, $kind = null ) {
		if ( (int) $lead_id === (int) $member_id || ! self::active_between( $lead_id, $member_id ) ) {
			return false;
		}
		foreach ( self::addons_between( $lead_id, $member_id ) as $ad ) {
			if ( (int) $ad->lead_id === (int) $lead_id && ( null === $kind || $kind === $ad->kind ) ) {
				return true;
			}
		}
		return false;
	}

	/** Members this member may direct ($kind as above): user_id => their dynamics' labels. */
	public static function led_by( $lead_id, $kind = null ) {
		$out = array();
		foreach ( self::addons_for_user( $lead_id, array( 'active' ) ) as $ad ) {
			$other = self::addon_other( $ad, $lead_id );
			if ( (int) $ad->lead_id !== (int) $lead_id || ( null !== $kind && $kind !== $ad->kind ) || isset( $out[ $other ] ) ) {
				continue;
			}
			$labels = array();
			foreach ( self::active_between( $lead_id, $other ) as $dyn ) {
				$labels[] = self::types()[ $dyn->type ]['label'];
			}
			if ( $labels ) {
				$out[ $other ] = $labels;
			}
		}
		return $out;
	}

	/**
	 * 0.16.0 upgrade, once: dynamics used to give the leading side both
	 * homework and chastity. Each pair keeps only what it uses (owner,
	 * 2026-10-05: "Keep what they use"): chastity for Keyholder dynamics or
	 * a lock in place between them, homework if a program is running.
	 * Pending Keyholder invitations get their chastity add-on too.
	 */
	public static function migrate_addons() {
		global $wpdb;
		if ( get_option( self::MIGRATED ) ) {
			return;
		}
		$t = CMP_Install::table( 'dynamics' );
		foreach ( $wpdb->get_results( "SELECT * FROM $t WHERE status IN ('active','pending') ORDER BY id" ) as $dyn ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$lead = self::lead_id( $dyn );
			if ( ! $lead ) {
				continue;
			}
			$member = self::other( $dyn, $lead );
			if ( 'pending' === $dyn->status ) {
				if ( 'keyholder' === $dyn->type ) {
					self::grant( 'chastity', $lead, $member, 'pending', self::group_key( $dyn ), (int) $dyn->proposer_id );
				}
				continue;
			}
			$locked  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CMP_Install::table( 'locks' ) . " WHERE keyholder_id = %d AND wearer_id = %d AND status = 'locked'", $lead, $member ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$program = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CMP_Install::table( 'programs' ) . " WHERE lead_id = %d AND member_id = %d AND status = 'active'", $lead, $member ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( 'keyholder' === $dyn->type || $locked ) {
				self::grant( 'chastity', $lead, $member );
			}
			if ( $program ) {
				self::grant( 'homework', $lead, $member );
			}
		}
		update_option( self::MIGRATED, gmdate( 'Y-m-d H:i:s' ), false );
	}

	public static function connected( $connected, $owner_id, $viewer_id ) {
		return $connected || (bool) self::active_between( $owner_id, $viewer_id );
	}

	/** Active dynamics both members show on their profiles. */
	public static function shown_on_profile( $user_id ) {
		return array_values(
			array_filter(
				self::for_user( $user_id, array( 'active' ) ),
				function ( $d ) {
					return $d->proposer_show && $d->partner_show;
				}
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ---------------------------------------------------------------- */

	private static function guard( $nonce_action = self::NONCE ) {
		$user_id = get_current_user_id();
		if ( ! CMP_Access::is_member( $user_id ) ) {
			wp_safe_redirect( CMP_Settings::member_page_url() );
			exit;
		}
		if ( ! isset( $_POST['_cmp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cmp_nonce'] ) ), $nonce_action ) ) {
			wp_safe_redirect( self::url( 'expired' ) );
			exit;
		}
		return $user_id;
	}

	private static function back( $notice, $to = '' ) {
		wp_safe_redirect( $to ? add_query_arg( 'cmp_notice', $notice, $to ) : self::url( $notice ) );
		exit;
	}

	private static function posted_dynamic( $user_id ) {
		$id  = isset( $_POST['dynamic'] ) ? absint( $_POST['dynamic'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$dyn = $id ? self::get( $id ) : null;
		if ( ! $dyn || ( (int) $dyn->proposer_id !== $user_id && (int) $dyn->partner_id !== $user_id ) ) {
			self::back( 'dyn_gone' ); // Same answer whether it exists or not.
		}
		return $dyn;
	}

	/**
	 * What an invitation asks for, from $viewer's side, as plain lines:
	 * "Dominant / submissive: they'd be your Dominant", "Chastity, with you
	 * holding the key" …
	 */
	public static function invitation_lines( $rows, $addons, $viewer ) {
		$lines = array();
		foreach ( $rows as $d ) {
			$type = self::types()[ $d->type ];
			if ( ! $type['lead'] ) {
				$lines[] = $type['label'];
				continue;
			}
			$lines[] = self::lead_id( $d ) === (int) $viewer
				/* translators: 1: dynamic type, 2: viewer's side */
				? sprintf( __( '%1$s: you\'d be the %2$s', 'cmp' ), $type['label'], self::side_label( $d, $viewer ) )
				/* translators: 1: dynamic type, 2: other member's side */
				: sprintf( __( '%1$s: they\'d be your %2$s', 'cmp' ), $type['label'], self::side_label( $d, self::other( $d, $viewer ) ) );
		}
		foreach ( $addons as $ad ) {
			$lines[] = self::addon_line( $ad, $viewer, false );
		}
		return $lines;
	}

	/** "Chastity · you hold the key" (active) or "Chastity, with them holding the key" (asked for). */
	public static function addon_line( $ad, $viewer, $active = true ) {
		$mine = (int) $ad->lead_id === (int) $viewer;
		$name = self::name( $ad->lead_id );
		if ( 'chastity' === $ad->kind ) {
			if ( $active ) {
				/* translators: %s: member name */
				return $mine ? __( 'Chastity · you hold the key', 'cmp' ) : sprintf( __( 'Chastity · %s holds the key', 'cmp' ), $name );
			}
			return $mine ? __( 'Chastity, with you holding the key', 'cmp' ) : __( 'Chastity, with them holding the key', 'cmp' );
		}
		if ( $active ) {
			/* translators: %s: member name */
			return $mine ? __( 'Homework · you set it', 'cmp' ) : sprintf( __( 'Homework · %s sets it', 'cmp' ), $name );
		}
		return $mine ? __( 'Homework, set by you', 'cmp' ) : __( 'Homework, set by them', 'cmp' );
	}

	private static function name( $user_id ) {
		$u = get_userdata( (int) $user_id );
		return $u ? $u->display_name : __( 'Former member', 'cmp' );
	}

	public static function handle_propose() {
		global $wpdb;
		$user_id = self::guard();
		$types   = self::types();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$to      = isset( $_POST['partner'] ) ? absint( $_POST['partner'] ) : 0;
		$message = isset( $_POST['message'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) ) : '';
		$chosen  = isset( $_POST['types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['types'] ) ) : array();
		$sides   = isset( $_POST['side'] ) ? wp_unslash( $_POST['side'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per key below.
		if ( ! $chosen && isset( $_POST['type'] ) ) { // One type, as before 0.16.0.
			$chosen = array( sanitize_key( wp_unslash( $_POST['type'] ) ) );
			$sides  = array( $chosen[0] => is_array( $sides ) ? '' : $sides );
		}
		$want  = isset( $_POST['addon'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['addon'] ) ) : array();
		$leads = isset( $_POST['addon_lead'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['addon_lead'] ) ) : array();
		// phpcs:enable
		$profile = add_query_arg( 'cmp_member', $to, CMP_Settings::member_page_url() );
		if ( ! $to || $to === $user_id || ! CMP_Access::is_member( $to ) || CMP_Messages::is_blocked( $user_id, $to ) ) {
			self::back( 'dyn_gone' );
		}
		$chosen = array_values( array_unique( $chosen ) );
		$picked = array();
		foreach ( $chosen as $type ) {
			$side = isset( $sides[ $type ] ) && ! is_array( $sides[ $type ] ) ? sanitize_key( $sides[ $type ] ) : '';
			if ( ! isset( $types[ $type ] ) || ( $types[ $type ]['lead'] && ! in_array( $side, array( 'a', 'b' ), true ) ) ) {
				self::back( 'dyn_invalid', $profile );
			}
			$picked[ $type ] = $types[ $type ]['lead'] ? $side : '';
		}
		if ( ! $picked || mb_strlen( $message ) > self::MAX_MESSAGE ) {
			self::back( 'dyn_invalid', $profile );
		}
		// Add-ons: lead is the proposer ("me") or the partner ("them").
		$addons = array();
		foreach ( self::ADDONS as $kind ) {
			if ( ! empty( $want[ $kind ] ) ) {
				$addons[ $kind ] = isset( $leads[ $kind ] ) && 'them' === $leads[ $kind ] ? $to : $user_id;
			}
		}
		if ( isset( $picked['keyholder'] ) ) { // Always with chastity, the Keyholder holding the key.
			$addons['chastity'] = 'a' === $picked['keyholder'] ? $user_id : $to;
		}
		$t = CMP_Install::table( 'dynamics' );
		foreach ( array_keys( $picked ) as $type ) {
			$dupe = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE type = %s AND status IN ('pending','active') AND ( ( proposer_id = %d AND partner_id = %d ) OR ( proposer_id = %d AND partner_id = %d ) )", $type, $user_id, $to, $to, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( (int) $dupe ) {
				self::back( 'dyn_duplicate', $profile );
			}
		}
		$pending = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT CASE WHEN group_id > 0 THEN group_id ELSE id END) FROM $t WHERE proposer_id = %d AND status = 'pending'", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( (int) $pending >= self::MAX_PENDING ) {
			self::back( 'dyn_too_many', $profile );
		}
		$key = 0;
		foreach ( $picked as $type => $side ) {
			$wpdb->insert(
				$t,
				array(
					'type'          => $type,
					'proposer_id'   => $user_id,
					'partner_id'    => $to,
					'proposer_side' => $side,
					'status'        => 'pending',
					'message'       => $key ? '' : $message,
					'proposer_show' => 1,
					'partner_show'  => 1,
					'group_id'      => $key,
					'created_at'    => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
			);
			$id = (int) $wpdb->insert_id;
			if ( ! $key ) {
				$key = $id;
				$wpdb->update( $t, array( 'group_id' => $key ), array( 'id' => $key ), array( '%d' ), array( '%d' ) );
			}
			CMP_Audit::log( 'dynamic_proposed', 'dynamic', $id, null, array( 'type' => $type, 'proposer' => $user_id, 'partner' => $to, 'proposer_side' => $side, 'group' => $key ) );
		}
		foreach ( $addons as $kind => $lead ) {
			if ( ! self::addon_taken( $kind, $lead, $lead === $user_id ? $to : $user_id ) ) {
				self::grant( $kind, $lead, $lead === $user_id ? $to : $user_id, 'pending', $key, $user_id );
			}
		}
		CMP_Notifications::add(
			$to,
			'invitation',
			sprintf(
				/* translators: 1: member name, 2: what they're asking for */
				__( '%1$s invited you to a dynamic: %2$s.', 'cmp' ),
				wp_get_current_user()->display_name,
				implode( '; ', self::invitation_lines( self::group_rows( $key ), self::group_addons( $key ), $to ) )
			),
			self::url()
		);
		self::back( 'dyn_sent' );
	}

	/** Add-ons asked for in one invitation, still waiting. */
	private static function group_addons( $key ) {
		global $wpdb;
		$t = CMP_Install::table( 'dynamic_addons' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE group_id = %d AND status = 'pending' ORDER BY id", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Accept or decline a whole invitation. */
	public static function handle_respond() {
		global $wpdb;
		$user_id = self::guard();
		$dyn     = self::posted_dynamic( $user_id );
		$answer  = isset( $_POST['answer'] ) ? sanitize_key( wp_unslash( $_POST['answer'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		if ( 'pending' !== $dyn->status || (int) $dyn->partner_id !== $user_id || ! in_array( $answer, array( 'accept', 'decline' ), true ) ) {
			self::back( 'dyn_gone' );
		}
		$status = 'accept' === $answer ? 'active' : 'declined';
		$key    = self::group_key( $dyn );
		$rows   = self::only( self::group_rows( $key ), 'pending' );
		$addons = self::group_addons( $key );
		$lines  = self::invitation_lines( $rows, $addons, (int) $dyn->proposer_id );
		foreach ( $rows as $row ) {
			$wpdb->update( CMP_Install::table( 'dynamics' ), array( 'status' => $status, 'responded_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $row->id, 'status' => 'pending' ), array( '%s', '%s' ), array( '%d', '%s' ) );
			CMP_Audit::log( 'dynamic_' . ( 'active' === $status ? 'accepted' : 'declined' ), 'dynamic', $row->id, array( 'status' => 'pending' ), array( 'status' => $status ) );
		}
		foreach ( $addons as $ad ) {
			self::set_addon_status( $ad, $status );
		}
		CMP_Notifications::add(
			$dyn->proposer_id,
			'access_change',
			sprintf(
				/* translators: 1: member name, 2: what was asked for */
				'active' === $status ? __( '%1$s accepted your invitation (%2$s). It\'s now active.', 'cmp' ) : __( '%1$s declined your invitation (%2$s).', 'cmp' ),
				wp_get_current_user()->display_name,
				implode( '; ', $lines )
			),
			self::url()
		);
		self::back( 'active' === $status ? 'dyn_accepted' : 'dyn_declined' );
	}

	public static function handle_withdraw() {
		global $wpdb;
		$user_id = self::guard();
		$dyn     = self::posted_dynamic( $user_id );
		if ( 'pending' !== $dyn->status || (int) $dyn->proposer_id !== $user_id ) {
			self::back( 'dyn_gone' );
		}
		$key = self::group_key( $dyn );
		foreach ( self::only( self::group_rows( $key ), 'pending' ) as $row ) {
			$wpdb->update( CMP_Install::table( 'dynamics' ), array( 'status' => 'withdrawn', 'responded_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $row->id, 'status' => 'pending' ), array( '%s', '%s' ), array( '%d', '%s' ) );
			CMP_Audit::log( 'dynamic_withdrawn', 'dynamic', $row->id, array( 'status' => 'pending' ), array( 'status' => 'withdrawn' ) );
		}
		foreach ( self::group_addons( $key ) as $ad ) {
			self::set_addon_status( $ad, 'withdrawn' );
		}
		self::back( 'dyn_withdrawn' );
	}

	/** Either member, any time, no approval: consent can always be withdrawn. */
	public static function handle_end() {
		global $wpdb;
		$user_id = self::guard();
		$dyn     = self::posted_dynamic( $user_id );
		if ( 'active' !== $dyn->status ) {
			self::back( 'dyn_gone' );
		}
		$other = self::other( $dyn, $user_id );
		$wpdb->update( CMP_Install::table( 'dynamics' ), array( 'status' => 'ended', 'ended_at' => gmdate( 'Y-m-d H:i:s' ), 'ended_by' => $user_id ), array( 'id' => $dyn->id, 'status' => 'active' ), array( '%s', '%s', '%d' ), array( '%d', '%s' ) );
		CMP_Audit::log( 'dynamic_ended', 'dynamic', $dyn->id, array( 'status' => 'active' ), array( 'status' => 'ended', 'ended_by' => $user_id ) );
		if ( ! self::active_between( $user_id, $other ) ) {
			self::close_addons( $user_id, $other, $user_id ); // Add-ons need a dynamic to belong to.
		}
		/**
		 * A dynamic ended: anything it allowed (homework, chastity) stops.
		 *
		 * @param object $dyn     The dynamic as it was.
		 * @param int    $user_id Who ended it.
		 */
		do_action( 'cmp_dynamic_ended', $dyn, $user_id );
		CMP_Notifications::add(
			$other,
			'access_change',
			sprintf(
				/* translators: 1: member name, 2: dynamic type */
				__( '%1$s ended your dynamic (%2$s).', 'cmp' ),
				wp_get_current_user()->display_name,
				self::types()[ $dyn->type ]['label']
			),
			self::url()
		);
		self::back( 'dyn_ended' );
	}

	public static function handle_show() {
		global $wpdb;
		$user_id = self::guard();
		$dyn     = self::posted_dynamic( $user_id );
		$show    = ! empty( $_POST['show'] ) ? 1 : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$col     = (int) $dyn->proposer_id === $user_id ? 'proposer_show' : 'partner_show';
		$wpdb->update( CMP_Install::table( 'dynamics' ), array( $col => $show ), array( 'id' => $dyn->id ), array( '%d' ), array( '%d' ) );
		self::back( 'dyn_saved' );
	}

	/**
	 * Add-ons on an active dynamic: ask (do=ask, partner, kind, lead=me|them),
	 * answer (do=accept|decline), withdraw a request, or turn one off.
	 */
	public static function handle_addon() {
		$user_id = self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'ask' === $do ) {
			$to   = isset( $_POST['partner'] ) ? absint( $_POST['partner'] ) : 0;
			$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
			$who  = isset( $_POST['lead'] ) ? sanitize_key( wp_unslash( $_POST['lead'] ) ) : '';
			if ( ! $kind && isset( $_POST['choice'] ) ) { // The dropdown: "homework:me".
				list( $kind, $who ) = array_pad( explode( ':', sanitize_text_field( wp_unslash( $_POST['choice'] ) ), 2 ), 2, '' );
				$kind                = sanitize_key( $kind );
			}
			$lead = 'them' === $who ? $to : $user_id;
			// phpcs:enable
			if ( ! $to || $to === $user_id || ! in_array( $kind, self::ADDONS, true ) || ! self::active_between( $user_id, $to ) || CMP_Messages::is_blocked( $user_id, $to ) ) {
				self::back( 'dyn_gone' );
			}
			$member = $lead === $user_id ? $to : $user_id;
			if ( self::addon_taken( $kind, $lead, $member ) ) {
				self::back( 'dyn_addon_taken' );
			}
			$ad = self::get_addon( self::grant( $kind, $lead, $member, 'pending', 0, $user_id ) );
			CMP_Notifications::add(
				$to,
				'invitation',
				/* translators: 1: member name, 2: e.g. "Chastity, with you holding the key" */
				sprintf( __( '%1$s asked to add: %2$s.', 'cmp' ), wp_get_current_user()->display_name, self::addon_line( $ad, $to, false ) ),
				self::url()
			);
			self::back( 'dyn_addon_sent' );
		}
		$id = isset( $_POST['addon'] ) ? absint( $_POST['addon'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ad = $id ? self::get_addon( $id ) : null;
		if ( ! $ad || ( (int) $ad->user_a !== $user_id && (int) $ad->user_b !== $user_id ) ) {
			self::back( 'dyn_gone' );
		}
		$other   = self::addon_other( $ad, $user_id );
		$mine    = (int) $ad->requested_by === $user_id;
		$waiting = 'pending' === $ad->status && ! (int) $ad->group_id;
		if ( in_array( $do, array( 'accept', 'decline' ), true ) && $waiting && ! $mine ) {
			$on = 'accept' === $do && self::active_between( $user_id, $other );
			self::set_addon_status( $ad, $on ? 'active' : 'declined' );
			CMP_Notifications::add(
				$other,
				'access_change',
				sprintf(
					/* translators: 1: member name, 2: e.g. "Homework, set by you" */
					$on ? __( '%1$s accepted: %2$s.', 'cmp' ) : __( '%1$s declined: %2$s.', 'cmp' ),
					wp_get_current_user()->display_name,
					self::addon_line( $ad, $other, false )
				),
				self::url()
			);
			self::back( $on ? 'dyn_addon_on' : 'dyn_addon_no' );
		}
		if ( 'withdraw' === $do && $waiting && $mine ) {
			self::set_addon_status( $ad, 'withdrawn' );
			self::back( 'dyn_withdrawn' );
		}
		if ( 'off' === $do && 'active' === $ad->status ) {
			self::set_addon_status( $ad, 'ended', $user_id );
			do_action( 'cmp_dynamic_ended', self::pair_stub( $ad->user_a, $ad->user_b ), $user_id );
			CMP_Notifications::add(
				$other,
				'access_change',
				/* translators: 1: member name, 2: Chastity or Homework */
				sprintf( __( '%1$s turned off %2$s between you.', 'cmp' ), wp_get_current_user()->display_name, mb_strtolower( self::addon_labels()[ $ad->kind ][0] ) ),
				self::url()
			);
			self::back( 'dyn_addon_off' );
		}
		self::back( 'dyn_gone' );
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	/** Invitations and add-on requests waiting for this member's answer. */
	public static function pending_for( $user_id ) {
		$groups = array();
		foreach ( self::for_user( $user_id, array( 'pending' ) ) as $d ) {
			if ( (int) $d->partner_id === (int) $user_id ) {
				$groups[ self::group_key( $d ) ] = 1;
			}
		}
		$asks = 0;
		foreach ( self::addons_for_user( $user_id, array( 'pending' ) ) as $ad ) {
			$asks += ( ! (int) $ad->group_id && (int) $ad->requested_by !== (int) $user_id ) ? 1 : 0;
		}
		return count( $groups ) + $asks;
	}

	private static function who_html( $other_id, $line ) {
		$user = get_userdata( $other_id );
		$name = $user ? $user->display_name : __( 'Former member', 'cmp' );
		$link = $user ? '<a href="' . esc_url( add_query_arg( 'cmp_member', $other_id, CMP_Settings::member_page_url() ) ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name );
		return '<div class="cmp-who"><span class="cmp-who-av" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ) . '</span><div><b>' . $link . '</b><small>' . $line . '</small></div></div>';
	}

	private static function action_form( $action, $dyn_id, $label, $class = 'cmp-btn cmp-btn-small', $extra = array(), $confirm = '' ) {
		$field = 'addon' === $action ? 'addon' : 'dynamic';
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cmp-inline-form"><input type="hidden" name="action" value="cmp_dyn_' . esc_attr( $action ) . '" /><input type="hidden" name="' . $field . '" value="' . (int) $dyn_id . '" />'
			. '<input type="hidden" name="_cmp_nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" />';
		foreach ( $extra as $k => $v ) {
			$html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" />';
		}
		return $html . '<button type="submit" class="' . esc_attr( $class ) . '"' . ( $confirm ? ' data-cmp-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
	}

	private static function list_html( $lines ) {
		$html = '<ul class="cmp-dyn-list">';
		foreach ( $lines as $l ) {
			$html .= '<li>' . esc_html( $l ) . '</li>';
		}
		return $html . '</ul>';
	}

	/** Pending invitations, grouped: key => array( rows, addons ). */
	private static function invitations( $user_id, $incoming ) {
		$out = array();
		foreach ( array_reverse( self::for_user( $user_id, array( 'pending' ) ) ) as $d ) {
			if ( ( (int) $d->partner_id === (int) $user_id ) !== $incoming ) {
				continue;
			}
			$key = self::group_key( $d );
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array( array(), self::group_addons( $key ) );
			}
			$out[ $key ][0][] = $d;
		}
		return array_reverse( $out, true );
	}

	public static function render( $user_id ) {
		$incoming = self::invitations( $user_id, true );
		$sent     = self::invitations( $user_id, false );
		$asks_in  = array();
		$asks_out = array();
		foreach ( self::addons_for_user( $user_id, array( 'pending' ) ) as $ad ) {
			if ( ! (int) $ad->group_id ) {
				if ( (int) $ad->requested_by === (int) $user_id ) {
					$asks_out[ self::addon_other( $ad, $user_id ) ][] = $ad;
				} else {
					$asks_in[] = $ad;
				}
			}
		}
		$partners = array();
		foreach ( array_reverse( self::for_user( $user_id, array( 'active' ) ) ) as $d ) {
			$partners[ self::other( $d, $user_id ) ][] = $d;
		}
		wp_enqueue_script( 'cmp-member' );
		ob_start();
		?>
		<section class="cmp-step" aria-labelledby="cmp-dyn-title">
			<h2 id="cmp-dyn-title" class="cmp-title"><?php esc_html_e( 'Dynamics', 'cmp' ); ?></h2>
			<p><?php esc_html_e( 'Relationships and power dynamics with other members. Both of you must agree, and either of you can end one at any time. Chastity and homework are add-ons you both agree to, with any dynamic.', 'cmp' ); ?></p>
			<p class="cmp-muted"><?php esc_html_e( 'To propose one, open a member\'s profile and choose "Propose a dynamic".', 'cmp' ); ?></p>
		</section>

		<section class="cmp-panel cmp-dyn-incoming">
			<h3 class="cmp-panel-title"><?php esc_html_e( 'Waiting for you', 'cmp' ); ?></h3>
			<?php if ( ! $incoming && ! $asks_in ) : ?>
				<p class="cmp-empty"><?php esc_html_e( 'Nothing here.', 'cmp' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $incoming as $key => list( $rows, $addons ) ) : ?>
				<?php $other = self::other( $rows[0], $user_id ); ?>
				<div class="cmp-dyn-row">
					<?php echo self::who_html( $other, esc_html__( 'invites you to:', 'cmp' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
					<?php echo self::list_html( self::invitation_lines( $rows, $addons, $user_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
					<?php if ( $rows[0]->message ) : ?>
						<p class="cmp-dyn-msg">“<?php echo esc_html( $rows[0]->message ); ?>”</p>
					<?php endif; ?>
					<div class="cmp-dyn-acts">
						<?php
						echo self::action_form( 'respond', $key, __( 'Accept', 'cmp' ), 'cmp-btn cmp-btn-small', array( 'answer' => 'accept' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo self::action_form( 'respond', $key, __( 'Decline', 'cmp' ), 'cmp-btn cmp-btn-small cmp-btn-outline', array( 'answer' => 'decline' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</div>
				</div>
			<?php endforeach; ?>
			<?php foreach ( $asks_in as $ad ) : ?>
				<div class="cmp-dyn-row">
					<?php echo self::who_html( self::addon_other( $ad, $user_id ), esc_html__( 'asks to add:', 'cmp' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
					<?php echo self::list_html( array( self::addon_line( $ad, $user_id, false ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
					<div class="cmp-dyn-acts">
						<?php
						echo self::action_form( 'addon', $ad->id, __( 'Accept', 'cmp' ), 'cmp-btn cmp-btn-small', array( 'do' => 'accept' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo self::action_form( 'addon', $ad->id, __( 'Decline', 'cmp' ), 'cmp-btn cmp-btn-small cmp-btn-outline', array( 'do' => 'decline' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</div>
				</div>
			<?php endforeach; ?>
		</section>

		<section class="cmp-panel cmp-dyn-active">
			<h3 class="cmp-panel-title"><?php esc_html_e( 'Active', 'cmp' ); ?></h3>
			<?php if ( ! $partners ) : ?>
				<p class="cmp-empty"><?php esc_html_e( 'Nothing here.', 'cmp' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $partners as $other => $rows ) : ?>
				<?php
				$sides = array();
				foreach ( $rows as $d ) {
					$type    = self::types()[ $d->type ];
					/* translators: %s: their side, e.g. Dominant */
					$sides[] = $type['lead'] ? sprintf( __( 'your %s', 'cmp' ), self::side_label( $d, $other ) ) : $type['label'];
				}
				$on = self::addons_between( $user_id, $other );
				?>
				<div class="cmp-dyn-row cmp-dyn-pair">
					<?php echo self::who_html( $other, esc_html( implode( ' · ', $sides ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here and inside. ?>
					<ul class="cmp-dyn-items">
						<?php foreach ( $rows as $d ) : ?>
							<?php
							$type      = self::types()[ $d->type ];
							$mine_show = (int) $d->proposer_id === $user_id ? $d->proposer_show : $d->partner_show;
							/* translators: 1: dynamic type, 2: viewer's side, 3: since date */
							$text = $type['lead'] ? sprintf( __( '%1$s · you\'re the %2$s · since %3$s', 'cmp' ), $type['label'], self::side_label( $d, $user_id ), wp_date( 'M Y', strtotime( ( $d->responded_at ? $d->responded_at : $d->created_at ) . ' UTC' ) ) )
								/* translators: 1: dynamic type, 2: since date */
								: sprintf( __( '%1$s · since %2$s', 'cmp' ), $type['label'], wp_date( 'M Y', strtotime( ( $d->responded_at ? $d->responded_at : $d->created_at ) . ' UTC' ) ) );
							?>
							<li class="cmp-dyn-item">
								<span class="cmp-dyn-item-text"><?php echo esc_html( $text ); ?></span>
								<span class="cmp-dyn-item-acts">
									<?php
									echo self::action_form( 'show', $d->id, $mine_show ? __( 'Shown on my profile ✓', 'cmp' ) : __( 'Hidden from my profile', 'cmp' ), 'cmp-btn cmp-btn-small cmp-btn-outline', array( 'show' => $mine_show ? '' : '1' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									echo self::action_form( 'end', $d->id, __( 'End', 'cmp' ), 'cmp-btn cmp-btn-small cmp-btn-outline cmp-btn-danger', array(), 1 === count( $rows ) ? __( 'End this dynamic? Chastity and homework between you stop straight away too.', 'cmp' ) : __( 'End this dynamic? Your other dynamics with them carry on.', 'cmp' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</span>
							</li>
						<?php endforeach; ?>
						<?php foreach ( $on as $ad ) : ?>
							<li class="cmp-dyn-item cmp-dyn-addon-on">
								<span class="cmp-dyn-item-text"><?php echo esc_html( self::addon_line( $ad, $user_id ) ); ?></span>
								<span class="cmp-dyn-item-acts"><?php echo self::action_form( 'addon', $ad->id, __( 'Turn off', 'cmp' ), 'cmp-btn cmp-btn-small cmp-btn-outline cmp-btn-danger', array( 'do' => 'off' ), __( 'Turn this off? It stops straight away for both of you.', 'cmp' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							</li>
						<?php endforeach; ?>
						<?php foreach ( isset( $asks_out[ $other ] ) ? $asks_out[ $other ] : array() as $ad ) : ?>
							<li class="cmp-dyn-item cmp-dyn-addon-asked">
								<?php /* translators: %s: e.g. "Homework, set by you" */ ?>
								<span class="cmp-dyn-item-text"><?php echo esc_html( sprintf( __( '%s · waiting for them to accept', 'cmp' ), self::addon_line( $ad, $user_id, false ) ) ); ?></span>
								<span class="cmp-dyn-item-acts"><?php echo self::action_form( 'addon', $ad->id, __( 'Withdraw', 'cmp' ), 'cmp-btn cmp-btn-small cmp-btn-outline', array( 'do' => 'withdraw' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php echo self::ask_form_html( $user_id, $other ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
				</div>
			<?php endforeach; ?>
		</section>

		<section class="cmp-panel cmp-dyn-sent">
			<h3 class="cmp-panel-title"><?php esc_html_e( 'Sent by you', 'cmp' ); ?></h3>
			<?php if ( ! $sent ) : ?>
				<p class="cmp-empty"><?php esc_html_e( 'Nothing here.', 'cmp' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $sent as $key => list( $rows, $addons ) ) : ?>
				<div class="cmp-dyn-row">
					<?php echo self::who_html( self::other( $rows[0], $user_id ), esc_html__( 'Pending · you invited them to:', 'cmp' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
					<?php echo self::list_html( self::invitation_lines( $rows, $addons, $user_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
					<div class="cmp-dyn-acts"><?php echo self::action_form( 'withdraw', $key, __( 'Withdraw', 'cmp' ), 'cmp-btn cmp-btn-small cmp-btn-outline' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/** "Add chastity or homework" on an active pair, plus a way to add a type. */
	private static function ask_form_html( $user_id, $other ) {
		$labels  = self::addon_labels();
		$options = '';
		foreach ( self::ADDONS as $kind ) {
			foreach ( array( 'me' => $user_id, 'them' => $other ) as $who => $lead ) {
				if ( ! self::addon_taken( $kind, $lead, 'me' === $who ? $other : $user_id ) ) {
					/* translators: 1: Chastity or Homework, 2: e.g. "I hold the key" */
					$options .= '<option value="' . esc_attr( $kind . ':' . $who ) . '">' . esc_html( sprintf( __( '%1$s: %2$s', 'cmp' ), $labels[ $kind ][0], 'me' === $who ? $labels[ $kind ][1] : $labels[ $kind ][2] ) ) . '</option>';
				}
			}
		}
		$profile = add_query_arg( 'cmp_member', $other, CMP_Settings::member_page_url() ) . '#cmp-dyn-propose';
		$html    = '<div class="cmp-dyn-more">';
		if ( $options ) {
			$id    = 'cmp_dyn_ask_' . (int) $other;
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cmp-form cmp-dyn-ask" data-cmp-dyn-ask>'
				. '<input type="hidden" name="action" value="cmp_dyn_addon" /><input type="hidden" name="do" value="ask" /><input type="hidden" name="partner" value="' . (int) $other . '" />'
				. '<input type="hidden" name="_cmp_nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" />'
				. '<label for="' . esc_attr( $id ) . '">' . esc_html__( 'Ask to add', 'cmp' ) . '</label>'
				. '<select id="' . esc_attr( $id ) . '" name="choice" data-cmp-dyn-choice>' . $options . '</select>'
				. '<button type="submit" class="cmp-btn cmp-btn-small cmp-btn-outline">' . esc_html__( 'Ask', 'cmp' ) . '</button></form>';
		}
		return $html . '<a class="cmp-dyn-addtype" href="' . esc_url( $profile ) . '">' . esc_html__( 'Add a type', 'cmp' ) . '</a></div>';
	}

	/** "Propose a dynamic" on another member's profile. */
	public static function propose_html( $viewer_id, $owner_id ) {
		$types  = self::types();
		$labels = self::addon_labels();
		/* translators: %s: side, e.g. Keyholder */
		$tpl = __( 'I\'m the %s', 'cmp' );
		ob_start();
		?>
		<details class="cmp-panel cmp-dyn-propose" id="cmp-dyn-propose">
			<summary class="cmp-btn"><?php esc_html_e( 'Propose a dynamic', 'cmp' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmp-form" data-cmp-dyn-form>
				<input type="hidden" name="action" value="cmp_dyn_propose" />
				<input type="hidden" name="partner" value="<?php echo (int) $owner_id; ?>" />
				<input type="hidden" name="_cmp_nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>" />
				<fieldset class="cmp-dyn-types">
					<legend><?php esc_html_e( 'Types (pick any)', 'cmp' ); ?></legend>
					<div class="cmp-dyn-chips">
						<?php foreach ( $types as $key => $t ) : ?>
							<span class="cmp-dyn-chip"><input type="checkbox" id="cmp_dyn_t_<?php echo esc_attr( $key ); ?>" name="types[]" value="<?php echo esc_attr( $key ); ?>" data-cmp-dyn-t="<?php echo esc_attr( $key ); ?>" /><label for="cmp_dyn_t_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $t['label'] ); ?></label></span>
						<?php endforeach; ?>
					</div>
					<p class="cmp-field-error" data-cmp-dyn-error hidden><?php esc_html_e( 'Choose at least one type, and your side in each.', 'cmp' ); ?></p>
				</fieldset>
				<div class="cmp-dyn-sides" data-cmp-dyn-sides>
					<?php foreach ( $types as $key => $t ) : ?>
						<?php
						if ( ! $t['lead'] ) {
							continue;
						}
						?>
						<fieldset class="cmp-dyn-side" data-cmp-dyn-side="<?php echo esc_attr( $key ); ?>">
							<?php /* translators: %s: dynamic type */ ?>
							<legend><?php echo esc_html( sprintf( __( 'Your side · %s', 'cmp' ), $t['label'] ) ); ?></legend>
							<p class="cmp-check"><input type="radio" id="cmp_dyn_s_<?php echo esc_attr( $key ); ?>_a" name="side[<?php echo esc_attr( $key ); ?>]" value="a" /> <label for="cmp_dyn_s_<?php echo esc_attr( $key ); ?>_a"><?php echo esc_html( sprintf( $tpl, $t['a'] ) ); ?></label></p>
							<p class="cmp-check"><input type="radio" id="cmp_dyn_s_<?php echo esc_attr( $key ); ?>_b" name="side[<?php echo esc_attr( $key ); ?>]" value="b" /> <label for="cmp_dyn_s_<?php echo esc_attr( $key ); ?>_b"><?php echo esc_html( sprintf( $tpl, $t['b'] ) ); ?></label></p>
						</fieldset>
					<?php endforeach; ?>
				</div>
				<fieldset class="cmp-dyn-addons">
					<legend><?php esc_html_e( 'Add-ons (optional)', 'cmp' ); ?></legend>
					<?php foreach ( self::ADDONS as $kind ) : ?>
						<div class="cmp-dyn-addon" data-cmp-dyn-addon="<?php echo esc_attr( $kind ); ?>">
							<p class="cmp-check"><input type="checkbox" id="cmp_dyn_a_<?php echo esc_attr( $kind ); ?>" name="addon[<?php echo esc_attr( $kind ); ?>]" value="1" /> <label for="cmp_dyn_a_<?php echo esc_attr( $kind ); ?>"><?php echo esc_html( $labels[ $kind ][0] ); ?></label></p>
							<div class="cmp-dyn-addon-lead" role="radiogroup" aria-label="<?php echo esc_attr( $labels[ $kind ][0] ); ?>">
								<span class="cmp-dyn-pill"><input type="radio" id="cmp_dyn_l_<?php echo esc_attr( $kind ); ?>_me" name="addon_lead[<?php echo esc_attr( $kind ); ?>]" value="me" checked /><label for="cmp_dyn_l_<?php echo esc_attr( $kind ); ?>_me"><?php echo esc_html( $labels[ $kind ][1] ); ?></label></span>
								<span class="cmp-dyn-pill"><input type="radio" id="cmp_dyn_l_<?php echo esc_attr( $kind ); ?>_them" name="addon_lead[<?php echo esc_attr( $kind ); ?>]" value="them" /><label for="cmp_dyn_l_<?php echo esc_attr( $kind ); ?>_them"><?php echo esc_html( $labels[ $kind ][2] ); ?></label></span>
							</div>
							<?php if ( 'chastity' === $kind ) : ?>
								<p class="cmp-muted" data-cmp-dyn-kh-note hidden><?php esc_html_e( 'Always on with Keyholder / chastity wearer: the Keyholder holds the key.', 'cmp' ); ?></p>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
					<p class="cmp-muted"><?php esc_html_e( 'Chastity lets one of you manage the other\'s lock; homework lets one of you set tasks for the other. Either of you can turn these off, or end the dynamic, at any time.', 'cmp' ); ?></p>
				</fieldset>
				<p class="cmp-field">
					<label for="cmp_dyn_msg"><?php esc_html_e( 'Message (optional)', 'cmp' ); ?></label>
					<textarea id="cmp_dyn_msg" name="message" rows="3" maxlength="<?php echo (int) self::MAX_MESSAGE; ?>"></textarea>
				</p>
				<button type="submit" class="cmp-btn"><?php esc_html_e( 'Send invitation', 'cmp' ); ?></button>
			</form>
		</details>
		<?php
		return ob_get_clean();
	}

	/** The "Dynamics" card on a profile: active, shown by both. */
	public static function profile_card_html( $owner_id ) {
		$rows = self::shown_on_profile( $owner_id );
		if ( ! $rows ) {
			return '';
		}
		$html = '<section class="cmp-prof-card cmp-prof-dyn"><h4>' . esc_html__( 'Dynamics', 'cmp' ) . '</h4><ul>';
		foreach ( $rows as $d ) {
			$other = self::other( $d, $owner_id );
			$user  = get_userdata( $other );
			if ( ! $user ) {
				continue;
			}
			$type = self::types()[ $d->type ];
			/* translators: 1: this member's side, 2: other member's name */
			$text  = $type['lead'] ? sprintf( __( '%1$s of %2$s', 'cmp' ), self::side_label( $d, $owner_id ), '%NAME%' ) : sprintf( /* translators: 1: type, 2: name */ __( '%1$s with %2$s', 'cmp' ), $type['label'], '%NAME%' );
			$link  = '<a href="' . esc_url( add_query_arg( 'cmp_member', $other, CMP_Settings::member_page_url() ) ) . '">' . esc_html( $user->display_name ) . '</a>';
			$html .= '<li>' . str_replace( '%NAME%', $link, esc_html( $text ) ) . '</li>';
		}
		return $html . '</ul></section>';
	}

	/* ------------------------------------------------------------------
	 * Privacy
	 * ---------------------------------------------------------------- */

	public static function export_rows( $user_id ) {
		$rows = array();
		foreach ( self::for_user( $user_id ) as $d ) {
			$other  = get_userdata( self::other( $d, $user_id ) );
			$rows[] = array(
				'name'  => __( 'Dynamic', 'cmp' ),
				'value' => sprintf( '%s · %s · %s · %s', self::types()[ $d->type ]['label'], self::side_label( $d, $user_id ), $other ? $other->display_name : '—', $d->status ),
			);
		}
		foreach ( self::addons_for_user( $user_id ) as $ad ) {
			$rows[] = array(
				'name'  => __( 'Dynamic add-on', 'cmp' ),
				'value' => sprintf( '%s · %s · %s', self::addon_line( $ad, $user_id, false ), self::name( self::addon_other( $ad, $user_id ) ), $ad->status ),
			);
		}
		return $rows;
	}

	public static function erase( $user_id ) {
		global $wpdb;
		$t      = CMP_Install::table( 'dynamics' );
		$active = self::for_user( $user_id, array( 'active' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CMP_Install::table( 'dynamic_addons' ) . ' WHERE user_a = %d OR user_b = %d', $user_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE proposer_id = %d OR partner_id = %d", $user_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $active as $dyn ) { // After the rows are gone, so homework and chastity see nothing left.
			do_action( 'cmp_dynamic_ended', $dyn, $user_id );
		}
		return $n;
	}
}
