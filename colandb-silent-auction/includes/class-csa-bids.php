<?php
defined( 'ABSPATH' ) || exit;

/** Placing a bid: the rules, the safe update, the bid record and the outbid email. */
class CSA_Bids {

	const NONCE = 'csa_bid';
	/** A single bid above this is refused as a likely typo ($50,000). */
	const MAX_CENTS = 5000000;

	public static function init() {
		add_action( 'admin_post_csa_bid', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_csa_bid', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Places a bid. Returns a result code:
	 * 'placed', 'bought', 'login', 'closed', 'not_open', 'too_low', 'too_high', 'whole',
	 * 'already_top', 'no_buy_now', 'busy' (someone else bid at the same moment; try again), 'bad'.
	 */
	public static function place( $item_id, $user_id, $cents, $buy_now = false, $now = null ) {
		global $wpdb;
		$now     = null === $now ? time() : $now;
		$item_id = (int) $item_id;
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return 'login';
		}
		if ( CSA_Data::ITEM !== get_post_type( $item_id ) || 'publish' !== get_post_status( $item_id ) ) {
			return 'bad';
		}
		$item    = CSA_Data::item( $item_id );
		$auction = CSA_Data::auction( $item['auction'] );
		if ( ! $auction['start'] || 'publish' !== get_post_status( $auction['id'] ) || $now < $auction['start'] ) {
			return 'not_open';
		}
		if ( ( '' !== $auction['closed'] ) || $now >= $item['end'] ) {
			return 'closed';
		}
		if ( $buy_now ) {
			if ( ! $item['buy_now'] || $item['high'] >= $item['buy_now'] ) {
				return 'no_buy_now';
			}
			$cents = $item['buy_now'];
		}
		if ( null === $cents || $cents <= 0 ) {
			return 'bad';
		}
		if ( 0 !== $cents % 100 ) {
			return 'whole'; // Bids are whole dollars.
		}
		if ( $item['high_user'] === $user_id && ! $buy_now ) {
			return 'already_top';
		}
		if ( $cents < $item['min_next'] ) {
			return 'too_low';
		}
		if ( $cents > self::MAX_CENTS ) {
			return 'too_high';
		}
		if ( $item['buy_now'] && $cents >= $item['buy_now'] ) {
			$buy_now = true; // Bidding the Buy It Now price buys it.
			$cents   = $item['buy_now'];
		}

		// Compare-and-swap on the top bid: only one of two simultaneous bids
		// can change it from the value both of them saw.
		$old = (string) get_post_meta( $item_id, '_csa_high', true );
		if ( ! metadata_exists( 'post', $item_id, '_csa_high' ) ) {
			add_post_meta( $item_id, '_csa_high', '', true );
			$old = '';
		}
		$new     = $cents . '|' . $user_id;
		$changed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = '_csa_high' AND meta_value = %s",
				$new,
				$item_id,
				$old
			)
		);
		wp_cache_delete( $item_id, 'post_meta' );
		if ( 1 !== (int) $changed ) {
			return 'busy';
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			CSA_Install::table(),
			array(
				'item_id'     => $item_id,
				'auction_id'  => $auction['id'],
				'user_id'     => $user_id,
				'amount'      => $cents,
				'buy_now'     => $buy_now ? 1 : 0,
				'created_gmt' => gmdate( 'Y-m-d H:i:s', $now ),
			)
		);
		update_post_meta( $item_id, '_csa_bid_count', self::count( $item_id ) );
		if ( $buy_now ) {
			update_post_meta( $item_id, '_csa_sold_at', $now );
		} elseif ( $auction['antisnipe'] && $item['end'] - $now < CSA_Data::SNIPE_SEC ) {
			update_post_meta( $item_id, '_csa_extended', $now + CSA_Data::SNIPE_SEC );
		}

		if ( $item['high_user'] && $item['high_user'] !== $user_id ) {
			CSA_Emails::outbid( $item['high_user'], $item_id, $cents, $buy_now );
		}
		return $buy_now ? 'bought' : 'placed';
	}

	public static function count( $item_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CSA_Install::table() . ' WHERE item_id = %d', $item_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Every bid on an item, newest first (for wp-admin only). */
	public static function history( $item_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CSA_Install::table() . ' WHERE item_id = %d ORDER BY amount DESC, id DESC', $item_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Item ids this person has bid on in an auction. */
	public static function items_bid_by( $user_id, $auction_id ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT item_id FROM ' . CSA_Install::table() . ' WHERE user_id = %d AND auction_id = %d', $user_id, $auction_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** The form post from the Auction page. */
	public static function handle() {
		$item_id = isset( $_POST['csa_item'] ) ? absint( $_POST['csa_item'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$back    = isset( $_POST['csa_back'] ) ? esc_url_raw( wp_unslash( $_POST['csa_back'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$back    = wp_validate_redirect( $back, home_url( '/' ) );
		$back    = remove_query_arg( array( 'csa_msg', 'csa_item' ), $back );
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $back . '#csa-item-' . $item_id ) );
			exit;
		}
		if ( ! isset( $_POST['csa_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['csa_nonce'] ) ), self::NONCE ) ) {
			$code = 'expired';
		} else {
			$buy_now = ! empty( $_POST['csa_buy_now'] );
			$cents   = $buy_now ? 0 : CSA_Data::to_cents( isset( $_POST['csa_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['csa_amount'] ) ) : '' );
			$code    = self::place( $item_id, get_current_user_id(), $cents, $buy_now );
			if ( 'busy' === $code ) {
				// Someone bid at the same moment: check this bid again against the new top bid.
				$code = self::place( $item_id, get_current_user_id(), $cents, $buy_now );
			}
			if ( 'busy' === $code ) {
				$code = 'too_low';
			}
		}
		wp_safe_redirect( add_query_arg( array( 'csa_msg' => $code, 'csa_item' => $item_id ), $back ) . '#csa-item-' . $item_id );
		exit;
	}
}
