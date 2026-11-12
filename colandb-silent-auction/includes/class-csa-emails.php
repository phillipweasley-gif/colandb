<?php
defined( 'ABSPATH' ) || exit;

/**
 * The emails. Plain text, sent with wp_mail(), so they go out through
 * the site's mail setup (FluentSMTP on colandb.com: "COL&B", noreply@colandb.com).
 * The owner approved none of this wording yet: see the CHANGELOG before release.
 */
class CSA_Emails {

	private static function send( $to, $subject, $body ) {
		/**
		 * Lets a test or another plugin stop or change an auction email.
		 *
		 * @param array $mail { to, subject, body }.
		 */
		$mail = apply_filters( 'csa_email', array( 'to' => $to, 'subject' => $subject, 'body' => $body ) );
		if ( empty( $mail['to'] ) ) {
			return false;
		}
		return wp_mail( $mail['to'], $mail['subject'], $mail['body'] );
	}

	/** To the person whose top bid was just beaten. */
	public static function outbid( $user_id, $item_id, $new_cents, $bought ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}
		$item    = CSA_Data::item( $item_id );
		$auction = CSA_Data::auction( $item['auction'] );
		$link    = CSA_Install::page_url() . '#csa-item-' . $item_id;
		if ( $bought ) {
			/* translators: %s: item name */
			$subject = sprintf( __( '%s has been bought', 'csa' ), $item['title'] );
			$body    = sprintf(
				/* translators: 1: first name, 2: item, 3: auction, 4: price, 5: link to the auction */
				__( "Hi %1\$s,\n\nSomeone bought %2\$s in the %3\$s with Buy It Now for %4\$s, so your bid on it is no longer in the running.\n\nThere may be other items you'd like:\n%5\$s\n\nThank you for supporting COL&B.\n\nCOL&B", 'csa' ),
				CSA_Data::first_name( $user ),
				$item['title'],
				$auction['title'],
				CSA_Data::money( $new_cents ),
				CSA_Install::page_url()
			);
		} else {
			/* translators: %s: item name */
			$subject = sprintf( __( "You've been outbid on %s", 'csa' ), $item['title'] );
			$body    = sprintf(
				/* translators: 1: first name, 2: item, 3: auction, 4: new top bid, 5: closing time, 6: link to the item */
				__( "Hi %1\$s,\n\nSomeone has placed a higher bid on %2\$s in the %3\$s. The top bid is now %4\$s.\n\nBidding closes %5\$s. To bid again:\n%6\$s\n\nThank you for supporting COL&B.\n\nCOL&B", 'csa' ),
				CSA_Data::first_name( $user ),
				$item['title'],
				$auction['title'],
				CSA_Data::money( $new_cents ),
				CSA_Data::when( $item['end'] ),
				$link
			);
		}
		return self::send( $user->user_email, $subject, $body );
	}

	/** To a winner, once, with every item they won and the Pay now link. */
	public static function winner( $user_id, $auction_id, $lines, $order ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}
		$auction = CSA_Data::auction( $auction_id );
		$list    = '';
		foreach ( $lines as $line ) {
			$list .= '- ' . $line['title'] . ': ' . CSA_Data::money( $line['cents'] ) . "\n";
		}
		$extra = '';
		if ( (float) $order->get_total_tax() > 0 ) {
			$extra .= __( 'Sales tax', 'csa' ) . ': ' . CSA_Data::money( (int) round( (float) $order->get_total_tax() * 100 ) ) . "\n";
		}
		$list  .= $extra;
		$pickup = '' !== trim( $auction['pickup'] ) ? "\n" . __( 'Collecting your items:', 'csa' ) . "\n" . trim( $auction['pickup'] ) . "\n" : '';
		$by     = (int) $order->get_meta( '_csa_pay_by' );
		$notice = '' !== trim( $auction['tax_notice'] ) ? "\n" . trim( $auction['tax_notice'] ) . "\n" : '';
		$who    = '' !== trim( $auction['collector'] ) ? sprintf( /* translators: %s: business name */ __( ' Payment is collected for COL&B by %s.', 'csa' ), trim( $auction['collector'] ) ) : '';
		/* translators: %s: auction name */
		$subject = sprintf( __( 'You won! Pay for your %s items', 'csa' ), $auction['title'] );
		$body    = sprintf(
			/* translators: 1: first name, 2: auction, 3: list of items, 4: total, 5: pay-by date, 6: pay link, 7: (unused, empty), 8: pickup paragraph, 9: tax notice, 10: who collects, 11: terms link */
			__( "Hi %1\$s,\n\nCongratulations! You won these items in the %2\$s:\n\n%3\$s\nTotal: %4\$s\n\nPlease pay online by %5\$s (sign in with your colandb.com account if asked):\n%6\$s\n%7\$s%8\$s\nYour winning bids (less card processing costs) go to COL&B's fundraising. There are no other fees.%10\$s\n%9\$s\nAuction terms: %11\$s\n\nThank you for your support!\n\nCOL&B", 'csa' ),
			CSA_Data::first_name( $user ),
			$auction['title'],
			$list,
			CSA_Data::money( (int) round( (float) $order->get_total() * 100 ) ),
			$by ? wp_date( __( 'l, F j', 'csa' ), $by ) : __( 'the date on the Auction page', 'csa' ),
			$order->get_checkout_payment_url(),
			'',
			$pickup,
			$notice,
			$who,
			CSA_Install::page_url() . '#csa-terms'
		);
		return self::send( $user->user_email, $subject, $body );
	}

	/** In a fixed-price sale: to the buyer, as soon as they buy, with the Pay now link. */
	public static function purchase( $user_id, $auction_id, $lines, $order ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}
		$auction = CSA_Data::auction( $auction_id );
		$list    = '';
		foreach ( $lines as $line ) {
			$list .= '- ' . $line['title'] . ': ' . CSA_Data::money( $line['cents'] ) . "\n";
		}
		if ( (float) $order->get_total_tax() > 0 ) {
			$list .= __( 'Sales tax', 'csa' ) . ': ' . CSA_Data::money( (int) round( (float) $order->get_total_tax() * 100 ) ) . "\n";
		}
		$pickup = '' !== trim( $auction['pickup'] ) ? "\n" . __( 'Collecting your items:', 'csa' ) . "\n" . trim( $auction['pickup'] ) . "\n" : '';
		$by     = (int) $order->get_meta( '_csa_pay_by' );
		$notice = '' !== trim( $auction['tax_notice'] ) ? "\n" . trim( $auction['tax_notice'] ) . "\n" : '';
		$who    = '' !== trim( $auction['collector'] ) ? sprintf( /* translators: %s: business name */ __( ' Payment is collected for COL&B by %s.', 'csa' ), trim( $auction['collector'] ) ) : '';
		/* translators: %s: item name */
		$subject = sprintf( __( 'Thank you! Pay for %s', 'csa' ), $lines[0]['title'] );
		$body    = sprintf(
			/* translators: 1: first name, 2: sale name, 3: list of items, 4: total, 5: pay-by date, 6: pay link, 7: pickup paragraph, 8: tax notice, 9: who collects, 10: terms link */
			__( "Hi %1\$s,\n\nThank you for buying from the %2\$s:\n\n%3\$s\nTotal: %4\$s\n\nIf you haven't paid yet, please pay online by %5\$s (sign in with your colandb.com account if asked):\n%6\$s\n\nIf it isn't paid by then, we may cancel the order and offer the item to someone else.\n%7\$s\nYour payment (less card processing costs) goes to COL&B's fundraising. There are no other fees.%9\$s\n%8\$s\nSale terms: %10\$s\n\nThank you for your support!\n\nCOL&B", 'csa' ),
			CSA_Data::first_name( $user ),
			$auction['title'],
			$list,
			CSA_Data::money( (int) round( (float) $order->get_total() * 100 ) ),
			$by ? wp_date( __( 'l, F j', 'csa' ), $by ) : __( 'the date on the Sale page', 'csa' ),
			$order->get_checkout_payment_url(),
			$pickup,
			$notice,
			$who,
			CSA_Install::page_url() . '#csa-terms'
		);
		return self::send( $user->user_email, $subject, $body );
	}

	/** To the site administrator when an auction closes. */
	public static function summary( $auction_id, $winners, $total_cents, $unsold ) {
		$auction = CSA_Data::auction( $auction_id );
		if ( $auction['sale'] ) {
			/* translators: 1: sale, 2: total of items sold */
			$subject = sprintf( __( '%1$s has ended: %2$s in sales', 'csa' ), $auction['title'], CSA_Data::money( $total_cents ) );
			$body    = sprintf(
				/* translators: 1: sale, 2: number of buyers, 3: total, 4: number of items not sold, 5: wp-admin link */
				__( "The %1\$s has ended.\n\nBuyers: %2\$d\nSales: %3\$s\nItems not sold: %4\$d\n\nEach buyer was emailed a link to pay when they bought. To see who bought what and who has paid:\n%5\$s\n\nCOL&B website", 'csa' ),
				$auction['title'],
				$winners,
				CSA_Data::money( $total_cents ),
				$unsold,
				admin_url( 'post.php?post=' . (int) $auction_id . '&action=edit' )
			);
			return self::send( get_option( 'admin_email' ), $subject, $body );
		}
		/* translators: 1: auction, 2: total of winning bids */
		$subject = sprintf( __( '%1$s has closed: %2$s in winning bids', 'csa' ), $auction['title'], CSA_Data::money( $total_cents ) );
		$body    = sprintf(
			/* translators: 1: auction, 2: number of winners, 3: total, 4: number of items without bids, 5: wp-admin link */
			__( "The %1\$s has closed.\n\nWinners: %2\$d\nWinning bids: %3\$s\nItems with no bids: %4\$d\n\nEach winner has been emailed a link to pay. To see who won and who has paid:\n%5\$s\n\nCOL&B website", 'csa' ),
			$auction['title'],
			$winners,
			CSA_Data::money( $total_cents ),
			$unsold,
			admin_url( 'post.php?post=' . (int) $auction_id . '&action=edit' )
		);
		return self::send( get_option( 'admin_email' ), $subject, $body );
	}
}
