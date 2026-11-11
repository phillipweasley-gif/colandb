<?php
defined( 'ABSPATH' ) || exit;

/**
 * Auctions, items and the facts about them.
 *
 * Auction (`csa_auction`, not public): title, description, photo.
 *   _csa_start, _csa_end    Unix times (UTC) bidding opens and closes.
 *   _csa_fundraiser          CEC fundraiser id paid orders are added to (0 = none).
 *   _csa_antisnipe           '1': a bid in the last 2 minutes gives that item 2 more minutes.
 *   _csa_pickup              How winners collect their items (shown to winners).
 *   _csa_tax                 '1': winners' orders add sales tax (WooCommerce → Settings → Tax rates).
 *   _csa_costs_pct           Card processing costs included in the bids, in hundredths of a
 *                            percent (300 = 3%). Never added on top (card rules forbid a mandatory
 *                            surcharge on debit cards and Square doesn't support one online); it is
 *                            taken off each paid bid before it is added to the fundraiser, and said
 *                            on the page, so the total shows what COL&B actually receives.
 *   _csa_collector           Who collects the payments (e.g. "Weasley Studios"), said on the page.
 *   _csa_pay_days            Days winners have to pay (default 7).
 *   _csa_tax_notice          The tax-deductibility notice shown on the page and in the winner email.
 *   _csa_terms               The auction terms (shown on the page; bidding means agreeing).
 *   _csa_closed              '' while open; 'closing' while winners are worked out; then the Unix time.
 *
 * Item (`csa_item`, not public): title, description, photo.
 *   _csa_auction             The auction it belongs to.
 *   _csa_donor, _csa_value   "Donated by" and its value in cents (shown).
 *   _csa_start_bid           Starting bid in cents.
 *   _csa_increment           Minimum raise in cents.
 *   _csa_buy_now             Buy It Now price in cents (0 = none).
 *   _csa_high                "<cents>|<user id>" of the top bid, '' for none. Changed only by
 *                            CSA_Bids::place() with a compare-and-swap, so two bids can't both win.
 *   _csa_bid_count           Number of bids.
 *   _csa_extended            Unix time this item's bidding was extended to (anti-sniping), or 0.
 *   _csa_sold_at             Unix time it was bought with Buy It Now, or 0.
 *   _csa_order               WooCommerce order id once the auction has closed.
 *   _csa_paid                '1' once that order is paid.
 *
 * Money is kept in whole cents everywhere so sums are exact.
 */
class CSA_Data {

