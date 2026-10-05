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

	/**
	 * Administrators get the brief's dedicated "Manage Member Fields"
	 * capability (the profile option lists); event editors never do.
	 */
	private static function add_caps() {
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( CMP_Profile_Fields::CAP ) ) {
			$admin->add_cap( CMP_Profile_Fields::CAP );
		}
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
		return array( self::table( 'audit_log' ), self::table( 'notifications' ), self::table( 'profile_values' ), self::table( 'profile_images' ) );
	}

	private static function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::schema() as $sql ) {
			dbDelta( $sql );
		}
		self::add_caps();
		// 0.5.0: starter options for any profile list that has none yet.
		CMP_Profile_Fields::seed_defaults();
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

		// One row per member per field: the value (JSON; NULL for fields
		// whose value lives elsewhere, e.g. display name, photos, member
		// since) plus the member's visibility and search choices. A missing
		// row means empty, Private and not searchable (brief §2 defaults).
		$values = 'CREATE TABLE ' . self::table( 'profile_values' ) . " (
			user_id bigint(20) unsigned NOT NULL,
			field_key varchar(40) NOT NULL,
			value longtext NULL,
			visibility varchar(12) NOT NULL DEFAULT 'private',
			searchable tinyint(1) NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (user_id,field_key),
			KEY field_visibility (field_key,visibility)
		) $charset;";

		// Profile and cover photos, kept in the database (owner decision,
		// 2026-10-04) so no file ever sits at a public media URL. Always a
		// re-encoded JPEG (no camera metadata). Served only through
		// CMP_Profile_Images::serve(), which checks the photo's visibility.
		$images = 'CREATE TABLE ' . self::table( 'profile_images' ) . " (
			user_id bigint(20) unsigned NOT NULL,
			kind varchar(10) NOT NULL,
			mime varchar(20) NOT NULL,
			width smallint(5) unsigned NOT NULL,
			height smallint(5) unsigned NOT NULL,
			bytes int(10) unsigned NOT NULL,
			sha256 char(64) NOT NULL,
			data mediumblob NOT NULL,
			alt varchar(150) NOT NULL DEFAULT '',
			decorative tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (user_id,kind)
		) $charset;";

		return array( $audit, $notifications, $values, $images );
	}
}
