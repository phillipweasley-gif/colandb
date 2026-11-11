<?php
defined( 'ABSPATH' ) || exit;

/**
 * The Auction page: [csa_auction] (the current auctions) or [csa_auction id="123"].
 * Pages showing it are never cached, so every visitor sees the live top bid.
 */
class CSA_Public {

	public static function init() {
		add_shortcode( 'csa_auction', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_no_cache' ), 1 );
	}

	public static function register_assets() {
		wp_register_style( 'csa-auction', CSA_URL . 'assets/css/auction.css', array(), CSA_VERSION );
		wp_register_script( 'csa-auction', CSA_URL . 'assets/js/auction.js', array(), CSA_VERSION, true );
	}

	/* ---------------- Never cached ---------------- */

	public static function page_has_auction( $post = null ) {
		if ( null === $post ) {
			$post = is_singular() ? get_queried_object() : null;
		}
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		$elementor = (string) get_post_meta( $post->ID, '_elementor_data', true );
		return has_shortcode( $post->post_content, 'csa_auction' ) || false !== strpos( $elementor, 'csa_auction' );
	}

	public static function maybe_no_cache() {
		if ( self::page_has_auction() ) {
			self::no_cache();
		}
	}

	/** Same headers as the events plugin's form pages (CEC_No_Cache::send()). */
	public static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header_remove( 'ec-cdn-cache-control' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
	}

	/* ---------------- The page ---------------- */

	/** Auctions shown by [csa_auction] with no id: open and upcoming ones, else the latest closed one. */
	public static function current_auctions() {
		$ids  = get_posts( array( 'post_type' => CSA_Data::AUCTION, 'post_status' => 'publish', 'posts_per_page' => 50, 'fields' => 'ids' ) );
		$live = array();
		$past = array();
		foreach ( $ids as $id ) {
			CSA_Close::maybe_close( $id );
			$a = CSA_Data::auction( $id );
			if ( ! $a['start'] ) {
				continue;
			}
			if ( 'closed' === CSA_Data::auction_status( $id ) ) {
				$past[ $id ] = $a['end'];
			} else {
				$live[ $id ] = $a['start'];
			}
		}
		asort( $live );
		arsort( $past );
		// Results of auctions that closed in the last 30 days stay below the live ones;
		// with nothing live, the latest results are shown.
		$recent = array_filter(
			$past,
			function ( $end ) {
				return $end > time() - 30 * DAY_IN_SECONDS;
			}
		);
		$past   = $recent ? $recent : array_slice( $past, 0, 1, true );
		return array_merge( array_keys( $live ), array_keys( $past ) );
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'csa_auction' );
		self::no_cache();
		wp_enqueue_style( 'csa-auction' );
		wp_enqueue_script( 'csa-auction' );
		$id = absint( $atts['id'] );
		if ( $id ) {
			if ( CSA_Data::AUCTION !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
				return '';
			}
			CSA_Close::maybe_close( $id );
			$ids = array( $id );
		} else {
			$ids = self::current_auctions();
		}
		$out = '<div class="csa-wrap">';
		if ( ! $ids ) {
			$out .= '<p class="csa-empty">' . esc_html__( 'There is no silent auction right now. Check back soon!', 'csa' ) . '</p>';
		}
		foreach ( $ids as $aid ) {
			$out .= self::render_auction( $aid );
		}
		return $out . '</div>';
	}

	private static function message( $code ) {
		$m = array(
			'placed'      => array( 'ok', __( 'Your bid is in. You\'re the top bidder! We\'ll email you if someone outbids you.', 'csa' ) ),
			'bought'      => array( 'ok', __( 'It\'s yours! You bought this item with Buy It Now. When the auction closes we\'ll email you a link to pay.', 'csa' ) ),
			'too_low'     => array( 'error', __( 'Someone has already bid that much or more. Please bid at least the new minimum shown.', 'csa' ) ),
			'too_high'    => array( 'error', __( 'That bid is unusually large, so it wasn\'t placed. Please check the amount and try again.', 'csa' ) ),
			'already_top' => array( 'ok', __( 'You\'re already the top bidder on this item.', 'csa' ) ),
			'closed'      => array( 'error', __( 'Bidding on this item has closed.', 'csa' ) ),
			'not_open'    => array( 'error', __( 'Bidding hasn\'t opened yet.', 'csa' ) ),
			'no_buy_now'  => array( 'error', __( 'Buy It Now is no longer available for this item.', 'csa' ) ),
			'expired'     => array( 'error', __( 'This page was open too long, so your bid wasn\'t placed. Please try again.', 'csa' ) ),
			'bad'         => array( 'error', __( 'Please enter an amount in dollars, for example 25.', 'csa' ) ),
			'login'       => array( 'error', __( 'Please sign in to bid.', 'csa' ) ),
		);
		return isset( $m[ $code ] ) ? $m[ $code ] : null;
	}

