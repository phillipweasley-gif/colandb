<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * New stars and Locktober medals: notify, and let the member share (0.37.0).
 * Owner, 2026-10-22: "We also want a notification for when they earn their
 * new star and a way to share that via the social media options we
 * currently provide as an announcement."
 *
 * - check(): when the wearer's running run (CMP_Chastity::run_for) reaches
 *   a new star (bronze/emerald/ruby week, gold month, sapphire, platinum,
 *   diamond) or a new Locktober medal, the wearer gets a notification and so
 *   does a member keyholder. Runs hourly (cron) and whenever the wearer opens
 *   the Chastity tab. What was last announced is kept in user meta, so each
 *   star is announced once; the first check after installing only records
 *   where people are, without announcing old stars.
 * - share_html(): "Share your milestone" on the Chastity tab. Post it to
 *   the member feed as an announcement (all members or connections; the text
 *   can be edited), or share it outside COL&B with the links the events
 *   already use (WhatsApp, Telegram, text message, Facebook, X, email, copy).
 *   Outside links carry only the text and colandb.com, never a member page,
 *   and nothing is shared unless the member presses a button.
 */
class CMP_Star_News {

	const HOOK      = 'cmp_cl_stars_check';
	const META_STAR = 'cmp_cl_star_seen';   // "<run start>|<label>"
	const META_LT   = 'cmp_cl_lt_seen';     // last Locktober year announced

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'check_all' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Wearers with a running lock, or one that ended in the last week (a Locktober medal can land after). */
	public static function check_all() {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT wearer_id FROM ' . CMP_Install::table( 'locks' ) . " WHERE status = 'locked' OR ( status = 'ended' AND ended_at > %s )", gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $ids as $id ) {
			self::check( (int) $id );
		}
	}

	/** The newest star a run has, as a tier key ('gold'…), or ''. */
	public static function top_tier( $m ) {
		foreach ( array( 'ruby', 'emerald', 'bronze' ) as $w ) {
			if ( $m[ $w ] && ! $m['gold'] && ! $m['sapphire'] && ! $m['platinum'] && ! $m['diamond'] ) {
				return $w;
			}
		}
		// The star just reached is the smallest unit that's there: gold for a month, sapphire at 6, platinum at 12…
		if ( $m['gold'] ) {
			return 'gold';
		}
		if ( $m['sapphire'] ) {
			return 'sapphire';
		}
		return $m['platinum'] ? 'platinum' : ( $m['diamond'] ? 'diamond' : '' );
	}

	/**
	 * Announce a new star or medal for one wearer, once.
	 *
	 * @return string[] What was announced ('star', 'locktober').
	 */
	public static function check( $wearer_id ) {
		$done  = array();
		$lock  = CMP_Chastity::active_lock( $wearer_id );
		$first = '' === (string) get_user_meta( $wearer_id, self::META_STAR, true ) && '' === (string) get_user_meta( $wearer_id, self::META_LT, true );
		if ( $lock ) {
			$run   = CMP_Chastity::run_for( $lock );
			$m     = CMP_Chastity::milestones( $run );
			$label = CMP_Chastity::milestone_label( $m );
			$sig   = $run->started_at . '|' . $label;
			$seen  = (string) get_user_meta( $wearer_id, self::META_STAR, true );
			if ( $sig !== $seen ) {
				update_user_meta( $wearer_id, self::META_STAR, $sig );
				if ( ! $first && '' !== $label ) {
					$tier = self::top_tier( $m );
					$name = CMP_Chastity::star_tiers()[ $tier ];
					/* translators: 1: "an emerald star", 2: "2 weeks" */
					CMP_Notifications::add( $wearer_id, 'lock', sprintf( __( 'You earned %1$s: %2$s locked! Share your milestone from the Chastity tab.', 'cmp' ), $name, $label ), self::share_url() );
					if ( $lock->keyholder_id && CMP_Chastity::is_keyholder( $lock, (int) $lock->keyholder_id ) ) {
						/* translators: 1: wearer, 2: "an emerald star", 3: "2 weeks" */
						CMP_Notifications::add( (int) $lock->keyholder_id, 'lock', sprintf( __( '%1$s earned %2$s: %3$s locked.', 'cmp' ), get_userdata( $wearer_id ) ? get_userdata( $wearer_id )->display_name : '', $name, $label ), CMP_Chastity::url( array( 'lock' => $lock->id ) ) );
					}
					$done[] = 'star';
				}
			}
		}
		$years = CMP_Chastity::locktober_years( $wearer_id );
		$top   = $years ? (int) $years[0] : 0;
		$seen  = (int) get_user_meta( $wearer_id, self::META_LT, true );
		if ( $top > $seen ) {
			update_user_meta( $wearer_id, self::META_LT, $top );
			if ( ! $first ) {
				/* translators: %d: year */
				CMP_Notifications::add( $wearer_id, 'lock', sprintf( __( 'You completed Locktober %d! 🏅 Share it from the Chastity tab.', 'cmp' ), $top ), self::share_url( 'locktober' ) );
				$done[] = 'locktober';
			}
		} elseif ( $first ) {
			update_user_meta( $wearer_id, self::META_LT, $top );
		}
		return $done;
	}

	public static function share_url( $what = 'star' ) {
		return CMP_Chastity::url( array( 'share' => $what ) ) . '#cmp-cl-share';
	}

	/** The announcement text: the current run's latest star, a Locktober medal, or the longest run. */
	public static function share_text( $user_id, $what = 'star' ) {
		$years = CMP_Chastity::locktober_years( $user_id );
		if ( 'locktober' === $what && $years ) {
			/* translators: %d: year */
			return sprintf( __( 'I completed Locktober %d! 🏅 #Locktober', 'cmp' ), $years[0] );
		}
		$lock = CMP_Chastity::active_lock( $user_id );
		$run  = $lock ? CMP_Chastity::run_for( $lock ) : CMP_Chastity::best_run( $user_id );
		if ( ! $run ) {
			return '';
		}
		$m     = CMP_Chastity::milestones( $run );
		$label = CMP_Chastity::milestone_label( $m );
		if ( '' === $label ) {
			return '';
		}
		$tag  = '10' === wp_date( 'm' ) ? ' #Locktober' : '';
		$name = CMP_Chastity::star_tiers()[ self::top_tier( $m ) ];
		return $lock
			/* translators: 1: "a gold star", 2: "1 month" */
			? sprintf( __( 'I just earned %1$s: %2$s locked! ⭐', 'cmp' ), $name, $label ) . $tag
			/* translators: %s: "1 month" */
			: sprintf( __( 'My longest lock: %s! ⭐', 'cmp' ), $label ) . $tag;
	}

	private static function links( $text ) {
		$url    = home_url( '/' );
		$full   = $text . ' ' . $url;
		$is_ios = isset( $_SERVER['HTTP_USER_AGENT'] ) && preg_match( '/iPad|iPhone|iPod/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) );
		return array(
			'whatsapp' => array( 'WhatsApp', 'https://wa.me/?text=' . rawurlencode( $full ) ),
			'telegram' => array( 'Telegram', 'https://t.me/share/url?url=' . rawurlencode( $url ) . '&text=' . rawurlencode( $text ) ),
			'sms'      => array( __( 'Text message', 'cmp' ), 'sms:' . ( $is_ios ? '&' : '?' ) . 'body=' . rawurlencode( $full ) ),
			'facebook' => array( 'Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url ) . '&quote=' . rawurlencode( $text ) ),
			'twitter'  => array( 'X', 'https://twitter.com/intent/tweet?text=' . rawurlencode( $text ) . '&url=' . rawurlencode( $url ) ),
			'email'    => array( __( 'Email', 'cmp' ), 'mailto:?subject=' . rawurlencode( $text ) . '&body=' . rawurlencode( $full ) ),
		);
	}

	/** "Share your milestone": member feed announcement, or outside links. */
	public static function share_html( $user_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$what = isset( $_GET['share'] ) && 'locktober' === sanitize_key( wp_unslash( $_GET['share'] ) ) ? 'locktober' : 'star';
		$text = self::share_text( $user_id, $what );
		if ( '' === $text ) {
			return '';
		}
		$open  = isset( $_GET['share'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$html  = '<details class="cmp-hw-taskedit cmp-cl-share-ms" id="cmp-cl-share"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html__( 'Share your milestone', 'cmp' ) . '</summary>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cmp-form"><input type="hidden" name="action" value="cmp_chastity" /><input type="hidden" name="do" value="announce" /><input type="hidden" name="_cmp_nonce" value="' . esc_attr( wp_create_nonce( CMP_Chastity::NONCE ) ) . '" />';
		$html .= '<p class="cmp-field"><label for="cmp_cl_ann">' . esc_html__( 'Announce it on the member feed', 'cmp' ) . '</label><textarea id="cmp_cl_ann" name="body" rows="2" maxlength="500">' . esc_textarea( $text ) . '</textarea></p>';
		$vis   = '';
		foreach ( CMP_Feed::visibilities() as $k => $l ) {
			$vis .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
		}
		$html .= '<p class="cmp-field"><label for="cmp_cl_annv">' . esc_html__( 'Who sees it', 'cmp' ) . '</label><select id="cmp_cl_annv" name="visibility">' . $vis . '</select></p><button type="submit" class="cmp-btn cmp-btn-small">' . esc_html__( 'Post to the feed', 'cmp' ) . '</button></form>';
		$html .= '<p class="cmp-field"><b>' . esc_html__( 'Or share it outside COL&B', 'cmp' ) . '</b><br /><span class="cmp-muted">' . esc_html__( 'Only this text and a link to colandb.com are shared, never your profile. Think about who will see it before you post.', 'cmp' ) . '</span></p><p class="cmp-cl-share-links">';
		foreach ( self::links( $text ) as $k => $l ) {
			$html .= '<a class="cmp-btn cmp-btn-small cmp-btn-outline" href="' . esc_url( $l[1], array( 'https', 'sms', 'mailto' ) ) . '" target="_blank" rel="noopener noreferrer" data-share="' . esc_attr( $k ) . '">' . esc_html( $l[0] ) . '</a> ';
		}
		$html .= '</p><p class="cmp-cal-feed-copy"><input type="text" id="cmp_cl_share_txt" value="' . esc_attr( $text . ' ' . home_url( '/' ) ) . '" readonly aria-label="' . esc_attr__( 'Text to share', 'cmp' ) . '" /><button type="button" class="cmp-btn cmp-btn-small cmp-btn-outline" data-cmp-copy="#cmp_cl_share_txt" data-cmp-copied="' . esc_attr__( 'Copied', 'cmp' ) . '" data-cmp-select="' . esc_attr__( 'Text selected, copy it', 'cmp' ) . '">' . esc_html__( 'Copy', 'cmp' ) . '</button></p>';
		return $html . '</details>';
	}

	/** do=announce: post the milestone to the member feed. */
	public static function announce( $user_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- CMP_Chastity::handle() checked it.
		$body = isset( $_POST['body'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) ) : '';
		$vis  = isset( $_POST['visibility'] ) ? sanitize_key( wp_unslash( $_POST['visibility'] ) ) : '';
		// phpcs:enable
		if ( '' === $body || mb_strlen( $body ) > 500 || ! isset( CMP_Feed::visibilities()[ $vis ] ) || '' === self::share_text( $user_id ) ) {
			return false;
		}
		return CMP_Feed::announce( $user_id, $body, $vis );
	}
}
