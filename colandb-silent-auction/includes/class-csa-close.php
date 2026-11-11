<?php
defined( 'ABSPATH' ) || exit;

/**
 * Closing an auction: one WooCommerce order per winner, the winner and
 * summary emails, and adding paid orders to the linked fundraiser.
 *
 * An auction is closed (once) by whichever comes first after every item's
 * bidding has ended: the 5-minute check, someone opening the Auction page,
 * or the site team opening the auction in wp-admin.
 */
class CSA_Close {

	public static function init() {
		add_action( CSA_Install::CRON, array( __CLASS__, 'check_all' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'order_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'order_paid' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'order_cancelled' ) );
	}

	public static function check_all() {
		$ids = get_posts(
			array(
				'post_type'      => CSA_Data::AUCTION,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => '_csa_end', 'value' => time(), 'compare' => '<=', 'type' => 'NUMERIC' ),
				),
			)
		);
		foreach ( $ids as $id ) {
			self::maybe_close( $id );
		}
	}

	/** Closes the auction if every item's bidding has ended. Returns true if it closed now. */
	public static function maybe_close( $auction_id, $now = null ) {
		global $wpdb;
		$auction_id = (int) $auction_id;
		if ( 'publish' !== get_post_status( $auction_id ) || 'closing' !== CSA_Data::auction_status( $auction_id, $now ) ) {
			return false;
		}
		// Claim the closing so it happens once, even if two requests arrive together.
		if ( ! metadata_exists( 'post', $auction_id, '_csa_closed' ) ) {
			add_post_meta( $auction_id, '_csa_closed', '', true );
		}
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = 'closing' WHERE post_id = %d AND meta_key = '_csa_closed' AND meta_value = ''", $auction_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $auction_id, 'post_meta' );
		if ( 1 !== (int) $claimed ) {
			return false;
		}

		$by_winner = array();
		$unsold    = 0;
		$buyers    = array();
		$total     = 0;
		foreach ( CSA_Data::item_ids( $auction_id ) as $item_id ) {
			$item = CSA_Data::item( $item_id );
			if ( ! $item['high'] || ! $item['high_user'] ) {
				++$unsold;
				continue;
			}
			if ( $item['order'] ) {
				// Bought in a fixed-price sale: its order and email went out then.
				$buyers[ $item['high_user'] ] = true;
				$total                       += $item['high'];
				continue;
			}
			$by_winner[ $item['high_user'] ][] = array(
				'id'    => $item_id,
				'title' => $item['title'],
				'cents' => $item['high'],
			);
		}

		foreach ( $by_winner as $user_id => $lines ) {
			$order = self::create_order( $auction_id, $user_id, $lines );
			if ( ! $order ) {
				continue;
			}
			foreach ( $lines as $line ) {
				update_post_meta( $line['id'], '_csa_order', $order->get_id() );
				$total += $line['cents'];
			}
			CSA_Emails::winner( $user_id, $auction_id, $lines, $order );
			$buyers[ $user_id ] = true;
		}
		update_post_meta( $auction_id, '_csa_closed', (string) ( null === $now ? time() : $now ) );
		CSA_Emails::summary( $auction_id, count( $buyers ), $total, $unsold );
		return true;
	}

	/**
	 * In a fixed-price sale: the buyer's order for one item, made as soon as they buy it,
	 * and the email with its pay link. Returns the order or null.
	 */
	public static function sale_order( $auction_id, $user_id, $item_id ) {
		$item  = CSA_Data::item( $item_id );
		$lines = array( array( 'id' => (int) $item_id, 'title' => $item['title'], 'cents' => $item['high'] ) );
		$order = self::create_order( $auction_id, $user_id, $lines );
		if ( ! $order ) {
			return null;
		}
		update_post_meta( $item_id, '_csa_order', $order->get_id() );
		CSA_Emails::purchase( $user_id, $auction_id, $lines, $order );
		return $order;
	}

