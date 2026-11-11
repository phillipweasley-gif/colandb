<?php
defined( 'ABSPATH' ) || exit;

/** wp-admin: Silent Auction → Auctions and Items, their boxes, lists and results. */
class CSA_Admin {

	const NONCE = 'csa_admin_save';

	public static function init() {
		add_action( 'add_meta_boxes_' . CSA_Data::AUCTION, array( __CLASS__, 'auction_boxes' ) );
		add_action( 'add_meta_boxes_' . CSA_Data::ITEM, array( __CLASS__, 'item_boxes' ) );
		add_action( 'save_post_' . CSA_Data::AUCTION, array( __CLASS__, 'save_auction' ), 10, 2 );
		add_action( 'save_post_' . CSA_Data::ITEM, array( __CLASS__, 'save_item' ), 10, 2 );
		add_filter( 'manage_' . CSA_Data::AUCTION . '_posts_columns', array( __CLASS__, 'auction_columns' ) );
		add_action( 'manage_' . CSA_Data::AUCTION . '_posts_custom_column', array( __CLASS__, 'auction_column' ), 10, 2 );
		add_filter( 'manage_' . CSA_Data::ITEM . '_posts_columns', array( __CLASS__, 'item_columns' ) );
		add_action( 'manage_' . CSA_Data::ITEM . '_posts_custom_column', array( __CLASS__, 'item_column' ), 10, 2 );
		add_action( 'load-post.php', array( __CLASS__, 'close_on_open' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/** Opening a finished auction in wp-admin closes it, if the 5-minute check hasn't yet. */
	public static function close_on_open() {
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id && CSA_Data::AUCTION === get_post_type( $id ) ) {
			CSA_Close::maybe_close( $id );
		}
	}

	public static function notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, array( CSA_Data::AUCTION, CSA_Data::ITEM ), true ) ) {
			return;
		}
		$page = (int) get_option( 'csa_page_id' );
		if ( $page && 'publish' !== get_post_status( $page ) ) {
			printf(
				'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'The Silent Auction page is still a draft, so the public can\'t see it yet. Publish it (and add it to the menu) when the auction is ready.', 'csa' ),
				esc_url( get_edit_post_link( $page ) ),
				esc_html__( 'Edit the page', 'csa' )
			);
		}
	}

	/* ---------------- Dates ---------------- */

	private static function local_input( $ts ) {
		return $ts ? wp_date( 'Y-m-d\TH:i', (int) $ts ) : '';
	}

	private static function from_local( $raw ) {
		$raw = trim( (string) $raw );
		$d   = DateTime::createFromFormat( 'Y-m-d\TH:i', $raw, wp_timezone() );
		return ( $d && $d->format( 'Y-m-d\TH:i' ) === $raw ) ? $d->getTimestamp() : 0;
	}

	private static function posted( $key, $max = 500 ) {
		return isset( $_POST[ $key ] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ), 0, $max ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in the callers.
	}

	private static function can_save( $post_id ) {
		if ( ! isset( $_POST['csa_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['csa_nonce'] ) ), self::NONCE ) ) {
			return false;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		return isset( $_POST['post_ID'] ) && (int) $_POST['post_ID'] === (int) $post_id;
	}

	/* ---------------- Auction ---------------- */

	public static function auction_boxes( $post ) {
		add_meta_box( 'csa_auction_details', __( 'When and where the money goes', 'csa' ), array( __CLASS__, 'box_auction' ), CSA_Data::AUCTION, 'normal', 'high' );
		if ( 'auto-draft' !== $post->post_status ) {
			add_meta_box( 'csa_auction_results', __( 'Items, bids and winners (private)', 'csa' ), array( __CLASS__, 'box_results' ), CSA_Data::AUCTION, 'normal', 'high' );
		}
	}

	public static function box_auction( $post ) {
		wp_nonce_field( self::NONCE, 'csa_nonce' );
		$a       = CSA_Data::auction( $post->ID );
		$new     = 'auto-draft' === $post->post_status;
		$choices = function_exists( 'cec_get_fundraiser_choices' ) ? cec_get_fundraiser_choices() : array();
		?>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="csa_start"><?php esc_html_e( 'Bidding opens', 'csa' ); ?></label></th>
				<td><input type="datetime-local" id="csa_start" name="csa_start" value="<?php echo esc_attr( self::local_input( $a['start'] ) ); ?>" required />
				<label for="csa_end"><?php esc_html_e( 'Bidding closes', 'csa' ); ?></label>
				<input type="datetime-local" id="csa_end" name="csa_end" value="<?php echo esc_attr( self::local_input( $a['end'] ) ); ?>" required />
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: time zone */ __( 'Site time (%s). Bids are accepted only between these times.', 'csa' ), wp_timezone_string() ) ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Last-minute bids', 'csa' ); ?></th>
				<td><label><input type="checkbox" name="csa_antisnipe" value="1" <?php checked( $new || $a['antisnipe'] ); ?> /> <?php esc_html_e( 'A bid in the last 2 minutes gives that item 2 more minutes, so nobody wins by bidding at the final second.', 'csa' ); ?></label></td></tr>
			<tr><th scope="row"><label for="csa_fundraiser"><?php esc_html_e( 'Money raised goes to', 'csa' ); ?></label></th>
				<td>
				<?php if ( $choices ) : ?>
					<select id="csa_fundraiser" name="csa_fundraiser"><option value="0"><?php esc_html_e( '— No fundraiser —', 'csa' ); ?></option>
					<?php foreach ( $choices as $id => $title ) : ?>
						<option value="<?php echo (int) $id; ?>" <?php selected( $a['fundraiser'], $id ); ?>><?php echo esc_html( $title ); ?></option>
					<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Each paid order is added to this fundraiser\'s total on the Fundraising page (Events → Fundraisers).', 'csa' ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No fundraisers yet. Add one under Events → Fundraisers to have paid orders counted on the Fundraising page.', 'csa' ); ?></p>
				<?php endif; ?>
				</td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Sales tax', 'csa' ); ?></th>
				<td><label><input type="checkbox" name="csa_tax" value="1" <?php checked( $new || $a['tax'] ); ?> /> <?php esc_html_e( 'Add sales tax to winners\' orders', 'csa' ); ?></label>
				<p class="description"><?php esc_html_e( 'Uses the rates in WooCommerce → Settings → Tax (based on the shop address, since items are collected in person). Only the winning bids count toward the fundraiser, not tax or fees.', 'csa' ); ?></p></td></tr>
			<tr><th scope="row"><label for="csa_costs_pct"><?php esc_html_e( 'Card processing costs', 'csa' ); ?></label></th>
				<td><input type="text" inputmode="decimal" class="small-text" id="csa_costs_pct" name="csa_costs_pct" value="<?php echo esc_attr( $new ? '3' : ( $a['costs_pct'] ? rtrim( rtrim( number_format( $a['costs_pct'] / 100, 2, '.', '' ), '0' ), '.' ) : '' ) ); ?>" /> %
				<p class="description"><?php esc_html_e( 'Bids include card processing costs; no fee is added on top. The page tells bidders so, and this percentage is taken off each paid bid before it is added to the fundraiser, so its total shows what COL&B receives. Leave empty if the collector absorbs the costs.', 'csa' ); ?></p></td></tr>
			<tr><th scope="row"><label for="csa_collector"><?php esc_html_e( 'Payments collected by', 'csa' ); ?></label></th>
				<td><input type="text" id="csa_collector" name="csa_collector" value="<?php echo esc_attr( $a['collector'] ); ?>" class="regular-text" maxlength="120" placeholder="Weasley Studios" />
				<label for="csa_pay_days"><?php esc_html_e( 'Winners pay within', 'csa' ); ?></label> <input type="number" min="1" max="60" class="small-text" id="csa_pay_days" name="csa_pay_days" value="<?php echo (int) $a['pay_days']; ?>" /> <?php esc_html_e( 'days', 'csa' ); ?>
				<p class="description"><?php esc_html_e( 'The business whose payment account receives the money. Shown on the Auction page, so bidders know who charges their card and that it is collected for COL&B.', 'csa' ); ?></p></td></tr>
			<tr><th scope="row"><label for="csa_tax_notice"><?php esc_html_e( 'Tax-deductible notice', 'csa' ); ?></label></th>
				<td><textarea id="csa_tax_notice" name="csa_tax_notice" rows="2" class="large-text" maxlength="500"><?php echo esc_textarea( $new ? CSA_Data::default_tax_notice() : $a['tax_notice'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Shown on the Auction page and in each winner\'s email. Change it only on professional advice (for example once COL&B is a 501(c)(3)).', 'csa' ); ?></p></td></tr>
			<tr><th scope="row"><label for="csa_terms"><?php esc_html_e( 'Auction terms', 'csa' ); ?></label></th>
				<td><textarea id="csa_terms" name="csa_terms" rows="10" class="large-text" maxlength="5000"><?php echo esc_textarea( $new ? CSA_Data::default_terms() : $a['terms'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Shown on the Auction page under "Auction terms". Bidders agree to them when they bid.', 'csa' ); ?></p></td></tr>
			<tr><th scope="row"><label for="csa_pickup"><?php esc_html_e( 'How winners collect items', 'csa' ); ?></label></th>
				<td><textarea id="csa_pickup" name="csa_pickup" rows="3" class="large-text" maxlength="1000"><?php echo esc_textarea( $a['pickup'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Shown on the Auction page and in each winner\'s email. For example: "Pick up at Monthly Leather Invasion on December 13, or contact us to arrange delivery."', 'csa' ); ?></p></td></tr>
		</table>
		<p><?php esc_html_e( 'The description and photo above are shown at the top of the Auction page.', 'csa' ); ?></p>
		<?php
	}

	public static function save_auction( $post_id ) {
		if ( ! self::can_save( $post_id ) ) {
			return;
		}
		$start = self::from_local( self::posted( 'csa_start', 20 ) );
		$end   = self::from_local( self::posted( 'csa_end', 20 ) );
		if ( $start && $end && $end <= $start ) {
			$end = $start + HOUR_IN_SECONDS;
		}
		update_post_meta( $post_id, '_csa_start', $start );
		update_post_meta( $post_id, '_csa_end', $end );
		update_post_meta( $post_id, '_csa_antisnipe', empty( $_POST['csa_antisnipe'] ) ? '0' : '1' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$fr      = absint( self::posted( 'csa_fundraiser', 20 ) );
		$choices = function_exists( 'cec_get_fundraiser_choices' ) ? cec_get_fundraiser_choices() : array();
		update_post_meta( $post_id, '_csa_fundraiser', isset( $choices[ $fr ] ) ? $fr : 0 );
		$pickup = isset( $_POST['csa_pickup'] ) ? mb_substr( trim( sanitize_textarea_field( wp_unslash( $_POST['csa_pickup'] ) ) ), 0, 1000 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $post_id, '_csa_pickup', $pickup );
		update_post_meta( $post_id, '_csa_tax', empty( $_POST['csa_tax'] ) ? '0' : '1' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$pct = CSA_Data::to_cents( self::posted( 'csa_costs_pct', 10 ) ); // "3" → 300: hundredths of a percent.
		update_post_meta( $post_id, '_csa_costs_pct', null === $pct ? 0 : min( 1000, $pct ) );
		update_post_meta( $post_id, '_csa_collector', self::posted( 'csa_collector', 120 ) );
		update_post_meta( $post_id, '_csa_pay_days', max( 1, min( 60, absint( self::posted( 'csa_pay_days', 3 ) ) ) ) );
		foreach ( array( 'tax_notice' => 500, 'terms' => 5000 ) as $k => $max ) {
			$v = isset( $_POST[ 'csa_' . $k ] ) ? mb_substr( trim( sanitize_textarea_field( wp_unslash( $_POST[ 'csa_' . $k ] ) ) ), 0, $max ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_post_meta( $post_id, '_csa_' . $k, $v );
		}
		if ( ! metadata_exists( 'post', $post_id, '_csa_closed' ) ) {
			add_post_meta( $post_id, '_csa_closed', '', true );
		}
	}

	public static function box_results( $post ) {
		$items  = CSA_Data::item_ids( $post->ID, array( 'publish', 'draft', 'pending' ) );
		$status = CSA_Data::auction_status( $post->ID );
		$labels = array(
			'upcoming' => __( 'Not open yet', 'csa' ),
			'open'     => __( 'Open for bids', 'csa' ),
			'closing'  => __( 'Bidding has ended; winners are emailed within 5 minutes', 'csa' ),
			'closed'   => __( 'Closed. Winners have been emailed a link to pay.', 'csa' ),
		);
		printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Status:', 'csa' ), esc_html( $labels[ $status ] ) );
		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( admin_url( 'post-new.php?post_type=' . CSA_Data::ITEM . '&csa_auction=' . (int) $post->ID ) ),
			esc_html__( 'Add an item to this auction', 'csa' )
		);
		if ( ! $items ) {
			echo '<p>' . esc_html__( 'No items yet.', 'csa' ) . '</p>';
			return;
		}
		$total = 0;
		$paid  = 0;
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Item', 'csa' ) . '</th><th>' . esc_html__( 'Top bid', 'csa' ) . '</th><th>' . esc_html__( 'Bids', 'csa' ) . '</th><th>' . esc_html__( 'Top bidder', 'csa' ) . '</th><th>' . esc_html__( 'Order', 'csa' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $id ) {
			$it   = CSA_Data::item( $id );
			$user = $it['high_user'] ? get_userdata( $it['high_user'] ) : null;
			$total += $it['high'];
			$order = '';
			if ( $it['order'] ) {
				$o = function_exists( 'wc_get_order' ) ? wc_get_order( $it['order'] ) : null;
				if ( $it['paid'] ) {
					$paid += $it['high'];
				}
				$order = sprintf(
					'<a href="%s">#%s</a> %s',
					esc_url( $o ? $o->get_edit_order_url() : '#' ),
					esc_html( $o ? $o->get_order_number() : $it['order'] ),
					esc_html( $it['paid'] ? __( 'Paid', 'csa' ) : __( 'Not paid yet', 'csa' ) )
				);
			}
			printf(
				'<tr><td><a href="%s">%s</a>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
				esc_url( get_edit_post_link( $id ) ),
				esc_html( $it['title'] ),
				'publish' === get_post_status( $id ) ? '' : ' <em>(' . esc_html__( 'draft, not shown', 'csa' ) . ')</em>',
				$it['high'] ? esc_html( CSA_Data::money( $it['high'] ) ) : '&mdash;',
				(int) $it['bids'],
				$user ? esc_html( $user->display_name . ' <' . $user->user_email . '>' ) : '&mdash;',
				$order ? wp_kses_post( $order ) : '&mdash;'
			);
		}
		echo '</tbody></table>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: total of top bids, 2: amount paid */
					__( 'Top bids total %1$s. Paid so far: %2$s. To record a cash or Venmo payment, open the order and set its status to Completed.', 'csa' ),
					CSA_Data::money( $total ),
					CSA_Data::money( $paid )
				)
			)
		);
	}

	public static function auction_columns( $cols ) {
		unset( $cols['date'] );
		$cols['csa_when']   = __( 'Bidding', 'csa' );
		$cols['csa_items']  = __( 'Items', 'csa' );
		$cols['csa_status'] = __( 'Status', 'csa' );
		return $cols;
	}

	public static function auction_column( $col, $id ) {
		$a = CSA_Data::auction( $id );
		if ( 'csa_when' === $col ) {
			echo $a['start'] ? esc_html( wp_date( 'M j, g:i a', $a['start'] ) . ' – ' . wp_date( 'M j, g:i a', $a['end'] ) ) : '&mdash;';
		} elseif ( 'csa_items' === $col ) {
			echo (int) count( CSA_Data::item_ids( $id ) );
		} elseif ( 'csa_status' === $col ) {
			$s = CSA_Data::auction_status( $id );
			$l = array(
				'upcoming' => __( 'Not open yet', 'csa' ),
				'open'     => __( 'Open', 'csa' ),
				'closing'  => __( 'Closing', 'csa' ),
				'closed'   => __( 'Closed', 'csa' ),
			);
			echo esc_html( $l[ $s ] );
		}
	}

	/* ---------------- Item ---------------- */

	public static function item_boxes( $post ) {
		add_meta_box( 'csa_item_details', __( 'Auction item', 'csa' ), array( __CLASS__, 'box_item' ), CSA_Data::ITEM, 'normal', 'high' );
		if ( 'auto-draft' !== $post->post_status ) {
			add_meta_box( 'csa_item_bids', __( 'Bids (private)', 'csa' ), array( __CLASS__, 'box_bids' ), CSA_Data::ITEM, 'normal', 'high' );
		}
	}

	public static function box_item( $post ) {
		wp_nonce_field( self::NONCE, 'csa_nonce' );
		$it       = CSA_Data::item( $post->ID );
		$new      = 'auto-draft' === $post->post_status;
		$auction  = $it['auction'] ? $it['auction'] : ( isset( $_GET['csa_auction'] ) ? absint( $_GET['csa_auction'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$auctions = get_posts( array( 'post_type' => CSA_Data::AUCTION, 'post_status' => array( 'publish', 'draft', 'future', 'pending' ), 'posts_per_page' => 100 ) );
		$money    = function ( $name, $cents, $placeholder = '' ) {
			printf( '<input type="text" inputmode="decimal" class="small-text" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" />', esc_attr( $name ), esc_attr( $cents ? CSA_Data::field( $cents ) : '' ), esc_attr( $placeholder ) );
		};
		?>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="csa_auction"><?php esc_html_e( 'Auction', 'csa' ); ?></label></th>
				<td><select id="csa_auction" name="csa_auction" required><option value=""><?php esc_html_e( '— Choose —', 'csa' ); ?></option>
				<?php foreach ( $auctions as $a ) : ?>
					<option value="<?php echo (int) $a->ID; ?>" <?php selected( $auction, $a->ID ); ?>><?php echo esc_html( get_the_title( $a ) ); ?></option>
				<?php endforeach; ?>
				</select></td></tr>
			<tr><th scope="row"><label for="csa_donor"><?php esc_html_e( 'Donated by', 'csa' ); ?></label></th>
				<td><input type="text" id="csa_donor" name="csa_donor" value="<?php echo esc_attr( $it['donor'] ); ?>" class="regular-text" maxlength="120" /> <span class="description"><?php esc_html_e( 'Optional, shown on the item.', 'csa' ); ?></span></td></tr>
			<tr><th scope="row"><label for="csa_value"><?php esc_html_e( 'Estimated value ($)', 'csa' ); ?></label></th>
				<td><?php $money( 'csa_value', $it['value'] ); ?> <span class="description"><?php esc_html_e( 'Optional: the donor\'s estimate of its retail value, shown as "Est. value $… (from the donor)". Keep it realistic.', 'csa' ); ?></span></td></tr>
			<tr><th scope="row"><label for="csa_start_bid"><?php esc_html_e( 'Starting bid ($)', 'csa' ); ?></label></th>
				<td><?php $money( 'csa_start_bid', $it['start_bid'], '10' ); ?>
				<label for="csa_increment"><?php esc_html_e( 'Minimum raise ($)', 'csa' ); ?></label> <?php $money( 'csa_increment', $new ? 500 : $it['increment'] ); ?>
				<p class="description"><?php esc_html_e( 'Whole dollars (cents are rounded up). Each new bid must be at least the current top bid plus the minimum raise.', 'csa' ); ?></p></td></tr>
			<tr><th scope="row"><label for="csa_buy_now"><?php esc_html_e( 'Buy It Now price ($)', 'csa' ); ?></label></th>
				<td><?php $money( 'csa_buy_now', $it['buy_now'] ); ?> <span class="description"><?php esc_html_e( 'Optional. Anyone can end bidding on this item by paying this price.', 'csa' ); ?></span></td></tr>
		</table>
		<p><?php esc_html_e( 'The title, description and photo (Featured image) are shown on the Auction page. Only published items are shown.', 'csa' ); ?></p>
		<?php
		if ( $it['bids'] ) {
			echo '<p class="description">' . esc_html__( 'This item already has bids. Changing its prices now applies to the next bid only.', 'csa' ) . '</p>';
		}
	}

	public static function save_item( $post_id ) {
		if ( ! self::can_save( $post_id ) ) {
			return;
		}
		$auction = absint( self::posted( 'csa_auction', 20 ) );
		if ( $auction && CSA_Data::AUCTION === get_post_type( $auction ) ) {
			update_post_meta( $post_id, '_csa_auction', $auction );
		}
		update_post_meta( $post_id, '_csa_donor', self::posted( 'csa_donor', 120 ) );
		foreach ( array( 'value', 'start_bid', 'increment', 'buy_now' ) as $k ) {
			$c = CSA_Data::to_cents( self::posted( 'csa_' . $k, 20 ) );
			$c = null === $c ? 0 : $c;
			if ( 'value' !== $k ) {
				$c = (int) ( ceil( $c / 100 ) * 100 ); // Bids are whole dollars, so these are too (rounded up).
			}
			update_post_meta( $post_id, '_csa_' . $k, $c );
		}
		if ( (int) get_post_meta( $post_id, '_csa_increment', true ) < 100 ) {
			update_post_meta( $post_id, '_csa_increment', 100 );
		}
		if ( ! metadata_exists( 'post', $post_id, '_csa_high' ) ) {
			add_post_meta( $post_id, '_csa_high', '', true );
		}
	}

	public static function box_bids( $post ) {
		$rows = CSA_Bids::history( $post->ID );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No bids yet.', 'csa' ) . '</p>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'Bidders\' names are never shown on the site. Only the top amount and the number of bids are.', 'csa' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Amount', 'csa' ) . '</th><th>' . esc_html__( 'Bidder', 'csa' ) . '</th><th>' . esc_html__( 'When', 'csa' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$u = get_userdata( (int) $r->user_id );
			printf(
				'<tr><td>%s%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( CSA_Data::money( $r->amount ) ),
				$r->buy_now ? ' <em>' . esc_html__( '(Buy It Now)', 'csa' ) . '</em>' : '',
				esc_html( $u ? $u->display_name . ' <' . $u->user_email . '>' : '#' . $r->user_id ),
				esc_html( wp_date( 'M j, g:i:s a', strtotime( $r->created_gmt . ' UTC' ) ) )
			);
		}
		echo '</tbody></table>';
	}

	public static function item_columns( $cols ) {
		unset( $cols['date'] );
		$cols['csa_auction'] = __( 'Auction', 'csa' );
		$cols['csa_high']    = __( 'Top bid', 'csa' );
		$cols['csa_bids']    = __( 'Bids', 'csa' );
		return $cols;
	}

	public static function item_column( $col, $id ) {
		$it = CSA_Data::item( $id );
		if ( 'csa_auction' === $col ) {
			echo $it['auction'] ? esc_html( get_the_title( $it['auction'] ) ) : '&mdash;';
		} elseif ( 'csa_high' === $col ) {
			echo $it['high'] ? esc_html( CSA_Data::money( $it['high'] ) ) : '&mdash;';
		} elseif ( 'csa_bids' === $col ) {
			echo (int) $it['bids'];
		}
	}
}
