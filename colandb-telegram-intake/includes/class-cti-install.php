<?php
/**
 * Tables, schedules and a small lock.
 *
 *   {prefix}cti_posts    one row per Telegram channel post (unique on chat + message)
 *   {prefix}cti_entries  one row per event found in a post (unique on post + index)
 *   {prefix}cti_updates  Telegram update IDs already received (idempotency)
 *   {prefix}cti_log      the audit trail
 */
defined( 'ABSPATH' ) || exit;

final class CTI_Install {

	const DB_VERSION = 1;
	const DB_OPTION  = 'cti_db_version';

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'cti_' . $name;
	}

	public static function activate() {
		self::create_tables();
		CTI_Processor::schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( CTI_Processor::HOOK );
		wp_clear_scheduled_hook( CTI_Processor::CLEANUP_HOOK );
	}

	public static function maybe_upgrade() {
		if ( (int) get_option( self::DB_OPTION ) < self::DB_VERSION ) {
			self::create_tables();
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table( 'posts' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			chat_id varchar(32) NOT NULL,
			message_id bigint(20) NOT NULL,
			channel varchar(80) NOT NULL DEFAULT '',
			post_url varchar(255) NOT NULL DEFAULT '',
			post_date datetime NULL,
			edit_date datetime NULL,
			last_update_id bigint(20) NOT NULL DEFAULT 0,
			media_group varchar(64) NOT NULL DEFAULT '',
			group_parent bigint(20) unsigned NOT NULL DEFAULT 0,
			body longtext NOT NULL,
			links text NOT NULL,
			photos text NOT NULL,
			content_hash char(64) NOT NULL DEFAULT '',
			image_hash char(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'received',
			reason text NOT NULL,
			attempts int(11) NOT NULL DEFAULT 0,
			next_attempt datetime NULL,
			extraction longtext NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			processed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source (chat_id,message_id),
			KEY status (status,next_attempt),
			KEY media (chat_id,media_group)
		) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'entries' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			idx int(11) NOT NULL DEFAULT 0,
			status varchar(24) NOT NULL DEFAULT 'new',
			reason text NOT NULL,
			title varchar(255) NOT NULL DEFAULT '',
			start_date varchar(10) NOT NULL DEFAULT '',
			proposed longtext NOT NULL,
			previous longtext NOT NULL,
			candidates longtext NOT NULL,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
			decided_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_idx (post_id,idx),
			KEY status (status),
			KEY event_id (event_id),
			KEY start_date (start_date)
		) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'updates' ) . " (
			update_id bigint(20) NOT NULL,
			received_at datetime NOT NULL,
			PRIMARY KEY  (update_id)
		) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'log' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(40) NOT NULL,
			detail text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id)
		) $c;"
		);
		update_option( self::DB_OPTION, self::DB_VERSION );
	}

	/**
	 * A lock that works on MySQL and on the test kit's SQLite: a row in the
	 * options table, inserted with plain SQL (option_name is unique, so only
	 * one process can insert it). WordPress's option functions are not used:
	 * their per-request cache would keep seeing a lock already released.
	 * A lock older than $ttl seconds is treated as abandoned.
	 */
	public static function lock( $name, $ttl = 300, $wait = 0 ) {
		global $wpdb;
		$key   = 'cti_lock_' . $name;
		$until = microtime( true ) + $wait;
		do {
			$suppress = $wpdb->suppress_errors( true );
			$got      = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, (string) time() ) );
			$wpdb->suppress_errors( $suppress );
			if ( $got ) {
				return true;
			}
			$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
			if ( $held && $held < time() - $ttl ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, (string) $held ) );
				continue;
			}
			if ( microtime( true ) < $until ) {
				usleep( 200000 );
			}
		} while ( microtime( true ) < $until );
		return false;
	}

	public static function unlock( $name ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'cti_lock_' . $name ) );
		wp_cache_delete( 'cti_lock_' . $name, 'options' );
	}
}
