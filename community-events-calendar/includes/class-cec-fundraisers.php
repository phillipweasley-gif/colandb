<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 1.46.0: public fundraising tracker.
 *
 * - A fundraiser is a non-public `cec_fundraiser` post (Events → Fundraisers
 *   in wp-admin, for the site team): title, description (the editor), a
 *   photo, a goal, optional start and end dates, an optional organization or
 *   titleholder, a donate link with its button text, and "other ways to give"
 *   instructions.
 * - The site team records each donation (amount, date, how it was given and
 *   a private note). Only totals are ever shown publicly: amount raised,
 *   goal, percentage and number of donations. The private notes and the
 *   individual donations stay in wp-admin.
 * - [cec_fundraisers] lists the current and upcoming fundraisers, then the
 *   completed ones; [cec_fundraiser id="123"] shows one card.
 *
 * Meta: _cec_fr_goal, _cec_fr_start, _cec_fr_end (Y-m-d, site dates),
 * _cec_fr_url, _cec_fr_button, _cec_fr_other, _cec_fr_org (term id),
 * _cec_fr_entries (list of { date, amount, method, note }), and the cached
 * totals _cec_fr_raised / _cec_fr_count.
 */
class CEC_Fundraisers {

	const POST_TYPE = 'cec_fundraiser';
	const NONCE     = 'cec_fundraiser_save';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_shortcode( 'cec_fundraisers', array( __CLASS__, 'shortcode_list' ) );
		add_shortcode( 'cec_fundraiser', array( __CLASS__, 'shortcode_one' ) );
		add_action( 'trashed_post', array( __CLASS__, 'maybe_refresh' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'maybe_refresh' ) );
	}

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Fundraisers', 'cec' ),
					'singular_name' => __( 'Fundraiser', 'cec' ),
					'add_new_item'  => __( 'Add New Fundraiser', 'cec' ),
					'edit_item'     => __( 'Edit Fundraiser', 'cec' ),
					'all_items'     => __( 'Fundraisers', 'cec' ),
					'not_found'     => __( 'No fundraisers yet.', 'cec' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=cec_event',
				'show_in_rest'    => false, // The classic editor, so the goal and donation boxes save with the post.
				'supports'        => array( 'title', 'editor', 'thumbnail' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
			)
		);
	}

	public static function register_assets() {
		wp_register_style( 'cec-fundraisers', CEC_URL . 'assets/css/fundraisers.css', array(), CEC_VERSION );
	}

	/* ------------------------------------------------------------------
	 * Data
	 * ---------------------------------------------------------------- */

	public static function methods() {
		return array(
			'online' => __( 'Online (donate link)', 'cec' ),
			'cash'   => __( 'Cash', 'cec' ),
			'venmo'  => __( 'Venmo', 'cec' ),
			'cashapp' => __( 'Cash App', 'cec' ),
			'paypal' => __( 'PayPal', 'cec' ),
			'check'  => __( 'Check', 'cec' ),
			'other'  => __( 'Other', 'cec' ),
		);
	}

	/** A money amount from a form: "20", "$20.50", "1,250" → 20.0 / 20.5 / 1250.0; null if not a non-negative amount. */
	public static function money( $raw ) {
		$raw = trim( str_replace( array( '$', ',', ' ' ), '', (string) $raw ) );
		if ( '' === $raw || ! preg_match( '/^\d+(\.\d{1,2})?$/', $raw ) ) {
			return null;
		}
		return round( (float) $raw, 2 );
	}

	public static function entries( $id ) {
		$entries = get_post_meta( $id, '_cec_fr_entries', true );
		return is_array( $entries ) ? $entries : array();
	}

	/** Everything a card needs, public fields only. */
	public static function data( $id ) {
		$goal   = (float) get_post_meta( $id, '_cec_fr_goal', true );
		$raised = (float) get_post_meta( $id, '_cec_fr_raised', true );
		$start  = (string) get_post_meta( $id, '_cec_fr_start', true );
		$end    = (string) get_post_meta( $id, '_cec_fr_end', true );
		$today  = wp_date( 'Y-m-d' );
		$status = ( $end && $end < $today ) ? 'ended' : ( ( $start && $start > $today ) ? 'upcoming' : 'active' );
		$org    = (int) get_post_meta( $id, '_cec_fr_org', true );
		$term   = $org ? get_term( $org, 'cec_partner_org' ) : null;
		return array(
			'id'      => (int) $id,
			'title'   => get_the_title( $id ),
			'content' => get_post_field( 'post_content', $id ),
			'goal'    => $goal,
			'raised'  => $raised,
			'count'   => (int) get_post_meta( $id, '_cec_fr_count', true ),
			'percent' => $goal > 0 ? (int) floor( $raised / $goal * 100 ) : 0,
			'start'   => $start,
			'end'     => $end,
			'status'  => $status,
			'url'     => (string) get_post_meta( $id, '_cec_fr_url', true ),
			'button'  => (string) get_post_meta( $id, '_cec_fr_button', true ),
			'other'   => (string) get_post_meta( $id, '_cec_fr_other', true ),
			'org'     => ( $term && ! is_wp_error( $term ) ) ? $term : null,
			'photo'   => get_the_post_thumbnail( $id, 'medium_large', array( 'class' => 'cec-fr-photo', 'loading' => 'lazy' ) ),
		);
	}

	private static function money_text( $amount ) {
		return CEC_Event_Helper::format_price( $amount );
	}

	/* ------------------------------------------------------------------
	 * wp-admin
	 * ---------------------------------------------------------------- */

	public static function add_meta_boxes() {
		add_meta_box( 'cec_fr_details', __( 'Goal and giving', 'cec' ), array( __CLASS__, 'box_details' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'cec_fr_donations', __( 'Donations (private)', 'cec' ), array( __CLASS__, 'box_donations' ), self::POST_TYPE, 'normal', 'high' );
	}

	public static function box_details( $post ) {
		wp_nonce_field( self::NONCE, 'cec_fr_nonce' );
		$m    = function ( $k ) use ( $post ) {
			return (string) get_post_meta( $post->ID, $k, true );
		};
		$goal = $m( '_cec_fr_goal' );
		$orgs = get_terms( array( 'taxonomy' => 'cec_partner_org', 'hide_empty' => false, 'orderby' => 'name' ) );
		?>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="cec_fr_goal"><?php esc_html_e( 'Goal ($)', 'cec' ); ?></label></th>
				<td><input type="text" inputmode="decimal" id="cec_fr_goal" name="cec_fr_goal" value="<?php echo esc_attr( '' === $goal ? '' : rtrim( rtrim( number_format( (float) $goal, 2, '.', '' ), '0' ), '.' ) ); ?>" class="small-text" required /> <span class="description"><?php esc_html_e( 'For example 1500. Required.', 'cec' ); ?></span></td></tr>
			<tr><th scope="row"><label for="cec_fr_start"><?php esc_html_e( 'Starts', 'cec' ); ?></label></th>
				<td><input type="date" id="cec_fr_start" name="cec_fr_start" value="<?php echo esc_attr( $m( '_cec_fr_start' ) ); ?>" /> <label for="cec_fr_end"><?php esc_html_e( 'Ends', 'cec' ); ?></label> <input type="date" id="cec_fr_end" name="cec_fr_end" value="<?php echo esc_attr( $m( '_cec_fr_end' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Both optional. Before the start it shows as "Starts …"; after the end it moves to Completed and the donate button is hidden.', 'cec' ); ?></p></td></tr>
			<tr><th scope="row"><label for="cec_fr_org"><?php esc_html_e( 'For (optional)', 'cec' ); ?></label></th>
				<td><select id="cec_fr_org" name="cec_fr_org"><option value=""><?php esc_html_e( '— COL&B —', 'cec' ); ?></option>
				<?php foreach ( is_array( $orgs ) ? $orgs : array() as $o ) : ?>
					<option value="<?php echo (int) $o->term_id; ?>" <?php selected( (int) $m( '_cec_fr_org' ), (int) $o->term_id ); ?>><?php echo esc_html( $o->name ); ?></option>
				<?php endforeach; ?>
				</select> <span class="description"><?php esc_html_e( 'An organization or titleholder this fundraiser is for. Shown with a link to their page.', 'cec' ); ?></span></td></tr>
			<tr><th scope="row"><label for="cec_fr_url"><?php esc_html_e( 'Donate link', 'cec' ); ?></label></th>
				<td><input type="url" id="cec_fr_url" name="cec_fr_url" value="<?php echo esc_attr( $m( '_cec_fr_url' ) ); ?>" class="regular-text" placeholder="https://" />
				<label for="cec_fr_button"><?php esc_html_e( 'Button text', 'cec' ); ?></label> <input type="text" id="cec_fr_button" name="cec_fr_button" value="<?php echo esc_attr( $m( '_cec_fr_button' ) ); ?>" placeholder="<?php esc_attr_e( 'Donate', 'cec' ); ?>" maxlength="40" />
				<p class="description"><?php esc_html_e( 'PayPal, Venmo, Cash App, GoFundMe, Givebutter… Leave empty for no button.', 'cec' ); ?></p></td></tr>
			<tr><th scope="row"><label for="cec_fr_other"><?php esc_html_e( 'Other ways to give', 'cec' ); ?></label></th>
				<td><textarea id="cec_fr_other" name="cec_fr_other" rows="2" class="large-text" maxlength="500"><?php echo esc_textarea( $m( '_cec_fr_other' ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Optional, shown under the button. For example: "Cash at the door at Monthly Leather Invasion."', 'cec' ); ?></p></td></tr>
		</table>
		<?php
	}

	public static function box_donations( $post ) {
		$entries = self::entries( $post->ID );
		$methods = self::methods();
		$raised  = (float) get_post_meta( $post->ID, '_cec_fr_raised', true );
		$goal    = (float) get_post_meta( $post->ID, '_cec_fr_goal', true );
		?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: amount raised, 2: number of donations, 3: goal */
					_n( '%1$s raised from %2$d donation, of a %3$s goal.', '%1$s raised from %2$d donations, of a %3$s goal.', count( $entries ), 'cec' ),
					self::money_text( $raised ),
					count( $entries ),
					self::money_text( $goal )
				)
			);
			?>
		</p>
		<p class="description"><?php esc_html_e( 'Only the total, the goal and the number of donations are shown on the site. Dates, how each was given and these notes never are, so donors stay private. Use the note for your own records (for example who gave, or "collected at the door").', 'cec' ); ?></p>
		<table class="widefat striped cec-fr-entries">
			<thead><tr><th><?php esc_html_e( 'Date', 'cec' ); ?></th><th><?php esc_html_e( 'Amount ($)', 'cec' ); ?></th><th><?php esc_html_e( 'How', 'cec' ); ?></th><th><?php esc_html_e( 'Private note', 'cec' ); ?></th><th><?php esc_html_e( 'Remove', 'cec' ); ?></th></tr></thead>
			<tbody>
			<?php
			$rows = $entries;
			for ( $i = 0; $i < 3; $i++ ) {
				$rows[] = array( 'date' => '', 'amount' => '', 'method' => '', 'note' => '', 'new' => true );
			}
			foreach ( $rows as $i => $e ) :
				$new = ! empty( $e['new'] );
				?>
				<tr>
					<td><input type="date" name="cec_fr_entries[<?php echo (int) $i; ?>][date]" value="<?php echo esc_attr( $new ? '' : $e['date'] ); ?>" aria-label="<?php esc_attr_e( 'Date', 'cec' ); ?>" /></td>
					<td><input type="text" inputmode="decimal" class="small-text" name="cec_fr_entries[<?php echo (int) $i; ?>][amount]" value="<?php echo esc_attr( $new ? '' : rtrim( rtrim( number_format( (float) $e['amount'], 2, '.', '' ), '0' ), '.' ) ); ?>" aria-label="<?php esc_attr_e( 'Amount', 'cec' ); ?>" <?php echo $new ? 'placeholder="' . esc_attr__( 'Add…', 'cec' ) . '"' : ''; ?> /></td>
					<td><select name="cec_fr_entries[<?php echo (int) $i; ?>][method]" aria-label="<?php esc_attr_e( 'How it was given', 'cec' ); ?>">
						<?php foreach ( $methods as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $new ? 'online' : $e['method'], $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select></td>
					<td><input type="text" class="regular-text" maxlength="200" name="cec_fr_entries[<?php echo (int) $i; ?>][note]" value="<?php echo esc_attr( $new ? '' : $e['note'] ); ?>" aria-label="<?php esc_attr_e( 'Private note', 'cec' ); ?>" /></td>
					<td><?php if ( ! $new ) : ?><input type="checkbox" name="cec_fr_entries[<?php echo (int) $i; ?>][remove]" value="1" aria-label="<?php esc_attr_e( 'Remove this donation', 'cec' ); ?>" /><?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Three empty rows are added each time. Fill in an amount to add a donation (the date defaults to today), then Update.', 'cec' ); ?></p>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['cec_fr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cec_fr_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		// Only the fundraiser whose form was posted (see CEC 1.45.1).
		if ( ! isset( $_POST['post_ID'] ) || (int) $_POST['post_ID'] !== (int) $post_id ) {
			return;
		}
		$text = function ( $k, $max ) {
			return isset( $_POST[ $k ] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) ), 0, $max ) : '';
		};
		$date = function ( $v ) {
			$v = trim( (string) $v );
			$d = DateTime::createFromFormat( '!Y-m-d', $v );
			return ( $d && $d->format( 'Y-m-d' ) === $v ) ? $v : '';
		};

		$goal = self::money( $text( 'cec_fr_goal', 20 ) );
		update_post_meta( $post_id, '_cec_fr_goal', null === $goal ? 0 : $goal );
		$start = $date( $text( 'cec_fr_start', 10 ) );
		$end   = $date( $text( 'cec_fr_end', 10 ) );
		if ( $start && $end && $end < $start ) {
			$end = $start;
		}
		update_post_meta( $post_id, '_cec_fr_start', $start );
		update_post_meta( $post_id, '_cec_fr_end', $end );
		$url = esc_url_raw( $text( 'cec_fr_url', 500 ), array( 'http', 'https' ) );
		update_post_meta( $post_id, '_cec_fr_url', $url );
		update_post_meta( $post_id, '_cec_fr_button', $text( 'cec_fr_button', 40 ) );
		$other = isset( $_POST['cec_fr_other'] ) ? mb_substr( trim( sanitize_textarea_field( wp_unslash( $_POST['cec_fr_other'] ) ) ), 0, 500 ) : '';
		update_post_meta( $post_id, '_cec_fr_other', $other );
		$org  = isset( $_POST['cec_fr_org'] ) ? absint( $_POST['cec_fr_org'] ) : 0;
		$term = $org ? get_term( $org, 'cec_partner_org' ) : null;
		update_post_meta( $post_id, '_cec_fr_org', ( $term && ! is_wp_error( $term ) ) ? $org : 0 );

		$methods = self::methods();
		$entries = array();
		$posted  = isset( $_POST['cec_fr_entries'] ) && is_array( $_POST['cec_fr_entries'] ) ? wp_unslash( $_POST['cec_fr_entries'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.
		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}
			$amount = self::money( $row['amount'] ?? '' );
			if ( null === $amount || 0.0 === $amount ) {
				continue;
			}
			$d = $date( $row['date'] ?? '' );
			$entries[] = array(
				'date'   => $d ? $d : wp_date( 'Y-m-d' ),
				'amount' => $amount,
				'method' => isset( $methods[ $row['method'] ?? '' ] ) ? $row['method'] : 'other',
				'note'   => mb_substr( trim( sanitize_text_field( (string) ( $row['note'] ?? '' ) ) ), 0, 200 ),
			);
		}
		usort(
			$entries,
			function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);
		update_post_meta( $post_id, '_cec_fr_entries', $entries );
		update_post_meta( $post_id, '_cec_fr_raised', round( array_sum( wp_list_pluck( $entries, 'amount' ) ), 2 ) );
		update_post_meta( $post_id, '_cec_fr_count', count( $entries ) );

		self::refresh_pages();
	}

	public static function maybe_refresh( $post_id ) {
		if ( self::POST_TYPE === get_post_type( $post_id ) ) {
			self::refresh_pages();
		}
	}

	/**
	 * Re-saves the pages showing a fundraiser shortcode and clears
	 * Elementor's cache, so the host's CDN serves the new totals (the same
	 * approach as CEC_Orgs::refresh_pages()).
	 */
	public static function refresh_pages() {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data' WHERE p.post_status = 'publish' AND p.post_type IN ('page','post') AND ( p.post_content LIKE '%[cec_fundraiser%' OR m.meta_value LIKE '%cec_fundraiser%' )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array_map( 'intval', $ids ) as $id ) {
			delete_post_meta( $id, '_elementor_element_cache' );
			clean_post_cache( $id );
			wp_update_post( array( 'ID' => $id ) );
		}
		if ( did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['cec_fr_progress'] = __( 'Raised', 'cec' );
				$out['cec_fr_dates']    = __( 'Dates', 'cec' );
			}
		}
		return $out;
	}

	public static function column( $col, $id ) {
		$d = self::data( $id );
		if ( 'cec_fr_progress' === $col ) {
			/* translators: 1: raised, 2: goal, 3: percent, 4: donations */
			echo esc_html( sprintf( __( '%1$s of %2$s (%3$d%%) · %4$d', 'cec' ), self::money_text( $d['raised'] ), self::money_text( $d['goal'] ), $d['percent'], $d['count'] ) );
		} elseif ( 'cec_fr_dates' === $col ) {
			echo esc_html( self::dates_text( $d ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Public
	 * ---------------------------------------------------------------- */

	private static function dates_text( $d ) {
		$fmt = function ( $ymd ) {
			return wp_date( 'M j, Y', strtotime( $ymd . ' 12:00:00' ) );
		};
		if ( 'ended' === $d['status'] ) {
			/* translators: %s: date */
			return sprintf( __( 'Ended %s', 'cec' ), $fmt( $d['end'] ) );
		}
		if ( 'upcoming' === $d['status'] ) {
			/* translators: %s: date */
			return sprintf( __( 'Starts %s', 'cec' ), $fmt( $d['start'] ) );
		}
		/* translators: %s: date */
		return $d['end'] ? sprintf( __( 'Through %s', 'cec' ), $fmt( $d['end'] ) ) : '';
	}

	/** One fundraiser card. */
	public static function card( $id ) {
		$d       = self::data( $id );
		$raised  = self::money_text( $d['raised'] );
		$goal    = self::money_text( $d['goal'] );
		$bar     = min( 100, $d['percent'] );
		$summary = $d['goal'] > 0
			/* translators: 1: amount raised, 2: goal */
			? sprintf( __( '%1$s raised of %2$s goal', 'cec' ), $raised, $goal )
			/* translators: %s: amount raised */
			: sprintf( __( '%s raised', 'cec' ), $raised );
		$h  = '<article class="cec-fr-card is-' . esc_attr( $d['status'] ) . '" id="fundraiser-' . (int) $d['id'] . '">';
		$h .= $d['photo'] ? '<div class="cec-fr-media">' . $d['photo'] . '</div>' : '';
		$h .= '<div class="cec-fr-body"><h3 class="cec-fr-title">' . esc_html( $d['title'] ) . '</h3>';
		$meta = array();
		if ( $d['org'] ) {
			/* translators: %s: organization or titleholder link */
			$meta[] = sprintf( esc_html__( 'For %s', 'cec' ), '<a href="' . esc_url( get_term_link( $d['org'] ) ) . '">' . esc_html( $d['org']->name ) . '</a>' );
		}
		$dates = self::dates_text( $d );
		if ( $dates ) {
			$meta[] = esc_html( $dates );
		}
		$h .= $meta ? '<p class="cec-fr-meta">' . implode( ' · ', $meta ) . '</p>' : '';
		if ( $d['goal'] > 0 ) {
			$h .= '<div class="cec-fr-bar" role="progressbar" aria-valuemin="0" aria-valuemax="' . esc_attr( $d['goal'] ) . '" aria-valuenow="' . esc_attr( min( $d['raised'], $d['goal'] ) ) . '" aria-valuetext="' . esc_attr( $summary ) . '"><span style="width:' . (int) $bar . '%"></span></div>';
		}
		$h .= '<p class="cec-fr-numbers"><strong>' . esc_html( $raised ) . '</strong> ';
		$h .= $d['goal'] > 0
			/* translators: 1: goal, 2: percent */
			? esc_html( sprintf( __( 'raised of %1$s goal · %2$d%%', 'cec' ), $goal, $d['percent'] ) )
			: esc_html__( 'raised', 'cec' );
		$h .= ' · ' . esc_html( sprintf( /* translators: %d: number of donations */ _n( '%d donation', '%d donations', $d['count'], 'cec' ), $d['count'] ) ) . '</p>';
		if ( $d['goal'] > 0 && $d['raised'] >= $d['goal'] ) {
			$h .= '<p class="cec-fr-reached">' . esc_html__( 'Goal reached. Thank you!', 'cec' ) . '</p>';
		}
		$h .= $d['content'] ? '<div class="cec-fr-desc">' . wpautop( wp_kses_post( $d['content'] ) ) . '</div>' : '';
		if ( 'ended' !== $d['status'] ) {
			if ( $d['url'] ) {
				$h .= '<p class="cec-fr-give"><a class="cec-fr-btn" href="' . esc_url( $d['url'] ) . '" target="_blank" rel="noopener">' . esc_html( '' !== $d['button'] ? $d['button'] : __( 'Donate', 'cec' ) ) . '<span class="screen-reader-text"> ' . esc_html( sprintf( /* translators: %s: fundraiser */ __( 'to %s (opens in a new tab)', 'cec' ), $d['title'] ) ) . '</span></a></p>';
			}
			if ( '' !== $d['other'] ) {
				$h .= '<p class="cec-fr-other">' . nl2br( esc_html( $d['other'] ) ) . '</p>';
			}
		}
		return $h . '</div></article>';
	}

	/** Published fundraisers, current and upcoming first (soonest end first), then completed (latest first). */
	private static function ids_by_status() {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$out = array( 'current' => array(), 'ended' => array() );
		foreach ( $ids as $id ) {
			$d = self::data( $id );
			$out[ 'ended' === $d['status'] ? 'ended' : 'current' ][] = $d;
		}
		usort(
			$out['current'],
			function ( $a, $b ) {
				return strcmp( $a['end'] ? $a['end'] : '9999', $b['end'] ? $b['end'] : '9999' );
			}
		);
		usort(
			$out['ended'],
			function ( $a, $b ) {
				return strcmp( $b['end'], $a['end'] );
			}
		);
		return array( 'current' => wp_list_pluck( $out['current'], 'id' ), 'ended' => wp_list_pluck( $out['ended'], 'id' ) );
	}

	/** [cec_fundraisers show="all|current|ended" empty="…"] */
	public static function shortcode_list( $atts ) {
		$atts = shortcode_atts(
			array(
				'show'  => 'all',
				'empty' => __( 'No fundraisers right now. Check back soon!', 'cec' ),
			),
			$atts,
			'cec_fundraisers'
		);
		wp_enqueue_style( 'cec-fundraisers' );
		$ids = self::ids_by_status();
		$h   = '<div class="cec-fr-wrap">';
		if ( 'ended' !== $atts['show'] ) {
			$h .= $ids['current'] ? '<div class="cec-fr-list">' . implode( '', array_map( array( __CLASS__, 'card' ), $ids['current'] ) ) . '</div>' : '<p class="cec-fr-empty">' . esc_html( $atts['empty'] ) . '</p>';
		}
		if ( 'current' !== $atts['show'] && $ids['ended'] ) {
			$h .= '<h2 class="cec-fr-heading">' . esc_html__( 'Completed fundraisers', 'cec' ) . '</h2><div class="cec-fr-list is-ended">' . implode( '', array_map( array( __CLASS__, 'card' ), $ids['ended'] ) ) . '</div>';
		}
		return $h . '</div>';
	}

	/** [cec_fundraiser id="123"]: one card, e.g. on the homepage. */
	public static function shortcode_one( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'cec_fundraiser' );
		$id   = absint( $atts['id'] );
		if ( ! $id || self::POST_TYPE !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return '';
		}
		wp_enqueue_style( 'cec-fundraisers' );
		return '<div class="cec-fr-wrap">' . self::card( $id ) . '</div>';
	}
}