	private static function render_auction( $id ) {
		$a      = CSA_Data::auction( $id );
		$status = CSA_Data::auction_status( $id );
		$out    = '<section class="csa-auction is-' . esc_attr( $status ) . '" aria-labelledby="csa-a-' . (int) $id . '">';
		$out   .= '<header class="csa-head">';
		$out   .= '<h2 class="csa-title" id="csa-a-' . (int) $id . '">' . esc_html( $a['title'] ) . '</h2>';
		if ( 'upcoming' === $status ) {
			/* translators: %s: date and time */
			$when = sprintf( __( 'Bidding opens %s', 'csa' ), CSA_Data::when( $a['start'] ) );
		} elseif ( 'open' === $status ) {
			/* translators: %s: date and time */
			$when = sprintf( __( 'Bidding closes %s', 'csa' ), CSA_Data::when( $a['end'] ) );
		} else {
			/* translators: %s: date and time */
			$when = sprintf( __( 'Bidding closed %s', 'csa' ), CSA_Data::when( $a['end'] ) );
		}
		$out .= '<p class="csa-when">' . esc_html( $when );
		if ( 'open' === $status || 'upcoming' === $status ) {
			$out .= ' <span class="csa-countdown" data-csa-end="' . (int) ( 'open' === $status ? $a['end'] : $a['start'] ) . '" data-csa-prefix="' . esc_attr( 'open' === $status ? __( 'left', 'csa' ) : __( 'to go', 'csa' ) ) . '"></span>';
		}
		$out .= '</p>';
		$fr   = CSA_Data::fundraiser_title( $a['fundraiser'] );
		if ( $fr ) {
			/* translators: %s: fundraiser name */
			$out .= '<p class="csa-for">' . esc_html( sprintf( __( 'Proceeds go to: %s', 'csa' ), $fr ) ) . '</p>';
		}
		$desc = get_post_field( 'post_content', $id );
		if ( '' !== trim( $desc ) ) {
			$out .= '<div class="csa-desc">' . wpautop( wp_kses_post( $desc ) ) . '</div>';
		}
		$fee = CSA_Data::fee_text( $a );
		if ( $a['tax'] || $fee ) {
			if ( $a['tax'] && $fee ) {
				/* translators: %s: fee, e.g. "2.9% + $0.30" */
				$terms = sprintf( __( 'Winning bids are paid online by card, plus sales tax and a %s card processing fee.', 'csa' ), $fee );
			} elseif ( $fee ) {
				/* translators: %s: fee, e.g. "2.9% + $0.30" */
				$terms = sprintf( __( 'Winning bids are paid online by card, plus a %s card processing fee.', 'csa' ), $fee );
			} else {
				$terms = __( 'Winning bids are paid online by card, plus sales tax.', 'csa' );
			}
			$out .= '<p class="csa-terms">' . esc_html( $terms ) . '</p>';
		}
		if ( '' !== trim( $a['pickup'] ) ) {
			$out .= '<p class="csa-pickup"><strong>' . esc_html__( 'Collecting your items:', 'csa' ) . '</strong> ' . esc_html( $a['pickup'] ) . '</p>';
		}
		if ( ! is_user_logged_in() && 'closed' !== $status ) {
			$out .= '<p class="csa-signin">' . sprintf(
				/* translators: 1: sign-in link, 2: create-account link */
				esc_html__( 'You need a colandb.com account to bid: %1$s or %2$s.', 'csa' ),
				'<a href="' . esc_url( wp_login_url( self::here() ) ) . '">' . esc_html__( 'sign in', 'csa' ) . '</a>',
				'<a href="' . esc_url( wp_registration_url() ) . '">' . esc_html__( 'create one', 'csa' ) . '</a>'
			) . '</p>';
		}
		$out .= '</header>';

		$items = CSA_Data::item_ids( $id );
		if ( ! $items ) {
			$out .= '<p class="csa-empty">' . esc_html__( 'Items will be added soon.', 'csa' ) . '</p>';
		} else {
			$mine = is_user_logged_in() ? CSA_Bids::items_bid_by( get_current_user_id(), $id ) : array();
			$out .= '<div class="csa-items">';
			foreach ( $items as $item_id ) {
				$out .= self::render_item( $item_id, $status, in_array( $item_id, $mine, true ) );
			}
			$out .= '</div>';
		}
		return $out . '</section>';
	}

