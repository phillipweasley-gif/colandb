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
 * A dynamic is a type (a pair of sides, e.g. Keyholder / chastity wearer,
 * or an equal type such as Partners) between a proposer and a partner.
 * - Nothing happens until the partner accepts (status pending → active).
 * - Either member can end an active dynamic at any time, with no approval;
 *   it takes effect immediately.
 * - The leading side (Keyholder, Dominant, Owner …) is who lead_can_direct()
 *   lets assign homework (0.7.0) and manage chastity (0.8.0). Equal types
 *   give nobody that.
 * - Shown on both profiles only while active and both members leave "Show
 *   on my profile" on. Active partners count as connections
 *   (cmp_are_connected), so "My connections" visibility now means something.
 */
class CMP_Dynamics {

	const TAB          = 'dynamics';
	const NONCE        = 'cmp_dynamics';
	const MAX_PENDING  = 10;
	const MAX_MESSAGE  = 500;
	const STATUSES     = array( 'pending', 'active', 'declined', 'withdrawn', 'ended' );

	public static function init() {
		foreach ( array( 'propose', 'respond', 'withdraw', 'end', 'show' ) as $a ) {
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
			'dyn_sent'      => array( 'success', __( 'Invitation sent. You\'ll be notified when they answer.', 'cmp' ) ),
			'dyn_accepted'  => array( 'success', __( 'Dynamic accepted. It\'s now active for both of you.', 'cmp' ) ),
			'dyn_declined'  => array( 'success', __( 'Invitation declined.', 'cmp' ) ),
			'dyn_withdrawn' => array( 'success', __( 'Invitation withdrawn.', 'cmp' ) ),
			'dyn_ended'     => array( 'success', __( 'Dynamic ended. It no longer gives either of you any permissions.', 'cmp' ) ),
			'dyn_saved'     => array( 'success', __( 'Saved.', 'cmp' ) ),
			'dyn_invalid'   => array( 'error', __( 'That invitation couldn\'t be sent. Choose a type and your side, and keep the message under 500 characters.', 'cmp' ) ),
			'dyn_duplicate' => array( 'error', __( 'You already have this kind of dynamic (or an invitation for it) with this member.', 'cmp' ) ),
			'dyn_too_many'  => array( 'error', __( 'You have 10 invitations waiting for an answer. Withdraw one before sending another.', 'cmp' ) ),
			'dyn_gone'      => array( 'error', __( 'That dynamic has changed or is no longer available.', 'cmp' ) ),
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
		if ( null !== $statuses ) {
			$rows = array_values(
				array_filter(
					$rows,
					function ( $r ) use ( $statuses ) {
						return in_array( $r->status, (array) $statuses, true );
					}
				)
			);
		}
		return $rows;
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

	/**
	 * Whether $lead_id may direct $member_id (assign homework, manage
	 * chastity): an active directed dynamic with $lead_id on the leading
	 * side. Ending the dynamic removes this at once.
	 *
	 * @param string|null $type Limit to one type (e.g. 'keyholder').
	 */
	public static function lead_can_direct( $lead_id, $member_id, $type = null ) {
		foreach ( self::active_between( $lead_id, $member_id ) as $dyn ) {
			if ( ( null === $type || $type === $dyn->type ) && self::lead_id( $dyn ) === (int) $lead_id ) {
				return true;
			}
		}
		return false;
	}

	/** Members this member leads in an active dynamic: user_id => dynamic labels. */
	public static function led_by( $lead_id ) {
		$out = array();
		foreach ( self::for_user( $lead_id, array( 'active' ) ) as $dyn ) {
			if ( self::lead_id( $dyn ) === (int) $lead_id ) {
				$out[ self::other( $dyn, $lead_id ) ][] = self::types()[ $dyn->type ]['label'];
			}
		}
		return $out;
	}

	public static function active_between( $a, $b ) {
		global $wpdb;
		$t = CMP_Install::table( 'dynamics' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = 'active' AND ( ( proposer_id = %d AND partner_id = %d ) OR ( proposer_id = %d AND partner_id = %d ) )", $a, $b, $b, $a ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

	public static function handle_propose() {
		global $wpdb;
		$user_id = self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$to      = isset( $_POST['partner'] ) ? absint( $_POST['partner'] ) : 0;
		$type    = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$side    = isset( $_POST['side'] ) ? sanitize_key( wp_unslash( $_POST['side'] ) ) : '';
		$message = isset( $_POST['message'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) ) : '';
		// phpcs:enable
		$profile = add_query_arg( 'cmp_member', $to, CMP_Settings::member_page_url() );
		$types   = self::types();
		if ( ! $to || $to === $user_id || ! CMP_Access::is_member( $to ) || CMP_Messages::is_blocked( $user_id, $to ) ) {
			self::back( 'dyn_gone' );
		}
		if ( ! isset( $types[ $type ] ) || ( $types[ $type ]['lead'] && ! in_array( $side, array( 'a', 'b' ), true ) ) || mb_strlen( $message ) > self::MAX_MESSAGE ) {
			self::back( 'dyn_invalid', $profile );
		}
		$side = $types[ $type ]['lead'] ? $side : '';
		$t    = CMP_Install::table( 'dynamics' );
		$dupe = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE type = %s AND status IN ('pending','active') AND ( ( proposer_id = %d AND partner_id = %d ) OR ( proposer_id = %d AND partner_id = %d ) )", $type, $user_id, $to, $to, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( (int) $dupe ) {
			self::back( 'dyn_duplicate', $profile );
		}
		$pending = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE proposer_id = %d AND status = 'pending'", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( (int) $pending >= self::MAX_PENDING ) {
			self::back( 'dyn_too_many', $profile );
		}
		$wpdb->insert(
			$t,
			array(
				'type'          => $type,
				'proposer_id'   => $user_id,
				'partner_id'    => $to,
				'proposer_side' => $side,
				'status'        => 'pending',
				'message'       => $message,
				'proposer_show' => 1,
				'partner_show'  => 1,
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		$id  = (int) $wpdb->insert_id;
		$dyn = self::get( $id );
		CMP_Audit::log( 'dynamic_proposed', 'dynamic', $id, null, array( 'type' => $type, 'proposer' => $user_id, 'partner' => $to, 'proposer_side' => $side ) );
		CMP_Notifications::add(
			$to,
			'invitation',
			sprintf(
				/* translators: 1: member name, 2: their side, 3: your side */
				__( '%1$s invited you to a dynamic: they would be your %2$s and you their %3$s.', 'cmp' ),
				wp_get_current_user()->display_name,
				self::side_label( $dyn, $user_id ),
				self::side_label( $dyn, $to )
			),
			self::url()
		);
		self::back( 'dyn_sent' );
	}

	public static function handle_respond() {
		global $wpdb;
		$user_id = self::guard();
		$dyn     = self::posted_dynamic( $user_id );
		$answer  = isset( $_POST['answer'] ) ? sanitize_key( wp_unslash( $_POST['answer'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		if ( 'pending' !== $dyn->status || (int) $dyn->partner_id !== $user_id || ! in_array( $answer, array( 'accept', 'decline' ), true ) ) {
			self::back( 'dyn_gone' );
		}
		$status = 'accept' === $answer ? 'active' : 'declined';
		$wpdb->update( CMP_Install::table( 'dynamics' ), array( 'status' => $status, 'responded_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $dyn->id, 'status' => 'pending' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		CMP_Audit::log( 'dynamic_' . ( 'active' === $status ? 'accepted' : 'declined' ), 'dynamic', $dyn->id, array( 'status' => 'pending' ), array( 'status' => $status ) );
		CMP_Notifications::add(
			$dyn->proposer_id,
			'access_change',
			sprintf(
				/* translators: 1: member name, 2: dynamic type */
				'active' === $status ? __( '%1$s accepted your invitation (%2$s). The dynamic is now active.', 'cmp' ) : __( '%1$s declined your invitation (%2$s).', 'cmp' ),
				wp_get_current_user()->display_name,
				self::types()[ $dyn->type ]['label']
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
		$wpdb->update( CMP_Install::table( 'dynamics' ), array( 'status' => 'withdrawn', 'responded_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $dyn->id, 'status' => 'pending' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		CMP_Audit::log( 'dynamic_withdrawn', 'dynamic', $dyn->id, array( 'status' => 'pending' ), array( 'status' => 'withdrawn' ) );
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
		$wpdb->update( CMP_Install::table( 'dynamics' ), array( 'status' => 'ended', 'ended_at' => gmdate( 'Y-m-d H:i:s' ), 'ended_by' => $user_id ), array( 'id' => $dyn->id, 'status' => 'active' ), array( '%s', '%s', '%d' ), array( '%d', '%s' ) );
		CMP_Audit::log( 'dynamic_ended', 'dynamic', $dyn->id, array( 'status' => 'active' ), array( 'status' => 'ended', 'ended_by' => $user_id ) );
		/**
		 * A dynamic ended: anything it allowed (homework, chastity) stops.
		 *
		 * @param object $dyn     The dynamic as it was.
		 * @param int    $user_id Who ended it.
		 */
		do_action( 'cmp_dynamic_ended', $dyn, $user_id );
		CMP_Notifications::add(
			self::other( $dyn, $user_id ),
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

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	public static function pending_for( $user_id ) {
		return count(
			array_filter(
				self::for_user( $user_id, array( 'pending' ) ),
				function ( $d ) use ( $user_id ) {
					return (int) $d->partner_id === (int) $user_id;
				}
			)
		);
	}

	private static function who_html( $other_id, $line ) {
		$user = get_userdata( $other_id );
		$name = $user ? $user->display_name : __( 'Former member', 'cmp' );
		$link = $user ? '<a href="' . esc_url( add_query_arg( 'cmp_member', $other_id, CMP_Settings::member_page_url() ) ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name );
		return '<div class="cmp-who"><span class="cmp-who-av" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ) . '</span><div><b>' . $link . '</b><small>' . $line . '</small></div></div>';
	}

	private static function action_form( $action, $dyn_id, $label, $class = 'cmp-btn cmp-btn-small', $extra = array(), $confirm = '' ) {
		$html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cmp-inline-form"><input type="hidden" name="action" value="cmp_dyn_' . esc_attr( $action ) . '" /><input type="hidden" name="dynamic" value="' . (int) $dyn_id . '" />'
			. '<input type="hidden" name="_cmp_nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" />';
		foreach ( $extra as $k => $v ) {
			$html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" />';
		}
		return $html . '<button type="submit" class="' . esc_attr( $class ) . '"' . ( $confirm ? ' data-cmp-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
	}

	public static function render( $user_id ) {
		$all      = self::for_user( $user_id );
		$types    = self::types();
		$incoming = array_filter( $all, function ( $d ) use ( $user_id ) { return 'pending' === $d->status && (int) $d->partner_id === $user_id; } );
		$active   = array_filter( $all, function ( $d ) { return 'active' === $d->status; } );
		$sent     = array_filter( $all, function ( $d ) use ( $user_id ) { return 'pending' === $d->status && (int) $d->proposer_id === $user_id; } );
		wp_enqueue_script( 'cmp-member' );
		ob_start();
		?>
		<section class="cmp-step" aria-labelledby="cmp-dyn-title">
			<h2 id="cmp-dyn-title" class="cmp-title"><?php esc_html_e( 'Dynamics', 'cmp' ); ?></h2>
			<p><?php esc_html_e( 'Relationships and power dynamics with other members. Both of you must agree, and either of you can end one at any time. The leading side (for example a Keyholder or Dominant) can set homework and manage chastity for the other.', 'cmp' ); ?></p>
			<p class="cmp-muted"><?php esc_html_e( 'To propose one, open a member\'s profile and choose "Propose a dynamic".', 'cmp' ); ?></p>
		</section>
		<?php
		$sections = array(
			array( __( 'Waiting for you', 'cmp' ), $incoming, 'incoming' ),
			array( __( 'Active', 'cmp' ), $active, 'active' ),
			array( __( 'Sent by you', 'cmp' ), $sent, 'sent' ),
		);
		foreach ( $sections as list( $title, $rows, $kind ) ) :
			?>
			<section class="cmp-panel cmp-dyn-<?php echo esc_attr( $kind ); ?>">
				<h3 class="cmp-panel-title"><?php echo esc_html( $title ); ?></h3>
				<?php if ( ! $rows ) : ?>
					<p class="cmp-empty"><?php esc_html_e( 'Nothing here.', 'cmp' ); ?></p>
				<?php endif; ?>
				<?php foreach ( $rows as $d ) : ?>
					<?php
					$other = self::other( $d, $user_id );
					$type  = $types[ $d->type ];
					if ( 'incoming' === $kind ) {
						/* translators: 1: their side, 2: your side */
						$line = $type['lead'] ? sprintf( __( 'wants to be your <b>%1$s</b>; you\'d be their %2$s', 'cmp' ), esc_html( self::side_label( $d, $other ) ), esc_html( self::side_label( $d, $user_id ) ) ) : esc_html( $type['label'] );
					} elseif ( 'active' === $kind ) {
						/* translators: 1: their side, 2: since date */
						$line = sprintf( __( 'your %1$s since %2$s', 'cmp' ), esc_html( self::side_label( $d, $other ) ), esc_html( wp_date( 'M Y', strtotime( ( $d->responded_at ? $d->responded_at : $d->created_at ) . ' UTC' ) ) ) );
					} else {
						/* translators: %s: dynamic type */
						$line = sprintf( __( 'Pending · %s', 'cmp' ), esc_html( $type['label'] ) );
					}
					$mine_show = (int) $d->proposer_id === $user_id ? $d->proposer_show : $d->partner_show;
					?>
					<div class="cmp-dyn-row">
						<?php echo self::who_html( $other, $line ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
						<?php if ( 'incoming' === $kind && $d->message ) : ?>
							<p class="cmp-dyn-msg">“<?php echo esc_html( $d->message ); ?>”</p>
						<?php endif; ?>
						<div class="cmp-dyn-acts">
							<?php
							if ( 'incoming' === $kind ) {
								echo self::action_form( 'respond', $d->id, __( 'Accept', 'cmp' ), 'cmp-btn cmp-btn-small', array( 'answer' => 'accept' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo self::action_form( 'respond', $d->id, __( 'Decline', 'cmp' ), 'cmp-btn cmp-btn-small cmp-btn-outline', array( 'answer' => 'decline' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} elseif ( 'active' === $kind ) {
								echo self::action_form( 'show', $d->id, $mine_show ? __( 'Shown on my profile ✓', 'cmp' ) : __( 'Hidden from my profile', 'cmp' ), 'cmp-btn cmp-btn-small cmp-btn-outline', array( 'show' => $mine_show ? '' : '1' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo self::action_form( 'end', $d->id, __( 'End', 'cmp' ), 'cmp-btn cmp-btn-small cmp-btn-outline cmp-btn-danger', array(), __( 'End this dynamic? It stops anything it allowed straight away.', 'cmp' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} else {
								echo self::action_form( 'withdraw', $d->id, __( 'Withdraw', 'cmp' ), 'cmp-btn cmp-btn-small cmp-btn-outline' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
							?>
						</div>
					</div>
				<?php endforeach; ?>
			</section>
			<?php
		endforeach;
		return ob_get_clean();
	}

	/** "Propose a dynamic" on another member's profile. */
	public static function propose_html( $viewer_id, $owner_id ) {
		$types = self::types();
		ob_start();
		?>
		<details class="cmp-panel cmp-dyn-propose">
			<summary class="cmp-btn"><?php esc_html_e( 'Propose a dynamic', 'cmp' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmp-form">
				<input type="hidden" name="action" value="cmp_dyn_propose" />
				<input type="hidden" name="partner" value="<?php echo (int) $owner_id; ?>" />
				<input type="hidden" name="_cmp_nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>" />
				<p class="cmp-field">
					<label for="cmp_dyn_type"><?php esc_html_e( 'Type', 'cmp' ); ?></label>
					<select id="cmp_dyn_type" name="type" required data-cmp-dyn-type>
						<?php foreach ( $types as $key => $t ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" data-a="<?php echo esc_attr( $t['a'] ); ?>" data-b="<?php echo esc_attr( (string) $t['b'] ); ?>"><?php echo esc_html( $t['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<fieldset class="cmp-field cmp-dyn-side" data-cmp-dyn-side data-tpl="<?php /* translators: %s: side, e.g. Keyholder */ esc_attr_e( 'I\'m the %s', 'cmp' ); ?>">
					<legend><?php esc_html_e( 'Your side', 'cmp' ); ?></legend>
					<p class="cmp-check"><input type="radio" id="cmp_dyn_side_a" name="side" value="a" checked /> <label for="cmp_dyn_side_a" data-cmp-side-a><?php echo esc_html( sprintf( /* translators: %s: side, e.g. Keyholder */ __( 'I\'m the %s', 'cmp' ), $types['keyholder']['a'] ) ); ?></label></p>
					<p class="cmp-check"><input type="radio" id="cmp_dyn_side_b" name="side" value="b" /> <label for="cmp_dyn_side_b" data-cmp-side-b><?php echo esc_html( sprintf( /* translators: %s: side, e.g. chastity wearer */ __( 'I\'m the %s', 'cmp' ), $types['keyholder']['b'] ) ); ?></label></p>
				</fieldset>
				<p class="cmp-muted"><?php esc_html_e( 'The leading side can set homework and manage chastity for the other. Either of you can end the dynamic at any time.', 'cmp' ); ?></p>
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
		return $rows;
	}

	public static function erase( $user_id ) {
		global $wpdb;
		$t = CMP_Install::table( 'dynamics' );
		foreach ( self::for_user( $user_id, array( 'active' ) ) as $dyn ) {
			do_action( 'cmp_dynamic_ended', $dyn, $user_id );
		}
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE proposer_id = %d OR partner_id = %d", $user_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
