<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades this plugin's own tables. Each release that adds a
 * table bumps CMP_DB_VERSION and adds its CREATE TABLE to schema(); dbDelta()
 * creates missing tables and adds missing columns without touching data.
 * Nothing here ever changes the events plugin's data.
 */
class CMP_Install {

	const DB_VERSION_OPTION = 'cmp_db_version';

	public static function activate() {
		self::create_tables();
	}

	public static function maybe_upgrade() {
		if ( (int) get_option( self::DB_VERSION_OPTION ) < CMP_DB_VERSION ) {
			self::create_tables();
		}
	}

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'cmp_' . $name;
	}

	public static function table_names() {
		return array( self::table( 'audit_log' ), self::table( 'notifications' ) );
	}

	private static function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::schema() as $sql ) {
			dbDelta( $sql );
		}
		update_option( self::DB_VERSION_OPTION, CMP_DB_VERSION, false );
	}

	/**
	 * All timestamps are stored in UTC (DATETIME, no offset).
	 */
	private static function schema() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// Append-only: CMP_Audit has no update or delete method. old_value
		// and new_value hold JSON; a create stores only new_value, a delete
		// only old_value plus a reason (brief §5).
		$audit = 'CREATE TABLE ' . self::table( 'audit_log' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(64) NOT NULL,
			object_type varchar(40) NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			old_value longtext NULL,
			new_value longtext NULL,
			reason text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY object (object_type,object_id),
			KEY actor_id (actor_id),
			KEY created_at (created_at)
		) $charset;";

		// deliver_at implements quiet hours: a notification is created
		// immediately but only shown once deliver_at has passed.
		$notifications = 'CREATE TABLE ' . self::table( 'notifications' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			category varchar(40) NOT NULL,
			message text NOT NULL,
			url varchar(2048) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			deliver_at datetime NOT NULL,
			read_at datetime NULL,
			PRIMARY KEY  (id),
			KEY user_inbox (user_id,deliver_at),
			KEY user_unread (user_id,read_at)
		) $charset;";

		return array( $audit, $notifications );
	}
}