	private static function here() {
		return remove_query_arg( array( 'csa_msg', 'csa_item' ), home_url( add_query_arg( array() ) ) );
	}

	private static function render_item( $id, $auction_status, $bid_on ) {
		$it   = CSA_Data::item( $id );
		$me   = get_current_user_id();
		$now  = time();
		$open = 'open' === $auction_status && $now < $it['end'];
		$out  = '<article class="csa-item' . ( $open ? ' is-open' : ' is-closed' ) . '" id="csa-item-' . (int) $id . '">';
		$photo = get_the_post_thumbnail( $id, 'medium_large', array( 'class' => 'csa-photo', 'loading' => 'lazy' ) );
		if ( $photo ) {
			$out .= '<div class="csa-media">' . $photo . '</div>';
		}
		$out .= '<div class="csa-body">';
		$out .= '<h3 class="csa-item-title">' . esc_html( $it['title'] ) . '</h3>';
		$meta = array();
		if ( '' !== $it['donor'] ) {
			/* translators: %s: who donated the item */
			$meta[] = esc_html( sprintf( __( 'Donated by %s', 'csa' ), $it['donor'] ) );
		}
		if ( $it['value'] ) {
			/* translators: %s: amount */
			$meta[] = esc_html( sprintf( __( 'Value %s', 'csa' ), CSA_Data::money( $it['value'] ) ) );
		}
		if ( $meta ) {
			$out .= '<p class="csa-meta">' . implode( ' · ', $meta ) . '</p>';
		}
		$desc = get_post_field( 'post_content', $id );
		if ( '' !== trim( $desc ) ) {
			$out .= '<div class="csa-desc">' . wpautop( wp_kses_post( $desc ) ) . '</div>';
		}

		// The price line.
		if ( $it['sold_at'] ) {
			/* translators: %s: amount */
			$price = sprintf( __( 'Bought with Buy It Now for %s', 'csa' ), CSA_Data::money( $it['high'] ) );
		} elseif ( $it['high'] && $open ) {
			/* translators: 1: amount, 2: number of bids */
			$price = sprintf( _n( 'Top bid %1$s · %2$d bid', 'Top bid %1$s · %2$d bids', $it['bids'], 'csa' ), '<strong>' . CSA_Data::money( $it['high'] ) . '</strong>', $it['bids'] );
		} elseif ( $it['high'] ) {
			/* translators: 1: amount, 2: number of bids */
			$price = sprintf( _n( 'Won for %1$s · %2$d bid', 'Won for %1$s · %2$d bids', $it['bids'], 'csa' ), '<strong>' . CSA_Data::money( $it['high'] ) . '</strong>', $it['bids'] );
		} elseif ( $open || 'upcoming' === $auction_status ) {
			/* translators: %s: amount */
			$price = sprintf( __( 'Starting bid %s · No bids yet', 'csa' ), '<strong>' . CSA_Data::money( $it['min_next'] ) . '</strong>' );
		} else {
			$price = __( 'No bids', 'csa' );
		}
		$out .= '<p class="csa-price">' . wp_kses( $price, array( 'strong' => array() ) ) . '</p>';

		// Message after a bid on this item.
		$code = isset( $_GET['csa_msg'], $_GET['csa_item'] ) && (int) $_GET['csa_item'] === $id ? sanitize_key( wp_unslash( $_GET['csa_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg  = $code ? self::message( $code ) : null;

		// Where the signed-in visitor stands (the message says it right after a bid).
		$quiet = $msg && 'ok' === $msg[0];
		if ( $quiet ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// Nothing: the message below already says it.
		} elseif ( $me && $it['high_user'] === $me ) {
			$out .= '<p class="csa-you is-top">' . esc_html( $open ? __( 'You\'re the top bidder.', 'csa' ) : __( 'You won this item. Check your email for the link to pay.', 'csa' ) ) . '</p>';
		} elseif ( $me && $bid_on && $it['high'] ) {
			$out .= '<p class="csa-you is-outbid">' . esc_html( $open ? __( 'You\'ve been outbid.', 'csa' ) : __( 'Someone else won this item.', 'csa' ) ) . '</p>';
		}

		if ( $msg ) {
			$out .= '<p class="csa-msg is-' . esc_attr( $msg[0] ) . '" role="status">' . esc_html( $msg[1] ) . '</p>';
		}

		if ( $open ) {
			if ( $it['end'] > CSA_Data::auction( $it['auction'] )['end'] ) {
				/* translators: %s: time */
				$out .= '<p class="csa-extended">' . esc_html( sprintf( __( 'Extended for a last-minute bid: closes at %s', 'csa' ), wp_date( 'g:i:s a', $it['end'] ) ) ) . ' <span class="csa-countdown" data-csa-end="' . (int) $it['end'] . '" data-csa-prefix="' . esc_attr__( 'left', 'csa' ) . '"></span></p>';
			}
			if ( $me ) {
				$out .= self::bid_form( $it );
			} else {
				$out .= '<p class="csa-give"><a class="csa-btn" href="' . esc_url( wp_login_url( self::here() . '#csa-item-' . $id ) ) . '">' . esc_html__( 'Sign in to bid', 'csa' ) . '</a></p>';
			}
		}
		return $out . '</div></article>';
	}

	private static function bid_form( $it ) {
		$top    = get_current_user_id() === $it['high_user'];
		$action = esc_url( admin_url( 'admin-post.php' ) );
		$hidden = '<input type="hidden" name="action" value="csa_bid" />'
			. '<input type="hidden" name="csa_item" value="' . (int) $it['id'] . '" />'
			. '<input type="hidden" name="csa_back" value="' . esc_attr( self::here() ) . '" />'
			. wp_nonce_field( CSA_Bids::NONCE, 'csa_nonce', false, false );
		$field = 'csa-amt-' . (int) $it['id'];
		$out   = '';
		if ( $top ) {
			// The top bidder can't raise their own bid; Buy It Now is still offered below.
			$out .= '<p class="csa-min">' . esc_html__( 'We\'ll email you if someone bids more.', 'csa' ) . '</p>';
		} else {
			$out  .= '<form class="csa-bid" method="post" action="' . $action . '" data-csa-min="' . esc_attr( CSA_Data::field( $it['min_next'] ) ) . '">' . $hidden;
			$out  .= '<label for="' . $field . '">' . esc_html__( 'Your bid ($)', 'csa' ) . '</label>';
			$out  .= '<div class="csa-bid-row"><input type="number" inputmode="decimal" id="' . $field . '" name="csa_amount" min="' . esc_attr( CSA_Data::field( $it['min_next'] ) ) . '" step="0.01" value="' . esc_attr( CSA_Data::field( $it['min_next'] ) ) . '" required aria-describedby="' . $field . '-min" />';
			$out  .= '<button type="submit" class="csa-btn">' . esc_html__( 'Place bid', 'csa' ) . '</button></div>';
			/* translators: %s: amount */
			$out .= '<p class="csa-min" id="' . $field . '-min">' . esc_html( sprintf( __( 'Minimum bid %s', 'csa' ), CSA_Data::money( $it['min_next'] ) ) ) . '</p>';
			$out .= '</form>';
		}
		if ( $it['buy_now'] && $it['high'] < $it['buy_now'] ) {
			$out .= '<form class="csa-buy" method="post" action="' . $action . '" data-csa-confirm="' . esc_attr( sprintf( /* translators: %s: amount */ __( 'Buy this item now for %s? Bidding on it ends and you pay when the auction closes.', 'csa' ), CSA_Data::money( $it['buy_now'] ) ) ) . '">' . $hidden . '<input type="hidden" name="csa_buy_now" value="1" />';
			/* translators: %s: amount */
			$out .= '<button type="submit" class="csa-btn is-secondary">' . esc_html( sprintf( __( 'Buy It Now for %s', 'csa' ), CSA_Data::money( $it['buy_now'] ) ) ) . '</button></form>';
		}
		return $out;
	}
}