	const AUCTION   = 'csa_auction';
	const ITEM      = 'csa_item';
	const SNIPE_SEC = 120;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_post_type(
			self::AUCTION,
			array(
				'labels'          => array(
					'name'          => __( 'Auctions', 'csa' ),
					'singular_name' => __( 'Auction', 'csa' ),
					'menu_name'     => __( 'Silent Auction', 'csa' ),
					'add_new_item'  => __( 'Add New Auction', 'csa' ),
					'edit_item'     => __( 'Edit Auction', 'csa' ),
					'all_items'     => __( 'Auctions', 'csa' ),
					'not_found'     => __( 'No auctions yet.', 'csa' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-hammer',
				'menu_position'   => 27,
				'show_in_rest'    => false,
				'supports'        => array( 'title', 'editor', 'thumbnail' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
			)
		);
		register_post_type(
			self::ITEM,
			array(
				'labels'          => array(
					'name'          => __( 'Auction Items', 'csa' ),
					'singular_name' => __( 'Auction Item', 'csa' ),
					'add_new_item'  => __( 'Add New Item', 'csa' ),
					'edit_item'     => __( 'Edit Item', 'csa' ),
					'all_items'     => __( 'Items', 'csa' ),
					'not_found'     => __( 'No items yet.', 'csa' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . self::AUCTION,
				'show_in_rest'    => false,
				'supports'        => array( 'title', 'editor', 'thumbnail' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
			)
		);
	}

	/* ---------------- Money ---------------- */

	/** "20", "$20.50", "1,250" → cents; null if not a non-negative amount. */
	public static function to_cents( $raw ) {
		$raw = trim( str_replace( array( '$', ',', ' ' ), '', (string) $raw ) );
		if ( '' === $raw || ! preg_match( '/^\d{1,7}(\.\d{1,2})?$/', $raw ) ) {
			return null;
		}
		$parts = explode( '.', $raw );
		return (int) $parts[0] * 100 + ( isset( $parts[1] ) ? (int) str_pad( $parts[1], 2, '0' ) : 0 );
	}

	/** Cents → "$20" or "$20.50". */
	public static function money( $cents ) {
		$cents = (int) $cents;
		return '$' . ( 0 === $cents % 100 ? number_format( $cents / 100 ) : number_format( $cents / 100, 2 ) );
	}

	/** Cents → "20" or "20.50" for a form field. */
	public static function field( $cents ) {
		$cents = (int) $cents;
		return 0 === $cents % 100 ? (string) ( $cents / 100 ) : number_format( $cents / 100, 2, '.', '' );
	}

	/* ---------------- Auctions ---------------- */

	public static function auction( $id ) {
		$id = (int) $id;
		return array(
			'id'         => $id,
			'title'      => get_the_title( $id ),
			'start'      => (int) get_post_meta( $id, '_csa_start', true ),
			'end'        => (int) get_post_meta( $id, '_csa_end', true ),
			'fundraiser' => (int) get_post_meta( $id, '_csa_fundraiser', true ),
			'antisnipe'  => '1' === get_post_meta( $id, '_csa_antisnipe', true ),
			'pickup'     => (string) get_post_meta( $id, '_csa_pickup', true ),
			'tax'        => '1' === get_post_meta( $id, '_csa_tax', true ),
			'costs_pct'  => (int) get_post_meta( $id, '_csa_costs_pct', true ),
			'collector'  => (string) get_post_meta( $id, '_csa_collector', true ),
			'pay_days'   => max( 1, (int) ( get_post_meta( $id, '_csa_pay_days', true ) ? get_post_meta( $id, '_csa_pay_days', true ) : 7 ) ),
			'tax_notice' => (string) get_post_meta( $id, '_csa_tax_notice', true ),
			'terms'      => (string) get_post_meta( $id, '_csa_terms', true ),
			'closed'     => (string) get_post_meta( $id, '_csa_closed', true ),
		);
	}

	/** 'upcoming', 'open', 'closing' (items still running on anti-sniping) or 'closed'. */
	public static function auction_status( $id, $now = null ) {
		$a   = self::auction( $id );
		$now = null === $now ? time() : $now;
		if ( '' !== $a['closed'] && 'closing' !== $a['closed'] ) {
			return 'closed';
		}
		if ( ! $a['start'] || ! $a['end'] || $now < $a['start'] ) {
			return 'upcoming';
		}
		if ( $now < $a['end'] ) {
			return 'open';
		}
		foreach ( self::item_ids( $id ) as $item ) {
			if ( $now < self::item_end( $item ) ) {
				return 'open';
			}
		}
		return 'closing';
	}

	/** Item ids of an auction, in menu order then title. */
	public static function item_ids( $auction_id, $status = 'publish' ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::ITEM,
					'post_status'    => $status,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
					'meta_key'       => '_csa_auction', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => (int) $auction_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
	}

	/* ---------------- Items ---------------- */

	/** [ cents, user id ] of the top bid, or [ 0, 0 ]. */
	public static function high( $item_id ) {
		$raw = (string) get_post_meta( $item_id, '_csa_high', true );
		if ( ! preg_match( '/^(\d+)\|(\d+)$/', $raw, $m ) ) {
			return array( 0, 0 );
		}
		return array( (int) $m[1], (int) $m[2] );
	}

	/** When bidding on this item closes (Unix time). */
	public static function item_end( $item_id ) {
		$sold = (int) get_post_meta( $item_id, '_csa_sold_at', true );
		if ( $sold ) {
			return $sold;
		}
		$auction = (int) get_post_meta( $item_id, '_csa_auction', true );
		$end     = (int) get_post_meta( $auction, '_csa_end', true );
		return max( $end, (int) get_post_meta( $item_id, '_csa_extended', true ) );
	}

	public static function item( $id ) {
		$id = (int) $id;
		list( $high, $high_user ) = self::high( $id );
		$start_bid = (int) get_post_meta( $id, '_csa_start_bid', true );
		$increment = max( 100, (int) get_post_meta( $id, '_csa_increment', true ) );
		return array(
			'id'        => $id,
			'title'     => get_the_title( $id ),
			'auction'   => (int) get_post_meta( $id, '_csa_auction', true ),
			'donor'     => (string) get_post_meta( $id, '_csa_donor', true ),
			'value'     => (int) get_post_meta( $id, '_csa_value', true ),
			'start_bid' => $start_bid,
			'increment' => $increment,
			'buy_now'   => (int) get_post_meta( $id, '_csa_buy_now', true ),
			'high'      => $high,
			'high_user' => $high_user,
			'bids'      => (int) get_post_meta( $id, '_csa_bid_count', true ),
			'min_next'  => $high ? $high + $increment : max( 100, $start_bid ),
			'end'       => self::item_end( $id ),
			'sold_at'   => (int) get_post_meta( $id, '_csa_sold_at', true ),
			'order'     => (int) get_post_meta( $id, '_csa_order', true ),
			'paid'      => '1' === get_post_meta( $id, '_csa_paid', true ),
		);
	}

	/** "3%" for a percent stored in hundredths. */
	public static function pct_text( $hundredths ) {
		return rtrim( rtrim( number_format( $hundredths / 100, 2, '.', '' ), '0' ), '.' ) . '%';
	}

	/** Default texts for a new auction (the site team can change them per auction). */
	public static function default_tax_notice() {
		return __( 'COL&B is not a registered 501(c)(3) nonprofit yet, so winning bids and donated items are not tax-deductible.', 'csa' );
	}

	public static function default_terms() {
		return __( "1. Every bid is a promise to buy the item at that price if you win. Bids can't be withdrawn.\n2. Winners are emailed when bidding closes and must pay online within the time stated on the Auction page. If a winner doesn't pay in time, we may offer the item to the next-highest bidder or auction it again.\n3. Bids are in whole dollars and include card processing costs. Sales tax is added at checkout; there are no other fees.\n4. Items are donated and sold as-is. Values are the donor's estimates, not appraisals. No returns or refunds, unless an item is not as described.\n5. Items must be collected as described on the Auction page.\n6. Winning bids and donated items are not tax-deductible.\n7. Bidders' names are never shown publicly. Your email is used only to tell you about your bids and winnings.\n8. We may cancel a bid or remove an item if something goes wrong (for example, an item becomes unavailable or a bid is clearly a mistake).", 'csa' );
	}

	/** Site-time text for a Unix time, e.g. "Saturday, December 6 at 8:00 pm". */
	public static function when( $ts ) {
		/* translators: date format for when bidding opens or closes */
		return wp_date( __( 'l, F j \a\t g:i a', 'csa' ), (int) $ts );
	}

	/** A person's display name for emails (first name, else display name). */
	public static function first_name( $user ) {
		$first = trim( (string) get_user_meta( $user->ID, 'first_name', true ) );
		return '' !== $first ? $first : $user->display_name;
	}

	/** Title of the linked fundraiser, if the events plugin is there. */
	public static function fundraiser_title( $id ) {
		if ( ! $id || ! function_exists( 'cec_get_fundraiser_choices' ) ) {
			return '';
		}
		$choices = cec_get_fundraiser_choices();
		return isset( $choices[ $id ] ) ? $choices[ $id ] : '';
	}
}