	/**
	 * A cancelled order (the site team cancels one that wasn't paid in time): in a sale
	 * that is still open, its unpaid items go back on sale. In an auction nothing changes;
	 * the site team decides what to do with the item.
	 */
	public static function order_cancelled( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order || ! $order->get_meta( '_csa_auction' ) ) {
			return;
		}
		$auction_id = (int) $order->get_meta( '_csa_auction' );
		$a          = CSA_Data::auction( $auction_id );
		if ( ! $a['sale'] || 'open' !== CSA_Data::auction_status( $auction_id ) ) {
			return;
		}
		foreach ( $order->get_items() as $li ) {
			$item_id = (int) $li->get_meta( '_csa_item' );
			if ( ! $item_id || (int) get_post_meta( $item_id, '_csa_order', true ) !== (int) $order_id || '1' === get_post_meta( $item_id, '_csa_paid', true ) ) {
				continue;
			}
			update_post_meta( $item_id, '_csa_high', '' );
			update_post_meta( $item_id, '_csa_sold_at', 0 );
			update_post_meta( $item_id, '_csa_order', 0 );
		}
		$order->add_order_note( __( 'Cancelled, so its items are back on sale.', 'csa' ) );
	}

	/** A pending order holding a winner's items, to pay on WooCommerce's "pay for order" page. */
	private static function create_order( $auction_id, $user_id, $lines ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return null;
		}
		$user  = get_userdata( $user_id );
		$order = wc_create_order(
			array(
				'customer_id' => (int) $user_id,
				'created_via' => 'csa', // Not "checkout", so WooCommerce never cancels it as an abandoned cart.
				'status'      => 'pending',
			)
		);
		if ( is_wp_error( $order ) || ! $order ) {
			return null;
		}
		$a = CSA_Data::auction( $auction_id );
		foreach ( $lines as $line ) {
			$li = new WC_Order_Item_Product();
			/* translators: %s: item name */
			$li->set_name( sprintf( $a['sale'] ? __( 'COL&B sale: %s', 'csa' ) : __( 'Silent auction: %s', 'csa' ), $line['title'] ) );
			$li->set_quantity( 1 );
			$li->set_subtotal( $line['cents'] / 100 );
			$li->set_total( $line['cents'] / 100 );
			$li->add_meta_data( '_csa_item', $line['id'], true );
			$order->add_item( $li );
		}
		if ( $user ) {
			$order->set_billing_email( $user->user_email );
			$order->set_billing_first_name( (string) get_user_meta( $user_id, 'first_name', true ) );
			$order->set_billing_last_name( (string) get_user_meta( $user_id, 'last_name', true ) );
		}
		$order->update_meta_data( '_csa_auction', (int) $auction_id );
		$order->calculate_totals( $a['tax'] && wc_tax_enabled() );
		$order->update_meta_data( '_csa_pay_by', time() + $a['pay_days'] * DAY_IN_SECONDS );
		/* translators: %s: auction or sale name */
		$order->add_order_note( sprintf( $a['sale'] ? __( 'Created when the item was bought in %s.', 'csa' ) : __( 'Created when the %s silent auction closed.', 'csa' ), get_the_title( $auction_id ) ) );
		$order->save();
		return $order;
	}

	/**
	 * A paid order (WooCommerce "Processing" or "Completed", including one the
	 * site team marks Completed by hand for cash at an event): its items are
	 * marked paid and the amount is added to the linked fundraiser, once.
	 */
	public static function order_paid( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order || ! $order->get_meta( '_csa_auction' ) || $order->get_meta( '_csa_counted' ) ) {
			return;
		}
		$order->update_meta_data( '_csa_counted', '1' );
		$order->save();
		$cents = 0;
		foreach ( $order->get_items() as $li ) {
			$item_id = (int) $li->get_meta( '_csa_item' );
			if ( $item_id ) {
				update_post_meta( $item_id, '_csa_paid', '1' );
			}
			$cents += (int) round( (float) $li->get_total() * 100 );
		}
		$auction = CSA_Data::auction( (int) $order->get_meta( '_csa_auction' ) );
		$gross   = $cents;
		$cents  -= (int) round( $cents * $auction['costs_pct'] / 10000 ); // What COL&B receives after card costs.
		if ( $auction['fundraiser'] && function_exists( 'cec_fundraiser_add_donation' ) && $cents > 0 ) {
			$added = cec_fundraiser_add_donation(
				$auction['fundraiser'],
				CSA_Data::field( $cents ),
				'auction',
				/* translators: 1: auction or sale name, 2: order number, 3: winning bids or sale prices, 4: card costs percent */
				sprintf( $auction['sale'] ? __( '%1$s, order #%2$s: %3$s in sales less %4$s card costs', 'csa' ) : __( '%1$s, order #%2$s: %3$s in bids less %4$s card costs', 'csa' ), $auction['title'], $order->get_order_number(), CSA_Data::money( $gross ), CSA_Data::pct_text( $auction['costs_pct'] ) ),
				wp_date( 'Y-m-d' )
			);
			if ( $added ) {
				/* translators: 1: amount, 2: fundraiser */
				$order->add_order_note( sprintf( __( 'Added %1$s to the fundraiser "%2$s".', 'csa' ), CSA_Data::money( $cents ), CSA_Data::fundraiser_title( $auction['fundraiser'] ) ) );
			}
		}
	}
}
