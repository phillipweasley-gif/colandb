<?php
defined( 'ABSPATH' ) || exit;

/** The bids table, the closing check and the (draft) Auction page. */
class CSA_Install {

	const DB_VERSION = '1';
	const CRON       = 'csa_check_closing';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'csa_bids';
	}

	public static function activate() {
		self::create_table();
		self::schedule();
		self::create_page();
		update_option( 'csa_db_version', self::DB_VERSION, false );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'csa_db_version' ) !== self::DB_VERSION ) {
			self::activate();
		}
		if ( ! wp_next_scheduled( self::CRON ) ) {
			self::schedule();
		}
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				item_id bigint(20) unsigned NOT NULL,
				auction_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				amount bigint(20) unsigned NOT NULL,
				buy_now tinyint(1) NOT NULL DEFAULT 0,
				created_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY item_id (item_id),
				KEY user_id (user_id)
			) $charset;"
		);
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 60, 'csa_five_minutes', self::CRON );
		}
	}

	/** A draft "Silent Auction" page, published by the site team when they're ready. */
	public static function create_page() {
		$existing = (int) get_option( 'csa_page_id' );
		if ( $existing && get_post( $existing ) ) {
			return;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => __( 'Silent Auction', 'csa' ),
				'post_content' => '[csa_auction]',
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			update_option( 'csa_page_id', (int) $id, false );
		}
	}

	/** The Auction page address (the draft's preview address until it's published). */
	public static function page_url() {
		$id = (int) get_option( 'csa_page_id' );
		return $id && 'publish' === get_post_status( $id ) ? get_permalink( $id ) : home_url( '/' );
	}
}

add_filter(
	'cron_schedules',
	static function ( $s ) {
		$s['csa_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (silent auction)', 'csa' ),
		);
		return $s;
	}
);
